<?php

namespace HMWEvents\Services;

use HMWEvents\Helpers\EventFieldConfig;
use HMWEvents\Registry\EventTypeRegistry;
use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventTypeDefaultsService
{
    private ?EventTemplateService $template_service = null;

    private function template_service(): EventTemplateService
    {
        if ($this->template_service === null) {
            $this->template_service = new EventTemplateService();
        }

        return $this->template_service;
    }

    public function register(): void
    {
        add_action('add_meta_boxes_hmw_event', [$this, 'add_meta_box']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_hmwevents_apply_event_type_defaults', [$this, 'ajax_apply_defaults']);
        add_action('save_post_hmw_event', [$this, 'save_template_selection'], 9);
        add_action('save_post_hmw_event', [$this, 'auto_apply_on_first_save'], 20);
    }

    public function add_meta_box(): void
    {
        add_meta_box(
            'hmwevents-event-template-defaults',
            __('Event Template', 'hmw-events'),
            [$this, 'render_meta_box'],
            'hmw_event',
            'side',
            'high'
        );
    }

    public function render_meta_box(\WP_Post $post): void
    {
        $template_id = (int) get_post_meta($post->ID, '_selected_template_id', true);
        $templates = $this->template_service()->get_all(['is_active' => 1]);

        wp_nonce_field('hmwevents_template_select_save', 'hmwevents_template_select_nonce');
        ?>
        <p><?php esc_html_e('Select a template to configure fields and defaults. Event type selection is for categorization only.', 'hmw-events'); ?></p>
        <select name="hmwevents_event_template" id="hmwevents-event-template-select" style="width:100%;margin-bottom:8px;">
            <option value="">— Select Template —</option>
            <?php foreach ($templates as $template): ?>
                <option value="<?php echo (int) $template->id; ?>" <?php selected($template_id, (int) $template->id); ?>>
                    <?php echo esc_html($template->title); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" id="hmwevents-current-template-id" value="<?php echo esc_attr((string) $template_id); ?>" />
        <button type="button" class="button button-secondary" id="hmwevents-apply-template-defaults" disabled>
            <?php esc_html_e('Apply Template Defaults', 'hmw-events'); ?>
        </button>
        <p id="hmwevents-template-defaults-status" style="margin-top:8px;"></p>
        <?php
    }

    public function enqueue_assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'hmw_event') {
            return;
        }

        wp_enqueue_script('jquery');

        $templates = $this->template_service()->get_all(['is_active' => 1]);
        $hidden_fields_map = [];
        $all_hideable = [];

        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        foreach ($templates as $template) {
            $type_slug = $template->event_type_slug ?: '';

            $resolved = $resolver->resolve($type_slug, (array) $template->template_data);
            $event_fields = [];

            if (!is_wp_error($resolved) && isset($resolved['field_config']['event_fields'])) {
                $event_fields = $resolved['field_config']['event_fields'];
            } else {
                $data = is_array($template->template_data)
                    ? $template->template_data
                    : json_decode($template->template_data, true);
                $field_data = is_array($data) ? ($data['event_fields'] ?? []) : [];
                $event_fields = [
                    'hidden' => array_values(array_map('sanitize_key', (array) ($field_data['hidden'] ?? []))),
                    'required' => array_values(array_map('sanitize_key', (array) ($field_data['required'] ?? []))),
                    'optional' => array_values(array_map('sanitize_key', (array) ($field_data['optional'] ?? []))),
                ];
            }

            $hidden = array_values(array_map('sanitize_key', (array) ($event_fields['hidden'] ?? [])));
            $hidden_fields_map[(int) $template->id] = $hidden;
            foreach ($hidden as $field) {
                if ($field !== '') {
                    $all_hideable[] = $field;
                }
            }
        }

        $all_hideable = array_values(array_unique($all_hideable));
        $post_id = (int) get_the_ID();
        $attendance_options = $post_id > 0
            ? \HMWEvents\Helpers\EventHelper::get_active_attendance_options($post_id)
            : [];
        $simple_price_visible = !\HMWEvents\Services\AttendancePricingService::is_multi_options(array_map(static function ($option): array {
            return ['option_type' => $option->option_type];
        }, $attendance_options));

        wp_localize_script('jquery', 'hmwEventTypeDefaults', [
            'hiddenFields' => $hidden_fields_map,
            'allHideableFields' => $all_hideable,
            'simplePriceVisible' => $simple_price_visible,
        ]);
        wp_add_inline_script('jquery', $this->get_inline_script());
        add_action('admin_head', [$this, 'output_field_visibility_css']);
    }

    public function output_field_visibility_css(): void
    {
        echo '<style>.acf-field.hmwevents-hidden-by-type{display:none!important}</style>';
    }

    public function save_template_selection(int $post_id): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['hmwevents_template_select_nonce'])) {
            error_log('HMWEvents: template select nonce missing in POST');
            return;
        }

        if (!wp_verify_nonce($_POST['hmwevents_template_select_nonce'], 'hmwevents_template_select_save')) {
            error_log('HMWEvents: template select nonce verification failed');
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            error_log('HMWEvents: template select permission denied for post ' . $post_id);
            return;
        }

        $template_id = (int) ($_POST['hmwevents_event_template'] ?? 0);

        if ($template_id > 0) {
            update_post_meta($post_id, '_selected_template_id', $template_id);
            error_log('HMWEvents: saved template ID ' . $template_id . ' for post ' . $post_id);
        } else {
            delete_post_meta($post_id, '_selected_template_id');
        }
    }

    public function ajax_apply_defaults(): void
    {
        check_ajax_referer('hmwevents_apply_type_defaults', '_nonce');

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $type_slug = '';

        $template_id = (int) ($_POST['template_id'] ?? 0);

        if ($template_id > 0) {
            $template = $this->template_service()->get($template_id);
            if ($template && !empty($template->event_type_slug)) {
                $type_slug = sanitize_key($template->event_type_slug);
            }
        }

        if ($type_slug === '' && !empty($_POST['term_slug']) && is_string($_POST['term_slug'])) {
            $type_slug = sanitize_key($_POST['term_slug']);
        }

        if ($type_slug === '' && !empty($_POST['term_id'])) {
            $term = get_term((int) $_POST['term_id'], 'hmw_event_type');
            if ($term && !is_wp_error($term)) {
                $type_slug = $term->slug;
            }
        }

        if (!$post_id || $type_slug === '') {
            wp_send_json_error(['message' => __('Missing event or template.', 'hmw-events')], 400);
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'hmw-events')], 403);
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'hmw_event') {
            wp_send_json_error(['message' => __('Invalid event.', 'hmw-events')], 400);
        }

        $result = $this->apply_defaults_to_event($post_id, $type_slug);

        $saved = EventFieldConfig::read($post_id);
        $event_fields = $saved !== null && isset($saved['event_fields']) ? $saved['event_fields'] : [];

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: updated field count, 2: skipped field count */
                __('Applied defaults. Updated: %1$d, skipped: %2$d.', 'hmw-events'),
                $result['updated'],
                $result['skipped']
            ),
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'field_config' => [
                'hidden'   => array_values(array_map('sanitize_key', (array) ($event_fields['hidden'] ?? []))),
                'required' => array_values(array_map('sanitize_key', (array) ($event_fields['required'] ?? []))),
            ],
        ]);
    }

    /**
     * @return array{updated:int, skipped:int}
     */
    private function apply_defaults_to_event(int $post_id, string $type_slug): array
    {
        $defaults = EventTypeRegistry::get_default_meta($type_slug);
        $type_config = EventTypeRegistry::get($type_slug);
        $updated = 0;
        $skipped = 0;

        foreach ($defaults as $raw_key => $value) {
            $target_key = $this->normalize_meta_key((string) $raw_key);
            $alt_key = $this->alternate_meta_key($target_key);

            $current_target = get_post_meta($post_id, $target_key, true);
            $current_alt = $alt_key ? get_post_meta($post_id, $alt_key, true) : '';

            if ($this->has_value($current_target) || $this->has_value($current_alt)) {
                $skipped++;
                continue;
            }

            update_post_meta($post_id, $target_key, $value);
            $updated++;
        }

        if (!empty($defaults['event_delivery_mode'])) {
            $current_modes = wp_get_object_terms($post_id, 'hmw_event_delivery_mode', ['fields' => 'ids']);
            if (!is_wp_error($current_modes) && empty($current_modes)) {
                wp_set_object_terms($post_id, sanitize_key((string) $defaults['event_delivery_mode']), 'hmw_event_delivery_mode', false);
                $updated++;
            } else {
                $skipped++;
            }
        }

        $snapshot = EventFieldConfig::read($post_id);
        $registration_fields = [];
        if ($snapshot !== null && isset($snapshot['registration_fields'])
            && is_array($snapshot['registration_fields'])
            && !empty($snapshot['registration_fields'])
        ) {
            $registration_fields = $snapshot['registration_fields'];
        } else {
            $registration_fields = $this->build_default_registration_fields();
        }

        $event_fields = $this->resolve_event_fields($post_id, $type_slug);

        update_post_meta($post_id, '_event_field_config', [
            'event_fields'        => $event_fields,
            'registration_fields' => $registration_fields,
        ]);

        delete_post_meta($post_id, '_event_template_override');

        $this->ensure_attendance_options($post_id, $type_slug);

        return [
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    private function resolve_event_fields(int $post_id, string $type_slug): array
    {
        $template = null;

        $template_id = (int) get_post_meta($post_id, '_selected_template_id', true)
            ?: (int) get_post_meta($post_id, '_created_from_template_id', true);

        if ($template_id <= 0 && !empty($_POST['hmwevents_event_template'])) {
            $template_id = (int) $_POST['hmwevents_event_template'];
        }

        if ($template_id) {
            $template = $this->template_service()->get($template_id);
        }

        if (!$template) {
            $template = $this->find_template_by_event_type($type_slug);
        }

        if ($template) {
            $resolver = new TemplateResolver(new TemplateSchemaValidator());
            $resolved = $resolver->resolve(
                $template->event_type_slug ?: $type_slug,
                (array) $template->template_data
            );
            if (!is_wp_error($resolved) && isset($resolved['field_config']['event_fields'])) {
                return $resolved['field_config']['event_fields'];
            }
        }

        $type_config = EventTypeRegistry::get($type_slug);
        return [
            'required' => array_values(array_map('sanitize_key', (array) ($type_config['required_fields'] ?? []))),
            'optional' => [],
            'hidden'   => array_values(array_map('sanitize_key', (array) ($type_config['hidden_fields'] ?? []))),
        ];
    }

    private function find_template_by_event_type(string $type_slug): ?object
    {
        $templates = $this->template_service()->get_all(['event_type_slug' => $type_slug, 'is_active' => true]);
        if (!empty($templates)) {
            return $templates[0];
        }

        return null;
    }

    private function build_default_registration_fields(): array
    {
        $presets = RegistrationFieldRegistry::presets();
        $contact_fields = [];

        foreach ($presets as $preset) {
            $contact_fields[] = [
                'key'           => $preset['key'],
                'label'         => $preset['label'],
                'type'          => $preset['type'],
                'required'      => $preset['required'],
                'placeholder'   => $preset['placeholder'] ?? '',
                'width'         => $preset['width'] ?? 'full',
                'source'        => $preset['source'] ?? 'registrant_meta',
                'meta_key'      => $preset['meta_key'] ?? null,
                'preset'        => true,
                'per_attendee'  => true,
                'options'       => $preset['options'] ?? [],
            ];
        }

        return [
            'sections' => [
                [
                    'id'     => 'sec_contact',
                    'label'  => __('Contact Details', 'hmw-events'),
                    'fields' => $contact_fields,
                ],
            ],
            'multi_booking' => [
                'enabled' => false,
                'min'     => 1,
                'max'     => 10,
            ],
        ];
    }

    private function ensure_attendance_options(int $post_id, string $type_slug): void
    {
        global $wpdb;
        $table = DatabaseService::get_table_name('event_attendance_options');

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_post_id = %d AND is_active = 1",
            $post_id
        ));

        if ((int) $existing > 0) {
            return;
        }

        $presets = EventTypeRegistry::get_attendance_option_presets($type_slug);
        if (empty($presets)) {
            return;
        }

        if (!AttendancePricingService::is_multi_options($presets)) {
            if (get_post_meta($post_id, '_event_price', true) === '' && isset($presets[0]['price'])) {
                update_post_meta($post_id, '_event_price', (float) $presets[0]['price']);
            }
            return;
        }

        foreach ($presets as $index => $preset) {
            AttendancePricingService::insert_attendance_option($post_id, $preset, $index);
        }
    }

    private function normalize_meta_key(string $key): string
    {
        $key = sanitize_key($key);
        if (str_starts_with($key, 'event_')) {
            return '_' . $key;
        }

        return $key;
    }

    private function alternate_meta_key(string $key): ?string
    {
        if (str_starts_with($key, '_event_')) {
            return substr($key, 1);
        }

        if (str_starts_with($key, 'event_')) {
            return '_' . $key;
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function has_value($value): bool
    {
        if (is_array($value)) {
            return !empty($value);
        }

        return $value !== '' && $value !== null;
    }

    public function auto_apply_on_first_save(int $post_id): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $template_id = (int) get_post_meta($post_id, '_selected_template_id', true);

        if ($template_id <= 0 && !empty($_POST['hmwevents_event_template'])) {
            $template_id = (int) $_POST['hmwevents_event_template'];
            if ($template_id > 0) {
                update_post_meta($post_id, '_selected_template_id', $template_id);
            }
        }

        $type_slug = '';

        if ($template_id > 0) {
            $template = $this->template_service()->get($template_id);
            if ($template && !empty($template->event_type_slug)) {
                $type_slug = sanitize_key($template->event_type_slug);
            }
        }

        if ($type_slug === '') {
            $terms = wp_get_object_terms($post_id, 'hmw_event_type', ['fields' => 'slugs']);
            if (is_wp_error($terms) || empty($terms)) {
                return;
            }
            $type_slug = sanitize_key((string) $terms[0]);
        }

        if ($type_slug === '') {
            return;
        }

        $existing = EventFieldConfig::read($post_id);

        if ($existing !== null && !empty($existing)) {
            $event_fields = $this->resolve_event_fields($post_id, $type_slug);
            $existing_hidden = array_values((array) ($existing['event_fields']['hidden'] ?? []));
            $new_hidden = array_values((array) ($event_fields['hidden'] ?? []));

            if ($existing_hidden === $new_hidden) {
                return;
            }
        }

        $this->apply_defaults_to_event($post_id, $type_slug);
    }

    private function get_inline_script(): string
    {
        $nonce = wp_create_nonce('hmwevents_apply_type_defaults');
        $ajax_url = admin_url('admin-ajax.php');

        return "(function(\$){
            var HIDDEN_CLASS = 'hmwevents-hidden-by-type';
            var hiddenFields = window.hmwEventTypeDefaults.hiddenFields;
            var allFields = window.hmwEventTypeDefaults.allHideableFields;

            function hasActiveOverride(){
                return \$('#hmwevents-override-container').data('has-override') === 1;
            }

            function showAllTypeFields(){
                \$.each(allFields, function(i, fieldName){
                    \$('.acf-field[data-name=\"_' + fieldName + '\"]')
                        .removeClass(HIDDEN_CLASS)
                        .show();
                });
            }

            function hideFieldsForTemplate(templateId){
                if(hasActiveOverride()) return;
                var key = String(templateId);
                if(!key || key === '0' || !hiddenFields[key]) return;
                \$.each(hiddenFields[key], function(i, fieldName){
                    if(fieldName === 'event_price' && window.hmwEventTypeDefaults.simplePriceVisible) return;
                    \$('.acf-field[data-name=\"_' + fieldName + '\"]')
                        .addClass(HIDDEN_CLASS);
                });
            }

            function getSelectedTemplateId(){
                return \$('#hmwevents-event-template-select').val() || 0;
            }

            function updateButtonState(){
                var baseline = \$('#hmwevents-current-template-id').val() || '0';
                var selected = getSelectedTemplateId();
                var hasChange = selected > 0 && String(selected) !== String(baseline);
                \$('#hmwevents-apply-template-defaults').prop('disabled', !hasChange);
                if(hasChange){
                    \$('#hmwevents-template-defaults-status').text('Template changed. You can safely apply defaults.');
                } else {
                    \$('#hmwevents-template-defaults-status').text('');
                }
            }

            \$(document).on('change', '#hmwevents-event-template-select', function(){
                showAllTypeFields();
                hideFieldsForTemplate(getSelectedTemplateId());
                updateButtonState();
            });

            \$(document).on('click', '#hmwevents-apply-template-defaults', function(e){
                e.preventDefault();
                var btn = \$(this);
                var postId = parseInt(\$('#post_ID').val(), 10) || 0;
                var templateId = getSelectedTemplateId();

                if(!postId || templateId <= 0){
                    \$('#hmwevents-template-defaults-status').text('Save draft first and select a template.');
                    return;
                }

                btn.prop('disabled', true);
                \$('#hmwevents-template-defaults-status').text('Applying template defaults...');

                \$.post('" . esc_js($ajax_url) . "', {
                    action: 'hmwevents_apply_event_type_defaults',
                    _nonce: '" . esc_js($nonce) . "',
                    post_id: postId,
                    template_id: templateId
                }).done(function(resp){
                    if(resp && resp.success){
                        \$('#hmwevents-current-template-id').val(templateId);
                        if(resp.data && resp.data.field_config){
                            showAllTypeFields();
                            \$.each(resp.data.field_config.hidden || [], function(i, fieldName){
                                \$('.acf-field[data-name=\"_' + fieldName + '\"]')
                                    .addClass(HIDDEN_CLASS);
                            });
                        }
                        \$('#hmwevents-template-defaults-status').text(resp.data && resp.data.message ? resp.data.message : 'Template defaults applied.');
                    } else {
                        var message = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to apply template defaults.';
                        \$('#hmwevents-template-defaults-status').text(message);
                    }
                }).fail(function(){
                    \$('#hmwevents-template-defaults-status').text('Request failed.');
                }).always(function(){
                    updateButtonState();
                });
            });

            \$(function(){
                if(!hasActiveOverride()){
                    hideFieldsForTemplate(getSelectedTemplateId());
                }
                updateButtonState();
            });
        })(jQuery);";
    }
}
