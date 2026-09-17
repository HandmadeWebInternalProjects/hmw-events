<?php

namespace HMWEvents\Services;

use HMWEvents\Helpers\EventFieldConfig;
use HMWEvents\Registry\EventTypeRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

use HMWEvents\PostTypes\Event;
use HMWEvents\PostTypes\EventLocation;
use HMWEvents\Helpers\GoogleMapField;

class EventDataService
{
    public function is_event(int $event_id): bool
    {
        $post = get_post($event_id);

        if (!$post) {
            return false;
        }

        return $post->post_type === Event::POST_TYPE;
    }

    /**
     * @param int $event_id
     * @return string|null Event start date in Y-m-d H:i:s format, or null.
     */
    public function get_start_date(int $event_id): ?string
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $value = get_post_meta($event_id, '_event_start_date', true);

        if (empty($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param int $event_id
     * @return string|null Event end date in Y-m-d H:i:s format, or null.
     */
    public function get_end_date(int $event_id): ?string
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $value = get_post_meta($event_id, '_event_end_date', true);

        if (empty($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param int $event_id
     * @return float|null Full price, or null for non-event posts.
     */
    public function get_price(int $event_id): ?float
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $value = get_post_meta($event_id, '_event_price', true);

        return $value === '' || $value === null ? 0.0 : (float) $value;
    }

    /**
     * @param int $event_id
     * @return float|null Deposit price, or null for non-event posts.
     */
    public function get_deposit(int $event_id): ?float
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $value = get_post_meta($event_id, '_event_deposit', true);

        return $value === '' || $value === null ? 0.0 : (float) $value;
    }

    /**
     * @param int $event_id
     * @return float Booking surcharge. Returns 0.0 for non-event posts.
     */
    public function get_surcharge(int $event_id): float
    {
        if (!$this->is_event($event_id)) {
            return 0.0;
        }

        $value = get_post_meta($event_id, '_event_surcharge', true);

        return $value === '' || $value === null ? 0.0 : (float) $value;
    }

    /**
     * @param int $event_id
     * @return string Surcharge type: 'flat' or 'percent'.
     */
    public function get_surcharge_type(int $event_id): string
    {
        if (!$this->is_event($event_id)) {
            return 'flat';
        }

        $value = get_post_meta($event_id, '_event_surcharge_type', true);

        return $value === 'percent' ? 'percent' : 'flat';
    }

    /**
     * @param int   $event_id
     * @param float $base_amount Booking base amount before surcharge.
     * @return float Surcharge amount for the given base amount.
     */
    public function calculate_surcharge(int $event_id, float $base_amount): float
    {
        $surcharge = $this->get_surcharge($event_id);

        if ($surcharge <= 0) {
            return 0.0;
        }

        if ($this->get_surcharge_type($event_id) === 'percent') {
            return round($base_amount * $surcharge / 100, 2);
        }

        return $surcharge;
    }

    /**
     * @param int $event_id
     * @return int|null Capacity, or null for non-event posts.
     */
    public function get_capacity(int $event_id): ?int
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $value = get_post_meta($event_id, '_event_capacity', true);

        if ($value === '' || $value === null) {
            return 0;
        }

        return max(0, (int) $value);
    }

    /**
     * @param int $event_id
     * @return int Max registrants per person. 0 means unlimited. Returns 0 for non-event posts.
     */
    public function get_max_per_registrant(int $event_id): int
    {
        if (!$this->is_event($event_id)) {
            return 0;
        }

        if (!$this->is_event_field_enabled($event_id, 'event_max_per_registrant')) {
            return 0;
        }

        $value = get_post_meta($event_id, '_event_max_per_registrant', true);

        if ($value === '' || $value === null) {
            return 0;
        }

        return max(0, (int) $value);
    }

    private function is_event_field_enabled(int $event_id, string $field_key): bool
    {
        $config = EventFieldConfig::read($event_id, '_event_template_override');
        if ($config === null || !isset($config['event_fields'])) {
            $config = EventFieldConfig::read($event_id);
        }

        if (is_array($config) && isset($config['event_fields']) && is_array($config['event_fields'])) {
            return !in_array($field_key, (array) ($config['event_fields']['hidden'] ?? []), true);
        }

        $terms = wp_get_object_terms($event_id, 'hmw_event_type', ['fields' => 'slugs']);
        $event_type = !is_wp_error($terms) && !empty($terms) ? (string) $terms[0] : '';

        return !$event_type || EventTypeRegistry::is_field_visible($event_type, $field_key);
    }

    /**
     * Whether the booking form and new bookings are enabled for this event.
     * Defaults to true when the toggle has never been saved.
     */
    public function bookings_enabled(int $event_id): bool
    {
        if (!$this->is_event($event_id)) {
            return false;
        }

        $value = get_post_meta($event_id, '_event_enable_bookings', true);

        return $value === '' || $value === null ? true : (bool) $value;
    }

    /**
     * How multi-session series bookings expand:
     * 'track'      — booking a time slot books every session with that time;
     * 'individual' — the attendee books only the specific sessions they picked.
     */
    public function get_session_booking_mode(int $event_id): string
    {
        if (!$this->is_event($event_id)) {
            return 'track';
        }

        $value = get_post_meta($event_id, '_event_session_booking_mode', true);

        return in_array($value, ['track', 'individual'], true) ? $value : 'track';
    }

    /**
     * @param int $event_id
     * @return bool|null Whether the event is free, or null for non-event posts.
     */
    public function get_is_free(int $event_id): ?bool
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        return (bool) get_post_meta($event_id, '_event_is_free', true);
    }

