<?php

namespace HMWEvents\Services;

use HMWEvents\Registry\EventTypeRegistry;
use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class TemplateSchemaValidator
{
    const SCHEMA_VERSION = 3;

    const ALLOWED_FIELD_TYPES = [
        'text', 'email', 'tel', 'textarea', 'select',
        'checkbox', 'radio', 'date', 'number', 'file',
    ];

    const ALLOWED_SOURCES = ['registrant_meta', 'booking_details'];

    private const GROUP_EXPANSIONS = [
        'event_recurrence' => [
            'event_is_recurring',
            'event_recurrence_interval',
            'event_recurrence_unit',
            'event_recurrence_days',
            'event_recurrence_end_type',
            'event_recurrence_end_date',
            'event_recurrence_max_occurrences',
            'event_recurrence_custom_dates',
        ],
    ];

    public function normalize(array $template_data): array|\WP_Error
    {
        $normalized = $this->normalize_shape($template_data);
        $errors = $this->validate($normalized);

        if (!empty($errors)) {
            return new \WP_Error('invalid_template_schema', implode(' ', $errors), ['errors' => $errors]);
        }

        return $normalized;
    }

    private function normalize_shape(array $template_data): array
    {
        $known_top_level = [
            'schema_version', 'template_version', 'post', 'event_fields',
            'registration_fields', 'defaults', 'meta', 'post_title', 'post_content',
        ];

        $legacy_meta = [];
        foreach ($template_data as $key => $value) {
            if (!is_string($key) || in_array($key, $known_top_level, true)) {
                continue;
            }

            if (str_starts_with($key, 'event_') || str_starts_with($key, '_event_')) {
                $legacy_meta[$key] = $value;
            }
        }

        $post = is_array($template_data['post'] ?? null) ? $template_data['post'] : [];
        $event_fields = is_array($template_data['event_fields'] ?? null) ? $template_data['event_fields'] : [];
        $registration_fields = is_array($template_data['registration_fields'] ?? null) ? $template_data['registration_fields'] : [];
        $defaults = is_array($template_data['defaults'] ?? null) ? $template_data['defaults'] : [];

        if (!isset($defaults['event_meta'])) {
            $defaults['event_meta'] = [];
        }

        if (is_array($template_data['meta'] ?? null)) {
            $defaults['event_meta'] = array_merge($defaults['event_meta'], $template_data['meta']);
        }

        $defaults['event_meta'] = array_merge($defaults['event_meta'], $legacy_meta);

        $schema_version = (int) ($template_data['schema_version'] ?? 1);

        if ($schema_version < 2) {
            $registration_fields = $this->normalize_v1_to_v2($registration_fields);
            $schema_version = 2;
        }

        if ($schema_version < 3) {
            $registration_fields = $this->migrate_v2_to_v3($registration_fields);
            $schema_version = 3;
        }

        if ($schema_version >= 3) {
            $registration_fields = $this->normalize_v3_sections($registration_fields);
        }

        return [
            'schema_version'      => $schema_version,
            'template_version'    => max(1, (int) ($template_data['template_version'] ?? 1)),
            'post'                => [
                'title_pattern' => sanitize_text_field($post['title_pattern'] ?? ''),
                'post_content'  => wp_kses_post($post['post_content'] ?? ($template_data['post_content'] ?? '')),
                'post_title'    => sanitize_text_field($post['post_title'] ?? ($template_data['post_title'] ?? '')),
            ],
            'event_fields'        => $this->normalize_field_state($event_fields, true),
            'registration_fields' => $registration_fields,
            'defaults'            => [
                'event_meta'         => $this->sanitize_assoc($defaults['event_meta']),
                'registration'       => $this->normalize_registration_defaults($defaults['registration'] ?? []),
                'attendance_options' => $this->normalize_attendance_options($defaults['attendance_options'] ?? []),
            ],
        ];
    }

    private function normalize_v1_to_v2(array $registration_fields): array
    {
        $required = $this->sanitize_string_list($registration_fields['required'] ?? []);
        $optional = $this->sanitize_string_list($registration_fields['optional'] ?? []);
        $order = array_merge($required, $optional);

        return [
            'required'        => $required,
            'optional'        => $optional,
            'hidden'          => $registration_fields['hidden'] ?? [],
            'order'           => $order,
            'field_overrides' => [],
        ];
    }

    private function migrate_v2_to_v3(array $registration_fields): array
    {
        $registry = RegistrationFieldRegistry::all();
        $required = $this->sanitize_string_list($registration_fields['required'] ?? []);
        $optional = $this->sanitize_string_list($registration_fields['optional'] ?? []);
        $hidden = $this->sanitize_string_list($registration_fields['hidden'] ?? []);
        $order = $this->sanitize_string_list($registration_fields['order'] ?? []);
        $overrides = is_array($registration_fields['field_overrides'] ?? null)
            ? $registration_fields['field_overrides']
            : [];

        $key_set = [];
        $order_keys = [];
        foreach ($order as $k) {
            if (!in_array($k, $hidden, true) && isset($registry[$k])) {
                $key_set[$k] = false;
                $order_keys[] = $k;
            }
        }
        foreach ($required as $k) {
            if (!in_array($k, $hidden, true) && isset($registry[$k])) {
                $key_set[$k] = true;
            }
        }
        foreach ($optional as $k) {
            if (!in_array($k, $hidden, true) && isset($registry[$k]) && !isset($key_set[$k])) {
                $key_set[$k] = false;
            }
        }

        $fields = [];
        $seen_keys = [];

        foreach ($order_keys as $k) {
            if (!in_array($k, $seen_keys, true)) {
                $seen_keys[] = $k;
                $fields[] = $this->build_migrated_field($k, $registry, $key_set[$k], $overrides);
            }
        }

        foreach ($key_set as $k => $is_required) {
            if (!in_array($k, $seen_keys, true)) {
                $seen_keys[] = $k;
                $fields[] = $this->build_migrated_field($k, $registry, $is_required, $overrides);
            }
        }

        return [
            'sections' => [[
                'id'     => 'general',
                'label'  => 'General',
                'fields' => $fields,
            ]],
            'multi_booking' => [
                'enabled' => false,
                'min'     => 1,
                'max'     => 10,
            ],
        ];
    }

    private function build_migrated_field(string $key, array $registry, bool $is_required, array $overrides): array
    {
        $reg_field = $registry[$key] ?? [];
        $override = $overrides[$key] ?? [];

        return $this->build_v3_field(
            $key,
            $override['label'] ?? $reg_field['label'] ?? $key,
            $reg_field['type'] ?? 'text',
            $is_required,
            $override['placeholder'] ?? '',
            $override['width'] ?? 'full',
            $reg_field['source'] ?? 'booking_details',
            $reg_field['meta_key'] ?? null,
            is_array($reg_field['options'] ?? null) ? $reg_field['options'] : []
        );
    }

    private function build_v3_field(
        string $key, string $label, string $type, bool $required,
        string $placeholder, string $width, string $source, ?string $meta_key,
        array $options = []
    ): array {
        $requires_options = in_array($type, ['select', 'checkbox', 'radio'], true);

        if ($requires_options && empty($options)) {
            if ($type === 'checkbox') {
                $options = [['value' => '1', 'label' => __('Yes', 'hmw-events')]];
            } else {
                $options = [];
            }
        }

        return [
            'key'          => $key,
            'label'        => $label,
            'type'         => $type,
            'required'     => $required,
            'placeholder'  => $placeholder,
            'width'        => $width,
            'source'       => $source,
            'meta_key'     => $meta_key,
            'preset'       => ($source === 'registrant_meta'),
            'per_attendee' => false,
            'options'      => [],
        ];
    }

    private function normalize_v3_sections(array $registration_fields): array
    {
        $sections = [];
        $raw_sections = is_array($registration_fields['sections'] ?? null) ? $registration_fields['sections'] : [];

        foreach ($raw_sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $section_id = sanitize_key($section['id'] ?? 'sec_' . bin2hex(random_bytes(4)));
            $fields = [];

            foreach ($section['fields'] as $raw_field) {
                if (!is_array($raw_field)) {
                    continue;
                }

                $fields[] = $this->normalize_v3_field($raw_field);
            }

            $sections[] = [
                'id'     => $section_id,
                'label'  => sanitize_text_field($section['label'] ?? $section_id),
                'fields' => $fields,
            ];
        }

        $multi_booking = is_array($registration_fields['multi_booking'] ?? null)
            ? $registration_fields['multi_booking']
            : [];

        return [
            'sections'      => $sections,
            'multi_booking' => [
                'enabled' => (bool) ($multi_booking['enabled'] ?? false),
                'min'     => max(1, (int) ($multi_booking['min'] ?? 1)),
                'max'     => max(1, (int) ($multi_booking['max'] ?? 10)),
            ],
        ];
    }

    private function normalize_v3_field(array $field): array
    {
        $type = sanitize_key($field['type'] ?? 'text');

        $source = sanitize_key($field['source'] ?? 'booking_details');
        if (!in_array($source, self::ALLOWED_SOURCES, true)) {
            $source = 'booking_details';
        }

        $width = sanitize_key($field['width'] ?? 'full');
        if (!in_array($width, ['half', 'full'], true)) {
            $width = 'full';
        }

        $requires_options = in_array($type, ['select', 'checkbox', 'radio'], true);
        $options = [];

        if ($requires_options) {
            $raw_options = is_array($field['options'] ?? null) ? $field['options'] : [];
            foreach ($raw_options as $opt) {
                if (!is_array($opt)) {
                    continue;
                }
                $options[] = [
                    'value' => sanitize_text_field($opt['value'] ?? ''),
                    'label' => sanitize_text_field($opt['label'] ?? ''),
                ];
            }
        }

        return [
            'key'          => sanitize_key($field['key'] ?? 'field_' . bin2hex(random_bytes(4))),
            'label'        => sanitize_text_field($field['label'] ?? ''),
            'type'         => $type,
            'required'     => (bool) ($field['required'] ?? false),
            'placeholder'  => sanitize_text_field($field['placeholder'] ?? ''),
            'width'        => $width,
            'source'       => $source,
            'meta_key'     => $field['meta_key'] ?? null,
            'preset'       => (bool) ($field['preset'] ?? false),
            'per_attendee' => (bool) ($field['per_attendee'] ?? false),
            'options'      => $options,
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];

        $schema_version = (int) ($data['schema_version'] ?? 0);
        if ($schema_version < 1 || $schema_version > self::SCHEMA_VERSION) {
            $errors[] = sprintf('Unsupported schema_version; expected between 1 and %d.', self::SCHEMA_VERSION);
        }

        $known_event_fields = $this->get_known_event_field_keys();
        $known_group_keys = array_keys(self::GROUP_EXPANSIONS);
        foreach (['required', 'optional', 'hidden'] as $bucket) {
            foreach ($data['event_fields'][$bucket] as $key) {
                if (in_array($key, $known_group_keys, true)) {
                    continue;
                }
                if (!in_array($key, $known_event_fields, true)) {
                    $errors[] = sprintf('Unknown event field: %s.', $key);
                }
            }
        }

        if ($schema_version >= 3) {
            $errors = array_merge($errors, $this->validate_sections($data));
            $errors = array_merge($errors, $this->validate_multi_booking($data));
        } elseif ($schema_version >= 2) {
            $errors = array_merge($errors, $this->validate_v2_registration($data));
        }

        return $errors;
    }

    private function validate_v2_registration(array $data): array
    {
        $errors = [];
        $known = array_keys(RegistrationFieldRegistry::all());

        foreach (['required', 'optional', 'hidden'] as $bucket) {
            foreach ($data['registration_fields'][$bucket] as $key) {
                if (!in_array($key, $known, true)) {
                    $errors[] = sprintf('Unknown registration field: %s.', $key);
                }
            }
        }

        foreach ($data['registration_fields']['order'] as $key) {
            if (!in_array($key, $known, true)) {
                $errors[] = sprintf('Unknown registration field in order: %s.', $key);
            }
        }

        foreach ($data['registration_fields']['field_overrides'] as $field_key => $override) {
            if (!in_array($field_key, $known, true)) {
                $errors[] = sprintf('Unknown registration field override key: %s.', $field_key);
                continue;
            }

            if (!is_array($override)) {
                $errors[] = sprintf('Invalid registration field override shape for: %s.', $field_key);
            }
        }

        return $errors;
    }

    private function validate_sections(array $data): array
    {
        $errors = [];
        $sections = $data['registration_fields']['sections'];
        $multi_enabled = (bool) ($data['registration_fields']['multi_booking']['enabled'] ?? false);

        if (empty($sections)) {
            return $errors;
        }

        $section_ids = [];
        $all_field_keys = [];

        foreach ($sections as $si => $section) {
            $id = $section['id'] ?? '';
            if ($id === '') {
                $errors[] = sprintf('Section %d has an empty id.', $si);
            } elseif (in_array($id, $section_ids, true)) {
                $errors[] = sprintf('Duplicate section id: %s.', $id);
            } else {
                $section_ids[] = $id;
            }

            $label = $section['label'] ?? '';
            if ($label === '') {
                $errors[] = sprintf('Section "%s" has an empty label.', $id);
            }

            $fields = $section['fields'] ?? [];
            if (!is_array($fields) || empty($fields)) {
                continue;
            }

            foreach ($fields as $fi => $field) {
                $fkey = $field['key'] ?? '';
                if ($fkey === '') {
                    $errors[] = sprintf('Section "%s" field %d has an empty key.', $id, $fi);
                    continue;
                }

                if (in_array($fkey, $all_field_keys, true)) {
                    $errors[] = sprintf('Duplicate field key "%s" in section "%s".', $fkey, $id);
                } else {
                    $all_field_keys[] = $fkey;
                }

                if (empty($field['label'])) {
                    $errors[] = sprintf('Field "%s" in section "%s" has an empty label.', $fkey, $id);
                }

                $type = $field['type'] ?? '';
                if (!in_array($type, self::ALLOWED_FIELD_TYPES, true)) {
                    $errors[] = sprintf('Field "%s" has invalid type "%s".', $fkey, $type);
                }

                $source = $field['source'] ?? '';
                if (!in_array($source, self::ALLOWED_SOURCES, true)) {
                    $errors[] = sprintf('Field "%s" has invalid source "%s".', $fkey, $source);
                }

                $width = $field['width'] ?? '';
                if (!in_array($width, ['half', 'full'], true)) {
                    $errors[] = sprintf('Field "%s" has invalid width "%s".', $fkey, $width);
                }

                $requires_options = in_array($type, ['select', 'checkbox', 'radio'], true);
                if ($requires_options) {
                    $options = $field['options'] ?? [];
                    if (!is_array($options) || empty($options)) {
                        $errors[] = sprintf('Field "%s" of type "%s" requires at least one option.', $fkey, $type);
                    } else {
                        foreach ($options as $oi => $opt) {
                            if (!is_array($opt) || !isset($opt['value']) || !isset($opt['label'])) {
                                $errors[] = sprintf('Field "%s" option %d is invalid (needs value and label).', $fkey, $oi);
                            }
                        }
                    }
                }

                if (!is_bool($field['required'])) {
                    $errors[] = sprintf('Field "%s" required must be boolean.', $fkey);
                }

                if (!is_bool($field['per_attendee'])) {
                    $errors[] = sprintf('Field "%s" per_attendee must be boolean.', $fkey);
                }

                if ($field['per_attendee'] && !$multi_enabled) {
                    $errors[] = sprintf('Field "%s" has per_attendee=true but multi-booking is disabled.', $fkey);
                }
            }
        }

        return $errors;
    }

    private function validate_multi_booking(array $data): array
    {
        $errors = [];
        $mb = $data['registration_fields']['multi_booking'];

        if (!isset($mb['enabled']) || !is_bool($mb['enabled'])) {
            $errors[] = 'multi_booking.enabled must be a boolean.';
        }

        $min = (int) ($mb['min'] ?? 1);
        $max = (int) ($mb['max'] ?? 10);

        if ($min < 1) {
            $errors[] = 'multi_booking.min must be at least 1.';
        }

        if ($max < 1 || $max > 100) {
            $errors[] = 'multi_booking.max must be between 1 and 100.';
        }

        if ($min > $max) {
            $errors[] = 'multi_booking.min must be less than or equal to max.';
        }

        return $errors;
    }

    private function normalize_field_state(array $state, bool $event_fields = false): array
    {
        $required = $this->sanitize_string_list($state['required'] ?? []);
        $optional = $this->sanitize_string_list($state['optional'] ?? []);
        $hidden = $this->sanitize_string_list($state['hidden'] ?? []);

        if ($event_fields) {
            $required = $this->canonicalize_event_field_list($required);
            $optional = $this->canonicalize_event_field_list($optional);
            $hidden = $this->canonicalize_event_field_list($hidden);
        }

        $required = array_values(array_diff($required, $hidden));
        $optional = array_values(array_diff($optional, $hidden));
        $optional = array_values(array_diff($optional, $required));

        return [
            'required' => $required,
            'optional' => $optional,
            'hidden'   => $hidden,
        ];
    }

    private function canonicalize_event_field_list(array $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            if (str_starts_with($key, '_event_')) {
                $normalized[] = substr($key, 1);
                continue;
            }

            $normalized[] = $key;
        }

        $normalized = array_values(array_unique($normalized));

        $expanded = [];
        foreach ($normalized as $key) {
            if (isset(self::GROUP_EXPANSIONS[$key])) {
                foreach (self::GROUP_EXPANSIONS[$key] as $child) {
                    $expanded[] = $child;
                }
            } else {
                $expanded[] = $key;
            }
        }

        return array_values(array_unique($expanded));
    }

    private function normalize_registration_defaults(array $registration): array
    {
        $attendance_default = sanitize_key($registration['attendance_default'] ?? 'individual');
        $field_overrides = [];

        $raw_overrides = is_array($registration['field_overrides'] ?? null)
            ? $registration['field_overrides']
            : [];

        foreach ($raw_overrides as $field_key => $override) {
            if (!is_array($override)) {
                continue;
            }

            $field_overrides[sanitize_key((string) $field_key)] = [
                'label'       => sanitize_text_field($override['label'] ?? ''),
                'placeholder' => sanitize_text_field($override['placeholder'] ?? ''),
            ];
        }

        return [
            'attendance_default' => $attendance_default ?: 'individual',
            'field_overrides'    => $field_overrides,
        ];
    }

    private function normalize_attendance_options(array $options): array
    {
        $clean = [];

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $label = sanitize_text_field($option['label'] ?? '');
            $type = sanitize_key($option['option_type'] ?? 'individual');

            if ($label === '') {
                continue;
            }

            $clean[] = [
                'option_type' => $type ?: 'individual',
                'label'       => $label,
                'price'       => (float) ($option['price'] ?? 0),
            ];
        }

        return $clean;
    }

    private function sanitize_assoc(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $clean[sanitize_key((string) $key)] = $value;
        }

        return $clean;
    }

    private function sanitize_string_list($list): array
    {
        if (!is_array($list)) {
            return [];
        }

        $clean = [];
        foreach ($list as $value) {
            if (!is_string($value)) {
                continue;
            }

            $key = sanitize_key($value);
            if ($key === '') {
                continue;
            }

            $clean[] = $key;
        }

        return array_values(array_unique($clean));
    }

    private function get_known_event_field_keys(): array
    {
        $keys = [];

        if (defined('HMWEvents_ABSPATH')) {
            $json_file = HMWEvents_ABSPATH . 'acf-json/group_hmw_event_details.json';
            if (file_exists($json_file)) {
                $contents = file_get_contents($json_file);
                $data = json_decode((string) $contents, true);
                if (is_array($data) && !empty($data['fields'])) {
                    foreach ($data['fields'] as $field) {
                        $meta_name = $field['name'] ?? '';
                        if ($meta_name === '') {
                            continue;
                        }

                        $normalized = sanitize_key($meta_name);
                        if (str_starts_with($normalized, '_event_')) {
                            $normalized = substr($normalized, 1);
                        }

                        $keys[] = $normalized;
                        if (str_starts_with($normalized, 'event_')) {
                            $keys[] = '_' . $normalized;
                        }
                    }
                }
            }
        }

        $all = EventTypeRegistry::all();
        foreach ($all as $config) {
            foreach (($config['hidden_fields'] ?? []) as $field) {
                $normalized = sanitize_key((string) $field);
                $keys[] = $normalized;
            }
            foreach (($config['required_fields'] ?? []) as $field) {
                $normalized = sanitize_key((string) $field);
                $keys[] = $normalized;
            }
            foreach (array_keys($config['default_meta'] ?? []) as $field) {
                $normalized = sanitize_key((string) $field);
                $keys[] = $normalized;
            }
        }

        foreach (array_keys(EventTypeRegistry::defaults()['default_meta'] ?? []) as $field) {
            $normalized = sanitize_key((string) $field);
            $keys[] = $normalized;
        }

        $keys = array_filter(array_unique($keys));
        sort($keys);

        return array_values($keys);
    }
}
