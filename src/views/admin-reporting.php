<?php
/**
 * Admin Reporting Page Template.
 *
 * Variables available from Reporting::render_page():
 *   $date_from           string   Current date_from filter value
 *   $date_to             string   Current date_to filter value
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
        'action'    => 'hmwevents_export_reporting_csv',
        'date_from' => rawurlencode($date_from),
        'date_to'   => rawurlencode($date_to),
        'nonce'     => wp_create_nonce('hmwevents_reporting_csv_nonce'),
    ],
    admin_url('admin-post.php')
);
?>

<div class="wrap hmwevents-reporting">
    <h1><?php esc_html_e('Reporting', 'hmw-events'); ?></h1>

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

        <div style="padding-bottom:1px;">
            <button type="submit" class="button button-primary">
                <?php esc_html_e('Apply Filters', 'hmw-events'); ?>
            </button>
            <?php if ($date_from || $date_to) : ?>
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
</div>
