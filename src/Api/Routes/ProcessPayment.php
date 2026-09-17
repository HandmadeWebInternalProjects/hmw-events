<?php

/**
 * Process Payment REST API Endpoint.
 *
 * Handles payment intent creation for Breakdance forms.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Api\Routes;

use HMWEvents\Services\PaymentGateway;
use HMWEvents\Services\VoucherService;
use HMWEvents\Services\CouponService;
use HMWEvents\Services\EventDataService;
use HMWEvents\Services\OrganizerPaymentSettings;
use HMWEvents\Factory\PaymentGatewayFactory;
use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Process Payment API Class.
 */
class ProcessPayment
{
  /**
   * Payment gateway instance.
   *
   * @var PaymentGateway
   */
  private $gateway;

  private ?EventDataService $event_data_service = null;

  /**
   * Initialize the API endpoint.
   *
   * @since 1.0.0
   */
  public function __construct()
  {
    // Use default payment gateway (Stripe by default)
    $this->gateway = new PaymentGateway();
    $this->gateway->register();

    $this->register_routes();
  }

  private function event_data_service(): EventDataService
  {
    if ($this->event_data_service === null) {
      $this->event_data_service = new EventDataService();
    }
    return $this->event_data_service;
  }

  /**
   * Build WP REST API arg definitions for all frontend booking fields from the registry.
   * Derives type, required flag, sanitize callback, and enum constraints from each
   * field's registry entry. Called from register_routes().
   *
   * @return array<string, array>
   */
  private function build_registry_args(): array
  {
    $args = [];

    foreach (\HMWEvents\Registry\RegistrationFieldRegistry::all() as $key => $field) {
      $required = !empty($field['required']);

      switch ($field['type']) {
        case 'file':
          continue 2;

        case 'email':
          $args[$key] = [
            'required'          => $required,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'validate_callback' => fn($v) => empty($v) || is_email($v),
          ];
          break;

        case 'checkbox':
          $args[$key] = [
            'required' => $required,
            'type'     => 'boolean',
          ];
          break;

        case 'textarea':
          $args[$key] = [
            'required'          => $required,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
          ];
          break;

        case 'select':
          $arg = [
            'required'          => $required,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ];
          $valid = array_values(array_filter(
            array_keys($field['options'] ?? []),
            fn($v) => $v !== ''
          ));
          if (!empty($valid)) {
            $arg['enum'] = $valid;
          }
          $args[$key] = $arg;
          break;

        default: // text, tel, date
          $args[$key] = [
            'required'          => $required,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ];
      }
    }

    return $args;
  }

