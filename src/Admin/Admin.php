<?php

namespace HMWEvents\Admin;

/**
 * Handmade Web Event Manager Admin Class.
 *
 * @package HMWEvents
 */

use HMWEvents\HMWEvents;
use HMWEvents\Helpers\Encryption;
use HMWEvents\Services\Emails\EmailQueueRepository;
use HMWEvents\Services\Emails\EmailService;
use HMWEvents\Services\Emails\EmailTemplateRepository;

use function get_current_screen;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * HMWEvents Admin Class.
 */
class Admin
{
  use \HMWEvents\Traits\HasComponents;
  protected $options_panel;

  /**
   * Fields that need encryption.
   *
   * @var array
   */
  private $encrypted_fields = [
    'hmwevents_stripe_test_secret_key',
    'hmwevents_stripe_test_webhook_secret',
    'hmwevents_stripe_live_secret_key',
    'hmwevents_stripe_live_webhook_secret',
    'hmwevents_mautic_password',
  ];

  /**
   * Constructor.
   *
   * @since 1.0.0
   */
  public function __construct()
  {

    add_action('init', [$this, 'init']);

    // Admin assets
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
  }

  public function init()
  {
    // Add main admin menu first
    add_action('admin_menu', [$this, 'admin_menu'], 5); // Earlier priority

    // Add menus after main menu is registered
    add_action('admin_menu', [$this, 'create_menu'], 10); // Later priority

    // Admin notices
    add_action('admin_notices', [$this, 'display_admin_notices']);

    // Initialize email handlers
    new EmailQueue();
    new EmailTemplates();
    new EventTemplates();
    new Reporting();

    // Add filter to encrypt Stripe keys before saving
    add_filter('exopite_sof_save_menu_options', [$this, 'encrypt_sensitive_fields'], 10, 2);

    // Add filter to decrypt Stripe keys when displaying form
    // add_filter('exopite-simple-options-get-' . HMWEvents()->get_plugin_name(), [$this, 'decrypt_stripe_keys_for_display'], 10, 1);

    $this->register_components();
  }

  /**
   * Store all the classes inside an array
   * @return array Full list of classes
   */
  public static function get_components(): array
  {
    return [];
  }

