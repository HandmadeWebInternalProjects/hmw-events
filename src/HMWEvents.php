<?php

namespace HMWEvents;

/**
 * Main class for Handmade Web Event Manager plugin.
 *
 * @package HMWEvents
 */

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Main HMWEvents Class.
 */
final class HMWEvents
{
    use Traits\HasComponents;

    /**
     *  The unique identifier of this plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string    $plugin_name    The string used to uniquely identify this plugin.
     */
    protected string $plugin_name;

    /**
     *  An array containing the options saved in the settings page.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string    $plugin_name    The string used to uniquely identify this plugin.
     */
    protected array $options;

    /**
     * Store plugin admin class to allow public access.
     *
     * @since 1.0.0
     * @var object      The admin class.
     */
    public $admin;

    /**
     * Store plugin public class to allow public access.
     *
     * @since 1.0.0
     * @var object      The admin class.
     */
    public $public;

    /**
     * Store plugin flash class to allow public access.
     *
     * @since 1.0.0
     * @var object      The flash messaging class.
     */
    public $flash;

    /**
     * Plugin version.
     *
     * @since 1.0.0
     * @var string
     */
    public $version;

    /**
     * This class instance.
     *
     * @since 1.0.0
     * @var HMWEvents single instance of this class.
     */
    private static $instance;


    public function __construct()
    {
        $this->plugin_name = HMWEvents_PLUGIN_NAME;
        $this->version = HMWEvents_VERSION;
        $this->options = get_option($this->plugin_name, []) ?? [];

    }

    /**
     * Store all the classes inside an array
     * @return array Full list of classes
     */
    public static function get_components(): array
    {
        return [
          // Core Infrastructure
          Services\DatabaseService::class,
          Services\StripeService::class,
          Services\EventDataService::class,
          Services\CapacityService::class,
          Services\PaymentGateway::class,
          Services\VoucherService::class,
          Services\BookingCleanup::class,
          Services\RecurringJobs::class,
          Services\Emails\EmailQueueProcessor::class,
          Services\Emails\EmailEventHooks::class,
          Services\Hooks::class,
          Services\ACF::class,
          Services\EventTemplateService::class,
          Services\EventTemplateOverrideService::class,
          Services\FormSubmissionService::class,
           Services\EventTypeDefaultsService::class,
           Admin\EventPricing::class,
          Services\RegistrationFormPreset::class,
          Services\RegistrationFormRenderer::class,
          Services\DocumentUploadHandler::class,
          Services\PaymentService::class,
           Services\NetTermsHandler::class,
           Services\InvoiceService::class,
           Services\PaymentOverrideService::class,
          Services\WaitlistService::class,
          Services\InvitationTokenService::class,
          Services\BookingSelfCancelService::class,
          Services\SessionService::class,
          Services\SessionBookingService::class,
          Services\EventListingService::class,
          Services\VenueSuburbService::class,
          Services\ReportingService::class,

          // Post Types
          PostTypes\Event::class,
          PostTypes\EventLocation::class,
          PostTypes\Registrant::class,
          PostTypes\Coupon::class,

          // Taxonomies
          Taxonomies\EventType::class,
          Taxonomies\EventAudience::class,
          Taxonomies\EventDeliveryMode::class,
          Services\TaxonomyRegistrar::class,

          // User Roles
          Roles\EventOrganizerRole::class,

          // Meta Fields (placeholder — full ACF groups migrate in later phases)
          // Meta\EventMeta::class,
          // Meta\RegistrantMeta::class,

          // HTTP & API
          Api\RegisterRoutes::class,
          Api\StripeWebhook::class,
          Api\Routes\V3Registration::class,

          // Admin Handlers
          Admin\EventLifecycle::class,
          Admin\EventListColumns::class,
          Admin\RecurringEventHandler::class,
          Admin\EventBookings::class,
          Admin\OrganizerPaymentsDashboard::class,
          Admin\WorkflowEnforcer::class,
          Admin\SessionCalendar::class,

          // Mailing / CRM integrations
          Services\Mailing\MailingDispatcher::class,

          // Maps
          Services\Maps\MapRendererDispatcher::class,

          // UI & Presentation
          Shortcodes\ResumePayment::class,
          Shortcodes\BookingForm::class,
          Shortcodes\BookingConfirmation::class,
          Shortcodes\EventCategoryGrid::class,
        ];
    }

