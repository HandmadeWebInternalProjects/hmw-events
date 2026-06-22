<?php
/**
 * Educator Course Meta Fields.
 *
 * Manages custom meta fields for the Educator Course post type using ACF.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Meta;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Course Meta Fields.
 */
class CourseMeta
{
    /**
     * Initialize meta field registration.
     *
     * @since 1.0.0
     */
    public function register()
    {
        add_filter('acf/prepare_field/name=course_template_id', [$this, 'admin_only_field']);
        add_filter('acf/prepare_field/name=course_recurrence_id', [$this, 'admin_only_field']);
        add_filter('acf/prepare_field/name=course_educator_id', [$this, 'admin_only_field']);

        add_filter('acf/prepare_field/name=course_is_external', [$this, 'hospital_only_field']);
        add_filter('acf/prepare_field/name=course_external_url', [$this, 'hospital_only_field']);
    }



    /**
     * Make educator ID field readonly for non-admins.
     *
     * @since 1.0.0
     * @param array $field ACF field array.
     * @return array Modified field array.
     */
    public function admin_only_field($field)
    {
      // Roles that can edit
      $allowed_roles = ['administrator', 'shop_manager']; // Add roles as needed
      
      if (array_intersect($allowed_roles, wp_get_current_user()->roles)) {
          $field['readonly'] = 0;
      } else {
          return false;
      }

      return $field;
    }

    /**
     * Show field only for hospital educators.
     *
     * Hides the external booking fields for educators who are not
     * partner hospital users (educator_hospital = true).
     *
     * @since 1.0.0
     * @param array $field ACF field array.
     * @return array|false Modified field array or false to hide.
     */
    public function hospital_only_field($field)
    {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return false;
        }

        $is_hospital = get_field('educator_hospital', 'user_' . $user_id);

        if (!$is_hospital) {
            return false;
        }

        return $field;
    }

    /**
     * Get currency code for a course.
     *
     * @since 1.0.0
     * @param int $course_id Course ID.
     * @return string Currency code (e.g., 'AUD', 'NZD', 'USD').
     */
    public static function get_course_currency($course_id)
    {
        $currency = get_field('course_currency', $course_id);
        return !empty($currency) ? strtoupper($currency) : 'AUD';
    }

    /**
     * Get currency symbol for a currency code.
     *
     * @since 1.0.0
     * @param string $currency_code Currency code (e.g., 'AUD', 'NZD', 'USD').
     * @return string Currency symbol.
     */
    public static function get_currency_symbol($currency_code = 'AUD')
    {
        $symbols = [
            'AUD' => 'A$',
            'NZD' => 'NZ$',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'CAD' => 'C$',
            'SGD' => 'S$',
            'HKD' => 'HK$',
            'JPY' => '¥',
            'CNY' => '¥',
            'INR' => '₹',
            'THB' => '฿',
            'PHP' => '₱',
            'MYR' => 'RM',
            'IDR' => 'Rp',
            'VND' => '₫',
            'KRW' => '₩',
            'TWD' => 'NT$',
            'BRL' => 'R$',
            'MXN' => 'Mex$',
            'ZAR' => 'R',
            'AED' => 'AED',
            'SAR' => 'SAR',
            'CHF' => 'CHF',
            'SEK' => 'kr',
            'NOK' => 'kr',
            'DKK' => 'kr',
            'PLN' => 'zł',
            'CZK' => 'Kč',
            'HUF' => 'Ft',
            'RON' => 'lei',
        ];

        return $symbols[strtoupper($currency_code)] ?? strtoupper($currency_code);
    }

    /**
     * Format amount with currency symbol.
     *
     * @since 1.0.0
     * @param float $amount Amount to format.
     * @param string $currency_code Currency code.
     * @return string Formatted amount with currency symbol.
     */
    public static function format_currency($amount, $currency_code = 'AUD')
    {
        $symbol = self::get_currency_symbol($currency_code);
        return $symbol . number_format($amount, 2);
    }

    public static function get_course_cutoff_date($course_id)
    {
        if (!function_exists('get_field')) {
            return null; // ACF not available
        }
        $cutoff_date = get_field('course_cutoff_date', $course_id);
        return !empty($cutoff_date) ? $cutoff_date : null;
    }
}
