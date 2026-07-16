<?php
/**
 * Admin Reporting Page Template.
 *
 * Variables available from Reporting::render_page():
 *   $date_from           string   Current date_from filter value
 *   $date_to             string   Current date_to filter value
 *   $payment_status      string   Current payment_status filter value
 *   $attendance_status   string   Current attendance_status filter value
 *   $event_type          string   Current event_type filter value
 *   $audience            string   Current audience filter value
 *   $educators           array    Query results - one row per educator
 *   $grand_total_sales   float    Sum of all educator sales
 *   $grand_total_bookings int     Sum of all educator bookings
 *   $nonce               string   Nonce for CSV export link
 *
 * @package HMWEvents
 * @since 1.0.0
 */

defined('ABSPATH') || die('Don\'t run this file directly!');

$csv_url = add_query_arg(
    [
        'action'            => 'hmwevents_export_reporting_csv',
        'date_from'         => rawurlencode($date_from),
        'date_to'           => rawurlencode($date_to),
        'payment_status'    => rawurlencode($payment_status ?? ''),
        'attendance_status' => rawurlencode($attendance_status ?? ''),
        'event_type'        => rawurlencode($event_type ?? ''),
        'audience'          => rawurlencode($audience ?? ''),
        'nonce'             => wp_create_nonce('hmwevents_reporting_csv_nonce'),
    ],
    admin_url('admin-post.php')
);
?>

