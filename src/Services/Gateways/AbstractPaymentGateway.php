<?php

/**
 * Abstract Payment Gateway.
 *
 * Base implementation with common functionality for all payment gateways.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Gateways;

use HMWEvents\Interfaces\PaymentGatewayInterface;
use HMWEvents\Helpers\EventHelper;
use HMWEvents\Helpers\ConfigHelper;
use HMWEvents\Services\EventDataService;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Abstract Payment Gateway Class.
 */
abstract class AbstractPaymentGateway implements PaymentGatewayInterface
{
  /**
   * Gateway configuration.
   *
   * @var array
   */
  protected $config = [];

  /**
   * Event organizer ID for multi-tenant gateway credentials.
   *
   * @var int|null
   */
  protected $organizer_id = null;

  private ?EventDataService $event_data_service = null;

  /**
   * Set organizer ID for credential lookup.
   *
   * @param int $organizer_id Organizer user ID.
   * @return void
   */
  public function set_organizer_id($organizer_id)
  {
    $this->organizer_id = $organizer_id;
  }

  /**
   * Get organizer ID.
   *
   * @return int|null
   */
  public function get_organizer_id()
  {
    return $this->organizer_id;
  }

  private function event_data_service(): EventDataService
  {
    if ($this->event_data_service === null) {
      $this->event_data_service = new EventDataService();
    }
    return $this->event_data_service;
  }

  /**
   * Check if gateway is in test mode.
   *
   * @return bool
   */
  public function is_test_mode()
  {
    return ConfigHelper::get_option('hmwevents_' . $this->get_gateway_id() . '_mode', 'test') === 'test';
  }

  /**
   * Get supported currencies.
   *
   * @return array
   */
  public function get_supported_currencies()
  {
    return ['AUD', 'USD', 'EUR', 'GBP'];
  }

  /**
   * Get the webhook URL for this gateway.
   *
   * @return string
   */
  public function get_webhook_url()
  {
    return rest_url('hmwevents/v1/webhook/' . $this->get_gateway_id());
  }

  /**
   * Get or create customer post in WordPress.
   *
   * @param array $data Customer data (customer_name, registrant_email, customer_phone).
   * @return int|\WP_Error Customer post ID or error.
   */
  protected function get_or_create_customer_post($data)
  {
    // Check if customer exists by email
      $existing = get_posts([
      'post_type' => 'hmw_registrant',
      'meta_key' => 'registrant_email',
      'meta_value' => $data['registrant_email'],
      'posts_per_page' => 1,
      'fields' => 'ids',
    ]);

    if (!empty($existing)) {
      $customer_id = $existing[0];
      // Update with latest details so re-bookings keep customer data fresh.
      $this->sync_customer_meta($customer_id, $data);
      return $customer_id;
    }

    // Create new customer
    $customer_id = wp_insert_post([
      'post_type' => 'hmw_registrant',
      'post_title' => $data['customer_name'],
      'post_status' => 'publish',
    ]);

    if (is_wp_error($customer_id)) {
      return $customer_id;
    }

    update_post_meta($customer_id, 'registrant_email', sanitize_email($data['registrant_email']));
    $this->sync_customer_meta($customer_id, $data);

    return $customer_id;
  }

