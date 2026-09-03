<?php

namespace HMWEvents\Registry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class AcfFieldGroupRegistry
{
    private const GROUPS = [
        'event_recurrence' => [
            'anchor' => 'event_is_recurring',
            'prefix' => 'event_recurrence_',
            'label'  => 'Recurrence Settings',
        ],
    ];

    private static ?array $raw_fields = null;

    private static function raw_fields(): array
    {
        if (self::$raw_fields !== null) {
            return self::$raw_fields;
        }

        self::$raw_fields = [];

        if (!defined('HMWEvents_ABSPATH')) {
            return self::$raw_fields;
        }

        $json_file = HMWEvents_ABSPATH . 'acf-json/group_hmw_event_details.json';
        if (!file_exists($json_file)) {
            return self::$raw_fields;
        }

        $contents = file_get_contents($json_file);
        $data = json_decode((string) $contents, true);
        if (!is_array($data) || empty($data['fields'])) {
            return self::$raw_fields;
        }

        self::$raw_fields = $data['fields'];

        return self::$raw_fields;
    }

    private static function normalize_name(string $name): string
    {
        $normalized = sanitize_key($name);
        if (str_starts_with($normalized, '_event_')) {
            $normalized = substr($normalized, 1);
        }

        return $normalized;
    }

    private static function canonical_names(): array
    {
        $names = [];

        foreach (self::raw_fields() as $field) {
            $meta_name = $field['name'] ?? '';
            if ($meta_name === '') {
                continue;
            }

            $names[] = self::normalize_name($meta_name);
        }

        return $names;
    }

    public static function get_field_keys(): array
    {
        $keys = [];

        foreach (self::raw_fields() as $field) {
            $meta_name = $field['name'] ?? '';
            if ($meta_name === '') {
                continue;
            }

            $normalized = self::normalize_name($meta_name);
            $keys[] = $normalized;

            if (str_starts_with($normalized, 'event_')) {
                $keys[] = '_' . $normalized;
            }
        }

        return array_values(array_unique($keys));
    }

    public static function get_group_expansions(): array
    {
        $names = self::canonical_names();
        $name_set = array_flip($names);
        $expansions = [];

        foreach (self::GROUPS as $group_key => $def) {
            $children = [];

            if (isset($name_set[$def['anchor']])) {
                $children[] = $def['anchor'];
            }

            foreach ($names as $name) {
                if ($name !== $def['anchor'] && str_starts_with($name, $def['prefix'])) {
                    $children[] = $name;
                }
            }

            if (!empty($children)) {
                $expansions[$group_key] = array_values(array_unique($children));
            }
        }

        return $expansions;
    }

    public static function get_group_definitions(): array
    {
        $expansions = self::get_group_expansions();
        $definitions = [];

        foreach (self::GROUPS as $group_key => $def) {
            $definitions[$group_key] = [
                'key'      => $group_key,
                'label'    => __($def['label'], 'hmw-events'),
                'type'     => 'group',
                'children' => $expansions[$group_key] ?? [],
            ];
        }

        return $definitions;
    }

    public static function group_fields(array $fields): array
    {
        $expansions = self::get_group_expansions();
        $definitions = self::get_group_definitions();

        $child_to_group = [];
        foreach ($expansions as $group_key => $children) {
            foreach ($children as $child) {
                $child_to_group[$child] = $group_key;
            }
        }

        if (empty($child_to_group)) {
            return $fields;
        }

        $grouped = [];
        $inserted = [];

        foreach ($fields as $field) {
            $key = $field['key'] ?? '';

            if (isset($child_to_group[$key])) {
                $group_key = $child_to_group[$key];
                if (!isset($inserted[$group_key])) {
                    $grouped[] = $definitions[$group_key];
                    $inserted[$group_key] = true;
                }
                continue;
            }

            $grouped[] = $field;
        }

        return $grouped;
    }
}