    /**
     * @param int $event_id
     * @return bool|null Whether net terms are allowed, or null for non-event posts.
     */
    public function get_allow_net_terms(int $event_id): ?bool
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        return (bool) get_post_meta($event_id, '_event_allow_net_terms', true);
    }

    /**
     * @param int $event_id
     * @return int|null ID of the assigned organizer user, or null.
     */
    public function get_organizer_id(int $event_id): ?int
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $value = get_post_meta($event_id, '_organizer_id', true);

        return $value !== '' && $value !== null ? (int) $value : null;
    }

    /**
     * @param int $event_id
     * @return int|null Related venue post ID, or null.
     */
    public function get_venue_id(int $event_id): ?int
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $value = get_post_meta($event_id, '_event_venue', true);

        return $value !== '' && $value !== null ? (int) $value : null;
    }

    private function get_venue_post(int $event_id): ?\WP_Post
    {
        $venue_id = $this->get_venue_id($event_id);

        if (!$venue_id) {
            return null;
        }

        $venue = get_post($venue_id);

        return $venue instanceof \WP_Post && $venue->post_type === EventLocation::POST_TYPE ? $venue : null;
    }

    /**
     * @param int $event_id
     * @return string|null Venue name, or null for non-event posts.
     */
    public function get_venue_name(int $event_id): ?string
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $venue = $this->get_venue_post($event_id);
        if ($venue) {
            return $venue->post_title;
        }

        $value = get_post_meta($event_id, '_event_venue_name', true);

        return empty($value) ? null : (string) $value;
    }

    /**
     * Retrieve the raw Google Map field array as stored by ACF.
     *
     * @param int $event_id
     * @return array|null The raw map data array (address, lat, lng, etc.), or null.
     */
    public function get_venue_address(int $event_id): ?array
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $venue = $this->get_venue_post($event_id);
        if ($venue) {
            $value = get_post_meta($venue->ID, EventLocation::META_ADDRESS, true);

            return is_array($value) ? $value : null;
        }

        $value = get_post_meta($event_id, '_event_venue_address', true);

        if (!is_array($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Return the human-readable venue address string from the Google Map field.
     *
     * Delegates to {@link GoogleMapField::get_address_string()} for parsing.
     *
     * @param int $event_id
     * @return string Address string. Empty string for non-event or missing address.
     */
    public function get_venue_address_string(int $event_id): string
    {
        if (!$this->is_event($event_id)) {
            return '';
        }

        $venue = $this->get_venue_post($event_id);
        if ($venue) {
            return GoogleMapField::get_address_string(get_post_meta($venue->ID, EventLocation::META_ADDRESS, true));
        }

        $raw = get_post_meta($event_id, '_event_venue_address', true);

        return GoogleMapField::get_address_string($raw);
    }

    /**
     * Resolve the suburb of an event's venue from the Google Map field.
     *
     * Prefers the denormalised `_event_venue_suburb` meta written by
     * VenueSuburbService, falling back to parsing the map field.
     *
     * @param int $event_id
     * @return string|null Suburb name, or null when unavailable.
     */
    public function get_venue_suburb(int $event_id): ?string
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $stored = get_post_meta($event_id, VenueSuburbService::META_EVENT_SUBURB, true);

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $address = $this->get_venue_address($event_id);

        if (!is_array($address)) {
            return null;
        }

        $parsed = GoogleMapField::parse_google_map_address($address);
        $suburb = trim((string) ($parsed['suburb'] ?? ''));

        return $suburb !== '' ? $suburb : null;
    }

    /**
     * @param int $event_id
     * @return string|null Webinar URL, or null for non-event posts.
     */
    public function get_webinar_url(int $event_id): ?string
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $value = get_post_meta($event_id, '_event_webinar_url', true);

        return empty($value) ? null : (string) $value;
    }

    /**
     * @param int $event_id
     * @return string|null Booking notes, or null for non-event posts.
     */
    public function get_booking_notes(int $event_id): ?string
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $value = get_post_meta($event_id, '_event_booking_notes', true);

        return empty($value) ? null : (string) $value;
    }

    /**
     * @param int $event_id
     * @return string|null Notification email override, or null.
     */
    public function get_notification_email(int $event_id): ?string
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $value = get_post_meta($event_id, '_event_notification_email', true);

        return empty($value) ? null : (string) $value;
    }

    /**
     * @param int $event_id
     * @return string Event currency code (AUD, USD, etc.). Defaults to 'AUD'. Returns 'AUD' for non-event posts.
     */
    public function get_currency(int $event_id): string
    {
        if (!$this->is_event($event_id)) {
            return 'AUD';
        }

        $value = get_post_meta($event_id, '_event_currency', true);

        return !empty($value) && is_string($value) ? $value : 'AUD';
    }

    /**
     * @param int $event_id
     * @return string|null Booking cutoff date string, or null for non-event or empty.
     */
    public function get_booking_cutoff(int $event_id): ?string
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $date = get_post_meta($event_id, '_event_booking_cutoff', true);

        return !empty($date) && is_string($date) ? $date : null;
    }

    /**
     * @param int $event_id
     * @return string|null Post status (publish, draft, fully_booked, cancelled, archived), or null.
     */
    public function get_status(int $event_id): ?string
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        $post = get_post($event_id);

        return $post ? $post->post_status : null;
    }

    /**
     * Return a combined payload of all current event metadata.
     *
     * @param int $event_id
     * @return array|null Full details array, or null for non-event posts.
     */
    public function get_details(int $event_id): ?array
    {
        if (!$this->is_event($event_id)) {
            return null;
        }

        return [
            'id'                   => $event_id,
            'start_date'           => $this->get_start_date($event_id),
            'end_date'             => $this->get_end_date($event_id),
            'price'                => $this->get_price($event_id),
            'deposit'              => $this->get_deposit($event_id),
            'surcharge'            => $this->get_surcharge($event_id),
            'surcharge_type'       => $this->get_surcharge_type($event_id),
            'currency'             => $this->get_currency($event_id),
            'booking_cutoff'       => $this->get_booking_cutoff($event_id),
            'capacity'             => $this->get_capacity($event_id),
            'max_per_registrant'   => $this->get_max_per_registrant($event_id),
            'is_free'              => $this->get_is_free($event_id),
            'bookings_enabled'     => $this->bookings_enabled($event_id),
            'allow_net_terms'      => $this->get_allow_net_terms($event_id),
            'organizer_id'         => $this->get_organizer_id($event_id),
            'venue_id'             => $this->get_venue_id($event_id),
            'venue_name'           => $this->get_venue_name($event_id),
            'venue_address'        => $this->get_venue_address($event_id),
            'venue_address_string' => $this->get_venue_address_string($event_id),
            'webinar_url'          => $this->get_webinar_url($event_id),
            'booking_notes'        => $this->get_booking_notes($event_id),
            'notification_email'   => $this->get_notification_email($event_id),
            'status'               => $this->get_status($event_id),
        ];
    }
}
