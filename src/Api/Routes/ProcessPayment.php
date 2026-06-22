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
use HMWEvents\Factory\PaymentGatewayFactory;

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

    foreach (\HMWEvents\Config\BookingFields::for_frontend() as $key => $field) {
      $required = !empty($field['frontend_required']);

      switch ($field['type']) {
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
    register_rest_route('cms/v1', '/payment/process', [
      'methods' => 'POST',
      'callback' => [$this, 'process_payment'],
      'permission_callback' => '__return_true',
      'args' => array_merge(
        $this->build_registry_args(),
        [
          'course_id' => [
            'required' => true,
            'type' => 'integer',
          ],
          'educator_id' => [
            'required' => true,
            'type' => 'integer',
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
    register_rest_route('cms/v1', '/voucher/validate', [
      'methods' => 'POST',
      'callback' => [$this, 'validate_voucher'],
      'permission_callback' => '__return_true',
      'args' => [
        'voucher_code' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
        'course_id' => [
          'required' => true,
          'type' => 'integer',
        ],
        'customer_email' => [
          'required' => false,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_email',
        ],
      ],
    ]);

    // Validate coupon code
    register_rest_route('cms/v1', '/coupon/validate', [
      'methods' => 'POST',
      'callback' => [$this, 'validate_coupon'],
      'permission_callback' => '__return_true',
      'args' => [
        'coupon_code' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
        'course_id' => [
          'required' => true,
          'type' => 'integer',
        ],
        'educator_id' => [
          'required' => true,
          'type' => 'integer',
        ],
        'customer_email' => [
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
    register_rest_route('cms/v1', '/payment/verify-amount', [
      'methods' => 'POST',
      'callback' => [$this, 'verify_amount'],
      'permission_callback' => '__return_true',
      'args' => [
        'course_id' => [
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
    register_rest_route('cms/v1', '/payment/create', [
      'methods' => 'POST',
      'callback' => [$this, 'create_payment'],
      'permission_callback' => '__return_true', // Public endpoint
      'args' => [
        'customer_name' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
        ],
        'customer_email' => [
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
        'course_id' => [
          'required' => true,
          'type' => 'integer',
        ],
        'educator_id' => [
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
    register_rest_route('cms/v1', '/payment/confirm', [
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
    register_rest_route('cms/v1', '/payment/status/(?P<payment_intent_id>[a-zA-Z0-9_]+)', [
      'methods' => 'GET',
      'callback' => [$this, 'get_payment_status'],
      'permission_callback' => '__return_true',
    ]);

    // Resume payment with recovery token
    register_rest_route('cms/v1', '/payment/resume/(?P<token>[a-zA-Z0-9]+)', [
      'methods' => 'GET',
      'callback' => [$this, 'resume_payment'],
      'permission_callback' => '__return_true',
    ]);

    // Get gateway configuration (publishable keys, etc.)
    register_rest_route('cms/v1', '/payment/gateway-config', [
      'methods' => 'GET',
      'callback' => [$this, 'get_gateway_config'],
      'permission_callback' => '__return_true',
      'args' => [
        'educator_id' => [
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
    // Verify reCAPTCHA token if Breakdance keys are configured
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

    // Get gateway ID from request (defaults to Stripe)
    $gateway_id = $request->get_param('gateway') ?: null;

    // Get educator ID from request
    $educator_id = $request->get_param('educator_id');

    // Create gateway instance using factory with educator context
    $gateway = PaymentGatewayFactory::create($gateway_id, $educator_id);

    if (is_wp_error($gateway)) {
      return new \WP_Error(
        'gateway_error',
        $gateway->get_error_message(),
        ['status' => 400]
      );
    }

    // Extract booking details from registry fields — driven by BookingFields::for_frontend()
    // so adding a field to the registry automatically includes it here with no other changes.
    $booking_details = [];
    foreach (\HMWEvents\Config\BookingFields::for_frontend() as $key => $field) {
      $value = $request->get_param($key);
      if ($value !== null && $value !== '') {
        $booking_details[$key] = $value;
      }
    }

    $voucher_code = $request->get_param('voucher_code');
    $voucher_discount = null;
    $coupon_code = $request->get_param('coupon_code');
    $coupon_discount = null;
    $is_deposit = (bool) $request->get_param('is_deposit');

    // Calculate server-side base amount for selected payment type.
    $course_id = (int) $request->get_param('course_id');
    $course_cost = floatval(get_field('course_full_cost', $course_id));
    $deposit_cost = floatval(get_field('course_deposit_cost', $course_id));
    $base_amount = $is_deposit && $deposit_cost > 0 ? $deposit_cost : $course_cost;

    // Validate and apply voucher if provided
    if (!empty($voucher_code)) {
      $voucher_service = new \HMWEvents\Services\VoucherService();
      $voucher_data = $voucher_service->validate_voucher(
        $voucher_code,
        $request->get_param('course_id'),
        $request->get_param('email')
      );

      if (is_wp_error($voucher_data)) {
        return $voucher_data; // Return validation error
      }

      $voucher_discount = $voucher_data;
    }

    // Validate and apply coupon if provided (and no voucher)
    if (!empty($coupon_code) && empty($voucher_code)) {
      $coupon_service = new \HMWEvents\Services\CouponService();
      $payment_type = $is_deposit ? 'deposit' : 'full';

      $coupon_data = $coupon_service->validate_coupon(
        $coupon_code,
        $request->get_param('course_id'),
        $educator_id,
        $request->get_param('email'),
        $payment_type,
        $base_amount
      );

      if (is_wp_error($coupon_data)) {
        return $coupon_data; // Return validation error
      }

      $coupon_discount = $coupon_data;
    }

    // Determine whether payment details are required for this booking.
    $final_amount = $base_amount;
    if (!empty($voucher_discount)) {
      $voucher_service = new \HMWEvents\Services\VoucherService();
      $discount_calc = $voucher_service->calculate_discount($final_amount, $voucher_discount);
      $final_amount = floatval($discount_calc['final_amount'] ?? $final_amount);
    } elseif (!empty($coupon_discount)) {
      $coupon_service = new \HMWEvents\Services\CouponService();
      $discount_calc = $coupon_service->calculate_discount($final_amount, $coupon_discount);
      $final_amount = floatval($discount_calc['final_amount'] ?? $final_amount);
    }

    $requires_payment = $final_amount > 0.00001;
    $payment_method_id = $request->get_param('payment_method_id');
    if ($requires_payment && empty($payment_method_id)) {
      return new \WP_Error(
        'missing_payment_method',
        'Payment details are required for this booking.',
        ['status' => 400]
      );
    }

    // Construct full customer name
    $customer_name = trim($request->get_param('mothers_first_name') . ' ' . $request->get_param('mothers_last_name'));

    $booking_data = [
      'customer_name' => $customer_name,
      'customer_email' => $request->get_param('email'),
      'customer_phone' => $request->get_param('phone'),
      'course_id' => $request->get_param('course_id'),
      'educator_id' => $educator_id,
      'is_deposit' => $is_deposit,
      'payment_method_id' => $payment_method_id,
      'booking_details' => $booking_details,
      'voucher_code' => $voucher_code,
      'voucher_discount' => $voucher_discount,
      'coupon_code' => $coupon_code,
      'coupon_discount' => $coupon_discount,
      'skip_payment_gateway' => !$requires_payment,
    ];

    $result = $gateway->process_payment_with_confirmation($booking_data);

    if (is_wp_error($result)) {
      return new \WP_Error(
        $result->get_error_code(),
        $result->get_error_message(),
        ['status' => 400]
      );
    }

    return new \WP_REST_Response([
      'success' => true,
      'data' => $result,
    ], 200);
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
    $booking_data = [
      'customer_name' => $request->get_param('customer_name'),
      'customer_email' => $request->get_param('customer_email'),
      'customer_phone' => $request->get_param('customer_phone'),
      'course_id' => $request->get_param('course_id'),
      'educator_id' => $request->get_param('educator_id'),
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
            FROM {$wpdb->prefix}educator_payment_transactions pt
            LEFT JOIN {$wpdb->prefix}educator_booking_groups bg ON pt.booking_group_id = bg.id
            LEFT JOIN {$wpdb->prefix}educator_bookings b ON bg.id = b.booking_group_id
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
      "SELECT * FROM {$wpdb->prefix}educator_booking_groups 
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
            SELECT * FROM {$wpdb->prefix}educator_payment_transactions
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
            FROM {$wpdb->prefix}educator_bookings b
            LEFT JOIN {$wpdb->prefix}posts c ON b.course_post_id = c.ID
            WHERE b.booking_group_id = %d
            LIMIT 1
        ", $booking_group->id));

    // Get customer details
    $customer_email = get_post_meta($booking_group->customer_post_id, 'customer_email', true);
    $customer_name = get_the_title($booking_group->customer_post_id);

    // Check if we're in test mode
    $is_test_mode = \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_stripe_mode', 'test') === 'test';

    // var_dump('Is test mode:', $is_test_mode);
    // Get Stripe keys based on mode
    if ($is_test_mode) {
      // In test mode, always use plugin's test keys
      $stripe_publishable_key = \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_stripe_test_publishable_key', '');
      $stripe_secret_key_encrypted = \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_stripe_test_secret_key', '');
    } else {
      // In live mode, use educator's keys if they have Stripe configured,
      // otherwise fall back to plugin live keys (same logic as StripePaymentGateway::get_api_keys).
      $course = get_post($booking->course_post_id);
      $educator_id = $course ? (int) $course->post_author : 0;
      $educator_payment_type = get_user_meta($educator_id, 'educator_payment_type', true);

      if ($educator_payment_type === 'stripe') {
        $stripe_publishable_key = get_user_meta($educator_id, 'educator_stripe_key', true);
        $stripe_secret_key_encrypted = get_user_meta($educator_id, 'educator_stripe_secret', true);
      }

      // Fall back to plugin live keys if educator hasn't configured their own Stripe account
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
                SELECT * FROM {$wpdb->prefix}educator_payment_transactions
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
      $course_full_price = get_field('course_full_cost', $booking->course_post_id);
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
        'customer_email' => $customer_email,
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
        $wpdb->prefix . 'educator_payment_transactions',
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
          'customer_email'     => $customer_email,
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
          $wpdb->prefix . 'educator_payment_transactions',
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
        'customer_email' => $customer_email,
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
    $course_id = $request->get_param('course_id');
    $customer_email = $request->get_param('customer_email');

    // Validate voucher
    $voucher_data = $voucher_service->validate_voucher($voucher_code, $course_id, $customer_email);

    if (is_wp_error($voucher_data)) {
      return new \WP_Error(
        $voucher_data->get_error_code(),
        $voucher_data->get_error_message(),
        ['status' => 400]
      );
    }

    // Get course pricing
    $course_cost = get_field('course_full_cost', $course_id);
    $deposit_cost = get_field('course_deposit_cost', $course_id);

    // Calculate discount for both full and deposit amounts
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
    $course_id = $request->get_param('course_id');
    $educator_id = $request->get_param('educator_id');
    $customer_email = $request->get_param('customer_email');
    $payment_type = $request->get_param('payment_type') === 'deposit' ? 'deposit' : 'full';

    // Get course pricing
    $course_cost = floatval(get_field('course_full_cost', $course_id));
    $deposit_cost = floatval(get_field('course_deposit_cost', $course_id));

    $amount = $payment_type === 'deposit' && $deposit_cost > 0 ? $deposit_cost : $course_cost;

    // Validate coupon
    $coupon_data = $coupon_service->validate_coupon(
      $coupon_code,
      $course_id,
      $educator_id,
      $customer_email,
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
    $course_id = $request->get_param('course_id');
    $is_deposit = $request->get_param('is_deposit');

    if (!$course_id) {
      return new \WP_Error('missing_course_id', 'Course ID is required', ['status' => 400]);
    }

    // Verify course exists
    $course = get_post($course_id);
    if (!$course || $course->post_type !== 'educator_course') {
      return new \WP_Error('invalid_course', 'Course not found', ['status' => 404]);
    }

    // Get course pricing from ACF fields (server-side source of truth)
    $course_cost = get_field('course_full_cost', $course_id);
    $deposit_cost = get_field('course_deposit_cost', $course_id);
    $currency = \HMWEvents\Meta\CourseMeta::get_course_currency($course_id);

    if (empty($course_cost)) {
      return new \WP_Error('invalid_course', 'Course has no price set', ['status' => 400]);
    }

    // Calculate amount based on payment type
    $is_deposit = $is_deposit && !empty($deposit_cost);
    $amount = $is_deposit ? floatval($deposit_cost) : floatval($course_cost);

    return new \WP_REST_Response([
      'success' => true,
      'data' => [
        'amount' => $amount,
        'currency' => $currency,
        'currency_symbol' => \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency),
        'course_id' => $course_id,
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
    $educator_id = $request->get_param('educator_id');
    $gateway_id = $request->get_param('gateway');

    // Create gateway instance for this educator
    $gateway = PaymentGatewayFactory::create($gateway_id, $educator_id);

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
