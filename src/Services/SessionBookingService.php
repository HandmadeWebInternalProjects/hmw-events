<?php

namespace HMWEvents\Services;

use HMWEvents\Services\Hooks;
use HMWEvents\Helpers\EventHelper;

defined('ABSPATH') || die('Don\'t run this file directly!');

class SessionBookingService
{
    public const MODE_TRACK = 'track';
    public const MODE_INDIVIDUAL = 'individual';

    private ?EventDataService $event_data_service = null;
    private ?CapacityService $capacity_service = null;

    public function register(): void
    {
        add_action('hmwevents_session_created', [$this, 'sync_track_bookings_to_session'], 10, 2);
    }

    private function event_data(): EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new EventDataService();
        }
        return $this->event_data_service;
    }

    private function capacity(): CapacityService
    {
        if ($this->capacity_service === null) {
            $this->capacity_service = new CapacityService();
        }
        return $this->capacity_service;
    }

    /**
     * All bookable occurrences for a series: the parent occurrence plus its
     * published child sessions and custom-date clones.
     *
     * @return array[] Each: id, title, start, end, time, price, capacity,
     *                 remaining, is_full, is_parent.
     */
    public function get_bookable_sessions(int $event_id): array
    {
        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'hmw_event') {
            return [];
        }

        $parent_price = (float) $this->event_data()->get_price($event_id);

        $occurrences = [];

        if (!$event->post_parent) {
            $occurrences[] = $this->build_occurrence($event, $parent_price, true);
        }

        foreach (Hooks::get_all_sessions($event) as $session) {
            $occurrences[] = $this->build_occurrence($session, $parent_price, false);
        }

        usort($occurrences, static function ($a, $b) {
            return strcmp($a['start'], $b['start']);
        });

        return $occurrences;
    }

    private function build_occurrence(\WP_Post $post, float $fallback_price, bool $is_parent): array
    {
        $start = (string) get_post_meta($post->ID, '_event_start_date', true);
        $end = (string) get_post_meta($post->ID, '_event_end_date', true);
        $raw_price = get_post_meta($post->ID, '_event_price', true);

        $capacity = $this->capacity()->get_capacity($post->ID);
        $remaining = $capacity > 0 ? $this->capacity()->get_remaining_places($post->ID) : null;

        return [
            'id'        => (int) $post->ID,
            'title'     => $post->post_title,
            'start'     => $start,
            'end'       => $end,
            'time'      => strlen($start) > 10 ? substr($start, 11) : '',
            'price'     => $raw_price !== '' && $raw_price !== null ? (float) $raw_price : $fallback_price,
            'capacity'  => $capacity,
            'remaining' => $remaining,
            'is_full'   => $capacity > 0 && $remaining !== null && $remaining < 1,
            'is_parent' => $is_parent,
        ];
    }

    /**
     * Whether any bookable occurrence of the series carries a price above
     * zero — used to require payment on session-picker forms where the
     * parent event's own price may be empty or free.
     */
    public function has_priced_bookable_sessions(int $event_id): bool
    {
        foreach ($this->get_bookable_sessions($event_id) as $occurrence) {
            if (!$occurrence['is_full'] && (float) $occurrence['price'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expand a booking selection into booking rows.
     *
     * Track mode: the picked slot's time-of-day selects every matching
     * session in the series; the full amount rides on the primary row.
     * Individual mode: each picked session becomes its own row priced at
     * session price × attendee count.
     *
     * When the selected attendance option carries a positive price, the
     * option is the product: the option price × attendee count is charged
     * once (on the primary row) regardless of how many sessions are picked,
     * overriding per-session prices. Free options fall back to per-session
     * pricing.
     *
     * @return array|\WP_Error {rows, total, booking_type, session_ids, slot}
     */
    public function expand_selection(int $event_id, array $picked_ids, ?string $mode = null, int $attendee_count = 1, ?string $attendance_type = null)
    {
        $attendee_count = max(1, $attendee_count);
        $picked_ids = array_values(array_unique(array_map('intval', $picked_ids)));
        $picked_ids = array_filter($picked_ids, fn($id) => $id > 0);

        if (empty($picked_ids)) {
            return new \WP_Error('no_sessions_selected', __('Please select at least one session to attend.', 'hmw-events'));
        }

        $mode = $mode ?: $this->event_data()->get_session_booking_mode($event_id);
        $option_price = $this->resolve_option_price($event_id, $attendance_type);

        $index = [];
        foreach ($this->get_bookable_sessions($event_id) as $occurrence) {
            $index[$occurrence['id']] = $occurrence;
        }

        foreach ($picked_ids as $id) {
            if (!isset($index[$id])) {
                return new \WP_Error(
                    'invalid_session',
                    sprintf(__('Session #%d is not part of this event.', 'hmw-events'), $id)
                );
            }
        }

        foreach ($picked_ids as $id) {
            $occurrence = $index[$id];
            if ($occurrence['is_full'] || ($occurrence['remaining'] !== null && $occurrence['remaining'] < $attendee_count)) {
                return new \WP_Error(
                    'session_full',
                    sprintf(
                        __('Sorry, "%s" no longer has enough places available.', 'hmw-events'),
                        $occurrence['title']
                    )
                );
            }
        }

        if ($mode === self::MODE_INDIVIDUAL) {
            if ($option_price !== null) {
                $total = $option_price * $attendee_count;
                $rows = [];
                foreach ($picked_ids as $i => $id) {
                    $rows[] = ['event_post_id' => $id, 'booking_amount' => $i === 0 ? $total : 0.0];
                }

                return [
                    'rows'         => $rows,
                    'total'        => $total,
                    'booking_type' => 'package',
                    'session_ids'  => $picked_ids,
                    'slot'         => null,
                ];
            }

            $rows = [];
            $total = 0.0;
            foreach ($picked_ids as $id) {
                $amount = $index[$id]['price'] * $attendee_count;
                $rows[] = ['event_post_id' => $id, 'booking_amount' => $amount];
                $total += $amount;
            }

            return [
                'rows'         => $rows,
                'total'        => $total,
                'booking_type' => 'package',
                'session_ids'  => $picked_ids,
                'slot'         => null,
            ];
        }

        $primary_id = $picked_ids[0];
        $slot = $index[$primary_id]['time'];
        $track_ids = [];

        foreach ($index as $occurrence) {
            if ($occurrence['time'] === $slot) {
                $track_ids[] = $occurrence['id'];
            }
        }

        $total = ($option_price ?? $index[$primary_id]['price']) * $attendee_count;
        $rows = [];
        foreach ($track_ids as $i => $id) {
            $rows[] = [
                'event_post_id'   => $id,
                'booking_amount'  => $i === 0 ? $total : 0.0,
            ];
        }

        return [
            'rows'         => $rows,
            'total'        => $total,
            'booking_type' => 'recurring',
            'session_ids'  => $track_ids,
            'slot'         => $slot,
        ];
    }

    /**
     * Price for the selected attendance option when it carries one, so
     * session-picker bookings charge the option rather than per-session
     * meta prices. Null when no paid option applies.
     */
    private function resolve_option_price(int $event_id, ?string $attendance_type): ?float
    {
        if ($attendance_type === null || $attendance_type === '') {
            return null;
        }

        $option = EventHelper::resolve_attendance_option($event_id, $attendance_type);

        if ($option === null || (float) $option->price <= 0) {
            return null;
        }

        return (float) $option->price;
    }

    /**
     * Extend existing track bookings when a new session is added to a series.
     *
     * For every active recurring group booked on the same slot, a booking row
     * is created for the new session and the session's availability is
     * decremented. Individual-mode selections are never extended.
     *
     * Hooked to hmwevents_session_created.
     *
     * @return int Number of booking rows created.
     */
    public function sync_track_bookings_to_session(int $session_id, int $parent_id = 0): int
    {
        global $wpdb;

        $session = get_post($session_id);
        if (!$session || $session->post_type !== 'hmw_event') {
            return 0;
        }

        $start = (string) get_post_meta($session_id, '_event_start_date', true);
        if ($start === '' || strlen($start) <= 10) {
            return 0;
        }
        $slot = substr($start, 11);

        $root_id = $session->post_parent
            ? (int) $session->post_parent
            : (int) get_post_meta($session_id, '_cloned_from', true);
        if (!$root_id) {
            return 0;
        }

        $sibling_ids = [];
        foreach ($this->get_bookable_sessions($root_id) as $occurrence) {
            if ($occurrence['id'] === (int) $session_id) {
                continue;
            }
            if ($occurrence['time'] === $slot) {
                $sibling_ids[] = $occurrence['id'];
            }
        }

        if (empty($sibling_ids)) {
            return 0;
        }

        $bookings_table = DatabaseService::get_table_name('bookings');
        $groups_table = DatabaseService::get_table_name('booking_groups');

        $placeholders = implode(',', array_fill(0, count($sibling_ids), '%d'));
        $group_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT booking_group_id, registrant_post_id, ticket_quantity, attendance_option_id, ticket_type, status, payment_status
             FROM {$bookings_table}
             WHERE event_post_id IN ({$placeholders})
               AND status IN ('pending', 'confirmed')
               AND deleted_at IS NULL",
            ...$sibling_ids
        ));

        if (empty($group_rows)) {
            return 0;
        }

        $created = 0;

        foreach ($group_rows as $row) {
            $group = $wpdb->get_row($wpdb->prepare(
                "SELECT booking_type, payment_status, metadata FROM {$groups_table} WHERE id = %d",
                $row->booking_group_id
            ));

            if (!$group || $group->booking_type !== 'recurring') {
                continue;
            }
            if (in_array($group->payment_status, ['refunded', 'failed', 'cancelled'], true)) {
                continue;
            }

            $metadata = json_decode((string) $group->metadata, true) ?: [];
            if (($metadata['mode'] ?? '') !== 'track' || ($metadata['slot'] ?? '') !== $slot) {
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$bookings_table} WHERE booking_group_id = %d AND event_post_id = %d",
                $row->booking_group_id,
                $session_id
            ));
            if ($exists) {
                continue;
            }

            $ticket_quantity = max(1, (int) ($row->ticket_quantity ?? 1));
            $booking_number = 'CB-' . date('Ymd') . '-' . strtoupper(wp_generate_password(6, false));

            $inserted = $wpdb->insert(
                $bookings_table,
                [
                    'booking_group_id'     => (int) $row->booking_group_id,
                    'booking_number'       => $booking_number,
                    'event_post_id'        => (int) $session_id,
                    'registrant_post_id'   => (int) $row->registrant_post_id,
                    'attendance_option_id' => $row->attendance_option_id,
                    'ticket_type'          => $row->ticket_type,
                    'ticket_quantity'      => $ticket_quantity,
                    'booking_amount'       => 0.0,
                    'status'               => $row->status,
                    'payment_status'       => $row->payment_status,
                    'booking_source'       => 'track_extension',
                    'created_at'           => current_time('mysql'),
                ],
                ['%d', '%s', '%d', '%d', '%d', '%s', '%d', '%f', '%s', '%s', '%s', '%s']
            );

            if ($inserted === false) {
                continue;
            }

            $booking_id = (int) $wpdb->insert_id;
            $created++;

            $wpdb->query($wpdb->prepare(
                "UPDATE " . DatabaseService::get_table_name('event_availability') . "
                 SET booked_count = GREATEST(0, booked_count + %d),
                     available_count = GREATEST(0, available_count - %d)
                 WHERE event_post_id = %d",
                $ticket_quantity,
                $ticket_quantity,
                $session_id
            ));

            $wpdb->insert(
                DatabaseService::get_table_name('booking_history'),
                [
                    'booking_id'    => $booking_id,
                    'field_changed' => 'status',
                    'old_value'     => '',
                    'new_value'     => (string) $row->status,
                    'changed_by'    => get_current_user_id(),
                    'change_reason' => 'Track extension — session added to series',
                    'created_at'    => current_time('mysql'),
                ],
                ['%d', '%s', '%s', '%s', '%d', '%s', '%s']
            );
        }

        if ($created > 0) {
            do_action('hmwevents_track_bookings_extended', $session_id, $created);
        }

        return $created;
    }

    /**
     * Cancel every active booking row for a session (organizer removed the
     * session). Attendees are emailed via the booking_cancelled action.
     *
     * @return int Number of rows cancelled.
     */
    public function cancel_bookings_for_session(int $session_id, string $reason = ''): int
    {
        global $wpdb;

        $bookings_table = DatabaseService::get_table_name('bookings');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, ticket_quantity FROM {$bookings_table}
             WHERE event_post_id = %d
               AND status IN ('pending', 'confirmed')
               AND deleted_at IS NULL",
            $session_id
        ));

        if (empty($rows)) {
            return 0;
        }

        $reason = $reason !== '' ? $reason : __('Session removed by organizer', 'hmw-events');
        $cancelled = 0;

        foreach ($rows as $row) {
            $updated = $wpdb->update(
                $bookings_table,
                ['status' => 'cancelled', 'cancelled_at' => current_time('mysql')],
                ['id' => (int) $row->id],
                ['%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                continue;
            }

            do_action('hmwevents_booking_cancelled', (int) $row->id, $reason, []);
            $cancelled++;
        }

        return $cancelled;
    }
}
