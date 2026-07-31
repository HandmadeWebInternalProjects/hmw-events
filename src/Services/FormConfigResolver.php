<?php

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class FormConfigResolver
{
    public static function resolve(int $event_id): ?array
    {
        $override = get_post_meta($event_id, '_event_template_override', true);
        if (is_array($override) && !empty($override['registration_fields']['sections'])) {
            return $override['registration_fields'];
        }

        $config = get_post_meta($event_id, '_event_field_config', true);
        if (is_array($config) && !empty($config['registration_fields']['sections'])) {
            $reg = $config['registration_fields'];
            if (is_array($override) && !empty($override['registration_fields']['multi_booking'])) {
                $reg['multi_booking'] = $override['registration_fields']['multi_booking'];
            }
            return $reg;
        }

        return null;
    }
}
