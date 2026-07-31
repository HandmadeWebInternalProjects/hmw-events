<?php

namespace HMWEvents\Admin;

use HMWEvents\Services\EventTemplateService;
use HMWEvents\Registry\EventTypeRegistry;
use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Event Templates admin page and handlers.
 */
class EventTemplates
{
    private EventTemplateService $service;

    public function __construct()
    {
        $this->service = new EventTemplateService();

        add_action('admin_post_hmwevents_event_template_save', [$this, 'handle_save']);
        add_action('admin_post_hmwevents_event_template_retire', [$this, 'handle_retire_toggle']);
        add_action('admin_post_hmwevents_event_template_create_event', [$this, 'handle_create_event']);
        add_action('admin_post_hmwevents_event_template_reapply', [$this, 'handle_reapply_template']);
        add_action('admin_post_hmwevents_duplicate_template', [$this, 'handle_duplicate_template']);
    }

    public function render_page(): void
    {
        if (!current_user_can('edit_hmw_events') && !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'hmw-events'));
        }

        $selected_id = (int) ($_GET['template_id'] ?? 0);
        $templates = $this->service->get_all();
        $selected = $selected_id ? $this->service->get($selected_id) : null;
        $types = get_terms([
            'taxonomy'   => 'hmw_event_type',
            'hide_empty' => false,
        ]);

        $template_data = $selected
            ? wp_json_encode($selected->template_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : wp_json_encode($this->get_default_template_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($template_data)) {
            $template_data = '{}';
        }

        $this->localize_template_editor_data();

        $success = '';
        if (get_transient('hmwevents_success_notice')) {
            $success = get_transient('hmwevents_success_notice');
            delete_transient('hmwevents_success_notice');
        }
        if (get_transient('hmwevents_template_duplicated')) {
            delete_transient('hmwevents_template_duplicated');
            $success = $success ?: __('Template duplicated successfully.', 'hmw-events');
        }
        $error = get_transient('hmwevents_error_notice');
        if ($error) {
            delete_transient('hmwevents_error_notice');
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Event Templates', 'hmw-events'); ?></h1>
            <p><?php esc_html_e('Templates are global and can be used by any admin.', 'hmw-events'); ?></p>
            <?php if ($success): ?>
                <div class="notice notice-success"><p><?php echo esc_html($success); ?></p></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Existing Templates', 'hmw-events'); ?></h2>
            <table class="widefat striped" style="max-width: 980px; margin-bottom: 24px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'hmw-events'); ?></th>
                        <th><?php esc_html_e('Title', 'hmw-events'); ?></th>
                        <th><?php esc_html_e('Type', 'hmw-events'); ?></th>
                        <th><?php esc_html_e('Version', 'hmw-events'); ?></th>
                        <th><?php esc_html_e('Status', 'hmw-events'); ?></th>
                        <th><?php esc_html_e('Actions', 'hmw-events'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($templates)) : ?>
                        <tr><td colspan="6"><?php esc_html_e('No templates found yet.', 'hmw-events'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($templates as $template) : ?>
                            <?php $version = (int) (($template->template_data['template_version'] ?? 1)); ?>
                            <tr>
                                <td><?php echo (int) $template->id; ?></td>
                                <td><?php echo esc_html($template->title); ?></td>
                                <td><code><?php echo esc_html($template->event_type_slug); ?></code></td>
                                <td><?php echo esc_html((string) $version); ?></td>
                                <td><?php echo $template->is_retired ? esc_html__('Retired', 'hmw-events') : esc_html__('Active', 'hmw-events'); ?></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'hmwevents-event-templates', 'template_id' => (int) $template->id], admin_url('admin.php'))); ?>"><?php esc_html_e('Edit', 'hmw-events'); ?></a>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hmwevents_duplicate_template&id=' . (int) $template->id), 'hmwevents_duplicate_template_' . (int) $template->id)); ?>" style="margin-left: 6px;"><?php esc_html_e('Duplicate', 'hmw-events'); ?></a>
                                    <form style="display:inline-block; margin-left: 6px;" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="hmwevents_event_template_retire" />
                                        <input type="hidden" name="template_id" value="<?php echo (int) $template->id; ?>" />
                                        <input type="hidden" name="retire" value="<?php echo $template->is_retired ? '0' : '1'; ?>" />
                                        <?php wp_nonce_field('hmwevents_event_template_retire'); ?>
                                        <button type="submit" class="button button-small"><?php echo $template->is_retired ? esc_html__('Unretire', 'hmw-events') : esc_html__('Retire', 'hmw-events'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php echo $selected ? esc_html__('Edit Template', 'hmw-events') : esc_html__('Create Template', 'hmw-events'); ?></h2>
            <p class="hmwevents-editor-notice"><?php esc_html_e('Use the visual editor below. Select an Event Type to load registry presets, then adjust as needed. All changes auto-save to a hidden JSON field on submit.', 'hmw-events'); ?></p>

            <form id="hmwevents-template-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width: 1080px;">
                <input type="hidden" name="action" value="hmwevents_event_template_save" />
                <input type="hidden" name="template_id" value="<?php echo (int) ($selected->id ?? 0); ?>" />
                <?php wp_nonce_field('hmwevents_event_template_save'); ?>
                <input type="hidden" id="hmwevents_template_data_raw" name="template_data" value="<?php echo esc_attr($template_data); ?>" />

                <div id="hmwevents-template-save-status" style="display:none;"></div>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="hmwevents_template_title"><?php esc_html_e('Title', 'hmw-events'); ?></label></th>
                        <td><input class="regular-text" id="hmwevents_template_title" name="title" type="text" value="<?php echo esc_attr($selected->title ?? ''); ?>" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hmwevents_template_type"><?php esc_html_e('Event Type', 'hmw-events'); ?></label></th>
                        <td>
                            <select id="hmwevents_template_type" name="event_type_slug" required>
                                <option value=""><?php esc_html_e('Select type to load presets', 'hmw-events'); ?></option>
                                <?php foreach ((array) $types as $type) : ?>
                                    <option value="<?php echo esc_attr($type->slug); ?>" <?php selected(($selected->event_type_slug ?? ''), $type->slug); ?>>
                                        <?php echo esc_html($type->name . ' (' . $type->slug . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Selecting a type loads its default field visibility and meta values.', 'hmw-events'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hmwevents_title_pattern"><?php esc_html_e('Post Title Pattern', 'hmw-events'); ?></label></th>
                        <td><input class="regular-text" id="hmwevents_title_pattern" type="text" value="" placeholder="e.g. {event_type} - {date}" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hmwevents_post_content"><?php esc_html_e('Default Post Content', 'hmw-events'); ?></label></th>
                        <td><textarea id="hmwevents_post_content" rows="3" style="width:100%;"></textarea></td>
                    </tr>
                </table>

                <!-- Event Fields -->
                <div class="hmwevents-section">
                    <h3><?php esc_html_e('Event Fields (from ACF)', 'hmw-events'); ?></h3>
                    <p class="description"><?php esc_html_e('Drag fields between columns. Fields in the left column are hidden from the event edit screen. Check \'Required\' to make a field mandatory.', 'hmw-events'); ?></p>
                    <div id="hmwevents-event-fields-container"></div>
                </div>

                <!-- Registration Fields -->
                <div class="hmwevents-section">
                    <h3><?php esc_html_e('Registration Form Builder', 'hmw-events'); ?></h3>
                    <p class="description"><?php esc_html_e('Drag to reorder fields and move between section groups. Click a field name to edit its label and placeholder. Use the section dropdown to reassign fields.', 'hmw-events'); ?></p>
                    <div id="hmwevents-registration-fields-container"></div>
                </div>

                <!-- Event Meta Defaults -->
                <div class="hmwevents-section">
                    <h3><?php esc_html_e('Event Meta Defaults', 'hmw-events'); ?></h3>
                    <p class="description"><?php esc_html_e('Default values pre-filled when creating an event from this template. Leave blank for no default.', 'hmw-events'); ?></p>
                    <div id="hmwevents-event-meta-defaults"></div>
                </div>

                <!-- JSON preview -->
                <div class="hmwevents-toggle-row">
                    <a href="#" class="hmwevents-toggle-json-preview"><?php esc_html_e('Show JSON', 'hmw-events'); ?></a>
                    &nbsp;|&nbsp;
                    <a href="#" class="hmwevents-toggle-advanced"><?php esc_html_e('Show advanced editor', 'hmw-events'); ?></a>
                </div>
                <div id="hmwevents-json-preview">
                    <pre id="hmwevents_template_data_raw_display" style="margin:0; white-space:pre-wrap; font-size:11px;"></pre>
                </div>
                <div id="hmwevents-advanced-edit">
                    <textarea id="hmwevents-raw-json" style="width:100%; height:300px; font-family:monospace; font-size:12px;"></textarea>
                    <p class="description"><?php esc_html_e('Edit the raw JSON template data directly. Changes are saved when you click "Save Template" at the bottom.', 'hmw-events'); ?></p>
                </div>

                <?php submit_button($selected ? __('Update Template', 'hmw-events') : __('Create Template', 'hmw-events')); ?>
            </form>

            <?php if ($selected) : ?>
                <h2><?php esc_html_e('Create Event from Template', 'hmw-events'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width: 980px; margin-bottom: 24px;">
                    <input type="hidden" name="action" value="hmwevents_event_template_create_event" />
                    <input type="hidden" name="template_id" value="<?php echo (int) $selected->id; ?>" />
                    <?php wp_nonce_field('hmwevents_event_template_create_event'); ?>
                    <p>
                        <label for="hmwevents_new_event_title"><?php esc_html_e('Optional event title override', 'hmw-events'); ?></label><br>
                        <input class="regular-text" id="hmwevents_new_event_title" type="text" name="post_title" value="" />
                    </p>
                    <?php submit_button(__('Create Draft Event', 'hmw-events'), 'secondary', 'submit', false); ?>
                </form>

                <h2><?php esc_html_e('Re-apply Template to Existing Event', 'hmw-events'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width: 980px;">
                    <input type="hidden" name="action" value="hmwevents_event_template_reapply" />
                    <input type="hidden" name="template_id" value="<?php echo (int) $selected->id; ?>" />
                    <?php wp_nonce_field('hmwevents_event_template_reapply'); ?>
                    <p>
                        <label for="hmwevents_reapply_event_id"><?php esc_html_e('Event ID', 'hmw-events'); ?></label><br>
                        <input id="hmwevents_reapply_event_id" type="number" min="1" name="event_id" required />
                    </p>
                    <p><strong><?php esc_html_e('Sections to apply', 'hmw-events'); ?></strong></p>
                    <p>
                        <label><input type="checkbox" name="sections[]" value="event_meta" checked> <?php esc_html_e('Event Meta', 'hmw-events'); ?></label><br>
                        <label><input type="checkbox" name="sections[]" value="field_config" checked> <?php esc_html_e('Field Config', 'hmw-events'); ?></label><br>
                        <label><input type="checkbox" name="sections[]" value="defaults" checked> <?php esc_html_e('Snapshot Defaults', 'hmw-events'); ?></label><br>
                        <label><input type="checkbox" name="sections[]" value="attendance_options" checked> <?php esc_html_e('Attendance Options', 'hmw-events'); ?></label>
                    </p>
                    <?php submit_button(__('Re-apply Template', 'hmw-events'), 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>
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
                <p style="margin-top:12px;">
                    <button type="button" class="button button-primary" id="hmwevents-modal-save"><?php esc_html_e('Save Field', 'hmw-events'); ?></button>
                    <button type="button" class="button" onclick="tb_remove(); return false;"><?php esc_html_e('Cancel', 'hmw-events'); ?></button>
                    <button type="button" class="button button-link-delete" id="hmwevents-modal-delete" style="float:right;color:#b32d2e;"><?php esc_html_e('Delete Field', 'hmw-events'); ?></button>
                </p>
            </div>
        </div>
        <?php
    }

    private function localize_template_editor_data(): void
    {
        $acf_fields = $this->get_acf_event_fields();
        $reg_fields = $this->get_registration_fields_for_editor();
        $presets = $this->get_registry_presets_for_editor();

        wp_localize_script('hmwevents-event-templates', 'hmwEventTemplates', [
            'acfEventFields'       => $acf_fields,
            'registrationFields'   => $reg_fields,
            'registryPresets'      => $presets,
            'sectionLabels'        => $this->get_section_labels(),
            'registrationPresets'  => \HMWEvents\Registry\RegistrationFieldRegistry::presets(),
        ]);
    }

    private function get_section_labels(): array
    {
        return [
            'contact'           => __('Contact', 'hmw-events'),
            'address'           => __('Address', 'hmw-events'),
            'professional'      => __('Professional', 'hmw-events'),
            'documents'         => __('Documents', 'hmw-events'),
            'additional'        => __('Additional', 'hmw-events'),
        ];
    }

    private function get_acf_event_fields(): array
    {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $this->get_acf_fields_from_json();
        }

        $groups = acf_get_field_groups(['post_type' => 'hmw_event']);
        $fields = [];

        foreach ($groups as $group) {
            $group_fields = acf_get_fields($group['key']);
            if (!is_array($group_fields)) {
                continue;
            }

            foreach ($group_fields as $field) {
                $meta_name = $field['name'] ?? '';
                if ($meta_name === '' || !str_starts_with($meta_name, '_event_') && !str_starts_with($meta_name, 'event_')) {
                    continue;
                }

                $normalized = $meta_name;
                if (str_starts_with($normalized, '_event_')) {
                    $normalized = substr($normalized, 1);
                }

                $fields[] = [
                    'key'   => $normalized,
                    'label' => $field['label'] ?? $normalized,
                    'type'  => $field['type'] ?? 'text',
                    'name'  => $meta_name,
                ];
            }
        }

        $fields = empty($fields) ? $this->get_acf_fields_from_json() : $fields;

        return $this->group_recurrence_fields($fields);
    }

    private function get_acf_fields_from_json(): array
    {
        $json_file = HMWEvents_ABSPATH . 'acf-json/group_hmw_event_details.json';
        if (!file_exists($json_file)) {
            return [];
        }

        $contents = file_get_contents($json_file);
        $data = json_decode((string) $contents, true);
        if (!is_array($data) || empty($data['fields'])) {
            return [];
        }

        $fields = [];
        foreach ($data['fields'] as $field) {
            $meta_name = $field['name'] ?? '';
            if ($meta_name === '' || !str_starts_with($meta_name, '_event_') && !str_starts_with($meta_name, 'event_')) {
                continue;
            }

            $normalized = $meta_name;
            if (str_starts_with($normalized, '_event_')) {
                $normalized = substr($normalized, 1);
            }

            $fields[] = [
                'key'   => $normalized,
                'label' => $field['label'] ?? $normalized,
                'type'  => $field['type'] ?? 'text',
                'name'  => $meta_name,
            ];
        }

        return $fields;
    }

    private function group_recurrence_fields(array $fields): array
    {
        $child_keys = \HMWEvents\Registry\EventTypeRegistry::RECURRENCE_FIELD_KEYS;
        $child_set = array_flip($child_keys);

        $child_indices = [];
        $has_children = false;

        foreach ($fields as $i => $f) {
            if (isset($child_set[$f['key']])) {
                $child_indices[$i] = true;
                $has_children = true;
            }
        }

        if (!$has_children) {
            return $fields;
        }

        $first_child_index = null;
        foreach ($child_indices as $idx => $_) {
            $first_child_index = $idx;
            break;
        }

        $grouped = [];
        $group_inserted = false;

        foreach ($fields as $i => $f) {
            if (isset($child_indices[$i])) {
                if (!$group_inserted) {
                    $grouped[] = [
                        'key'      => 'event_recurrence',
                        'label'    => __('Recurrence Settings', 'hmw-events'),
                        'type'     => 'group',
                        'children' => $child_keys,
                    ];
                    $group_inserted = true;
                }
                continue;
            }

            $grouped[] = $f;
        }

        return $grouped;
    }

    private function get_registration_fields_for_editor(): array
    {
        $all = RegistrationFieldRegistry::all();
        $fields = [];

        foreach ($all as $key => $def) {
            $audience = $def['audience_variants'] ?? ['*'];
            if (in_array('*', $audience, true)) {
                $audience = [];
            }

            $fields[] = [
                'key'                 => sanitize_key((string) $key),
                'label'               => $def['label'] ?? $key,
                'type'                => $def['type'] ?? 'text',
                'section'             => $def['section'] ?? '',
                'default_section'     => $def['section'] ?? '',
                'default_width'       => $def['width'] ?? 'full',
                'default_placeholder' => $def['placeholder'] ?? '',
                'audience_variants'   => array_values($audience),
            ];
        }

        return $fields;
    }

    private function get_registry_presets_for_editor(): array
    {
        $all = EventTypeRegistry::all();
        $presets = [];

        foreach ($all as $slug => $config) {
            $presets[$slug] = [
                'hidden_fields'   => $config['hidden_fields'] ?? [],
                'required_fields' => $config['required_fields'] ?? [],
                'default_meta'    => $config['default_meta'] ?? [],
            ];
        }

        return $presets;
    }

    public function handle_save(): void
    {
        if (!current_user_can('edit_hmw_events') && !current_user_can('manage_options')) {
            wp_die(-1);
        }

        check_admin_referer('hmwevents_event_template_save');

        $template_id = (int) ($_POST['template_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        $event_type_slug = sanitize_text_field($_POST['event_type_slug'] ?? '');
        $raw_template_data = wp_unslash($_POST['template_data'] ?? '');

        $decoded = json_decode((string) $raw_template_data, true);
        if (!is_array($decoded)) {
            set_transient('hmwevents_error_notice', __('Invalid JSON for template data.', 'hmw-events'), 30);
            wp_safe_redirect(admin_url('admin.php?page=hmwevents-event-templates'));
            exit;
        }

        $payload = [
            'title'           => $title,
            'event_type_slug' => $event_type_slug,
            'template_data'   => $decoded,
        ];

        if ($template_id > 0) {
            $ok = $this->service->update($template_id, $payload);
            if ($ok) {
                set_transient('hmwevents_success_notice', __('Template updated.', 'hmw-events'), 30);
            } else {
                set_transient('hmwevents_error_notice', __('Template update failed. Check schema and field keys.', 'hmw-events'), 30);
            }
            wp_safe_redirect(admin_url('admin.php?page=hmwevents-event-templates&template_id=' . $template_id));
            exit;
        }

        $created_id = $this->service->create($payload);
        if ($created_id) {
            set_transient('hmwevents_success_notice', __('Template created.', 'hmw-events'), 30);
            wp_safe_redirect(admin_url('admin.php?page=hmwevents-event-templates&template_id=' . (int) $created_id));
            exit;
        }

        set_transient('hmwevents_error_notice', __('Template creation failed. Check schema and field keys.', 'hmw-events'), 30);
        wp_safe_redirect(admin_url('admin.php?page=hmwevents-event-templates'));
        exit;
    }

    public function handle_retire_toggle(): void
    {
        if (!current_user_can('edit_hmw_events') && !current_user_can('manage_options')) {
            wp_die(-1);
        }

        check_admin_referer('hmwevents_event_template_retire');

        $template_id = (int) ($_POST['template_id'] ?? 0);
        $retire = (int) ($_POST['retire'] ?? 0);

        $ok = $retire ? $this->service->retire($template_id) : $this->service->unretire($template_id);

        if ($ok) {
            set_transient('hmwevents_success_notice', $retire ? __('Template retired.', 'hmw-events') : __('Template reactivated.', 'hmw-events'), 30);
        } else {
            set_transient('hmwevents_error_notice', __('Unable to update template status.', 'hmw-events'), 30);
        }

        wp_safe_redirect(admin_url('admin.php?page=hmwevents-event-templates'));
        exit;
    }

    public function handle_create_event(): void
    {
        if (!current_user_can('edit_hmw_events') && !current_user_can('manage_options')) {
            wp_die(-1);
        }

        check_admin_referer('hmwevents_event_template_create_event');

        $template_id = (int) ($_POST['template_id'] ?? 0);
        $post_title = sanitize_text_field($_POST['post_title'] ?? '');
        $result = $this->service->create_event_from_template($template_id, [
            'post_title'  => $post_title,
            'post_status' => 'draft',
        ]);

        if (is_wp_error($result)) {
            set_transient('hmwevents_error_notice', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=hmwevents-event-templates&template_id=' . $template_id));
            exit;
        }

        set_transient('hmwevents_success_notice', __('Draft event created from template.', 'hmw-events'), 30);
        wp_safe_redirect(get_edit_post_link((int) $result, 'redirect'));
        exit;
    }

    public function handle_reapply_template(): void
    {
        if (!current_user_can('edit_hmw_events') && !current_user_can('manage_options')) {
            wp_die(-1);
        }

        check_admin_referer('hmwevents_event_template_reapply');

        $template_id = (int) ($_POST['template_id'] ?? 0);
        $event_id = (int) ($_POST['event_id'] ?? 0);
        $sections = array_map('sanitize_key', (array) ($_POST['sections'] ?? []));

        $result = $this->service->reapply_template_to_event($event_id, $template_id, $sections);
        if (is_wp_error($result)) {
            set_transient('hmwevents_error_notice', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=hmwevents-event-templates&template_id=' . $template_id));
            exit;
        }

        set_transient('hmwevents_success_notice', __('Template re-applied to event.', 'hmw-events'), 30);
        wp_safe_redirect(get_edit_post_link($event_id, 'redirect'));
        exit;
    }

    public function handle_duplicate_template(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id || (!current_user_can('edit_hmw_events') && !current_user_can('manage_options'))) {
            wp_die(__('Permission denied.', 'hmw-events'));
        }
        check_admin_referer('hmwevents_duplicate_template_' . $id);

        $service = new EventTemplateService();
        $template = $service->get($id);
        if (!$template) {
            wp_die(__('Template not found.', 'hmw-events'));
        }

        $new_id = $service->create([
            'title'           => $template->title . ' (' . __('Copy', 'hmw-events') . ')',
            'event_type_slug' => $template->event_type_slug,
            'template_data'   => (array) $template->template_data,
        ]);

        if (!$new_id) {
            wp_die(__('Failed to duplicate template.', 'hmw-events'));
        }

        set_transient('hmwevents_template_duplicated', true, 30);
        wp_redirect(admin_url('admin.php?page=hmwevents-event-templates'));
        exit;
    }

    private function get_default_template_data(): array
    {
        return [
            'schema_version'      => 2,
            'template_version'    => 1,
            'post'                => [
                'title_pattern' => '',
                'post_content'  => '',
            ],
            'event_fields'        => [
                'required' => ['event_start_date', 'event_end_date'],
                'optional' => [],
                'hidden'   => [],
            ],
            'registration_fields' => [
                'required'        => ['first_name', 'last_name', 'email', 'phone'],
                'optional'        => [],
                'hidden'          => [],
                'order'           => [],
                'field_overrides' => new \stdClass(),
            ],
            'defaults'            => [
                'event_meta'         => [
                    'event_capacity' => 20,
                ],
                'registration'       => [
                    'attendance_default' => 'individual',
                    'field_overrides'    => new \stdClass(),
                ],
                'attendance_options' => [
                    [
                        'option_type' => 'individual',
                        'label'       => 'Individual',
                        'price'       => 0,
                    ],
                ],
            ],
        ];
    }
}
