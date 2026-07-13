<?php

/**
 * Event Type Defaults Service.
 *
 * Provides a safe, non-destructive "Apply Type Defaults" action when
 * an admin changes the event type on an event edit screen.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

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
        add_action('save_post_hmw_event', [$this, 'auto_apply_on_first_save'], 20);
    }

    public function add_meta_box(): void
    {
        add_meta_box(
            'hmwevents-event-type-defaults',
            __('Event Type Defaults', 'hmw-events'),
            [$this, 'render_meta_box'],
            'hmw_event',
            'side',
            'high'
        );
    }

    public function render_meta_box(\WP_Post $post): void
    {
        $type_terms = wp_get_object_terms($post->ID, 'hmw_event_type', ['fields' => 'slugs']);
        $current_slug = (!is_wp_error($type_terms) && !empty($type_terms)) ? sanitize_key((string) $type_terms[0]) : '';

        wp_nonce_field('hmwevents_apply_type_defaults', 'hmwevents_apply_type_defaults_nonce');
        ?>
        <p><?php esc_html_e('When event type changes, you can apply type defaults without overwriting existing values.', 'hmw-events'); ?></p>
        <p class="description"><?php esc_html_e('Safe mode: only empty meta fields are populated.', 'hmw-events'); ?></p>
        <input type="hidden" id="hmwevents-current-event-type-slug" value="<?php echo esc_attr($current_slug); ?>" />
        <button type="button" class="button button-secondary" id="hmwevents-apply-type-defaults" disabled>
            <?php esc_html_e('Apply Type Defaults', 'hmw-events'); ?>
        </button>
        <p id="hmwevents-type-defaults-status" style="margin-top:8px;"></p>
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
        wp_localize_script('jquery', 'hmwEventTypeDefaults', [
            'hiddenFields' => EventTypeRegistry::get_hidden_fields_map(),
            'allHideableFields' => EventTypeRegistry::get_all_hideable_fields(),
        ]);
        wp_add_inline_script('jquery', $this->get_inline_script());
    }

    public function ajax_apply_defaults(): void
    {
        check_ajax_referer('hmwevents_apply_type_defaults', '_nonce');

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $type_slug = '';

        if (!empty($_POST['term_slug']) && is_string($_POST['term_slug'])) {
            $type_slug = sanitize_key($_POST['term_slug']);
        } elseif (!empty($_POST['term_id'])) {
            $term = get_term((int) $_POST['term_id'], 'hmw_event_type');
            if ($term && !is_wp_error($term)) {
                $type_slug = $term->slug;
            }
        }

        if (!$post_id || $type_slug === '') {
            wp_send_json_error(['message' => __('Missing event or type.', 'hmw-events')], 400);
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'hmw-events')], 403);
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'hmw_event') {
            wp_send_json_error(['message' => __('Invalid event.', 'hmw-events')], 400);
        }

        $result = $this->apply_defaults_to_event($post_id, $type_slug);

        $saved = get_post_meta($post_id, '_event_field_config', true);
        $event_fields = is_array($saved) && isset($saved['event_fields']) ? $saved['event_fields'] : [];

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

        $snapshot = get_post_meta($post_id, '_event_field_config', true);
        $registration_fields = [];
        if (is_array($snapshot) && isset($snapshot['registration_fields'])
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
        $template_id = (int) get_post_meta($post_id, '_created_from_template_id', true);
        if ($template_id) {
            $template = $this->template_service()->get($template_id);
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
        }

        $type_config = EventTypeRegistry::get($type_slug);
        return [
            'required' => array_values(array_map('sanitize_key', (array) ($type_config['required_fields'] ?? []))),
            'optional' => [],
            'hidden'   => array_values(array_map('sanitize_key', (array) ($type_config['hidden_fields'] ?? []))),
        ];
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
                'options'       => [],
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

        foreach ($presets as $index => $preset) {
            $wpdb->insert($table, [
                'event_post_id' => $post_id,
                'option_type'   => sanitize_key($preset['option_type'] ?? 'individual') ?: 'individual',
                'label'         => sanitize_text_field($preset['label'] ?? 'Individual'),
                'price'         => (float) ($preset['price'] ?? 0),
                'sort_order'    => $index,
                'is_active'     => 1,
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql'),
            ], ['%d', '%s', '%s', '%f', '%d', '%d', '%s', '%s']);
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

        $existing = get_post_meta($post_id, '_event_field_config', true);
        if (is_array($existing) && !empty($existing)) {
            return;
        }

        $terms = wp_get_object_terms($post_id, 'hmw_event_type', ['fields' => 'slugs']);
        if (is_wp_error($terms) || empty($terms)) {
            return;
        }

        $type_slug = sanitize_key((string) $terms[0]);
        $this->apply_defaults_to_event($post_id, $type_slug);
    }

    private function get_inline_script(): string
    {
        $nonce = wp_create_nonce('hmwevents_apply_type_defaults');
        $ajax_url = admin_url('admin-ajax.php');

        return "(function(\$){
            var hiddenFields = window.hmwEventTypeDefaults.hiddenFields;
            var allFields = window.hmwEventTypeDefaults.allHideableFields;

            function showAllTypeFields(){
                \$.each(allFields, function(i, fieldName){
                    \$('.acf-field[data-name=\"_' + fieldName + '\"]').show();
                });
            }

            function hideFieldsForType(typeSlug){
                if(!typeSlug || !hiddenFields[typeSlug]) return;
                \$.each(hiddenFields[typeSlug], function(i, fieldName){
                    \$('.acf-field[data-name=\"_' + fieldName + '\"]').hide();
                });
            }

            function getSelectedTypeSlug(){
                return \$('#hmwevents-event-type-select').val() || '';
            }

            function updateButtonState(){
                var baseline = \$('#hmwevents-current-event-type-slug').val() || '';
                var selected = getSelectedTypeSlug();
                var hasChange = selected !== '' && selected !== baseline;
                \$('#hmwevents-apply-type-defaults').prop('disabled', !hasChange);
                if(hasChange){
                    \$('#hmwevents-type-defaults-status').text('Event type changed. You can safely apply defaults.');
                } else {
                    \$('#hmwevents-type-defaults-status').text('');
                }
            }

            \$(document).on('change', '#hmwevents-event-type-select', function(){
                showAllTypeFields();
                hideFieldsForType(getSelectedTypeSlug());
                updateButtonState();
            });

            \$(document).on('click', '#hmwevents-apply-type-defaults', function(e){
                e.preventDefault();
                var btn = \$(this);
                var postId = parseInt(\$('#post_ID').val(), 10) || 0;
                var typeSlug = getSelectedTypeSlug();

                if(!postId || !typeSlug){
                    \$('#hmwevents-type-defaults-status').text('Save draft first and select an event type.');
                    return;
                }

                btn.prop('disabled', true);
                \$('#hmwevents-type-defaults-status').text('Applying defaults...');

                \$.post('" . esc_js($ajax_url) . "', {
                    action: 'hmwevents_apply_event_type_defaults',
                    _nonce: '" . esc_js($nonce) . "',
                    post_id: postId,
                    term_slug: typeSlug
                }).done(function(resp){
                    if(resp && resp.success){
                        \$('#hmwevents-current-event-type-slug').val(typeSlug);
                        if(resp.data && resp.data.field_config){
                            showAllTypeFields();
                            \$.each(resp.data.field_config.hidden || [], function(i, fieldName){
                                \$('.acf-field[data-name=\"_' + fieldName + '\"]').hide();
                            });
                        }
                        \$('#hmwevents-type-defaults-status').text(resp.data && resp.data.message ? resp.data.message : 'Defaults applied.');
                    } else {
                        var message = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to apply defaults.';
                        \$('#hmwevents-type-defaults-status').text(message);
                    }
                }).fail(function(){
                    \$('#hmwevents-type-defaults-status').text('Request failed.');
                }).always(function(){
                    updateButtonState();
                });
            });

            \$(function(){
                hideFieldsForType(getSelectedTypeSlug());
                updateButtonState();
            });
        })(jQuery);";
    }
}
