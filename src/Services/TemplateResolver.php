<?php

/**
 * Template Resolver.
 *
 * Produces a fully resolved event configuration for event creation.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

use HMWEvents\Registry\AcfFieldGroupRegistry;
use HMWEvents\Registry\EventTypeRegistry;
use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class TemplateResolver
{
    private TemplateSchemaValidator $validator;

    public function __construct(?TemplateSchemaValidator $validator = null)
    {
        $this->validator = $validator ?: new TemplateSchemaValidator();
    }

    /**
     * Resolve all defaults and overrides into a single creation payload.
     *
     * @return array|\WP_Error
     */
    public function resolve(string $event_type, array $template_data, array $overrides = []): array|\WP_Error
    {
        $normalized = $this->validator->normalize($template_data);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $registry_defaults = EventTypeRegistry::get_default_meta($event_type);
        $template_meta = $normalized['defaults']['event_meta'] ?? [];
        $override_meta = $this->extract_meta_overrides($overrides);

        $event_meta = array_merge($registry_defaults, $template_meta, $override_meta);

        $resolved = [
            'template' => [
                'schema_version'   => (int) ($normalized['schema_version'] ?? 1),
                'template_version' => (int) ($normalized['template_version'] ?? 1),
            ],
            'post' => [
                'post_title'   => sanitize_text_field(
                    $overrides['post_title']
                        ?? $normalized['post']['post_title']
                        ?? ''
                ),
                'post_content' => wp_kses_post(
                    $overrides['post_content']
                        ?? $normalized['post']['post_content']
                        ?? ''
                ),
                'post_status'  => sanitize_key($overrides['post_status'] ?? 'draft') ?: 'draft',
            ],
            'event_meta' => $event_meta,
            'field_config' => [
                'event_fields'        => $this->resolve_event_field_config($event_type, $normalized),
                'registration_fields' => $this->resolve_registration_field_config($normalized),
            ],
            'defaults' => [
                'registration'       => $normalized['defaults']['registration'] ?? ['attendance_default' => 'individual', 'field_overrides' => []],
                'attendance_options' => $this->resolve_attendance_options($event_type, $normalized),
            ],
        ];

        if ($resolved['post']['post_title'] === '') {
            $resolved['post']['post_title'] = sanitize_text_field($overrides['fallback_post_title'] ?? 'Untitled Event');
        }

        return $resolved;
    }

    /**
     * @return array{required:string[], optional:string[], hidden:string[]}
     */
    private function resolve_event_field_config(string $event_type, array $normalized): array
    {
        $base = [
            'required' => $this->normalize_event_field_list(array_map('sanitize_key', EventTypeRegistry::get($event_type)['required_fields'] ?? [])),
            'optional' => [],
            'hidden'   => $this->normalize_event_field_list(array_map('sanitize_key', EventTypeRegistry::get($event_type)['hidden_fields'] ?? [])),
        ];

        $template = $normalized['event_fields'] ?? ['required' => [], 'optional' => [], 'hidden' => []];

        $template_required = $this->normalize_event_field_list($template['required']);
        $template_optional = $this->normalize_event_field_list($template['optional']);
        $template_hidden = $this->normalize_event_field_list($template['hidden']);

        $hidden = array_values(array_unique(array_merge($base['hidden'], $template_hidden)));
        $hidden = array_values(array_diff($hidden, $base['required']));

        $required = array_values(array_unique(array_merge($base['required'], $template_required)));
        $required = array_values(array_diff($required, $base['hidden']));

        $optional = array_values(array_unique(array_merge($base['optional'], $template_optional)));
        $optional = array_values(array_diff($optional, $required, $hidden));

        return [
            'required' => $required,
            'optional' => $optional,
            'hidden'   => $hidden,
        ];
    }

    /**
     * @param array<int,string> $keys
     * @return string[]
     */
    private function normalize_event_field_list(array $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }

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

    /**
     * @return array{required:string[], optional:string[], hidden:string[], order:string[], field_overrides:array<string, array{label:string, placeholder:string, width:string, section:string}>}
     */
    private function resolve_registration_field_config(array $normalized): array
    {
        if (($normalized['schema_version'] ?? 0) >= 3 && isset($normalized['registration_fields']['sections'])) {
            return $normalized['registration_fields'];
        }

        $base_required = [];
        foreach (RegistrationFieldRegistry::all() as $key => $field) {
            if (!empty($field['required'])) {
                $base_required[] = sanitize_key((string) $key);
            }
        }

        $template = $normalized['registration_fields'] ?? ['required' => [], 'optional' => [], 'hidden' => []];

        $required = array_values(array_unique(array_merge($base_required, $template['required'])));
        $optional = array_values(array_unique($template['optional']));
        $hidden = array_values(array_unique($template['hidden']));

        $required = array_values(array_diff($required, $hidden));
        $optional = array_values(array_diff($optional, $hidden));
        $optional = array_values(array_diff($optional, $required));

        return [
            'required'        => $required,
            'optional'        => $optional,
            'hidden'          => $hidden,
            'order'           => $template['order'] ?? [],
            'field_overrides' => $template['field_overrides'] ?? [],
        ];
    }

    public function resolve_with_override(array $resolved_config, array $event_override): array
    {
        if (!empty($event_override['event_fields']) && is_array($event_override['event_fields'])) {
            $resolved_config['field_config']['event_fields'] = $event_override['event_fields'];
        }

        if (!empty($event_override['registration_fields']) && is_array($event_override['registration_fields'])) {
            $base_multi_booking = $resolved_config['field_config']['registration_fields']['multi_booking'] ?? null;

            if (isset($event_override['registration_fields']['sections'])) {
                $resolved_config['field_config']['registration_fields'] = $event_override['registration_fields'];
                if (!isset($event_override['registration_fields']['multi_booking']) && $base_multi_booking) {
                    $resolved_config['field_config']['registration_fields']['multi_booking'] = $base_multi_booking;
                }
                return $resolved_config;
            }

            $resolved_config['field_config']['registration_fields'] = $event_override['registration_fields'];

            if (!isset($event_override['registration_fields']['multi_booking']) && $base_multi_booking) {
                $resolved_config['field_config']['registration_fields']['multi_booking'] = $base_multi_booking;
            }
        }

        return $resolved_config;
    }

    /**
     * @return array<int, array{option_type:string, label:string, price:float, capacity:int|null, composition:array, pricing_rules:array}>
     */
    private function resolve_attendance_options(string $event_type, array $normalized): array
    {
        $options = $normalized['defaults']['attendance_options'] ?? [];
        if (!empty($options)) {
            return $options;
        }

        $registry = EventTypeRegistry::get_attendance_option_presets($event_type);
        $multi_max = (int) ($normalized['registration_fields']['multi_booking']['max'] ?? AttendancePricingService::DEFAULT_MAX_CHILDREN);
        $clean = [];

        foreach ($registry as $option) {
            $raw_capacity = $option['capacity'] ?? null;
            $capacity = ($raw_capacity === null || $raw_capacity === '')
                ? null
                : max(0, (int) $raw_capacity);

            $type = sanitize_key($option['option_type'] ?? 'individual') ?: 'individual';

            $clean[] = [
                'option_type'   => $type,
                'option_key'    => sanitize_key((string) ($option['option_key'] ?? '')),
                'label'         => sanitize_text_field($option['label'] ?? 'Individual'),
                'description'   => (string) ($option['description'] ?? ''),
                'price'         => (float) ($option['price'] ?? 0),
                'capacity'      => $capacity,
                'composition'   => AttendancePricingService::default_composition($type, $multi_max),
                'price_mode'    => AttendancePricingService::MODE_FLAT,
                'pricing_rules' => [],
            ];
        }

        return $clean;
    }

    /**
     * @return array<string,mixed>
     */
    private function extract_meta_overrides(array $overrides): array
    {
        $meta = [];

        if (!empty($overrides['event_meta']) && is_array($overrides['event_meta'])) {
            foreach ($overrides['event_meta'] as $key => $value) {
                $meta[sanitize_key((string) $key)] = $value;
            }
        }

        foreach ($overrides as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'event_')) {
                continue;
            }
            $meta[sanitize_key($key)] = $value;
        }

        return $meta;
    }
}
