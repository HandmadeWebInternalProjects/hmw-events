<?php
/**
 * Educator Payments Dashboard.
 *
 * Provides educators with a view of their bookings, payments, and revenue.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Admin;

use HMWEvents\Utils\Pagination;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Educator Payments Dashboard.
 */
class EducatorPaymentsDashboard
{
    /**
     * Initialize the dashboard.
     *
     * @since 1.0.0
     */
    public function register()
    {
        // Add menu page for educators
        add_action('admin_menu', [$this, 'add_menu_page']);
        
        // Handle AJAX requests
        add_action('wp_ajax_hmwevents_export_payments', [$this, 'export_payments_csv']);
        add_action('wp_ajax_hmwevents_cancel_booking', [$this, 'cancel_booking']);
        add_action('wp_ajax_hmwevents_refund_booking', [$this, 'refund_booking']);
        add_action('wp_ajax_hmwevents_send_payment_link', [$this, 'send_payment_link']);
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Add menu page for educators.
     *
     * @since 1.0.0
     */
    public function add_menu_page()
    {
        // Only show to educators (or admins)
        if (!current_user_can('edit_posts') && !current_user_can('manage_options')) {
            return;
        }

        add_menu_page(
            __('My Payments', 'hmw-events'),
            __('My Payments', 'hmw-events'),
            'edit_posts', // Educators can edit posts
            'hmwevents-educator-payments',
            [$this, 'render_dashboard'],
            'dashicons-money-alt',
            26
        );
    }

    /**
     * Render the payments dashboard.
     *
     * @since 1.0.0
     */
    public function render_dashboard()
    {
        global $wpdb;
        
        $current_user_id = get_current_user_id();
        
        // Calculate Australian financial year dates (July 1 - June 30)
        $current_date = current_time('Y-m-d');
        $current_year = (int) current_time('Y');
        $current_month = (int) current_time('m');
        
        // If we're in Jan-Jun, FY started last year on July 1
        // If we're in Jul-Dec, FY started this year on July 1
        if ($current_month < 7) {
            $fy_start = ($current_year - 1) . '-07-01';
            $fy_end = $current_year . '-06-30';
        } else {
            $fy_start = $current_year . '-07-01';
            $fy_end = ($current_year + 1) . '-06-30';
        }
        
        // Get filter parameters
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : $fy_start;
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : $fy_end;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Pagination parameters
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;

        // Build query
        $where = ["c.post_author = %d"]; // Only educator's own courses
        $where_values = [$current_user_id];

        if ($status_filter !== 'all') {
            $where[] = "b.payment_status = %s";
            $where_values[] = $status_filter;
        }

        if ($date_from) {
            $where[] = "b.created_at >= %s";
            $where_values[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where[] = "b.created_at <= %s";
            $where_values[] = $date_to . ' 23:59:59';
        }

        if ($search) {
            $where[] = "(b.booking_number LIKE %s OR cu.post_title LIKE %s)";
            $where_values[] = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $where_sql = implode(' AND ', $where);

        // Get total count for pagination
        $count_query = $wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            INNER JOIN {$wpdb->posts} cu ON b.customer_post_id = cu.ID
            WHERE {$where_sql}
            AND b.deleted_at IS NULL
        ", $where_values);

        $total_items = (int) $wpdb->get_var($count_query);

        // Create pagination instance
        $pagination = new Pagination($total_items, $per_page, $current_page);

        // Get bookings with payment info
        $query = $wpdb->prepare("
            SELECT 
                b.id,
                b.booking_number,
                b.booking_amount,
                b.currency,
                b.ticket_type,
                b.status as booking_status,
                b.payment_status,
                b.created_at,
                b.cancelled_at,
                c.ID as course_id,
                c.post_title as course_name,
                cu.ID as customer_id,
                cu.post_title as customer_name,
                bg.booking_reference,
                bg.payment_type as group_payment_type,
                bg.total_amount as group_total,
                pt.gateway_transaction_id,
                pt.status as transaction_status,
                pt.error_message,
                vu.voucher_code,
                vu.redeemed_value as voucher_amount,
                cuu.coupon_code,
                cuu.discount_amount as coupon_amount,
                COALESCE((
                    SELECT SUM(pt_total.amount)
                    FROM {$wpdb->prefix}educator_payment_transactions pt_total
                    WHERE pt_total.booking_group_id = bg.id
                    AND pt_total.status = 'succeeded'
                ), 0) as total_paid,
                COALESCE((
                    SELECT SUM(pt_remaining.amount)
                    FROM {$wpdb->prefix}educator_payment_transactions pt_remaining
                    WHERE pt_remaining.booking_group_id = bg.id
                    AND pt_remaining.status = 'succeeded'
                    AND pt_remaining.metadata LIKE '%remaining%'
                ), 0) as remaining_paid
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            INNER JOIN {$wpdb->posts} cu ON b.customer_post_id = cu.ID
            LEFT JOIN {$wpdb->prefix}educator_payment_transactions pt ON bg.id = pt.booking_group_id
            LEFT JOIN {$wpdb->prefix}educator_voucher_usage vu ON b.id = vu.booking_id
            LEFT JOIN {$wpdb->prefix}educator_coupon_usage cuu ON b.id = cuu.booking_id
            WHERE {$where_sql}
            AND b.deleted_at IS NULL
            ORDER BY b.created_at DESC
            {$pagination->get_limit()}
        ", $where_values);

        $bookings = $wpdb->get_results($query);

        // Normalize duplicate rows caused by multiple transactions to one row per booking.
        if (!empty($bookings)) {
            $bookings = array_values(array_reduce($bookings, function ($carry, $row) {
                $booking_id = (int) $row->id;

                if (!isset($carry[$booking_id])) {
                    $carry[$booking_id] = $row;
                    return $carry;
                }

                $existing = $carry[$booking_id];
                if (empty($existing->error_message) && !empty($row->error_message)) {
                    $carry[$booking_id] = $row;
                }

                return $carry;
            }, []));
        }

        // Calculate summary stats using the same filters as the main query
        $stats_query = $wpdb->prepare("
            SELECT 
                COUNT(CASE WHEN b.payment_status = 'paid' THEN 1 END) as total_bookings,
                SUM(CASE WHEN b.payment_status = 'paid' THEN b.booking_amount ELSE 0 END) as total_revenue,
                SUM(CASE WHEN b.payment_status = 'pending' THEN b.booking_amount ELSE 0 END) as pending_revenue,
                SUM(CASE WHEN b.payment_status = 'refunded' THEN b.booking_amount ELSE 0 END) as refunded_amount
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            INNER JOIN {$wpdb->posts} cu ON b.customer_post_id = cu.ID
            WHERE {$where_sql}
            AND b.deleted_at IS NULL
        ", $where_values);

        $stats = $wpdb->get_row($stats_query);

        // Render dashboard HTML
        $view_path = plugin_dir_path(dirname(__FILE__)) . 'views/educator-payments-dashboard.php';
        if (file_exists($view_path)) {
            include $view_path;
        } else {
            echo '<div class="notice notice-error"><p>' . __('Dashboard template not found.', 'hmw-events') . '</p></div>';
        }
    }

    /**
     * Export payments as CSV.
     *
     * @since 1.0.0
     */
    public function export_payments_csv()
    {
        check_ajax_referer('hmwevents_export_payments', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die(__('Unauthorized', 'hmw-events'));
        }

        global $wpdb;
        $current_user_id = get_current_user_id();

        // Get all bookings for this educator
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT 
                b.booking_number,
                b.created_at as booking_date,
                cu.post_title as customer_name,
                c.post_title as course_name,
                b.booking_amount,
                b.currency,
                b.payment_status,
                b.status as booking_status,
                pt.gateway_transaction_id,
                pt.created_at as payment_date,
                vu.voucher_code,
                vu.redeemed_value as voucher_amount,
                cuu.coupon_code,
                cuu.discount_amount as coupon_amount
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            INNER JOIN {$wpdb->posts} cu ON b.customer_post_id = cu.ID
            LEFT JOIN {$wpdb->prefix}educator_payment_transactions pt ON bg.id = pt.booking_group_id
            LEFT JOIN {$wpdb->prefix}educator_voucher_usage vu ON b.id = vu.booking_id
            LEFT JOIN {$wpdb->prefix}educator_coupon_usage cuu ON b.id = cuu.booking_id
            WHERE c.post_author = %d
            AND b.deleted_at IS NULL
            ORDER BY b.created_at DESC
        ", $current_user_id));

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=payments-' . date('Y-m-d') . '.csv');

        // Create CSV
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, [
            'Booking Number',
            'Booking Date',
            'Customer',
            'Course',
            'Amount',
            'Payment Status',
            'Booking Status',
            'Transaction ID',
            'Payment Date'
        ]);

        // Data rows
        foreach ($bookings as $booking) {
            fputcsv($output, [
                $booking->booking_number,
                $booking->booking_date,
                $booking->customer_name,
                $booking->course_name,
                '$' . number_format($booking->booking_amount, 2),
                ucfirst($booking->payment_status),
                ucfirst($booking->booking_status),
                $booking->gateway_transaction_id ?: 'N/A',
                $booking->payment_date ?: 'N/A'
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * Enqueue dashboard scripts.
     *
     * @since 1.0.0
     * @param string $hook Current admin page hook.
     */
    public function enqueue_scripts($hook)
    {
        // Only load on payments dashboard
        if ($hook !== 'toplevel_page_hmwevents-educator-payments') {
            return;
        }

        wp_enqueue_script(
            'hmwevents-payments-dashboard',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'resources/admin/js/payments-dashboard.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('hmwevents-payments-dashboard', 'cmsPayments', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hmwevents_payment_actions'),
            'confirmCancel' => __('Are you sure you want to cancel this booking? This action cannot be undone.', 'hmw-events'),
            'confirmRefund' => __('Are you sure you want to refund this payment? The refund will be processed through Stripe and cannot be undone.', 'hmw-events'),
        ]);
    }

    /**
     * Cancel a booking.
     *
     * @since 1.0.0
     */
    public function cancel_booking()
    {
        check_ajax_referer('hmwevents_payment_actions', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Unauthorized', 'hmw-events')]);
        }

        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;

        if (!$booking_id) {
            wp_send_json_error(['message' => __('Invalid booking ID', 'hmw-events')]);
        }

        global $wpdb;
        $current_user_id = get_current_user_id();

        // Verify this booking belongs to the educator
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, c.post_author
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            WHERE b.id = %d
            AND c.post_author = %d
            AND b.deleted_at IS NULL
        ", $booking_id, $current_user_id));

        if (!$booking) {
            wp_send_json_error(['message' => __('Booking not found or access denied', 'hmw-events')]);
        }

        // Check if already cancelled
        if ($booking->status === 'cancelled') {
            wp_send_json_error(['message' => __('This booking is already cancelled', 'hmw-events')]);
        }

        // Update booking status
        $updated = $wpdb->update(
            $wpdb->prefix . 'educator_bookings',
            [
                'status' => 'cancelled',
                'cancelled_at' => current_time('mysql'),
            ],
            ['id' => $booking_id],
            ['%s', '%s'],
            ['%d']
        );

        // Update payment status if pending
        $wpdb->update(
            $wpdb->prefix . 'educator_bookings',
            [
                'payment_status' => 'failed',
            ],
            [
                'id' => $booking_id,
                'payment_status' => 'pending',
            ],
            ['%s'],
            ['%d', '%s']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => __('Failed to cancel booking', 'hmw-events')]);
        }

        // Update course availability (add spot back)
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->prefix}educator_course_availability
            SET booked_count = IF(booked_count > 0, booked_count - 1, 0),
                available_count = available_count + 1
            WHERE course_post_id = %d
        ", $booking->course_post_id));

        // Trigger cancellation hooks (cancel pending emails + queue status email)
        do_action('hmwevents_booking_cancelled', $booking_id, 'Cancelled by educator', (array) $booking);

        wp_send_json_success([
            'message' => __('Booking cancelled successfully', 'hmw-events'),
        ]);
    }

    /**
     * Refund a booking payment.
     *
     * @since 1.0.0
     */
    public function refund_booking()
    {
        check_ajax_referer('hmwevents_payment_actions', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Unauthorized', 'hmw-events')]);
        }

        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        $refund_amount = isset($_POST['refund_amount']) ? floatval($_POST['refund_amount']) : 0;

        if (!$booking_id) {
            wp_send_json_error(['message' => __('Invalid booking ID', 'hmw-events')]);
        }

        global $wpdb;
        $current_user_id = get_current_user_id();

        // Get booking and payment transaction
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT 
                b.*,
                c.post_author,
                bg.id as group_id,
                pt.gateway_transaction_id,
                pt.gateway,
                pt.id as transaction_id
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
            LEFT JOIN {$wpdb->prefix}educator_payment_transactions pt ON bg.id = pt.booking_group_id
            WHERE b.id = %d
            AND c.post_author = %d
            AND b.deleted_at IS NULL
        ", $booking_id, $current_user_id));

        if (!$booking) {
            wp_send_json_error(['message' => __('Booking not found or access denied', 'hmw-events')]);
        }

        // Check if payment can be refunded
        if ($booking->payment_status !== 'paid') {
            wp_send_json_error(['message' => __('Only paid bookings can be refunded', 'hmw-events')]);
        }

        if ($booking->payment_status === 'refunded') {
            wp_send_json_error(['message' => __('This booking has already been refunded', 'hmw-events')]);
        }

        if (!$booking->gateway_transaction_id) {
            wp_send_json_error(['message' => __('No payment transaction found', 'hmw-events')]);
        }

        // Use full amount if not specified
        if ($refund_amount <= 0) {
            wp_send_json_error(['message' => __('Invalid refund amount', 'hmw-events')]);
            return;
        }

        // Validate refund amount
        if ($refund_amount > $booking->booking_amount) {
            wp_send_json_error(['message' => __('Refund amount cannot exceed booking amount', 'hmw-events')]);
        }

        // Process refund through Stripe using the educator's Stripe account
        $refund_result = $this->process_stripe_refund(
            $booking->gateway_transaction_id,
            $refund_amount,
            'Refunded by educator',
            (int) $booking->post_author
        );

        if (is_wp_error($refund_result)) {
            wp_send_json_error(['message' => $refund_result->get_error_message()]);
        }

        // Update booking payment status
        $wpdb->update(
            $wpdb->prefix . 'educator_bookings',
            [
                'payment_status' => 'refunded',
                'status' => 'cancelled',
                'cancelled_at' => current_time('mysql'),
            ],
            ['id' => $booking_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        // Update payment transaction
        $wpdb->update(
            $wpdb->prefix . 'educator_payment_transactions',
            [
                'status' => 'refunded',
                'refund_id' => $refund_result['refund_id'],
                'refunded_amount' => $refund_amount,
                'refunded_at' => current_time('mysql'),
            ],
            ['id' => $booking->transaction_id],
            ['%s', '%s', '%f', '%s'],
            ['%d']
        );

        // Update course availability (add spot back)
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->prefix}educator_course_availability
            SET booked_count = IF(booked_count > 0, booked_count - 1, 0),
                available_count = available_count + 1
            WHERE course_post_id = %d
        ", $booking->course_post_id));

        // Trigger refund hooks (cancel pending emails + queue refund email)
        do_action('hmwevents_refund_issued', $booking_id, $refund_amount, 'Refunded by educator');

        wp_send_json_success([
            'message' => sprintf(__('Refund of $%s processed successfully', 'hmw-events'), number_format($refund_amount, 2)),
        ]);
    }

    /**
     * Send payment link to customer for pending or remaining payment.
     *
     * @since 1.0.0
     */
    public function send_payment_link()
    {
        check_ajax_referer('hmwevents_payment_actions', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Unauthorized', 'hmw-events')]);
        }

        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        $payment_type = isset($_POST['payment_type']) ? sanitize_text_field($_POST['payment_type']) : 'pending';

        if (!$booking_id) {
            wp_send_json_error(['message' => __('Invalid booking ID', 'hmw-events')]);
        }

        if (!in_array($payment_type, ['pending', 'remaining'], true)) {
            wp_send_json_error(['message' => __('Invalid payment type', 'hmw-events')]);
        }

        global $wpdb;
        $current_user_id = get_current_user_id();

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT
                b.id,
                b.booking_number,
                b.payment_status,
                b.currency,
                b.course_post_id,
                b.deleted_at,
                bg.id as booking_group_id,
                bg.customer_post_id,
                bg.payment_type as group_payment_type,
                bg.total_amount,
                c.post_author,
                c.post_title as course_name
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            WHERE b.id = %d
            AND c.post_author = %d
            AND b.deleted_at IS NULL
            LIMIT 1",
            $booking_id,
            $current_user_id
        ));

        if (!$booking) {
            wp_send_json_error(['message' => __('Booking not found or access denied', 'hmw-events')]);
        }

        if ($payment_type === 'pending' && !in_array($booking->payment_status, ['pending', 'failed'], true)) {
            wp_send_json_error(['message' => __('This booking is not eligible for a pending payment link.', 'hmw-events')]);
        }

        if ($payment_type === 'remaining') {
            if (!($booking->payment_status === 'paid' && $booking->group_payment_type === 'deposit')) {
                wp_send_json_error(['message' => __('This booking is not eligible for a remaining payment link.', 'hmw-events')]);
            }

            $remaining_paid = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$wpdb->prefix}educator_payment_transactions
                WHERE booking_group_id = %d
                AND status = 'succeeded'
                AND metadata LIKE %s",
                $booking->booking_group_id,
                '%remaining%'
            ));

            if ((int) $remaining_paid > 0) {
                wp_send_json_error(['message' => __('Remaining payment has already been completed.', 'hmw-events')]);
            }
        }

