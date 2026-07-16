<?php

/**
 * Waitlist Service.
 *
 * Manages the operational waitlist for fully-booked events.
 * Handles join, position tracking, promotion (with token generation),
 * conversion to booking, and expiry.
 *
 * Uses the hmwevents_waitlist table.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class WaitlistService
{
    private string $table;

    public function __construct()
    {
        $this->table = DatabaseService::get_table_name('waitlist');
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('hmwevents_daily_waitlist_cleanup', [$this, 'expire_old_entries']);
        add_filter('hmwevents_event_is_full', [$this, 'check_event_full'], 10, 2);
    }

    // ================================================================
    // JOIN / LEAVE
    // ================================================================

    /**
     * Join the waitlist for an event.
     *
     * @param int    $event_post_id
     * @param int    $registrant_post_id
     * @param string $email
     * @return int|\WP_Error  Waitlist entry ID or error.
     */
    public function join(int $event_post_id, int $registrant_post_id, string $email): int|\WP_Error
    {
        global $wpdb;

        // Check if already on the waitlist for this event
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} 
             WHERE event_post_id = %d AND registrant_post_id = %d AND status IN ('waiting', 'notified')",
            $event_post_id,
            $registrant_post_id
        ));

        if ($existing) {
            return new \WP_Error(
                'already_waitlisted',
                __('You are already on the waitlist for this event.', 'hmw-events')
            );
        }

        // Get next position
        $max_position = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(position) FROM {$this->table} WHERE event_post_id = %d",
            $event_post_id
        ));

        $position = $max_position + 1;

        $result = $wpdb->insert($this->table, [
            'event_post_id'      => $event_post_id,
            'registrant_post_id' => $registrant_post_id,
            'position'           => $position,
            'status'             => 'waiting',
            'created_at'         => current_time('mysql'),
            'updated_at'         => current_time('mysql'),
        ], ['%d', '%d', '%d', '%s', '%s', '%s']);

        if ($result === false) {
            return new \WP_Error('db_error', __('Failed to join waitlist.', 'hmw-events'));
        }

        $entry_id = (int) $wpdb->insert_id;

        // Update waitlist count in event availability
        $this->increment_waitlist_count($event_post_id);

        /**
         * Action: hmwevents_waitlist_joined
         *
         * @param int $entry_id
         * @param int $event_post_id
         * @param int $registrant_post_id
         * @param int $position
         */
        do_action('hmwevents_waitlist_joined', $entry_id, $event_post_id, $registrant_post_id, $position);

        return $entry_id;
    }

    /**
     * Leave the waitlist.
     */
    public function leave(int $entry_id): bool
    {
        global $wpdb;

        $entry = $this->get($entry_id);
        if (!$entry) {
            return false;
        }

        $result = $wpdb->update(
            $this->table,
            ['status' => 'cancelled', 'updated_at' => current_time('mysql')],
            ['id' => $entry_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($result !== false) {
            $this->decrement_waitlist_count($entry->event_post_id);
        }

        return $result !== false;
    }

    // ================================================================
    // QUERY
    // ================================================================

    /**
     * Get a single waitlist entry.
     */
    public function get(int $entry_id): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $entry_id
        ));
    }

    /**
     * Get the waitlist for an event.
     *
     * @param int    $event_post_id
     * @param string $status  Filter by status. Empty = all active.
     * @return object[]
     */
    public function get_for_event(int $event_post_id, string $status = ''): array
    {
        global $wpdb;

        $where = 'event_post_id = %d';
        $params = [$event_post_id];

        if ($status) {
            $where .= ' AND status = %s';
            $params[] = $status;
        } else {
            $where .= " AND status NOT IN ('cancelled', 'expired')";
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY position ASC",
            ...$params
        )) ?: [];
    }

    /**
     * Count waitlist entries.
     */
    public function count_for_event(int $event_post_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} 
             WHERE event_post_id = %d AND status IN ('waiting', 'notified')",
            $event_post_id
        ));
    }

    /**
     * Get the position of an entry.
     */
    public function get_position(int $entry_id): int
    {
        $entry = $this->get($entry_id);
        return $entry ? (int) $entry->position : 0;
    }

    // ================================================================
    // PROMOTE
    // ================================================================

    /**
     * Promote the next person on the waitlist.
     *
     * Marks the top entry as 'notified', sets an expiry, and returns
     * the entry so a registration token/invitation email can be sent.
     *
     * @param int $event_post_id
     * @param int $notification_expiry_hours  How long they have to register.
     * @return object|null  The promoted entry or null if no one waiting.
     */
    public function promote_next(int $event_post_id, int $notification_expiry_hours = 48): ?object
    {
        global $wpdb;

        $next = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE event_post_id = %d AND status = 'waiting' 
             ORDER BY position ASC LIMIT 1",
            $event_post_id
        ));

        if (!$next) {
            return null;
        }

        $expires_at = gmdate('Y-m-d H:i:s', strtotime("+{$notification_expiry_hours} hours"));

        $wpdb->update(
            $this->table,
            [
                'status'      => 'notified',
                'notified_at' => current_time('mysql'),
                'expires_at'  => $expires_at,
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $next->id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        $next->status     = 'notified';
        $next->notified_at = current_time('mysql');
        $next->expires_at  = $expires_at;

        /**
         * Action: hmwevents_waitlist_promoted
         *
         * @param object $entry       The promoted waitlist entry.
         * @param int    $event_post_id
         */
        do_action('hmwevents_waitlist_promoted', $next, $event_post_id);

        return $next;
    }

    public function promote_entry(int $entry_id, int $event_post_id, int $expiry_hours = 48): ?object
    {
        global $wpdb;

        $entry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND event_post_id = %d AND status = 'waiting'",
            $entry_id,
            $event_post_id
        ));

        if (!$entry) {
            return null;
        }

        $expires_at = gmdate('Y-m-d H:i:s', strtotime("+{$expiry_hours} hours"));

        $wpdb->update(
            $this->table,
            [
                'status'      => 'notified',
                'notified_at' => current_time('mysql'),
                'expires_at'  => $expires_at,
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $entry_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        do_action('hmwevents_waitlist_promoted', $entry, $event_post_id);

        return $entry;
    }

    /**
     * Convert a waitlist entry to confirmed (spot was taken).
     */
    public function convert(int $entry_id): bool
    {
        global $wpdb;

        $entry = $this->get($entry_id);
        if (!$entry || !in_array($entry->status, ['waiting', 'notified'], true)) {
            return false;
        }

        $wpdb->update(
            $this->table,
            ['status' => 'converted', 'updated_at' => current_time('mysql')],
            ['id' => $entry_id],
            ['%s', '%s'],
            ['%d']
        );

        $this->decrement_waitlist_count($entry->event_post_id);

        return true;
    }

    // ================================================================
    // MAINTENANCE
    // ================================================================

    /**
     * Expire old notified entries that weren't claimed.
     *
     * Hooked to daily cron.
     *
     * @return int Number expired.
     */
    public function expire_old_entries(): int
    {
        global $wpdb;

        $expired = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table} 
             WHERE status = 'notified' AND expires_at <= %s",
            current_time('mysql')
        ));

        $count = 0;
        foreach ($expired as $id) {
            $wpdb->update(
                $this->table,
                ['status' => 'expired', 'updated_at' => current_time('mysql')],
                ['id' => $id],
                ['%s', '%s'],
                ['%d']
            );
            $count++;
        }

        if ($count > 0) {
            error_log("HMWEvents: Expired {$count} waitlist entries.");
        }

        return $count;
    }

    /**
     * Filter: check if an event is full (including for "By Invitation" events).
     *
     * "By Invitation" events always appear full to the public.
     */
    public function check_event_full(bool $is_full, int $event_post_id): bool
    {
        if ($is_full) {
            return true;
        }

        if (\HMWEvents\PostTypes\Event::is_invitation_only($event_post_id)) {
            return true;
        }

        if ($this->count_for_event($event_post_id) > 0) {
            return true;
        }

        return $is_full;
    }

    // ================================================================
    // HELPERS
    // ================================================================

    private function increment_waitlist_count(int $event_post_id): void
    {
        global $wpdb;
        $table = DatabaseService::get_table_name('event_availability');
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET waitlisted_count = waitlisted_count + 1 WHERE event_post_id = %d",
            $event_post_id
        ));
    }

    private function decrement_waitlist_count(int $event_post_id): void
    {
        global $wpdb;
        $table = DatabaseService::get_table_name('event_availability');
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET waitlisted_count = GREATEST(waitlisted_count - 1, 0) WHERE event_post_id = %d",
            $event_post_id
        ));
    }
}
