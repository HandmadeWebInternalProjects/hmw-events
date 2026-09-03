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
use HMWEvents\Registry\RegistrationFieldRegistry;
use HMWEvents\Services\AttendancePricingService;
use HMWEvents\Services\EventFormFieldsResolver;
use HMWEvents\Services\FormConfigResolver;

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
     *   @type int    $event_id            Required.
     *   @type string $dietary_requirements
     * }
     * @return array|\WP_Error Result array or error.
     */
    public function create_manual_booking($booking_data)
    {
        global $wpdb;

        $event_id = intval($booking_data['event_id'] ?? 0);
        if (!$event_id) {
            return new \WP_Error('missing_field', 'event_id is required');
        }

        $raw_attendees = is_array($booking_data['attendees'] ?? null) ? $booking_data['attendees'] : [];

        $first_name = sanitize_text_field($this->resolve_customer_field($booking_data, 'first_name', $raw_attendees));
        $last_name  = sanitize_text_field($this->resolve_customer_field($booking_data, 'last_name', $raw_attendees));
        $email      = sanitize_email($this->resolve_customer_field($booking_data, 'email', $raw_attendees));
        $phone      = sanitize_text_field($this->resolve_customer_field($booking_data, 'phone', $raw_attendees));

        if (trim($first_name . ' ' . $last_name) === '' && trim($email) === '') {
            return new \WP_Error('missing_field', __('Customer name and email are required.', 'hmw-events'));
        }

        if ($email !== '') {
            $cap_check = \HMWEvents\Helpers\EventHelper::check_registrant_cap($event_id, $email);
            if (is_wp_error($cap_check)) {
                return $cap_check;
            }
        }

        $attendance_type = sanitize_key((string) ($booking_data['attendance_type'] ?? '')) ?: 'individual';
        $attendees = $this->normalize_attendees($raw_attendees, $event_id);
        $booking_data['attendees'] = $attendees;

        $selection_check = $this->validate_attendance_selection($event_id, $attendance_type, $attendees);
        if (is_wp_error($selection_check)) {
            return $selection_check;
        }

        $wpdb->query('START TRANSACTION');

        try {
            $session_ids = array_values(array_filter(array_map('intval', (array) ($booking_data['session_ids'] ?? []))));
            $session_booking = null;
            if ($session_ids) {
                $session_booking = (new \HMWEvents\Services\SessionBookingService())
                    ->expand_selection($event_id, $session_ids, null, max(1, $ticket_quantity));
                if (is_wp_error($session_booking)) {
                    throw new \Exception($session_booking->get_error_message());
                }
            }

            // Build full name for customer post title
            $full_name  = trim($first_name . ' ' . $last_name);
            if ($full_name === '') {
                $full_name = $email;
            }

            // 1. Create or update the customer post
            $customer_id = $this->get_or_create_customer_post([
                'customer_name'       => $full_name,
                'registrant_email'      => $email,
                'customer_phone'      => $phone,
                'customer_first_name' => $first_name,
                'customer_last_name'  => $last_name,
                'street_address'      => $booking_data['street_address'] ?? '',
                'city'                => $booking_data['city'] ?? '',
                'postcode'            => $booking_data['postcode'] ?? '',
            ]);

            if (is_wp_error($customer_id)) {
                throw new \Exception($customer_id->get_error_message());
            }

            $this->persist_configured_fields($customer_id, $booking_data, $event_id);

            if (!empty($attendees[0])) {
                if ($attendees[0]['date_of_birth'] !== '') {
                    update_post_meta($customer_id, 'registrant_date_of_birth', $attendees[0]['date_of_birth']);
                }
                update_post_meta($customer_id, 'registrant_attendee_role', $attendees[0]['role']);
            }

            // 2. Check event and option capacity
            $ticket_quantity = !empty($attendees) ? count($attendees) : max(1, (int) ($booking_data['ticket_quantity'] ?? 1));

            $capacity_check = EventHelper::check_booking_capacity($event_id, $attendance_type, 1, $ticket_quantity);
            if (is_wp_error($capacity_check)) {
                throw new \Exception($capacity_check->get_error_message());
            }

            $attendance_option_id = EventHelper::resolve_attendance_option_id($event_id, $attendance_type);

            // 3. Resolve full-price amount (manual bookings always use full price)
            $amount_data = $this->calculate_booking_amount($event_id, false, $attendance_type, $attendees);
            if (is_wp_error($amount_data)) {
                throw new \Exception($amount_data->get_error_message());
            }

            // 4. Create booking group
            $booking_reference = $this->generate_booking_reference();
            $booking_number    = $this->generate_booking_number();

            $booking_group_id = $this->create_booking_group([
                'booking_reference' => $booking_reference,
                'customer_post_id'  => $customer_id,
                'booking_type'      => $session_booking['booking_type'] ?? 'single',
                'payment_type'      => 'full',
                'total_courses'     => $session_booking ? count($session_booking['rows']) : 1,
                'total_amount'      => $amount_data['amount'],
                'payment_status'    => 'pending',
                'metadata'          => array_merge(['booking_source' => 'manual'], $session_booking ? [
                    'series_root' => $event_id,
                    'mode'        => $session_booking['booking_type'] === 'recurring' ? 'track' : 'individual',
                    'slot'        => $session_booking['slot'],
                    'session_ids' => $session_booking['session_ids'],
                ] : []),
            ]);

            if (is_wp_error($booking_group_id)) {
                throw new \Exception($booking_group_id->get_error_message());
            }

            // 5. Build questionnaire details to store alongside the booking
            $booking_details = $this->extract_booking_details($booking_data, $attendees);

            // 6. Create the booking — confirmed immediately, payment pending
            $booking_id = $this->create_booking([
                'booking_group_id' => $booking_group_id,
                'booking_number' => $booking_number,
                'event_post_id'   => $event_id,
                'customer_post_id' => $customer_id,
                'attendance_option_id' => $attendance_option_id,
                'ticket_type'      => 'full',
                'ticket_quantity'  => $ticket_quantity,
                'booking_amount'   => $amount_data['amount'],
                'status'           => 'confirmed',
                'payment_status'   => 'pending',
                'booking_source'   => 'manual',
                'booking_details'  => $booking_details,
                'suppress_created_action' => !empty($session_booking),
            ]);

            if (is_wp_error($booking_id)) {
                throw new \Exception($booking_id->get_error_message());
            }

            // Additional session rows for multi-session manual bookings.
            $extra_booking_ids = [];
            if ($session_booking) {
                foreach ($session_booking['rows'] as $row) {
                    if ((int) $row['event_post_id'] === $event_id) {
                        continue;
                    }
                    $extra_booking_id = $this->create_booking([
                        'booking_group_id' => $booking_group_id,
                        'booking_number' => $this->generate_booking_number(),
                        'event_post_id' => (int) $row['event_post_id'],
                        'customer_post_id' => $customer_id,
                        'attendance_option_id' => $attendance_option_id,
                        'ticket_type' => 'full',
                        'ticket_quantity' => $ticket_quantity,
                        'booking_amount' => (float) ($row['booking_amount'] ?? 0),
                        'status' => 'confirmed',
                        'payment_status' => 'pending',
                        'booking_source' => 'manual',
                        'suppress_created_action' => true,
                    ]);
                    if ($extra_booking_id && !is_wp_error($extra_booking_id)) {
                        $extra_booking_ids[] = (int) $extra_booking_id;
                        $this->update_course_availability((int) $row['event_post_id'], $ticket_quantity);
                        $this->add_booking_history($extra_booking_id, null, 'confirmed', 'Manual multi-session booking row');
                    }
                }
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

            // 8. Decrement event availability
            $this->update_course_availability($event_id, $ticket_quantity);

            // 9. Record booking history
            $this->add_booking_history($booking_id, null, 'confirmed', 'Manual booking created by admin/organizer');

            $wpdb->query('COMMIT');

            if ($session_booking) {
                try {
                    do_action(
                        'hmwevents_group_booking_created',
                        $booking_group_id,
                        array_merge([$booking_id], $extra_booking_ids),
                        $booking_id,
                        $booking_data
                    );
                } catch (\Throwable $e) {
                    error_log('HMWEvents group confirmation error: ' . $e->getMessage());
                }
            }

            $invite_token = $booking_data['token'] ?? '';
            if ($invite_token) {
                $token_service = new \HMWEvents\Services\InvitationTokenService();
                $token_service->consume($invite_token, $event_id);
            }

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
     * Write configured registrant_meta fields to the customer post using each
     * field's meta_key. Booking_details fields are handled separately by
     * extract_booking_details() and stored in booking_details.form_data.
     *
     * @param int   $customer_id  Customer post ID.
     * @param array $booking_data Raw booking payload.
     * @param int   $event_id     Event post ID.
     */
    private function persist_configured_fields($customer_id, $booking_data, $event_id)
    {
        foreach (EventFormFieldsResolver::for_event($event_id) as $key => $field) {
            if (($field['source'] ?? '') !== RegistrationFieldRegistry::SOURCE_REGISTRANT_META) {
                continue;
            }

            $meta_key = $field['meta_key'] ?? '';
            if ($meta_key === '') {
                continue;
            }

            $value = !empty($field['per_attendee'])
                ? (is_array($booking_data['attendees'] ?? null) ? ($booking_data['attendees'][0][$key] ?? null) : null)
                : $this->field_value($booking_data, $key);
            if ($value === null || $value === '') {
                continue;
            }

            $sanitized = ($field['type'] ?? '') === 'email'
                ? sanitize_email($value)
                : sanitize_text_field((string) $value);

            update_post_meta($customer_id, $meta_key, $sanitized);
        }
    }

    /**
     * Resolve a raw field value from the booking payload by field key first,
     * then by its canonical form name (e.g. first_name -> customer_first_name).
     *
     * @param array  $booking_data Raw booking payload.
     * @param string $key          Configured field key.
     * @return mixed
     */
    private function field_value(array $booking_data, string $key)
    {
        if (array_key_exists($key, $booking_data)) {
            return $booking_data[$key];
        }

        $form_name = EventFormFieldsResolver::canonical_field_name($key);
        if ($form_name !== $key && array_key_exists($form_name, $booking_data)) {
            return $booking_data[$form_name];
        }

        return null;
    }

    /**
     * Resolve a customer identity field (first_name, last_name, email, phone)
     * from the top-level payload or from the primary attendee's per-attendee
     * entry when those fields are configured as per-attendee.
     */
    private function resolve_customer_field(array $booking_data, string $key, array $attendees): string
    {
        $value = $this->field_value($booking_data, $key);
        if ($value !== null && (string) $value !== '') {
            return (string) $value;
        }

        if (isset($attendees[0]) && is_array($attendees[0])) {
            if (isset($attendees[0][$key]) && (string) $attendees[0][$key] !== '') {
                return (string) $attendees[0][$key];
            }
        }

        return '';
    }

    /**
     * Extract questionnaire / detail fields from a raw booking data array.
     *
     * Uses the event's resolved field configuration so the list of fields stays
     * in sync with the frontend V3 form. Registrant-meta fields are written to
     * postmeta by persist_configured_fields(); booking_details fields are stored
     * here, along with the structured attendees/children list when multi-attendee
     * data was submitted — mirroring FormSubmissionService::extract_booking_details().
     *
     * @param array $booking_data Raw booking payload.
     * @param array $attendees    Normalized attendee list.
     * @return array Sanitized detail fields.
     */
    private function extract_booking_details($booking_data, array $attendees = [])
    {
        $event_id = (int) ($booking_data['event_id'] ?? 0);
        $details = [];
        $detail_fields = [];

        foreach (EventFormFieldsResolver::for_event($event_id) as $key => $field) {
            if (($field['source'] ?? '') !== RegistrationFieldRegistry::SOURCE_BOOKING_DETAILS) {
                continue;
            }

            $detail_fields[$key] = $field;
        }

        foreach ($detail_fields as $key => $field) {
            if (!empty($field['per_attendee'])) {
                continue;
            }

            $value = $this->field_value($booking_data, $key);
            if ($value === null) {
                continue;
            }

            $value = (string) $value;

            if (($field['type'] ?? '') === 'checkbox') {
                if (in_array($value, ['1', 'true', 'on'], true)) {
                    $details[$key] = '1';
                }
                continue;
            }

            if ($value === '') {
                continue;
            }

            $details[$key] = $this->sanitize_detail_value($value, (string) ($field['type'] ?? 'text'));
        }

        $config = FormConfigResolver::resolve($event_id);
        $multi_booking = is_array($config) ? ($config['multi_booking'] ?? []) : [];
        $is_parent_children = (($multi_booking['mode'] ?? 'attendees') === 'parent_children');

        if (!empty($multi_booking['enabled']) || count($attendees) > 1) {
            $start = $is_parent_children ? 1 : 0;
            $list = [];

            for ($ai = $start, $total = count($attendees); $ai < $total; $ai++) {
                $child = [];

                if ($is_parent_children && $attendees[$ai]['child_name'] !== '') {
                    $child['child_name'] = $attendees[$ai]['child_name'];
                }

                if ($attendees[$ai]['date_of_birth'] !== '') {
                    $child['date_of_birth'] = $attendees[$ai]['date_of_birth'];
                }

                foreach ($detail_fields as $key => $field) {
                    if (empty($field['per_attendee']) || !isset($attendees[$ai][$key])) {
                        continue;
                    }

                    $child[$key] = $attendees[$ai][$key];
                }

                $list[] = $child;
            }

            $details[$is_parent_children ? 'children' : 'attendees'] = $list;
        }

        return $details;
    }

    private function sanitize_detail_value(string $value, string $type): string
    {
        if ($type === 'textarea') {
            return sanitize_textarea_field($value);
        }

        if ($type === 'email') {
            return sanitize_email($value);
        }

        return sanitize_text_field($value);
    }

    /**
     * Normalize the raw attendees payload into a sanitized list of
     * role / date_of_birth / child_name entries plus per-attendee
     * booking_details values.
     */
    private function normalize_attendees($raw_attendees, int $event_id): array
    {
        if (!is_array($raw_attendees)) {
            return [];
        }

        $pricing = new AttendancePricingService();
        $detail_fields = [];

        foreach (EventFormFieldsResolver::for_event($event_id) as $key => $field) {
            if (!empty($field['per_attendee'])) {
                $detail_fields[$key] = $field;
            }
        }

        $attendees = [];

        foreach (array_values($raw_attendees) as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $entry = [
                'role'          => $pricing->attendee_role([
                    'role' => $raw['attendee_role'] ?? $raw['role'] ?? '',
                ]),
                'date_of_birth' => sanitize_text_field((string) ($raw['date_of_birth'] ?? '')),
                'child_name'    => sanitize_text_field((string) ($raw['child_name'] ?? '')),
            ];

            foreach ($detail_fields as $key => $field) {
                if (!isset($raw[$key])) {
                    continue;
                }

                $value = (string) $raw[$key];
                if ($value === '') {
                    continue;
                }

                $entry[$key] = $this->sanitize_detail_value($value, (string) ($field['type'] ?? 'text'));
            }

            $attendees[] = $entry;
        }

        return $attendees;
    }

    /**
     * Validate the selected attendance option against active options and the
     * configured composition, mirroring FormSubmissionService's validation.
     *
     * @return true|\WP_Error
     */
    private function validate_attendance_selection(int $event_id, string $attendance_type, array $attendees)
    {
        $active_options = EventHelper::get_active_attendance_options($event_id);

        if (!empty($active_options) && EventHelper::resolve_attendance_option($event_id, $attendance_type) === null) {
            return new \WP_Error('invalid_attendance_type', __('The selected attendance option is no longer available.', 'hmw-events'));
        }

        $pricing = new AttendancePricingService();
        $option_config = $pricing->resolve_configuration($event_id, $attendance_type);

        $config = FormConfigResolver::resolve($event_id);
        $multi_booking = is_array($config) ? ($config['multi_booking'] ?? []) : [];
        $is_parent_children = (($multi_booking['mode'] ?? 'attendees') === 'parent_children');

        if (!empty($multi_booking['enabled']) && !$is_parent_children) {
            $option_config['composition']['min_attendees'] = max(1, (int) ($multi_booking['min'] ?? 1));
            $option_config['composition']['max_attendees'] = max(
                (int) ($option_config['composition']['min_attendees'] ?? 1),
                (int) ($multi_booking['max'] ?? 10)
            );
            $option_config['composition']['min_children'] = 0;
            $option_config['composition']['min_adults'] = 0;
        }

        return $pricing->validate_composition(
            $option_config['composition'],
            $attendees ?: [['role' => AttendancePricingService::ROLE_ADULT]]
        );
    }
}
