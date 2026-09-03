<?php
/**
 * Payment Link Email Handler.
 *
 * Sends payment link emails to customers.
 * Unlike other handlers, this uses pre-rendered HTML stored at queue time
 * since the payment URL is time-sensitive (expires in 24 hours).
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails\Handlers;

use HMWEvents\Services\Emails\AbstractEmailHandler;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Payment Link Email Handler.
 */
class PaymentLinkHandler extends AbstractEmailHandler
{
    /**
     * Email type identifier.
     *
     * @var string
     */
    protected $email_type = 'payment_link';

    /**
     * Template key.
     *
     * @var string
     */
    protected $template_key = 'payment_link';

    /**
     * Not used — payment link emails are pre-rendered at queue time.
     *
     * @param int $booking_id Booking ID.
     * @return array Empty array (content is stored in html_body at queue time).
     */
    protected function prepare_fresh_template_data($booking_id)
    {
        return [];
    }

    /**
     * Queue a payment link email with pre-rendered content.
     *
     * Payment link emails are pre-rendered at queue time because the payment
     * URL is time-sensitive and must not be regenerated when the queue processes.
     *
     * @param int    $booking_id      Booking ID.
     * @param int    $educator_id     Educator user ID.
     * @param string $recipient_email Recipient email address.
     * @param string $recipient_name  Recipient display name.
     * @param string $subject         Pre-rendered subject line.
     * @param string $html_body       Pre-rendered HTML body.
     * @return int|false Queue ID or false on failure.
     */
    public function queue_email($booking_id, $educator_id, $recipient_email, $recipient_name, $subject, $html_body)
    {
        return $this->queue_repo->add([
            'booking_id'      => $booking_id,
            'organizer_id'    => $educator_id,
            'recipient_email' => $recipient_email,
            'recipient_name'  => $recipient_name,
            'email_type'      => $this->email_type,
            'subject'         => $subject,
            'template_key'    => $this->template_key,
            'html_body'       => $html_body,
        ]);
    }

    /**
     * Send the payment link email using pre-rendered content stored at queue time.
     *
     * Overrides the base send() to use the stored html_body and subject rather
     * than re-rendering from a DB template, since the payment URL is time-sensitive.
     *
     * @param object $email Email object from queue.
     * @return bool Success or failure.
     */
    public function send($email)
    {
        $this->queue_repo->mark_processing($email->id);

        try {
            if (empty($email->html_body) || empty($email->subject)) {
                throw new \Exception('Payment link email is missing pre-rendered content (ID: ' . $email->id . ')');
            }

            $educator_email = '';
            $organizer_id = $email->organizer_id ?? $email->educator_id ?? null;
            if (!empty($organizer_id)) {
                $educator_user = get_userdata((int) $organizer_id);
                if ($educator_user) {
                    $educator_email = $educator_user->user_email;
                }
            }

            if (!empty($email->booking_id)) {
                global $wpdb;
                $event_post_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT event_post_id FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " WHERE id = %d",
                    $email->booking_id
                ));
                if ($event_post_id) {
                    $override = get_post_meta((int) $event_post_id, '_event_notification_email', true);
                    if ($override && is_email($override)) {
                        $educator_email = $override;
                    }
                }
            }

            do_action('hmwevents_before_email_send', $email);

            $sent = wp_mail(
                $email->recipient_email,
                $email->subject,
                $email->html_body,
                $this->get_email_headers($educator_email)
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
            error_log('HMWEvents Payment link email error: ' . $e->getMessage());
            return false;
        }
    }
}
