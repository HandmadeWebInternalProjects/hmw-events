<?php

use HMWEvents\HMWEvents;
use function Breakdance\Util\getDirectoryPathRelativeToPluginFolder;

/**
 * Plugin Name: Handmade Web Event Manager
 * Plugin URI: https://handmadewebdesign.com.au/
 * Description: Base plugin for the Handmade Web Event Manager system for booking management
 * Version: 1.2.3
 * Author: Handmade Web & Design
 * Author URI: https://www.handmadewebdesign.com.au/
 * Text Domain: hmw-events
 * Domain Path: /languages/
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Tested up to: 6.3
 * GitHub Plugin URI: https://github.com/HandmadeWebExternalProjects/hmw-events
 * Primary Branch: main
 */

defined('ABSPATH') || die('Don\'t run this file directly!');

if (!defined('HMWEvents_PLUGIN_FILE')) {
    define('HMWEvents_PLUGIN_FILE', __FILE__);
}
if (!defined('HMWEvents_PLUGIN_NAME')) {
    define('HMWEvents_PLUGIN_NAME', 'hmw-events');
}
if (!defined('HMWEvents_VERSION')) {
    define('HMWEvents_VERSION', '1.2.3');
}
if (!defined('HMWEvents_ABSPATH')) {
    define('HMWEvents_ABSPATH', dirname(HMWEvents_PLUGIN_FILE) . '/');
}
if (!defined('HMWEvents_PLUGIN_BASENAME')) {
    define('HMWEvents_PLUGIN_BASENAME', plugin_basename(HMWEvents_PLUGIN_FILE));
}

if (!file_exists($composer = __DIR__ . '/vendor/autoload.php')) {
    wp_die(__('Error locating autoloader. Please run <code>composer install</code>.'));
}

if (!defined('VIEWS_PATH')) {
    define('VIEWS_PATH', HMWEvents_ABSPATH . 'src/views/');
}

include_once HMWEvents_ABSPATH . 'src/Helpers.php';


require $composer;

/**
 * Returns the main instance of HMWEvents.
 *
 * @since 1.0.0
 * @return HMWEvents
 */
function HMWEvents()
{
    return HMWEvents::instance();
}

// Set to wp global
$GLOBALS['hmwevents'] = HMWEvents();

// Remove post tags from the 'post' post type globally
add_action('init', function () {
    unregister_taxonomy_for_object_type('post_tag', 'post');
}, 999);

add_action('plugins_loaded', function () {
    HMWEvents()->init();
}, 10);

// Load WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    require_once HMWEvents_ABSPATH . 'src/CLI/ImportEventVenueCommand.php';
    require_once HMWEvents_ABSPATH . 'src/CLI/RepairForeignKeysCommand.php';
}

// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'hmwevents_activate');
register_deactivation_hook(__FILE__, 'hmwevents_deactivate');
/**
 * Plugin activation hook.
 *
 * @since 1.0.0
 * @updated 1.0.17 Added foreign key management for migrations
 */
function hmwevents_activate()
{
    require_once HMWEvents_ABSPATH . 'includes/install.php';
    hmwevents_activation();
}

/**
 * Plugin deactivation hook.
 *
 * @since 1.0.0
 */
function hmwevents_deactivate()
{
    // Remove capabilities from event organizer role
    $organizer_role = new \HMWEvents\Roles\EventOrganizerRole();
    $organizer_role->remove_capabilities();

    // Unschedule recurring jobs
    $recurring_jobs = new \HMWEvents\Services\RecurringJobs();
    $recurring_jobs->unregister();
}

add_action(
  'breakdance_loaded',
  function () {
    \Breakdance\ElementStudio\registerSaveLocation(
      getDirectoryPathRelativeToPluginFolder(__DIR__) . '/src/Breakdance/elements',
      'HMWEvents\Breakdance',
      'element',
      'HMWEvents Custom Elements',
      false
    );

    \Breakdance\ElementStudio\registerSaveLocation(
      getDirectoryPathRelativeToPluginFolder(__DIR__) . '/src/Breakdance/macros',
      'HMWEvents\Breakdance;',
      'macro',
      'HMWEvents Custom Macros',
      false,
    );

    \Breakdance\ElementStudio\registerSaveLocation(
      getDirectoryPathRelativeToPluginFolder(__DIR__) . '/src/Breakdance/presets',
      'HMWEvents\Breakdance;',
      'preset',
      'HMWEvents Custom Presets',
      false,
    );
  },
  // register elements before loading them
  9
);

add_filter('breakdance_reusable_dependencies_urls', function ($urls) {

  $urls['hmwevents'] = plugins_url('/', __FILE__);

  return $urls;
}, PHP_INT_MAX);