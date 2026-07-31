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
  // QUERY
  // ================================================================

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
        global $wpdb;
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT position FROM {$this->table} WHERE id = %d",
            $entry_id
        ));
        return $result ? (int) $result->position : 0;
    }

    // ================================================================
    // PROMOTE
    // ================================================================

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
}
