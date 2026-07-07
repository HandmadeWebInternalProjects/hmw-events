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

        ?>
        <style>
        .hmwevents-field-hidden td { opacity: 0.4; }
        .hmwevents-field-required td { font-weight: 600; background: #f0f6fc; }
        .hmwevents-audience-badge { font-size: 10px; background: #e0e0e0; color: #555; padding: 1px 5px; border-radius: 3px; margin-left: 4px; }
        .hmwevents-section { margin-bottom: 24px; background: #fff; border: 1px solid #c3c4c7; padding: 16px; }
        .hmwevents-section h3 { margin-top: 0; padding-bottom: 8px; border-bottom: 1px solid #eee; }
        .hmwevents-section table { margin-top: 8px; }
        .hmwevents-editor-notice { padding: 8px 12px; margin: 0 0 12px; background: #f0f6fc; border-left: 4px solid #2271b1; }
        #hmwevents-json-preview { background: #f6f7f7; padding: 12px; overflow-x: auto; max-height: 350px; display: none; margin-top: 8px; }
        #hmwevents-advanced-edit { display: none; margin-top: 8px; }
        #hmwevents-template-save-status { margin: 8px 0; }
        .hmwevents-toggle-row { margin: 8px 0; font-size: 12px; }
        .hmwevents-toggle-row a { text-decoration: none; }
        </style>
        <div class="wrap">
            <h1><?php esc_html_e('Event Templates', 'hmw-events'); ?></h1>
            <p><?php esc_html_e('Templates are global and can be used by any admin.', 'hmw-events'); ?></p>

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
                    <p class="description"><?php esc_html_e('Set visibility for each ACF event detail field. Hidden fields are removed from the edit screen. Required fields are marked mandatory.', 'hmw-events'); ?></p>
                    <div id="hmwevents-event-fields-container"></div>
                </div>

                <!-- Registration Fields -->
                <div class="hmwevents-section">
                    <h3><?php esc_html_e('Registration Fields', 'hmw-events'); ?></h3>
                    <p class="description"><?php esc_html_e('Set visibility for registration form fields. Audience badges show which registrant types see the field.', 'hmw-events'); ?></p>
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
                    <p class="description"><?php esc_html_e('Edit the raw JSON directly. Changes here overwrite the visual editor on save only if you modify the hidden field manually.', 'hmw-events'); ?></p>
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
        <?php
    }

    private function localize_template_editor_data(): void
    {
        $acf_fields = $this->get_acf_event_fields();
        $reg_fields = $this->get_registration_fields_for_editor();
        $presets = $this->get_registry_presets_for_editor();

        wp_localize_script('hmwevents-event-templates', 'hmwEventTemplates', [
            'acfEventFields'      => $acf_fields,
            'registrationFields'  => $reg_fields,
            'registryPresets'     => $presets,
        ]);
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

        return empty($fields) ? $this->get_acf_fields_from_json() : $fields;
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
                'key'               => sanitize_key((string) $key),
                'label'             => $def['label'] ?? $key,
                'type'              => $def['type'] ?? 'text',
                'section'           => $def['section'] ?? '',
                'audience_variants' => array_values($audience),
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
            set_transient('lhmwevents_error_notice', __('Invalid JSON for template data.', 'hmw-events'), 30);
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
                set_transient('lhmwevents_success_notice', __('Template updated.', 'hmw-events'), 30);
            } else {
                set_transient('lhmwevents_error_notice', __('Template update failed. Check schema and field keys.', 'hmw-events'), 30);
            }
            wp_safe_redirect(admin_url('admin.php?page=hmwevents-event-templates&template_id=' . $template_id));
            exit;
        }

        $created_id = $this->service->create($payload);
        if ($created_id) {
            set_transient('lhmwevents_success_notice', __('Template created.', 'hmw-events'), 30);
            wp_safe_redirect(admin_url('admin.php?page=hmwevents-event-templates&template_id=' . (int) $created_id));
            exit;
        }

        set_transient('lhmwevents_error_notice', __('Template creation failed. Check schema and field keys.', 'hmw-events'), 30);
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
            set_transient('lhmwevents_success_notice', $retire ? __('Template retired.', 'hmw-events') : __('Template reactivated.', 'hmw-events'), 30);
        } else {
            set_transient('lhmwevents_error_notice', __('Unable to update template status.', 'hmw-events'), 30);
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
            set_transient('lhmwevents_error_notice', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=hmwevents-event-templates&template_id=' . $template_id));
            exit;
        }

        set_transient('lhmwevents_success_notice', __('Draft event created from template.', 'hmw-events'), 30);
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
            set_transient('lhmwevents_error_notice', $result->get_error_message(), 30);
            wp_safe_redirect(admin_url('admin.php?page=hmwevents-event-templates&template_id=' . $template_id));
            exit;
        }

        set_transient('lhmwevents_success_notice', __('Template re-applied to event.', 'hmw-events'), 30);
        wp_safe_redirect(get_edit_post_link($event_id, 'redirect'));
        exit;
    }

    private function get_default_template_data(): array
    {
        return [
            'schema_version'      => 1,
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
                'required' => ['first_name', 'last_name', 'email', 'phone'],
                'optional' => [],
                'hidden'   => [],
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
