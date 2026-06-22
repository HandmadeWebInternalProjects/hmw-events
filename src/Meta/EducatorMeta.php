<?php
/**
 * Educator User Meta Fields.
 *
 * Manages custom user meta fields for educators using ACF.
 * Handles encryption/decryption of sensitive payment gateway keys.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Meta;

use HMWEvents\Helpers\Encryption;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Educator Meta Fields.
 */
class EducatorMeta
{
    /**
     * Initialize meta field registration.
     *
     * @since 1.0.0
     */
    public function register()
    {        
        // Encrypt Stripe/PayPal keys when saving via ACF
        add_filter('acf/update_value/name=educator_stripe_secret', [$this, 'encrypt_before_save'], 10, 3);
        // add_filter('acf/update_value/name=educator_stripe_key', [$this, 'encrypt_before_save'], 10, 3);
        add_filter('acf/update_value/name=educator_paypal_secret', [$this, 'encrypt_before_save'], 10, 3);
        add_filter('acf/update_value/name=educator_paypal_client_id', [$this, 'encrypt_before_save'], 10, 3);
        
        // Decrypt Stripe/PayPal keys when loading via ACF
        add_filter('acf/load_value/name=educator_stripe_secret', [$this, 'decrypt_on_load'], 10, 3);
        add_filter('acf/load_value/name=educator_stripe_key', [$this, 'decrypt_on_load'], 10, 3);
        add_filter('acf/load_value/name=educator_paypal_secret', [$this, 'decrypt_on_load'], 10, 3);
        add_filter('acf/load_value/name=educator_paypal_client_id', [$this, 'decrypt_on_load'], 10, 3);
    }

    /**
     * Encrypt value before saving to database.
     *
     * @since 1.0.0
     * @param mixed $value The value to save.
     * @param int   $post_id The post ID (or user ID with 'user_' prefix).
     * @param array $field The field array.
     * @return mixed Encrypted value.
     */
    public function encrypt_before_save($value, $post_id, $field)
    {
        // If empty, allow normal save
        if (empty($value)) {
            return $value;
        }

        // If not a string, return as-is
        if (!is_string($value)) {
            return $value;
        }

        // Check if value is already encrypted (starts with "def" - Defuse prefix)
        if (strpos($value, 'def') === 0 && strlen($value) > 100) {
            // Already encrypted, return as-is
            return $value;
        }

        // Encrypt the value
        $encrypted = Encryption::encrypt($value);

        if ($encrypted === false) {
            error_log("Failed to encrypt {$field['name']} for user/post {$post_id}");
            return $value; // Return original value if encryption fails
        }

        return $encrypted;
    }

    /**
     * Decrypt value when loading from database.
     *
     * @since 1.0.0
     * @param mixed $value The value from database.
     * @param int   $post_id The post ID (or user ID with 'user_' prefix).
     * @param array $field The field array.
     * @return mixed Decrypted value.
     */
    public function decrypt_on_load($value, $post_id, $field)
    {
        // If empty, return as-is
        if (empty($value)) {
            return $value;
        }

        // If it's an array, extract first element
        if (is_array($value)) {
            $value = !empty($value) ? $value[0] : '';
            if (empty($value)) {
                return '';
            }
        }

        // If not a string, return as-is
        if (!is_string($value)) {
            return $value;
        }

        // Check if it looks encrypted (Defuse format starts with "def")
        if (strpos($value, 'def') !== 0) {
            // Not encrypted, return as-is
            return $value;
        }

        // Decrypt
        $decrypted = Encryption::decrypt($value);

        // Return decrypted value or original if decryption failed
        return $decrypted !== false ? $decrypted : $value;
    }
}
