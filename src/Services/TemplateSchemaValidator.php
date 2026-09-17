<?php

namespace HMWEvents\Services;

use HMWEvents\Registry\AcfFieldGroupRegistry;
use HMWEvents\Registry\EventTypeRegistry;
use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class TemplateSchemaValidator
{
    const SCHEMA_VERSION = 3;

    const ALLOWED_FIELD_TYPES = [
        'text', 'email', 'tel', 'textarea', 'select',
        'checkbox', 'radio', 'date', 'number', 'file',
        'session_picker',
    ];

    const ALLOWED_SOURCES = ['registrant_meta', 'booking_details'];

    const ALLOWED_ATTENDANCE_TYPES = ['individual', 'parent', 'parent_child', 'couple', 'professional'];

    const ALLOWED_MULTI_BOOKING_MODES = ['attendees', 'parent_children'];

    private const LEGACY_EVENT_FIELD_KEYS = [
        'event_venue_name',
        'event_venue_address',
        'event_currency',
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

                foreach (($section['fields'] ?? []) as $raw_field) {
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
            'multi_booking' => $this->normalize_multi_booking($multi_booking),
        ];
    }

    private function normalize_multi_booking(array $multi_booking): array
    {
        $mode = sanitize_key((string) ($multi_booking['mode'] ?? 'attendees'));
        if ($mode === '') {
            $mode = 'attendees';
        }

        $child_fields = is_array($multi_booking['child_fields'] ?? null) ? $multi_booking['child_fields'] : [];

        return [
            'enabled'      => (bool) ($multi_booking['enabled'] ?? false),
            'mode'         => $mode,
            'min'          => max(1, (int) ($multi_booking['min'] ?? 1)),
            'max'          => max(1, (int) ($multi_booking['max'] ?? 10)),
            'child_fields' => $this->normalize_child_fields($child_fields),
        ];
    }

    private function normalize_child_fields(array $child_fields): array
    {
        $name = is_array($child_fields['name'] ?? null) ? $child_fields['name'] : [];
        $age  = is_array($child_fields['age'] ?? null) ? $child_fields['age'] : [];

        return [
            'name' => [
                'enabled'  => (bool) ($name['enabled'] ?? true),
                'required' => (bool) ($name['required'] ?? true),
                'label'    => sanitize_text_field((string) ($name['label'] ?? '')) ?: __('Child Name', 'hmw-events'),
            ],
            'age'  => [
                'enabled'  => (bool) ($age['enabled'] ?? true),
                'required' => (bool) ($age['required'] ?? false),
                'label'    => sanitize_text_field((string) ($age['label'] ?? '')) ?: __('Date of Birth', 'hmw-events'),
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
            'attendance_types' => $this->sanitize_attendance_types($field['attendance_types'] ?? []),
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
        $known_group_keys = array_keys(AcfFieldGroupRegistry::get_group_expansions());
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

        $errors = array_merge($errors, $this->validate_attendance_options($data));

        return $errors;
    }

    private function validate_attendance_options(array $data): array
    {
        $errors = [];
        $options = $data['defaults']['attendance_options'] ?? [];

        foreach ($options as $index => $option) {
            $capacity = $option['capacity'] ?? null;
            if ($capacity !== null && $capacity < 0) {
                $errors[] = sprintf('Attendance option %d capacity must be at least 0.', $index);
            }

            $mode = $option['price_mode'] ?? 'flat';
            if (!in_array($mode, AttendancePricingService::MODES, true)) {
                $errors[] = sprintf('Attendance option %d has an invalid price_mode.', $index);
            }

            $composition = $option['composition'] ?? [];
            $min = (int) ($composition['min_attendees'] ?? 1);
            $max = (int) ($composition['max_attendees'] ?? $min);

            if ($min < 1) {
                $errors[] = sprintf('Attendance option %d minimum attendees must be at least 1.', $index);
            }

            if ($max < $min) {
                $errors[] = sprintf('Attendance option %d maximum attendees must be at least the minimum.', $index);
            }

            $min_adults = (int) ($composition['min_adults'] ?? 0);
            $min_children = (int) ($composition['min_children'] ?? 0);

            if ($min_adults + $min_children > $max) {
                $errors[] = sprintf('Attendance option %d requires more adults and children than its maximum attendees allows.', $index);
            }

            foreach ($option['pricing_rules'] ?? [] as $rule_index => $rule) {
                if (!isset($rule['price']) || !is_numeric($rule['price'])) {
                    $errors[] = sprintf('Attendance option %d pricing rule %d must have a numeric price.', $index, $rule_index);
                }

                if ($mode === 'per_attendee' && !in_array($rule['role'] ?? '', ['adult', 'child', 'any'], true)) {
                    $errors[] = sprintf('Attendance option %d pricing rule %d must target adult, child, or any for per-attendee pricing.', $index, $rule_index);
                }

                $rule_min = (int) ($rule['min_age'] ?? 0);
                $rule_max = $rule['max_age'] ?? null;

                if ($rule_min < 0) {
                    $errors[] = sprintf('Attendance option %d pricing rule %d minimum age must be at least 0.', $index, $rule_index);
                }

                if ($rule_max !== null && $rule_max !== '' && (int) $rule_max < $rule_min) {
                    $errors[] = sprintf('Attendance option %d pricing rule %d maximum age must not be less than its minimum age.', $index, $rule_index);
                }
            }
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

                if (!is_array($field['attendance_types'])) {
                    $errors[] = sprintf('Field "%s" attendance_types must be an array.', $fkey);
                } else {
                    foreach ($field['attendance_types'] as $attendance_type) {
                        if (!in_array($attendance_type, self::ALLOWED_ATTENDANCE_TYPES, true)) {
                            $errors[] = sprintf('Field "%s" has invalid attendance type "%s".', $fkey, $attendance_type);
                        }
                    }
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

        $mode = $mb['mode'] ?? 'attendees';
        if (!in_array($mode, self::ALLOWED_MULTI_BOOKING_MODES, true)) {
            $errors[] = sprintf(
                'multi_booking.mode must be one of: %s.',
                implode(', ', self::ALLOWED_MULTI_BOOKING_MODES)
            );
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

    private function sanitize_attendance_types($types): array
    {
        if (!is_array($types)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('sanitize_key', $types),
            fn(string $type): bool => $type !== ''
        )));
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

        $expansions = AcfFieldGroupRegistry::get_group_expansions();
        $expanded = [];
        foreach ($normalized as $key) {
            if (isset($expansions[$key])) {
                foreach ($expansions[$key] as $child) {
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

            $raw_capacity = $option['capacity'] ?? null;
            $capacity = ($raw_capacity === null || $raw_capacity === '')
                ? null
                : max(0, (int) $raw_capacity);

            $rules = $this->normalize_pricing_rules($option['pricing_rules'] ?? []);
            $mode  = AttendancePricingService::normalize_mode((string) ($option['price_mode'] ?? ''), $rules);

            $clean[] = [
                'option_type'   => $type ?: 'individual',
                'option_key'    => sanitize_key((string) ($option['option_key'] ?? '')),
                'label'         => $label,
                'description'   => trim((string) ($option['description'] ?? '')),
                'price'         => (float) ($option['price'] ?? 0),
                'capacity'      => $capacity,
                'composition'   => $this->normalize_composition($option['composition'] ?? [], $type),
                'price_mode'    => $mode,
                'pricing_rules' => $rules,
            ];
        }

        return $clean;
    }

    private function normalize_composition(array $composition, string $type): array
    {
        $defaults = AttendancePricingService::default_composition($type);

        $min = isset($composition['min_attendees'])
            ? max(1, (int) $composition['min_attendees'])
            : $defaults['min_attendees'];

        $max = isset($composition['max_attendees'])
            ? max(1, (int) $composition['max_attendees'])
            : $defaults['max_attendees'];

        if ($max < $min) {
            $max = $min;
        }

        $min_adults = isset($composition['min_adults'])
            ? max(0, (int) $composition['min_adults'])
            : $defaults['min_adults'];

        $min_children = isset($composition['min_children'])
            ? max(0, (int) $composition['min_children'])
            : $defaults['min_children'];

        $allowed = $this->sanitize_attendance_types($composition['allowed_roles'] ?? $defaults['allowed_roles']);
        $allowed = array_values(array_filter($allowed, fn($role) => in_array($role, ['adult', 'child'], true)));
        if (empty($allowed)) {
            $allowed = ['adult'];
        }

        return [
            'min_attendees' => $min,
            'max_attendees' => $max,
            'min_adults'    => $min_adults,
            'min_children'  => $min_children,
            'allowed_roles' => $allowed,
        ];
    }

    private function normalize_pricing_rules(array $rules): array
    {
        $clean = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $price = $rule['price'] ?? null;
            if ($price === null || $price === '' || !is_numeric($price)) {
                continue;
            }

            $role = sanitize_key((string) ($rule['role'] ?? 'any'));
            if (!in_array($role, ['adult', 'child', 'any'], true)) {
                $role = 'any';
            }

            $max_age = $rule['max_age'] ?? null;
            if ($max_age !== null && $max_age !== '') {
                $max_age = (int) $max_age;
            } else {
                $max_age = null;
            }

            $clean[] = [
                'label'   => sanitize_text_field($rule['label'] ?? ''),
                'role'    => $role,
                'min_age' => max(0, (int) ($rule['min_age'] ?? 0)),
                'max_age' => $max_age,
                'price'   => (float) $price,
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
        $keys = AcfFieldGroupRegistry::get_field_keys();

        foreach ($this->get_live_event_field_keys() as $key) {
            $keys[] = $key;
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

        foreach (self::LEGACY_EVENT_FIELD_KEYS as $field) {
            $keys[] = sanitize_key($field);
        }

        $keys = array_filter(array_unique($keys));
        sort($keys);

        return array_values($keys);
    }

    /**
     * Mirror the template editor's field list: fields registered dynamically
     * (e.g. taxonomy fields injected on acf/init) exist in live ACF but not
     * in acf-json, so they must be accepted here.
     */
    private function get_live_event_field_keys(): array
    {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return [];
        }

        $keys = [];

        $groups = acf_get_field_groups(['post_type' => 'hmw_event']);
        foreach ((array) $groups as $group) {
            $group_fields = acf_get_fields($group['key']);
            if (!is_array($group_fields)) {
                continue;
            }

            foreach ($group_fields as $field) {
                $name = sanitize_key((string) ($field['name'] ?? ''));
                if ($name === '' || (!str_starts_with($name, '_event_') && !str_starts_with($name, 'event_'))) {
                    continue;
                }

                $keys[] = str_starts_with($name, '_event_') ? substr($name, 1) : $name;
            }
        }

        return array_values(array_unique($keys));
    }
}