<div class="wrap hmwevents-reporting">
    <h1><?php esc_html_e('Reporting', 'hmw-events'); ?></h1>

    <p style="margin-top:0;">
        <button type="button" class="button" id="hmwevents-remove-test-data" style="color:#b32d2e;">
            <?php esc_html_e('Remove Abandoned Bookings (>7 days pending)', 'hmw-events'); ?>
        </button>
    </p>

    <script>
    document.getElementById('hmwevents-remove-test-data').addEventListener('click', function() {
        if (!confirm('Remove all pending bookings older than 7 days? This cannot be undone.')) {
            return;
        }
        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Removing...';

        var formData = new FormData();
        formData.append('action', 'hmwevents_remove_test_data');

        fetch(ajaxurl, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                alert(data.data ? data.data.message : 'Done.');
                location.reload();
            })
            .catch(function() {
                alert('Request failed.');
                btn.disabled = false;
                btn.textContent = 'Remove Abandoned Bookings (>7 days pending)';
            });
    });
    </script>

    <!-- Filter bar -->
    <form method="get" style="background:#fff;padding:16px 20px;border:1px solid #ddd;border-radius:4px;margin:20px 0;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;">
        <input type="hidden" name="page" value="hmwevents-reporting">

        <div>
            <label for="hmwevents-date-from" style="display:block;margin-bottom:4px;font-weight:600;">
                <?php esc_html_e('From', 'hmw-events'); ?>
            </label>
            <input
                type="date"
                id="hmwevents-date-from"
                name="date_from"
                value="<?php echo esc_attr($date_from); ?>"
                class="regular-text"
            >
        </div>

        <div>
            <label for="hmwevents-date-to" style="display:block;margin-bottom:4px;font-weight:600;">
                <?php esc_html_e('To', 'hmw-events'); ?>
            </label>
            <input
                type="date"
                id="hmwevents-date-to"
                name="date_to"
                value="<?php echo esc_attr($date_to); ?>"
                class="regular-text"
            >
        </div>

        <div>
            <label for="hmwevents-payment-status" style="display:block;margin-bottom:4px;font-weight:600;">
                <?php esc_html_e('Payment Status', 'hmw-events'); ?>
            </label>
            <select name="payment_status" id="hmwevents-payment-status">
                <option value=""><?php esc_html_e('All', 'hmw-events'); ?></option>
                <?php
                $statuses = ['pending', 'paid', 'refunded', 'failed', 'invoiced'];
                foreach ($statuses as $s):
                ?>
                    <option value="<?php echo esc_attr($s); ?>" <?php selected(($payment_status ?? ''), $s); ?>><?php echo esc_html(ucfirst($s)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="hmwevents-attendance-status" style="display:block;margin-bottom:4px;font-weight:600;">
                <?php esc_html_e('Attendance', 'hmw-events'); ?>
            </label>
            <select name="attendance_status" id="hmwevents-attendance-status">
                <option value=""><?php esc_html_e('All', 'hmw-events'); ?></option>
                <?php
                $attendance_options = ['registered', 'attended', 'no_show'];
                foreach ($attendance_options as $a):
                    $a_label = match ($a) {
                        'registered' => __('Registered', 'hmw-events'),
                        'attended'   => __('Attended', 'hmw-events'),
                        'no_show'    => __('No Show', 'hmw-events'),
                        default      => ucfirst($a),
                    };
                ?>
                    <option value="<?php echo esc_attr($a); ?>" <?php selected(($attendance_status ?? ''), $a); ?>><?php echo esc_html($a_label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="hmwevents-event-type" style="display:block;margin-bottom:4px;font-weight:600;">
                <?php esc_html_e('Event Type', 'hmw-events'); ?>
            </label>
            <select name="event_type" id="hmwevents-event-type">
                <option value=""><?php esc_html_e('All', 'hmw-events'); ?></option>
                <?php
                $types = get_terms(['taxonomy' => 'hmw_event_type', 'hide_empty' => true]);
                if (!is_wp_error($types)):
                    foreach ($types as $type):
                ?>
                    <option value="<?php echo esc_attr($type->slug); ?>" <?php selected(($event_type ?? ''), $type->slug); ?>><?php echo esc_html($type->name); ?></option>
                <?php endforeach; endif; ?>
            </select>
        </div>

        <div>
            <label for="hmwevents-audience" style="display:block;margin-bottom:4px;font-weight:600;">
                <?php esc_html_e('Audience', 'hmw-events'); ?>
            </label>
            <select name="audience" id="hmwevents-audience">
                <option value=""><?php esc_html_e('All', 'hmw-events'); ?></option>
                <?php
                $audiences = get_terms(['taxonomy' => 'hmw_event_audience', 'hide_empty' => true]);
                if (!is_wp_error($audiences)):
                    foreach ($audiences as $aud):
                ?>
                    <option value="<?php echo esc_attr($aud->slug); ?>" <?php selected(($audience ?? ''), $aud->slug); ?>><?php echo esc_html($aud->name); ?></option>
                <?php endforeach; endif; ?>
            </select>
        </div>

        <div style="padding-bottom:1px;">
            <button type="submit" class="button button-primary">
                <?php esc_html_e('Apply Filters', 'hmw-events'); ?>
            </button>
            <?php if ($date_from || $date_to || ($payment_status ?? '') || ($attendance_status ?? '') || ($event_type ?? '') || ($audience ?? '')) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=hmwevents-reporting')); ?>" class="button" style="margin-left:6px;">
                    <?php esc_html_e('Clear', 'hmw-events'); ?>
                </a>
            <?php endif; ?>
        </div>

        <div style="margin-left:auto;padding-bottom:1px;">
            <a href="<?php echo esc_url($csv_url); ?>" class="button button-secondary">
                ⬇ <?php esc_html_e('Generate CSV', 'hmw-events'); ?>
            </a>
        </div>
    </form>

    <?php if ($date_from || $date_to) : ?>
        <p style="color:#666;font-style:italic;margin-bottom:10px;">
            <?php
            printf(
                esc_html__('Showing results from %1$s to %2$s', 'hmw-events'),
                '<strong>' . esc_html($date_from ?: 'beginning') . '</strong>',
                '<strong>' . esc_html($date_to ?: 'present') . '</strong>'
            );
            ?>
        </p>
    <?php endif; ?>

    <!-- Results table -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" style="width:35%;"><?php esc_html_e('Educator', 'hmw-events'); ?></th>
                <th scope="col" style="width:30%;"><?php esc_html_e('Email', 'hmw-events'); ?></th>
                <th scope="col" style="width:15%;text-align:right;"><?php esc_html_e('No. of Sales', 'hmw-events'); ?></th>
                <th scope="col" style="width:20%;text-align:right;"><?php esc_html_e('Total Booking Sales', 'hmw-events'); ?></th>
            </tr>
        </thead>

        <tbody>
            <?php if (empty($educators)) : ?>
                <tr>
                    <td colspan="4" style="text-align:center;color:#666;padding:30px;">
                        <?php esc_html_e('No results found for the selected date range.', 'hmw-events'); ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ($educators as $educator) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . absint($educator->educator_id))); ?>">
                                <?php echo esc_html($educator->educator_name); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($educator->educator_email); ?></td>
                        <td style="text-align:right;"><?php echo number_format((int) $educator->total_bookings); ?></td>
                        <td style="text-align:right;">$<?php echo number_format((float) $educator->total_sales, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>

        <?php if (!empty($educators)) : ?>
            <tfoot>
                <tr style="font-weight:bold;background:#f0f0f0;">
                    <td colspan="2"><?php esc_html_e('Totals', 'hmw-events'); ?></td>
                    <td style="text-align:right;"><?php echo number_format($grand_total_bookings); ?></td>
                    <td style="text-align:right;">$<?php echo number_format($grand_total_sales, 2); ?></td>
                </tr>
            </tfoot>
        <?php endif; ?>
    </table>

    <div class="hmwevents-compliance-box" style="margin-top:24px; padding:16px 20px; background:#f0f6fc; border:1px solid #72aee6; border-radius:4px;">
        <h3 style="margin-top:0;"><?php esc_html_e('Data Governance', 'hmw-events'); ?></h3>
        <table class="wp-list-table widefat fixed" style="margin-top:8px;">
            <thead>
                <tr>
                    <th style="width:45%;"><?php esc_html_e('Metric', 'hmw-events'); ?></th>
                    <th><?php esc_html_e('Status', 'hmw-events'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php global $wpdb; ?>
                <tr>
                    <td><?php esc_html_e('Archived events past 90-day retention', 'hmw-events'); ?></td>
                    <td>
                        <?php
                        $cutoff = date('Y-m-d H:i:s', strtotime('-90 days'));
                        $count = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'hmw_event' AND post_status = 'archived' AND post_modified < %s",
                            $cutoff
                        ));
                        echo $count > 0
                            ? esc_html(sprintf(__('%d events have PII pending cleanup on next cron run', 'hmw-events'), $count))
                            : esc_html__('No PII pending cleanup', 'hmw-events');
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Expired documents pending removal', 'hmw-events'); ?></td>
                    <td>
                        <?php
                        $docs_table = $wpdb->prefix . 'hmwevents_registration_documents';
                        $doc_count = (int) $wpdb->get_var(
                            "SELECT COUNT(*) FROM {$docs_table} WHERE retention_until <= NOW()"
                        );
                        echo $doc_count > 0
                            ? esc_html(sprintf(__('%d documents pending cleanup', 'hmw-events'), $doc_count))
                            : esc_html__('No documents pending cleanup', 'hmw-events');
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Booking audit entries', 'hmw-events'); ?></td>
                    <td>
                        <?php
                        $history_table = $wpdb->prefix . 'hmwevents_booking_history';
                        $history_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$history_table}");
                        echo esc_html(sprintf(
                            _n('%d entry recorded', '%d entries recorded', $history_count, 'hmw-events'),
                            $history_count
                        ));
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Cron schedule', 'hmw-events'); ?></td>
                    <td>
                        <?php
                        $next_pii = wp_next_scheduled('hmwevents_pii_retention_cleanup');
                        $next_doc = wp_next_scheduled('hmwevents_daily_retention_cleanup');
                        echo $next_pii
                            ? esc_html(sprintf(__('PII cleanup: %s', 'hmw-events'), date_i18n('Y-m-d H:i:s', $next_pii)))
                            : esc_html__('Not scheduled', 'hmw-events');
                        echo '<br>';
                        echo $next_doc
                            ? esc_html(sprintf(__('Document cleanup: %s', 'hmw-events'), date_i18n('Y-m-d H:i:s', $next_doc)))
                            : esc_html__('Not scheduled', 'hmw-events');
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