  /**
   * Create options page and metabox.
   */
  public function create_menu()
  {
    /**
     * Create a settings page using Exopite framework.
     * We're manually creating the submenu, so Exopite should just handle the page content.
     * @link https://github.com/JoeSz/Exopite-Simple-Options-Framework
     */
    $config_submenu = array(
      'type'              => 'menu',
      'id'                => HMWEvents()->get_plugin_name(),
      'parent'            => 'hmwevents-main',
      'submenu'           => false,                          // Don't create submenu - we did it manually
      'title'             => 'Settings',
      'menu_title'        => 'Settings',
      'capability'        => 'manage_options',
      'plugin_basename'   => plugin_basename(plugin_dir_path(__DIR__) . HMWEvents()->get_plugin_name() . '.php'),
    );

    /*
         * To add a metabox.
         * This normally go to your functions.php or another hook
         */
    $config_metabox = [

      /*
             * METABOX
             */
      'type'              => 'metabox',                       // Required, menu or metabox
      'id'                => HMWEvents()->get_plugin_name() . '-meta',   // Required, meta box id, unique, for saving meta: id[field-id]
      'post_types'        => [HMWEvents()->get_plugin_name()],  // Post types to display meta box
      // 'post_types'        => [ 'post', 'page' ],         // Could be multiple
      'context'           => 'advanced',                      // The context within the screen where the boxes should display: 'normal', 'side', and 'advanced'.
      'priority'          => 'default',                       // 	The priority within the context where the boxes should show ('high', 'low').
      'title'             => 'Demo Metabox',                  // The title of the metabox
      'capability'        => 'edit_posts',                    // The capability needed to view the page
      // 'tabbed'            => false,                        // Add tabs or not, default true
      // 'simple'            => true,                         // Save post meta as simple instead of an array, default false
      // 'multilang'         => true,                         // Multilang support, required for ONLY qTranslate-X and WP Multilang

    ];

    /**
     * Available fields:
     * - ACE field
     * - attached
     * - backup
     * - button
     * - botton_bar
     * - card
     * - checkbox
     * - color
     * - content
     * - date
     * - editor
     * - group/accordion item
     * - hidden
     * - image
     * - image_select
     * - meta
     * - notice
     * - number
     * - password
     * - radio
     * - range
     * - select
     * - switcher
     * - tab
     * - tap_list
     * - text
     * - textarea
     * - typography
     * - upload
     * - video mp4/oembed
     */


    $fields[] = [
      'name'   => 'setup',
      'title'  => 'Setup',
      'icon'   => 'dashicons-admin-generic',
      'fields' => [

        [
          'id'    => 'hmwevents_clean_uninstall',
          'type'  => 'checkbox',
          'title' => 'Clean Uninstall',
          'label' => 'If enabled, when the plugin is deleted, all data will be removed from the database.',
          'style' => 'fancy',
        ],

        [
          'id'    => 'hmwevents_email_logo',
          'type'    => 'image',
          'title'   => 'Email Logo',
        ],

      ],
    ];

    $fields[] = [
      'name'   => 'stripe',
      'title'  => 'Stripe Settings',
      'icon'   => 'dashicons-money-alt',
      'fields' => [

        [
          'type'    => 'notice',
          'class'   => 'info',
          'content' => '<h3>Stripe Payment Configuration</h3><p>Configure your Stripe API credentials to enable payment processing. You can switch between test and live modes.</p>',
        ],

        [
          'id'      => 'hmwevents_stripe_mode',
          'type'    => 'radio',
          'title'   => 'Stripe Mode',
          'options' => [
            'test' => 'Test Mode (for development and testing)',
            'live' => 'Live Mode (processes real payments)',
          ],
          'default' => 'test',
          'style'   => 'fancy',
          'desc'    => 'Select whether to use test or live Stripe credentials.',
        ],

        [
          'type'    => 'content',
          'content' => '<hr><h3 style="margin-top: 20px;">Test Mode Credentials</h3><p>Test mode credentials for development and testing. Find these in your <a href="https://dashboard.stripe.com/test/apikeys" target="_blank">Stripe Dashboard (Test Mode)</a>.</p>',
        ],

        [
          'id'          => 'hmwevents_stripe_test_secret_key',
          'type'        => 'password',
          'title'       => 'Test Secret Key',
          'placeholder' => 'sk_test_...',
          'desc'        => 'Your Stripe test secret key (starts with sk_test_)',
        ],

        [
          'id'          => 'hmwevents_stripe_test_publishable_key',
          'type'        => 'text',
          'title'       => 'Test Publishable Key',
          'placeholder' => 'pk_test_...',
          'desc'        => 'Your Stripe test publishable key (starts with pk_test_)',
        ],

        [
          'id'          => 'hmwevents_stripe_test_webhook_secret',
          'type'        => 'password',
          'title'       => 'Test Webhook Secret',
          'placeholder' => 'whsec_...',
          'desc'        => 'Your Stripe test webhook signing secret (starts with whsec_)',
        ],

        [
          'type'    => 'content',
          'content' => '<hr><h3 style="margin-top: 20px;">Live Mode Credentials</h3><p><strong>⚠️ Warning:</strong> Live mode credentials will process real payments. Find these in your <a href="https://dashboard.stripe.com/apikeys" target="_blank">Stripe Dashboard (Live Mode)</a>.</p>',
        ],

        [
          'id'          => 'hmwevents_stripe_live_secret_key',
          'type'        => 'password',
          'title'       => 'Live Secret Key',
          'placeholder' => 'sk_live_...',
          'desc'        => 'Your Stripe live secret key (starts with sk_live_)',
        ],

        [
          'id'          => 'hmwevents_stripe_live_publishable_key',
          'type'        => 'text',
          'title'       => 'Live Publishable Key',
          'placeholder' => 'pk_live_...',
          'desc'        => 'Your Stripe live publishable key (starts with pk_live_)',
        ],

        [
          'id'          => 'hmwevents_stripe_live_webhook_secret',
          'type'        => 'password',
          'title'       => 'Live Webhook Secret',
          'placeholder' => 'whsec_...',
          'desc'        => 'Your Stripe live webhook signing secret (starts with whsec_)',
        ],

        [
          'type'    => 'content',
          'content' => '<hr><h3 style="margin-top: 20px;">Webhook Configuration</h3><p>Configure your Stripe webhook to point to:</p><p><code style="background: #f0f0f0; padding: 5px 10px; display: inline-block; margin: 10px 0;">' . rest_url('hmwevents/v1/webhook/stripe') . '</code></p><p>Select the following events in your <a href="https://dashboard.stripe.com/webhooks" target="_blank">Stripe Dashboard</a>:</p><ul style="list-style-type: disc; margin-left: 20px;"><li><code>payment_intent.succeeded</code></li><li><code>payment_intent.payment_failed</code></li><li><code>charge.refunded</code></li><li><code>charge.dispute.created</code></li></ul>',
        ],

        [
          'type'    => 'content',
          'content' => '<hr><h3 style="margin-top: 20px;">Getting Started</h3><ol><li>Sign up for a <a href="https://dashboard.stripe.com/register" target="_blank">Stripe account</a> if you don\'t have one</li><li>Get your API keys from the <a href="https://dashboard.stripe.com/test/apikeys" target="_blank">Stripe Dashboard</a></li><li>Start in <strong>Test Mode</strong> to safely test payments</li><li>Configure your webhook endpoint in Stripe (URL shown above)</li><li>When ready, switch to <strong>Live Mode</strong> with your live credentials</li></ol>',
        ],

      ],
    ];

    $fields[] = [
      'name'   => 'mailing',
      'title'  => 'Mailing / CRM',
      'icon'   => 'dashicons-email-alt',
      'fields' => [

        [
          'type'    => 'notice',
          'class'   => 'info',
          'content' => '<h3>Mailing / CRM Integration</h3><p>When a booking is confirmed, the customer can optionally be synced to your CRM / mailing service. Select a provider below to enable this feature.</p>',
        ],

        [
          'id'      => 'hmwevents_mailing_provider',
          'type'    => 'radio',
          'title'   => 'Mailing Provider',
          'options' => [
            'none'   => 'Disabled',
            'mautic' => 'Mautic',
          ],
          'default' => 'none',
          'style'   => 'fancy',
          'desc'    => 'Choose which service receives new booking contacts. More providers can be added in future.',
        ],

        [
          'type'    => 'content',
          'content' => '<hr><h3 style="margin-top:20px;">Mautic Settings</h3><p>Only required when Mautic is selected above.</p>',
        ],

        [
          'id'          => 'hmwevents_mautic_url',
          'type'        => 'text',
          'title'       => 'Mautic URL',
          'placeholder' => 'https://info.example.com',
          'desc'        => 'Base URL of your Mautic instance (no trailing slash).',
        ],

        [
          'id'          => 'hmwevents_mautic_username',
          'type'        => 'text',
          'title'       => 'Mautic Username',
          'placeholder' => 'CalmbirthWebsiteCRON',
          'desc'        => 'Basic Auth username for the Mautic API.',
        ],

        [
          'id'          => 'hmwevents_mautic_password',
          'type'        => 'password',
          'title'       => 'Mautic Password',
          'placeholder' => '',
          'desc'        => 'Basic Auth password for the Mautic API. Stored encrypted.',
        ],

      ],
    ];

    $fields[] = [
      'name'   => 'educator_defaults',
      'title'  => 'Educator Defaults',
      'icon'   => 'dashicons-awards',
      'fields' => [

        [
          'type'    => 'notice',
          'class'   => 'info',
          'content' => '<h3>Educator Default Settings</h3><p>Configure default audio files and other resources that educators can use in their email templates.</p>',
        ],

        [
          'id'          => 'hmwevents_audio_download_url',
          'type'        => 'text',
          'title'       => 'Audio Download URL',
          'placeholder' => 'https://www.dropbox.com/s/...',
          'desc'        => 'URL to the course audio file (e.g., Dropbox link). Used in {{download_audio_track}} template variable.',
        ],

        [
          'id'          => 'hmwevents_free_pre_course_audio_url',
          'type'        => 'text',
          'title'       => 'Free Pre-Course Audio Track URL',
          'placeholder' => 'https://www.dropbox.com/s/...',
          'desc'        => 'URL to the free pre-course audio track (e.g., Dropbox link). Used in {{free_pre_course_audio_track}} template variable.',
        ],

        [
          'id'          => 'hmwevents_feedback_form_url',
          'type'        => 'text',
          'title'       => 'Feedback Form URL',
          'placeholder' => 'https://example.com/feedback/',
          'desc'        => 'Base URL for feedback form links. Used in {{feedback_url}} template variable; booking ID is appended automatically.',
        ],

      ],
    ];

    /**
     * instantiate your admin page
     */
    $this->options_panel = new \Exopite_Simple_Options_Framework($config_submenu, $fields);
    // $options_panel = new Exopite_Simple_Options_Framework($config_metabox, $fields);
  }

