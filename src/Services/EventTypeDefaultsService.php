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

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventTypeDefaultsService
{
    public function register(): void
    {
        add_action('add_meta_boxes_hmw_event', [$this, 'add_meta_box']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_hmwevents_apply_event_type_defaults', [$this, 'ajax_apply_defaults']);
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
        $type_terms = wp_get_object_terms($post->ID, 'hmw_event_type', ['fields' => 'ids']);
        $current_type_id = (!is_wp_error($type_terms) && !empty($type_terms)) ? (int) $type_terms[0] : 0;

        wp_nonce_field('hmwevents_apply_type_defaults', 'hmwevents_apply_type_defaults_nonce');
        ?>
        <p><?php esc_html_e('When event type changes, you can apply type defaults without overwriting existing values.', 'hmw-events'); ?></p>
        <p class="description"><?php esc_html_e('Safe mode: only empty meta fields are populated.', 'hmw-events'); ?></p>
        <input type="hidden" id="hmwevents-current-event-type-id" value="<?php echo esc_attr((string) $current_type_id); ?>" />
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
        wp_add_inline_script('jquery', $this->get_inline_script());
    }

    public function ajax_apply_defaults(): void
    {
        check_ajax_referer('hmwevents_apply_type_defaults', '_nonce');

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $term_id = (int) ($_POST['term_id'] ?? 0);

        if (!$post_id || !$term_id) {
            wp_send_json_error(['message' => __('Missing event or type.', 'hmw-events')], 400);
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'hmw-events')], 403);
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'hmw_event') {
            wp_send_json_error(['message' => __('Invalid event.', 'hmw-events')], 400);
        }

        $term = get_term($term_id, 'hmw_event_type');
        if (!$term || is_wp_error($term)) {
            wp_send_json_error(['message' => __('Invalid event type.', 'hmw-events')], 400);
        }

        $result = $this->apply_defaults_to_event($post_id, $term->slug);

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: updated field count, 2: skipped field count */
                __('Applied defaults. Updated: %1$d, skipped: %2$d.', 'hmw-events'),
                $result['updated'],
                $result['skipped']
            ),
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
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
        if (is_array($snapshot) && isset($snapshot['registration_fields']) && is_array($snapshot['registration_fields'])) {
            $registration_fields = $snapshot['registration_fields'];
        }

        update_post_meta($post_id, '_event_field_config', [
            'event_fields' => [
                'required' => array_values(array_map('sanitize_key', (array) ($type_config['required_fields'] ?? []))),
                'optional' => [],
                'hidden'   => array_values(array_map('sanitize_key', (array) ($type_config['hidden_fields'] ?? []))),
            ],
            'registration_fields' => $registration_fields,
        ]);

        return [
            'updated' => $updated,
            'skipped' => $skipped,
        ];
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

    private function get_inline_script(): string
    {
        $nonce = wp_create_nonce('hmwevents_apply_type_defaults');
        $ajax_url = admin_url('admin-ajax.php');

        return "(function($){
            function getSelectedTypeIds(){
                var ids = [];
                $('#taxonomy-hmw_event_type input[type=checkbox]:checked').each(function(){
                    var id = parseInt($(this).val(), 10) || 0;
                    if(id > 0){ ids.push(id); }
                });
                return ids;
            }

            function updateButtonState(){
                var baseline = parseInt($('#hmwevents-current-event-type-id').val(), 10) || 0;
                var selectedIds = getSelectedTypeIds();
                var hasSingleSelection = selectedIds.length === 1;
                var selected = hasSingleSelection ? selectedIds[0] : 0;
                var hasChange = hasSingleSelection && selected !== baseline;
                $('#hmwevents-apply-type-defaults').prop('disabled', !hasChange);

                if(selectedIds.length > 1){
                    $('#hmwevents-type-defaults-status').text('Select exactly one event type to apply defaults.');
                } else if(hasChange){
                    $('#hmwevents-type-defaults-status').text('Event type changed. You can safely apply defaults.');
                } else {
                    $('#hmwevents-type-defaults-status').text('');
                }
            }

            $(document).on('change', '#taxonomy-hmw_event_type input[type=checkbox]', function(){
                updateButtonState();
            });

            $(document).on('click', '#hmwevents-apply-type-defaults', function(e){
                e.preventDefault();
                var btn = $(this);
                var postId = parseInt($('#post_ID').val(), 10) || 0;
                var selectedIds = getSelectedTypeIds();
                var termId = selectedIds.length === 1 ? selectedIds[0] : 0;

                if(!postId || !termId){
                    $('#hmwevents-type-defaults-status').text('Save draft first and select one event type.');
                    return;
                }

                btn.prop('disabled', true);
                $('#hmwevents-type-defaults-status').text('Applying defaults...');

                $.post('" . esc_js($ajax_url) . "', {
                    action: 'hmwevents_apply_event_type_defaults',
                    _nonce: '" . esc_js($nonce) . "',
                    post_id: postId,
                    term_id: termId
                }).done(function(resp){
                    if(resp && resp.success){
                        $('#hmwevents-current-event-type-id').val(termId);
                        $('#hmwevents-type-defaults-status').text(resp.data && resp.data.message ? resp.data.message : 'Defaults applied.');
                    } else {
                        var message = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to apply defaults.';
                        $('#hmwevents-type-defaults-status').text(message);
                    }
                }).fail(function(){
                    $('#hmwevents-type-defaults-status').text('Request failed.');
                }).always(function(){
                    updateButtonState();
                });
            });

            $(function(){ updateButtonState(); });
        })(jQuery);";
    }
}
