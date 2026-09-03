<?php

namespace HMWEvents\Services\Emails\Handlers;

use HMWEvents\Services\Emails\AbstractEmailHandler;

defined('ABSPATH') || die('Don\'t run this file directly!');

class NotificationHandler extends AbstractEmailHandler
{
    public const TYPE_PAYMENT_RECEIVED = 'payment_received';
    public const TYPE_WAITLIST_PROMOTION = 'waitlist_promotion';
    public const TYPE_WAITLIST_JOINED = 'waitlist_joined';
    public const TYPE_INVOICE_ISSUED = 'invoice_issued';
    public const TYPE_INVITATION_SENT = 'invitation_sent';
    public const TYPE_BOOKING_CONFIRMATION_MULTIPLE = 'booking_confirmation_multiple';

    protected $template_key = '';
    protected $email_type = '';

    /**
     * Queue a notification email rendered from stored template data.
     *
     * @param array $data {
     *     @type string $email_type      One of the TYPE_* constants.
     *     @type string $recipient_email Recipient address.
     *     @type string $recipient_name  Optional display name.
     *     @type int    $organizer_id    Optional organizer for template override lookup.
     *     @type int    $booking_id      Optional bookings-table ID.
     *     @type array  $template_data   Placeholder variables.
     * }
     * @return int|false Queue row ID or false on failure.
     */
    public function queue_notification(array $data): int|false
    {
        $email_type = sanitize_key((string) ($data['email_type'] ?? ''));

        if (!$email_type || !isset($this->default_templates()[$email_type])) {
            error_log('Unknown notification email type: ' . $email_type);
            return false;
        }

        if (empty($data['recipient_email'])) {
            error_log('Notification email missing recipient: ' . $email_type);
            return false;
        }

        $this->email_type = $email_type;
        $this->template_key = $email_type;

        return $this->queue([
            'booking_id'      => isset($data['booking_id']) ? (int) $data['booking_id'] : null,
            'organizer_id'    => isset($data['organizer_id']) ? (int) $data['organizer_id'] : null,
            'recipient_email' => $data['recipient_email'],
            'recipient_name'  => $data['recipient_name'] ?? '',
            'template_data'   => $data['template_data'] ?? [],
            'scheduled_at'    => $data['scheduled_at'] ?? current_time('mysql'),
        ]);
    }

    /**
     * Notifications render from stored data and organizer-managed templates,
     * never from freshly regenerated booking data.
     */
    public function send($email)
    {
        $booking_id = (int) ($email->booking_id ?? 0);

        if ($booking_id && $this->should_skip_email_for_booking($email, $booking_id)) {
            $this->queue_repo->update_status(
                $email->id,
                'cancelled',
                'Skipped: booking cancelled or refunded'
            );
            return false;
        }

        $this->queue_repo->mark_processing($email->id);

        try {
            $stored = !empty($email->template_data) ? (json_decode($email->template_data, true) ?: []) : [];
            $rendered = $this->render_notification($email, $stored);

            if ($rendered === null) {
                throw new \Exception('No template available for notification type: ' . $email->email_type);
            }

            $sent = wp_mail(
                $email->recipient_email,
                $rendered['subject'],
                $rendered['body'],
                $this->get_email_headers(),
                $this->get_attachments($email->id)
            );

            if ($sent) {
                $this->queue_repo->mark_sent($email->id);
                do_action('hmwevents_after_email_sent', $email);
                return true;
            }

            $this->queue_repo->mark_failed($email->id, 'wp_mail returned false');
            return false;
        } catch (\Exception $e) {
            $this->queue_repo->mark_failed($email->id, $e->getMessage());
            error_log('HMWEvents notification email error: ' . $e->getMessage());
            return false;
        }
    }

    private function render_notification($email, array $variables): ?array
    {
        $key = $email->template_key ?: $email->email_type;
        $organizer_id = !empty($email->organizer_id) ? (int) $email->organizer_id : null;

        $template = $this->template_repo->get_template($organizer_id, $key);
        if ($template) {
            return $this->template_repo->render($template, $variables);
        }

        $defaults = $this->default_templates();
        if (!isset($defaults[$email->email_type])) {
            return null;
        }

        $subject = $defaults[$email->email_type]['subject'];
        $body = $defaults[$email->email_type]['body'];

        foreach ($variables as $var_key => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, 'is_scalar'));
            } elseif (is_object($value)) {
                $value = wp_json_encode($value);
            }
            $placeholder = '{{' . $var_key . '}}';
            $subject = str_replace($placeholder, (string) $value, $subject);
            $body = str_replace($placeholder, (string) $value, $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }

    protected function prepare_fresh_template_data($booking_id)
    {
        return [];
    }

    /**
     * Built-in fallback templates used when no DB template exists.
     *
     * @return array
     */
    private function default_templates(): array
    {
        return [
            self::TYPE_PAYMENT_RECEIVED => [
                'subject' => __('Payment received — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>We've received your payment of <strong>{{amount}}</strong> for {{event_title}}. Your tax invoice is attached to this email.</p>",
            ],
            self::TYPE_WAITLIST_JOINED => [
                'subject' => __("You're on the waitlist — {{event_title}}", 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>You're on the waitlist for <strong>{{event_title}}</strong>. You're currently number {{position}} in line.</p>\n<p>If a spot opens up, we'll email you with instructions on how to register — so keep an eye on your inbox.</p>",
            ],
            self::TYPE_WAITLIST_PROMOTION => [
                'subject' => __('A spot is available — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>A spot has opened up for <strong>{{event_title}}</strong>!</p>\n<p><a href=\"{{registration_url}}\">Click here to register</a>. This link expires in {{expiry_hours}} hours.</p>",
            ],
            self::TYPE_INVOICE_ISSUED => [
                'subject' => __('Invoice {{invoice_number}} for {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>Your registration for {{event_title}} has been confirmed. Please find your tax invoice <strong>{{invoice_number}}</strong> attached.</p>\n<p>The amount due is <strong>{{amount}}</strong>, payable by {{due_date}}.</p>\n{{bank_details}}",
            ],
            self::TYPE_INVITATION_SENT => [
                'subject' => __('You\'re invited — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>You've been invited to register for <strong>{{event_title}}</strong>.</p>\n<p><a href=\"{{registration_url}}\">Click here to register</a>.</p>",
            ],
            self::TYPE_BOOKING_CONFIRMATION_MULTIPLE => [
                'subject' => __('Booking confirmed — {{event_title}}', 'hmw-events'),
                'body'    => "<p>Hi {{first_name}},</p>\n<p>Your booking for <strong>{{event_title}}</strong> is confirmed. Your booking includes the following {{session_count}} sessions:</p>\n{{session_list}}\n<p>Booking reference: <strong>{{booking_reference}}</strong><br>Total: {{amount}}</p>\n<p>We'll send you a reminder before each session.</p>",
            ],
        ];
    }

    public function get_template_variables_description()
    {
        return array_merge(parent::get_template_variables_description(), [
            'first_name'       => 'Recipient first name',
            'event_title'      => 'Event title',
            'registration_url' => 'Registration link',
            'expiry_hours'     => 'Hours until registration link expires',
            'amount'           => 'Amount (formatted)',
            'invoice_number'   => 'Invoice number',
            'due_date'         => 'Invoice due date',
            'bank_details'     => 'EFT / bank payment instructions (HTML)',
            'session_list'     => 'HTML list of booked sessions (multi-session bookings)',
            'session_count'    => 'Number of sessions in a multi-session booking',
            'booking_reference' => 'Booking group reference',
        ]);
    }
}