  /**
   * Write customer meta fields using the ACF-registered key names.
   * Safe to call on both new and existing customers — only overwrites
   * non-empty values so partial data never blanks out existing fields.
   *
   * Driven by the registry (BookingFields::with_meta_fallback()), so adding a
   * customer_meta field there is all that's needed — no changes required here.
   *
   * @param int   $customer_id Customer post ID.
   * @param array $data        Raw booking_data array (booking_details key holds field values).
   */
  private function sync_customer_meta($customer_id, $data)
  {
    // Values may arrive in one of three shapes depending on caller:
    //
    //  1. ProcessPayment (frontend form): full $booking_data with a 'booking_details'
    //     sub-array keyed by canonical field key (e.g. 'mothers_first_name').
    //
    //  2. ManualBookingGateway: flat array keyed by a mix of canonical keys
    //     ('street_address', 'city', 'postcode') and meta keys
    //     ('customer_first_name', 'customer_last_name', 'customer_phone').
    //
    //  3. Minimal callers (create_or_get_customer): only registrant_email /
    //     customer_name / customer_phone — partial sync is intentional.
    //
    // Resolution order: booking_details[canonical] → data[canonical] → data[meta_key].
    $details = $data['booking_details'] ?? [];

    foreach (\HMWEvents\Registry\RegistrationFieldRegistry::all() as $key => $field) {
      if (($field['source'] ?? '') !== \HMWEvents\Registry\RegistrationFieldRegistry::SOURCE_REGISTRANT_META) {
        continue;
      }
      $meta_key = $field['meta_key'] ?? '';
      if ($meta_key === '') {
        continue;
      }
      $raw = $details[$key]
          ?? $data[$key]
          ?? $data[$meta_key]
          ?? '';
      if ($raw === '' || $raw === null) {
        continue;
      }
      $value = ($field['type'] ?? '') === 'email'
        ? sanitize_email($raw)
        : sanitize_text_field($raw);
      update_post_meta($customer_id, $meta_key, $value);
    }
  }

