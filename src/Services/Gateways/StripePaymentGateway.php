<?php
/**
 * Stripe Payment Gateway.
 *
 * Stripe-specific payment gateway implementation.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Gateways;

use HMWEvents\Services\StripeService;
use HMWEvents\Services\OrganizerPaymentSettings;
use HMWEvents\Helpers\EventHelper;
use HMWEvents\Helpers\ConfigHelper;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Stripe Payment Gateway Class.
 */
class StripePaymentGateway extends AbstractPaymentGateway
{
    /**
     * Stripe service instance.
     *
     * @var StripeService
     */
    private $stripe;

    /**
     * Stripe API keys.
     *
     * @var array
     */
    private $api_keys = [];

    /**
     * Constructor.
     *
     * @param int|null $organizer_id Optional organizer ID for multi-tenant support.
     */
    public function __construct($organizer_id = null)
    {
        $this->organizer_id = $organizer_id;
        $this->stripe = new StripeService();
    }

    /**
     * Get the gateway identifier.
     *
     * @return string
     */
    public function get_gateway_id()
    {
        return 'stripe';
    }

    /**
     * Get the Stripe client instance.
     *
     * @return mixed
     */
    public function get_gateway_client(): mixed
    {
      return $this->stripe->get_client();
    }

    /**
     * Get the gateway display name.
     *
     * @return string
     */
    public function get_gateway_name()
    {
        return 'Stripe';
    }

    /**
     * Check if gateway is properly configured and available.
     *
     * @return bool
     */
    public function is_available()
    {
        $keys = $this->get_api_keys();
        return !empty($keys['secret_key']);
    }

    /**
     * Initialize the gateway.
     *
     * @return void
     */
    public function register()
    {
        $keys = $this->get_api_keys();
        if (!empty($keys['secret_key'])) {
            $this->stripe->init_stripe_with_key($keys['secret_key'], $keys['publishable_key'] ?? null);
        }
    }

    /**
     * Get API keys based on test mode and educator.
     *
     * @return array Array with 'secret_key' and 'publishable_key'.
     */
    private function get_api_keys()
    {
        if (!empty($this->api_keys)) {
            return $this->api_keys;
        }

        $is_test_mode = $this->is_test_mode();

        // In test mode, always use plugin settings
        if ($is_test_mode) {
            $this->api_keys = $this->get_plugin_api_keys('test');
            return $this->api_keys;
        }

        // In live mode, use organizer keys if available, otherwise fall back to plugin settings
        if ($this->organizer_id) {
            $organizer_keys = $this->get_organizer_api_keys($this->organizer_id);
            if (!empty($organizer_keys['secret_key'])) {
                $this->api_keys = $organizer_keys;
                return $this->api_keys;
            }
        }

        // Fallback to plugin settings for live mode
        $this->api_keys = $this->get_plugin_api_keys('live');
        return $this->api_keys;
    }

    /**
     * Get API keys from plugin settings.
     *
     * @param string $mode Mode: 'test' or 'live'.
     * @return array Array with 'secret_key' and 'publishable_key'.
     */
    private function get_plugin_api_keys($mode)
    {
        $prefix = $mode === 'live' ? 'hmwevents_stripe_live_' : 'hmwevents_stripe_test_';
        
        $secret_key_encrypted = ConfigHelper::get_option($prefix . 'secret_key', '');
        $publishable_key = ConfigHelper::get_option($prefix . 'publishable_key', '');

        $secret_key = $this->decrypt_key($secret_key_encrypted);

        return [
            'secret_key' => $secret_key,
            'publishable_key' => $publishable_key,
            'source' => 'plugin_' . $mode,
        ];
    }

    /**
     * Get API keys from organizer user meta.
     *
     * @param int $organizer_id Organizer user ID.
     * @return array Array with 'secret_key' and 'publishable_key'.
     */
    private function get_organizer_api_keys($organizer_id)
    {
        $settings = new OrganizerPaymentSettings((int) $organizer_id);

        if ($settings->get_payment_type() !== 'stripe') {
            return [];
        }

        $secret_key_encrypted = $settings->get_stripe_secret_key();
        $publishable_key = $settings->get_stripe_publishable_key();

        if (empty($secret_key_encrypted)) {
            return [];
        }

        $secret_key = $this->decrypt_key($secret_key_encrypted);
        $publishable_key = $this->decrypt_key($publishable_key);

        return [
            'secret_key' => $secret_key,
            'publishable_key' => $publishable_key,
            'source' => 'organizer_' . $organizer_id,
        ];
    }

