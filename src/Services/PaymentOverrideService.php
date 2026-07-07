<?php

/**
 * Payment Override Service.
 *
 * Provides admin-only manual override controls for payment statuses.
 * Records all overrides in hmwevents_booking_history for audit trail.
 *
 * Supports:
 * - Manual payment status changes (pending → paid, etc.)
 * - Refund recording
 * - Payment failure recording
 * - Write-off / void recording
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class PaymentOverrideService
{
    /**
     * Allowed payment status transitions for manual overrides.
     */
    private const ALLOWED_OVERRIDES = [
        'pending'  => ['paid', 'invoiced', 'failed', 'refunded'],
        'invoiced' => ['paid', 'failed', 'refunded'],
        'paid'     => ['refunded'],
        'failed'   => ['pending', 'paid'],
    ];

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('wp_ajax_hmwevents_override_payment_status', [$this, 'ajax_override']);
    }

    /**
     * Check if the current user can override payments (admin only).
     */
    public function can_override(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Override a booking group's payment status.
     *
     * @param int    $booking_group_id
     * @param string $new_status    The target payment status.
     * @param string $reason        Reason for the override (audit trail).
     * @return true|\WP_Error
     */
    public function override_status(int $booking_group_id, string $new_status, string $reason = ''): true|\WP_Error
    {
        if (!$this->can_override()) {
            return new \WP_Error('permission_denied', __('Only administrators can override payment status.', 'hmw-events'));
        }

        global $wpdb;
        $groups_table = DatabaseService::get_table_name('booking_groups');

        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$groups_table} WHERE id = %d",
            $booking_group_id
        ));

        if (!$group) {
            return new \WP_Error('not_found', __('Booking group not found.', 'hmw-events'));
        }

        $old_status = $group->payment_status;
        $allowed = self::ALLOWED_OVERRIDES[$old_status] ?? [];

        if (!in_array($new_status, $allowed, true)) {
            return new \WP_Error(
                'invalid_transition',
                sprintf(
                    __('Cannot override from %1$s to %2$s.', 'hmw-events'),
                    $old_status,
                    $new_status
                )
            );
        }

        // Update booking group
        $wpdb->update(
            $groups_table,
            ['payment_status' => $new_status, 'updated_at' => current_time('mysql')],
            ['id' => $booking_group_id],
            ['%s', '%s'],
            ['%d']
        );

        // Update child bookings
        $bookings_table = DatabaseService::get_table_name('bookings');
        $wpdb->update(
            $bookings_table,
            ['payment_status' => $new_status, 'updated_at' => current_time('mysql')],
            ['booking_group_id' => $booking_group_id],
            ['%s', '%s'],
            ['%d']
        );

        // Record in audit history
        $this->record_audit($booking_group_id, $old_status, $new_status, $reason);

        /**
         * Action: hmwevents_payment_status_overridden
         *
         * @param int    $booking_group_id
         * @param string $old_status
         * @param string $new_status
         * @param string $reason
         */
        do_action('hmwevents_payment_status_overridden', $booking_group_id, $old_status, $new_status, $reason);

        return true;
    }

    /**
     * Record a refund for a booking group.
     *
     * @param int   $booking_group_id
     * @param float $amount           Refund amount.
     * @param string $reason
     */
    public function record_refund(int $booking_group_id, float $amount, string $reason = ''): true|\WP_Error
    {
        if (!$this->can_override()) {
            return new \WP_Error('permission_denied', __('Only administrators can process refunds.', 'hmw-events'));
        }

        global $wpdb;
        $tx_table = DatabaseService::get_table_name('payment_transactions');
        $groups_table = DatabaseService::get_table_name('booking_groups');

        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$groups_table} WHERE id = %d",
            $booking_group_id
        ));

        if (!$group) {
            return new \WP_Error('not_found', __('Booking group not found.', 'hmw-events'));
        }

        // Record refund transaction
        $wpdb->insert($tx_table, [
            'booking_group_id' => $booking_group_id,
            'transaction_type' => ($amount >= $group->total_amount) ? 'refund' : 'partial_refund',
            'amount'           => $amount,
            'currency'         => $group->currency,
            'payment_gateway'  => 'manual',
            'gateway'          => 'manual',
            'status'           => 'refunded',
            'metadata'         => wp_json_encode(['type' => 'manual_refund', 'reason' => $reason]),
            'created_at'       => current_time('mysql'),
        ], ['%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s']);

        // Update payment status
        $this->override_status($booking_group_id, 'refunded', $reason ?: 'Manual refund');

        return true;
    }

    /**
     * Record a payment failure.
     */
    public function record_failure(int $booking_group_id, string $reason = ''): true|\WP_Error
    {
        return $this->override_status($booking_group_id, 'failed', $reason ?: 'Payment failed');
    }

    /**
     * Record an audit entry in booking_history.
     */
    private function record_audit(int $booking_group_id, string $old_status, string $new_status, string $reason): void
    {
        global $wpdb;
        $history_table = DatabaseService::get_table_name('booking_history');

        // Find booking IDs for this group
        $bookings_table = DatabaseService::get_table_name('bookings');
        $booking_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$bookings_table} WHERE booking_group_id = %d",
            $booking_group_id
        ));

        foreach ($booking_ids as $booking_id) {
            $wpdb->insert($history_table, [
                'booking_id'    => $booking_id,
                'field_changed' => 'payment_status',
                'old_value'     => $old_status,
                'new_value'     => $new_status,
                'changed_by'    => get_current_user_id(),
                'change_reason' => $reason,
                'created_at'    => current_time('mysql'),
            ], ['%d', '%s', '%s', '%s', '%d', '%s', '%s']);
        }
    }

    /**
     * AJAX handler for payment status override.
     */
    public function ajax_override(): void
    {
        check_ajax_referer('hmwevents_payment_override', '_wpnonce');

        if (!$this->can_override()) {
            wp_die(-1);
        }

        $booking_group_id = (int) ($_POST['booking_group_id'] ?? 0);
        $new_status       = sanitize_text_field($_POST['new_status'] ?? '');
        $reason           = sanitize_text_field($_POST['reason'] ?? '');

        $result = $this->override_status($booking_group_id, $new_status, $reason);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message'   => __('Payment status updated.', 'hmw-events'),
            'new_status' => $new_status,
        ]);
    }
}
