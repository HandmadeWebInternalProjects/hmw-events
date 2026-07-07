<?php

/**
 * Reporting Service.
 *
 * Handles exports (CSV), saved report filters, and compliance jobs:
 * - Registration/attendee CSV export
 * - Payment/invoice CSV export
 * - PII retention (90-day document purge)
 * - Archived event exclusion from frontend
 *
 * Saved report filters are stored in hmwevents_saved_report_filters.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class ReportingService
{
    private string $saved_filters_table;

    public function __construct()
    {
        $this->saved_filters_table = DatabaseService::get_table_name('saved_report_filters');
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('hmwevents_daily_compliance_check', [$this, 'run_compliance_jobs']);
        add_action('wp_ajax_hmwevents_export_csv', [$this, 'ajax_export']);
        add_action('wp_ajax_hmwevents_save_report_filter', [$this, 'ajax_save_filter']);
        add_action('pre_get_posts', [$this, 'exclude_archived_events_from_frontend']);

        // Schedule compliance cron if not already scheduled
        if (!wp_next_scheduled('hmwevents_daily_compliance_check')) {
            wp_schedule_event(time(), 'daily', 'hmwevents_daily_compliance_check');
        }
    }

    // ================================================================
    // CSV EXPORTS
    // ================================================================

    /**
     * Export registrations to CSV.
     *
     * @param array $filters Event ID, date range, status filter.
     * @return string CSV content.
     */
    public function export_registrations(array $filters = []): string
    {
        global $wpdb;

        $bookings_table = DatabaseService::get_table_name('bookings');
        $details_table  = DatabaseService::get_table_name('booking_details');
        $groups_table   = DatabaseService::get_table_name('booking_groups');

        $where = ["b.status != 'cancelled'"];
        $params = [];

        if (!empty($filters['event_id'])) {
            $where[] = 'b.event_post_id = %d';
            $params[] = (int) $filters['event_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'b.created_at >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'b.created_at <= %s';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $where_clause = implode(' AND ', $where);

        $sql = "
            SELECT b.*, bg.payment_status as group_payment_status, bd.form_data
            FROM {$bookings_table} b
            LEFT JOIN {$groups_table} bg ON b.booking_group_id = bg.id
            LEFT JOIN {$details_table} bd ON b.id = bd.booking_id
            WHERE {$where_clause}
            ORDER BY b.created_at DESC
        ";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $rows = $wpdb->get_results($sql);

        $headers = [
            __('Booking #', 'hmw-events'),
            __('Event ID', 'hmw-events'),
            __('Registrant ID', 'hmw-events'),
            __('Status', 'hmw-events'),
            __('Attendance', 'hmw-events'),
            __('Payment Status', 'hmw-events'),
            __('Amount', 'hmw-events'),
            __('Coupon', 'hmw-events'),
            __('Discount', 'hmw-events'),
            __('Created', 'hmw-events'),
        ];

        $csv = $this->array_to_csv($headers);
        foreach ($rows as $row) {
            $csv .= $this->array_to_csv([
                $row->booking_number,
                $row->event_post_id,
                $row->registrant_post_id,
                $row->status,
                $row->attendance_status,
                $row->payment_status ?: $row->group_payment_status,
                $row->booking_amount,
                $row->coupon_code,
                $row->discount_amount,
                $row->created_at,
            ]);
        }

        return $csv;
    }

    /**
     * Export payments to CSV.
     */
    public function export_payments(array $filters = []): string
    {
        global $wpdb;

        $tx_table    = DatabaseService::get_table_name('payment_transactions');
        $groups_table = DatabaseService::get_table_name('booking_groups');

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'pt.created_at >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'pt.created_at <= %s';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['status'])) {
            $where[] = 'pt.status = %s';
            $params[] = $filters['status'];
        }

        $where_clause = implode(' AND ', $where);

        $sql = "
            SELECT pt.*, bg.booking_reference
            FROM {$tx_table} pt
            LEFT JOIN {$groups_table} bg ON pt.booking_group_id = bg.id
            WHERE {$where_clause}
            ORDER BY pt.created_at DESC
        ";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $rows = $wpdb->get_results($sql);

        $headers = [
            __('Transaction ID', 'hmw-events'),
            __('Booking Reference', 'hmw-events'),
            __('Type', 'hmw-events'),
            __('Amount', 'hmw-events'),
            __('Currency', 'hmw-events'),
            __('Gateway', 'hmw-events'),
            __('Gateway ID', 'hmw-events'),
            __('Status', 'hmw-events'),
            __('Created', 'hmw-events'),
        ];

        $csv = $this->array_to_csv($headers);
        foreach ($rows as $row) {
            $csv .= $this->array_to_csv([
                $row->id,
                $row->booking_reference,
                $row->transaction_type,
                $row->amount,
                $row->currency,
                $row->gateway,
                $row->gateway_transaction_id,
                $row->status,
                $row->created_at,
            ]);
        }

        return $csv;
    }

    /**
     * Export attendees (confirmed + attended).
     */
    public function export_attendees(int $event_id): string
    {
        global $wpdb;

        $bookings_table = DatabaseService::get_table_name('bookings');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$bookings_table} 
             WHERE event_post_id = %d AND status IN ('confirmed', 'attended') 
             ORDER BY created_at ASC",
            $event_id
        ));

        $headers = [
            __('Booking #', 'hmw-events'),
            __('Registrant ID', 'hmw-events'),
            __('Ticket Type', 'hmw-events'),
            __('Attendance', 'hmw-events'),
            __('Payment Status', 'hmw-events'),
            __('Amount', 'hmw-events'),
        ];

        $csv = $this->array_to_csv($headers);
        foreach ($rows as $row) {
            $csv .= $this->array_to_csv([
                $row->booking_number,
                $row->registrant_post_id,
                $row->ticket_type,
                $row->attendance_status,
                $row->payment_status,
                $row->booking_amount,
            ]);
        }

        return $csv;
    }

    // ================================================================
    // SAVED REPORT FILTERS
    // ================================================================

    /**
     * Save a report filter.
     */
    public function save_filter(int $user_id, string $label, string $report_type, array $filter_data, bool $is_default = false): int
    {
        global $wpdb;

        if ($is_default) {
            $wpdb->update(
                $this->saved_filters_table,
                ['is_default' => 0],
                ['user_id' => $user_id, 'report_type' => $report_type],
                ['%d'],
                ['%d', '%s']
            );
        }

        $wpdb->insert($this->saved_filters_table, [
            'user_id'     => $user_id,
            'label'       => $label,
            'report_type' => $report_type,
            'filter_data' => wp_json_encode($filter_data),
            'is_default'  => (int) $is_default,
            'created_at'  => current_time('mysql'),
            'updated_at'  => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%d', '%s', '%s']);

        return (int) $wpdb->insert_id;
    }

    /**
     * Get saved filters for a user/report type.
     */
    public function get_saved_filters(int $user_id, string $report_type = ''): array
    {
        global $wpdb;

        $where = 'user_id = %d';
        $params = [$user_id];

        if ($report_type) {
            $where .= ' AND report_type = %s';
            $params[] = $report_type;
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->saved_filters_table} WHERE {$where} ORDER BY is_default DESC, updated_at DESC",
            ...$params
        )) ?: [];
    }

    /**
     * Delete a saved filter.
     */
    public function delete_filter(int $filter_id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete($this->saved_filters_table, ['id' => $filter_id], ['%d']);
    }

    // ================================================================
    // COMPLIANCE
    // ================================================================

    /**
     * Run daily compliance jobs:
     * - Purge PII documents past retention
     * - Purge old email queue entries
     */
    public function run_compliance_jobs(): void
    {
        $doc_handler = new DocumentUploadHandler();
        $doc_purged = $doc_handler->purge_expired_documents();

        $email_service = new EmailDispatchService();
        $emails_purged = $email_service->cleanup_old(90);

        error_log("HMWEvents Compliance: Purged {$doc_purged} documents, {$emails_purged} emails.");
    }

    /**
     * Exclude archived events from frontend queries.
     */
    public function exclude_archived_events_from_frontend(\WP_Query $query): void
    {
        if (is_admin() || $query->get('post_type') !== 'hmw_event') {
            return;
        }

        $query->set('post_status', ['publish', 'fully_booked']);
    }

    // ================================================================
    // AJAX
    // ================================================================

    /**
     * AJAX export handler.
     */
    public function ajax_export(): void
    {
        if (!current_user_can('edit_hmw_events')) {
            wp_die(-1);
        }

        $export_type = sanitize_text_field($_POST['export_type'] ?? 'registrations');
        $event_id    = (int) ($_POST['event_id'] ?? 0);
        $date_from   = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to     = sanitize_text_field($_POST['date_to'] ?? '');

        $filters = ['date_from' => $date_from, 'date_to' => $date_to];
        if ($event_id) {
            $filters['event_id'] = $event_id;
        }

        $csv = match ($export_type) {
            'payments'    => $this->export_payments($filters),
            'attendees'   => $export_type === 'attendees' && $event_id
                ? $this->export_attendees($event_id)
                : $this->export_registrations($filters),
            default       => $this->export_registrations($filters),
        };

        wp_send_json_success(['csv' => $csv, 'filename' => "hmwevents-{$export_type}-" . date('Y-m-d') . '.csv']);
    }

    /**
     * AJAX save filter handler.
     */
    public function ajax_save_filter(): void
    {
        if (!current_user_can('edit_hmw_events')) {
            wp_die(-1);
        }

        $user_id    = get_current_user_id();
        $label      = sanitize_text_field($_POST['label'] ?? '');
        $report_type = sanitize_text_field($_POST['report_type'] ?? 'registrations');
        $filter_data = json_decode(wp_unslash($_POST['filter_data'] ?? '{}'), true) ?: [];
        $is_default  = !empty($_POST['is_default']);

        $id = $this->save_filter($user_id, $label, $report_type, $filter_data, $is_default);

        wp_send_json_success(['id' => $id]);
    }

    // ================================================================
    // HELPERS
    // ================================================================

    /**
     * Convert an array to a CSV line.
     */
    private function array_to_csv(array $data): string
    {
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $data);
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        return $csv;
    }
}