        $result = \HMWEvents\Helpers\Booking::send_payment_link_email(
            $booking,
            $payment_type === 'remaining'
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if (!$result) {
            wp_send_json_error(['message' => __('Failed to queue payment link email. Please try again.', 'hmw-events')]);
        }

        wp_send_json_success([
            'message' => __('Payment link queued for sending.', 'hmw-events'),
        ]);
    }

    /**
     * Process Stripe refund.
     *
     * @since 1.0.0
     * @param string   $charge_id   Stripe charge/payment intent ID.
     * @param float    $amount      Refund amount.
     * @param string   $reason      Refund reason.
     * @param int|null $educator_id Educator user ID to use their Stripe account keys.
     * @return array|\WP_Error Refund result or error.
     */
    private function process_stripe_refund($charge_id, $amount, $reason = '', $educator_id = null)
    {
        // Use StripePaymentGateway with the educator ID so it uses the correct
        // Stripe account (educator keys in live mode, plugin keys as fallback).
        $gateway = new \HMWEvents\Services\Gateways\StripePaymentGateway($educator_id);
        $gateway->register();

        $refund = $gateway->refund_payment($charge_id, $amount, 'requested_by_customer');

        if (is_wp_error($refund)) {
            return $refund;
        }

        return [
            'refund_id' => $refund['refund_id'],
            'status' => $refund['status'],
        ];
    }
}
