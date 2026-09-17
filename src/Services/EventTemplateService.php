<?php

/**
 * Event Template Service.
 *
 * CRUD operations for event templates stored in hmwevents_event_templates.
 * Also handles the "create event from template" flow.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventTemplateService
{
    private string $table;
    private TemplateSchemaValidator $validator;
    private TemplateResolver $resolver;

    public function __construct()
    {
        $this->table = DatabaseService::get_table_name('event_templates');
        $this->validator = new TemplateSchemaValidator();
        $this->resolver = new TemplateResolver($this->validator);
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('wp_ajax_hmwevents_create_event_from_template', [$this, 'ajax_create_from_template']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_template_scripts']);

        // Apply template defaults when creating a new event
        add_filter('default_content', [$this, 'apply_template_defaults_on_new_post'], 10, 2);
    }

    // ================================================================
    // CRUD
    // ================================================================

    /**
     * Create a new template.
     *
     * @param array $data {title, event_type_slug, template_data, created_by}
     * @return int|false Template ID or false on failure.
     */
    public function create(array $data): int|false
    {
        global $wpdb;

        $title     = sanitize_text_field($data['title'] ?? '');
        $type_slug = sanitize_text_field($data['event_type_slug'] ?? '');
        $created_by = (int) ($data['created_by'] ?? get_current_user_id());

        if (empty($title) || empty($type_slug)) {
            return false;
        }

        $normalized = $this->validator->normalize((array) ($data['template_data'] ?? []));
        if (is_wp_error($normalized)) {
            error_log('HMWEvents: Invalid event template payload: ' . $normalized->get_error_message());
            return false;
        }

        $json_data = wp_json_encode($normalized);

        $result = $wpdb->insert(
            $this->table,
            [
                'title'           => $title,
                'event_type_slug' => $type_slug,
                'template_data'   => $json_data,
                'created_by'      => $created_by,
                'is_active'       => 1,
                'is_retired'      => 0,
                'created_at'      => current_time('mysql'),
                'updated_at'      => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s']
        );

        if ($result === false) {
            error_log('HMWEvents: Failed to create event template: ' . $wpdb->last_error);
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Get a single template by ID.
     */
    public function get(int $id): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ));

        if ($row) {
            $row->template_data = json_decode($row->template_data, true) ?: [];
        }

        return $row;
    }

    /**
     * Get all templates, optionally filtered.
     *
     * @param array $filters {event_type_slug, is_active, is_retired, created_by, search}
     * @return object[]
     */
    public function get_all(array $filters = []): array
    {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['event_type_slug'])) {
            $where[] = 'event_type_slug = %s';
            $params[] = $filters['event_type_slug'];
        }

        if (isset($filters['is_active'])) {
            $where[] = 'is_active = %d';
            $params[] = (int) $filters['is_active'];
        }

        if (isset($filters['is_retired'])) {
            $where[] = 'is_retired = %d';
            $params[] = (int) $filters['is_retired'];
        }

        if (!empty($filters['created_by'])) {
            $where[] = 'created_by = %d';
            $params[] = (int) $filters['created_by'];
        }

        if (!empty($filters['search'])) {
            $where[] = 'title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['search']) . '%';
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$this->table} WHERE {$where_clause} ORDER BY title ASC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $rows = $wpdb->get_results($sql);

        foreach ($rows as $row) {
            $row->template_data = json_decode($row->template_data, true) ?: [];
        }

        return $rows;
    }

    /**
     * Update a template.
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $updates = [];
        $formats = [];
        $existing = $this->get($id);

        if (isset($data['title'])) {
            $updates['title'] = sanitize_text_field($data['title']);
            $formats[] = '%s';
        }

        if (isset($data['event_type_slug'])) {
            $updates['event_type_slug'] = sanitize_text_field($data['event_type_slug']);
            $formats[] = '%s';
        }

        if (isset($data['template_data'])) {
            $template_data = (array) $data['template_data'];

            if (!isset($template_data['template_version'])) {
                $current_version = (int) (($existing && is_array($existing->template_data ?? null))
                    ? ($existing->template_data['template_version'] ?? 1)
                    : 1);
                $template_data['template_version'] = $current_version + 1;
            }

            $normalized = $this->validator->normalize($template_data);
            if (is_wp_error($normalized)) {
                error_log('HMWEvents: Invalid event template payload on update: ' . $normalized->get_error_message());
                return false;
            }

            $updates['template_data'] = wp_json_encode($normalized);
            $formats[] = '%s';
        }

        if (isset($data['is_active'])) {
            $updates['is_active'] = (int) $data['is_active'];
            $formats[] = '%d';
        }

        if (isset($data['is_retired'])) {
            $updates['is_retired'] = (int) $data['is_retired'];
            $formats[] = '%d';
        }

        if (empty($updates)) {
            return false;
        }

        $updates['updated_at'] = current_time('mysql');
        $formats[] = '%s';

        $result = $wpdb->update(
            $this->table,
            $updates,
            ['id' => $id],
            $formats,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete a template permanently.
     */
    public function delete(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete($this->table, ['id' => $id], ['%d']);
    }

    /**
     * Retire a template (soft-delete via is_retired flag).
     */
    public function retire(int $id): bool
    {
        return $this->update($id, ['is_retired' => 1, 'is_active' => 0]);
    }

    /**
     * Reactivate a retired template.
     */
    public function unretire(int $id): bool
    {
        return $this->update($id, ['is_retired' => 0, 'is_active' => 1]);
    }

    // ================================================================
    // CREATE FROM TEMPLATE
    // ================================================================

    /**
     * Create a new event post pre-populated from a template.
     *
     * @param int   $template_id Template row ID.
     * @param array $overrides   Field overrides (start_date, end_date, title prefix, etc.)
     * @return int|\WP_Error Post ID or error.
     */
    public function create_event_from_template(int $template_id, array $overrides = []): int|\WP_Error
    {
        $template = $this->get($template_id);
        if (!$template) {
            return new \WP_Error('template_not_found', __('Template not found.', 'hmw-events'));
        }

        if ($template->is_retired) {
            return new \WP_Error('template_retired', __('Cannot create from a retired template.', 'hmw-events'));
        }

        $event_type = $template->event_type_slug;
        $resolved = $this->resolver->resolve($event_type, (array) $template->template_data, array_merge([
            'fallback_post_title' => $template->title,
        ], $overrides));

        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $meta_input = $this->normalize_event_meta_for_storage((array) ($resolved['event_meta'] ?? []));
        $attendance_options = (array) ($resolved['defaults']['attendance_options'] ?? []);

        if (!AttendancePricingService::is_multi_options($attendance_options)
            && !isset($meta_input['_event_price'])
            && isset($attendance_options[0]['price'])
        ) {
            $meta_input['_event_price'] = (float) $attendance_options[0]['price'];
        }

        // Build post array
        $post_args = [
            'post_type'   => 'hmw_event',
            'post_status' => $resolved['post']['post_status'],
            'post_title'  => $resolved['post']['post_title'],
            'post_content' => $resolved['post']['post_content'],
            'meta_input'   => $meta_input,
        ];

        $post_id = wp_insert_post($post_args, true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Set event type taxonomy
        if ($event_type) {
            wp_set_object_terms($post_id, $event_type, 'hmw_event_type');
        }

        // Set delivery mode if in defaults
        if (!empty($resolved['event_meta']['event_delivery_mode'])) {
            wp_set_object_terms($post_id, $resolved['event_meta']['event_delivery_mode'], 'hmw_event_delivery_mode');
        }

        // Insert default attendance options from resolved defaults.
        if (AttendancePricingService::is_multi_options($attendance_options)) {
            $this->insert_attendance_options($post_id, $attendance_options);
        }

        $this->persist_event_snapshot($post_id, $template_id, $resolved);

        return $post_id;
    }

    /**
     * Re-apply a template to an existing event.
     *
     * @param int      $event_post_id Event ID.
     * @param int      $template_id   Template ID.
     * @param string[] $sections      Sections to apply: event_meta, field_config, defaults, attendance_options.
     * @return int|\WP_Error Number of updated pieces.
     */
    public function reapply_template_to_event(int $event_post_id, int $template_id, array $sections = []): int|\WP_Error
    {
        global $wpdb;

        $post = get_post($event_post_id);
        if (!$post || $post->post_type !== 'hmw_event') {
            return new \WP_Error('invalid_event', __('Invalid event.', 'hmw-events'));
        }

        $template = $this->get($template_id);
        if (!$template || $template->is_retired) {
            return new \WP_Error('invalid_template', __('Template not found or retired.', 'hmw-events'));
        }

        $sections = array_values(array_unique(array_filter(array_map('sanitize_key', $sections))));
        if (empty($sections)) {
            $sections = ['event_meta', 'field_config', 'defaults', 'attendance_options'];
        }

        $resolved = $this->resolver->resolve($template->event_type_slug, (array) $template->template_data, [
            'fallback_post_title' => $post->post_title,
        ]);

        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $updates = 0;

        // Keep event taxonomy aligned with the template event type.
        if (!empty($template->event_type_slug)) {
            wp_set_object_terms($event_post_id, $template->event_type_slug, 'hmw_event_type', false);
        }

        if (in_array('event_meta', $sections, true)) {
            $meta_updates = $this->normalize_event_meta_for_storage((array) ($resolved['event_meta'] ?? []));
            foreach ($meta_updates as $meta_key => $meta_value) {
                update_post_meta($event_post_id, $meta_key, $meta_value);
            }

            if (!empty($resolved['event_meta']['event_delivery_mode'])) {
                wp_set_object_terms($event_post_id, sanitize_key((string) $resolved['event_meta']['event_delivery_mode']), 'hmw_event_delivery_mode', false);
            }

            $updates++;
        }

        if (in_array('field_config', $sections, true)) {
            update_post_meta($event_post_id, '_event_field_config', $resolved['field_config']);
            $updates++;
        }

        if (in_array('defaults', $sections, true)) {
            update_post_meta($event_post_id, '_event_default_values', $resolved['defaults']);
            $updates++;
        }

        if (in_array('attendance_options', $sections, true)) {
            $this->sync_attendance_options($event_post_id, (array) ($resolved['defaults']['attendance_options'] ?? []));
            $updates++;
        }

        $this->persist_event_snapshot($event_post_id, $template_id, $resolved);

        return $updates;
    }

    /**
     * Backfill missing template snapshots for events created from templates.
     *
     * @return array{processed:int, updated:int, skipped:int, last_post_id:int, done:bool}
     */
    public function backfill_template_snapshots(int $batch = 100, int $after_post_id = 0): array
    {
        global $wpdb;

        $batch = max(1, min(500, (int) $batch));
        $after_post_id = max(0, (int) $after_post_id);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value AS template_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             LEFT JOIN {$wpdb->postmeta} snap ON snap.post_id = pm.post_id AND snap.meta_key = '_template_snapshot'
             WHERE pm.meta_key = '_created_from_template_id'
               AND p.post_type = 'hmw_event'
               AND pm.post_id > %d
               AND snap.post_id IS NULL
             ORDER BY pm.post_id ASC
             LIMIT %d",
            $after_post_id,
            $batch
        ));

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $last_post_id = $after_post_id;

        foreach ($rows as $row) {
            $processed++;
            $event_post_id = (int) $row->post_id;
            $template_id = (int) $row->template_id;
            $last_post_id = max($last_post_id, $event_post_id);

            $template = $this->get($template_id);
            if (!$template) {
                $skipped++;
                continue;
            }

            $post = get_post($event_post_id);
            if (!$post || $post->post_type !== 'hmw_event') {
                $skipped++;
                continue;
            }

            $resolved = $this->resolver->resolve($template->event_type_slug, (array) $template->template_data, [
                'fallback_post_title' => $post->post_title,
            ]);

            if (is_wp_error($resolved)) {
                $skipped++;
                continue;
            }

            $this->persist_event_snapshot($event_post_id, $template_id, $resolved);
            $updated++;
        }

        return [
            'processed'    => $processed,
            'updated'      => $updated,
            'skipped'      => $skipped,
            'last_post_id' => $last_post_id,
            'done'         => $processed < $batch,
        ];
    }

    /**
     * Insert default attendance options for a new event based on event type.
     */
    private function insert_attendance_options(int $event_post_id, array $presets, int $sort_offset = 0): void
    {
        foreach ($presets as $index => $preset) {
            AttendancePricingService::insert_attendance_option($event_post_id, $preset, $sort_offset + $index);
        }
    }

    private function sync_attendance_options(int $event_post_id, array $presets): void
    {
        global $wpdb;
        $table = DatabaseService::get_table_name('event_attendance_options');
        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT id, option_type, price, price_mode, pricing_rules, description FROM {$table} WHERE event_post_id = %d",
            $event_post_id
        ));
        $existing_options = [];

        foreach ((array) $existing as $row) {
            $type = sanitize_key($row->option_type);
            $existing_options[$type][] = [
                'price'       => (float) $row->price,
                'price_mode'  => (string) ($row->price_mode ?? ''),
                'rules'       => AttendancePricingService::decode_rules($row->pricing_rules ?? null),
                'description' => (string) ($row->description ?? ''),
            ];
        }

        if (!AttendancePricingService::is_multi_options($presets)) {
            if (isset($presets[0]['price']) && get_post_meta($event_post_id, '_event_price', true) === '') {
                update_post_meta($event_post_id, '_event_price', (float) $presets[0]['price']);
            }
            $wpdb->delete($table, ['event_post_id' => $event_post_id], ['%d']);
            return;
        }

        $wpdb->delete($table, ['event_post_id' => $event_post_id], ['%d']);
        foreach ($presets as $index => $preset) {
            $type = sanitize_key($preset['option_type'] ?? 'individual') ?: 'individual';

            if (!empty($existing_options[$type])) {
                $existing_option = array_shift($existing_options[$type]);
                $preset['price'] = $existing_option['price'];
                if ($existing_option['price_mode'] !== '') {
                    $preset['price_mode'] = $existing_option['price_mode'];
                }
                if ($existing_option['rules'] !== null) {
                    $preset['pricing_rules'] = $existing_option['rules'];
                }
                if ($existing_option['description'] !== '') {
                    $preset['description'] = $existing_option['description'];
                }
            } else {
                $preset['price'] = (float) ($preset['price'] ?? 0);
            }

            $this->insert_attendance_options($event_post_id, [$preset], $index);
        }
    }

    /**
     * Persist immutable template metadata and resolved configuration snapshot.
     */
    private function persist_event_snapshot(int $post_id, int $template_id, array $resolved): void
    {
        update_post_meta($post_id, '_created_from_template_id', $template_id);
        update_post_meta($post_id, '_created_from_template_version', (int) ($resolved['template']['template_version'] ?? 1));
        update_post_meta($post_id, '_template_snapshot', $resolved);
        update_post_meta($post_id, '_event_field_config', $resolved['field_config']);
        update_post_meta($post_id, '_event_default_values', $resolved['defaults']);
    }

    /**
     * Normalize event meta keys for post meta storage.
     *
     * Canonical template keys use `event_*`, while runtime event meta is stored
     * as `_event_*` in this plugin.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function normalize_event_meta_for_storage(array $meta): array
    {
        $normalized = [];

        foreach ($meta as $raw_key => $value) {
            $key = sanitize_key((string) $raw_key);
            if ($key === '') {
                continue;
            }

            if (str_starts_with($key, '_event_')) {
                $normalized[$key] = $value;
                continue;
            }

            if (str_starts_with($key, 'event_')) {
                $normalized['_' . $key] = $value;
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Get the template data that should be pre-populated on a new event post.
     *
     * Used by the admin UI to show what values will be applied.
     */
    public function get_template_preview(int $template_id): ?array
    {
        $template = $this->get($template_id);
        if (!$template) {
            return null;
        }

        $resolved = $this->resolver->resolve($template->event_type_slug, (array) $template->template_data, [
            'fallback_post_title' => $template->title,
        ]);

        if (is_wp_error($resolved)) {
            return null;
        }

        return [
            'id'                => $template->id,
            'title'             => $template->title,
            'event_type_slug'   => $template->event_type_slug,
            'event_type_label'  => $this->get_event_type_label($template->event_type_slug),
            'post_title'        => $resolved['post']['post_title'],
            'post_content'      => $resolved['post']['post_content'],
            'meta'              => $resolved['event_meta'],
            'field_config'      => $resolved['field_config'],
            'defaults'          => $resolved['defaults'],
            'template_version'  => $resolved['template']['template_version'],
            'attendance_options' => $resolved['defaults']['attendance_options'],
            'is_retired'        => (bool) $template->is_retired,
        ];
    }

    /**
     * Get human-readable label for an event type slug.
     */
    private function get_event_type_label(string $slug): string
    {
        $term = get_term_by('slug', $slug, 'hmw_event_type');
        return $term && !is_wp_error($term) ? $term->name : $slug;
    }

    // ================================================================
    // HOOKS
    // ================================================================

    /**
     * Apply template defaults when the_default_content() or similar is called.
     *
     * This is a filter hook — only fires on admin post-new.php.
     */
    public function apply_template_defaults_on_new_post(string $content, \WP_Post $post): string
    {
        if ($post->post_type !== 'hmw_event') {
            return $content;
        }

        $template_id = $_GET['template_id'] ?? 0;
        if (empty($template_id)) {
            return $content;
        }

        $template = $this->get((int) $template_id);
        if (!$template || $template->is_retired) {
            return $content;
        }

        $template_data = (array) $template->template_data;
        if (isset($template_data['post']['post_content'])) {
            return (string) $template_data['post']['post_content'];
        }

        return (string) ($template_data['post_content'] ?? $content);
    }

    /**
     * AJAX handler for creating event from template.
     */
    public function ajax_create_from_template(): void
    {
        check_ajax_referer('hmwevents_create_from_template', '_wpnonce');

        if (!current_user_can('edit_hmw_events')) {
            wp_die(-1);
        }

        $template_id = (int) ($_POST['template_id'] ?? 0);
        $overrides   = [
            'post_title'   => sanitize_text_field($_POST['post_title'] ?? ''),
            'post_content' => wp_kses_post($_POST['post_content'] ?? ''),
            'post_status'  => sanitize_text_field($_POST['post_status'] ?? 'draft'),
        ];

        $result = $this->create_event_from_template($template_id, $overrides);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'post_id' => $result,
            'edit_url' => get_edit_post_link($result, 'raw'),
        ]);
    }

    /**
     * Enqueue admin scripts for template selection UI.
     */
    public function enqueue_template_scripts(string $hook): void
    {
        if ($hook !== 'post-new.php') {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'hmw_event') {
            return;
        }
    }
}
