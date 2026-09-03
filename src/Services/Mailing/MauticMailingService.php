<?php

/**
 * Mautic Mailing Service.
 *
 * Syncs booking contacts to Mautic via Basic Auth REST API.
 * Uses WordPress HTTP API so no extra Composer dependency is needed.
 *
 * Behaviour mirrors the legacy hmw_educators_sync_mautic_contact_handler
 * function while adapting it to the current HMWEvents database schema.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Mailing;

use HMWEvents\Helpers\ConfigHelper;
use HMWEvents\Helpers\Encryption;
use HMWEvents\Interfaces\MailingServiceInterface;
use HMWEvents\Traits\MakesHttpRequests;

defined('ABSPATH') || die('Don\'t run this file directly!');

class MauticMailingService implements MailingServiceInterface
{
    use MakesHttpRequests;
    /**
     * Mautic instance base URL (no trailing slash).
     *
     * @var string
     */
    private string $base_url;

    /**
     * Mautic API username.
     *
     * @var string
     */
    private string $username;

    /**
     * Mautic API password (plain text, decrypted at runtime).
     *
     * @var string
     */
    private string $password;

    /**
     * @param string $base_url  e.g. https://info.example.com
     * @param string $username  Mautic Basic Auth username.
     * @param string $password  Mautic Basic Auth password (plain text).
     */
    public function __construct(string $base_url, string $username, string $password)
    {
        $this->base_url  = rtrim($base_url, '/');
        $this->username  = $username;
        $this->password  = $password;
    }

    /**
     * Create an instance from the HMWEvents plugin settings.
     *
     * Returns null when Mautic is not configured or the provider is not set to mautic.
     *
     * @return static|null
     */
    public static function from_settings(): ?static
    {
        $provider = ConfigHelper::get_option('hmwevents_mailing_provider', 'none');

        if ($provider !== 'mautic') {
            return null;
        }

        $base_url  = ConfigHelper::get_option('hmwevents_mautic_url', '');
        $username  = ConfigHelper::get_option('hmwevents_mautic_username', '');
        $encrypted = ConfigHelper::get_option('hmwevents_mautic_password', '');

        if (empty($base_url) || empty($username) || empty($encrypted)) {
            error_log('HMWEvents Mautic: Missing configuration – ensure Mautic URL, username and password are set in Settings.');
            return null;
        }

        // Decrypt the stored password
        $password = '';
        if (strpos($encrypted, 'def') === 0) {
            $password = Encryption::decrypt($encrypted) ?: '';
        } else {
            // Stored as plain text (shouldn't happen after first save, but guard anyway)
            $password = $encrypted;
        }

        if (empty($password)) {
            error_log('HMWEvents Mautic: Failed to decrypt Mautic password.');
            return null;
        }

        return new static($base_url, $username, $password);
    }

    /**
     * {@inheritdoc}
     */
    public function sync_booking(int $booking_id): void
    {
        global $wpdb;

        // ------------------------------------------------------------
        // 1. Load booking data from the DB
        // ------------------------------------------------------------
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT
                b.id,
                b.registrant_post_id AS customer_post_id,
                b.event_post_id,
                c.post_title   AS course_name,
                c.post_author  AS educator_id,
                bd.form_data
            FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " b
            INNER JOIN {$wpdb->posts} c  ON c.ID = b.event_post_id
            LEFT  JOIN " . \HMWEvents\Services\DatabaseService::get_table_name('booking_details') . " bd ON bd.booking_id = b.id
            WHERE b.id = %d
              AND b.deleted_at IS NULL
        ", $booking_id));

        if (!$row) {
            error_log("HMWEvents Mautic: booking_id {$booking_id} not found.");
            return;
        }

        // Decode JSON form data (questionnaire answers)
        $form_data = json_decode($row->form_data ?? '{}', true) ?: [];

        // Pull fields stored in customer post meta / form_data
        $customer_post_id = (int) $row->customer_post_id;
        $email            = get_post_meta($customer_post_id, 'registrant_email', true)
                            ?: ($form_data['email'] ?? '');
        $first_name       = $form_data['mothers_first_name'] ?? get_the_title($customer_post_id);
        $last_name        = $form_data['mothers_last_name'] ?? '';
        $postcode         = $form_data['postcode'] ?? '';
        $mailing_ok       = !empty($form_data['mailing_agreement']);

        $event_post_id = (int) $row->event_post_id;
        $course_end_date = ($event_post_id && get_post_type($event_post_id) === 'hmw_event')
            ? (get_field('_event_end_date', $event_post_id) ?: '')
            : '';

        // Educator display name
        $educator        = get_userdata((int) $row->educator_id);
        $educator_name   = $educator ? $educator->display_name : '';

        if (empty($email)) {
            error_log("HMWEvents Mautic: No email address for booking {$booking_id} – skipping sync.");
            return;
        }

        // ------------------------------------------------------------
        // 2. Build Mautic contact payload
        // ------------------------------------------------------------
        $tags = ['CourseBooked'];

        if ($mailing_ok) {
            $tags[] = 'MarketingOK';
        }

        $contact_data = [
            'firstname' => $first_name,
            'lastname'  => $last_name,
            'zipcode'   => $postcode,
            'tags'      => implode(',', $tags),
        ];

        if (!empty($course_end_date)) {
            $ts = strtotime($course_end_date);
            if ($ts !== false) {
                $contact_data['course_complete_date'] = gmdate('Y-m-d', $ts);
            } else {
                error_log("HMWEvents Mautic: could not parse course_end_date '{$course_end_date}' for booking {$booking_id}.");
            }
        }
        if (!empty($educator_name)) {
            $contact_data['educator_name'] = $educator_name;
        }

        error_log("HMWEvents Mautic: sync attempt for booking {$booking_id}: " . json_encode($contact_data));

        // ------------------------------------------------------------
        // 3. Look up existing contact by email
        // ------------------------------------------------------------
        $existing = $this->api_get('contacts', ['search' => 'email:' . $email, 'limit' => 1]);

        if (is_wp_error($existing)) {
            error_log("HMWEvents Mautic: lookup failed for booking {$booking_id}: " . $existing->get_error_message());
            return;
        }

        $contacts = $existing['contacts'] ?? [];

        // ------------------------------------------------------------
        // 4. Create or update
        // ------------------------------------------------------------
        if (!empty($contacts)) {
            $contact     = reset($contacts);
            $contact_id  = (int) $contact['id'];
            $response    = $this->api_post("contacts/{$contact_id}/edit", $contact_data, 'PATCH');
        } else {
            $contact_data['email'] = $email;
            $response = $this->api_post('contacts/new', $contact_data);
        }

        if (is_wp_error($response)) {
            error_log("HMWEvents Mautic: API error for booking {$booking_id}: " . $response->get_error_message());
            return;
        }

        if (!isset($response['contact']['id'])) {
            error_log("HMWEvents Mautic: unexpected response for booking {$booking_id}: " . json_encode($response));
            return;
        }

        error_log("HMWEvents Mautic: contact {$response['contact']['id']} synced for booking {$booking_id}.");
    }

    // ----------------------------------------------------------------
    // Mautic-specific HTTP helpers (use MakesHttpRequests for transport)
    // ----------------------------------------------------------------

    /**
     * GET from a Mautic API endpoint (relative to /api/).
     *
     * @param string $endpoint e.g. 'contacts'
     * @param array  $params   Query parameters.
     * @return array|\WP_Error
     */
    private function api_get(string $endpoint, array $params = [])
    {
        return $this->http_get(
            $this->base_url . '/api/' . ltrim($endpoint, '/'),
            $this->auth_headers(),
            $params
        );
    }

    /**
     * POST/PATCH to a Mautic API endpoint (relative to /api/).
     *
     * @param string $endpoint e.g. 'contacts/new'
     * @param array  $body     Request body data.
     * @param string $method   HTTP method, default 'POST'.
     * @return array|\WP_Error
     */
    private function api_post(string $endpoint, array $body, string $method = 'POST')
    {
        return $this->http_post(
            $this->base_url . '/api/' . ltrim($endpoint, '/'),
            $body,
            $this->auth_headers(),
            $method
        );
    }

    /**
     * Basic Auth header array for this Mautic instance.
     *
     * @return array
     */
    private function auth_headers(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
        ];
    }
}
