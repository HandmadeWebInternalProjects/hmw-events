<?php

namespace HMWEvents\Helpers;

defined('ABSPATH') || die('Don\'t run this file directly!');

class CurrencyHelper
{
    public static function get_event_currency(int $event_id): string
    {
        $currency = get_post_meta($event_id, '_event_currency', true);
        if (!empty($currency) && is_string($currency)) {
            return $currency;
        }

        return 'AUD';
    }

    public static function get_currency_symbol(string $currency): string
    {
        $map = [
            'AUD' => '$',
            'USD' => '$',
            'NZD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CAD' => '$',
            'JPY' => '¥',
        ];

        return $map[strtoupper($currency)] ?? '$';
    }
}
