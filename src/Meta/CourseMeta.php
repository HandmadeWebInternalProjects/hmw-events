<?php

namespace HMWEvents\Meta;

use HMWEvents\Helpers\CurrencyHelper;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Compatibility stub for legacy CourseMeta references.
 * Delegates to new helpers and event meta.
 */
class CourseMeta
{
    public static function get_course_currency(int $course_id): string
    {
        return CurrencyHelper::get_event_currency($course_id);
    }

    public static function get_currency_symbol(string $currency): string
    {
        return CurrencyHelper::get_currency_symbol($currency);
    }

    public static function get_course_cutoff_date(int $course_id): ?string
    {
        $date = get_post_meta($course_id, '_event_booking_cutoff', true);

        return !empty($date) && is_string($date) ? $date : null;
    }
}
