<?php

/**
 * Email Template Manager.
 *
 * CRUD operations for email templates stored in hmwevents_email_templates.
 * Supports system-level default templates and organizer-level overrides.
 * Simple {{variable}} placeholder rendering.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EmailTemplateManager
{
    private string $table;

    public function __construct()
    {
        $this->table = DatabaseService::get_table_name('email_templates');
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('hmwevents_install_templates', [$this, 'create_default_templates']);
    }

    // ================================================================
    // CRUD
    // ================================================================

    /**
     * Upsert a template.
     *
     * @param array $data {organizer_id, template_key, subject, body, variables, is_active}
     * @return int Template ID.
     */
    public function save(array $data): int
    {
        global $wpdb;

        $organizer_id = (int) ($data['organizer_id'] ?? 0);
        $template_key = sanitize_text_field($data['template_key'] ?? '');
        $subject      = sanitize_text_field($data['subject'] ?? '');
        $body         = wp_kses_post($data['body'] ?? '');
        $variables    = $data['variables'] ?? [];
        $is_active    = isset($data['is_active']) ? (int) $data['is_active'] : 1;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, version FROM {$this->table} WHERE organizer_id = %d AND template_key = %s",
            $organizer_id ?: 0,
            $template_key
        ));

        if ($existing) {
            $wpdb->update(
                $this->table,
                [
                    'subject'    => $subject,
                    'body'       => $body,
                    'variables'  => wp_json_encode($variables),
                    'is_active'  => $is_active,
                    'version'    => (int) $existing->version + 1,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $existing->id],
                ['%s', '%s', '%s', '%d', '%d', '%s'],
                ['%d']
            );
            return (int) $existing->id;
        }

        $wpdb->insert($this->table, [
            'organizer_id'  => $organizer_id ?: 0,
            'template_key'  => $template_key,
            'subject'       => $subject,
            'body'          => $body,
            'variables'     => wp_json_encode($variables),
            'is_active'     => $is_active,
            'version'       => 1,
            'created_at'    => current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);

        return (int) $wpdb->insert_id;
    }

    /**
     * Get a template by organizer and key.
     *
     * Falls back to system default (organizer_id = 0) if no organizer override exists.
     *
     * @return object|null
     */
    public function get(int $organizer_id, string $template_key): ?object
    {
        global $wpdb;

        // Try organizer-specific first
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE organizer_id = %d AND template_key = %s AND is_active = 1",
            $organizer_id,
            $template_key
        ));

        if (!$row && $organizer_id > 0) {
            // Fall back to system default
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE organizer_id = 0 AND template_key = %s AND is_active = 1",
                $template_key
            ));
        }

        return $row;
    }

    /**
     * Get all templates for an organizer, or all system templates.
     */
    public function get_all(int $organizer_id = 0): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE organizer_id = %d ORDER BY template_key ASC",
            $organizer_id
        )) ?: [];
    }

    /**
     * Delete a template.
     */
    public function delete(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete($this->table, ['id' => $id], ['%d']);
    }

    /**
     * Activate / deactivate a template.
     */
    public function set_active(int $id, bool $active): bool
    {
        global $wpdb;
        return (bool) $wpdb->update(
            $this->table,
            ['is_active' => (int) $active, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
    }

    // ================================================================
    // RENDER
    // ================================================================

    /**
     * Render a template with variable replacement.
     *
     * Supports {{variable_name}} placeholders.
     * Arrays become comma-separated strings.
     *
     * @param object $template   Template row from DB.
     * @param array  $variables  Key-value pairs for replacement.
     * @return array {subject, body}
     */
    public function render(object $template, array $variables): array
    {
        $subject = $template->subject;
        $body    = $template->body;

        foreach ($variables as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            } elseif (is_object($value)) {
                $value = wp_json_encode($value);
            }

            $placeholder = '{{' . $key . '}}';
            $subject = str_replace($placeholder, (string) $value, $subject);
            $body    = str_replace($placeholder, (string) $value, $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Get rendered email content for a booking trigger.
     *
     * @param int    $organizer_id
     * @param string $template_key
     * @param array  $variables
     * @return array|null {subject, body, template_key}
     */
    public function get_rendered(int $organizer_id, string $template_key, array $variables): ?array
    {
        $template = $this->get($organizer_id, $template_key);
        if (!$template) {
            return null;
        }

        $rendered = $this->render($template, $variables);

        return [
            'subject'      => $rendered['subject'],
            'body'         => $rendered['body'],
            'template_key' => $template_key,
        ];
    }

    // ================================================================
    // DEFAULT TEMPLATES
    // ================================================================

    /**
     * Create system default templates for all triggers.
     */
    public function create_default_templates(): void
    {
        if (get_option('hmwevents_email_templates_created')) {
            return;
        }

        $defaults = [
            'booking_confirmed' => [
                'subject' => __('Your booking is confirmed — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>Your booking for <strong>{{event_title}}</strong> on {{event_date}} has been confirmed.</p>\n<p><strong>Booking Reference:</strong> {{booking_reference}}</p>\n{{all_fields}}",
            ],
            'booking_cancelled' => [
                'subject' => __('Booking cancelled — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>Your booking for <strong>{{event_title}}</strong> has been cancelled.</p>\n<p>If you didn't request this, please contact us.</p>",
            ],
            'payment_received' => [
                'subject' => __('Payment received — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>We've received your payment of <strong>{{amount}}</strong> for {{event_title}}.</p>\n{{gst_breakdown}}",
            ],
            'refund_issued' => [
                'subject' => __('Refund processed — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>A refund of <strong>{{amount}}</strong> has been processed for {{event_title}}.</p>",
            ],
            'waitlist_promotion' => [
                'subject' => __('A spot is available — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>A spot has opened up for <strong>{{event_title}}</strong>!</p>\n<p><a href=\"{{registration_url}}\">Click here to register</a>. This link expires in {{expiry_hours}} hours.</p>",
            ],
            'invoice_issued' => [
                'subject' => __('Invoice for {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>Your registration for {{event_title}} has been confirmed. An invoice for <strong>{{amount}}</strong> is attached.</p>\n{{gst_breakdown}}",
            ],
            'reminder_7_days' => [
                'subject' => __('Coming up — {{event_title}} in 7 days', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>Just a reminder: <strong>{{event_title}}</strong> is happening in 7 days on {{event_date}}.</p>\n<p>Location: {{event_location}}</p>",
            ],
            'reminder_1_day' => [
                'subject' => __('Tomorrow — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p><strong>{{event_title}}</strong> is tomorrow!</p>\n<p>Date: {{event_date}}<br>Location: {{event_location}}</p>\n<p>See you there!</p>",
            ],
            'post_event' => [
                'subject' => __('Thank you for attending {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>Thank you for attending <strong>{{event_title}}</strong>. We hope you found it valuable.</p>\n<p>If you have any feedback, we'd love to hear it.</p>",
            ],
            'event_changed' => [
                'subject' => __('Event update — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>Some details for <strong>{{event_title}}</strong> have changed:</p>\n{{changes}}",
            ],
            'new_booking_notify' => [
                'subject' => __('New booking — {{event_title}}', 'hmw-events'),
                'body'    => "<p>A new booking has been received for <strong>{{event_title}}</strong>.</p>\n<p><strong>Registrant:</strong> {{first_name}} {{last_name}}<br><strong>Reference:</strong> {{booking_reference}}</p>",
            ],
            'invitation_sent' => [
                'subject' => __('You\'re invited — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>You've been invited to register for <strong>{{event_title}}</strong>.</p>\n<p><a href=\"{{registration_url}}\">Click here to register</a>.</p>",
            ],
        ];

        foreach ($defaults as $key => $data) {
            $this->save([
                'organizer_id' => 0,
                'template_key' => $key,
                'subject'      => $data['subject'],
                'body'         => $data['body'],
                'variables'    => array_keys($data),
                'is_active'    => 1,
            ]);
        }

        update_option('hmwevents_email_templates_created', true);
    }
}