    /**
     * Init Plugin
     *
     * @since 1.0.0
     */
    public function init()
    {
        // Before init action.
        do_action('before_hmwevents_init');

        $this->check_dependencies();
        $this->load_dependencies();

        if ($this->is_request('admin')) {
            $this->admin = new Admin\Admin();
        }

        if ($this->is_request('frontend')) {
            $this->public = new FrontEnd();
        }

        $this->register_components();

        $this->flash = new Services\SessionFlash();

        $this->define_hooks();

        // Init action.
        do_action('hmwevents_init');
        
    }

    /**
     * Main HMWEvents Instance.
     *
     * Singleton instance of the HMWEvents class.
     *
     * @since 1.0.0
     * @return HMWEvents - Main instance.
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Cloning instances is forbidden due to singleton pattern.
     *
     * @since 1.0.0
     */
    public function __clone()
    {
        _doing_it_wrong(__FUNCTION__, sprintf('You cannot clone instances of %s.', esc_html(get_class($this))), '1.0.0');
    }

    /**
     * Unserializing instances is forbidden due to singleton pattern.
     *
     * @since 1.0.0
     */
    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, sprintf('You cannot unserialize instances of %s.', esc_html(get_class($this))), '1.0.0');
    }

    /**
     * Hook into actions and filters.
     *
     * @since 1.0.0
     */
    private function define_hooks()
    {
        register_shutdown_function([$this, 'log_errors']);
        add_action('init', [$this, 'load_plugin_textdomain']);
        add_action('plugins_loaded', [$this, 'maybe_preload_woocommerce_textdomain'], 20);

        // Flush rewrite rules when plugin version changes (prevents 404 on event singles after update)
        add_action('init', function () {
            $stored = get_option('hmwevents_plugin_version', '');
            if ($stored !== HMWEvents_VERSION) {
                flush_rewrite_rules();
                update_option('hmwevents_plugin_version', HMWEvents_VERSION);
            }
        }, 99);

        \HMWEvents\Helpers\GoogleMapField::register_hooks();

        // Initialize REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register REST API routes.
     *
     * @since 1.0.0
     */
    public function register_rest_routes()
    {
        // REST routes will be registered here via RegisterRoutes class
        do_action('hmwevents_register_rest_routes');
    }

    /**
     * Check if required dependencies are available.
     *
     * @since 1.0.0
     * @return bool True if all dependencies are met
     */
    private function check_dependencies(): bool
    {
        $missing_dependencies = [];

        $plugin_name = HMWEvents_PLUGIN_NAME;

        if (!class_exists('ACF')) {
            $missing_dependencies[] = [
              'name' => 'Advanced Custom Fields',
              'url' => 'https://www.advancedcustomfields.com/',
              'message' => "{$plugin_name} requires Advanced Custom Fields to be installed and activated."
            ];
        }

        if (!empty($missing_dependencies)) {
            add_action('admin_notices', function () use ($missing_dependencies) {
                foreach ($missing_dependencies as $dependency) {
                    $link = $dependency['url'] ? sprintf('<a href="%s" target="_blank">%s</a>', $dependency['url'], $dependency['name']) : $dependency['name'];
                    echo sprintf('<div class="notice notice-error"><p>%s</p></div>', $dependency['message']);
                }
            });
            return false;
        }

        return true;
    }

    /**
     * Load required dependencies for plugin.
     *
     * @since 1.0.0
     */
    private function load_dependencies()
    {
        /**
         * Options Framework
         */
        if (!class_exists('Exopite_Simple_Options_Framework', false)) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'src/Admin/exopite-simple-options/exopite-simple-options-framework-class.php';
        }
    }

    /**
     * Ensures fatal errors are logged so they can be picked up in the status report.
     *
     * @since 1.0.0
     */
    public function log_errors()
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            if (defined('WP_DEBUG') && WP_DEBUG === true) {
                /* translators: 1: Error Message 2: File Name and Path 3: Line Number */
                $error_message = sprintf(__('%1$s in %2$s on line %3$s', $this->get_plugin_name()), $error['message'], $error['file'], $error['line']) . PHP_EOL;
                // phpcs:disable WordPress.PHP.DevelopmentFunctions
                error_log($error_message);
                // phpcs:enable WordPress.PHP.DevelopmentFunctions
            }
        }
    }

    /**
     * Returns true if the request is a non-legacy REST API request.
     *
     * Legacy REST requests should still run some extra code for backwards compatibility.
     *
     * @since 1.0.0
     * @return bool
     */
    public function is_rest_api_request()
    {
        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $rest_prefix         = trailingslashit(rest_get_url_prefix());
        $is_rest_api_request = (false !== strpos($_SERVER['REQUEST_URI'], $rest_prefix)); // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        /**
         * Whether this is a REST API request.
         *
         * @since 1.0.0
         */
        return apply_filters('hmwevents_is_rest_api_request', $is_rest_api_request);
    }

    /**
     * Easy way to run checks on what type of request is being made.
     *
     * @since 1.0.0
     *
     * @param string $type admin, ajax, cron or frontend.
     * @return bool
     */
    private function is_request($type)
    {
        return match ($type) {
            'admin' => is_admin(),
            'ajax' => defined('DOING_AJAX'),
            'cron' => defined('DOING_CRON'),
            'frontend' => (!is_admin() || defined('DOING_AJAX')) && !defined('DOING_CRON') && !$this->is_rest_api_request(),
        };
    }

    /**
     * Load Localisation files. We don't normally do this but future proofing.
     *
     * Note: the first-loaded translation file overrides any following ones if the same translation is present.
     *
     * Locales found in:
     *      - WP_LANG_DIR/hmw-events/hmw-events-LOCALE.mo
     *      - WP_LANG_DIR/plugins/hmw-events-LOCALE.mo
     *
     * @since 1.0.0
     */
    public function load_plugin_textdomain()
    {
        $locale = determine_locale();

        /**
         * Filter to adjust the HMWEvents locale to use for translations.
         */
        $plugin_name = $this->get_plugin_name();
        $locale = apply_filters('plugin_locale', $locale, $plugin_name);

        unload_textdomain($plugin_name);
        load_textdomain($plugin_name, WP_LANG_DIR . "/{$plugin_name}/{$plugin_name}-{$locale}.mo");
        load_plugin_textdomain($plugin_name, false, plugin_basename(dirname(HMWEvents_PLUGIN_FILE)) . '/languages');
    }

    /**
     * Preload WooCommerce textdomain to avoid early JIT warnings.
     *
     * @since 1.0.0
     * @return void
     */
    public function maybe_preload_woocommerce_textdomain()
    {
        if (!function_exists('WC')) {
            return;
        }

        if (is_textdomain_loaded('woocommerce')) {
            return;
        }

        $wc = WC();
        if (is_object($wc) && method_exists($wc, 'load_plugin_textdomain')) {
            $wc->load_plugin_textdomain();
        }
    }

    /**
     * The name of the plugin used to uniquely identify it within the context of
     * WordPress and to define internationalization functionality.
     *
     * @since     1.0.0
     * @return    string    The name of the plugin.
     */
    public function get_plugin_name()
    {
        return $this->plugin_name;
    }

    /**
     * Retrieve an option from the plugin settings page.
     *
     * @since 1.0.0
     * @param string $key The option key
     * @param mixed $default Default value if option doesn't exist
     * @return mixed The option value
     */
    public function get_option(string $key, $default = null)
    {
        if (empty($key)) {
            return $default;
        }

        return $this->options[$key] ?? $default;
    }

    /**
     * Get the base page from options or return the default.
     *
     * @since 1.0.0
     * @return string The base page slug
     */
    public function base_page_slug(): string
    {
        $base_page_id = $this->get_option('base_page');

        if ($base_page_id) {
            $slug = get_post_field('post_name', $base_page_id);
            if ($slug && !is_wp_error($slug)) {
                return $slug;
            }
        }

        return 'hmw-events';
    }

    /**
     * Get the plugin url.
     *
     * @since 1.0.0
     * @return string
     */
    public static function plugin_url()
    {
        return untrailingslashit(plugins_url('/', HMWEvents_PLUGIN_FILE));
    }

    /**
     * Get the plugin path.
     *
     * @since 1.0.0
     * @return string
     */
    public static function plugin_path()
    {
        return untrailingslashit(plugin_dir_path(HMWEvents_PLUGIN_FILE));
    }

    /**
     * Get Ajax URL.
     *
     * @since 1.0.0
     * @return string
     */
    public function ajax_url()
    {
        return admin_url('admin-ajax.php', 'relative');
    }
}
