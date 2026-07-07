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
use HMWEvents\Services\Emails\EmailService;
use HMWEvents\Services\Gateways\ManualBookingGateway;
use HMWEvents\Services\BookingDetailsService;

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
        // Transfer booking to another course
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
                'new_course_id' => [
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
                'course_id'          => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'customer_first_name' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'customer_last_name'  => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'registrant_email'      => ['required' => true, 'type' => 'string', 'format' => 'email', 'sanitize_callback' => 'sanitize_email'],
                'customer_phone'      => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'partner_name'        => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'street_address'      => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'city'                => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'postcode'            => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'due_date'            => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'health_fund'         => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'dietary_requirements' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'first_baby'          => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
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

        $booking_id = $request->get_param('booking_id');
        $new_course_id = $request->get_param('new_course_id');
        $current_user_id = get_current_user_id();

        // Get booking with course info
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, c.post_author
            FROM {$wpdb->prefix}hmwevents_bookings b
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
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

        // Security check: Only course owner or admin can transfer
        if (!current_user_can('manage_options') && $booking->post_author != $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only transfer bookings for your own courses.', 'hmw-events'),
                ['status' => 403]
            );
        }

        // Verify new course exists and is correct post type
        $new_course = get_post($new_course_id);
        if (!$new_course || $new_course->post_type !== 'educator_course') {
            return new WP_Error(
                'invalid_course',
                __('Invalid course selected.', 'hmw-events'),
                ['status' => 400]
            );
        }

        // Check ownership of new course
        if (!current_user_can('manage_options') && $new_course->post_author != $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only transfer to your own courses.', 'hmw-events'),
                ['status' => 403]
            );
        }

        // Update booking
        $updated = $wpdb->update(
            $wpdb->prefix . 'hmwevents_bookings',
            ['course_post_id' => $new_course_id],
            ['id' => $booking_id],
            ['%d'],
            ['%d']
        );

        // Last Error
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
                get_the_title($new_course_id)
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

        $booking_id = $request->get_param('booking_id');
        $current_user_id = get_current_user_id();

        // Get booking with course info
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, c.post_author, c.post_title as course_name,
                   cust.post_title as customer_name
            FROM {$wpdb->prefix}hmwevents_bookings b
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            INNER JOIN {$wpdb->posts} cust ON b.customer_post_id = cust.ID
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

        $booking_id = $request->get_param('booking_id');
        $current_user_id = get_current_user_id();

        // Get booking with course and payment info
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, c.post_author, c.post_title as course_name,
                   cust.post_title as customer_name,
                   pt.gateway_transaction_id, pt.amount as paid_amount
            FROM {$wpdb->prefix}hmwevents_bookings b
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            INNER JOIN {$wpdb->posts} cust ON b.customer_post_id = cust.ID
            INNER JOIN {$wpdb->prefix}hmwevents_booking_groups bg ON b.booking_group_id = bg.id
            LEFT JOIN {$wpdb->prefix}hmwevents_payment_transactions pt ON bg.id = pt.booking_group_id
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
        $queued = $email_service->queue_booking_confirmation((int) $booking_id);

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
        $course_id       = $request->get_param('course_id');
        $current_user_id = get_current_user_id();

        // Verify the course exists and belongs to this educator (or user is admin)
        $course = get_post($course_id);
        if (!$course || $course->post_type !== 'educator_course') {
            return new WP_Error('invalid_course', __('Invalid course.', 'hmw-events'), ['status' => 400]);
        }

        if (!current_user_can('manage_options') && $course->post_author != $current_user_id) {
            return new WP_Error(
                'rest_forbidden',
                __('You can only add bookings to your own courses.', 'hmw-events'),
                ['status' => 403]
            );
        }

        $booking_data = array_merge($request->get_params(), [
            'educator_id' => (int) $course->post_author,
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

        $booking_id      = $request->get_param('booking_id');
        $current_user_id = get_current_user_id();

        // Fetch booking + course author for permission check
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, c.post_author
            FROM {$wpdb->prefix}hmwevents_bookings b
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
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

        $customer_id = (int) $booking->customer_post_id;

        $details_service    = new BookingDetailsService();
        $existing_form_data = $details_service->get_form_data($booking_id) ?? [];
        $updated_form_data  = $existing_form_data;
        $name_fields_changed = false;

        foreach (\HMWEvents\Registry\RegistrationFieldRegistry::for_admin_display() as $key => $field) {
            $value = $request->get_param($key);
            if ($value === null) {
                continue;
            }

            $sanitized = $field['type'] === 'email'
                ? sanitize_email($value)
                : sanitize_text_field($value);

            if ($field['source'] === 'customer_meta') {
                // Skip invalid email rather than clearing a valid stored value
                if ($field['type'] === 'email' && !empty($value) && !is_email($value)) {
                    continue;
                }
                update_post_meta($customer_id, $field['meta_key'], $sanitized);
                if (in_array($field['meta_key'], ['customer_first_name', 'customer_last_name'], true)) {
                    $name_fields_changed = true;
                }
            } else {
                $updated_form_data[$key] = $sanitized;
                // Write convenience copy to CPT meta for fields that declare a meta_key
                if (!empty($field['meta_key'])) {
                    update_post_meta($customer_id, $field['meta_key'], $sanitized);
                }
            }
        }

        // Rebuild customer post title if a name field changed
        if ($name_fields_changed) {
            $first = get_post_meta($customer_id, 'customer_first_name', true);
            $last  = get_post_meta($customer_id, 'customer_last_name', true);
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

        $booking_id      = $request->get_param('booking_id');
        $current_user_id = get_current_user_id();

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.id, b.booking_number, b.payment_status, b.currency,
                   b.course_post_id, b.deleted_at,
                   bg.id AS booking_group_id, bg.customer_post_id,
                   bg.payment_type AS group_payment_type, bg.total_amount,
                   c.post_author, c.post_title AS course_name
            FROM {$wpdb->prefix}hmwevents_bookings b
            INNER JOIN {$wpdb->prefix}hmwevents_booking_groups bg ON b.booking_group_id = bg.id
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
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
}
