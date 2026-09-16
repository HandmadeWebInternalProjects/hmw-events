<?php

namespace HMWEvents\Registry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class TaxonomyRegistry
{
    private static ?array $definitions = null;

    public static function all(): array
    {
        if (self::$definitions === null) {
            self::$definitions = apply_filters('hmwevents_taxonomies', self::defaults());
        }

        return self::$definitions;
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    public static function defaults(): array
    {
        return [
            'hmw_event_parenting_topic' => [
                'name'               => 'Parenting Topics',
                'singular'           => 'Parenting Topic',
                'description'        => 'Parenting subject areas for events (sleep, feeding, behaviour, mental health)',
                'hierarchical'       => true,
                'public'             => true,
                'rewrite'            => 'event-parenting-topic',
                'default_terms'      => [
                    'sleep'             => 'Sleep',
                    'feeding'           => 'Feeding',
                    'toddler-behaviour' => 'Toddler Behaviour',
                    'mental-health'     => 'Mental Health',
                ],
                'option_flag'        => 'hmwevents_parenting_topics_inserted',
                'event_field_key'    => 'event_parenting_topic',
                'field_label'        => 'Parenting Topic',
                'required_for'       => ['*'],
                'hidden_for'         => [],
                'filter_key'         => 'topic',
                'show_single_meta'   => true,
                'archive'            => true,
            ],

            'hmw_event_professional_topic' => [
                'name'               => 'Professional Topics',
                'singular'           => 'Professional Topic',
                'description'        => 'Clinical/professional frameworks for professional events (PCIT, Family Partnership Model)',
                'hierarchical'       => true,
                'public'             => false,
                'rewrite'            => false,
                'query_var'          => false,
                'default_terms'      => [
                    'pcit'                     => 'PCIT',
                    'family-partnership-model' => 'Family Partnership Model',
                ],
                'option_flag'        => 'hmwevents_professional_topics_inserted',
                'event_field_key'    => 'event_professional_topic',
                'field_label'        => 'Professional Topic',
                'required_for'       => ['professional-online', 'professional-in-person'],
                'hidden_for'         => [
                    'parenting-webinar',
                    'professional-webinar',
                    'parent-one-off-free',
                    'parent-walk-in',
                    'parent-course',
                ],
                'filter_key'         => null,
                'show_single_meta'   => true,
                'archive'            => false,
            ],

            'hmw_event_program' => [
                'name'               => 'Programs',
                'singular'           => 'Program',
                'description'        => 'Named programs an event belongs to (Circle of Security, Bringing Up Great Kids)',
                'hierarchical'       => true,
                'public'             => true,
                'rewrite'            => 'event-program',
                'default_terms'      => [
                    'circle-of-security'     => 'Circle of Security',
                    'bringing-up-great-kids' => 'Bringing Up Great Kids',
                    'first-steps-count'      => 'First Steps Count',
                ],
                'option_flag'        => 'hmwevents_programs_inserted',
                'event_field_key'    => 'event_program',
                'field_label'        => 'Program',
                'required_for'       => [],
                'hidden_for'         => [],
                'filter_key'         => 'program',
                'show_single_meta'   => true,
                'archive'            => true,
            ],
        ];

    }

    public static function normalize(array $def, string $slug): array
    {
        $public = $def['public'] ?? true;

        return array_merge([
            'name'               => $slug,
            'singular'           => $slug,
            'description'        => '',
            'hierarchical'       => true,
            'public'             => $public,
            'publicly_queryable' => $public,
            'show_admin_column'  => true,
            'rewrite'            => false,
            'query_var'          => null,
            'default_terms'      => [],
            'option_flag'        => '',
            'event_field_key'    => null,
            'field_label'        => '',
            'required_for'       => [],
            'hidden_for'         => [],
            'filter_key'         => null,
            'show_single_meta'   => false,
            'archive'            => false,
        ], $def);
    }

    public static function filter_key_map(): array
    {
        $map = [];
        foreach (self::all() as $slug => $def) {
            $def = self::normalize($def, $slug);
            if ($def['filter_key']) {
                $map[$slug] = $def['filter_key'];
            }
        }

        return $map;
    }

    public static function archive_taxonomies(): array
    {
        $slugs = [];
        foreach (self::all() as $slug => $def) {
            $def = self::normalize($def, $slug);
            if ($def['archive'] && $def['publicly_queryable']) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    public static function single_meta_taxonomies(): array
    {
        $defs = [];
        foreach (self::all() as $slug => $def) {
            $def = self::normalize($def, $slug);
            if ($def['show_single_meta']) {
                $defs[$slug] = $def;
            }
        }

        return $defs;
    }

    public static function apply_to_type_configs(array $archetypes): array
    {
        $rules = [];

        foreach (self::all() as $slug => $def) {
            $def = self::normalize($def, $slug);
            $key = $def['event_field_key'];
            if (!$key) {
                continue;
            }

            $required_for = (array) $def['required_for'];
            $hidden_for   = (array) $def['hidden_for'];
            if ($required_for || $hidden_for) {
                $rules[$key] = [$required_for, $hidden_for];
            }
        }

        if (!$rules) {
            return $archetypes;
        }

        foreach ($archetypes as $slug => $config) {
            foreach ($rules as $key => [$required_for, $hidden_for]) {
                if (in_array('*', $required_for, true) || in_array($slug, $required_for, true)) {
                    $config['required_fields'] = array_values(array_unique(array_merge(
                        (array) ($config['required_fields'] ?? []),
                        [$key]
                    )));
                }

                if (in_array($slug, $hidden_for, true)) {
                    $config['hidden_fields'] = array_values(array_unique(array_merge(
                        (array) ($config['hidden_fields'] ?? []),
                        [$key]
                    )));
                }
            }

            $archetypes[$slug] = $config;
        }

        return $archetypes;
    }
}
