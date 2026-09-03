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

use HMWEvents\PostTypes\Registrant;

defined('ABSPATH') || die('Don\'t run this file directly!');

class WaitlistService
{
    public const CLEANUP_HOOK = 'hmwevents_daily_waitlist_cleanup';
    public const JOINED_HOOK = 'hmwevents_waitlist_joined';

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

        if (function_exists('as_next_scheduled_action')) {
            add_action('action_scheduler_init', [$this, 'schedule_cleanup']);
        } else {
            add_action('init', [$this, 'schedule_cleanup']);
        }
    }

    /**
     * Ensure the waitlist cleanup cron is scheduled.
     */
    public function schedule_cleanup(): void
    {
        if (function_exists('as_next_scheduled_action')) {
            if (!as_next_scheduled_action(self::CLEANUP_HOOK, [], 'hmw-events')) {
                as_schedule_recurring_action(
                    strtotime('tomorrow 2:00 AM'),
                    DAY_IN_SECONDS,
                    self::CLEANUP_HOOK,
                    [],
                    'hmw-events'
                );
            }
            return;
        }

        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 2:00 AM'), 'daily', self::CLEANUP_HOOK);
        }
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
  // JOIN
  // ================================================================

    /**
     * Add someone to the waitlist for a fully-booked event.
     *
     * Creates an hmw_registrant stub and a waiting entry at the next position.
     * Fires the hmwevents_waitlist_joined action on success.
     *
     * @param int    $event_post_id Event post ID.
     * @param string $first_name    First name.
     * @param string $last_name     Last name.
     * @param string $email         Email address.
     * @return object|\WP_Error The created entry or an error.
     */
    public function join(int $event_post_id, string $first_name, string $last_name, string $email)
    {
        global $wpdb;

        $email = sanitize_email($email);
        if (!$email || !is_email($email)) {
            return new \WP_Error('invalid_email', __('A valid email address is required.', 'hmw-events'));
        }

        $post = get_post($event_post_id);
        if (!$post || $post->post_type !== 'hmw_event') {
            return new \WP_Error('invalid_event', __('Event not found.', 'hmw-events'));
        }

        if ($this->find_active_entry_for_email($event_post_id, $email)) {
            return new \WP_Error(
                'already_waitlisted',
                __('This email address is already on the waitlist for this event.', 'hmw-events')
            );
        }

        $registrant_id = $this->create_registrant_stub($event_post_id, $first_name, $last_name, $email);
        if (is_wp_error($registrant_id)) {
            return $registrant_id;
        }

        $position = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(position), 0) FROM {$this->table} WHERE event_post_id = %d",
            $event_post_id
        )) + 1;

        $inserted = $wpdb->insert(
            $this->table,
            [
                'event_post_id'      => $event_post_id,
                'registrant_post_id' => $registrant_id,
                'position'           => $position,
                'status'             => 'waiting',
            ],
            ['%d', '%d', '%d', '%s']
        );

        if (!$inserted) {
            wp_delete_post($registrant_id, true);
            return new \WP_Error('waitlist_failed', __('Could not join the waitlist. Please try again later.', 'hmw-events'));
        }

        $entry = (object) [
            'id'                 => (int) $wpdb->insert_id,
            'event_post_id'      => $event_post_id,
            'registrant_post_id' => $registrant_id,
            'position'           => $position,
            'status'             => 'waiting',
            'recipient_email'    => $email,
        ];

        do_action(self::JOINED_HOOK, $entry, $event_post_id);

        return $entry;
    }

    /**
     * Find an active (waiting/notified) waitlist entry for an email on an event.
     */
    private function find_active_entry_for_email(int $event_post_id, string $email): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT w.id FROM {$this->table} w
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = w.registrant_post_id AND pm.meta_key = 'registrant_email'
             WHERE w.event_post_id = %d AND pm.meta_value = %s
               AND w.status IN ('waiting', 'notified')",
            $event_post_id,
            $email
        ));
    }

    /**
     * Create an hmw_registrant post holding the waitlister's details.
     *
     * @return int|\WP_Error
     */
    private function create_registrant_stub(int $event_post_id, string $first_name, string $last_name, string $email): int|\WP_Error
    {
        $first_name = sanitize_text_field($first_name);
        $last_name  = sanitize_text_field($last_name);
        $full_name  = trim($first_name . ' ' . $last_name);

        if ($full_name === '') {
            $full_name = $email;
        }

        $post_id = wp_insert_post([
            'post_type'   => Registrant::POST_TYPE,
            'post_title'  => $full_name,
            'post_status' => 'publish',
            'meta_input'  => [
                'registrant_first_name' => $first_name,
                'registrant_last_name'  => $last_name,
                'registrant_email'      => $email,
                '_event_id'             => $event_post_id,
                '_waitlisted'           => 1,
            ],
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        wp_set_object_terms((int) $post_id, $email, 'registrant_email', false);

        return (int) $post_id;
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

        $entry->recipient_email = get_post_meta((int) $entry->registrant_post_id, 'registrant_email', true);

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
}
