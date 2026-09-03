<?php

namespace HMWEvents\Services;

use HMWEvents\Helpers\EventFieldConfig;

defined('ABSPATH') || die('Don\'t run this file directly!');

class FormConfigResolver
{
    private const ATTENDANCE_TYPES = ['individual', 'parent', 'parent_child', 'couple', 'professional'];

    public static function resolve(int $event_id): ?array
    {
        $override = EventFieldConfig::read($event_id, '_event_template_override');
        if ($override !== null
            && isset($override['registration_fields']['sections'])
            && is_array($override['registration_fields']['sections'])
        ) {
            return $override['registration_fields'];
        }

        $config = EventFieldConfig::read($event_id);
        if ($config !== null
            && isset($config['registration_fields']['sections'])
            && is_array($config['registration_fields']['sections'])
        ) {
            $reg = $config['registration_fields'];
            if (!empty($override['registration_fields']['multi_booking'])) {
                $reg['multi_booking'] = $override['registration_fields']['multi_booking'];
            }
            return $reg;
        }

        return null;
    }

    public static function for_attendance(array $config, string $attendance_type): array
    {
        $attendance_type = sanitize_key($attendance_type);
        if (!in_array($attendance_type, self::ATTENDANCE_TYPES, true)) {
            $attendance_type = 'individual';
        }

        foreach ($config['sections'] ?? [] as $section_index => $section) {
            if (!is_array($section)) {
                continue;
            }

            $fields = [];
            foreach ($section['fields'] ?? [] as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $targets = $field['attendance_types'] ?? [];
                if (is_array($targets) && $targets !== [] && !in_array($attendance_type, $targets, true)) {
                    continue;
                }

                $fields[] = $field;
            }

            $config['sections'][$section_index]['fields'] = $fields;
        }

        return $config;
    }
}
