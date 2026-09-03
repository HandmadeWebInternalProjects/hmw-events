<?php

namespace HMWEvents\Admin;

use HMWEvents\Helpers\EventFieldConfig;
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
        $field_config = EventFieldConfig::read((int) $post->ID);
        if ($field_config === null || empty($field_config)) {
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
        $has_children = $this->override_service->has_child_sessions((int) $post->ID);

        $this->current_post_id = (int) $post->ID;
        $this->current_has_children = $has_children;
        $this->current_has_override = $has_override;
        $this->current_apply_to_children = $apply_to_children;

        ?>
        <div id="hmwevents-override-container"
             data-post-id="<?php echo (int) $post->ID; ?>"
             data-has-children="<?php echo $has_children ? '1' : '0'; ?>"
             data-has-override="<?php echo $has_override ? '1' : '0'; ?>">
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

                <div id="hmwevents-override-modal-children" style="margin-top:10px;">
                    <label>
                        <input type="checkbox" id="hmwevents-override-apply-children" <?php disabled(! $this->current_has_children); ?>>
                        <?php esc_html_e('Apply to all child sessions', 'hmw-events'); ?>
                    </label>
                    <p class="description" id="hmwevents-override-children-note" style="margin:4px 0 0 22px;<?php echo $this->current_has_children ? ' display:none;' : ''; ?>">
                        <?php esc_html_e('This event has no sessions attached yet, so the override applies to this event only.', 'hmw-events'); ?>
                    </p>
                </div>

                <div style="margin-top:14px; padding-top:10px; border-top:1px solid #dcdcde;">
                    <button type="button" class="button button-primary hmwevents-save-override"><?php esc_html_e('Save Override', 'hmw-events'); ?></button>
                    <span id="hmwevents-override-status" style="margin-left:8px;"></span>
                </div>
            </div>
        </div>
        <div id="hmwevents-field-modal" style="display:none;">
            <div class="hmwevents-modal-body">
                <div class="hmwevents-modal-presets">
                    <p><strong><?php esc_html_e('Quick Presets', 'hmw-events'); ?></strong></p>
                    <div id="hmwevents-modal-preset-buttons"></div>
                    <hr>
                </div>
                <table class="form-table">
                    <tr>
                        <th><label for="hmwevents-modal-key"><?php esc_html_e('Field Key', 'hmw-events'); ?></label></th>
                        <td><input type="text" id="hmwevents-modal-key" class="regular-text" placeholder="e.g. custom_field"></td>
                    </tr>
                    <tr>
                        <th><label for="hmwevents-modal-label"><?php esc_html_e('Label', 'hmw-events'); ?></label></th>
                        <td><input type="text" id="hmwevents-modal-label" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="hmwevents-modal-placeholder"><?php esc_html_e('Placeholder', 'hmw-events'); ?></label></th>
                        <td><input type="text" id="hmwevents-modal-placeholder" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="hmwevents-modal-type"><?php esc_html_e('Type', 'hmw-events'); ?></label></th>
                        <td>
                            <select id="hmwevents-modal-type">
                                <option value="text"><?php esc_html_e('Text', 'hmw-events'); ?></option>
                                <option value="email"><?php esc_html_e('Email', 'hmw-events'); ?></option>
                                <option value="tel"><?php esc_html_e('Phone', 'hmw-events'); ?></option>
                                <option value="textarea"><?php esc_html_e('Textarea', 'hmw-events'); ?></option>
                                <option value="select"><?php esc_html_e('Select / Dropdown', 'hmw-events'); ?></option>
                                <option value="checkbox"><?php esc_html_e('Checkbox', 'hmw-events'); ?></option>
                                <option value="radio"><?php esc_html_e('Radio', 'hmw-events'); ?></option>
                                <option value="date"><?php esc_html_e('Date', 'hmw-events'); ?></option>
                                <option value="number"><?php esc_html_e('Number', 'hmw-events'); ?></option>
                                <option value="file"><?php esc_html_e('File Upload', 'hmw-events'); ?></option>
                                <option value="session_picker"><?php esc_html_e('Session Picker', 'hmw-events'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hmwevents-modal-required"><?php esc_html_e('Required', 'hmw-events'); ?></label></th>
                        <td><input type="checkbox" id="hmwevents-modal-required"> <?php esc_html_e('Make this field required', 'hmw-events'); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Width', 'hmw-events'); ?></th>
                        <td>
                            <label><input type="radio" name="hmwevents-modal-width" value="half"> <?php esc_html_e('Half', 'hmw-events'); ?></label>
                            <label style="margin-left:12px;"><input type="radio" name="hmwevents-modal-width" value="full" checked> <?php esc_html_e('Full', 'hmw-events'); ?></label>
                        </td>
                    </tr>
                </table>
                <div id="hmwevents-modal-options-panel" style="display:none;">
                    <h4><?php esc_html_e('Options', 'hmw-events'); ?></h4>
                    <ul id="hmwevents-modal-options-list" class="hmwevents-modal-options-list"></ul>
                    <button type="button" class="button button-small" id="hmwevents-modal-add-option" style="margin-top:4px;">+ <?php esc_html_e('Add Option', 'hmw-events'); ?></button>
                </div>
                <p id="hmwevents-modal-per-attendee-row" style="display:none;">
                    <label><input type="checkbox" id="hmwevents-modal-per-attendee"> <?php esc_html_e('Repeat this field for each attendee (multi-booking)', 'hmw-events'); ?></label>
                </p>
                <p>
                    <strong><?php esc_html_e('Attendance Options', 'hmw-events'); ?></strong><br>
                    <span class="description"><?php esc_html_e('Leave all unchecked to show this field for every attendance option.', 'hmw-events'); ?></span><br>
                    <label><input type="checkbox" class="hmwevents-modal-attendance-type" value="individual"> <?php esc_html_e('Individual', 'hmw-events'); ?></label>
                    <label><input type="checkbox" class="hmwevents-modal-attendance-type" value="parent"> <?php esc_html_e('Parent', 'hmw-events'); ?></label>
                    <label><input type="checkbox" class="hmwevents-modal-attendance-type" value="parent_child"> <?php esc_html_e('Parent / Child', 'hmw-events'); ?></label>
                    <label><input type="checkbox" class="hmwevents-modal-attendance-type" value="couple"> <?php esc_html_e('Couple', 'hmw-events'); ?></label>
                    <label><input type="checkbox" class="hmwevents-modal-attendance-type" value="professional"> <?php esc_html_e('Professional', 'hmw-events'); ?></label>
                </p>
                <p style="margin-top:12px;">
                    <button type="button" class="button button-primary" id="hmwevents-modal-save"><?php esc_html_e('Save Field', 'hmw-events'); ?></button>
                    <button type="button" class="button" onclick="tb_remove(); return false;"><?php esc_html_e('Cancel', 'hmw-events'); ?></button>
                    <button type="button" class="button button-link-delete" id="hmwevents-modal-delete" style="float:right;color:#b32d2e;"><?php esc_html_e('Delete Field', 'hmw-events'); ?></button>
                </p>
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
            'has_children'      => $this->override_service->has_child_sessions($event_id),
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
