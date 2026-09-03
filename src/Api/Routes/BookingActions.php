<?php

/**
 * Booking Actions REST API Endpoint.
 *
 * Handles booking management actions like transfer, resend emails, etc.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Api\Routes;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use HMWEvents\PostTypes\Event;
use HMWEvents\Registry\RegistrationFieldRegistry;
use HMWEvents\Services\Emails\EmailService;
use HMWEvents\Services\Gateways\ManualBookingGateway;
use HMWEvents\Services\BookingDetailsService;
use HMWEvents\Services\DatabaseService;
use HMWEvents\Services\EventFormFieldsResolver;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Booking Actions API Class.
 */
class BookingActions
{
    /**
     * Initialize the API endpoint.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->register_routes();
    }

    /**
     * Register REST API routes.
     *
     * @since 1.0.0
     */
    public function register_routes()
    {
        // Transfer booking to another event
        register_rest_route('hmwevents/v1', '/booking/transfer', [
            'methods' => 'POST',
            'callback' => [$this, 'transfer_booking'],
            'permission_callback' => [$this, 'check_educator_permission'],
            'args' => [
                'booking_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($value) {
                        return is_numeric($value) && $value > 0;
                    },
                ],
                'new_event_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($value) {
                        return is_numeric($value) && $value > 0;
                    },
                ],
            ],
        ]);

        // Resend booking confirmation email
        register_rest_route('hmwevents/v1', '/booking/resend-confirmation', [
            'methods' => 'POST',
            'callback' => [$this, 'resend_confirmation'],
            'permission_callback' => [$this, 'check_educator_permission'],
            'args' => [
                'booking_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($value) {
                        return is_numeric($value) && $value > 0;
                    },
                ],
            ],
        ]);

        // Resend payment receipt email
        register_rest_route('hmwevents/v1', '/booking/resend-receipt', [
            'methods' => 'POST',
            'callback' => [$this, 'resend_receipt'],
            'permission_callback' => [$this, 'check_educator_permission'],
            'args' => [
                'booking_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($value) {
                        return is_numeric($value) && $value > 0;
                    },
                ],
            ],
        ]);

        // Create a manual booking (no payment collected)
        register_rest_route('hmwevents/v1', '/booking/create-manual', [
            'methods' => 'POST',
            'callback' => [$this, 'create_manual_booking'],
            'permission_callback' => [$this, 'check_educator_permission'],
            'args' => [
                'event_id'          => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'customer_first_name' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'customer_last_name'  => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'registrant_email'      => ['required' => false, 'type' => 'string', 'format' => 'email', 'sanitize_callback' => 'sanitize_email'],
                'customer_phone'      => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'street_address'      => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'city'                => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'postcode'            => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'dietary_requirements' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'attendance_type'     => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_key'],
                'ticket_quantity'     => ['required' => false, 'type' => 'integer', 'minimum' => 1],
                'attendee_count'      => ['required' => false, 'type' => 'integer', 'minimum' => 1],
                'attendees'           => ['required' => false, 'type' => 'array', 'sanitize_callback' => [$this, 'sanitize_attendees_arg']],
                'session_ids'         => ['required' => false, 'type' => 'array', 'sanitize_callback' => [$this, 'sanitize_session_ids_arg']],
                'send_confirmation'   => ['required' => false, 'type' => 'boolean', 'default' => true],
            ],
        ]);

        // Update an existing booking's customer details and questionnaire responses
        register_rest_route('hmwevents/v1', '/booking/update', [
            'methods' => 'POST',
            'callback' => [$this, 'update_booking'],
            'permission_callback' => [$this, 'check_educator_permission'],
            'args' => array_merge(
                ['booking_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1]],
                $this->build_edit_args()
            ),
        ]);

        // Send a payment link to a customer for a pending booking
        register_rest_route('hmwevents/v1', '/booking/send-payment-link', [
            'methods' => 'POST',
            'callback' => [$this, 'send_payment_link'],
            'permission_callback' => [$this, 'check_educator_permission'],
            'args' => [
                'booking_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'minimum' => 1,
                ],
            ],
        ]);

        register_rest_route('hmwevents/v1', '/booking/mark-as-paid', [
            'methods'             => 'POST',
            'callback'            => [$this, 'mark_as_paid'],
            'permission_callback' => [$this, 'check_educator_permission'],
            'args' => [
                'booking_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
            ],
        ]);

        register_rest_route('hmwevents/v1', '/booking/resend-invoice', [
            'methods'             => 'POST',
            'callback'            => [$this, 'resend_invoice'],
            'permission_callback' => [$this, 'check_educator_permission'],
            'args' => [
                'booking_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
            ],
        ]);

        register_rest_route('hmwevents/v1', '/booking/mark-invoice-paid', [
            'methods'             => 'POST',
            'callback'            => [$this, 'mark_invoice_paid'],
            'permission_callback' => [$this, 'check_educator_permission'],
            'args' => [
                'booking_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'reference'  => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    /**
     * Check if user has permission to manage bookings.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request Current request.
     * @return bool|WP_Error True if permitted, error otherwise.
     */
    public function check_educator_permission($request)
    {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_forbidden',
                __('You must be logged in to perform this action.', 'hmw-events'),
                ['status' => 401]
            );
        }

        // Admins and educators can manage bookings
        if (current_user_can('manage_options') || current_user_can('edit_posts')) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            __('You do not have permission to perform this action.', 'hmw-events'),
            ['status' => 403]
        );
    }

    /**
     * Transfer booking to another course.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request Current request.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function transfer_booking($request)
    {
        global $wpdb;

        $bookings_table = DatabaseService::get_table_name('bookings');

        $booking_id = $request->get_param('booking_id');
        $new_event_id = $request->get_param('new_event_id');
        $current_user_id = get_current_user_id();

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, c.post_author
            FROM {$bookings_table} b
            INNER JOIN {$wpdb->posts} c ON b.event_post_id = c.ID
            WHERE b.id = %d
            AND b.deleted_at IS NULL
        ", $booking_id));

        if (!$booking) {
            return new WP_Error(
                'booking_not_found',
                __('Booking not found.', 'hmw-events'),
                ['status' => 404]
            );
        }

        if (!current_user_can('manage_options') && $booking->post_author != $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only transfer bookings for your own events.', 'hmw-events'),
                ['status' => 403]
            );
        }

        $new_event = get_post($new_event_id);
        if (!$new_event || $new_event->post_type !== Event::POST_TYPE) {
            return new WP_Error(
                'invalid_course',
                __('Invalid event selected.', 'hmw-events'),
                ['status' => 400]
            );
        }

        if (!current_user_can('manage_options') && $new_event->post_author != $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only transfer to your own events.', 'hmw-events'),
                ['status' => 403]
            );
        }

        $updated = $wpdb->update(
            $bookings_table,
            ['event_post_id' => $new_event_id],
            ['id' => $booking_id],
            ['%d'],
            ['%d']
        );

        error_log('Booking transfer error: ' . $wpdb->last_error);

        if ($updated === false) {
            return new WP_Error(
                'update_failed',
                __('Failed to transfer booking.', 'hmw-events'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => sprintf(
                __('Booking transferred to %s successfully.', 'hmw-events'),
                get_the_title($new_event_id)
            ),
        ], 200);
    }

    /**
     * Resend booking confirmation email.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request Current request.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function resend_confirmation($request)
    {
        global $wpdb;

        $bookings_table = DatabaseService::get_table_name('bookings');
        $booking_id = $request->get_param('booking_id');
        $current_user_id = get_current_user_id();

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, b.registrant_post_id AS customer_post_id, c.post_author, c.post_title as course_name,
                   cust.post_title as customer_name
            FROM {$bookings_table} b
            INNER JOIN {$wpdb->posts} c ON b.event_post_id = c.ID
            INNER JOIN {$wpdb->posts} cust ON b.registrant_post_id = cust.ID
            WHERE b.id = %d
            AND b.deleted_at IS NULL
        ", $booking_id));

        if (!$booking) {
            return new WP_Error(
                'booking_not_found',
                __('Booking not found.', 'hmw-events'),
                ['status' => 404]
            );
        }

        // Security check
        if (!current_user_can('manage_options') && $booking->post_author != $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only resend confirmations for your own courses.', 'hmw-events'),
                ['status' => 403]
            );
        }

        // Check booking status
        if ($booking->status !== 'confirmed') {
            return new WP_Error(
                'invalid_status',
                __('Only confirmed bookings can have confirmation emails resent.', 'hmw-events'),
                ['status' => 400]
            );
        }

        // Get customer email
        $registrant_email = get_post_meta($booking->customer_post_id, 'registrant_email', true);

        if (!$registrant_email) {
            return new WP_Error(
                'no_email',
                __('Customer email not found.', 'hmw-events'),
                ['status' => 400]
            );
        }

        $email_service = new EmailService();
        $queued = $email_service->queue_booking_confirmation((int) $booking_id);

        if (!$queued) {
            return new WP_Error(
                'email_failed',
                __('Failed to queue confirmation email. Please try again.', 'hmw-events'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => sprintf(
                __('Confirmation email sent to %s successfully.', 'hmw-events'),
                $registrant_email
            ),
        ], 200);
    }

    /**
     * Resend payment receipt email.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request Current request.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function resend_receipt($request)
    {
        global $wpdb;

        $bookings_table = DatabaseService::get_table_name('bookings');
        $booking_groups_table = DatabaseService::get_table_name('booking_groups');
        $payment_transactions_table = DatabaseService::get_table_name('payment_transactions');

        $booking_id = $request->get_param('booking_id');
        $current_user_id = get_current_user_id();

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, b.registrant_post_id AS customer_post_id, c.post_author, c.post_title as course_name,
                   cust.post_title as customer_name,
                   pt.gateway_transaction_id, pt.amount as paid_amount
            FROM {$bookings_table} b
            INNER JOIN {$wpdb->posts} c ON b.event_post_id = c.ID
            INNER JOIN {$wpdb->posts} cust ON b.registrant_post_id = cust.ID
            INNER JOIN {$booking_groups_table} bg ON b.booking_group_id = bg.id
            LEFT JOIN {$payment_transactions_table} pt ON bg.id = pt.booking_group_id
            WHERE b.id = %d
            AND b.deleted_at IS NULL
        ", $booking_id));

        if (!$booking) {
            return new WP_Error(
                'booking_not_found',
                __('Booking not found.', 'hmw-events'),
                ['status' => 404]
            );
        }

        // Security check
        if (!current_user_can('manage_options') && $booking->post_author != $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only resend receipts for your own courses.', 'hmw-events'),
                ['status' => 403]
            );
        }

        // Check payment status
        if ($booking->payment_status !== 'paid') {
            return new WP_Error(
                'invalid_status',
                __('Only paid bookings can have receipts resent.', 'hmw-events'),
                ['status' => 400]
            );
        }

        // Get customer email
        $registrant_email = get_post_meta($booking->customer_post_id, 'registrant_email', true);

        if (!$registrant_email) {
            return new WP_Error(
                'no_email',
                __('Customer email not found.', 'hmw-events'),
                ['status' => 400]
            );
        }

        $email_service = new EmailService();
        $queued = $email_service->queue_payment_receipt((int) $booking_id);

        if (!$queued) {
            return new WP_Error(
                'email_failed',
                __('Failed to queue receipt email. Please try again.', 'hmw-events'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => sprintf(
                __('Payment receipt sent to %s successfully.', 'hmw-events'),
                $registrant_email
            ),
        ], 200);
    }

    /**
     * Create a manual booking without collecting payment.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request Current request.
     * @return WP_REST_Response|WP_Error
     */
    public function create_manual_booking($request)
    {
        $event_id        = $request->get_param('event_id');
        $current_user_id = get_current_user_id();

        $event = get_post($event_id);
        if (!$event || $event->post_type !== Event::POST_TYPE) {
            return new WP_Error('invalid_event', __('Invalid event.', 'hmw-events'), ['status' => 400]);
        }

        if (!current_user_can('manage_options') && $event->post_author != $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only add bookings to your own events.', 'hmw-events'),
                ['status' => 403]
            );
        }

        $booking_data = array_merge($request->get_params(), [
            'organizer_id' => (int) $event->post_author,
        ]);

        $gateway = new ManualBookingGateway();

        $result = $gateway->create_manual_booking($booking_data);

        if (is_wp_error($result)) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                ['status' => 400]
            );
        }

        return new WP_REST_Response([
            'success'           => true,
            'booking_id'        => $result['booking_id'],
            'booking_number'    => $result['booking_number'],
            'booking_reference' => $result['booking_reference'],
            'amount'            => $result['amount'],
            'currency'          => $result['currency'],
            'message'           => sprintf(
                __('Booking %s created successfully. A confirmation email has been sent to the customer.', 'hmw-events'),
                $result['booking_number']
            ),
        ], 201);
    }

    /**
     * Sanitize the nested attendees array for the create-manual endpoint.
     *
     * @param mixed $value Raw attendees payload.
     * @return array<int, array<string, string>>
     */
    public function sanitize_attendees_arg($value)
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static function ($attendee) {
            if (!is_array($attendee)) {
                return [];
            }

            return array_map(static fn($v) => is_scalar($v) ? sanitize_text_field((string) $v) : '', $attendee);
        }, $value));
    }

    /**
     * Sanitize the session_ids array for the create-manual endpoint.
     *
     * @param mixed $value Raw session IDs payload.
     * @return array<int, int>
     */
    public function sanitize_session_ids_arg($value)
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value), fn($id) => $id > 0)));
    }

    /**
     * Build REST API args for the booking/update endpoint from the field registry.
     *
     * @return array<string, array>
     */
    private function build_edit_args(): array
    {
        $args = [];
        foreach (\HMWEvents\Registry\RegistrationFieldRegistry::for_admin_display() as $key => $field) {
            $arg = ['required' => false, 'type' => 'string'];
            if ($field['type'] === 'email') {
                $arg['sanitize_callback'] = 'sanitize_email';
                $arg['validate_callback'] = fn($v) => empty($v) || is_email($v);
            } else {
                $arg['sanitize_callback'] = 'sanitize_text_field';
            }
            if (!empty($field['options'])) {
                $arg['enum'] = array_values(array_filter(
                    array_keys($field['options']),
                    fn($v) => $v !== ''
                ));
            }
            $args[$key] = $arg;
        }
        return $args;
    }

    /**
     * Update an existing booking's customer details and questionnaire responses.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request Current request.
     * @return WP_REST_Response|WP_Error
     */
    public function update_booking($request)
    {
        global $wpdb;

        $bookings_table  = DatabaseService::get_table_name('bookings');
        $booking_id      = $request->get_param('booking_id');
        $current_user_id = get_current_user_id();

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, c.post_author
            FROM {$bookings_table} b
            INNER JOIN {$wpdb->posts} c ON b.event_post_id = c.ID
            WHERE b.id = %d AND b.deleted_at IS NULL
        ", $booking_id));

        if (!$booking) {
            return new WP_Error('booking_not_found', __('Booking not found.', 'hmw-events'), ['status' => 404]);
        }

        if (!current_user_can('manage_options') && $booking->post_author != $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only edit bookings for your own courses.', 'hmw-events'),
                ['status' => 403]
            );
        }

        $customer_id = (int) $booking->registrant_post_id;
        $event_id    = (int) $booking->event_post_id;

        $details_service    = new BookingDetailsService();
        $existing_form_data = $details_service->get_form_data($booking_id) ?? [];
        $updated_form_data  = $existing_form_data;
        $name_fields_changed = false;

        $fields = EventFormFieldsResolver::for_event($event_id);

        foreach ($fields as $key => $field) {
            $value = $request->get_param($key);
            if ($value === null) {
                continue;
            }

            $type   = $field['type'] ?? 'text';
            $source = $field['source'] ?? '';

            if ($source === RegistrationFieldRegistry::SOURCE_REGISTRANT_META) {
                $meta_key = $field['meta_key'] ?? '';
                if ($meta_key === '') {
                    continue;
                }

                // Skip invalid email rather than clearing a valid stored value
                if ($type === 'email' && !empty($value) && !is_email($value)) {
                    continue;
                }

                $sanitized = $type === 'email' ? sanitize_email($value) : sanitize_text_field((string) $value);
                update_post_meta($customer_id, $meta_key, $sanitized);

                if (in_array($key, ['first_name', 'last_name'], true)) {
                    $name_fields_changed = true;
                }
            } else {
                if ($type === 'checkbox') {
                    if (in_array((string) $value, ['1', 'true', 'on'], true)) {
                        $updated_form_data[$key] = '1';
                    } else {
                        unset($updated_form_data[$key]);
                    }
                } elseif ($type === 'textarea') {
                    $updated_form_data[$key] = sanitize_textarea_field((string) $value);
                } elseif ($type === 'email') {
                    $updated_form_data[$key] = sanitize_email($value);
                } else {
                    $updated_form_data[$key] = sanitize_text_field((string) $value);
                }
            }
        }

        // Rebuild customer post title if a name field changed
        if ($name_fields_changed) {
            $first = get_post_meta($customer_id, 'registrant_first_name', true);
            $last  = get_post_meta($customer_id, 'registrant_last_name', true);
            $title = trim("$first $last");
            if ($title) {
                wp_update_post(['ID' => $customer_id, 'post_title' => $title]);
            }
        }

        if ($updated_form_data !== $existing_form_data) {
            $details_service->save_booking_details($booking_id, $updated_form_data);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Booking updated successfully.', 'hmw-events'),
        ], 200);
    }

    /**
     * Send a payment link to the customer for a pending booking.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request Current request.
     * @return WP_REST_Response|WP_Error
     */
    public function send_payment_link($request)
    {
        global $wpdb;

        $bookings_table       = DatabaseService::get_table_name('bookings');
        $booking_groups_table = DatabaseService::get_table_name('booking_groups');

        $booking_id      = $request->get_param('booking_id');
        $current_user_id = get_current_user_id();

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.id, b.booking_number, b.payment_status, b.currency,
                   b.event_post_id, b.deleted_at,
                   bg.id AS booking_group_id, bg.registrant_post_id AS customer_post_id,
                   bg.payment_type AS group_payment_type, bg.total_amount,
                   c.post_author, c.post_title AS course_name
            FROM {$bookings_table} b
            INNER JOIN {$booking_groups_table} bg ON b.booking_group_id = bg.id
            INNER JOIN {$wpdb->posts} c ON b.event_post_id = c.ID
            WHERE b.id = %d AND b.deleted_at IS NULL
        ", $booking_id));

        if (!$booking) {
            return new WP_Error('booking_not_found', __('Booking not found.', 'hmw-events'), ['status' => 404]);
        }

        if (!current_user_can('manage_options') && $booking->post_author != $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only send payment links for your own courses.', 'hmw-events'),
                ['status' => 403]
            );
        }

        // Determine payment link type: pending/failed, or remaining balance for paid deposits.
        $is_remaining = (
            $booking->payment_status === 'paid'
            && \HMWEvents\Helpers\Booking::needs_remaining_payment_link($booking)
        );

        if (!in_array($booking->payment_status, ['pending', 'failed'], true) && !$is_remaining) {
            return new WP_Error(
                'invalid_status',
                __('A payment link can only be sent for bookings with pending or failed payment status, or paid deposit bookings with outstanding balance.', 'hmw-events'),
                ['status' => 400]
            );
        }

        $result = \HMWEvents\Helpers\Booking::send_payment_link_email($booking, $is_remaining);

        if (is_wp_error($result)) {
            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                ['status' => 400]
            );
        }

        if (!$result) {
            return new WP_Error('email_failed', __('Failed to queue payment link email.', 'hmw-events'), ['status' => 500]);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => sprintf(
                __('Payment link sent to %s successfully.', 'hmw-events'),
                get_post_meta((int) $booking->customer_post_id, 'registrant_email', true)
            ),
        ], 200);
    }

    public function mark_as_paid($request)
    {
        global $wpdb;

        $booking_id      = $request->get_param('booking_id');
        $current_user_id = get_current_user_id();

        $bookings_table = DatabaseService::get_table_name('bookings');
        $payment_table  = DatabaseService::get_table_name('payment_transactions');
        $booking_groups_table = DatabaseService::get_table_name('booking_groups');
        $history_table  = DatabaseService::get_table_name('booking_history');

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.id, b.booking_number, b.payment_status, b.status,
                   b.event_post_id, b.deleted_at,
                   bg.id AS booking_group_id,
                   pt.id AS transaction_id, pt.gateway_transaction_id,
                   c.post_author
            FROM {$bookings_table} b
            INNER JOIN {$booking_groups_table} bg ON b.booking_group_id = bg.id
            INNER JOIN {$wpdb->posts} c ON b.event_post_id = c.ID
            LEFT JOIN {$payment_table} pt
                   ON bg.id = pt.booking_group_id
                  AND pt.transaction_type = 'charge'
                  AND pt.status = 'pending'
            WHERE b.id = %d AND b.deleted_at IS NULL
        ", $booking_id));

        if (!$booking) {
            return new WP_Error('booking_not_found', __('Booking not found.', 'hmw-events'), ['status' => 404]);
        }

        if (!current_user_can('manage_options') && $booking->post_author != $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only manage bookings for your own events.', 'hmw-events'),
                ['status' => 403]
            );
        }

        if (empty($booking->gateway_transaction_id) || strpos($booking->gateway_transaction_id, 'manual_') !== 0) {
            return new WP_Error(
                'invalid_booking',
                __('Only manually created bookings can be marked as paid.', 'hmw-events'),
                ['status' => 400]
            );
        }

        if (!in_array($booking->payment_status, ['pending', 'failed'], true)) {
            return new WP_Error(
                'invalid_status',
                __('This booking cannot be marked as paid in its current payment state.', 'hmw-events'),
                ['status' => 400]
            );
        }

        $wpdb->query('START TRANSACTION');

        try {
            $wpdb->update(
                $payment_table,
                ['status' => 'succeeded'],
                ['id' => $booking->transaction_id],
                ['%s'],
                ['%d']
            );

            $wpdb->update(
                $booking_groups_table,
                ['payment_status' => 'paid'],
                ['id' => $booking->booking_group_id],
                ['%s'],
                ['%d']
            );

            $wpdb->update(
                $bookings_table,
                ['payment_status' => 'paid'],
                ['id' => $booking_id],
                ['%s'],
                ['%d']
            );

            $wpdb->insert(
                $history_table,
                [
                    'booking_id'    => $booking_id,
                    'field_changed' => 'payment_status',
                    'old_value'     => $booking->payment_status,
                    'new_value'     => 'paid',
                    'changed_by'    => get_current_user_id(),
                    'change_reason' => sprintf('Payment marked as paid by %s', wp_get_current_user()->display_name),
                    'created_at'    => current_time('mysql'),
                ],
                ['%d', '%s', '%s', '%s', '%d', '%s', '%s']
            );

            do_action('hmwevents_booking_confirmed', (int) $booking_id, []);
            do_action('hmwevents_payment_received', (int) $booking_id, [
                'payment_status' => 'paid',
            ]);

            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mark_as_paid_failed', $e->getMessage(), ['status' => 500]);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Payment marked as paid successfully.', 'hmw-events'),
        ], 200);
    }

    public function resend_invoice($request)
    {
        global $wpdb;

        $booking_id      = $request->get_param('booking_id');
        $current_user_id = get_current_user_id();

        $bookings_table = DatabaseService::get_table_name('bookings');

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.id, b.booking_group_id, b.event_post_id, b.deleted_at, c.post_author
            FROM {$bookings_table} b
            INNER JOIN {$wpdb->posts} c ON b.event_post_id = c.ID
            WHERE b.id = %d AND b.deleted_at IS NULL
        ", $booking_id));

        if (!$booking) {
            return new WP_Error('booking_not_found', __('Booking not found.', 'hmw-events'), ['status' => 404]);
        }

        if (!current_user_can('manage_options') && (int) $booking->post_author !== $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only resend invoices for your own events.', 'hmw-events'),
                ['status' => 403]
            );
        }

        $invoice_service = new \HMWEvents\Services\InvoiceService();
        $result = $invoice_service->resend_invoice((int) $booking_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Invoice resent successfully.', 'hmw-events'),
        ], 200);
    }

    public function mark_invoice_paid($request)
    {
        global $wpdb;

        $booking_id      = $request->get_param('booking_id');
        $reference       = (string) $request->get_param('reference');
        $current_user_id = get_current_user_id();

        $bookings_table = DatabaseService::get_table_name('bookings');

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.id, b.booking_group_id, b.event_post_id, b.deleted_at, c.post_author
            FROM {$bookings_table} b
            INNER JOIN {$wpdb->posts} c ON b.event_post_id = c.ID
            WHERE b.id = %d AND b.deleted_at IS NULL
        ", $booking_id));

        if (!$booking) {
            return new WP_Error('booking_not_found', __('Booking not found.', 'hmw-events'), ['status' => 404]);
        }

        if (!current_user_can('manage_options') && (int) $booking->post_author !== $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only reconcile invoices for your own events.', 'hmw-events'),
                ['status' => 403]
            );
        }

        $invoice_service = new \HMWEvents\Services\InvoiceService();
        $result = $invoice_service->mark_paid((int) $booking->booking_group_id, $reference);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Invoice marked as paid and receipt email queued.', 'hmw-events'),
        ], 200);
    }
}