  /**
   * Register REST API routes.
   *
   * @since 1.0.0
   */
  public function register_routes()
  {
    // Single-step payment processing
    register_rest_route('hmwevents/v1', '/payment/process', [
      'methods' => 'POST',
      'callback' => [$this, 'process_payment'],
      'permission_callback' => '__return_true',
      'args' => array_merge(
        $this->build_registry_args(),
        [
          'event_id' => [
            'required' => true,
            'type' => 'integer',
          ],
          'attendance_type' => [
            'required' => false,
            'type' => 'string',
            'default' => 'individual',
          ],
          'is_deposit' => [
            'required' => false,
            'type' => 'boolean',
          ],
          'payment_method_id' => [
            'required' => false,
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ],
          'gateway' => [
            'required' => false,
            'type' => 'string',
            'default' => 'stripe',
            'sanitize_callback' => 'sanitize_text_field',
            'description' => 'Payment gateway to use (stripe, paypal, etc.)',
          ],
          'voucher_code' => [
            'required' => false,
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ],
          'coupon_code' => [
            'required' => false,
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ],
          'recaptcha_token' => [
            'required' => false,
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ],
        ]
      ),
    ]);

    // Validate voucher code
    register_rest_route('hmwevents/v1', '/voucher/validate', [
      'methods' => 'POST',
      'callback' => [$this, 'validate_voucher'],
      'permission_callback' => '__return_true',
      'args' => [
        'voucher_code' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
        'event_id' => [
          'required' => true,
          'type' => 'integer',
        ],
        'registrant_email' => [
          'required' => false,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_email',
        ],
      ],
    ]);

    // Validate coupon code
    register_rest_route('hmwevents/v1', '/coupon/validate', [
      'methods' => 'POST',
      'callback' => [$this, 'validate_coupon'],
      'permission_callback' => '__return_true',
      'args' => [
        'coupon_code' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
        'event_id' => [
          'required' => true,
          'type' => 'integer',
        ],
        'organizer_id' => [
          'required' => false,
          'type' => 'integer',
        ],
        'registrant_email' => [
          'required' => false,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_email',
        ],
        'payment_type' => [
          'required' => false,
          'type' => 'string',
          'default' => 'full',
          'sanitize_callback' => 'sanitize_text_field',
        ],
        'amount' => [
          'required' => false,
          'type' => 'number',
          'default' => 0,
        ],
      ],
    ]);

    // Verify payment amount (security check before creating payment)
    register_rest_route('hmwevents/v1', '/payment/verify-amount', [
      'methods' => 'POST',
      'callback' => [$this, 'verify_amount'],
      'permission_callback' => '__return_true',
      'args' => [
        'event_id' => [
          'required' => true,
          'type' => 'integer',
        ],
        'is_deposit' => [
          'required' => false,
          'type' => 'boolean',
          'default' => false,
        ],
        'voucher_code' => [
          'required' => false,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
      ],
    ]);

    // Legacy: Create payment intent (deprecated - use /payment/process instead)
    register_rest_route('hmwevents/v1', '/payment/create', [
      'methods' => 'POST',
      'callback' => [$this, 'create_payment'],
      'permission_callback' => '__return_true', // Public endpoint
      'args' => [
        'customer_name' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
        'registrant_email' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_email',
          'validate_callback' => 'is_email',
        ],
        'customer_phone' => [
          'required' => false,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
        'event_id' => [
          'required' => true,
          'type' => 'integer',
        ],
        'organizer_id' => [
          'required' => true,
          'type' => 'integer',
        ],
        'is_deposit' => [
          'required' => false,
          'type' => 'boolean',
        ],
        'voucher_code' => [
          'required' => false,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
      ],
    ]);

    // Confirm payment
    register_rest_route('hmwevents/v1', '/payment/confirm', [
      'methods' => 'POST',
      'callback' => [$this, 'confirm_payment'],
      'permission_callback' => '__return_true',
      'args' => [
        'payment_intent_id' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
      ],
    ]);

    // Get payment status
    register_rest_route('hmwevents/v1', '/payment/status/(?P<payment_intent_id>[a-zA-Z0-9_]+)', [
      'methods' => 'GET',
      'callback' => [$this, 'get_payment_status'],
      'permission_callback' => '__return_true',
    ]);

    // Resume payment with recovery token
    register_rest_route('hmwevents/v1', '/payment/resume/(?P<token>[a-zA-Z0-9]+)', [
      'methods' => 'GET',
      'callback' => [$this, 'resume_payment'],
      'permission_callback' => '__return_true',
    ]);

    // Get gateway configuration (publishable keys, etc.)
    register_rest_route('hmwevents/v1', '/payment/gateway-config', [
      'methods' => 'GET',
      'callback' => [$this, 'get_gateway_config'],
      'permission_callback' => '__return_true',
      'args' => [
        'organizer_id' => [
          'required' => true,
          'type' => 'integer',
          'validate_callback' => function ($param) {
            return is_numeric($param) && $param > 0;
          },
        ],
        'gateway' => [
          'required' => false,
          'type' => 'string',
          'default' => null,
          'sanitize_callback' => 'sanitize_text_field',
        ],
      ],
    ]);
  }

  /**
   * Process payment in single step (create booking + confirm payment).
   *
   * @since 1.0.0
   * @param \WP_REST_Request $request Request object.
   * @return \WP_REST_Response|\WP_Error
   */
  public function process_payment(\WP_REST_Request $request)
  {
    if (
      function_exists('Breakdance\\APIKeys\\getKey') &&
      defined('BREAKDANCE_RECAPTCHA_SECRET_KEY_NAME') &&
      \Breakdance\APIKeys\getKey(BREAKDANCE_RECAPTCHA_SECRET_KEY_NAME)
    ) {
      $token = (string) $request->get_param('recaptcha_token');
      $ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

      if (!\Breakdance\Forms\Recaptcha\verify($token, $ip, 'hmwevents_booking_submit')) {
        return new \WP_Error(
          'recaptcha_failed',
          'reCAPTCHA verification failed. Please try again.',
          ['status' => 400]
        );
      }
    }

    $event_id = (int) $request->get_param('event_id');

    if (!$this->event_data_service()->is_event($event_id)) {
      return new \WP_Error(
        'invalid_event',
        __('Event not found.', 'hmw-events'),
        ['status' => 404]
      );
    }

    if (!$this->event_data_service()->bookings_enabled($event_id)) {
      return new \WP_Error(
        'bookings_disabled',
        __('Bookings are not available for this event.', 'hmw-events'),
        ['status' => 403]
      );
    }

    $organizer_id = $this->event_data_service()->get_organizer_id($event_id);
    if (!$organizer_id) {
      $post = get_post($event_id);
      $organizer_id = $post ? (int) $post->post_author : null;
    }

    $registrant_email  = sanitize_email((string) $request->get_param('email'));
    $customer_name     = $this->build_customer_name($request);
    $customer_phone    = sanitize_text_field((string) $request->get_param('phone'));
    $is_deposit        = (bool) $request->get_param('is_deposit');
    $payment_method_id = sanitize_text_field((string) $request->get_param('payment_method_id'));
    $attendance_type   = sanitize_text_field((string) $request->get_param('attendance_type') ?: 'individual');

    $booking_data = [
      'customer_name'     => $customer_name,
      'registrant_email'  => $registrant_email,
      'customer_phone'    => $customer_phone,
      'event_id'         => $event_id,
      'organizer_id'       => $organizer_id,
      'is_deposit'        => $is_deposit,
      'payment_method_id' => $payment_method_id,
      'attendance_type'   => $attendance_type,
    ];

    $booking_details = $this->extract_booking_details_from_request($request);
    if (!empty($booking_details)) {
      $booking_data['booking_details'] = $booking_details;
    }

    $voucher_code = sanitize_text_field((string) $request->get_param('voucher_code'));
    if ($voucher_code !== '') {
      $voucher_service = new VoucherService();
      $voucher_data    = $voucher_service->validate_voucher($voucher_code, $event_id, $registrant_email);
      if (!is_wp_error($voucher_data)) {
        $booking_data['voucher_code']    = $voucher_code;
        $booking_data['voucher_discount'] = $voucher_data;
      }
    }

    $coupon_code = sanitize_text_field((string) $request->get_param('coupon_code'));
    if ($voucher_code === '' && $coupon_code !== '') {
      $coupon_service = new CouponService();
      $amount_for_coupon = $this->calculate_base_amount($event_id, $is_deposit);
      $coupon_data = $coupon_service->validate_coupon(
        $coupon_code,
        $event_id,
        $organizer_id,
        $registrant_email,
        $is_deposit ? 'deposit' : 'full',
        $amount_for_coupon
      );
      if (!is_wp_error($coupon_data)) {
        $booking_data['coupon_code']    = $coupon_code;
        $booking_data['coupon_discount'] = $coupon_data;
      }
    }

    $gateway = new PaymentGateway(null, $organizer_id);
    $gateway->register();

    $result = $gateway->process_payment_with_confirmation($booking_data);

    if (is_wp_error($result)) {
      return $result;
    }

    return rest_ensure_response($result);
  }

  /**
   * Build a full customer name from first_name / last_name fields,
   * falling back to customer_name, then registrant_email.
   *
   * @param \WP_REST_Request $request
   * @return string
   */
  private function build_customer_name(\WP_REST_Request $request): string
  {
    $first = sanitize_text_field((string) $request->get_param('first_name'));
    $last  = sanitize_text_field((string) $request->get_param('last_name'));
    $full  = trim($first . ' ' . $last);

    if ($full !== '') {
      return $full;
    }

    $customer_name = sanitize_text_field((string) $request->get_param('customer_name'));
    if ($customer_name !== '') {
      return $customer_name;
    }

    return sanitize_email((string) $request->get_param('email'));
  }

  /**
   * Extract booking_details from request params using the
   * RegistrationFieldRegistry to identify booking_details-source fields.
   *
   * @param \WP_REST_Request $request
   * @return array<string, string>
   */
  private function extract_booking_details_from_request(\WP_REST_Request $request): array
  {
    $details = [];
    $params  = $request->get_params();

    foreach (RegistrationFieldRegistry::booking_details_fields() as $key => $field) {
      $value = $params[$key] ?? null;
      if ($value === null || $value === '') {
        continue;
      }
      $type = $field['type'] ?? 'text';
      if ($type === 'checkbox') {
        $details[$key] = $value ? 'true' : 'false';
      } elseif ($type === 'textarea') {
        $details[$key] = sanitize_textarea_field((string) $value);
      } else {
        $details[$key] = sanitize_text_field((string) $value);
      }
    }

    return $details;
  }

  /**
   * Calculate the server-side base amount for coupon validation purposes.
   * Mirrors AbstractPaymentGateway::calculate_amount() logic without the
   * gateway dependency.
   *
   * @param int  $event_id
   * @param bool $is_deposit
   * @return float
   */
  private function calculate_base_amount(int $event_id, bool $is_deposit): float
  {
    $eds          = $this->event_data_service();
    $course_cost  = $eds->get_price($event_id) ?? 0.0;
    $deposit_cost = $eds->get_deposit($event_id) ?? 0.0;

    $effective_deposit = $is_deposit && $deposit_cost > 0;
    $base = $effective_deposit ? $deposit_cost : $course_cost;

    return $base + $eds->calculate_surcharge($event_id, $base);
  }

  /**
   * Create payment intent.
   *
   * @deprecated Use process_payment() instead
   * @since 1.0.0
   * @param \WP_REST_Request $request Request object.
   * @return \WP_REST_Response|\WP_Error
   */
  public function create_payment(\WP_REST_Request $request)
  {
    $event_id = (int) $request->get_param('event_id');

    if (!$this->event_data_service()->is_event($event_id)) {
      return new \WP_Error(
        'invalid_event',
        __('Event not found.', 'hmw-events'),
        ['status' => 404]
      );
    }

    if (!$this->event_data_service()->bookings_enabled($event_id)) {
      return new \WP_Error(
        'bookings_disabled',
        __('Bookings are not available for this event.', 'hmw-events'),
        ['status' => 403]
      );
    }

    $booking_data = [
      'customer_name' => $request->get_param('customer_name'),
      'registrant_email' => $request->get_param('registrant_email'),
      'customer_phone' => $request->get_param('customer_phone'),
      'event_id' => $request->get_param('event_id'),
      'organizer_id' => $request->get_param('organizer_id'),
      'is_deposit' => $request->get_param('is_deposit'),
    ];

    $result = $this->gateway->process_booking($booking_data);

    if (is_wp_error($result)) {
      return new \WP_Error(
        $result->get_error_code(),
        $result->get_error_message(),
        ['status' => 400]
      );
    }

    $invite_token = $_GET['token'] ?? $request->get_param('token') ?? '';
    $event_id     = (int) $request->get_param('event_id');
    if ($invite_token && $event_id) {
      $token_service = new \HMWEvents\Services\InvitationTokenService();
      $token_service->consume($invite_token, $event_id);
    }

    return new \WP_REST_Response([
      'success' => true,
      'data' => $result,
    ], 200);
  }

  /**
   * Confirm payment.
   *
   * @since 1.0.0
   * @param \WP_REST_Request $request Request object.
   * @return \WP_REST_Response|\WP_Error
   */
  public function confirm_payment(\WP_REST_Request $request)
  {
    $payment_intent_id = $request->get_param('payment_intent_id');

    $result = $this->gateway->confirm_payment($payment_intent_id);

    if (is_wp_error($result)) {
      return new \WP_Error(
        $result->get_error_code(),
        $result->get_error_message(),
        ['status' => 400]
      );
    }

    return new \WP_REST_Response([
      'success' => true,
      'message' => 'Payment confirmed successfully',
    ], 200);
  }

  /**
   * Get payment status.
   *
   * @since 1.0.0
   * @param \WP_REST_Request $request Request object.
   * @return \WP_REST_Response|\WP_Error
   */
  public function get_payment_status(\WP_REST_Request $request)
  {
    global $wpdb;

    $payment_intent_id = $request->get_param('payment_intent_id');

    $transaction = $wpdb->get_row($wpdb->prepare("
            SELECT pt.*, b.booking_number, b.status as booking_status
            FROM " . \HMWEvents\Services\DatabaseService::get_table_name('payment_transactions') . " pt
            LEFT JOIN " . \HMWEvents\Services\DatabaseService::get_table_name('booking_groups') . " bg ON pt.booking_group_id = bg.id
            LEFT JOIN " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " b ON bg.id = b.booking_group_id
            WHERE pt.gateway_transaction_id = %s
        ", $payment_intent_id));

    if (!$transaction) {
      return new \WP_Error('not_found', 'Payment not found', ['status' => 404]);
    }

    return new \WP_REST_Response([
      'success' => true,
      'data' => [
        'payment_status' => $transaction->status,
        'booking_status' => $transaction->booking_status,
        'booking_number' => $transaction->booking_number,
        'amount' => $transaction->amount,
      ],
    ], 200);
  }

  /**
   * Resume payment with recovery token.
   *
   * @since 1.0.0
   * @param \WP_REST_Request $request Request object.
   * @return \WP_REST_Response|\WP_Error
   */
  public function resume_payment(\WP_REST_Request $request)
  {
    global $wpdb;

    $token = $request->get_param('token');

    // Find booking group by token
    // Include: pending/failed payments OR paid deposits that haven't been upgraded to full
    $booking_groups = $wpdb->get_results(
      "SELECT * FROM " . \HMWEvents\Services\DatabaseService::get_table_name('booking_groups') . " 
            WHERE payment_status IN ('pending', 'failed') 
            OR (payment_status = 'paid' AND payment_type = 'deposit')"
    );

    $booking_group = null;
    foreach ($booking_groups as $group) {
      $metadata = json_decode($group->metadata ?: '{}', true);
      if (isset($metadata['recovery_token']) && $metadata['recovery_token'] === $token) {
        $booking_group = $group;
        $booking_group->metadata_array = $metadata;
        break;
      }
    }

    if (!$booking_group) {
      return new \WP_Error('invalid_token', 'Invalid or expired recovery token', ['status' => 404]);
    }

    // Check if token has expired (24 hours)
    if (isset($booking_group->metadata_array['recovery_token_expires'])) {
      $expires = $booking_group->metadata_array['recovery_token_expires'];
      if (strtotime($expires) < current_time('timestamp', true)) {
        return new \WP_Error('token_expired', 'Recovery link has expired. Please contact support for assistance.', ['status' => 410]);
      }
    }

    // Get payment transaction
    $transaction = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM " . \HMWEvents\Services\DatabaseService::get_table_name('payment_transactions') . "
            WHERE booking_group_id = %d
            ORDER BY created_at DESC
            LIMIT 1
        ", $booking_group->id));

    if (!$transaction) {
      return new \WP_Error('transaction_not_found', 'Payment transaction not found', ['status' => 404]);
    }

    // Get booking details
    $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, c.post_title as course_name
            FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " b
            LEFT JOIN {$wpdb->prefix}posts c ON b.event_post_id = c.ID
            WHERE b.booking_group_id = %d
            LIMIT 1
        ", $booking_group->id));

    // Get customer details
    $registrant_post_id = $booking_group->registrant_post_id ?? $booking_group->customer_post_id ?? 0;
    $registrant_email = get_post_meta($registrant_post_id, 'registrant_email', true);
    $customer_name = get_the_title($registrant_post_id);

    // Check if we're in test mode
    $is_test_mode = \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_stripe_mode', 'test') === 'test';

    // var_dump('Is test mode:', $is_test_mode);
    // Get Stripe keys based on mode
    if ($is_test_mode) {
      $stripe_publishable_key = \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_stripe_test_publishable_key', '');
      $stripe_secret_key_encrypted = \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_stripe_test_secret_key', '');
    } else {
      $course = get_post($booking->event_post_id);
      $organizer_id = $course ? (int) $course->post_author : 0;

      if ($organizer_id) {
        $org_settings = new OrganizerPaymentSettings($organizer_id);

        if ($org_settings->get_payment_type() === 'stripe') {
          $stripe_publishable_key = $org_settings->get_stripe_publishable_key();
          $stripe_secret_key_encrypted = $org_settings->get_stripe_secret_key();
        }
      }

      if (empty($stripe_publishable_key) || empty($stripe_secret_key_encrypted)) {
        $stripe_publishable_key = \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_stripe_live_publishable_key', '');
        $stripe_secret_key_encrypted = \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_stripe_live_secret_key', '');
      }
    }

    if (!$stripe_publishable_key) {
      $mode_text = $is_test_mode ? 'test' : 'live';
      return new \WP_Error(
        'stripe_not_configured',
        "Payment system not configured for {$mode_text} mode. Please contact support.",
        ['status' => 500]
      );
    }

    // Decrypt the secret key if it's encrypted
    $stripe_secret_key = '';
    if (!empty($stripe_secret_key_encrypted)) {
      // Check if already plain text (starts with sk_)
      if (strpos($stripe_secret_key_encrypted, 'sk_') === 0) {
        $stripe_secret_key = $stripe_secret_key_encrypted;
      } else {
        // Decrypt
        $decrypted = \HMWEvents\Helpers\Encryption::decrypt($stripe_secret_key_encrypted);
        $stripe_secret_key = $decrypted !== false ? $decrypted : '';
      }
    }

    // Calculate amount in dollars (StripeService handles conversion to cents).
    // If this is a paid deposit and full payment hasn't been made, show remaining balance.
    $amount = floatval($booking_group->total_amount);
    $is_remaining_payment = ($booking_group->payment_status === 'paid' && $booking_group->payment_type === 'deposit');

    if ($is_remaining_payment) {
      // Check if remaining payment has already been completed
      $remaining_payment = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM " . \HMWEvents\Services\DatabaseService::get_table_name('payment_transactions') . "
                WHERE booking_group_id = %d
                AND status = 'succeeded'
                AND metadata LIKE %s
                ORDER BY created_at DESC
                LIMIT 1
            ", $booking_group->id, '%remaining%'));

      if ($remaining_payment) {
        return new \WP_Error(
          'payment_completed',
          'This payment has already been completed. Thank you for your booking!',
          ['status' => 410]
        );
      }

      // Get course pricing from ACF fields (stored in dollars)
      $course_full_price = $this->event_data_service()->get_price($booking->event_post_id) ?? 0.0;
      $course_full_price += $this->event_data_service()->calculate_surcharge((int) $booking->event_post_id, $course_full_price);
      $deposit_paid = floatval($booking_group->total_amount);

      if ($course_full_price) {
        // Calculate remaining balance in dollars.
        $amount = max(0, floatval($course_full_price) - $deposit_paid);
      }
    }

    // Get payment intent from Stripe to retrieve client_secret
    $stripe_service = new \HMWEvents\Services\StripeService();

    // Initialize Stripe with educator's secret key if available, otherwise use system default
    if (!empty($stripe_secret_key)) {
      $stripe_service->init_stripe_with_key($stripe_secret_key, $stripe_publishable_key);
    } else {
      $stripe_service->init_stripe();
    }

    // For remaining balance payments, create a new payment intent
    // For pending/failed payments, retrieve the existing payment intent
    if ($is_remaining_payment) {
      // Create new payment intent for remaining balance
      $payment_intent = $stripe_service->create_payment_intent($amount, 'AUD', [
        'registrant_email' => $registrant_email,
        'customer_name' => $customer_name,
        'booking_reference' => $booking_group->booking_reference,
        'booking_number' => $booking->booking_number,
        'course_name' => $booking->course_name,
        'payment_type' => 'remaining',
      ]);

      if (is_wp_error($payment_intent)) {
        return new \WP_Error(
          'payment_intent_error',
          'Unable to create payment intent for remaining balance. Please contact support.',
          ['status' => 500]
        );
      }

      $payment_intent_id = $payment_intent->id;
      $client_secret = $payment_intent->client_secret;

      // Get Stripe customer ID from customer post meta
      $stripe_customer_id = get_post_meta($booking_group->customer_post_id, 'stripe_customer_id', true);

      // Create a new transaction record for the remaining payment.
      // Amount is stored in dollars.
      global $wpdb;
      $wpdb->insert(
        \HMWEvents\Services\DatabaseService::get_table_name('payment_transactions'),
        [
          'booking_group_id' => $booking_group->id,
          'gateway' => 'stripe',
          'gateway_transaction_id' => $payment_intent_id,
          'gateway_customer_id' => $stripe_customer_id ?: null,
          'amount' => $amount,
          'currency' => 'AUD',
          'status' => 'pending',
          'metadata' => json_encode(['payment_type' => 'remaining']),
          'created_at' => current_time('mysql'),
        ],
        ['%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s']
      );
    } else {
      // For manual bookings, gateway_transaction_id is a placeholder like 'manual_CB-...'
      // — create a fresh Stripe payment intent so the customer can pay.
      if (strpos($transaction->gateway_transaction_id, 'manual_') === 0) {
        $payment_intent = $stripe_service->create_payment_intent($amount, $booking_group->currency ?: 'AUD', [
          'registrant_email'     => $registrant_email,
          'customer_name'      => $customer_name,
          'booking_reference'  => $booking_group->booking_reference,
          'booking_number'     => $booking->booking_number,
          'course_name'        => $booking->course_name,
          'payment_type'       => 'full',
          'booking_source'     => 'manual',
        ]);

        if (is_wp_error($payment_intent)) {
          return new \WP_Error(
            'payment_intent_error',
            'Unable to create payment details. Please contact support.',
            ['status' => 500]
          );
        }

        $payment_intent_id = $payment_intent->id;
        $client_secret     = $payment_intent->client_secret;

        // Update the transaction row so future resume attempts use the real Stripe ID
        $wpdb->update(
          \HMWEvents\Services\DatabaseService::get_table_name('payment_transactions'),
          ['gateway_transaction_id' => $payment_intent_id],
          ['id' => $transaction->id],
          ['%s'],
          ['%d']
        );
      } else {
        // Retrieve existing payment intent
        $payment_intent = $stripe_service->get_payment_intent($transaction->gateway_transaction_id);

        if (is_wp_error($payment_intent)) {
          return new \WP_Error(
            'payment_intent_error',
            'Unable to retrieve payment details. Please contact support.',
            ['status' => 500]
          );
        }

        $payment_intent_id = $transaction->gateway_transaction_id;
        $client_secret     = $payment_intent->client_secret;
      }
    }

    return new \WP_REST_Response([
      'success' => true,
      'data' => [
        'booking_reference' => $booking_group->booking_reference,
        'booking_number' => $booking->booking_number,
        'course_name' => $booking->course_name,
        'customer_name' => $customer_name,
        'registrant_email' => $registrant_email,
        'amount' => $amount,
        'payment_type' => $is_remaining_payment ? 'remaining' : $booking_group->payment_type,
        'payment_intent_id' => $payment_intent_id,
        'client_secret' => $client_secret,
        'expires_at' => $booking_group->metadata_array['recovery_token_expires'] ?? null,
        'stripe_publishable_key' => $stripe_publishable_key,
      ],
    ], 200);
  }

  /**
   * Validate voucher code.
   *
   * @since 1.0.0
   * @param \WP_REST_Request $request Request object.
   * @return \WP_REST_Response|\WP_Error
   */
  public function validate_voucher(\WP_REST_Request $request)
  {
    $voucher_service = new \HMWEvents\Services\VoucherService();

    $voucher_code = $request->get_param('voucher_code');
    $event_id = $request->get_param('event_id');
    $registrant_email = $request->get_param('registrant_email');

    // Validate voucher
    $voucher_data = $voucher_service->validate_voucher($voucher_code, $event_id, $registrant_email);

    if (is_wp_error($voucher_data)) {
      return new \WP_Error(
        $voucher_data->get_error_code(),
        $voucher_data->get_error_message(),
        ['status' => 400]
      );
    }

    $course_cost  = $this->event_data_service()->get_price($event_id) ?? 0.0;
    $deposit_cost = $this->event_data_service()->get_deposit($event_id) ?? 0.0;

    $full_discount = $voucher_service->calculate_discount(floatval($course_cost), $voucher_data);
    $deposit_discount = null;

    if (!empty($deposit_cost)) {
      $deposit_discount = $voucher_service->calculate_discount(floatval($deposit_cost), $voucher_data);
    }

    return new \WP_REST_Response([
      'success' => true,
      'voucher' => [
        'code' => $voucher_data['code'],
        'voucher_type' => $voucher_data['voucher_type'],
        'remaining_value' => $voucher_data['remaining_value'],
        'voucher_id' => $voucher_data['voucher_id'],
      ],
      'pricing' => [
        'full' => $full_discount,
        'deposit' => $deposit_discount,
      ],
    ], 200);
  }

  /**
   * Validate coupon code.
   * 
   * @since 1.0.0
   * @param \WP_REST_Request $request Request object.
   * @return \WP_REST_Response|\WP_Error
   */
  public function validate_coupon(\WP_REST_Request $request)
  {
    $coupon_service = new \HMWEvents\Services\CouponService();

    $coupon_code = $request->get_param('coupon_code');
    $event_id = $request->get_param('event_id');
    $organizer_id = $request->get_param('organizer_id');
    $registrant_email = $request->get_param('registrant_email');
    $payment_type = $request->get_param('payment_type') === 'deposit' ? 'deposit' : 'full';

    if (!empty($organizer_id)) {
        $organizer_id = (int) $organizer_id;
    } else {
        $organizer_id = $this->event_data_service()->get_organizer_id((int) $event_id);
        if (!$organizer_id) {
            $post = get_post((int) $event_id);
            $organizer_id = $post ? (int) $post->post_author : null;
        }
    }

    // Get course pricing
    $course_cost  = $this->event_data_service()->get_price($event_id) ?? 0.0;
    $deposit_cost = $this->event_data_service()->get_deposit($event_id) ?? 0.0;

    $amount    = $payment_type === 'deposit' && $deposit_cost > 0 ? $deposit_cost : $course_cost;
    $amount += $this->event_data_service()->calculate_surcharge((int) $event_id, (float) $amount);

    // Validate coupon
    $coupon_data = $coupon_service->validate_coupon(
      $coupon_code,
      $event_id,
      $organizer_id,
      $registrant_email,
      $payment_type,
      $amount
    );

    if (is_wp_error($coupon_data)) {
      return new \WP_Error(
        $coupon_data->get_error_code(),
        $coupon_data->get_error_message(),
        ['status' => 400]
      );
    }

    // Calculate discount for both full and deposit amounts
    $full_discount = $coupon_service->calculate_discount($course_cost, $coupon_data);
    $deposit_discount = null;

    if ($deposit_cost > 0) {
      $deposit_discount = $coupon_service->calculate_discount($deposit_cost, $coupon_data);
    }

    return new \WP_REST_Response([
      'success' => true,
      'coupon' => [
        'code' => $coupon_data['code'],
        'discount_type' => $coupon_data['discount_type'],
        'discount_value' => $coupon_data['discount_value'],
        'description' => $coupon_data['description'],
      ],
      'pricing' => [
        'full' => $full_discount,
        'deposit' => $deposit_discount,
      ],
    ], 200);
  }

  /**
   * Verify payment amount before creating payment intent.
   * 
   * SECURITY: Always calculate amounts server-side from ACF fields.
   * Never trust client-side amount values.
   *
   * @since 1.0.0
   * @param \WP_REST_Request $request Request object.
   * @return \WP_REST_Response|\WP_Error
   */
  public function verify_amount(\WP_REST_Request $request)
  {
    $event_id = $request->get_param('event_id');
    $is_deposit = $request->get_param('is_deposit');

    if (!$event_id) {
      return new \WP_Error('missing_event_id', 'Event ID is required', ['status' => 400]);
    }

    // Verify event exists
    $event = get_post($event_id);
    if (!$event || !in_array($event->post_type, ['hmw_event'], true)) {
      return new \WP_Error('invalid_event', 'Event not found', ['status' => 404]);
    }

    // Get event pricing from ACF fields (server-side source of truth)
    $course_cost  = $this->event_data_service()->get_price($event_id) ?? 0.0;
    $deposit_cost = $this->event_data_service()->get_deposit($event_id) ?? 0.0;
    $currency     = \HMWEvents\Meta\CourseMeta::get_course_currency($event_id);

    if (empty($course_cost)) {
      return new \WP_Error('invalid_event', 'Event has no price set', ['status' => 400]);
    }

    // Calculate amount based on payment type
    $is_deposit = $is_deposit && !empty($deposit_cost);
    $amount    = $is_deposit ? floatval($deposit_cost) : floatval($course_cost);
    $amount += $this->event_data_service()->calculate_surcharge((int) $event_id, $amount);

    return new \WP_REST_Response([
      'success' => true,
      'data' => [
        'amount' => $amount,
        'currency' => $currency,
        'currency_symbol' => \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency),
        'event_id' => $event_id,
        'is_deposit' => $is_deposit,
        'payment_type' => $is_deposit ? 'deposit' : 'full',
      ],
    ], 200);
  }

  /**
   * Get gateway configuration for frontend.
   *
   * @since 1.0.0
   * @param \WP_REST_Request $request Request object.
   * @return \WP_REST_Response|\WP_Error
   */
  public function get_gateway_config(\WP_REST_Request $request)
  {
    $organizer_id = $request->get_param('organizer_id');
    $gateway_id = $request->get_param('gateway');

    // Create gateway instance for this organizer
    $gateway = PaymentGatewayFactory::create($gateway_id, $organizer_id);

    if (is_wp_error($gateway)) {
      return new \WP_Error(
        'gateway_error',
        $gateway->get_error_message(),
        ['status' => 400]
      );
    }

    $config = [
      'gateway_id' => $gateway->get_gateway_id(),
      'gateway_name' => $gateway->get_gateway_name(),
      'publishable_key' => $gateway->get_publishable_key(),
    ];

    // Add key info for debugging (admin only)
    if (current_user_can('manage_options')) {
      $config['key_info'] = $gateway->get_key_info();
    }

    return new \WP_REST_Response([
      'success' => true,
      'config' => $config,
    ], 200);
  }
}
