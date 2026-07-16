<?php

/**
 * Invitation Token Service.
 *
 * Manages private registration tokens for invitation-only events
 * and waitlist promotions. Tokens are single-use, expirable, and
 * stored in hmwevents_private_registration_tokens.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class InvitationTokenService
{
    private string $table;

    /**
     * Token length in characters.
     */
    private const TOKEN_LENGTH = 64;

    public function __construct()
    {
        $this->table = DatabaseService::get_table_name('private_registration_tokens');
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('hmwevents_waitlist_promoted', [$this, 'create_token_for_promoted'], 10, 2);
        add_filter('hmwevents_can_register_for_event', [$this, 'validate_token_access'], 10, 3);
    }

    // ================================================================
    // TOKEN CRUD
    // ================================================================

    /**
     * Create a new registration token.
     *
     * @param int    $event_post_id
     * @param string $recipient_email
     * @param int    $max_uses          Usually 1 for single-use.
     * @param int    $expiry_hours      Hours until token expires.
     * @return array|\WP_Error  {token, row}
     */
    public function create(int $event_post_id, string $recipient_email = '', int $max_uses = 1, int $expiry_hours = 48): array|\WP_Error
    {
        global $wpdb;

        $token = $this->generate_token();
        $expires_at = gmdate('Y-m-d H:i:s', strtotime("+{$expiry_hours} hours"));

        $result = $wpdb->insert($this->table, [
            'event_post_id'   => $event_post_id,
            'token'           => $token,
            'recipient_email' => $recipient_email,
            'max_uses'        => $max_uses,
            'use_count'       => 0,
            'expires_at'      => $expires_at,
            'is_active'       => 1,
            'created_by'      => get_current_user_id(),
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ], ['%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s']);

        if ($result === false) {
            return new \WP_Error('db_error', __('Failed to create registration token.', 'hmw-events'));
        }

        $token_data = [
            'id'               => (int) $wpdb->insert_id,
            'token'            => $token,
            'event_post_id'    => $event_post_id,
            'recipient_email'  => $recipient_email,
            'max_uses'         => $max_uses,
            'expires_at'       => $expires_at,
            'registration_url' => $this->build_registration_url($event_post_id, $token),
        ];

        /**
         * Action: hmwevents_invitation_token_created
         *
         * @param array $token_data
         */
        do_action('hmwevents_invitation_token_created', $token_data);

        return $token_data;
    }

    /**
     * Validate a token.
     *
     * Checks: exists, active, not expired, has remaining uses.
     *
     * @param string $token
     * @param int    $event_post_id
     * @return true|\WP_Error
     */
    public function validate(string $token, int $event_post_id): true|\WP_Error
    {
        global $wpdb;

        if (empty($token)) {
            return new \WP_Error('token_missing', __('A registration token is required.', 'hmw-events'));
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE token = %s AND event_post_id = %d",
            $token,
            $event_post_id
        ));

        if (!$row) {
            return new \WP_Error('token_invalid', __('Invalid registration token.', 'hmw-events'));
        }

        if (!$row->is_active) {
            return new \WP_Error('token_disabled', __('This registration token is no longer active.', 'hmw-events'));
        }

        if (strtotime($row->expires_at) < time()) {
            return new \WP_Error('token_expired', __('This registration token has expired.', 'hmw-events'));
        }

        if ($row->use_count >= $row->max_uses) {
            return new \WP_Error('token_exhausted', __('This registration token has already been used.', 'hmw-events'));
        }

        return true;
    }

    /**
     * Consume a token (increment use_count).
     *
     * @param string $token
     * @param int    $event_post_id
     * @return bool True on success.
     */
    public function consume(string $token, int $event_post_id): bool
    {
        global $wpdb;

        $validation = $this->validate($token, $event_post_id);
        if (is_wp_error($validation)) {
            return false;
        }

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET use_count = use_count + 1, updated_at = %s WHERE token = %s AND event_post_id = %d",
            current_time('mysql'),
            $token,
            $event_post_id
        ));

        return $result !== false;
    }

    /**
     * Deactivate a token early.
     */
    public function deactivate(string $token, int $event_post_id): bool
    {
        global $wpdb;

        return (bool) $wpdb->update(
            $this->table,
            ['is_active' => 0, 'updated_at' => current_time('mysql')],
            ['token' => $token, 'event_post_id' => $event_post_id],
            ['%d', '%s'],
            ['%s', '%d']
        );
    }

    /**
     * Get all tokens for an event.
     *
     * @return object[]
     */
    public function get_for_event(int $event_post_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE event_post_id = %d ORDER BY created_at DESC",
            $event_post_id
        )) ?: [];
    }

    // ================================================================
    // WAITLIST INTEGRATION
    // ================================================================

    /**
     * Auto-create a token when someone is promoted from the waitlist.
     *
     * @param object $entry
     * @param int    $event_post_id
     */
    public function create_token_for_promoted(object $entry, int $event_post_id): void
    {
        $this->create(
            $event_post_id,
            $entry->recipient_email ?? '',
            1,   // single-use
            48   // 48 hours
        );
    }

    /**
     * Validate token access when checking if a user can register.
     *
     * Filter: hmwevents_can_register_for_event
     *
     * @param bool  $can_register
     * @param int   $event_post_id
     * @param array $context  May contain 'token' key.
     * @return bool
     */
    public function validate_token_access(bool $can_register, int $event_post_id, array $context): bool
    {
        $event = get_post($event_post_id);
        if (!$event || !\HMWEvents\PostTypes\Event::is_invitation_only($event_post_id)) {
            return $can_register;
        }

        // By-invitation events require a valid token
        $token = $context['token'] ?? $_GET['token'] ?? '';

        $validation = $this->validate($token, $event_post_id);
        return !is_wp_error($validation);
    }

    // ================================================================
    // HELPERS
    // ================================================================

    /**
     * Generate a cryptographically secure token.
     */
    private function generate_token(): string
    {
        return bin2hex(random_bytes(self::TOKEN_LENGTH / 2));
    }

    /**
     * Build the registration URL for a token.
     */
    private function build_registration_url(int $event_post_id, string $token): string
    {
        $url = get_permalink($event_post_id);
        if (!$url || is_wp_error($url)) {
            $url = home_url('/');
        }

        return add_query_arg('token', $token, $url);
    }
}
