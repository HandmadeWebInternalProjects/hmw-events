<?php

namespace HMWEvents\Services;

use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventFormFieldsResolver
{
    public static function for_event(int $event_id): array
    {
        $config = FormConfigResolver::resolve($event_id);

        $fields = ($config !== null && !empty($config['sections']) && is_array($config['sections']))
            ? self::from_sections($config['sections'])
            : self::from_registry();

        return array_filter($fields, fn($field) => ($field['type'] ?? '') !== 'file');
    }

    public static function from_sections(array $sections): array
    {
        $fields = [];

        foreach ($sections as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (!is_array($field) || empty($field['key'])) {
                    continue;
                }

                $fields[$field['key']] = self::normalize($field['key'], $field);
            }
        }

        return $fields;
    }

    public static function from_registry(): array
    {
        $fields = [];

        foreach (RegistrationFieldRegistry::for_admin_display() as $key => $field) {
            $fields[$key] = self::normalize((string) $key, $field);
        }

        return $fields;
    }

    public static function canonical_field_name(string $key): string
    {
        return match ($key) {
            'first_name' => 'customer_first_name',
            'last_name'  => 'customer_last_name',
            'email'      => 'registrant_email',
            'phone'      => 'customer_phone',
            'suburb'     => 'city',
            default      => $key,
        };
    }

    private static function normalize(string $key, array $field): array
    {
        return [
            'key'              => $key,
            'label'            => $field['label'] ?? $key,
            'type'             => $field['type'] ?? 'text',
            'required'         => !empty($field['required']),
            'source'           => $field['source'] ?? '',
            'meta_key'         => $field['meta_key'] ?? null,
            'options'          => $field['options'] ?? [],
            'placeholder'      => $field['placeholder'] ?? '',
            'per_attendee'     => !empty($field['per_attendee']),
            'attendance_types' => array_values(array_filter(array_map('sanitize_key', (array) ($field['attendance_types'] ?? [])))),
            'width'            => $field['width'] ?? 'full',
        ];
    }
}
