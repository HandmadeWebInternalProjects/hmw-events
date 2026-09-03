<?php
/**
 * Email Event Hooks.
 *
 * Integrates email system with booking lifecycle events.
 * Listens to booking status changes and triggers appropriate emails.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails;

use HMWEvents\Registry\EmailTypeRegistry;
use HMWEvents\Services\Emails\Handlers\StatusChangeHandler;
use HMWEvents\Registry\CommunicationTriggerMatrix;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Email Event Hooks Class.
 */
class EmailEventHooks
{
    /**
     * Email service instance.
     *
     * @var EmailService
     */
    private $email_service;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->email_service = new EmailService();
    }

    /**
     * Register all hooks.
     *
     * @return void
     */
    public function register()
    {
        // Booking lifecycle events
        add_action('hmwevents_booking_created', [$this, 'on_booking_created'], 10, 2);
        add_action('hmwevents_group_booking_created', [$this, 'on_group_booking_created'], 10, 4);
        add_action('hmwevents_booking_cancelled', [$this, 'on_booking_cancelled'], 10, 3);
        add_action('hmwevents_refund_issued', [$this, 'on_refund_issued'], 10, 3);
        add_action('hmwevents_event_changed', [$this, 'on_event_changed'], 10, 3);

        // Async scheduling for reminders
        add_action('hmwevents_email_schedule_reminders', [$this, 'schedule_reminders'], 10, 1);

        // v2 event actions
        add_action('hmwevents_waitlist_promoted', [$this, 'on_v2_waitlist_promoted'], 10, 2);
        add_action('hmwevents_waitlist_joined', [$this, 'on_v2_waitlist_joined'], 10, 2);
        add_action('hmwevents_invoice_created', [$this, 'on_v2_invoice_created'], 10, 3);
        add_action('hmwevents_invitation_token_created', [$this, 'on_v2_invitation_token_created'], 10, 1);

        // Payment gateway — auto-queue receipt when payment status transitions to paid
        add_action('hmwevents_payment_received', [$this, 'on_payment_received'], 10, 2);
    }

    /**
     * Fired when a booking is created.
     *
     * @param int   $booking_id Booking ID.
     * @param array $booking_data Booking data.
     * @return void
     */
    public function on_booking_created($booking_id, $booking_data = [])
    {
        if ($this->is_email_enabled('booking_confirmation')) {
            $this->email_service->queue_booking_confirmation($booking_id, $booking_data);
        }

        if ($this->is_email_enabled('new_booking_notify')) {
            $this->email_service->queue_organizer_new_booking($booking_id, $booking_data);
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(
                'hmwevents_email_schedule_reminders',
                ['booking_id' => $booking_id]
            );
        } else {
            $this->schedule_reminders($booking_id);
        }

        do_action('hmwevents_email_event', 'booking_created', $booking_id);
    }

    /**
     * Fired when a multi-session booking group is created.
     *
     * Sends ONE confirmation listing every booked session, ONE organizer
     * notification, and schedules reminders per session row.
     *
     * @param int   $group_id Booking group ID.
     * @param array $booking_ids All booking row IDs in the group.
     * @param int   $primary_booking_id Primary (amount-bearing) booking row ID.
     * @param array $booking_data Gateway booking payload.
     * @return void
     */
    public function on_group_booking_created($group_id, $booking_ids, $primary_booking_id, $booking_data = [])
    {
        $booking_ids = array_values(array_filter(array_map('intval', (array) $booking_ids)));
        $primary_booking_id = (int) $primary_booking_id;

        if ($this->is_email_enabled('booking_confirmation')) {
            $this->email_service->queue_group_booking_confirmation($primary_booking_id, $booking_ids, (array) $booking_data);
        }

        if ($this->is_email_enabled('new_booking_notify')) {
            $this->email_service->queue_organizer_new_booking($primary_booking_id, (array) $booking_data);
        }

        foreach ($booking_ids as $booking_id) {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    'hmwevents_email_schedule_reminders',
                    ['booking_id' => $booking_id]
                );
            } else {
                $this->schedule_reminders($booking_id);
            }
        }

        do_action('hmwevents_email_event', 'group_booking_created', $primary_booking_id);
    }

    /**
     * Fired when a booking is cancelled.
     *
     * @param int    $booking_id Booking ID.
     * @param string $reason Cancellation reason.
     * @param array  $booking_data Optional booking data.
     * @return void
     */
    public function on_booking_cancelled($booking_id, $reason = '', $booking_data = [])
    {
        $this->email_service->cancel_pending_emails(
            $booking_id,
            ['booking_cancelled'],
            'Booking cancelled'
        );

        if ($this->is_email_enabled('booking_cancelled')) {
            $this->email_service->queue_status_change_email(
                $booking_id,
                StatusChangeHandler::TYPE_BOOKING_CANCELLED,
                ['reason' => $reason]
            );
        }

        do_action('hmwevents_email_event', 'booking_cancelled', $booking_id);
    }

    /**
     * Fired when a refund is issued.
     *
     * @param int    $booking_id Booking ID.
     * @param float  $amount Refund amount.
     * @param string $reason Refund reason.
     * @return void
     */
    public function on_refund_issued($booking_id, $amount, $reason = '')
    {
        global $wpdb;
        
        // Get booking currency
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT currency FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " WHERE id = %d",
            $booking_id
        ));
        $currency = $booking->currency ?? 'AUD';
        
        // Cancel any pending emails that no longer apply
        $this->email_service->cancel_pending_emails(
            $booking_id,
            ['refund_issued'],
            'Refund issued'
        );

        if ($this->is_email_enabled('refund_issued')) {
            $this->email_service->queue_status_change_email(
                $booking_id,
                StatusChangeHandler::TYPE_REFUND_ISSUED,
                [
                    'refund_amount' => $this->format_currency($amount, $currency),
                    'reason'        => $reason,
                ]
            );
        }

        do_action('hmwevents_email_event', 'refund_issued', $booking_id);
    }

    /**
     * Fired when event details change.
     *
     * @param int    $event_id Event post ID.
     * @param array  $changed_fields Changed fields.
     * @param array  $old_values Old values.
     * @return void
     */
    public function on_event_changed($event_id, $changed_fields = [], $old_values = [])
    {
        if (!$this->is_email_enabled('event_changed')) {
            return;
        }

        global $wpdb;

        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " 
             WHERE event_post_id = %d AND status IN ('pending', 'confirmed')",
            $event_id
        ));

        foreach ($bookings as $booking) {
            $this->email_service->queue_status_change_email(
                $booking->id,
                StatusChangeHandler::TYPE_EVENT_CHANGED,
                [
                    'changed_fields' => implode(', ', array_keys($changed_fields)),
                    'old_values'     => json_encode($old_values),
                ]
            );
        }

        do_action('hmwevents_email_event', 'event_changed', $event_id);
    }

    /**
     * Schedule reminder emails for a booking.
     *
     * @param int $booking_id Booking ID.
     * @return void
     */
    public function schedule_reminders($booking_id)
    {
        $booking_id = is_array($booking_id) ? ($booking_id['booking_id'] ?? null) : $booking_id;
        if (empty($booking_id)) {
            return;
        }

        if ($this->is_email_enabled('reminder_7_days')) {
            $this->email_service->queue_course_reminder($booking_id, 7);
        }

        if ($this->is_email_enabled('reminder_1_day')) {
            $this->email_service->queue_course_reminder($booking_id, 1);
        }

        if ($this->is_email_enabled('post_event')) {
            $this->email_service->queue_post_course_email($booking_id, 1);
        }
    }

    /**
     * Format currency value.
     *
     * @param float $amount Amount.
     * @param string $currency Currency code (defaults to AUD).
     * @return string Formatted currency.
     */
    private function format_currency($amount, $currency = 'AUD')
    {
        $currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);
        return $currency_symbol . number_format($amount, 2);
    }

    /**
     * Get email service instance.
     *
     * @return EmailService Email service.
     */
    public function get_email_service()
    {
        return $this->email_service;
    }

    // ============================================================
    // V2 Event Handlers
    // ============================================================

    /**
     * Handle v2 waitlist promotion — send invitation email.
     */
    public function on_v2_waitlist_promoted(object $entry, int $event_post_id): void
    {
        if (!$this->is_email_enabled('waitlist_promotion')) {
            return;
        }

        $this->email_service->queue_notification([
            'email_type'      => 'waitlist_promotion',
            'recipient_email' => $entry->recipient_email ?? '',
            'template_data'   => [
                'first_name'       => '',
                'event_title'      => get_the_title($event_post_id),
                'registration_url' => get_permalink($event_post_id),
                'expiry_hours'     => 48,
            ],
        ]);
    }

    /**
     * Handle v2 waitlist joined — send confirmation email.
     */
    public function on_v2_waitlist_joined(object $entry, int $event_post_id): void
    {
        if (!$this->is_email_enabled('waitlist_joined')) {
            return;
        }

        $this->email_service->queue_notification([
            'email_type'      => 'waitlist_joined',
            'recipient_email' => $entry->recipient_email ?? '',
            'template_data'   => [
                'first_name'  => '',
                'event_title' => get_the_title($event_post_id),
                'position'    => (int) ($entry->position ?? 0),
            ],
        ]);
    }

    /**
     * Handle v2 invoice created — send invoice email.
     */
    public function on_v2_invoice_created(int $event_id, array $invoice_data, array $registration_data): void
    {
        if (!$this->is_email_enabled('invoice_issued')) {
            return;
        }

        $this->email_service->queue_notification([
            'email_type'      => 'invoice_issued',
            'recipient_email' => $registration_data['meta']['registrant_email'] ?? '',
            'template_data'   => [
                'first_name'  => $registration_data['meta']['registrant_first_name'] ?? '',
                'event_title' => get_the_title($event_id),
                'amount'      => number_format($invoice_data['amount'] ?? 0, 2),
                'gst_breakdown' => '',
            ],
        ]);
    }

    /**
     * Handle v2 invitation token created — send invitation email.
     */
    public function on_v2_invitation_token_created(array $token_data): void
    {
        if (!$this->is_email_enabled('invitation_sent')) {
            return;
        }

        $this->email_service->queue_notification([
            'email_type'      => 'invitation_sent',
            'recipient_email' => $token_data['recipient_email'] ?? '',
            'template_data'   => [
                'first_name'       => '',
                'event_title'      => get_the_title($token_data['event_post_id']),
                'registration_url' => $token_data['registration_url'],
            ],
        ]);
    }

    /**
     * Handle payment received event from payment gateway.
     *
     * Fires when a booking's payment_status transitions to 'paid'.
     * Automatically queues a tax invoice / payment receipt email.
     */
    public function on_payment_received(int $booking_id, array $data): void
    {
        if (!$this->is_email_enabled('payment_received')) {
            return;
        }

        $this->email_service->queue_payment_receipt($booking_id);
    }

    /**
     * Check if a specific email type is enabled.
     *
     * @param string $email_type The email type key.
     * @return bool
     */
    private function is_email_enabled(string $email_type): bool
    {
        $email_type = EmailTypeRegistry::canonical($email_type);

        if (!EmailTypeRegistry::is_valid($email_type)) {
            return true;
        }

        $disabled = array_map([EmailTypeRegistry::class, 'canonical'], (array) get_option('hmwevents_disabled_emails', []));
        return !in_array($email_type, $disabled, true);
    }
}

