<?php

namespace HMWEvents\Services;

use HMWEvents\Helpers\EventFieldConfig;
use HMWEvents\PostTypes\Event;
use HMWEvents\Registry\EventTypeRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventTemplateOverrideService
{
    const OVERRIDE_META_KEY = '_event_template_override';
    const APPLY_TO_CHILDREN_KEY = '_event_template_override_apply_to_children';

    private ?TemplateResolver $resolver = null;
    private ?TemplateSchemaValidator $validator = null;

    private function resolver(): TemplateResolver
    {
        if ($this->resolver === null) {
            $this->resolver = new TemplateResolver($this->validator());
        }

        return $this->resolver;
    }

    private function validator(): TemplateSchemaValidator
    {
        if ($this->validator === null) {
            $this->validator = new TemplateSchemaValidator();
        }

        return $this->validator;
    }

    /**
     * @return array{event_fields:array|null, registration_fields:array|null}
     */
    public function get_override(int $event_id): ?array
    {
        $meta = EventFieldConfig::read($event_id, self::OVERRIDE_META_KEY);

        if ($meta === null || empty($meta)) {
            return null;
        }

        return $meta;
    }

    public function has_override(int $event_id): bool
    {
        return $this->get_override($event_id) !== null;
    }

    public function is_applied_to_children(int $event_id): bool
    {
        return (bool) get_post_meta($event_id, self::APPLY_TO_CHILDREN_KEY, true);
    }

    public function get_child_session_ids(int $event_id): array
    {
        $children = get_posts([
            'post_type'      => Event::POST_TYPE,
            'post_parent'    => $event_id,
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);

        $clones = get_posts([
            'post_type'      => Event::POST_TYPE,
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_cloned_from',
                    'value' => $event_id,
                ],
            ],
        ]);

        return array_values(array_unique(array_map('intval', array_merge(
            is_array($children) ? $children : [],
            is_array($clones) ? $clones : []
        ))));
    }

    public function has_child_sessions(int $event_id): bool
    {
        return !empty($this->get_child_session_ids($event_id));
    }

    /**
     * @return array{event_fields:array, registration_fields:array}|null
     */
    private function get_resolved_field_config(int $event_id): ?array
    {
        $override = $this->get_override($event_id);
        $field_config = EventFieldConfig::read($event_id);

        if ($override !== null) {
            $base = $field_config ?? $this->get_event_type_defaults($event_id);
            $resolved = $this->resolver()->resolve_with_override(
                ['field_config' => $base],
                $override
            );

            return $resolved['field_config'] ?? $base;
        }

        if ($field_config !== null) {
            return $field_config;
        }

        return $this->get_event_type_defaults($event_id);
    }

    public function get_resolved_config(int $event_id): array
    {
        $event_meta = EventFieldConfig::read($event_id, '_event_default_values') ?? [];

        $attendance_options = $this->get_attendance_options($event_id);
        $field_config = $this->get_resolved_field_config($event_id);

        return [
            'field_config'       => $field_config,
            'event_meta'         => $event_meta,
            'attendance_options' => $attendance_options,
        ];
    }

    public function save_override(int $event_id, array $override_config, bool $apply_to_children): void
    {
        $normalized_event_fields = [];
        if (!empty($override_config['event_fields']) && is_array($override_config['event_fields'])) {
            $normalized_event_fields = $override_config['event_fields'];
        }

        $normalized_registration_fields = [];
        if (!empty($override_config['registration_fields']) && is_array($override_config['registration_fields'])) {
            $normalized_registration_fields = $override_config['registration_fields'];
        }

        if (empty($normalized_event_fields) && empty($normalized_registration_fields)) {
            return;
        }

        $meta = [];
        if (!empty($normalized_event_fields)) {
            $meta['event_fields'] = $normalized_event_fields;
        }
        if (!empty($normalized_registration_fields)) {
            $meta['registration_fields'] = $normalized_registration_fields;
        }

        update_post_meta($event_id, self::OVERRIDE_META_KEY, $meta);
        update_post_meta($event_id, self::APPLY_TO_CHILDREN_KEY, $apply_to_children);

        $this->cascade_to_children($event_id, $meta, $apply_to_children);
    }

    public function reset_override(int $event_id): void
    {
        delete_post_meta($event_id, self::OVERRIDE_META_KEY);
        delete_post_meta($event_id, self::APPLY_TO_CHILDREN_KEY);

        foreach ($this->get_child_session_ids($event_id) as $child_id) {
            delete_post_meta($child_id, self::OVERRIDE_META_KEY);
            delete_post_meta($child_id, self::APPLY_TO_CHILDREN_KEY);
        }
    }

    public function cascade_to_children(int $parent_id, array $override_config, bool $apply_to_children): void
    {
        foreach ($this->get_child_session_ids($parent_id) as $child_id) {
            update_post_meta($child_id, self::OVERRIDE_META_KEY, $override_config);
            update_post_meta($child_id, self::APPLY_TO_CHILDREN_KEY, $apply_to_children);
        }
    }

    /**
     * @return array{event_fields:array{required:string[], optional:string[], hidden:string[]}, registration_fields:array{required:string[], optional:string[], hidden:string[], order:string[], field_overrides:array}}
     */
    private function get_event_type_defaults(int $event_id): array
    {
        $terms = wp_get_object_terms($event_id, 'hmw_event_type');
        $type_slug = !empty($terms) && !is_wp_error($terms) ? $terms[0]->slug : '';

        $event_type_config = EventTypeRegistry::get($type_slug);

        $event_fields = [
            'required' => array_map('sanitize_key', $event_type_config['required_fields'] ?? []),
            'optional' => [],
            'hidden'   => array_map('sanitize_key', $event_type_config['hidden_fields'] ?? []),
        ];

        $registration_fields = [
            'required'        => [],
            'optional'        => [],
            'hidden'          => [],
            'order'           => array_keys(\HMWEvents\Registry\RegistrationFieldRegistry::all()),
            'field_overrides' => [],
        ];

        foreach (\HMWEvents\Registry\RegistrationFieldRegistry::all() as $key => $field) {
            if (!empty($field['required'])) {
                $registration_fields['required'][] = $key;
            }
        }

        return [
            'event_fields'        => $event_fields,
            'registration_fields' => $registration_fields,
        ];
    }

    private function get_attendance_options(int $event_id): array
    {
        global $wpdb;
        $table = DatabaseService::get_table_name('event_attendance_options');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_type, label, price FROM {$table} WHERE event_post_id = %d AND is_active = 1 ORDER BY sort_order ASC",
            $event_id
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(function ($row) {
            return [
                'option_type' => sanitize_key($row['option_type']),
                'label'       => sanitize_text_field($row['label']),
                'price'       => (float) $row['price'],
            ];
        }, $rows);
    }
}
