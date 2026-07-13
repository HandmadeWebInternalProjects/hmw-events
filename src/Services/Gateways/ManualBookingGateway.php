<?php

/**
 * Manual Booking Gateway.
 *
 * Creates confirmed bookings without processing payment.
 * Payment can be collected later via a payment link.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Gateways;

use HMWEvents\Helpers\EventHelper;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Manual Booking Gateway Class.
 */
class ManualBookingGateway extends AbstractPaymentGateway
{
    public function get_gateway_id()
    {
        return 'manual';
    }

    public function get_gateway_name()
    {
        return 'Manual';
    }

    public function is_available()
    {
        return true;
    }

    public function register() {}

    public function process_booking($booking_data)
    {
        return $this->create_manual_booking($booking_data);
    }

    public function confirm_payment($transaction_id)
    {
        return new \WP_Error('not_supported', 'Manual bookings do not use payment confirmation');
    }

    public function refund_payment($transaction_id, $amount = null, $reason = '')
    {
        return new \WP_Error('not_supported', 'Manual bookings do not support automatic refunds');
    }

    public function verify_webhook_signature($payload, $signature)
    {
        return new \WP_Error('not_supported', 'Manual bookings do not use webhooks');
    }

    public function get_gateway_client()
    {
        return null;
    }

    public function process_payment_with_confirmation($booking_data)
    {
        return $this->create_manual_booking($booking_data);
    }

    public function get_payment_status($transaction_id)
    {
        return new \WP_Error('not_supported', 'Manual bookings do not query a payment gateway');
    }

    public function create_or_get_customer($email, $data = [])
    {
        return new \WP_Error('not_supported', 'Manual bookings do not create gateway customers');
    }

    /**
     * Create a manual booking without collecting payment.
     *
     * The booking is immediately confirmed; payment status is 'pending' so that
     * a payment link can be sent to the customer later via the meta box actions.
     *
     * @param array $booking_data {
     *   @type string $customer_first_name  Required.
     *   @type string $customer_last_name   Required.
     *   @type string $registrant_email       Required.
     *   @type string $customer_phone
     *   @type string $street_address
     *   @type string $city
     *   @type string $postcode
     *   @type int    $course_id            Required.
     *   @type string $dietary_requirements
     * }
     * @return array|\WP_Error Result array or error.
     */
    public function create_manual_booking($booking_data)
    {
        global $wpdb;

        $course_id = intval($booking_data['course_id'] ?? 0);
        if (!$course_id) {
            return new \WP_Error('missing_field', 'course_id is required');
        }

        $wpdb->query('START TRANSACTION');

        try {
            // Build full name for customer post title
            $first_name = sanitize_text_field($booking_data['customer_first_name'] ?? '');
            $last_name  = sanitize_text_field($booking_data['customer_last_name'] ?? '');
            $full_name  = trim($first_name . ' ' . $last_name);
            if (empty($full_name)) {
                $full_name = sanitize_email($booking_data['registrant_email'] ?? '');
            }

            // 1. Create or update the customer post
            $customer_id = $this->get_or_create_customer_post([
                'customer_name'       => $full_name,
                'registrant_email'      => $booking_data['registrant_email'],
                'customer_phone'      => $booking_data['customer_phone'] ?? '',
                'customer_first_name' => $first_name,
                'customer_last_name'  => $last_name,
                'street_address'      => $booking_data['street_address'] ?? '',
                'city'                => $booking_data['city'] ?? '',
                'postcode'            => $booking_data['postcode'] ?? '',
            ]);

            if (is_wp_error($customer_id)) {
                throw new \Exception($customer_id->get_error_message());
            }

            // 2. Check course availability
            if (!EventHelper::check_course_availability($course_id)) {
                throw new \Exception(__('This course is fully booked.', 'hmw-events'));
            }

            // 3. Resolve full-price amount (manual bookings always use full price)
            $amount_data = $this->calculate_amount($course_id, false);
            if (is_wp_error($amount_data)) {
                throw new \Exception($amount_data->get_error_message());
            }

            $booking_reference = $this->generate_booking_reference();
            $booking_number    = $this->generate_booking_number();

            // 4. Create booking group
            $booking_group_id = $this->create_booking_group([
                'booking_reference' => $booking_reference,
                'customer_post_id'  => $customer_id,
                'booking_type'      => 'single',
                'payment_type'      => 'full',
                'total_courses'     => 1,
                'total_amount'      => $amount_data['amount'],
                'payment_status'    => 'pending',
                'metadata'          => ['booking_source' => 'manual'],
            ]);

            if (is_wp_error($booking_group_id)) {
                throw new \Exception($booking_group_id->get_error_message());
            }

            // 5. Build questionnaire details to store alongside the booking
            $booking_details = $this->extract_booking_details($booking_data);

            // 6. Create the booking — confirmed immediately, payment pending
            $booking_id = $this->create_booking([
                'booking_group_id' => $booking_group_id,
                'booking_number'   => $booking_number,
                'event_post_id'   => $course_id,
                'customer_post_id' => $customer_id,
                'ticket_type'      => 'full',
                'ticket_quantity'  => 1,
                'booking_amount'   => $amount_data['amount'],
                'status'           => 'confirmed',
                'payment_status'   => 'pending',
                'booking_source'   => 'manual',
                'booking_details'  => $booking_details,
            ]);

            if (is_wp_error($booking_id)) {
                throw new \Exception($booking_id->get_error_message());
            }

            // 7. Create a pending transaction row so payment links can be generated later
            $this->create_transaction([
                'booking_group_id'          => $booking_group_id,
                'transaction_type'          => 'charge',
                'amount'                    => $amount_data['amount'],
                'currency'                  => $amount_data['currency'],
                'gateway_transaction_id'    => 'manual_' . $booking_number,
                'gateway_customer_id'       => null,
                'gateway_payment_method_id' => null,
                'status'                    => 'pending',
                'metadata'                  => json_encode(['booking_source' => 'manual']),
            ]);

            // 8. Decrement course availability
            $this->update_course_availability($course_id, 1);

            // 9. Record booking history
            $this->add_booking_history($booking_id, null, 'confirmed', 'Manual booking created by admin/educator');

            $wpdb->query('COMMIT');

            // hmwevents_booking_created is fired automatically by AbstractPaymentGateway::create_booking().
            // hmwevents_booking_confirmed (mailing list sync) is not needed for manual bookings
            // because payment has not yet been collected — it will fire when the customer pays.

            return [
                'success'           => true,
                'booking_id'        => $booking_id,
                'booking_number'    => $booking_number,
                'booking_reference' => $booking_reference,
                'amount'            => $amount_data['amount'],
                'currency'          => $amount_data['currency'],
            ];

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('booking_failed', $e->getMessage());
        }
    }

    /**
     * Extract questionnaire / detail fields from a raw booking data array.
     *
     * Uses BookingFields registry so the list of fields stays in sync
     * across all consumers automatically.
     *
     * @param array $booking_data Raw booking payload.
     * @return array Sanitized detail fields.
     */
    private function extract_booking_details($booking_data)
    {
        $details = [];

        foreach (\HMWEvents\Registry\RegistrationFieldRegistry::booking_details_fields() as $key => $field) {
            $value = $booking_data[ $field['form_name'] ] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($field['type'] === 'checkbox') {
                $details[$key] = ($value === '1') ? 'true' : 'false';
            } elseif ($field['type'] === 'textarea') {
                $details[$key] = sanitize_textarea_field((string) $value);
            } else {
                $details[$key] = sanitize_text_field((string) $value);
            }
        }

        return $details;
    }
}
