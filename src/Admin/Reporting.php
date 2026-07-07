<?php

/**
 * Admin Reporting Page.
 *
 * Provides admins with a sales overview across all educators,
 * showing total booking sales and number of sales per educator,
 * with date range filtering and CSV export.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Admin;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Reporting Page Handler.
 */
class Reporting
{
    /**
     * Constructor - register hooks.
     */
    public function __construct()
    {
        add_action('admin_post_hmwevents_export_reporting_csv', [$this, 'export_csv']);
    }

    /**
     * Render the reporting page.
     *
     * @since 1.0.0
     */
    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        global $wpdb;

        // Default to current Australian financial year
        $current_year  = (int) current_time('Y');
        $current_month = (int) current_time('m');

        if ($current_month < 7) {
            $default_from = ($current_year - 1) . '-07-01';
            $default_to   = $current_year . '-06-30';
        } else {
            $default_from = $current_year . '-07-01';
            $default_to   = ($current_year + 1) . '-06-30';
        }

        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : $default_from;
        $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : $default_to;

        $educators = $this->get_educator_sales($date_from, $date_to);

        $grand_total_sales    = 0.0;
        $grand_total_bookings = 0;

        foreach ($educators as $educator) {
            $grand_total_sales    += (float) $educator->total_sales;
            $grand_total_bookings += (int) $educator->total_bookings;
        }

        $nonce = wp_create_nonce('hmwevents_reporting_nonce');

