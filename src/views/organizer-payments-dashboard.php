<?php
/**
 * Educator Payments Dashboard Template.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

defined('ABSPATH') || die('Don\'t run this file directly!');
?>

<div class="wrap hmwevents-payments-dashboard">
    <h1><?php _e('My Payments', 'hmw-events'); ?></h1>

    <style>
        /* Responsive reflow for the payments dashboard */
        @media screen and (max-width: 960px) {
            .hmwevents-payments-dashboard .hmwevents-stats-grid {
                grid-template-columns: repeat(2, 1fr) !important;
            }
            .hmwevents-payments-dashboard .hmwevents-filters-grid {
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }
        @media screen and (max-width: 600px) {
            .hmwevents-payments-dashboard .hmwevents-stats-grid {
                grid-template-columns: 1fr !important;
                gap: 12px !important;
            }
            .hmwevents-payments-dashboard .hmwevents-filters-grid {
                grid-template-columns: 1fr !important;
            }
            .hmwevents-payments-dashboard .hmwevents-stat-card {
                padding: 14px !important;
            }
            .hmwevents-payments-dashboard .hmwevents-stat-card p {
                font-size: 24px !important;
            }
            .hmwevents-payments-dashboard .hmwevents-table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -10px;
                padding: 0 10px;
            }
            .hmwevents-payments-dashboard .hmwevents-table-wrapper table {
                min-width: 900px;
            }
        }
    </style>

    <!-- Summary Stats -->
    <div class="hmwevents-stats-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0;">
        <div class="hmwevents-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0; color: #666; font-size: 14px;"><?php _e('Total Paid Bookings', 'hmw-events'); ?></h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: #2271b1;">
                <?php echo number_format((int) ($stats->total_bookings ?? 0)); ?>
            </p>
        </div>

        <div class="hmwevents-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0; color: #666; font-size: 14px;"><?php _e('Total Revenue', 'hmw-events'); ?></h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: #00a32a;">
                $<?php echo number_format((float) ($stats->total_revenue ?? 0), 2); ?>
            </p>
        </div>

        <div class="hmwevents-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0; color: #666; font-size: 14px;"><?php _e('Pending Payments', 'hmw-events'); ?></h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: #dba617;">
                $<?php echo number_format((float) ($stats->pending_revenue ?? 0), 2); ?>
            </p>
        </div>

        <div class="hmwevents-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin: 0 0 10px 0; color: #666; font-size: 14px;"><?php _e('Refunded', 'hmw-events'); ?></h3>
            <p style="margin: 0; font-size: 32px; font-weight: bold; color: #d63638;">
                $<?php echo number_format((float) ($stats->refunded_amount ?? 0), 2); ?>
            </p>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" style="background: #fff; padding: 15px; border: 1px solid #ddd; margin: 20px 0;">
        <input type="hidden" name="page" value="hmwevents-educator-payments">
        
        <div class="hmwevents-filters-grid" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; align-items: end;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;"><?php _e('Status', 'hmw-events'); ?></label>
                <select name="status" class="regular-text" style="width: 100%;">
                    <option value="all" <?php selected($status_filter, 'all'); ?>><?php _e('All Statuses', 'hmw-events'); ?></option>
                    <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('Pending', 'hmw-events'); ?></option>
                    <option value="paid" <?php selected($status_filter, 'paid'); ?>><?php _e('Paid', 'hmw-events'); ?></option>
                    <option value="refunded" <?php selected($status_filter, 'refunded'); ?>><?php _e('Refunded', 'hmw-events'); ?></option>
                    <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php _e('Failed', 'hmw-events'); ?></option>
                </select>
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;"><?php _e('From Date', 'hmw-events'); ?></label>
                <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" class="regular-text" style="width: 100%;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;"><?php _e('To Date', 'hmw-events'); ?></label>
                <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" class="regular-text" style="width: 100%;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 600;"><?php _e('Search', 'hmw-events'); ?></label>
                <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Booking # or Customer', 'hmw-events'); ?>" class="regular-text" style="width: 100%;">
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="submit" class="button button-primary"><?php _e('Filter', 'hmw-events'); ?></button>
                <a href="?page=hmwevents-educator-payments" class="button"><?php _e('Reset', 'hmw-events'); ?></a>
            </div>
        </div>
    </form>

    <!-- Export Button -->
    <div style="margin: 20px 0; text-align: right;">
        <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=hmwevents_export_payments'), 'hmwevents_export_payments', 'nonce'); ?>" 
           class="button">
            <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
            <?php _e('Export to CSV', 'hmw-events'); ?>
        </a>
    </div>

    <!-- Payments Table -->
    <div class="hmwevents-table-wrapper">
    <table class="wp-list-table widefat fixed striped" style="background: #fff;">
        <thead>
            <tr>
                <th><?php _e('Booking #', 'hmw-events'); ?></th>
                <th><?php _e('Date', 'hmw-events'); ?></th>
                <th><?php _e('Customer', 'hmw-events'); ?></th>
                <th><?php _e('Course', 'hmw-events'); ?></th>
                <th><?php _e('Amount', 'hmw-events'); ?></th>
                <th><?php _e('Payment Status', 'hmw-events'); ?></th>
                <th><?php _e('Booking Status', 'hmw-events'); ?></th>
                <th><?php _e('Transaction ID', 'hmw-events'); ?></th>
                <th><?php _e('Notes', 'hmw-events'); ?></th>
                <th><?php _e('Actions', 'hmw-events'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bookings)): ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 40px;">
                        <?php _e('No payments found.', 'hmw-events'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($bookings as $booking): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($booking->booking_number); ?></strong>
                            <br>
                            <small style="color: #666;"><?php echo esc_html($booking->booking_reference); ?></small>
                        </td>
                        <td>
                            <?php echo date('M j, Y', strtotime($booking->created_at)); ?>
                            <br>
                            <small style="color: #666;"><?php echo date('g:i a', strtotime($booking->created_at)); ?></small>
                        </td>
                        <td>
                            <a href="<?php echo get_edit_post_link($booking->customer_id); ?>">
                                <?php echo esc_html($booking->customer_name); ?>
                            </a>
                        </td>
                        <td>
                            <a href="<?php echo get_edit_post_link($booking->course_id); ?>">
                                <?php echo esc_html($booking->course_name); ?>
                            </a>
                        </td>
                        <td>
                            <?php 
                            // Show total paid if multiple transactions, otherwise show booking amount
                            $display_amount = $booking->total_paid > 0 ? $booking->total_paid : $booking->booking_amount;
                            $currency = $booking->currency ?: 'AUD';
                            $currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);
                            ?>
                            <strong><?php echo esc_html($currency_symbol . number_format($display_amount, 2)); ?></strong>
                            <?php if (!empty($booking->voucher_code)): ?>
                                <br>
                                <small style="color: #10b981; font-weight: 600;" title="<?php echo esc_attr(sprintf(__('Voucher: %s (-%s%s)', 'hmw-events'), $booking->voucher_code, $currency_symbol, number_format($booking->voucher_amount, 2))); ?>">
                                    <span class="dashicons dashicons-tickets-alt" style="font-size: 14px; vertical-align: middle;"></span>
                                    <?php echo esc_html($booking->voucher_code); ?> (-<?php echo esc_html($currency_symbol . number_format($booking->voucher_amount, 2)); ?>)
                                </small>
                            <?php endif; ?>
                            <?php if (!empty($booking->coupon_code)): ?>
                                <br>
                                <small style="color: #2271b1; font-weight: 600;" title="<?php echo esc_attr(sprintf(__('Coupon: %s (-%s%s)', 'hmw-events'), $booking->coupon_code, $currency_symbol, number_format($booking->coupon_amount, 2))); ?>">
                                    <span class="dashicons dashicons-tag" style="font-size: 14px; vertical-align: middle;"></span>
                                    <?php echo esc_html($booking->coupon_code); ?> (-<?php echo esc_html($currency_symbol . number_format($booking->coupon_amount, 2)); ?>)
                                </small>
                            <?php endif; ?>
                            <?php if ($booking->ticket_type === 'deposit' && $booking->total_paid > $booking->booking_amount): ?>
                                <br>
                                <small style="color: #666;" title="<?php _e('Full payment received', 'hmw-events'); ?>">
                                    <?php _e('(Deposit + Remaining)', 'hmw-events'); ?>
                                </small>
                            <?php elseif ($booking->ticket_type === 'deposit' && $booking->payment_status === 'paid'): ?>
                                <br>
                                <small style="color: #dba617;" title="<?php _e('Deposit paid, remaining balance due', 'hmw-events'); ?>">
                                    <?php _e('(Deposit only)', 'hmw-events'); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $payment_status_colors = [
                                'paid' => '#00a32a',
                                'cancelled' => '#d63638',
                                'pending' => '#dba617',
                                'failed' => '#d63638',
                                'refunded' => '#d63638',
                                'processing' => '#2271b1',
                            ];
                            $color = $payment_status_colors[$booking->payment_status] ?? '#666';
                            ?>
                            <span style="display: inline-block; padding: 4px 8px; border-radius: 3px; background: <?php echo $color; ?>20; color: <?php echo $color; ?>; font-weight: 600; font-size: 12px;">
                                <?php echo esc_html(ucfirst($booking->payment_status)); ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $booking_status_colors = [
                                'confirmed' => '#00a32a',
                                'pending' => '#dba617',
                                'cancelled' => '#d63638',
                                'waitlisted' => '#2271b1',
                            ];
                            $color = $booking_status_colors[$booking->booking_status] ?? '#666';
                            ?>
                            <span style="display: inline-block; padding: 4px 8px; border-radius: 3px; background: <?php echo $color; ?>20; color: <?php echo $color; ?>; font-weight: 600; font-size: 12px;">
                                <?php echo esc_html(ucfirst($booking->booking_status)); ?>
                            </span>
                            <?php if ($booking->cancelled_at): ?>
                                <br>
                                <small style="color: #666;">
                                    <?php echo date('M j, Y', strtotime($booking->cancelled_at)); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($booking->gateway_transaction_id): ?>
                                <code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 11px;">
                                    <?php echo esc_html(substr($booking->gateway_transaction_id, 0, 20)); ?>
                                    <?php if (strlen($booking->gateway_transaction_id) > 20): ?>...<?php endif; ?>
                                </code>
                            <?php else: ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($booking->error_message): ?>
                                <span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php echo esc_attr($booking->error_message); ?>"></span>
                                <details style="display: inline;">
                                    <summary style="cursor: pointer; color: #2271b1;"><?php _e('View Error', 'hmw-events'); ?></summary>
                                    <div style="margin-top: 8px; padding: 8px; background: #fff8e5; border-left: 3px solid #dba617; font-size: 12px;">
                                        <?php echo esc_html($booking->error_message); ?>
                                    </div>
                                </details>
                            <?php elseif (!empty($booking->coupon_code)): ?>
                                <span style="color: #2271b1;">
                                    <?php echo esc_html(sprintf(__('Coupon applied: %s', 'hmw-events'), $booking->coupon_code)); ?>
                                </span>
                            <?php elseif (!empty($booking->voucher_code)): ?>
                                <span style="color: #10b981;">
                                    <?php echo esc_html(sprintf(__('Voucher applied: %s', 'hmw-events'), $booking->voucher_code)); ?>
                                </span>
                            <?php elseif ($booking->payment_status === 'failed'): ?>
                                <span style="color: #d63638;">
                                    <?php _e('Payment failed', 'hmw-events'); ?>
                                </span>
                            <?php elseif ($booking->payment_status === 'pending'): ?>
                                <span style="color: #dba617;">
                                    <?php _e('Awaiting payment', 'hmw-events'); ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $can_cancel = ($booking->booking_status !== 'cancelled');
                            $can_refund = ($booking->payment_status === 'paid' && $booking->booking_status !== 'cancelled');
                            $can_send_pending_link = in_array($booking->payment_status, ['pending', 'failed']);
                            // Show remaining link if: deposit booking, deposit is paid, but remaining payment not yet completed
                            $can_send_remaining_link = \HMWEvents\Helpers\Booking::needs_remaining_payment_link($booking);
                            ?>
                            
                            <?php if ($can_cancel || $can_refund || $can_send_pending_link || $can_send_remaining_link): ?>
                                <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                    <?php if ($can_send_pending_link): ?>
                                        <button type="button" 
                                                class="button button-small button-primary hmwevents-send-payment-link" 
                                                data-booking-id="<?php echo esc_attr($booking->id); ?>"
                                                data-payment-type="pending"
                                                title="<?php _e('Send payment link to customer', 'hmw-events'); ?>">
                                            <span class="dashicons dashicons-email" style="font-size: 16px; vertical-align: middle; margin-top: 2px;"></span>
                                            <?php _e('Send Payment Link', 'hmw-events'); ?>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($can_send_remaining_link): ?>
                                        <button type="button" 
                                                class="button button-small button-primary hmwevents-send-payment-link" 
                                                data-booking-id="<?php echo esc_attr($booking->id); ?>"
                                                data-payment-type="remaining"
                                                title="<?php _e('Send link for remaining balance', 'hmw-events'); ?>">
                                            <span class="dashicons dashicons-email" style="font-size: 16px; vertical-align: middle; margin-top: 2px;"></span>
                                            <?php _e('Send Remaining Link', 'hmw-events'); ?>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($can_cancel): ?>
                                        <button type="button" 
                                                class="button button-small hmwevents-cancel-booking" 
                                                data-booking-id="<?php echo esc_attr($booking->id); ?>"
                                                data-booking-number="<?php echo esc_attr($booking->booking_number); ?>">
                                            <?php _e('Cancel', 'hmw-events'); ?>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($can_refund): ?>
                                        <button type="button" 
                                                class="button button-small hmwevents-refund-booking" 
                                                data-booking-id="<?php echo esc_attr($booking->id); ?>"
                                                data-booking-number="<?php echo esc_attr($booking->booking_number); ?>"
                                                data-amount="<?php echo esc_attr($booking->booking_amount); ?>">
                                            <?php _e('Refund', 'hmw-events'); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div><!-- .hmwevents-table-wrapper -->

    <!-- Pagination -->
    <?php if (isset($pagination) && $pagination->has_pages()): ?>
        <?php echo $pagination->render('wordpress'); ?>
    <?php endif; ?>
</div>
