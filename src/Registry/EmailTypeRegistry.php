<?php

/**
 * Email Type Registry.
 *
 * Single source of truth for the email types the plugin sends and that
 * can be disabled from the Email Queue "Notification Settings" UI.
 * Consumers: the Email Queue admin screen (settings list), the
 * EmailEventHooks::is_email_enabled() guards, and the queue table's
 * type column display.
 *
 * Keys are the email_type vocabulary stored in hmwevents_email_queue.
 * Trigger-matrix keys (CommunicationTriggerMatrix) are a separate
 * vocabulary and are not renamed here.
 *
 * @package HMWEvents\Registry
 * @since 2.0.0
 */

namespace HMWEvents\Registry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EmailTypeRegistry
{
    /**
     * Legacy email_type values persisted in hmwevents_email_queue before
     * a rename, mapped to their canonical replacement.
     *
     * @var array<string, string>
     */
    private const LEGACY_ALIASES = [
        'course_changed' => 'event_changed',
    ];

    /**
     * Cached email type definitions.
     *
     * @var array<string, string>|null
     */
    private static ?array $types = null;

    /**
     * Get all registered email types with their labels.
     *
     * @return array<string, string> email_type => label
     */
    public static function all(): array
    {
        if (self::$types !== null) {
            return self::$types;
        }

        self::$types = [
            'booking_confirmation' => __('Booking Confirmation (Customer)', 'hmw-events'),
            'new_booking_notify'   => __('New Booking Notification (Admin)', 'hmw-events'),
            'payment_received'     => __('Payment Receipt', 'hmw-events'),
            'booking_cancelled'    => __('Booking Cancelled', 'hmw-events'),
            'reminder_7_days'      => __('7-Day Reminder', 'hmw-events'),
            'reminder_1_day'       => __('1-Day Reminder', 'hmw-events'),
            'post_event'           => __('Post-Event Follow-up', 'hmw-events'),
            'invitation_sent'      => __('Invitation Sent', 'hmw-events'),
            'waitlist_joined'      => __('Waitlist Joined', 'hmw-events'),
            'waitlist_promotion'   => __('Waitlist Promotion', 'hmw-events'),
            'invoice_issued'       => __('Invoice Issued', 'hmw-events'),
            'refund_issued'        => __('Refund Issued', 'hmw-events'),
            'event_changed'        => __('Event Details Changed', 'hmw-events'),
        ];

        return self::$types;
    }

    /**
     * Get all registered email type keys.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Get the human label for an email type.
     *
     * @param string $key Email type key.
     * @return string Label, or the raw key when unknown.
     */
    public static function label(string $key): string
    {
        return self::all()[self::canonical($key)] ?? $key;
    }

    /**
     * Check whether an email type key is registered.
     *
     * @param string $key Email type key.
     * @return bool
     */
    public static function is_valid(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /**
     * Resolve an email type key to its canonical form.
     *
     * Legacy keys stored before a rename resolve to their replacement;
     * unknown keys are returned unchanged.
     *
     * @param string $key Email type key.
     * @return string
     */
    public static function canonical(string $key): string
    {
        return self::LEGACY_ALIASES[$key] ?? $key;
    }

    /**
     * Check whether an email type key is a legacy alias.
     *
     * @param string $key Email type key.
     * @return bool
     */
    public static function is_legacy(string $key): bool
    {
        return isset(self::LEGACY_ALIASES[$key]);
    }
}
