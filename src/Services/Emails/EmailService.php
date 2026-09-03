<?php
/**
 * Email Service - Main Orchestrator.
 *
 * Central service for managing the email system.
 * Coordinates between handlers, queue, and ActionScheduler.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails;

use HMWEvents\Services\Emails\Handlers\BookingConfirmationHandler;
use HMWEvents\Services\Emails\Handlers\ReminderHandler;
use HMWEvents\Services\Emails\Handlers\PostEventHandler;
use HMWEvents\Services\Emails\Handlers\StatusChangeHandler;
use HMWEvents\Services\Emails\Handlers\OrganizerNewBookingHandler;
use HMWEvents\Services\Emails\Handlers\PaymentLinkHandler;
use HMWEvents\Services\Emails\Handlers\NotificationHandler;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Email Service Class.
 */
class EmailService
{
    /**
     * Email handlers.
     *
     * @var array
     */
    private $handlers = [];

    /**
     * Queue repository.
     *
     * @var EmailQueueRepository
     */
    private $queue_repo;

    /**
     * Template repository.
     *
     * @var EmailTemplateRepository
     */
    private $template_repo;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->queue_repo = new EmailQueueRepository();
        $this->template_repo = new EmailTemplateRepository();

        // Initialize handlers
        $this->handlers = [
            'booking_confirmation'  => new BookingConfirmationHandler(),
            'reminder'              => new ReminderHandler(),
            'post_course'           => new PostEventHandler(),
            'status_change'         => new StatusChangeHandler(),
            'organizer_new_booking' => new OrganizerNewBookingHandler(),
            'payment_link'          => new PaymentLinkHandler(),
            'notification'          => new NotificationHandler(),
        ];
    }

    /**
     * Get handler by type.
     *
     * @param string $handler_type Handler type.
     * @return AbstractEmailHandler|false Handler or false if not found.
     */
    public function get_handler($handler_type)
    {
        return $this->handlers[$handler_type] ?? false;
    }

    /**
     * Queue organizer new-booking notification email.
     *
     * @param int   $booking_id   Booking ID.
     * @param array $booking_data Optional booking data.
     * @return int|false Email ID or false on failure.
     */
    public function queue_organizer_new_booking($booking_id, $booking_data = [])
    {
        $handler = $this->get_handler('organizer_new_booking');
        return $handler->queue_for_booking($booking_id, $booking_data);
    }

    /**
     * Queue a payment link email.
     *
     * The subject and HTML body are pre-rendered by the caller because the
     * payment URL is time-sensitive and must not be regenerated at send time.
     *
     * @param int    $booking_id      Booking ID.
     * @param int    $educator_id     Educator user ID.
     * @param string $recipient_email Recipient email address.
     * @param string $recipient_name  Recipient display name.
     * @param string $subject         Email subject.
     * @param string $html_body       Pre-rendered HTML body.
     * @return int|false Queue ID or false on failure.
     */
    public function queue_payment_link($booking_id, $educator_id, $recipient_email, $recipient_name, $subject, $html_body)
    {
        $handler = $this->get_handler('payment_link');
        return $handler->queue_email($booking_id, $educator_id, $recipient_email, $recipient_name, $subject, $html_body);
    }

    /**
     * Queue booking confirmation email.
     *
     * @param int   $booking_id Booking ID.
     * @param array $booking_data Optional booking data.
     * @return int|false Email ID or false on failure.
     */
    public function queue_booking_confirmation($booking_id, $booking_data = [])
    {
        global $wpdb;

        $queue_table = \HMWEvents\Services\DatabaseService::get_table_name('email_queue');
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$queue_table} WHERE booking_id = %d AND email_type = %s AND status IN ('pending', 'processing', 'sent') ORDER BY id DESC LIMIT 1",
            (int) $booking_id,
            'booking_confirmation'
        ));

        if ($existing_id) {
            return (int) $existing_id;
        }

        $handler = $this->get_handler('booking_confirmation');
        return $handler->queue_for_booking($booking_id, $booking_data);
    }

    /**
     * Queue course reminder email.
     *
     * @param int   $booking_id Booking ID.
     * @param int   $days_before Days before course.
     * @param array $booking_data Optional booking data.
     * @return int|false Email ID or false on failure.
     */
    public function queue_course_reminder($booking_id, $days_before = 7, $booking_data = [])
    {
        $handler = $this->get_handler('reminder');
        return $handler->queue_for_booking($booking_id, $days_before, $booking_data);
    }

    /**
     * Queue post-course email.
     *
     * @param int   $booking_id Booking ID.
     * @param int   $days_after Days after course.
     * @param array $booking_data Optional booking data.
     * @return int|false Email ID or false on failure.
     */
    public function queue_post_course_email($booking_id, $days_after = 1, $booking_data = [])
    {
        $handler = $this->get_handler('post_course');
        return $handler->queue_for_booking($booking_id, $days_after, $booking_data);
    }

    /**
     * Queue status change email.
     *
     * @param int    $booking_id Booking ID.
     * @param string $status_type Status change type.
     * @param array  $additional_data Additional data.
     * @return int|false Email ID or false on failure.
     */
    public function queue_status_change_email($booking_id, $status_type, $additional_data = [])
    {
        $handler = $this->get_handler('status_change');
        return $handler->queue_for_status_change($booking_id, $status_type, $additional_data);
    }

    /**
     * Queue a notification email (payment receipts, waitlist promotions,
     * invoices, invitations) rendered from stored template data.
     *
     * @param array $data See NotificationHandler::queue_notification().
     * @return int|false Queue row ID or false on failure.
     */
    public function queue_notification(array $data)
    {
        $handler = $this->get_handler('notification');
        return $handler->queue_notification($data);
    }

    /**
     * Queue ONE confirmation email for a multi-session booking group,
     * listing every booked session.
     *
     * @param int   $primary_booking_id Bookings-table ID of the primary row.
     * @param array $booking_ids        All booking rows in the group.
     * @param array $data               Optional gateway booking payload.
     * @return int|false Queue row ID or false on failure.
     */
    public function queue_group_booking_confirmation(int $primary_booking_id, array $booking_ids, array $data = [])
    {
        global $wpdb;

        $booking_ids = array_values(array_unique(array_filter(array_map('intval', $booking_ids))));
        if (!$primary_booking_id || empty($booking_ids)) {
            return false;
        }

        $primary = $wpdb->get_row($wpdb->prepare(
            "SELECT booking_number, registrant_post_id, event_post_id, booking_group_id
             FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . "
             WHERE id = %d",
            $primary_booking_id
        ));

        if (!$primary) {
            return false;
        }

        $customer_id = (int) $primary->registrant_post_id;
        $recipient_email = sanitize_email((string) get_post_meta($customer_id, 'registrant_email', true));
        if ($recipient_email === '') {
            return false;
        }

        $first_name = sanitize_text_field((string) get_post_meta($customer_id, 'registrant_first_name', true));

        $session_ids = [];
        foreach ($booking_ids as $booking_id) {
            $session_ids[] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT event_post_id FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " WHERE id = %d",
                $booking_id
            ));
        }
        $session_ids = array_values(array_filter($session_ids));

        $lines = [];
        $event_title = '';
        foreach ($session_ids as $session_id) {
            $session_post = get_post($session_id);
            if (!$session_post) {
                continue;
            }

            $start = (string) get_post_meta($session_id, '_event_start_date', true);
            $when = $start !== ''
                ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($start))
                : __('Date TBA', 'hmw-events');
            $lines[] = '<li>' . esc_html($when) . ' — ' . esc_html($session_post->post_title) . '</li>';

            if ($event_title === '') {
                $root_id = $session_post->post_parent ? wp_get_post_parent_id($session_id) : $session_id;
                $root_post = $root_id ? get_post($root_id) : null;
                $event_title = $root_post ? $root_post->post_title : $session_post->post_title;
            }
        }

        if (empty($lines)) {
            return false;
        }

        $root_post = null;
        foreach ($session_ids as $session_id) {
            $session_post = get_post($session_id);
            if (!$session_post) {
                continue;
            }
            $root_id = $session_post->post_parent ? (int) $session_post->post_parent : $session_id;
            $root_post = get_post($root_id);
            if ($root_post) {
                break;
            }
        }

        $organizer_id = $root_post ? (int) $root_post->post_author : null;

        $amount = '';
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT booking_reference, total_amount, currency FROM " . \HMWEvents\Services\DatabaseService::get_table_name('booking_groups') . " WHERE id = %d",
            $primary->booking_group_id
        ));
        if ($group) {
            $currency = $group->currency ?: 'AUD';
            $symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);
            $amount = $symbol . number_format((float) $group->total_amount, 2);
        }

        return $this->queue_notification([
            'email_type'      => \HMWEvents\Services\Emails\Handlers\NotificationHandler::TYPE_BOOKING_CONFIRMATION_MULTIPLE,
            'recipient_email' => $recipient_email,
            'recipient_name'  => $first_name,
            'booking_id'      => $primary_booking_id,
            'organizer_id'    => $organizer_id,
            'template_data'   => [
                'first_name'        => $first_name,
                'event_title'       => $event_title,
                'session_count'     => (string) count($lines),
                'session_list'      => '<ul>' . implode('', $lines) . '</ul>',
                'booking_reference' => $group->booking_reference ?? '',
                'amount'            => $amount,
            ],
        ]);
    }

    /**
     * Queue a payment receipt email for a paid booking.
     *
     * Resolves the recipient, first name, event title and amount from the
     * booking record and queues a 'payment_received' notification with a
     * paid tax-invoice PDF attached.
     *
     * @param int $booking_id Bookings-table row ID.
     * @return int|false Queue row ID or false on failure.
     */
    public function queue_payment_receipt(int $booking_id)
    {
        global $wpdb;

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT booking_number, booking_amount, event_post_id, registrant_post_id, booking_group_id
             FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . "
             WHERE id = %d",
            $booking_id
        ));

        if (!$booking) {
            return false;
        }

        $event_id    = (int) $booking->event_post_id;
        $customer_id = (int) $booking->registrant_post_id;

        $recipient_email = sanitize_email((string) get_post_meta($customer_id, 'registrant_email', true));
        if ($recipient_email === '') {
            return false;
        }

        $first_name = sanitize_text_field((string) get_post_meta($customer_id, 'registrant_first_name', true));

        $organizer_id = null;
        $event_post   = get_post($event_id);
        if ($event_post) {
            $organizer_id = (int) $event_post->post_author;
        }

        $email_id = $this->queue_notification([
            'email_type'      => NotificationHandler::TYPE_PAYMENT_RECEIVED,
            'recipient_email' => $recipient_email,
            'recipient_name'  => trim($first_name),
            'organizer_id'    => $organizer_id,
            'booking_id'      => $booking_id,
            'template_data'   => [
                'first_name'  => $first_name,
                'event_title' => get_the_title($event_id),
                'amount'      => number_format((float) $booking->booking_amount, 2),
            ],
        ]);

        if ($email_id) {
            $this->attach_receipt_pdf((int) $email_id, (int) $booking->booking_group_id);
        }

        return $email_id;
    }

    /**
     * Generate the paid tax-invoice PDF for a booking group and attach it
     * to a queued receipt email.
     *
     * @param int $email_id Email queue row ID.
     * @param int $group_id Booking group ID.
     * @return void
     */
    protected function attach_receipt_pdf(int $email_id, int $group_id): void
    {
        $invoice = new \HMWEvents\Services\InvoiceService();

        $pdf_path = $invoice->generate_receipt_pdf($group_id);
        if ($pdf_path === null) {
            return;
        }

        $filename = $invoice->assign_invoice_number($group_id) . '.pdf';

        $attachment_service = new EmailAttachmentService();
        $attachment_service->add($email_id, $pdf_path, $filename);
    }

    /**
     * Process pending emails from the queue.
     *
     * Called by ActionScheduler at regular intervals.
     *
     * @param int $limit Number of emails to process.
     * @return array Result statistics.
     */
    public function process_queue($limit = 10)
    {
        $pending_emails = $this->queue_repo->get_pending($limit);

        $stats = [
            'processed' => 0,
            'sent'      => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        foreach ($pending_emails as $email) {
            $stats['processed']++;

            try {
                // Get handler for this email type
                $handler = $this->get_handler_for_email_type($email->email_type);

                if (!$handler) {
                    $error = 'Handler not found for email type: ' . $email->email_type;
                    error_log('HMWEvents Email Error: ' . $error);
                    $this->queue_repo->mark_failed($email->id, $error);
                    $stats['failed']++;
                    continue;
                }

                // Send the email
                $sent = $handler->send($email);

                if ($sent) {
                    $stats['sent']++;
                } else {
                    $stats['failed']++;
                }
            } catch (\Exception $e) {
                error_log('HMWEvents Email Exception: ' . $e->getMessage());
                $this->queue_repo->mark_failed($email->id, $e->getMessage());
                $stats['failed']++;
                $stats['errors'][] = $e->getMessage();
            }
        }

        return $stats;
    }

    /**
     * Get handler for email type.
     *
     * @param string $email_type Email type.
     * @return AbstractEmailHandler|false Handler or false.
     */
    private function get_handler_for_email_type($email_type)
    {
        $mapping = [
            'booking_confirmation'  => 'booking_confirmation',
            'course_reminder'       => 'reminder',
            'post_course_feedback'  => 'post_course',
            'post_course_followup'  => 'post_course',
            'booking_cancelled'     => 'status_change',
            'refund_issued'         => 'status_change',
            'course_changed'        => 'status_change',
            'event_changed'         => 'status_change',
            'payment_link'          => 'payment_link',
            'organizer_new_booking' => 'organizer_new_booking',
            'payment_received'      => 'notification',
            'waitlist_promotion'    => 'notification',
            'waitlist_joined'       => 'notification',
            'invoice_issued'        => 'notification',
            'invitation_sent'       => 'notification',
        ];

        $handler_type = $mapping[$email_type] ?? null;
        if (!$handler_type) {
            return false;
        }

        return $this->get_handler($handler_type);
    }

    /**
     * Get queue statistics.
     *
     * @return object Statistics object.
     */
    public function get_queue_stats()
    {
        return $this->queue_repo->get_stats();
    }

    /**
     * Get pending emails.
     *
     * @param int $limit Number of emails.
     * @return array Email objects.
     */
    public function get_pending_emails($limit = 20)
    {
        return $this->queue_repo->get_pending($limit);
    }

    /**
     * Get failed emails (dead letter queue).
     *
     * @param int $limit Number of emails.
     * @return array Email objects.
     */
    public function get_failed_emails($limit = 20)
    {
        return $this->queue_repo->get_failed($limit);
    }

    /**
     * Get emails for a booking.
     *
     * @param int $booking_id Booking ID.
     * @return array Email objects.
     */
    public function get_booking_emails($booking_id)
    {
        return $this->queue_repo->get_by_booking($booking_id);
    }

    /**
     * Resend a failed email.
     *
     * @param int $email_id Email ID.
     * @return bool Success or failure.
     */
    public function resend_email($email_id)
    {
        $email = $this->queue_repo->get($email_id);
        if (!$email) {
            return false;
        }

        // Reset attempts and status
        $this->queue_repo->reset_for_retry($email_id);

        // Manually send
        $handler = $this->get_handler_for_email_type($email->email_type);
        if (!$handler) {
            return false;
        }

        return $handler->send($email);
    }

    /**
     * Retry a queued email without sending immediately.
     *
     * @param int $email_id Email ID.
     * @return bool|int Success or failure.
     */
    public function retry_email($email_id)
    {
        return $this->queue_repo->reset_for_retry($email_id);
    }

    /**
     * Cancel pending emails for a booking.
     *
     * @param int   $booking_id Booking ID.
     * @param array $exclude_types Email types to exclude from cancellation.
     * @param string $reason Cancellation reason.
     * @return int|false Number of rows updated or false on failure.
     */
    public function cancel_pending_emails($booking_id, $exclude_types = [], $reason = 'Cancelled due to booking update')
    {
        return $this->queue_repo->cancel_pending_by_booking($booking_id, $exclude_types, $reason);
    }

    /**
     * Create system default templates.
     *
     * @param bool $overwrite Whether to overwrite existing system templates.
     * @return void
     */
    public function create_default_templates($overwrite = false)
    {
        foreach ($this->get_default_templates_data() as $template) {
            if ($overwrite) {
                $this->template_repo->save($template);
            } else {
                // Check if already exists
                $existing = $this->template_repo->get_template(null, $template['template_key']);
                if (!$existing) {
                    $this->template_repo->save($template);
                }
            }
        }
    }

    /**
     * Get the array of default template definitions.
     *
     * @return array
     */
    private function get_default_templates_data()
    {
        return [
            [
                'template_key' => 'booking_confirmation',
                'subject'      => 'Your Event Booking Confirmation - {{event_title}}',
                'body'         => $this->get_default_template_body('booking_confirmation'),
            ],
            [
                'template_key' => 'course_reminder',
                'subject'      => 'Reminder: {{event_title}} is in {{days_until}} days',
                'body'         => $this->get_default_template_body('course_reminder'),
            ],
            [
                'template_key' => 'post_course_feedback',
                'subject'      => 'How was {{event_title}}? We\'d love your feedback',
                'body'         => $this->get_default_template_body('post_course_feedback'),
            ],
            [
                'template_key' => 'booking_cancelled',
                'subject'      => 'Your booking for {{event_title}} has been cancelled',
                'body'         => $this->get_default_template_body('booking_cancelled'),
            ],
            [
                'template_key' => 'refund_issued',
                'subject'      => 'Your refund of {{amount}} has been issued',
                'body'         => $this->get_default_template_body('refund_issued'),
            ],
            [
                'template_key' => 'remaining_payment_link',
                'subject'      => 'Complete Your Payment for {{event_title}}',
                'body'         => $this->get_default_template_body('remaining_payment_link'),
            ],
            [
                'template_key' => 'course_changed',
                'subject'      => 'Event Update: {{event_title}} Has Changed',
                'body'         => $this->get_default_template_body('course_changed'),
            ],
            [
                'template_key' => 'organizer_new_booking',
                'subject'      => 'New Booking: {{customer_name}} has booked {{event_title}}',
                'body'         => $this->get_default_template_body('organizer_new_booking'),
            ],
        ];
    }

    /**
     * Get default template body for a template type.
     *
     * @param string $template_key Template key.
     * @return string HTML template body.
     */
    private function get_default_template_body($template_key)
    {
        $templates = [
                        'booking_confirmation' => '<p>Dear {{customer_name}},</p>
                            <p>Thank you for your booking. We are pleased to confirm that your place has been secured, and we look forward to seeing you at the event.</p>
                            <p><strong>Your Booking Details:</strong><br>
                            Booking Number: {{booking_number}}<br>
                            Event: {{event_title}}<br>
                            Date: {{event_date}}<br>
                            Start time: {{event_start_time}}<br>
                            Payment: {{booking_amount}} ({{payment_type_label}})<br>
                            Location: {{event_location}} {{get_directions}}</p>
                            <p>To finalise your booking and assist the event organizer in understanding your needs, please answer any remaining questions in the event registration form.</p>
                            <p>If something arises between now and the event date that means you need to cancel your booking, please let the event organizer know as soon as possible.</p>
                            <p>Kind regards,</p>',
              
            'course_reminder' => '<p>Dear {{customer_name}},</p>
              <p>This is a friendly reminder that {{event_title}} is coming up in {{days_until}} days!</p>
               <p>Could you please confirm by return email that you have received this email and will be attending the event?</p>
               <p><strong>Event Details:</strong><br>
              Date: {{event_date}}<br>
              Location: {{event_location}} {{get_directions}}<br>
              Start Time: {{event_start_time}}</p>
               <p>Please arrive 15 minutes early for events attended in person. If you have any questions, please don\'t hesitate to contact us.</p>',

                          'post_course_feedback' => '<p>Dear {{customer_name}},</p>
              <p>Thank you for attending {{event_title}}!</p>
               <p>We hope you enjoyed {{event_title}}. Please find below any follow-up information and resources provided by the event organizer.</p>
               <p>Thank you for taking part in the event.</p>
               <p>Warm regards, {{organiser_name}}</p>',

                          'post_course_followup' => '<p>Dear {{customer_name}},</p>
              <p>It\'s been {{days_after}} days since you attended {{event_title}}. We hope you\'ve found the information valuable!</p>
              <p>If you have any follow-up questions or need additional support, please don\'t hesitate to reach out.</p>',

                          'booking_cancelled' => '<p>Dear {{customer_name}},</p>
              <p>Your booking for {{event_title}} has been cancelled.</p>
              <p>If you have any questions about this cancellation, please contact us.</p>',

                          'refund_issued' => '<p>Dear {{customer_name}},</p>
              <p>Your refund of {{amount}} has been processed.</p>
              <p>This refund will appear in your account within 3-5 business days depending on your bank.</p>
              <p>With thanks, {{organiser_name}}</p>',

                          'pending_payment_link' => '<p>Dear {{customer_name}},</p>
              <p>You started booking <strong>{{event_title}}</strong> but didn\'t complete the payment. We\'ve saved your spot!</p>
              <p><strong>Booking Details:</strong></p>
              <ul>
                <li>Booking Number: {{booking_number}}</li>
                 <li>Event: {{event_title}}</li>
                <li>Amount Due: ${{amount_due}}</li>
              </ul>
              <p>To complete your booking, please click the button below:</p>
              <p style="text-align: center; margin: 30px 0;">
                <a href="{{payment_url}}" style="background-color: #2271b1; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; font-weight: bold;">Complete Payment</a>
              </p>
              <p><small style="color: #666;">This payment link will expire on {{expires_at}}. If you need assistance, please contact us.</small></p>',

                          'remaining_payment_link' => '<p>Dear {{customer_name}},</p>
              <p>You have paid a deposit for <strong>{{event_title}}</strong> and we\'ve saved your spot. The outstanding amount of ${{amount_due}} is now due.</p>
              <p><strong>Booking Details:</strong><br>
              Booking Number: {{booking_number}}<br>
               Event: {{event_title}}<br>
              Amount Due: ${{amount_due}}</p>
              <p>To complete your booking and pay your outstanding amount, please click the button below:</p>
              <p style="text-align: center; margin: 30px 0;">
                <a href="{{payment_url}}" style="background-color: #2271b1; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; font-weight: bold;">Complete Payment</a>
              </p>
              <p><small style="color: #666;">This payment link will expire on {{expires_at}}. If you need assistance, please contact us.</small></p>',

                          'course_changed' => '<p>Dear {{customer_name}},</p>
              <p>We wanted to let you know that <strong>{{event_title}}</strong> has been updated.</p>
              <p><strong>Booking Details:</strong></p>
              <ul>
                <li>Booking Number: {{booking_number}}</li>
                 <li>Event: {{event_title}}</li>
                <li>Date: {{event_date}}</li>
              </ul>
              <p><strong>What changed:</strong></p>
              <p>{{changed_fields}}</p>
              <p>If you have any questions or concerns about these changes, please don\'t hesitate to contact us.</p>',

                          'organizer_new_booking' => '<p>Hi,</p>
              <p>A new booking has been made for <strong>{{event_title}}</strong>.</p>
              <p><strong>Booking Details:</strong><br>
              Booking Number: {{booking_number}}<br>
               Event: {{event_title}}<br>
              Date: {{event_date}}<br>
              Start Time: {{event_start_time}}<br>
              Payment: {{booking_amount}} ({{payment_type}})</p>
              <p><strong>Customer Details:</strong><br>
              Name: {{customer_name}}<br>
               Email: {{registrant_email}}<br>
               Phone: {{customer_phone}}</p>
               <p><strong>Registration Details:</strong><br>{{all_fields}}</p>
               <p>{{customer_link}}</p>',
        ];

        return $templates[$template_key] ?? '<p>Hello {{customer_name}},</p><p>Email content here</p>';
    }

    /**
     * Cleanup old sent emails.
     *
     * @param int $days Delete emails older than X days.
     * @return int Number of deleted emails.
     */
    public function cleanup_old_emails($days = 90)
    {
        return $this->queue_repo->delete_old_sent($days);
    }
}