  /**
   * Update event availability.
   *
   * @param int $event_id Event post ID.
   * @param int $change Change in booked count (+1 or -1).
   * @return bool|int
   */
  protected function update_course_availability($event_id, $change)
  {
    global $wpdb;

    return $wpdb->query($wpdb->prepare("
            UPDATE " . \HMWEvents\Services\DatabaseService::get_table_name('event_availability') . "
            SET booked_count = GREATEST(0, booked_count + %d),
                available_count = GREATEST(0, available_count - %d)
            WHERE event_post_id = %d
        ", $change, $change, $event_id));
  }

  /**
   * Generate unique booking reference.
   *
   * @return string
   */
  protected function generate_booking_reference()
  {
    return 'BKG-' . strtoupper(wp_generate_password(8, false));
  }

  /**
   * Generate unique booking number.
   *
   * @return string
   */
  protected function generate_booking_number()
  {
    return 'CB-' . date('Ymd') . '-' . strtoupper(wp_generate_password(6, false));
  }

  /**
   * Create booking group in database.
   *
   * @param array $data Booking group data.
   * @return int|\WP_Error Booking group ID or error.
   */
  protected function create_booking_group($data)
  {
    global $wpdb;

    // Prepare metadata as JSON string if provided
    $metadata = null;
    if (!empty($data['metadata'])) {
      $metadata = is_string($data['metadata']) ? $data['metadata'] : json_encode($data['metadata']);
    }

    $wpdb->insert(
      \HMWEvents\Services\DatabaseService::get_table_name('booking_groups'),
      [
        'booking_reference' => $data['booking_reference'],
        'registrant_post_id' => $data['customer_post_id'],
        'booking_type' => $data['booking_type'] ?? 'single',
        'payment_type' => $data['payment_type'],
        'total_bookings' => $data['total_courses'] ?? 1,
        'total_amount' => $data['total_amount'],
        'payment_status' => $data['payment_status'] ?? 'pending',
        'metadata' => $metadata,
        'created_at' => current_time('mysql'),
      ],
      ['%s', '%d', '%s', '%s', '%d', '%f', '%s', '%s', '%s']
    );

    if ($wpdb->last_error) {
      return new \WP_Error('db_error', 'Failed to create booking group: ' . $wpdb->last_error);
    }

    return $wpdb->insert_id;
  }

  /**
   * Create individual booking in database.
   *
   * @param array $data Booking data.
   * @return int|\WP_Error Booking ID or error.
   */
  protected function create_booking($data)
  {
    global $wpdb;

    $wpdb->insert(
      \HMWEvents\Services\DatabaseService::get_table_name('bookings'),
      [
        'booking_group_id' => $data['booking_group_id'],
        'booking_number' => $data['booking_number'],
        'event_post_id' => $data['event_post_id'],
        'registrant_post_id' => $data['customer_post_id'],
        'attendance_option_id' => $data['attendance_option_id'] ?? null,
        'ticket_type' => $data['ticket_type'],
         'ticket_quantity' => $data['ticket_quantity'] ?? 1,
         'booking_amount' => $data['booking_amount'],
         'coupon_code' => $data['coupon_code'] ?? null,
         'discount_amount' => $data['discount_amount'] ?? 0,
         'status' => $data['status'] ?? 'pending',
        'payment_status' => $data['payment_status'] ?? 'pending',
        'booking_source' => $data['booking_source'] ?? 'website',
        'created_at' => current_time('mysql'),
      ],
       ['%d', '%s', '%d', '%d', '%d', '%s', '%d', '%f', '%s', '%f', '%s', '%s', '%s', '%s']
    );

      if ($wpdb->last_error) {
        return new \WP_Error('db_error', 'Failed to create booking: ' . $wpdb->last_error);
      }

      $booking_id = $wpdb->insert_id;

      // Create booking details if provided
      if (!empty($data['booking_details']) && is_array($data['booking_details'])) {
        $details_result = $this->create_booking_details($booking_id, $data['booking_details']);
        if (is_wp_error($details_result)) {
          error_log('Failed to create booking details: ' . $details_result->get_error_message());
        }
      }

      /**
       * Fire booking created event for email system.
       * Multi-session rows suppress this per-row action; the gateway fires
       * a single group-level action instead so attendees receive one
       * confirmation covering all sessions.
       */
      if (empty($data['suppress_created_action'])) {
        do_action('hmwevents_booking_created', $booking_id, $data);
      }

      return $booking_id;
    }

  /**
   * Create booking details (questionnaire responses).
   *
   * @param int   $booking_id Booking ID.
   * @param array $details Questionnaire data.
   * @return int|\WP_Error Booking details ID or error.
   */
  protected function create_booking_details($booking_id, $details)
  {
    // Use the new BookingDetailsService
    $service = new \HMWEvents\Services\BookingDetailsService();

    // Save booking details with form version 1.0
    $result = $service->save_booking_details($booking_id, $details, '1.0');

    if ($result === false) {
      return new \WP_Error('db_error', 'Failed to create booking details');
    }

    return $result;
  }

  /**
   * Create payment transaction record.
   *
   * @param array $data Transaction data.
   * @return int|\WP_Error Transaction ID or error.
   */
  protected function create_transaction($data)
  {
    global $wpdb;

    $wpdb->insert(
      \HMWEvents\Services\DatabaseService::get_table_name('payment_transactions'),
      [
        'booking_group_id' => $data['booking_group_id'],
        'transaction_type' => $data['transaction_type'] ?? 'charge',
        'amount' => $data['amount'],
        'currency' => $data['currency'] ?? 'AUD',
        'gateway' => $this->get_gateway_id(),
        'gateway_transaction_id' => $data['gateway_transaction_id'],
        'gateway_customer_id' => $data['gateway_customer_id'] ?? null,
        'gateway_payment_method_id' => $data['gateway_payment_method_id'] ?? null,
        'status' => $data['status'],
        'metadata' => $data['metadata'] ?? null,
        'created_at' => current_time('mysql'),
      ],
      ['%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );

    if ($wpdb->last_error) {
      return new \WP_Error('db_error', 'Failed to create transaction: ' . $wpdb->last_error);
    }

    return $wpdb->insert_id;
  }

  /**
   * Add booking history entry.
   *
   * @param int    $booking_id Booking ID.
   * @param string $previous_status Previous status.
   * @param string $new_status New status.
   * @param string $reason Change reason.
   * @return bool
   */
  protected function add_booking_history($booking_id, $previous_status, $new_status, $reason)
  {
    global $wpdb;

    return $wpdb->insert(
      \HMWEvents\Services\DatabaseService::get_table_name('booking_history'),
      [
        'booking_id' => $booking_id,
        'field_changed' => 'status',
        'old_value' => (string) $previous_status,
        'new_value' => (string) $new_status,
        'changed_by' => get_current_user_id(),
        'change_reason' => $reason,
        'created_at' => current_time('mysql'),
      ],
      ['%d', '%s', '%s', '%s', '%d', '%s', '%s']
    );
  }

  /**
   * Update booking status.
   *
   * @param int    $booking_id Booking ID.
   * @param string $status New status.
   * @param string $payment_status New payment status.
   * @return bool|int
   */
  protected function update_booking_status($booking_id, $status, $payment_status)
  {
    global $wpdb;

    $previous = $wpdb->get_row($wpdb->prepare(
      "SELECT status, payment_status, booking_amount FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " WHERE id = %d",
      $booking_id
    ));

    $updated = $wpdb->update(
      \HMWEvents\Services\DatabaseService::get_table_name('bookings'),
      [
        'status' => $status,
        'payment_status' => $payment_status,
      ],
      ['id' => $booking_id],
      ['%s', '%s'],
      ['%d']
    );

    if ($updated && $previous) {
      if ($previous->status !== $status) {
        if ($status === 'confirmed') {
          do_action('hmwevents_booking_confirmed', $booking_id, []);
        }

        if ($status === 'cancelled') {
          do_action('hmwevents_booking_cancelled', $booking_id, 'status_updated', []);
        }
      }

      if ($previous->payment_status !== $payment_status && $payment_status === 'paid') {
        do_action('hmwevents_payment_received', $booking_id, [
          'payment_status' => $payment_status,
        ]);
      }

      if ($previous->payment_status !== $payment_status && $payment_status === 'refunded') {
        $refund_amount = $previous->booking_amount ?? 0;
        do_action('hmwevents_refund_issued', $booking_id, $refund_amount, 'status_updated');
      }
    }

    return $updated;
  }

  /**
   * Update booking group payment status.
   *
   * @param int    $booking_group_id Booking group ID.
   * @param string $payment_status New payment status.
   * @return bool|int
   */
  protected function update_booking_group_status($booking_group_id, $payment_status)
  {
    global $wpdb;

    return $wpdb->update(
      \HMWEvents\Services\DatabaseService::get_table_name('booking_groups'),
      ['payment_status' => $payment_status],
      ['id' => $booking_group_id],
      ['%s'],
      ['%d']
    );
  }

  /**
   * Update transaction status.
   *
   * @param string $transaction_id Gateway transaction ID.
   * @param string $status New status.
   * @return bool|int
   */
  protected function update_transaction_status($transaction_id, $status)
  {
    global $wpdb;

    return $wpdb->update(
      \HMWEvents\Services\DatabaseService::get_table_name('payment_transactions'),
      ['status' => $status],
      ['gateway_transaction_id' => $transaction_id],
      ['%s'],
      ['%s']
    );
  }

  /**
   * Get transaction by gateway transaction ID.
   *
   * @param string $transaction_id Gateway transaction ID.
   * @return object|\WP_Error Transaction object or error.
   */
  protected function get_transaction($transaction_id)
  {
    global $wpdb;

    $transaction = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM " . \HMWEvents\Services\DatabaseService::get_table_name('payment_transactions') . "
            WHERE gateway_transaction_id = %s
        ", $transaction_id));

    if (!$transaction) {
      return new \WP_Error('transaction_not_found', 'Transaction not found');
    }

    return $transaction;
  }

  /**
   * Validate required booking fields.
   *
   * @param array $booking_data Booking data.
   * @param array $required_fields Required field names.
   * @return true|\WP_Error True if valid, error otherwise.
   */
  protected function validate_booking_data($booking_data, $required_fields = [])
  {
    $default_required = ['customer_name', 'registrant_email', 'event_id', 'organizer_id'];
    $required = array_merge($default_required, $required_fields);

    foreach ($required as $field) {
      if (empty($booking_data[$field])) {
        return new \WP_Error('missing_field', sprintf('Missing required field: %s', $field));
      }
    }

    return true;
  }

  /**
   * Calculate booking amount.
   *
   * When an attendance type is provided and resolves to an active attendance
   * option, the option price replaces the event-level full price. Deposit and
   * surcharge handling remain unchanged.
   *
   * @param int    $event_id        Event ID.
   * @param bool   $is_deposit      Whether this is a deposit payment.
   * @param string $attendance_type Optional attendance option type.
   * @return array|\WP_Error Array with amount and payment_type, or error.
   */
  protected function calculate_amount($event_id, $is_deposit = false, $attendance_type = '')
  {
    $eds           = $this->event_data_service();
    $course_cost   = $eds->get_price($event_id);
    $deposit_cost  = $eds->get_deposit($event_id);
    $surcharge     = $eds->get_surcharge($event_id);
    $currency      = $eds->get_currency($event_id);

    $resolved_option = false;

    if ($attendance_type !== '') {
      $option_price = EventHelper::resolve_attendance_option_price($event_id, $attendance_type);
      if ($option_price !== null) {
        $course_cost = $option_price;
        $resolved_option = true;
      }
    }

    if (!$resolved_option && empty($course_cost)) {
      return new \WP_Error('invalid_course', 'Course cost not set');
    }

    $is_deposit = $is_deposit && !empty($deposit_cost);
    $base_amount = $is_deposit ? floatval($deposit_cost) : floatval($course_cost);
    $amount = $base_amount + $surcharge;
    $payment_type = $is_deposit ? 'deposit' : 'full';

    return [
      'amount'       => $amount,
      'base_amount'  => $base_amount,
      'surcharge'    => $surcharge,
      'payment_type' => $payment_type,
      'currency'     => $currency,
    ];
  }

  /**
   * Calculate a booking amount, preferring attendee age-band pricing when
   * attendees and pricing rules are present, and falling back to the legacy
   * option/event base price otherwise.
   *
   * @param int    $event_id        Event ID.
   * @param bool   $is_deposit      Whether this is a deposit payment.
   * @param string $attendance_type Optional attendance option type.
   * @param array  $attendees       Attendee list (role + date_of_birth).
   * @return array|\WP_Error Array with amount and payment_type, or error.
   */
  protected function calculate_booking_amount($event_id, $is_deposit = false, $attendance_type = '', array $attendees = [])
  {
    $pricing = new \HMWEvents\Services\AttendancePricingService();

    if (!$is_deposit && !empty($attendees) && $pricing->uses_attendee_pricing((int) $event_id, (string) $attendance_type)) {
      $result = $pricing->calculate_total((int) $event_id, (string) $attendance_type, $attendees);

      if (!is_wp_error($result)) {
        return [
          'amount'       => $result['total'],
          'base_amount'  => $result['base_amount'],
          'surcharge'    => $result['surcharge'],
          'payment_type' => 'full',
          'currency'     => $result['currency'],
        ];
      }
    }

    return $this->calculate_amount($event_id, $is_deposit, $attendance_type);
  }

  /**
   * Get the publishable/public key for frontend use.
   *
   * Default implementation returns empty string.
   * Override in gateway-specific implementations.
   *
   * @return string
   */
  public function get_publishable_key()
  {
    return '';
  }

  /**
   * Get diagnostic information about the gateway configuration.
   *
   * Default implementation returns basic info.
   * Override in gateway-specific implementations for more details.
   *
   * @return array
   */
  public function get_key_info()
  {
    return [
      'gateway_id'   => $this->get_gateway_id(),
      'gateway_name' => $this->get_gateway_name(),
      'organizer_id' => $this->organizer_id,
      'is_test_mode' => $this->is_test_mode(),
    ];
  }
}
