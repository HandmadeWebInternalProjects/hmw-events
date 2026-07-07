<?php

/**
 * Template Schema Validator.
 *
 * Normalizes and validates event template payloads.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

use HMWEvents\Registry\EventTypeRegistry;
use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class TemplateSchemaValidator
{
    /**
     * Normalize and validate template data.
     *
     * Supports both new schema and legacy template format.
     *
     * @return array|\WP_Error
     */
    public function normalize(array $template_data): array|\WP_Error
    {
        $normalized = $this->normalize_shape($template_data);
        $errors = $this->validate($normalized);

        if (!empty($errors)) {
            return new \WP_Error('invalid_template_schema', implode(' ', $errors), ['errors' => $errors]);
        }

        return $normalized;
    }

    /**
     * Convert legacy payload into the canonical schema.
     */
    private function normalize_shape(array $template_data): array
    {
        $known_top_level = [
            'schema_version',
            'template_version',
            'post',
            'event_fields',
            'registration_fields',
            'defaults',
            'meta',
            'post_title',
            'post_content',
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

        return [
            'schema_version'      => (int) ($template_data['schema_version'] ?? 1),
            'template_version'    => max(1, (int) ($template_data['template_version'] ?? 1)),
            'post'                => [
                'title_pattern' => sanitize_text_field($post['title_pattern'] ?? ''),
                'post_content'  => wp_kses_post($post['post_content'] ?? ($template_data['post_content'] ?? '')),
                'post_title'    => sanitize_text_field($post['post_title'] ?? ($template_data['post_title'] ?? '')),
            ],
            'event_fields'        => $this->normalize_field_state($event_fields, true),
            'registration_fields' => $this->normalize_field_state($registration_fields),
            'defaults'            => [
                'event_meta'         => $this->sanitize_assoc($defaults['event_meta']),
                'registration'       => $this->normalize_registration_defaults($defaults['registration'] ?? []),
                'attendance_options' => $this->normalize_attendance_options($defaults['attendance_options'] ?? []),
            ],
        ];
    }

    /**
     * Validate normalized data and return plain-text errors.
     *
     * @return string[]
     */
    private function validate(array $data): array
    {
        $errors = [];

        if (($data['schema_version'] ?? 0) !== 1) {
            $errors[] = 'Unsupported schema_version; expected 1.';
        }

        $known_event_fields = $this->get_known_event_field_keys();
        foreach (['required', 'optional', 'hidden'] as $bucket) {
            foreach ($data['event_fields'][$bucket] as $key) {
                if (!in_array($key, $known_event_fields, true)) {
                    $errors[] = sprintf('Unknown event field: %s.', $key);
                }
            }
        }

        $known_registration_fields = array_keys(RegistrationFieldRegistry::all());
        foreach (['required', 'optional', 'hidden'] as $bucket) {
            foreach ($data['registration_fields'][$bucket] as $key) {
                if (!in_array($key, $known_registration_fields, true)) {
                    $errors[] = sprintf('Unknown registration field: %s.', $key);
                }
            }
        }

        foreach ($data['defaults']['registration']['field_overrides'] as $field_key => $field_override) {
            if (!in_array($field_key, $known_registration_fields, true)) {
                $errors[] = sprintf('Unknown field override key: %s.', $field_key);
                continue;
            }

            if (!is_array($field_override)) {
                $errors[] = sprintf('Invalid field override shape for: %s.', $field_key);
            }
        }

        return $errors;
    }

    /**
     * @return array{required:string[], optional:string[], hidden:string[]}
     */
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

        // Hidden wins.
        $required = array_values(array_diff($required, $hidden));
        $optional = array_values(array_diff($optional, $hidden));

        // Required wins over optional.
        $optional = array_values(array_diff($optional, $required));

        return [
            'required' => $required,
            'optional' => $optional,
            'hidden'   => $hidden,
        ];
    }

    /**
     * @param string[] $keys
     * @return string[]
     */
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

        return array_values(array_unique($normalized));
    }

    /**
     * @return array{attendance_default:string, field_overrides:array<string, array{label:string, placeholder:string}>}
     */
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

    /**
     * @return array<int, array{option_type:string, label:string, price:float}>
     */
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

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function sanitize_assoc(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $clean[sanitize_key((string) $key)] = $value;
        }

        return $clean;
    }

    /**
     * @param mixed $list
     * @return string[]
     */
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

    /**
     * @return string[]
     */
    private function get_known_event_field_keys(): array
    {
        $keys = [];
        $all = EventTypeRegistry::all();

        foreach ($all as $config) {
            foreach (($config['hidden_fields'] ?? []) as $field) {
                $normalized = sanitize_key((string) $field);
                $keys[] = $normalized;
                if (str_starts_with($normalized, 'event_')) {
                    $keys[] = '_' . $normalized;
                }
            }
            foreach (($config['required_fields'] ?? []) as $field) {
                $normalized = sanitize_key((string) $field);
                $keys[] = $normalized;
                if (str_starts_with($normalized, 'event_')) {
                    $keys[] = '_' . $normalized;
                }
            }
            foreach (array_keys($config['default_meta'] ?? []) as $field) {
                $normalized = sanitize_key((string) $field);
                $keys[] = $normalized;
                if (str_starts_with($normalized, 'event_')) {
                    $keys[] = '_' . $normalized;
                }
            }
        }

        foreach (array_keys(EventTypeRegistry::defaults()['default_meta'] ?? []) as $field) {
            $normalized = sanitize_key((string) $field);
            $keys[] = $normalized;
            if (str_starts_with($normalized, 'event_')) {
                $keys[] = '_' . $normalized;
            }
        }

        $keys = array_filter(array_unique($keys));
        sort($keys);

        return array_values($keys);
    }
}
