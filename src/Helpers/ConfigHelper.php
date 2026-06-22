<?php
/**
 * Configuration Helper Functions.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Helpers;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Config Helper Class.
 * 
 * Handles retrieval of plugin options from Exopite framework,
 * which stores options under the 'en' key.
 */
class ConfigHelper
{
    /**
     * Get all plugin options (handles Exopite's 'en' key structure).
     *
     * @since 1.0.0
     * @return array Plugin options.
     */
    public static function get_options()
    {
        $raw_options = get_option(HMWEvents_PLUGIN_NAME, []);
        return isset($raw_options['en']) ? $raw_options['en'] : $raw_options;
    }
    
    /**
     * Get a specific option value.
     *
     * @since 1.0.0
     * @param string $key Option key.
     * @param mixed $default Default value if not found.
     * @return mixed Option value.
     */
    public static function get_option($key, $default = null)
    {
        $options = self::get_options();
        return isset($options[$key]) ? $options[$key] : $default;
    }

  /**
   * Updates a specific configuration option value.
   *
   * Retrieves the current options, updates the specified key with the new value,
   * and saves the updated options array back to the database under the plugin name
   * with 'en' locale wrapper.
   *
   * @since 1.0.0
   * @param string $key   The configuration option key to update.
   * @param mixed  $value The new value to set for the configuration option.
   *
   * @return bool True if the option was updated successfully, false otherwise.
   */
    public static function update_option($key, $value)
    {
        $options = self::get_options();
        $options[$key] = $value;
        return update_option(HMWEvents_PLUGIN_NAME, ['en' => $options]);
    }
}
