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

use HMWEvents\Services\Emails\Handlers\StatusChangeHandler;
use HMWEvents\Services\EmailTemplateManager;
use HMWEvents\Services\EmailDispatchService;
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
        add_action('hmwevents_booking_cancelled', [$this, 'on_booking_cancelled'], 10, 3);
        add_action('hmwevents_refund_issued', [$this, 'on_refund_issued'], 10, 3);
        add_action('hmwevents_course_changed', [$this, 'on_course_changed'], 10, 3);

        // Async scheduling for reminders
        add_action('hmwevents_email_schedule_reminders', [$this, 'schedule_reminders'], 10, 1);

        // v2 event actions — use new EmailTemplateManager + EmailDispatchService
        add_action('hmwevents_payment_confirmed', [$this, 'on_v2_payment_confirmed'], 10, 3);
        add_action('hmwevents_payment_intent_created', [$this, 'on_v2_payment_intent_created'], 10, 4);
        add_action('hmwevents_waitlist_promoted', [$this, 'on_v2_waitlist_promoted'], 10, 2);
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
            $this->email_service->queue_educator_new_booking($booking_id, $booking_data);
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
            "SELECT currency FROM {$wpdb->prefix}hmwevents_bookings WHERE id = %d",
            $booking_id
        ));
        $currency = $booking->currency ?? 'AUD';
        
        // Cancel any pending emails that no longer apply
        $this->email_service->cancel_pending_emails(
            $booking_id,
            ['refund_issued'],
            'Refund issued'
        );

        $this->email_service->queue_status_change_email(
            $booking_id,
            StatusChangeHandler::TYPE_REFUND_ISSUED,
            [
                'refund_amount' => $this->format_currency($amount, $currency),
                'reason'        => $reason,
            ]
        );

        do_action('hmwevents_email_event', 'refund_issued', $booking_id);
    }

    /**
     * Fired when a course date/details change.
     *
     * @param int    $course_id Course post ID.
     * @param array  $changed_fields Changed fields.
     * @param array  $old_values Old values.
     * @return void
     */
    public function on_course_changed($course_id, $changed_fields = [], $old_values = [])
    {
        global $wpdb;

        // Get all bookings for this course
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hmwevents_bookings 
             WHERE event_post_id = %d AND status IN ('pending', 'confirmed')",
            $course_id
        ));

        // Queue email for each booking
        foreach ($bookings as $booking) {
            $this->email_service->queue_status_change_email(
                $booking->id,
                StatusChangeHandler::TYPE_COURSE_CHANGED,
                [
                    'changed_fields' => implode(', ', array_keys($changed_fields)),
                    'old_values'     => json_encode($old_values),
                ]
            );
        }

        do_action('hmwevents_email_event', 'course_changed', $course_id);
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
        return '$' . number_format($amount, 2);
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
    // V2 Event Handlers — use new EmailTemplateManager + DispatchService
    // ============================================================

    /**
     * Handle v2 payment confirmed — send receipt.
     */
    public function on_v2_payment_confirmed($intent, int $booking_group_id, int $transaction_id): void
    {
        if (!$this->is_email_enabled('payment_received')) {
            return;
        }

        $dispatch = new EmailDispatchService();

        $dispatch->enqueue([
            'email_type'      => 'payment_received',
            'trigger'         => 'payment_received',
            'recipient_email' => $this->get_booking_email($booking_group_id),
            'booking_id'      => $booking_group_id,
            'template_data'   => [
                'amount'            => number_format($intent->amount / 100, 2),
                'booking_reference' => $booking_group_id,
                'event_title'       => get_the_title($intent->metadata['event_id'] ?? 0),
            ],
        ]);
    }

    /**
     * Handle v2 payment intent created — no email yet, just log.
     */
    public function on_v2_payment_intent_created($intent, int $event_id, array $data, array $gst): void
    {
        // Payment intent created — email sent after confirmation.
        // No action needed at this stage.
    }

    /**
     * Handle v2 waitlist promotion — send invitation email.
     */
    public function on_v2_waitlist_promoted(object $entry, int $event_post_id): void
    {
        if (!$this->is_email_enabled('invitation_sent')) {
            return;
        }

        $dispatch = new EmailDispatchService();

        $dispatch->enqueue([
            'email_type'      => 'waitlist_promotion',
            'trigger'         => 'waitlist_promotion',
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
     * Handle v2 invoice created — send invoice email.
     */
    public function on_v2_invoice_created(int $event_id, array $invoice_data, array $registration_data): void
    {
        $dispatch = new EmailDispatchService();

        $dispatch->enqueue([
            'email_type'      => 'invoice_issued',
            'trigger'         => 'invoice_issued',
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

        $dispatch = new EmailDispatchService();

        $dispatch->enqueue([
            'email_type'      => 'invitation_sent',
            'trigger'         => 'invitation_sent',
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

        $this->email_service->queue_booking_confirmation($booking_id);
    }

    /**
     * Get the registrant email for a booking group.
     */
    private function get_booking_email(int $booking_group_id): string
    {
        $post = get_post($booking_group_id);
        if ($post) {
            return get_post_meta($post->ID, 'registrant_email', true) ?: '';
        }
        return '';
    }

    /**
     * Check if a specific email type is enabled.
     *
     * @param string $email_type The email type key.
     * @return bool
     */
    private function is_email_enabled(string $email_type): bool
    {
        $disabled = get_option('hmwevents_disabled_emails', []);
        return !in_array($email_type, (array) $disabled, true);
    }
}