  /**
   * Add menu items.
   *
   * @since 1.0.0
   */
  public function admin_menu()
  {
    // Add main menu page
    add_menu_page(__('Handmade Web Event Manager', 'cms'), __('Handmade Web Event Manager', 'cms'), 'manage_options', 'hmwevents-main', [$this, 'render_admin_menu_page'], 'dashicons-wordpress', 60);

    // Manually add the Settings submenu - Exopite seems to not be creating it
    add_submenu_page(
      'hmwevents-main',                    // Parent slug
      'Settings',                              // Page title
      'Settings',                              // Menu title
      'manage_options',                        // Capability
      HMWEvents()->get_plugin_name(),      // Menu slug (cms)
      [$this, 'render_settings_page']         // Callback function
    );

    // Email Queue page
    add_submenu_page(
      'hmwevents-main',
      'Email Queue',
      'Email Queue',
      'manage_options',
      'hmwevents-email-queue',
      [$this, 'render_email_queue_page']
    );

    // Email Templates page
    add_submenu_page(
      'hmwevents-main',
      'Email Templates',
      'Email Templates',
      'manage_options',
      'hmwevents-email-templates',
      [$this, 'render_email_templates_page']
    );

    // Event Templates page
    add_submenu_page(
      'hmwevents-main',
      'Event Templates',
      'Event Templates',
      'manage_options',
      'hmwevents-event-templates',
      [$this, 'render_event_templates_page']
    );

    // Reporting page (admin only)
    add_submenu_page(
      'hmwevents-main',
      'Reporting',
      'Reporting',
      'manage_options',
      'hmwevents-reporting',
      [$this, 'render_reporting_page']
    );
  }

