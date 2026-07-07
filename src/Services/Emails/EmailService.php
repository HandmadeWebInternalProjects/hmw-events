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
            'educator_new_booking'  => new OrganizerNewBookingHandler(),
            'payment_link'          => new PaymentLinkHandler(),
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
     * Queue educator new-booking notification email.
     *
     * @param int   $booking_id   Booking ID.
     * @param array $booking_data Optional booking data.
     * @return int|false Email ID or false on failure.
     */
    public function queue_educator_new_booking($booking_id, $booking_data = [])
    {
        $handler = new OrganizerNewBookingHandler();
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
        $handler = new PaymentLinkHandler();
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
        $handler = new BookingConfirmationHandler();
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
        $handler = new ReminderHandler();
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
        $handler = new PostEventHandler();
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
        $handler = new StatusChangeHandler();
        return $handler->queue_for_status_change($booking_id, $status_type, $additional_data);
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
            'payment_link'          => 'payment_link',
            'educator_new_booking'  => 'educator_new_booking',
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
     * Create or overwrite all default templates for a specific educator.
     *
     * Pulls from the system (master) templates in the DB so that any formatting
     * or edits made to the masters are inherited. Falls back to the hardcoded
     * defaults only if a system template does not yet exist in the DB.
     *
     * @param int $educator_id Educator user ID.
     */
    public function create_educator_default_templates($educator_id)
    {
        foreach ($this->get_default_templates_data() as $template) {
            $system_template = $this->template_repo->get_template(null, $template['template_key']);

            if ($system_template) {
                $template['subject'] = $system_template->subject;
                $template['body']    = $system_template->body;
            }

            $template['educator_id'] = $educator_id;
            $this->template_repo->save($template);
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
                'subject'      => 'Your Course Booking Confirmation - {{course_name}}',
                'body'         => $this->get_default_template_body('booking_confirmation'),
            ],
            [
                'template_key' => 'course_reminder',
                'subject'      => 'Reminder: {{course_name}} is in {{days_until}} days',
                'body'         => $this->get_default_template_body('course_reminder'),
            ],
            [
                'template_key' => 'post_course_feedback',
                'subject'      => 'How was {{course_name}}? We\'d love your feedback',
                'body'         => $this->get_default_template_body('post_course_feedback'),
            ],
            [
                'template_key' => 'booking_cancelled',
                'subject'      => 'Your booking for {{course_name}} has been cancelled',
                'body'         => $this->get_default_template_body('booking_cancelled'),
            ],
            [
                'template_key' => 'refund_issued',
                'subject'      => 'Your refund of {{amount}} has been issued',
                'body'         => $this->get_default_template_body('refund_issued'),
            ],
            [
                'template_key' => 'remaining_payment_link',
                'subject'      => 'Complete Your Payment for {{course_name}}',
                'body'         => $this->get_default_template_body('remaining_payment_link'),
            ],
            [
                'template_key' => 'course_changed',
                'subject'      => 'Course Update: {{course_name}} Has Changed',
                'body'         => $this->get_default_template_body('course_changed'),
            ],
            [
                'template_key' => 'educator_new_booking',
                'subject'      => 'New Booking: {{customer_name}} has booked {{course_name}}',
                'body'         => $this->get_default_template_body('educator_new_booking'),
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
                            <p>Thank you for choosing Calmbirth&reg; as your childbirth education provider. We are pleased to have received your online booking as below. Your booking has been secured, so we will see you on the day!</p>
                            <p>As a token of our appreciation here is a one time 10% discount coupon to use on our online shop! Your discount voucher code is <strong>REE6XMZC</strong> and can be redeemed on any purchase.</p>
                            <p>While preparing for your course, Calmbirth&reg; is pleased to provide you with this complimentary guided relaxation audio track. {{download_relaxation_track}}.</p>
                            <p><strong>Your Booking Details:</strong><br>
                            Booking Number: {{booking_number}}<br>
                            Course: {{course_name}}<br>
                            Date: {{course_date}}<br>
                            Start time: {{course_start_time}}<br>
                            Payment: {{booking_amount}} ({{payment_type_label}})<br>
                            Location: {{course_location}} {{get_directions}}</p>
                            <p>To finalise your booking and assist our Educator in understanding your needs please kindly answer some further questions via the Calmbirth Enrolment Form.</p>
                            <p>If something arises between now and the date of your course which necessitates you cancelling your booking, let us know as soon as possible so any couples on our waiting list have the chance to take your place. You can review our cancellation policy here.</p>
                            <p>Kind regards,</p>',
              
            'course_reminder' => '<p>Dear {{customer_name}},</p>
              <p>This is a friendly reminder that {{course_name}} is coming up in {{days_until}} days!</p>
              <p>Could you please confirm by return email that you have received this email and will be attending the course?</p>
              <p><strong>Course Details:</strong><br>
              Date: {{course_date}}<br>
              Location: {{course_location}} {{get_directions}}<br>
              Start Time: {{course_start_time}}</p>
              <p>Please arrive 15 minutes early to courses attended in person. If you have any questions, please don\'t hesitate to contact us.</p>',

                          'post_course_feedback' => '<p>Dear {{customer_name}},</p>
              <p>Thank you for attending {{course_name}}!</p>
              <p>We hope you\'ve enjoyed your Calmbirth course. Please find below important information and resources to support your ongoing preparation for birth.</p>
              <p>Please download your Calmbirth Guided Relaxations via the Audio Downloads here: {{download_audio_track}}.<br>
              This link takes you to a dropbox where you can download the files directly to your phone.</p>
              <p><strong>Calmbirth Relaxations and Breathing Practice</strong></p>
              <p>We encourage you to practice your Calmbirth breathing techniques several times per day and listen to one Calmbirth Relaxation daily.</p>
              <p>These Relaxations have been specifically written to build upon and solidify what you\'ve learnt in your Calmbirth Course. They have a therapeutic component which is accumulative and best results happen when one or more relaxations are listened to daily up until birth.</p>
              <p>The best time each day to do the Calmbirth Relaxations is anytime that you make the time to sit and do them. This could be first thing in the morning, at lunchtime, when you get home from work or even as you are going to bed.</p>
              <p>Remember &ldquo;Practice Makes Permanent&rdquo;.</p>
              <p><strong>Your Calmbirth Course Feedback Survey</strong></p>
              <p>As a Calmbirth couple, your feedback is important and valuable to us and we would appreciate you taking a few minutes to fill in a quick survey on how you found your recent Calmbirth Course to be.</p>
              <p>{{feedback_survey}}</p>
              <p><strong>Other Important Links</strong></p>
              <p>Calmbirth Acupressure Video<br>
              3 Ways to Shorten Labor eBook Spinning Babies</p>
              <p><strong>Other Recommended Resources</strong></p>
              <p>Possums is offering Calmbirth attendees a 30% discount on their Possums Sleep Program - Possums. Use the code Calmbirth30</p>
              <p>Finally, don\'t forget to follow us on Facebook and Instagram (@hmwevents), or visit our HMWEvents Blog where we share lots of positive birth stories and information about birth and parenting.</p>
              <p>We would like to wish you well for your birth. Please do not hesitate to contact us at any time if we can help in any way with your preparation for birth.</p>
              <p>Warm Regards,<br>
              The Calmbirth Team</p>',

                          'post_course_followup' => '<p>Dear {{customer_name}},</p>
              <p>It\'s been {{days_after}} days since you attended {{course_name}}. We hope you\'ve found the information valuable!</p>
              <p>If you have any follow-up questions or need additional support, please don\'t hesitate to reach out.</p>',

                          'booking_cancelled' => '<p>Dear {{customer_name}},</p>
              <p>Your booking for {{course_name}} has been cancelled.</p>
              <p>If you have any questions about this cancellation, please contact us.</p>',

                          'refund_issued' => '<p>Dear {{customer_name}},</p>
              <p>Your refund of {{amount}} has been processed.</p>
              <p>This refund will appear in your account within 3-5 business days depending on your bank.</p>
              <p>With thanks,</p>',

                          'pending_payment_link' => '<p>Dear {{customer_name}},</p>
              <p>You started booking <strong>{{course_name}}</strong> but didn\'t complete the payment. We\'ve saved your spot!</p>
              <p><strong>Booking Details:</strong></p>
              <ul>
                <li>Booking Number: {{booking_number}}</li>
                <li>Course: {{course_name}}</li>
                <li>Amount Due: ${{amount_due}}</li>
              </ul>
              <p>To complete your booking, please click the button below:</p>
              <p style="text-align: center; margin: 30px 0;">
                <a href="{{payment_url}}" style="background-color: #2271b1; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; font-weight: bold;">Complete Payment</a>
              </p>
              <p><small style="color: #666;">This payment link will expire on {{expires_at}}. If you need assistance, please contact us.</small></p>',

                          'remaining_payment_link' => '<p>Dear {{customer_name}},</p>
              <p>You have paid a deposit for <strong>{{course_name}}</strong> and we\'ve saved your spot. The outstanding amount of ${{amount_due}} is now due.</p>
              <p><strong>Booking Details:</strong><br>
              Booking Number: {{booking_number}}<br>
              Course: {{course_name}}<br>
              Amount Due: ${{amount_due}}</p>
              <p>To complete your booking and pay your outstanding amount, please click the button below:</p>
              <p style="text-align: center; margin: 30px 0;">
                <a href="{{payment_url}}" style="background-color: #2271b1; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; font-weight: bold;">Complete Payment</a>
              </p>
              <p><small style="color: #666;">This payment link will expire on {{expires_at}}. If you need assistance, please contact us.</small></p>',

                          'course_changed' => '<p>Dear {{customer_name}},</p>
              <p>We wanted to let you know that <strong>{{course_name}}</strong> has been updated.</p>
              <p><strong>Booking Details:</strong></p>
              <ul>
                <li>Booking Number: {{booking_number}}</li>
                <li>Course: {{course_name}}</li>
                <li>Date: {{course_date}}</li>
              </ul>
              <p><strong>What changed:</strong></p>
              <p>{{changed_fields}}</p>
              <p>If you have any questions or concerns about these changes, please don\'t hesitate to contact us.</p>',

                          'educator_new_booking' => '<p>Hi,</p>
              <p>A new booking has been made for <strong>{{course_name}}</strong>.</p>
              <p><strong>Booking Details:</strong><br>
              Booking Number: {{booking_number}}<br>
              Course: {{course_name}}<br>
              Date: {{course_date}}<br>
              Start Time: {{course_start_time}}<br>
              Payment: {{booking_amount}} ({{payment_type}})</p>
              <p><strong>Customer Details:</strong><br>
              Name: {{customer_name}}<br>
              Email: {{registrant_email}}<br>
              Phone: {{customer_phone}}</p>
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
