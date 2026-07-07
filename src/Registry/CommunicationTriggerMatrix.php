<?php

/**
 * Communication Trigger Matrix.
 *
 * Maps event lifecycle events to email template keys.
 * The matrix is segmented by event type so different archetypes
 * can use different template sets.
 *
 * Default triggers (universal across all event types):
 *   booking_confirmed, booking_cancelled, payment_received,
 *   refund_issued, waitlist_promotion, invoice_issued,
 *   reminder_7_days, reminder_1_day, post_event
 *
 * Per-event-type overrides come from EventTypeRegistry::get_comm_template().
 *
 * @package HMWEvents\Registry
 * @since 2.0.0
 */

namespace HMWEvents\Registry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class CommunicationTriggerMatrix
{
    /**
     * Get the template key for a given trigger + event type.
     */
    public static function resolve(string $trigger, string $event_type = ''): string
    {
        if ($event_type) {
            $override = EventTypeRegistry::get_comm_template($event_type, $trigger);
            if ($override && $override !== (self::get_all_triggers()[$trigger] ?? null)) {
                return $override;
            }
        }

        return $trigger;
    }

    /**
     * Get all registered trigger keys with their labels.
     *
     * @return array<string, string>
     */
    public static function get_all_triggers(): array
    {
        return [
            'booking_confirmed'   => __('Booking Confirmed', 'hmw-events'),
            'booking_cancelled'   => __('Booking Cancelled', 'hmw-events'),
            'payment_received'    => __('Payment Received', 'hmw-events'),
            'refund_issued'       => __('Refund Issued', 'hmw-events'),
            'waitlist_promotion'  => __('Waitlist Promotion', 'hmw-events'),
            'invoice_issued'      => __('Invoice Issued', 'hmw-events'),
            'reminder_7_days'     => __('7-Day Reminder', 'hmw-events'),
            'reminder_1_day'      => __('1-Day Reminder', 'hmw-events'),
            'post_event'          => __('Post-Event Follow-Up', 'hmw-events'),
            'event_changed'       => __('Event Details Changed', 'hmw-events'),
            'new_booking_notify'  => __('New Booking (Organizer)', 'hmw-events'),
            'invitation_sent'     => __('Invitation Sent', 'hmw-events'),
        ];
    }

    /**
     * Get all triggers that apply to a given event type, with resolved template keys.
     *
     * @return array<string, string> trigger => template_key
     */
    public static function get_for_event_type(string $event_type): array
    {
        $result = [];
        foreach (self::get_all_triggers() as $trigger => $label) {
            $result[$trigger] = self::resolve($trigger, $event_type);
        }
        return $result;
    }

    /**
     * Get the applicable triggers for a specific payment outcome.
     *
     * @return string[]
     */
    public static function get_triggers_for_payment_outcome(string $payment_status): array
    {
        return match ($payment_status) {
            'paid'      => ['booking_confirmed', 'payment_received', 'new_booking_notify'],
            'invoiced'  => ['booking_confirmed', 'invoice_issued', 'new_booking_notify'],
            'refunded'  => ['refund_issued'],
            'failed'    => [],
            'pending'   => ['new_booking_notify'],
            default     => [],
        };
    }

    /**
     * Get the triggers that should be cancelled when a booking is cancelled.
     *
     * @return string[]
     */
    public static function get_cancellable_triggers(): array
    {
        return [
            'reminder_7_days',
            'reminder_1_day',
            'post_event',
            'event_changed',
        ];
    }
}
