<?php

namespace HMWEvents\Meta;

use HMWEvents\Helpers\CurrencyHelper;
use HMWEvents\Services\EventDataService;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Compatibility stub for legacy CourseMeta references.
 * Delegates to EventDataService.
 */
class CourseMeta
{
    public static function get_course_currency(int $course_id): string
    {
        $eds = new EventDataService();
        return $eds->get_currency($course_id);
    }

    public static function get_currency_symbol(string $currency): string
    {
        return CurrencyHelper::get_currency_symbol($currency);
    }

    public static function get_course_cutoff_date(int $course_id): ?string
    {
        $eds = new EventDataService();
        return $eds->get_booking_cutoff($course_id);
    }
}
