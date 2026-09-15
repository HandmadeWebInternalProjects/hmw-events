<?php

namespace HMWEvents\Services;

use HMWEvents\Helpers\GoogleMapField;
use HMWEvents\PostTypes\Event;
use HMWEvents\PostTypes\EventLocation;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Derives suburbs from venue Google Map fields and keeps a denormalised
 * `_event_venue_suburb` meta value on events for fast location filtering.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */
class VenueSuburbService
{
    public const META_EVENT_SUBURB = '_event_venue_suburb';

    private const LEGACY_STATE_TAXONOMY = 'hmw_event_state';
    private const CACHE_TRANSIENT = 'hmwevents_venue_suburbs';
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;
    private const UPGRADE_OPTION = 'hmwevents_venue_suburbs_migrated';

    private ?EventDataService $event_data_service = null;

    private function event_data(): EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new EventDataService();
        }
        return $this->event_data_service;
    }

    public function register(): void
    {
        add_action('acf/save_post', [$this, 'handle_acf_save'], 20);
        add_action('admin_init', [$this, 'run_upgrade_routine']);
    }

    /**
     * Recompute suburbs after ACF saves a venue or event.
     *
     * @param int|string $post_id Post ID (or options-page identifier).
     */
    public function handle_acf_save(int|string $post_id): void
    {
        $id = is_numeric($post_id) ? (int) $post_id : 0;

        if ($id <= 0) {
            return;
        }

        $post = get_post($id);

        if (!$post instanceof \WP_Post) {
            return;
        }

        if ($post->post_type === EventLocation::POST_TYPE) {
            $this->propagate_venue_suburb($post->ID);
            return;
        }

        if ($post->post_type === Event::POST_TYPE) {
            $this->write_event_suburb($post->ID, $this->compute_event_suburb($post->ID));
            $this->flush_cache();
        }
    }

    /**
     * @param int $venue_id
     * @return string Suburb extracted from the venue map field. Empty when unavailable.
     */
    public function suburb_for_venue(int $venue_id): string
    {
        return $this->suburb_from_address(get_post_meta($venue_id, EventLocation::META_ADDRESS, true));
    }

    /**
     * @param mixed $address Raw ACF google_map field value.
     */
    public function suburb_from_address(mixed $address): string
    {
        if (!is_array($address)) {
            return '';
        }

        $parsed = GoogleMapField::parse_google_map_address($address);

        return trim((string) ($parsed['suburb'] ?? ''));
    }

    /**
     * @param int $event_id
     */
    public function compute_event_suburb(int $event_id): string
    {
        return $this->suburb_from_address($this->event_data()->get_venue_address($event_id));
    }

    /**
     * @param int    $event_id
     * @param string $suburb Suburb name, or empty string to clear the meta.
     */
    public function write_event_suburb(int $event_id, string $suburb): void
    {
        if ($suburb === '') {
            delete_post_meta($event_id, self::META_EVENT_SUBURB);
            return;
        }

        update_post_meta($event_id, self::META_EVENT_SUBURB, $suburb);
    }

    /**
     * Recompute and fan out a venue's suburb to every event referencing it.
     *
     * @param int $venue_id
     * @return int Number of events updated.
     */
    public function propagate_venue_suburb(int $venue_id): int
    {
        global $wpdb;

        $suburb = $this->suburb_for_venue($venue_id);

        $event_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            '_event_venue',
            (string) $venue_id
        ));

        $updated = 0;

        foreach ($event_ids as $event_id) {
            $this->write_event_suburb((int) $event_id, $suburb);
            $updated++;
        }

        $this->flush_cache();

        return $updated;
    }

    /**
     * Suburbs of all currently listed events (publish / fully_booked).
     *
     * Cached in a transient; invalidated on venue and event saves.
     *
     * @return string[] Sorted suburb names.
     */
    public function get_available_suburbs(): array
    {
        $cached = get_transient(self::CACHE_TRANSIENT);

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND pm.meta_value != ''
               AND p.post_type = %s
               AND p.post_status IN ('publish', 'fully_booked')
             ORDER BY pm.meta_value ASC",
            self::META_EVENT_SUBURB,
            Event::POST_TYPE
        ));

        $suburbs = array_values(array_filter(array_map('strval', (array) $rows)));

        set_transient(self::CACHE_TRANSIENT, $suburbs, self::CACHE_TTL);

        return $suburbs;
    }

    public function flush_cache(): void
    {
        delete_transient(self::CACHE_TRANSIENT);
    }

    /**
     * Rebuild `_event_venue_suburb` for every event, clearing stale values.
     *
     * @return int Number of events processed.
     */
    public function recalculate_all(): int
    {
        global $wpdb;

        $event_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
            Event::POST_TYPE
        ));

        $updated = 0;

        foreach ($event_ids as $event_id) {
            $this->write_event_suburb((int) $event_id, $this->compute_event_suburb((int) $event_id));
            $updated++;
        }

        $this->flush_cache();

        return $updated;
    }

    /**
     * One-time upgrade: remove the retired state taxonomy rows and backfill
     * suburb meta for existing events and venues.
     */
    public function run_upgrade_routine(): void
    {
        if (get_option(self::UPGRADE_OPTION)) {
            return;
        }

        $this->cleanup_state_taxonomy();
        $this->recalculate_all();

        update_option(self::UPGRADE_OPTION, 1);
    }

    private function cleanup_state_taxonomy(): void
    {
        global $wpdb;

        $tt_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
            self::LEGACY_STATE_TAXONOMY
        ));

        if (empty($tt_ids)) {
            return;
        }

        $in = implode(',', array_map('intval', $tt_ids));

        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->terms} WHERE term_id NOT IN (SELECT term_id FROM {$wpdb->term_taxonomy})");
    }
}
