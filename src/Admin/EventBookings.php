<?php

namespace HMWEvents\Admin;

use HMWEvents\Services\DatabaseService;
use HMWEvents\PostTypes\Event;
use HMWEvents\Registry\EventTypeRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventBookings
{
    public function register(): void
    {
        add_action('add_meta_boxes_' . Event::POST_TYPE, [$this, 'add_meta_box']);
        add_action('wp_ajax_hmwevents_get_booking_details', [$this, 'ajax_get_booking_details']);
    }

    public function add_meta_box(): void
    {
        add_meta_box(
            'hmwevents_event_bookings',
            __('Event Bookings', 'hmw-events'),
            [$this, 'render_meta_box'],
            Event::POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render_meta_box(\WP_Post $post): void
    {
        $bookings = $this->get_bookings_for_event($post->ID);

        $type_terms = wp_get_object_terms($post->ID, 'hmw_event_type', ['fields' => 'slugs']);
        $type_slug = (!is_wp_error($type_terms) && !empty($type_terms)) ? sanitize_key((string) $type_terms[0]) : '';

        $registration_disabled = EventTypeRegistry::is_registration_disabled($type_slug);
        $external_registration = EventTypeRegistry::is_external_registration($type_slug);

        ?>
        <div class="hmwevents-bookings-wrap">

            <?php if ($external_registration): ?>
                <p class="description"><?php esc_html_e('This event uses external registration. No internal bookings will be created.', 'hmw-events'); ?></p>
            <?php elseif ($registration_disabled): ?>
                <p class="description"><?php esc_html_e('Public registration is disabled for this event type. Bookings must be added manually.', 'hmw-events'); ?></p>
            <?php endif; ?>

            <?php if (empty($bookings)): ?>
                <p><?php esc_html_e('No bookings yet.', 'hmw-events'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped hmwevents-bookings-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Booking #', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Customer', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Email', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Amount', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Status', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Payment', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Date', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Actions', 'hmw-events'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($booking->booking_number); ?></strong>
                                </td>
                                <td><?php echo esc_html(trim($booking->customer_first_name . ' ' . $booking->customer_last_name) ?: $booking->customer_name); ?></td>
                                <td><?php echo esc_html($booking->registrant_email); ?></td>
                                <td><?php echo esc_html(number_format((float) $booking->booking_amount, 2)); ?></td>
                                <td><?php echo esc_html($this->status_label($booking->status)); ?></td>
                                <td><?php echo esc_html($this->payment_status_label($booking->payment_status)); ?></td>
                                <td><?php echo esc_html(date('Y-m-d', strtotime($booking->created_at))); ?></td>
                                <td class="hmwevents-row-actions">
                                    <a href="#" class="hmwevents-edit-booking"
                                       data-booking-id="<?php echo (int) $booking->id; ?>"
                                       data-nonce="<?php echo esc_attr(wp_create_nonce('hmwevents_get_booking_details')); ?>">
                                        <?php esc_html_e('Edit', 'hmw-events'); ?>
                                    </a>
                                    <span class="separator">|</span>
                                    <a href="#" class="hmwevents-resend-confirmation"
                                       data-booking-id="<?php echo (int) $booking->id; ?>">
                                        <?php esc_html_e('Resend', 'hmw-events'); ?>
                                    </a>
                                    <?php if ($booking->payment_status === 'pending' || $booking->payment_status === 'invoiced'): ?>
                                        <span class="separator">|</span>
                                        <a href="#" class="hmwevents-send-payment-link"
                                           data-booking-id="<?php echo (int) $booking->id; ?>">
                                            <?php esc_html_e('Payment Link', 'hmw-events'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($booking->booking_source === 'manual' && in_array($booking->payment_status, ['pending', 'failed'], true)): ?>
                                        <span class="separator">|</span>
                                        <a href="#" class="hmwevents-mark-as-paid"
                                           data-booking-id="<?php echo (int) $booking->id; ?>">
                                            <?php esc_html_e('Mark as Paid', 'hmw-events'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!$external_registration): ?>
                <p style="margin-top:12px;">
                    <a href="#TB_inline?width=600&height=450&inlineId=hmwevents-add-booking-modal"
                       class="thickbox button button-primary"
                       id="hmwevents-add-booking-btn">
                        <?php esc_html_e('Add Manual Booking', 'hmw-events'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>

        <?php if (!$external_registration): ?>
        <div id="hmwevents-add-booking-modal" style="display:none;">
            <div style="padding:10px 20px;">
                <h2><?php esc_html_e('Add Manual Booking', 'hmw-events'); ?></h2>

                <div id="hmwevents-add-booking-form">
                    <input type="hidden" name="course_id" value="<?php echo (int) $post->ID; ?>">

                    <table class="form-table">
                        <tr>
                            <th><label for="hmw-manual-first-name"><?php esc_html_e('First Name', 'hmw-events'); ?> <span class="required">*</span></label></th>
                            <td><input type="text" name="customer_first_name" id="hmw-manual-first-name" class="regular-text" data-required></td>
                        </tr>
                        <tr>
                            <th><label for="hmw-manual-last-name"><?php esc_html_e('Last Name', 'hmw-events'); ?> <span class="required">*</span></label></th>
                            <td><input type="text" name="customer_last_name" id="hmw-manual-last-name" class="regular-text" data-required></td>
                        </tr>
                        <tr>
                            <th><label for="hmw-manual-email"><?php esc_html_e('Email', 'hmw-events'); ?> <span class="required">*</span></label></th>
                            <td><input type="email" name="registrant_email" id="hmw-manual-email" class="regular-text" data-required></td>
                        </tr>
                        <tr>
                            <th><label for="hmw-manual-phone"><?php esc_html_e('Phone', 'hmw-events'); ?></label></th>
                            <td><input type="text" name="customer_phone" id="hmw-manual-phone" class="regular-text"></td>
                        </tr>
                    </table>

                    <div id="hmwevents-add-booking-message" style="display:none; margin:12px 0;"></div>

                    <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                        <button type="button" class="button" id="hmwevents-add-booking-cancel" onclick="tb_remove();return false;"><?php esc_html_e('Cancel', 'hmw-events'); ?></button>
                        <button type="button" class="button button-primary" id="hmwevents-add-booking-submit"><?php esc_html_e('Create Booking', 'hmw-events'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div id="hmwevents-edit-booking-modal" class="hmwevents-modal" style="display:none;">
            <div class="hmwevents-modal-content">
                <span id="hmwevents-edit-booking-modal-close" class="hmwevents-modal-close">&times;</span>
                <h2 id="hmwevents-edit-booking-modal-title"><?php esc_html_e('Edit Booking', 'hmw-events'); ?></h2>
                <div id="hmwevents-edit-booking-modal-body"></div>
            </div>
        </div>
        <?php
    }

    /**
     * @return object[]
     */
    private function get_bookings_for_event(int $event_id): array
    {
        global $wpdb;

        $bookings_table = DatabaseService::get_table_name('bookings');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, r.post_title AS customer_name,
                COALESCE(pm_first.meta_value, '') AS customer_first_name,
                COALESCE(pm_last.meta_value, '') AS customer_last_name,
                COALESCE(pm_email.meta_value, '') AS registrant_email,
                COALESCE(pm_phone.meta_value, '') AS customer_phone
            FROM {$bookings_table} b
            LEFT JOIN {$wpdb->posts} r ON b.registrant_post_id = r.ID
            LEFT JOIN {$wpdb->postmeta} pm_email ON b.registrant_post_id = pm_email.post_id AND pm_email.meta_key = 'registrant_email'
            LEFT JOIN {$wpdb->postmeta} pm_first ON b.registrant_post_id = pm_first.post_id AND pm_first.meta_key = 'registrant_first_name'
            LEFT JOIN {$wpdb->postmeta} pm_last ON b.registrant_post_id = pm_last.post_id AND pm_last.meta_key = 'registrant_last_name'
            LEFT JOIN {$wpdb->postmeta} pm_phone ON b.registrant_post_id = pm_phone.post_id AND pm_phone.meta_key = 'registrant_phone'
            WHERE b.event_post_id = %d AND b.deleted_at IS NULL
            ORDER BY b.created_at DESC",
            $event_id
        )) ?: [];
    }

    public function ajax_get_booking_details(): void
    {
        check_ajax_referer('hmwevents_get_booking_details', 'nonce');

        if (!current_user_can('edit_hmw_events')) {
            wp_send_json_error(['message' => __('Permission denied.', 'hmw-events')]);
        }

        $booking_id = (int) ($_POST['booking_id'] ?? 0);
        if (!$booking_id) {
            wp_send_json_error(['message' => __('Invalid booking ID.', 'hmw-events')]);
        }

        global $wpdb;
        $bookings_table = DatabaseService::get_table_name('bookings');

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$bookings_table} WHERE id = %d AND deleted_at IS NULL",
            $booking_id
        ));

        if (!$booking) {
            wp_send_json_error(['message' => __('Booking not found.', 'hmw-events')]);
        }

        $registrant_id = (int) $booking->registrant_post_id;

        $current_values = [
            'customer_first_name' => (string) get_post_meta($registrant_id, 'registrant_first_name', true),
            'customer_last_name'  => (string) get_post_meta($registrant_id, 'registrant_last_name', true),
            'registrant_email'    => (string) get_post_meta($registrant_id, 'registrant_email', true),
            'customer_phone'      => (string) get_post_meta($registrant_id, 'registrant_phone', true),
            'status'              => $booking->status,
            'payment_status'      => $booking->payment_status,
        ];

        $details_table = DatabaseService::get_table_name('booking_details');
        $form_data_row = $wpdb->get_var($wpdb->prepare(
            "SELECT form_data FROM {$details_table} WHERE booking_id = %d",
            $booking_id
        ));

        if ($form_data_row) {
            $form_data = json_decode($form_data_row, true);
            if (is_array($form_data)) {
                foreach ($form_data as $key => $value) {
                    $current_values[$key] = $value;
                }
            }
        }

        wp_send_json_success([
            'booking_id'       => $booking->id,
            'booking_number'   => $booking->booking_number,
            'current_values'   => $current_values,
        ]);
    }

    private function status_label(string $status): string
    {
        $labels = [
            'pending'    => __('Pending', 'hmw-events'),
            'confirmed'  => __('Confirmed', 'hmw-events'),
            'cancelled'  => __('Cancelled', 'hmw-events'),
            'waitlisted' => __('Waitlisted', 'hmw-events'),
        ];

        return $labels[$status] ?? $status;
    }

    private function payment_status_label(string $status): string
    {
        $labels = [
            'pending'  => __('Pending', 'hmw-events'),
            'paid'     => __('Paid', 'hmw-events'),
            'refunded' => __('Refunded', 'hmw-events'),
            'failed'   => __('Failed', 'hmw-events'),
            'invoiced' => __('Invoiced', 'hmw-events'),
        ];

        return $labels[$status] ?? $status;
    }
}
