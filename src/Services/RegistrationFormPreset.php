<?php

/**
 * Registration Form Preset Service.
 *
 * Allows admins to save and load named form field presets that define
 * which fields are visible, required, and any overridden labels/placeholders
 * for a specific event type + audience combination.
 *
 * Presets are stored as WordPress options keyed by preset name.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class RegistrationFormPreset
{
    private const OPTION_PREFIX = 'hmwevents_form_preset_';

    /**
     * Save a preset.
     *
     * @param string $name   Unique preset name.
     * @param array  $config {event_type, audience, fields, label}
     */
    public function save(string $name, array $config): bool
    {
        $name = sanitize_key($name);
        $data = [
            'label'       => sanitize_text_field($config['label'] ?? $name),
            'event_type'  => sanitize_text_field($config['event_type'] ?? ''),
            'audience'    => sanitize_text_field($config['audience'] ?? RegistrationFieldRegistry::EVERYONE),
            'fields'      => $this->sanitize_fields($config['fields'] ?? []),
            'updated_at'  => current_time('mysql'),
        ];

        if (empty($data['label'])) {
            return false;
        }

        return update_option(self::OPTION_PREFIX . $name, $data, 'no');
    }

    /**
     * Get a single preset by name.
     */
    public function get(string $name): ?array
    {
        $data = get_option(self::OPTION_PREFIX . sanitize_key($name));
        return $data ?: null;
    }

    /**
     * Get all saved presets, optionally filtered by event type.
     *
     * @return array<string, array>
     */
    public function get_all(string $event_type = ''): array
    {
        global $wpdb;

        $like = self::OPTION_PREFIX . '%';
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        ));

        $presets = [];
        foreach ($results as $row) {
            $name = str_replace(self::OPTION_PREFIX, '', $row->option_name);
            $data = maybe_unserialize($row->option_value);
            if (!is_array($data)) {
                continue;
            }
            if ($event_type && ($data['event_type'] ?? '') !== $event_type) {
                continue;
            }
            $presets[$name] = $data;
        }

        return $presets;
    }

    /**
     * Delete a preset by name.
     */
    public function delete(string $name): bool
    {
        return delete_option(self::OPTION_PREFIX . sanitize_key($name));
    }

    /**
     * Get the effective field configuration for a given event type and audience.
     *
     * Merges: Registry fields → preset overrides → per-event config.
     *
     * @return array<string, array> Field key => resolved config
     */
    public function get_effective_fields(string $event_type, string $audience = ''): array
    {
        $preset_key = $this->find_matching_preset($event_type, $audience);

        // Base: all fields applicable for this audience
        $aud = $audience ?: RegistrationFieldRegistry::EVERYONE;
        $fields = RegistrationFieldRegistry::for_audience($aud);

        // Apply preset overrides if found
        if ($preset_key) {
            $preset = $this->get($preset_key);
            if ($preset && !empty($preset['fields'])) {
                foreach ($preset['fields'] as $key => $overrides) {
                    if (!isset($fields[$key])) {
                        continue;
                    }
                    if (isset($overrides['visible'])) {
                        $fields[$key]['preset_hidden'] = !$overrides['visible'];
                    }
                    if (isset($overrides['required'])) {
                        $fields[$key]['required'] = (bool) $overrides['required'];
                    }
                    if (!empty($overrides['label'])) {
                        $fields[$key]['label'] = sanitize_text_field($overrides['label']);
                    }
                    if (isset($overrides['placeholder'])) {
                        $fields[$key]['placeholder'] = sanitize_text_field($overrides['placeholder']);
                    }
                }
            }
        }

        // Remove fields explicitly hidden by preset
        return array_filter($fields, function ($f) {
            return empty($f['preset_hidden']);
        });
    }

    /**
     * Find the best matching preset for an event type + audience.
     */
    private function find_matching_preset(string $event_type, string $audience): ?string
    {
        $all = $this->get_all($event_type);

        // Exact match on audience first
        foreach ($all as $name => $data) {
            if (($data['audience'] ?? '') === $audience) {
                return $name;
            }
        }

        // Fallback: preset for this event type with no audience filter
        foreach ($all as $name => $data) {
            if (empty($data['audience']) || $data['audience'] === RegistrationFieldRegistry::EVERYONE) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Sanitize the fields portion of a preset config.
     */
    private function sanitize_fields(array $fields): array
    {
        $clean = [];
        foreach ($fields as $key => $data) {
            $key = sanitize_key($key);
            $clean[$key] = [
                'visible'     => isset($data['visible']) ? (bool) $data['visible'] : true,
                'required'    => isset($data['required']) ? (bool) $data['required'] : null,
                'label'       => isset($data['label']) ? sanitize_text_field($data['label']) : null,
                'placeholder' => isset($data['placeholder']) ? sanitize_text_field($data['placeholder']) : null,
            ];
        }
        return $clean;
    }
}