    /**
     * Decrypt an API key.
     *
     * @param string $encrypted_key Encrypted key.
     * @return string Decrypted key or original if plain text.
     */
    private function decrypt_key($encrypted_key)
    {
        if (empty($encrypted_key)) {
            return '';
        }

        // Check if already plain text (starts with sk_ or pk_)
        if (strpos($encrypted_key, 'sk_') === 0 || strpos($encrypted_key, 'pk_') === 0) {
            return $encrypted_key;
        }

        // Decrypt
        $decrypted = \HMWEvents\Helpers\Encryption::decrypt($encrypted_key);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Get the publishable key for frontend use.
     *
     * @return string Publishable key.
     */
    public function get_publishable_key()
    {
        $keys = $this->get_api_keys();
        return $keys['publishable_key'] ?? '';
    }

    /**
     * Get information about which keys are being used.
     *
     * @return array Info about key source.
     */
    public function get_key_info()
    {
        $keys = $this->get_api_keys();
        return [
            'source' => $keys['source'] ?? 'unknown',
            'has_secret_key' => !empty($keys['secret_key']),
            'has_publishable_key' => !empty($keys['publishable_key']),
            'is_test_mode' => $this->is_test_mode(),
            'organizer_id' => $this->organizer_id,
        ];
    }

    /**
     * Process a booking with payment intent creation.
     *
     * @param array $booking_data Booking information.
     * @return array|\WP_Error
     */
    public function process_booking($booking_data)
    {
        global $wpdb;

        // Validate required fields
        $validation = $this->validate_booking_data($booking_data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // 1. Create or get customer
            $customer_id = $this->get_or_create_customer_post($booking_data);

            error_log('Customer Post ID: ' . $customer_id);

            if (is_wp_error($customer_id)) {
                throw new \Exception($customer_id->get_error_message());
            }

            // 2. Validate event and option capacity
            $event_id = intval($booking_data['event_id']);
            $attendance_type = $booking_data['attendance_type'] ?? 'individual';
            $ticket_quantity = max(1, (int) ($booking_data['ticket_quantity'] ?? 1));

            $capacity_check = EventHelper::check_booking_capacity($event_id, $attendance_type, 1, $ticket_quantity);
            if (is_wp_error($capacity_check)) {
                throw new \Exception($capacity_check->get_error_message());
            }

            $attendance_option_id = EventHelper::resolve_attendance_option_id($event_id, $attendance_type);

            // 3. Calculate total amount
            $amount_data = $this->calculate_booking_amount($event_id, $booking_data['is_deposit'] ?? false, $attendance_type, $booking_data['attendees'] ?? []);
            if (is_wp_error($amount_data)) {
                throw new \Exception($amount_data->get_error_message());
            }

            $amount = array_key_exists('authoritative_amount', $booking_data)
                ? (float) $booking_data['authoritative_amount']
                : $amount_data['amount'];
            $is_net_terms = ($booking_data['payment_type'] ?? '') === 'net_terms';
            $payment_type = $is_net_terms ? 'net_terms' : $amount_data['payment_type'];
            $currency = $amount_data['currency'] ?? 'AUD';

            $skip_payment_gateway = $is_net_terms || !empty($booking_data['skip_payment_gateway']) || $amount <= 0.00001;

            // 4. Create booking group
            $booking_reference = $this->generate_booking_reference();

            $session_rows = array_values(array_filter($booking_data['session_rows'] ?? [], fn($r) => !empty($r['event_post_id'])));

            $booking_group_id = $this->create_booking_group([
                'booking_reference' => $booking_reference,
                'customer_post_id' => $customer_id,
                'booking_type' => $booking_data['booking_type'] ?? 'single',
                'total_courses' => $session_rows ? count($session_rows) : 1,
                'metadata' => $booking_data['group_metadata'] ?? null,
                'payment_type' => $payment_type,
                'total_amount' => $amount,
                'currency' => $currency,
                'payment_status' => $is_net_terms ? 'invoiced' : 'pending',
            ]);

            if (is_wp_error($booking_group_id)) {
                throw new \Exception($booking_group_id->get_error_message());
            }

            // 5. Create booking record
            $booking_number = $this->generate_booking_number();

            $booking_id = $this->create_booking([
                'booking_group_id' => $booking_group_id,
                'booking_number' => $booking_number,
                'event_post_id' => $event_id,
                'customer_post_id' => $customer_id,
                'attendance_option_id' => $attendance_option_id,
                'ticket_type' => $payment_type,
                'ticket_quantity' => $ticket_quantity,
                'booking_amount' => $amount,
                'currency' => $currency,
                'payment_status' => $is_net_terms ? 'invoiced' : 'pending',
                'suppress_created_action' => !empty($session_rows),
            ]);

            if (is_wp_error($booking_id)) {
                throw new \Exception($booking_id->get_error_message());
            }

            // Additional session rows for multi-session bookings. Per-row
            // created actions are suppressed; one group-level action fires
            // after commit so attendees get a single confirmation.
            $extra_rows = [];
            foreach ($session_rows as $row) {
                if ((int) $row['event_post_id'] === $event_id) {
                    continue;
                }
                $extra_booking_id = $this->create_booking([
                    'booking_group_id' => $booking_group_id,
                    'booking_number' => $this->generate_booking_number(),
                    'event_post_id' => (int) $row['event_post_id'],
                    'customer_post_id' => $customer_id,
                    'attendance_option_id' => $attendance_option_id,
                    'ticket_type' => $payment_type,
                    'ticket_quantity' => $ticket_quantity,
                    'booking_amount' => (float) ($row['booking_amount'] ?? 0),
                    'currency' => $currency,
                    'payment_status' => $is_net_terms ? 'invoiced' : 'pending',
                    'suppress_created_action' => true,
                ]);
                if ($extra_booking_id && !is_wp_error($extra_booking_id)) {
                    $extra_rows[] = [
                        'booking_id' => (int) $extra_booking_id,
                        'event_post_id' => (int) $row['event_post_id'],
                    ];
                }
            }

            $stripe_customer_id = get_post_meta($customer_id, 'stripe_customer_id', true);

            $payment_intent_id = null;
            $payment_status = $is_net_terms ? 'invoiced' : 'pending';
            $client_secret = null;
            $transaction_metadata = null;

            if ($is_net_terms) {
                $payment_intent_id = 'net_terms_' . $booking_number;
            } elseif ($skip_payment_gateway) {
                $payment_intent_id = 'zero_amount_' . $booking_number;
                $payment_status = 'succeeded';
                $transaction_metadata = json_encode([
                    'type' => 'zero_amount_booking',
                ]);
            } else {
                // 6. Create Stripe payment intent
                $payment_intent = $this->stripe->create_payment_intent($amount, $currency, [
                    'booking_id' => $booking_id,
                    'booking_number' => $booking_number,
                    'booking_reference' => $booking_reference,
                    'event_id' => $event_id,
                    'customer_id' => $customer_id,
                ]);

                if (is_wp_error($payment_intent)) {
                    throw new \Exception($payment_intent->get_error_message());
                }

                $payment_intent_id = $payment_intent->id;
                $payment_status = $payment_intent->status;
                $client_secret = $payment_intent->client_secret;
                $transaction_metadata = json_encode($booking_data);
            }

            // 7. Record payment transaction (net terms bookings are invoiced,
            //    not charged, so no transaction row is created until settlement)
            if (!$is_net_terms) {
                $transaction_id = $this->create_transaction([
                    'booking_group_id' => $booking_group_id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'gateway_transaction_id' => $payment_intent_id,
                    'gateway_customer_id' => $stripe_customer_id,
                    'status' => $payment_status,
                    'metadata' => $transaction_metadata,
                ]);

                if (is_wp_error($transaction_id)) {
                    throw new \Exception($transaction_id->get_error_message());
                }
            }

            // 8. Update booking statuses and history
            if ($is_net_terms) {
                $this->update_booking_group_status($booking_group_id, 'invoiced');
                $this->update_booking_status($booking_id, 'confirmed', 'invoiced');
                $this->update_course_availability($event_id, $ticket_quantity);
                $this->add_booking_history(
                    $booking_id,
                    null,
                    'confirmed',
                    'Booking confirmed (net terms invoice)'
                );
            } elseif ($skip_payment_gateway) {
                $this->update_booking_group_status($booking_group_id, 'paid');
                $this->update_booking_status($booking_id, 'confirmed', 'paid');
                $this->update_course_availability($event_id, $ticket_quantity);
                $this->add_booking_history(
                    $booking_id,
                    null,
                    'confirmed',
                    'Booking confirmed (zero amount)'
                );
            } else {
                $this->add_booking_history(
                    $booking_id,
                    null,
                    'pending',
                    'Booking created, awaiting payment'
                );
            }

            foreach ($extra_rows as $extra) {
                if ($is_net_terms) {
                    $this->update_booking_status($extra['booking_id'], 'confirmed', 'invoiced');
                    $this->update_course_availability($extra['event_post_id'], $ticket_quantity);
                    $this->add_booking_history($extra['booking_id'], null, 'confirmed', 'Booking confirmed (net terms invoice)');
                } elseif ($skip_payment_gateway) {
                    $this->update_booking_status($extra['booking_id'], 'confirmed', 'paid');
                    $this->update_course_availability($extra['event_post_id'], $ticket_quantity);
                    $this->add_booking_history($extra['booking_id'], null, 'confirmed', 'Booking confirmed (zero amount)');
                } else {
                    $this->add_booking_history($extra['booking_id'], null, 'pending', 'Booking created, awaiting payment');
                }
            }

            // Commit transaction
            $wpdb->query('COMMIT');

            if ($is_net_terms) {
                try {
                    do_action('hmwevents_net_terms_booking_created', $booking_id, $booking_group_id);
                } catch (\Throwable $e) {
                    error_log('HMWEvents invoice issue error: ' . $e->getMessage());
                }
            }

            if ($session_rows) {
                try {
                    do_action(
                        'hmwevents_group_booking_created',
                        $booking_group_id,
                        array_merge([$booking_id], array_column($extra_rows, 'booking_id')),
                        $booking_id,
                        $booking_data
                    );
                } catch (\Throwable $e) {
                    error_log('HMWEvents group confirmation error: ' . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'booking_id' => $booking_id,
                'booking_number' => $booking_number,
                'booking_reference' => $booking_reference,
                'booking_ids' => array_merge([$booking_id], array_column($extra_rows, 'booking_id')),
                'payment_intent_id' => $payment_intent_id,
                'client_secret' => $client_secret,
                'payment_status' => $payment_status,
                'amount' => $amount,
                'payment_skipped' => $skip_payment_gateway,
            ];

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('HMWEvents Stripe Payment Error: ' . $e->getMessage());
            return new \WP_Error('payment_failed', $e->getMessage());
        }
    }

    /**
     * Process payment with immediate confirmation (single-step).
     *
     * @param array $booking_data Booking data including payment_method_id.
     * @return array|\WP_Error
     */
    public function process_payment_with_confirmation($booking_data)
    {
        global $wpdb;

        try {
            // Validate required fields
            $validation = $this->validate_booking_data($booking_data);
            if (is_wp_error($validation)) {
                throw new \Exception($validation->get_error_message());
            }

            // Get event details
            $event_details = EventHelper::get_event_details($booking_data['event_id']);
            if (!$event_details) {
                throw new \Exception('Event not found');
            }

            $event_id = intval($booking_data['event_id']);
            $attendance_type = $booking_data['attendance_type'] ?? 'individual';
            $ticket_quantity = max(1, (int) ($booking_data['ticket_quantity'] ?? 1));

            $capacity_check = EventHelper::check_booking_capacity($event_id, $attendance_type, 1, $ticket_quantity);
            if (is_wp_error($capacity_check)) {
                throw new \Exception($capacity_check->get_error_message());
            }

            $attendance_option_id = EventHelper::resolve_attendance_option_id($event_id, $attendance_type);

            // Calculate amount
            $amount_data = $this->calculate_booking_amount($event_id, $booking_data['is_deposit'] ?? false, $attendance_type, $booking_data['attendees'] ?? []);
            if (is_wp_error($amount_data)) {
                throw new \Exception($amount_data->get_error_message());
            }

            $amount = array_key_exists('authoritative_amount', $booking_data)
                ? (float) $booking_data['authoritative_amount']
                : $amount_data['amount'];
            $payment_type = $amount_data['payment_type'];
            $currency = $amount_data['currency'] ?? 'AUD';

            // Apply voucher discount if present
            $discount_amount = 0;
            $original_amount = $amount;
            if (!empty($booking_data['voucher_discount'])) {
                $voucher_service = new \HMWEvents\Services\VoucherService();
                $discount_calc = $voucher_service->calculate_discount($amount, $booking_data['voucher_discount']);
                $discount_amount = $discount_calc['discount_amount'];
                $amount = $discount_calc['final_amount'];
                
                error_log('Voucher applied: ' . $booking_data['voucher_code'] . ' - Discount: $' . $discount_amount);
            }

            // Apply coupon discount if present (and no voucher)
            if (!array_key_exists('authoritative_amount', $booking_data) && !empty($booking_data['coupon_discount']) && empty($booking_data['voucher_discount'])) {
                $coupon_service = new \HMWEvents\Services\CouponService();
                $discount_calc = $coupon_service->calculate_discount($amount, $booking_data['coupon_discount']);
                $discount_amount = $discount_calc['discount_amount'];
                $amount = $discount_calc['final_amount'];
                
                error_log('Coupon applied: ' . $booking_data['coupon_code'] . ' - Discount: $' . $discount_amount);
            }

            $applied_discount_type = null;
            $applied_discount_code = null;
            if (!empty($booking_data['voucher_code']) && !empty($booking_data['voucher_discount'])) {
                $applied_discount_type = 'voucher';
                $applied_discount_code = $booking_data['voucher_code'];
            } elseif (!empty($booking_data['coupon_code']) && !empty($booking_data['coupon_discount'])) {
                $applied_discount_type = 'coupon';
                $applied_discount_code = $booking_data['coupon_code'];
            }

            $discount_context = [
                'type' => $applied_discount_type,
                'code' => $applied_discount_code,
                'discount_amount' => floatval($discount_amount),
                'original_amount' => floatval($original_amount),
                'final_amount' => floatval($amount),
            ];

            $skip_payment_gateway = !empty($booking_data['skip_payment_gateway']) || $amount <= 0.00001;

            if (!$skip_payment_gateway && empty($booking_data['payment_method_id'])) {
                throw new \Exception('Payment method is required');
            }

            // Start transaction
            $wpdb->query('START TRANSACTION');

            // 1. Get or create customer
            // Pass full $booking_data so sync_customer_meta can read booking_details
            // and write all registry-driven customer meta fields automatically.
            $customer_id = $this->get_or_create_customer_post($booking_data);

            if (is_wp_error($customer_id)) {
                throw new \Exception($customer_id->get_error_message());
            }

            // Create Stripe customer if not exists
            $stripe_customer_id = get_post_meta($customer_id, 'stripe_customer_id', true);
            if (!$skip_payment_gateway && empty($stripe_customer_id)) {
                $stripe_customer = $this->stripe->create_customer($booking_data['registrant_email'], [
                    'name' => $booking_data['customer_name'],
                    'phone' => $booking_data['customer_phone'] ?? '',
                    'metadata' => [
                        'customer_post_id' => $customer_id,
                    ],
                ]);

                if (!is_wp_error($stripe_customer)) {
                    $stripe_customer_id = $stripe_customer->id;
                    update_post_meta($customer_id, 'stripe_customer_id', $stripe_customer_id);
                }
            }

            // 2. Create booking group
            $booking_reference = $this->generate_booking_reference();

            $session_rows = array_values(array_filter($booking_data['session_rows'] ?? [], fn($r) => !empty($r['event_post_id'])));

            $booking_group_id = $this->create_booking_group([
                'booking_reference' => $booking_reference,
                'customer_post_id' => $customer_id,
                'booking_type' => $booking_data['booking_type'] ?? 'single',
                'total_courses' => $session_rows ? count($session_rows) : 1,
                'payment_type' => $payment_type,
                'total_amount' => $amount,
                'currency' => $currency,
                'metadata' => array_merge(
                    ['discount' => $discount_context],
                    $booking_data['group_metadata'] ?? []
                ),
            ]);

            if (is_wp_error($booking_group_id)) {
                throw new \Exception($booking_group_id->get_error_message());
            }

            // 3. Create individual booking
            $booking_number = $this->generate_booking_number();

            $booking_id = $this->create_booking([
                'booking_group_id' => $booking_group_id,
                'booking_number' => $booking_number,
                'event_post_id' => $booking_data['event_id'],
                'customer_post_id' => $customer_id,
                 'attendance_option_id' => $attendance_option_id,
                 'ticket_type' => $payment_type,
                 'ticket_quantity' => $ticket_quantity,
                 'booking_amount' => $amount,
                 'currency' => $currency,
                 'coupon_code' => $applied_discount_code,
                 'discount_amount' => $discount_amount,
                 'booking_details' => $booking_data['booking_details'] ?? [],
                 'suppress_created_action' => !empty($session_rows),
              ]);

            if (is_wp_error($booking_id)) {
                throw new \Exception($booking_id->get_error_message());
            }

            // Additional session rows for multi-session bookings. Per-row
            // created actions are suppressed; one group-level action fires
            // after commit so attendees get a single confirmation.
            $extra_rows = [];
            foreach ($session_rows as $row) {
                if ((int) $row['event_post_id'] === (int) $booking_data['event_id']) {
                    continue;
                }
                $extra_booking_id = $this->create_booking([
                    'booking_group_id' => $booking_group_id,
                    'booking_number' => $this->generate_booking_number(),
                    'event_post_id' => (int) $row['event_post_id'],
                    'customer_post_id' => $customer_id,
                    'attendance_option_id' => $attendance_option_id,
                    'ticket_type' => $payment_type,
                    'ticket_quantity' => $ticket_quantity,
                    'booking_amount' => (float) ($row['booking_amount'] ?? 0),
                    'currency' => $currency,
                    'payment_status' => 'pending',
                    'suppress_created_action' => true,
                ]);
                if ($extra_booking_id && !is_wp_error($extra_booking_id)) {
                    $extra_rows[] = [
                        'booking_id' => (int) $extra_booking_id,
                        'event_post_id' => (int) $row['event_post_id'],
                    ];
                }
            }

            // Update booking with discount amount if voucher was applied
            if ($discount_amount > 0) {
                $wpdb->update(
                    \HMWEvents\Services\DatabaseService::get_table_name('bookings'),
                    ['discount_amount' => $discount_amount],
                    ['id' => $booking_id],
                    ['%f'],
                    ['%d']
                );
            }

            $payment_intent_id = null;
            $payment_status = 'pending';
            $gateway_payment_method_id = null;
            $transaction_metadata = null;
            $client_secret = null;

            if ($skip_payment_gateway) {
                $payment_status = 'succeeded';
                $payment_intent_id = 'zero_amount_' . $booking_number;
                $transaction_metadata = json_encode([
                    'type' => 'zero_amount_booking',
                    'discount' => $discount_context,
                ]);
            } else {
                // 4. Create and confirm payment in one step
                $payment_intent = $this->stripe->create_and_confirm_payment(
                    $amount,
                    $booking_data['payment_method_id'],
                    $currency,
                    [
                        'booking_number' => $booking_number,
                        'registrant_email' => $booking_data['registrant_email'],
                        'event_id' => $booking_data['event_id'],
                    ]
                );

                if (is_wp_error($payment_intent)) {
                    throw new \Exception($payment_intent->get_error_message());
                }

                $payment_intent_id = $payment_intent->id;
                $payment_status = $payment_intent->status;
                $client_secret = $payment_intent->client_secret;
                $gateway_payment_method_id = $booking_data['payment_method_id'];
                $transaction_metadata = json_encode([
                    'payment_intent' => $payment_intent,
                    'discount' => $discount_context,
                ]);
            }

            // 5. Record transaction
            $transaction_id = $this->create_transaction([
                'booking_group_id' => $booking_group_id,
                'amount' => $amount,
                'currency' => $currency,
                'gateway_transaction_id' => $payment_intent_id,
                'gateway_customer_id' => $stripe_customer_id,
                'gateway_payment_method_id' => $gateway_payment_method_id,
                'status' => $payment_status,
                'metadata' => $transaction_metadata,
            ]);

            if (is_wp_error($transaction_id)) {
                throw new \Exception($transaction_id->get_error_message());
            }

            // 6. Update booking statuses based on payment result
            if ($payment_status === 'succeeded') {
                $this->update_booking_group_status($booking_group_id, 'paid');
                $this->update_booking_status($booking_id, 'confirmed', 'paid');
                $this->update_course_availability($booking_data['event_id'], $ticket_quantity);

                foreach ($extra_rows as $extra) {
                    $this->update_booking_status($extra['booking_id'], 'confirmed', 'paid');
                    $this->update_course_availability($extra['event_post_id'], $ticket_quantity);
                    $this->add_booking_history($extra['booking_id'], null, 'confirmed', 'Payment confirmed');
                }

                // Redeem voucher and track usage
                if (!empty($booking_data['voucher_code']) && !empty($booking_data['voucher_discount'])) {
                    $voucher_service = new \HMWEvents\Services\VoucherService();
                    $discount_amount = $original_amount - $amount;
                    $voucher_service->redeem_voucher($booking_data['voucher_code'], $booking_id, $discount_amount);
                    
                    // Track voucher usage in database
                    $insert_result = $wpdb->insert(
                        \HMWEvents\Services\DatabaseService::get_table_name('voucher_usage'),
                        [
                            'booking_id' => $booking_id,
                            'voucher_code' => $booking_data['voucher_code'],
                            'voucher_id' => $booking_data['voucher_discount']['voucher_id'] ?? null,
                            'voucher_type' => $booking_data['voucher_discount']['voucher_type'] ?? '',
                            'redeemed_value' => $discount_amount,
                            'original_amount' => $original_amount,
                            'discounted_amount' => $amount,
                        ],
                        ['%d', '%s', '%d', '%s', '%f', '%f', '%f']
                    );
                    
                    if ($insert_result === false) {
                        error_log('HMWEvents: Failed to insert voucher usage: ' . $wpdb->last_error);
                    } else {
                        error_log(sprintf('HMWEvents: Tracked voucher usage - Code: %s, Booking: %d, Discount: $%.2f', 
                            $booking_data['voucher_code'], $booking_id, $discount_amount));
                    }
                }

                // Record coupon usage
                if (!empty($booking_data['coupon_code']) && !empty($booking_data['coupon_discount']) && empty($booking_data['voucher_code'])) {
                    $coupon_service = new \HMWEvents\Services\CouponService();
                    $discount_amount = $original_amount - $amount;
                    $coupon_service->record_usage(
                        $booking_data['coupon_code'],
                        $booking_id,
                        $booking_data['registrant_email'],
                        $discount_amount,
                        $original_amount
                    );
                    
                    error_log(sprintf('HMWEvents: Tracked coupon usage - Code: %s, Booking: %d, Discount: $%.2f', 
                        $booking_data['coupon_code'], $booking_id, $discount_amount));
                }
            }

            // Commit transaction
            $wpdb->query('COMMIT');

            if ($session_rows) {
                try {
                    do_action(
                        'hmwevents_group_booking_created',
                        $booking_group_id,
                        array_merge([$booking_id], array_column($extra_rows, 'booking_id')),
                        $booking_id,
                        $booking_data
                    );
                } catch (\Throwable $e) {
                    error_log('HMWEvents group confirmation error: ' . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'booking_id' => $booking_id,
                'booking_number' => $booking_number,
                'booking_reference' => $booking_reference,
                'booking_ids' => array_merge([$booking_id], array_column($extra_rows, 'booking_id')),
                'client_secret' => $client_secret,
                'payment_intent_id' => $payment_intent_id,
                'payment_status' => $payment_status,
                'amount' => $amount,
                'payment_skipped' => $skip_payment_gateway,
            ];

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('HMWEvents Stripe Payment Error: ' . $e->getMessage());
            return new \WP_Error('payment_error', $e->getMessage());
        }
    }

    /**
     * Confirm payment and update booking status.
     *
     * @param string $payment_intent_id Stripe payment intent ID.
     * @return bool|\WP_Error
     */
    public function confirm_payment($payment_intent_id)
    {
        global $wpdb;

        // Get payment transaction
        $transaction = $this->get_transaction($payment_intent_id);
        if (is_wp_error($transaction)) {
            return $transaction;
        }

        // If already confirmed, avoid processing side effects twice.
        if ($transaction->status === 'succeeded') {
            return true;
        }

        // If no organizer_id was injected (e.g. called from webhook or REST confirm endpoint),
        // resolve it from the booking so we use the correct Stripe account keys.
        if (!$this->organizer_id && !$this->is_test_mode()) {
            $organizer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT c.post_author
                 FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " b
                  INNER JOIN {$wpdb->posts} c ON b.event_post_id = c.ID
                 WHERE b.booking_group_id = %d
                 LIMIT 1",
                $transaction->booking_group_id
            ));

            if ($organizer_id) {
                $correct_gateway = new self((int) $organizer_id);
                $correct_gateway->register();
                return $correct_gateway->confirm_payment($payment_intent_id);
            }
        }

        $transaction_metadata = json_decode((string) $transaction->metadata, true);
        $is_remaining_payment = is_array($transaction_metadata)
            && (($transaction_metadata['payment_type'] ?? '') === 'remaining');

        // Get payment intent from Stripe
        $payment_intent = $this->stripe->get_payment_intent($payment_intent_id);

        if (is_wp_error($payment_intent)) {
            return $payment_intent;
        }

        // Update transaction status
        $status = $payment_intent->status === 'succeeded' ? 'succeeded' : $payment_intent->status;
        $this->update_transaction_status($payment_intent_id, $status);

        if ($status === 'succeeded') {
            $this->update_booking_group_status($transaction->booking_group_id, 'paid');

            // Update all bookings in the group
            $bookings = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . "
                WHERE booking_group_id = %d
            ", $transaction->booking_group_id));

            foreach ($bookings as $booking) {
                // Update booking status
                $this->update_booking_status($booking->id, 'confirmed', 'paid');

                if (!$is_remaining_payment) {
                    // Update availability only on first/primary booking payment, not remaining-balance top-ups.
                    $people = max(1, (int) ($booking->ticket_quantity ?? 1));
                    $this->update_course_availability($booking->event_post_id, $people);

                    // Add to history for initial payment confirmation.
                    $this->add_booking_history(
                        $booking->id,
                        'pending',
                        'confirmed',
                        'Payment confirmed'
                    );
                }

                // TODO: Send confirmation email
            }

            return true;
        }

        return new \WP_Error('payment_not_confirmed', 'Payment not yet confirmed');
    }

    /**
     * Get payment status from Stripe.
     *
     * @param string $payment_intent_id Payment intent ID.
     * @return array|\WP_Error
     */
    public function get_payment_status($payment_intent_id)
    {
        $payment_intent = $this->stripe->get_payment_intent($payment_intent_id);

        if (is_wp_error($payment_intent)) {
            return $payment_intent;
        }

        return [
            'status' => $payment_intent->status,
            'amount' => $payment_intent->amount / 100,
            'currency' => strtoupper($payment_intent->currency),
            'created' => $payment_intent->created,
        ];
    }

    /**
     * Create or get a customer in Stripe.
     *
     * @param string $email Customer email.
     * @param array  $data Additional customer data.
     * @return string|\WP_Error Stripe customer ID or error.
     */
    public function create_or_get_customer($email, $data = [])
    {
        // Check if customer exists in WordPress
        $customer_post_id = $this->get_or_create_customer_post([
            'registrant_email' => $email,
            'customer_name' => $data['name'] ?? $email,
            'customer_phone' => $data['phone'] ?? '',
        ]);

        if (is_wp_error($customer_post_id)) {
            return $customer_post_id;
        }

        // Get existing Stripe customer ID
        $stripe_customer_id = get_post_meta($customer_post_id, 'stripe_customer_id', true);

        if (!empty($stripe_customer_id)) {
            return $stripe_customer_id;
        }

        // Create new Stripe customer
        $stripe_customer = $this->stripe->create_customer($email, array_merge($data, [
            'metadata' => [
                'customer_post_id' => $customer_post_id,
            ],
        ]));

        if (is_wp_error($stripe_customer)) {
            return $stripe_customer;
        }

        // Store Stripe customer ID
        update_post_meta($customer_post_id, 'stripe_customer_id', $stripe_customer->id);

        return $stripe_customer->id;
    }

    /**
     * Refund a payment.
     *
     * @param string $payment_intent_id Payment intent ID.
     * @param float  $amount Amount to refund (null for full refund).
     * @param string $reason Refund reason.
     * @return array|\WP_Error
     */
    public function refund_payment($payment_intent_id, $amount = null, $reason = '')
    {
        $refund = $this->stripe->create_refund($payment_intent_id, $amount, $reason);

        if (is_wp_error($refund)) {
            return $refund;
        }

        return [
            'refund_id' => $refund->id,
            'amount' => $refund->amount / 100,
            'status' => $refund->status,
        ];
    }

    /**
     * Verify webhook signature.
     *
     * @param string $payload Webhook payload.
     * @param string $signature Webhook signature.
     * @return bool|\WP_Error
     */
    public function verify_webhook_signature($payload, $signature)
    {
        // TODO: Implement webhook signature verification
        // This requires webhook secret to be configured
        return new \WP_Error(
            'not_implemented',
            'Webhook signature verification not yet implemented for Stripe gateway.'
        );
    }
}
