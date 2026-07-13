<?php

namespace HMWEvents\Admin;

use HMWEvents\Services\EventTemplateOverrideService;
use HMWEvents\Services\EventTemplateService;
use HMWEvents\PostTypes\Event;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventTemplateOverride
{
    private EventTemplateOverrideService $override_service;
    private EventTemplateService $template_service;

    private int $current_post_id = 0;
    private bool $current_has_children = false;
    private bool $current_has_override = false;
    private bool $current_apply_to_children = false;

    public function __construct()
    {
        $this->override_service = new EventTemplateOverrideService();
        $this->template_service = new EventTemplateService();
        $this->register();
    }

    public function register(): void
    {
        add_action('add_meta_boxes_' . Event::POST_TYPE, [$this, 'add_meta_box']);
        add_action('admin_footer', [$this, 'render_override_modal']);
        add_action('wp_ajax_hmwevents_save_event_override', [$this, 'ajax_save_override']);
        add_action('wp_ajax_hmwevents_load_event_override', [$this, 'ajax_load_override']);
        add_action('wp_ajax_hmwevents_reset_event_override', [$this, 'ajax_reset_override']);
    }

    public function add_meta_box(): void
    {
        add_meta_box(
            'hmwevents-template-override',
            __('Template Override', 'hmw-events'),
            [$this, 'render_meta_box'],
            Event::POST_TYPE,
            'side',
            'default'
        );
    }

    public function render_meta_box(\WP_Post $post): void
    {
        $template_id = (int) get_post_meta($post->ID, '_created_from_template_id', true);
        $field_config = get_post_meta($post->ID, '_event_field_config', true);
        if (!is_array($field_config) || empty($field_config)) {
            echo '<p>' . esc_html__('No field configuration saved yet. Set an event type and save the event first.', 'hmw-events') . '</p>';
            return;
        }

        if ($template_id) {
            $template = $this->template_service->get($template_id);
            $template_title = $template ? $template->title : __('Unknown', 'hmw-events');
        } else {
            $template_title = null;
        }

        $has_override = $this->override_service->has_override($post->ID);
        $apply_to_children = $this->override_service->is_applied_to_children($post->ID);

        $children = get_posts([
            'post_type'      => Event::POST_TYPE,
            'post_parent'    => $post->ID,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        $has_children = !empty($children);

        $this->current_post_id = (int) $post->ID;
        $this->current_has_children = $has_children;
        $this->current_has_override = $has_override;
        $this->current_apply_to_children = $apply_to_children;

        ?>
        <div id="hmwevents-override-container"
             data-post-id="<?php echo (int) $post->ID; ?>"
             data-has-children="<?php echo $has_children ? '1' : '0'; ?>">
            <?php if ($template_title): ?>
                <p style="margin-bottom:4px;">
                    <?php esc_html_e('This event uses template:', 'hmw-events'); ?>
                    <strong><?php echo esc_html($template_title); ?></strong>
                </p>
            <?php else: ?>
                <p style="margin-bottom:4px;">
                    <?php esc_html_e('This event uses event type defaults.', 'hmw-events'); ?>
                </p>
            <?php endif; ?>

            <?php if (!$has_override): ?>
                <p class="description" style="margin-bottom:8px;"><?php esc_html_e('No custom override configured.', 'hmw-events'); ?></p>
                <button type="button" class="button hmwevents-open-override"><?php esc_html_e('Create Field Override', 'hmw-events'); ?></button>
            <?php else: ?>
                <p class="description" style="color:#2271b1; margin-bottom:8px;"><?php esc_html_e('Custom override is active.', 'hmw-events'); ?></p>
                <button type="button" class="button hmwevents-open-override"><?php esc_html_e('Edit Override', 'hmw-events'); ?></button>
                <button type="button" class="button hmwevents-reset-override" style="color:#b32d2e;"><?php esc_html_e('Reset to Defaults', 'hmw-events'); ?></button>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_override_modal(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== Event::POST_TYPE) {
            return;
        }

        if ($this->current_post_id <= 0) {
            return;
        }
        ?>
        <div id="hmwevents-override-modal" style="display:none;">
            <div class="hmwevents-override-modal-body" style="padding:16px;">
                <p class="description" style="margin:0 0 8px;">
                    <?php esc_html_e('Drag fields between columns. Left = hidden, right = shown. Check Required to mark mandatory.', 'hmw-events'); ?>
                </p>

                <h4 style="margin:0 0 6px; font-size:14px;"><?php esc_html_e('ACF Event Fields', 'hmw-events'); ?></h4>
                <div class="hmwevents-override-dnd-container" id="hmwevents-override-acf-dnd">
                    <div class="hmwevents-override-dnd-panel">
                        <h5><?php esc_html_e('Hidden', 'hmw-events'); ?></h5>
                        <ul class="hmwevents-override-dnd-list hmwevents-override-hidden" id="hmwevents-override-acf-hidden"></ul>
                    </div>
                    <div class="hmwevents-override-dnd-panel">
                        <h5><?php esc_html_e('Included', 'hmw-events'); ?></h5>
                        <ul class="hmwevents-override-dnd-list hmwevents-override-included" id="hmwevents-override-acf-included"></ul>
                    </div>
                </div>

                <h4 style="margin:14px 0 6px; font-size:14px;"><?php esc_html_e('Registration Fields', 'hmw-events'); ?></h4>
                <div id="hmwevents-override-reg-fields"></div>

                <div id="hmwevents-override-modal-children" style="display:none; margin-top:10px;">
                    <label>
                        <input type="checkbox" id="hmwevents-override-apply-children">
                        <?php esc_html_e('Apply to all child sessions', 'hmw-events'); ?>
                    </label>
                </div>

                <div style="margin-top:14px; padding-top:10px; border-top:1px solid #dcdcde;">
                    <button type="button" class="button button-primary hmwevents-save-override"><?php esc_html_e('Save Override', 'hmw-events'); ?></button>
                    <span id="hmwevents-override-status" style="margin-left:8px;"></span>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_load_override(): void
    {
        $event_id = (int) ($_POST['event_id'] ?? 0);
        if (!$event_id || !current_user_can('edit_post', $event_id)) {
            wp_send_json_error();
        }

        check_ajax_referer('hmwevents_event_override', '_wpnonce');

        $resolved = $this->override_service->get_resolved_config($event_id);
        $override = $this->override_service->get_override($event_id);
        $has_override = $this->override_service->has_override($event_id);
        $apply_to_children = $this->override_service->is_applied_to_children($event_id);

        wp_send_json_success([
            'resolved'          => $resolved['field_config'],
            'override'          => $override,
            'has_override'      => $has_override,
            'apply_to_children' => $apply_to_children,
        ]);
    }

    public function ajax_save_override(): void
    {
        $event_id = (int) ($_POST['event_id'] ?? 0);
        if (!$event_id || !current_user_can('edit_post', $event_id)) {
            wp_send_json_error();
        }

        check_ajax_referer('hmwevents_event_override', '_wpnonce');

        $override_data = json_decode(wp_unslash($_POST['override_data'] ?? '{}'), true);
        if (!is_array($override_data)) {
            $override_data = [];
        }

        $apply_to_children = !empty($_POST['apply_to_children']);

        $this->override_service->save_override($event_id, $override_data, $apply_to_children);

        wp_send_json_success();
    }

    public function ajax_reset_override(): void
    {
        $event_id = (int) ($_POST['event_id'] ?? 0);
        if (!$event_id || !current_user_can('edit_post', $event_id)) {
            wp_send_json_error();
        }

        check_ajax_referer('hmwevents_event_override', '_wpnonce');

        $this->override_service->reset_override($event_id);

        wp_send_json_success();
    }
}