  /**
   * Display admin notices from transients
   */
  public function display_admin_notices()
  {
    // Check for success messages
    if ($success_message = get_transient('lhmwevents_success_notice')) {
      echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($success_message) . '</p></div>';
      delete_transient('lhmwevents_success_notice');
    }

    // Check for error messages
    if ($error_message = get_transient('lhmwevents_error_notice')) {
      echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_message) . '</p></div>';
      delete_transient('lhmwevents_error_notice');
    }

    // Check for Action Scheduler availability on our admin pages
    $current_screen = get_current_screen();
    if ($current_screen && strpos($current_screen->id, 'lsa-monthly-email-reports') !== false) {
      if (!function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler_Store')) {
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>Action Scheduler Required:</strong> ';
        echo 'For optimal performance with background email processing, please install and activate the ';
        echo '<a href="' . admin_url('plugin-install.php?s=action+scheduler&tab=search&type=term') . '" target="_blank">Action Scheduler</a> plugin.';
        echo '</p></div>';
      }
    }
  }

  /**
   * Render settings page - this will be handled by Exopite
   */
  public function render_settings_page()
  {
    // Call the Exopite framework's display_page method
    if (isset($this->options_panel) && method_exists($this->options_panel, 'display_page')) {
      $this->options_panel->display_page();
    } else {
      echo '<div class="wrap">';
      echo '<h1>Settings</h1>';
      echo '<p>Settings framework not loaded properly.</p>';
      echo '</div>';
    }
  }

  /**
   * Render email queue page.
   *
   * @since 1.0.0
   */
  public function render_email_queue_page()
  {
    $email_queue = new EmailQueue();
    $email_queue->render_page();
  }

  /**
   * Render email templates page.
   *
   * @since 1.0.0
   */
  public function render_email_templates_page()
  {
    $email_templates = new EmailTemplates();
    $email_templates->render_page();
  }

  /**
   * Render event templates page.
   */
  public function render_event_templates_page()
  {
    $event_templates = new EventTemplates();
    $event_templates->render_page();
  }

  /**
   * Render reporting page.
   *
   * @since 1.0.0
   */
  public function render_reporting_page()
  {
    $reporting = new Reporting();
    $reporting->render_page();
  }







  /**
   * Valid screen ids for plugin admin assets.
   *
   * @since 1.0.0
   * @return array
   */
  public function is_valid_screen()
  {
    $screen    = get_current_screen();
    $screen_id = $screen ? $screen->id : '';

    $valid_screen_ids = apply_filters(
      'lhmwevents_valid_admin_screen_ids',
      [
        HMWEvents()->get_plugin_name(),
        'lsa-monthly-email-reports',
        'lsa-monthly-reports',
        'hmwevents-email-queue',
        'hmwevents-email-templates',
        'hmwevents-event-templates',
        'hmwevents-reporting'
      ]
    );

    if (empty($valid_screen_ids)) {
      return false;
    }

    foreach ($valid_screen_ids as $admin_screen_id) {
      $matcher = '/' . $admin_screen_id . '/';
      if (preg_match($matcher, $screen_id)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Encrypt sensitive fields before saving.
   *
   * Hooks into: exopite_sof_save_menu_options
   *
   * @since 1.0.0
   * @param array $valid The options array being saved.
   * @param string $unique The unique identifier for this options page.
   * @return array The options array with encrypted sensitive fields.
   */
  public function encrypt_sensitive_fields($valid, $unique)
  {

    error_log('Entering encrypt_sensitive_fields');
    error_log(json_encode($valid));
    error_log(json_encode($unique));
    // Only process our plugin's options
    if ($unique !== HMWEvents()->get_plugin_name()) {
      return $valid;
    }

    if (!is_array($valid)) {
      return $valid;
    }

    $options = $valid['en'] ?? [];

    foreach ($this->encrypted_fields as $field) {
      if (isset($options[$field])) {
        $value = $options[$field];

        if ($this->should_encrypt_value($value)) {
          $encrypted = Encryption::encrypt($value);

          if ($encrypted !== false) {
            $options[$field] = $encrypted;
            error_log("Encrypted field: {$field}");
          } else {
            error_log("HMWEvents: Failed to encrypt field: {$field}");
          }
        } else {
          error_log("Field does not need encryption: {$field}");
        }
      }
    }

    $valid['en'] = $options;

    return $valid;
  }

  /**
   * Decrypt sensitive fields when retrieving options.
   *
   * Hooks into: exopite_sof_menu_get_options
   *
   * @since 1.0.0
   * @param array $options The options array being retrieved.
   * @param string $unique The unique identifier for this options page.
   * @return array The options array with decrypted sensitive fields.
   */
  public function decrypt_sensitive_fields($options, $unique)
  {
    // Only process our plugin's options
    if ($unique !== HMWEvents()->get_plugin_name()) {
      return $options;
    }

    if (!is_array($options)) {
      return $options;
    }

    foreach ($this->encrypted_fields as $field) {
      if (isset($options[$field]) && !empty($options[$field])) {
        $value = $options[$field];

        // Only decrypt if it appears to be encrypted
        if ($this->is_encrypted_value($value)) {
          $decrypted = Encryption::decrypt($value);

          if ($decrypted !== false) {
            $options[$field] = $decrypted;
          } else {
            // If decryption fails, it might be plain text (legacy data)
            // Leave it as is and let it get re-encrypted on next save
            error_log("HMWEvents: Failed to decrypt field: {$field} - may be plain text");
          }
        }
      }
    }

    return $options;
  }

  /**
   * Check if a value should be encrypted.
   *
   * @since 1.0.0
   * @param string $value The value to check.
   * @return bool True if value should be encrypted.
   */
  private function should_encrypt_value($value)
  {
    // Already encrypted (Defuse format)
    if ($this->is_encrypted_value($value)) {
      return false;
    }

    // Empty value
    if (empty($value)) {
      return false;
    }

    // Looks like a Stripe secret key or webhook secret
    if (preg_match('/^(sk_test_|sk_live_|whsec_)/', $value)) {
      return true;
    }

    // If we're not sure, encrypt it to be safe
    return true;
  }

  /**
   * Check if a value is already encrypted.
   *
   * @since 1.0.0
   * @param string $value The value to check.
   * @return bool True if value appears to be encrypted.
   */
  private function is_encrypted_value($value)
  {
    // Defuse encrypted strings start with 'def' followed by base64
    // They're also longer than typical API keys
    return (
      strlen($value) > 100 &&
      substr($value, 0, 3) === 'def' &&
      preg_match('/^def[0-9a-f]+$/', $value)
    );
  }

  /**
   * Enqueue assets.
   *
   * @since 1.0.0
   */
  public function enqueue_assets()
  {
    $screen = get_current_screen();
    $screen_id = $screen ? $screen->id : '';

    // Admin styles for cms pages only.
    if ($this->is_valid_screen()) {

      // Styles.
      wp_enqueue_style('hmwevents-admin', HMWEvents::plugin_url() . '/resources/admin/css/admin.css', [], HMWEvents_VERSION);

      // Scripts.
      wp_enqueue_script('hmwevents-admin', HMWEvents::plugin_url() . '/resources/admin/js/admin.js', [], HMWEvents_VERSION, true);

      // Add module type attribute
      add_filter('script_loader_tag', function ($tag, $handle, $src) {
        if ('hmwevents-admin' === $handle) {
          $tag = '<script type="module" src="' . esc_url($src) . '" id="' . $handle . '-js"></script>';
        }
        return $tag;
      }, 10, 3);

      // Localize scripts.
      $localize_params = [
        'ajax_url' => admin_url('admin-ajax.php'),
        'rest_url' => rest_url('hmwevents/v1'),
        'nonce'    => wp_create_nonce('wp_rest'),
      ];

      wp_localize_script('hmwevents-admin', 'lhmwevents_params', $localize_params);
    }

    // Email admin styles and scripts
    if (strpos($screen_id, 'hmwevents-email-queue') !== false || strpos($screen_id, 'hmwevents-email-templates') !== false) {
      wp_enqueue_style('hmwevents-email-admin', HMWEvents::plugin_url() . '/resources/admin/css/email-admin.css', [], HMWEvents_VERSION);
      
      // Email queue specific JS
      if (strpos($screen_id, 'hmwevents-email-queue') !== false) {
        wp_enqueue_script('hmwevents-email-queue', HMWEvents::plugin_url() . '/resources/admin/js/email-queue.js', ['jquery'], HMWEvents_VERSION, true);
      }
    }

    // Email templates specific JS (on email templates page AND profile/user-edit pages for educator templates)
    if (strpos($screen_id, 'hmwevents-email-templates') !== false || strpos($screen_id, 'profile') !== false || strpos($screen_id, 'user-edit') !== false) {
      wp_enqueue_script('hmwevents-email-templates', HMWEvents::plugin_url() . '/resources/admin/js/email-templates.js', ['jquery'], HMWEvents_VERSION, true);
      wp_localize_script('hmwevents-email-templates', 'cmsEmailTemplates', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('hmwevents_email_templates'),
      ]);
    }

    // Event templates GUI editor JS
    if (strpos($screen_id, 'hmwevents-event-templates') !== false) {
      wp_enqueue_script('hmwevents-event-templates', HMWEvents::plugin_url() . '/resources/admin/js/event-templates.js', ['jquery'], HMWEvents_VERSION, true);
    }

    // Course bookings meta box assets (only on course edit screen)
    if ($screen && $screen->id === 'educator_course') {
      wp_enqueue_style(
        'hmwevents-course-bookings',
        HMWEvents::plugin_url() . '/resources/admin/css/course-bookings.css',
        [],
        HMWEvents_VERSION
      );

      wp_enqueue_script(
        'hmwevents-course-bookings',
        HMWEvents::plugin_url() . '/resources/admin/js/course-bookings.js',
        ['jquery'],
        HMWEvents_VERSION,
        true
      );

      $edit_modal_fields = \HMWEvents\Registry\RegistrationFieldRegistry::for_admin_display();
      wp_localize_script('hmwevents-course-bookings', 'cmsBookings', [
        'restUrl' => rest_url('hmwevents/v1'),
        'restNonce' => wp_create_nonce('wp_rest'),
        'bookingFields' => array_map(
          fn($key, $field) => [
            'key'     => $key,
            'label'   => $field['label'],
            'type'    => $field['type'],
            'source'  => $field['source'],
            'options' => $field['options'] ?? null,
          ],
          array_keys($edit_modal_fields),
          array_values($edit_modal_fields)
        ),
        'i18n' => [
          'selectCourse' => __('Please select a course', 'hmw-events'),
          'transferring' => __('Transferring...', 'hmw-events'),
          'transferButton' => __('Transfer Booking', 'hmw-events'),
          'error' => __('An error occurred', 'hmw-events'),
          'sending' => __('Sending...', 'hmw-events'),
          'resendConfirmation' => __('Resend Confirmation', 'hmw-events'),
          'resendReceipt' => __('Resend Receipt', 'hmw-events'),
          'confirmResendConfirmation' => __('Resend booking confirmation email?', 'hmw-events'),
          'confirmResendReceipt' => __('Resend payment receipt email?', 'hmw-events'),
          'addBookingSubmit' => __('Create Booking', 'hmw-events'),
          'editBooking' => __('Edit Booking', 'hmw-events'),
          'saving' => __('Saving...', 'hmw-events'),
          'loading' => __('Loading...', 'hmw-events'),
          'bookingCreated' => __('Booking created successfully.', 'hmw-events'),
          'bookingUpdated' => __('Booking updated successfully.', 'hmw-events'),
          'confirmSendPaymentLink' => __('Send a payment link to this customer for the remaining course amount?', 'hmw-events'),
          'paymentLinkSent' => __('Payment link sent successfully.', 'hmw-events'),
        ],
      ]);
    }
  }

  public function add_forms()
  {

    $screen = get_current_screen();

    if ($screen->id != 'toplevel_page_gf_edit_forms') {
      return;
    }
  }

  /**
   * Render admin menu page.
   *
   * @since 1.0.0
   */
  public function render_admin_menu_page()
  {
    // Check permissions
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }
  ?>
    <div class="wrap" id="hmwevents-page">
      <h1><?php esc_html_e('Handmade Web Event Manager', 'cms'); ?></h1>

      <div class="notice notice-info">
        <p><strong>Welcome to Handmade Web Event Manager!</strong> Use the submenu items to configure settings and manage reports.</p>
      </div>

      <div class="card" style="max-width: 800px;">
        <h2>Quick Links</h2>
        <ul>
        </ul>
      </div>

      <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2>System Status</h2>
        <table class="wp-list-table widefat fixed striped">
          <tr>
            <td><strong>Plugin Version:</strong></td>
            <td><?php echo defined('HMWEvents_VERSION') ? HMWEvents_VERSION : 'Unknown'; ?></td>
          </tr>
          <tr>
            <td><strong>WordPress Version:</strong></td>
            <td><?php echo get_bloginfo('version'); ?></td>
          </tr>
          <tr>
            <td><strong>PHP Version:</strong></td>
            <td><?php echo phpversion(); ?></td>
          </tr>
        </table>
      </div>
    </div>
<?php
  }
}
