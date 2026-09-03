<?php

namespace HMWEvents\Helpers;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventFieldConfig
{
    public static function read(int $post_id, string $meta_key = '_event_field_config'): ?array
    {
        return self::normalize(get_post_meta($post_id, $meta_key, true));
    }

    public static function normalize(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $unserialized = maybe_unserialize($value);
            if (is_array($unserialized)) {
                return $unserialized;
            }

            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