        $view_path = plugin_dir_path(dirname(__FILE__)) . 'views/admin-reporting.php';
        if (file_exists($view_path)) {
            include $view_path;
        }
    }

    /**
     * Export reporting data as CSV (admin-post.php handler).
     *
     * @since 1.0.0
     */
    public function export_csv()
    {
        check_admin_referer('hmwevents_reporting_csv_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.'));
        }

        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '';

        $educators = $this->get_educator_sales($date_from, $date_to);

        $grand_total_sales    = 0.0;
        $grand_total_bookings = 0;

        foreach ($educators as $educator) {
            $grand_total_sales    += (float) $educator->total_sales;
            $grand_total_bookings += (int) $educator->total_bookings;
        }

        $filename = 'educator-sales-report-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Date range header row
        $range_label = 'Date Range: ' . ($date_from ?: 'All time') . ' to ' . ($date_to ?: 'Present');
        fputcsv($output, [$range_label]);
        fputcsv($output, []);

        // Column headers
        fputcsv($output, ['Educator Name', 'Email', 'Total Number of Sales', 'Total Booking Sales (AUD)']);

        foreach ($educators as $educator) {
            fputcsv($output, [
                $educator->educator_name,
                $educator->educator_email,
                (int) $educator->total_bookings,
                number_format((float) $educator->total_sales, 2, '.', ''),
            ]);
        }

        // Totals row
        fputcsv($output, []);
        fputcsv($output, [
            'TOTALS',
            '',
            $grand_total_bookings,
            number_format($grand_total_sales, 2, '.', ''),
        ]);

        // Per-educator detail sections
        foreach ($educators as $educator) {
            $rows = $this->get_educator_booking_rows((int) $educator->educator_id, $date_from, $date_to);

            if (empty($rows)) {
                continue;
            }

            fputcsv($output, []);
            fputcsv($output, [$educator->educator_name . ' – Booking Detail']);
            fputcsv($output, [
                'Course Name',
                'Start Date',
                'End Date',
                'Venue',
                'First Name',
                'Last Name',
                'Email',
                'Amount Paid',
                'Booking Date',
            ]);

            foreach ($rows as $row) {
                $form = json_decode($row->form_data ?? '{}', true) ?: [];
                fputcsv($output, [
                    $row->course_name,
                    $row->course_start_date ?: '',
                    $row->course_end_date   ?: '',
                    $row->venue             ?: '',
                    $form['mothers_first_name'] ?? '',
                    $form['mothers_last_name']  ?? '',
                    $row->registrant_email    ?: '',
                    number_format((float) $row->booking_amount, 2, '.', ''),
                    $row->booking_date ? date('d/m/Y H:i', strtotime($row->booking_date)) : '',
                ]);
            }
        }

        fclose($output);
        exit;
    }

    /**
     * Query educator sales data.
     *
     * Returns all educators (with the 'educator' role) joined to their paid bookings,
     * grouped by educator, ordered by total sales descending.
     *
     * @param string $date_from Optional start date (Y-m-d).
     * @param string $date_to   Optional end date (Y-m-d).
     * @return array Array of stdClass rows.
     */
    private function get_educator_sales(string $date_from = '', string $date_to = ''): array
    {
        global $wpdb;

        // Build booking ON-clause conditions dynamically so LEFT JOIN
        // still returns educators with zero bookings in the period.
        $booking_on = "b.course_post_id = c.ID AND b.deleted_at IS NULL AND b.payment_status = 'paid'";
        $values     = ['%educator%'];

        if ($date_from) {
            $booking_on .= ' AND b.created_at >= %s';
            $values[]    = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $booking_on .= ' AND b.created_at <= %s';
            $values[]    = $date_to . ' 23:59:59';
        }

        $capabilities_key = $wpdb->prefix . 'capabilities';

        $sql = "
            SELECT
                u.ID               AS educator_id,
                u.display_name     AS educator_name,
                u.user_email       AS educator_email,
                COUNT(DISTINCT b.id)              AS total_bookings,
                COALESCE(SUM(b.booking_amount), 0) AS total_sales
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} um
                ON um.user_id  = u.ID
                AND um.meta_key = %s
                AND um.meta_value LIKE %s
            LEFT JOIN {$wpdb->posts} c
                ON  c.post_author = u.ID
                AND c.post_type   = 'hmw_event'
                AND c.post_status != 'trash'
            LEFT JOIN {$wpdb->prefix}hmwevents_bookings b
                ON  {$booking_on}
            GROUP BY u.ID, u.display_name, u.user_email
            ORDER BY total_sales DESC, total_bookings DESC
        ";

        // Prepend the capabilities meta_key value
        array_unshift($values, $capabilities_key);

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    /**
     * Query individual paid booking rows for one educator.
     *
     * @param int    $educator_id Educator WP user ID.
     * @param string $date_from   Optional start date (Y-m-d).
     * @param string $date_to     Optional end date (Y-m-d).
     * @return array Array of stdClass rows.
     */
    private function get_educator_booking_rows(int $educator_id, string $date_from = '', string $date_to = ''): array
    {
        global $wpdb;

        $where  = "b.deleted_at IS NULL AND b.payment_status = 'paid' AND c.post_author = %d";
        $values = [$educator_id];

        if ($date_from) {
            $where   .= ' AND b.created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }
        if ($date_to) {
            $where   .= ' AND b.created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $sql = "
            SELECT
                c.post_title                AS course_name,
                pm_start.meta_value         AS course_start_date,
                pm_end.meta_value           AS course_end_date,
                CONCAT_WS(', ',
                    NULLIF(pm_suburb.meta_value, ''),
                    NULLIF(pm_state.meta_value,  '')
                )                           AS venue,
                pm_email.meta_value         AS registrant_email,
                bd.form_data,
                b.booking_amount,
                b.created_at                AS booking_date
            FROM {$wpdb->prefix}hmwevents_bookings b
            INNER JOIN {$wpdb->posts} c
                ON  c.ID = b.course_post_id
                AND c.post_status != 'trash'
            LEFT JOIN {$wpdb->postmeta} pm_start
                ON  pm_start.post_id  = b.course_post_id
                AND pm_start.meta_key = 'course_start_date'
            LEFT JOIN {$wpdb->postmeta} pm_end
                ON  pm_end.post_id  = b.course_post_id
                AND pm_end.meta_key = 'course_end_date'
            LEFT JOIN {$wpdb->postmeta} pm_suburb
                ON  pm_suburb.post_id  = b.course_post_id
                AND pm_suburb.meta_key = 'course_location_suburb'
            LEFT JOIN {$wpdb->postmeta} pm_state
                ON  pm_state.post_id  = b.course_post_id
                AND pm_state.meta_key = 'course_location_state'
            LEFT JOIN {$wpdb->postmeta} pm_email
                ON  pm_email.post_id  = b.customer_post_id
                AND pm_email.meta_key = 'registrant_email'
            LEFT JOIN {$wpdb->prefix}hmwevents_booking_details bd
                ON  bd.booking_id = b.id
            WHERE {$where}
            ORDER BY c.post_title ASC, b.created_at ASC
        ";

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }
}
