<?php
/**
 * Abstract Email Handler.
 *
 * Base class for all email handlers.
 * Provides common functionality for specific email types.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails;

use HMWEvents\Helpers\ConfigHelper;
use HMWEvents\Services\BookingDetailsService;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Abstract Email Handler Class.
 */
abstract class AbstractEmailHandler
{
    /**
     * Email queue repository.
     *
     * @var EmailQueueRepository
     */
    protected $queue_repo;

    /**
     * Email template repository.
     *
     * @var EmailTemplateRepository
     */
    protected $template_repo;

    /**
     * Template key for this handler.
     *
     * @var string
     */
    protected $template_key = '';

    /**
     * Email type identifier.
     *
     * @var string
     */
    protected $email_type = '';

    /**
     * Available template variables.
     *
     * @var array
     */
    protected $template_variables = [];

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->queue_repo = new EmailQueueRepository();
        $this->template_repo = new EmailTemplateRepository();
    }

    /**
     * Get email type.
     *
     * @return string Email type identifier.
     */
    public function get_email_type()
    {
        return $this->email_type;
    }

    /**
     * Get template key.
     *
     * @return string Template key.
     */
    public function get_template_key()
    {
        return $this->template_key;
    }

    /**
     * Queue an email to be sent.
     *
     * @param array $data Email data with recipient, subject, variables, etc.
     * @return int|false Email ID or false on failure.
     */
    public function queue($data)
    {
        // Validate required fields
        if (empty($data['recipient_email'])) {
            error_log('Email handler: Missing recipient_email');
            return false;
        }

        // Store minimal data - content will be generated at send time
        // This ensures course data is always current when email is sent
        // However, time-sensitive data (like payment links) can be stored in template_data
        $email_id = $this->queue_repo->add([
            'booking_id'      => $data['booking_id'] ?? null,
            'educator_id'     => $data['educator_id'] ?? null,
            'recipient_email' => $data['recipient_email'],
            'recipient_name'  => $data['recipient_name'] ?? '',
            'email_type'      => $this->email_type,
            'subject'         => '', // Will be generated at send time
            'template_key'    => $this->template_key,
            'template_data'   => isset($data['template_data']) ? json_encode($data['template_data']) : null,
            'html_body'       => '', // Will be generated at send time
            'scheduled_at'    => $data['scheduled_at'] ?? current_time('mysql'),
        ]);

        if ($email_id) {
            do_action('hmwevents_email_queued', $email_id, $this->email_type, $data);
        }

        return $email_id;
    }

    /**
     * Get rendered email content (subject and body).
     *
     * @param array $data Email data.
     * @return array|false Array with 'subject' and 'body' or false on failure.
     */
    protected function get_rendered_content($data)
    {
        $educator_id = $data['educator_id'] ?? null;
        // Use template_key from data if provided (from queue), otherwise use handler's default
        $template_key = $data['template_key'] ?? $this->template_key;
        $template = $this->template_repo->get_template($educator_id, $template_key);

        if (!$template) {
            error_log('Email template not found: ' . $template_key . ' for educator ' . ($educator_id ?? 'system'));
            return false;
        }

        $variables = $data['template_data'] ?? [];
        return $this->template_repo->render($template, $variables);
    }

    /**
     * Send an email from the queue.
     *
     * This is called by the queue processor.
     *
     * @param object $email Email object from queue.
     * @return bool Success or failure.
     */
    public function send($email)
    {
        // Mark as processing
        $this->queue_repo->mark_processing($email->id);

        try {
            // Get booking ID from dedicated column
            $booking_id = $email->booking_id;

            if (!$booking_id) {
                throw new \Exception('No booking ID found for email');
            }

            // If booking is cancelled/refunded, skip non-applicable emails
            if ($this->should_skip_email_for_booking($email, $booking_id)) {
                $this->queue_repo->update_status(
                    $email->id,
                    'cancelled',
                    'Skipped: booking cancelled or refunded'
                );
                return false;
            }

            // Re-fetch current booking/course data
            $fresh_data = $this->prepare_fresh_template_data($booking_id);
            if (!$fresh_data) {
                throw new \Exception('Failed to fetch booking data for booking ID: ' . $booking_id);
            }

            // If email has stored template data (e.g. payment links with URLs), merge it.
            // Volatile fields that must always reflect live DB state are stripped from stored
            // data so that fresh values are never overridden on resend.
            $stored_data = [];
            if (!empty($email->template_data)) {
                $stored_data = json_decode($email->template_data, true) ?: [];
                $volatile_keys = ['payment_type_label', 'payment_status', 'payment_type'];
                foreach ($volatile_keys as $key) {
                    unset($stored_data[$key]);
                }
            }
            $template_data = array_merge($fresh_data, $stored_data);

            // Inject all booking form fields as a formatted HTML table
            $template_data['all_fields'] = $this->get_all_fields_html($booking_id);

            // Render template with fresh data
            $rendered = $this->get_rendered_content([
                'educator_id' => $email->educator_id,
                'template_key' => $email->template_key,
                'template_data' => $template_data,
            ]);

            if (!$rendered) {
                throw new \Exception('Failed to render email template: ' . $this->template_key . ' for educator ' . ($email->educator_id ?? 'system'));
            }

            // Before send hook
            do_action('hmwevents_before_email_send', $email);

            // Send email using WordPress with freshly rendered content
            $educator_email = '';
            if (!empty($email->educator_id)) {
                $educator_user = get_userdata($email->educator_id);
                if ($educator_user) {
                    $educator_email = $educator_user->user_email;
                }
            }

            if (!empty($email->booking_id)) {
                global $wpdb;
                $event_post_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT event_post_id FROM {$wpdb->prefix}hmwevents_bookings WHERE id = %d",
                    $email->booking_id
                ));
                if ($event_post_id) {
                    $override = get_post_meta((int) $event_post_id, '_event_notification_email', true);
                    if ($override && is_email($override)) {
                        $educator_email = $override;
                    }
                }
            }

            $sent = wp_mail(
                $email->recipient_email,
                $rendered['subject'],
                $rendered['body'],
                $this->get_email_headers($educator_email)
            );

            if ($sent) {
                // Mark as sent
                $this->queue_repo->mark_sent($email->id);

                // After send hook
                do_action('hmwevents_after_email_sent', $email);

                return true;
            }

            // Mark as failed
            $this->queue_repo->mark_failed($email->id, 'wp_mail returned false');
            return false;

        } catch (\Exception $e) {
            $this->queue_repo->mark_failed($email->id, $e->getMessage());
            error_log('Email send error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get email headers.
     *
     * @param string $educator_email Optional educator email for Reply-To header.
     * @return array Email headers.
     */
    protected function get_email_headers($educator_email = '')
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->get_from_address(),
        ];

        if (!empty($educator_email)) {
            $headers[] = 'Reply-To: ' . $educator_email;
        }

        return $headers;
    }

    /**
     * Get from address.
     *
     * Override in child classes if needed.
     *
     * @return string From address.
     */
    protected function get_from_address()
    {
        $blog_name = get_option('blogname');
        $admin_email = get_option('admin_email');
        return "{$blog_name} <{$admin_email}>";
    }

    /**
     * Process/prepare data for template.
     *
     * Override in child classes to transform data.
     *
     * @param array $raw_data Raw data from booking/event.
     * @return array Template variables.
     */
    public function prepare_template_data($raw_data)
    {
        return $raw_data;
    }

    /**
     * Get fresh template data from booking ID (public wrapper).
     *
     * @param int $booking_id Booking ID.
     * @return array|false Template variables or false on failure.
     */
    public function get_template_data_for_booking($booking_id)
    {
        $data = $this->prepare_fresh_template_data($booking_id);
        if (is_array($data)) {
            $data['all_fields'] = $this->get_all_fields_html($booking_id);
        }
        return $data;
    }

    /**
     * Prepare fresh template data from booking ID.
     *
     * Re-fetches booking and course data to ensure email has current information.
     * Child classes MUST implement this method.
     *
     * @param int $booking_id Booking ID.
     * @return array|false Template variables or false on failure.
     */
    abstract protected function prepare_fresh_template_data($booking_id);

    /**
     * Check if email should be skipped due to booking status.
     *
     * @param object $email Email object.
     * @param int    $booking_id Booking ID.
     * @return bool True if should skip.
     */
    protected function should_skip_email_for_booking($email, $booking_id)
    {
        global $wpdb;

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT status, payment_status FROM {$wpdb->prefix}hmwevents_bookings WHERE id = %d",
            $booking_id
        ));

        if (!$booking) {
            return false;
        }

        $is_cancelled = $booking->status === 'cancelled';
        $is_refunded = $booking->payment_status === 'refunded';

        if (!$is_cancelled && !$is_refunded) {
            return false;
        }

        $skip_types = [
            'course_reminder',
            'post_course_feedback',
            'post_course_followup',
            'booking_confirmation',
            'booking_confirmed',
            'payment_received',
            'course_changed',
        ];

        return in_array($email->email_type, $skip_types, true);
    }

    /**
     * Validate email data.
     *
     * Override in child classes for specific validation.
     *
     * @param array $data Email data.
     * @return bool|string True if valid, error message string if invalid.
     */
    public function validate($data)
    {
        if (empty($data['recipient_email'])) {
            return 'Recipient email is required';
        }

        if (!is_email($data['recipient_email'])) {
            return 'Invalid email address: ' . $data['recipient_email'];
        }

        return true;
    }

    /**
     * Get customer name from customer post.
     *
     * Tries ACF customer_first_name first, then falls back to post title,
     * post meta, and finally the ACF customer_name field.
     *
     * @param int $customer_post_id Customer post ID.
     * @return string Customer name or empty string.
     */
    protected function get_customer_name($customer_post_id)
    {
        if (!$customer_post_id) {
            return '';
        }

        // 1. Try ACF customer_first_name field first (preferred).
        if (function_exists('get_field')) {
            $first_name = get_field('customer_first_name', $customer_post_id);
            if (!empty($first_name) && is_string($first_name)) {
                return $first_name;
            }
        }

        // 2. Try post title.
        $name = get_the_title($customer_post_id);
        if (!empty($name)) {
            return $name;
        }

        // 3. Try post meta.
        $meta_name = get_post_meta($customer_post_id, 'customer_name', true);
        if (!empty($meta_name)) {
            return $meta_name;
        }

        // 4. Try ACF customer_name field.
        if (function_exists('get_field')) {
            $acf_name = get_field('customer_name', $customer_post_id);
            if (!empty($acf_name)) {
                return $acf_name;
            }
        }

        return '';
    }

    /**
     * Resolve customer display name for email use.
     *
     * Tries ACF customer_first_name first, then the provided fallback
     * string (typically from a SQL JOIN), and finally get_customer_name().
     *
     * @param int    $customer_post_id Customer post ID.
     * @param string $fallback         Fallback name (e.g. post_title from SQL JOIN).
     * @return string Resolved customer display name.
     */
    protected function resolve_customer_display_name($customer_post_id, $fallback = '')
    {
        if (!$customer_post_id) {
            return $fallback;
        }

        // 1. Try ACF customer_first_name first (preferred).
        if (function_exists('get_field')) {
            $first_name = get_field('customer_first_name', $customer_post_id);
            if (!empty($first_name) && is_string($first_name)) {
                return $first_name;
            }
        }

        // 2. Use the fallback string if provided.
        if (!empty($fallback)) {
            return $fallback;
        }

        // 3. Full resolution via get_customer_name().
        return $this->get_customer_name($customer_post_id);
    }

    /**
     * Get normalized course location address.
     *
     * @param int $course_id Course post ID.
     * @return string
     */
    protected function get_course_location_address($course_id)
    {
        if (!$course_id || !function_exists('get_field')) {
            return '';
        }

        $location = get_field('course_location_address', $course_id);
        return $this->normalize_location_address($location);
    }

    /**
     * Build Google Maps directions link for a destination address.
     *
     * @param string $address Destination address.
     * @return string
     */
    protected function build_directions_link($address)
    {
        $address = trim((string) $address);
        if ($address === '') {
            return '';
        }

        return "<a href='https://www.google.com/maps/dir/?api=1&destination=" . rawurlencode($address) . "' target='_blank'>Get Directions</a>";
    }

    /**
     * Build audio download link to dropbox file.
     *
     * @return string Anchor tag with download link or empty string if not configured.
     */
    protected function build_download_audio_track()
    {
        $download_url = apply_filters('hmwevents_audio_download_url', ConfigHelper::get_option('hmwevents_audio_download_url', ''));
        $download_url = trim((string) $download_url);

        if ($download_url === '') {
            return '';
        }

        // Ensure the URL is valid
        if (!filter_var($download_url, FILTER_VALIDATE_URL)) {
            error_log('Invalid audio download URL: ' . $download_url);
            return '';
        }

        return "<a href='" . esc_url($download_url) . "' target='_blank'>Download Audio Track</a>";
    }

    /**
     * Build free pre-course audio track link.
     *
     * @return string Anchor tag with download link or empty string if not configured.
     */
    protected function build_free_pre_course_audio_track_link()
    {
        $download_url = apply_filters('hmwevents_free_pre_course_audio_url', ConfigHelper::get_option('hmwevents_free_pre_course_audio_url', ''));
        $download_url = trim((string) $download_url);

        if ($download_url === '') {
            return '';
        }

        // Ensure the URL is valid
        if (!filter_var($download_url, FILTER_VALIDATE_URL)) {
            error_log('Invalid free pre-course audio URL: ' . $download_url);
            return '';
        }

        return "<a href='" . esc_url($download_url) . "' target='_blank'>Download Relaxation Track</a>";
    }

    /**
     * Build feedback form link.
     *
     * @param int $booking_id Booking ID.
     * @return string Anchor tag with feedback link.
     */
    protected function build_feedback_url($booking_id)
    {
        $default_base_url = home_url('/feedback/');
        $base_url = trim((string) ConfigHelper::get_option('hmwevents_feedback_form_url', $default_base_url));

        if ($base_url === '') {
            $base_url = $default_base_url;
        }

        if (!wp_http_validate_url($base_url)) {
            error_log('Invalid feedback form URL: ' . $base_url);
            $base_url = $default_base_url;
        }

        $feedback_url = add_query_arg('booking', (int) $booking_id, $base_url);

        return "<a href='" . esc_url($feedback_url) . "' target='_blank'>Take the educator and course feedback survey</a>";
    }

    /**
     * Build all universal template variables available to every email type.
     *
     * @param int    $booking_id      Booking ID.
     * @param string $course_location Course location address.
     * @return array
     */
    protected function build_universal_variables($booking_id, $course_location = '')
    {
        $feedback_link = $this->build_feedback_url($booking_id);

        return [
            'course_location'             => $course_location,
            'get_directions'              => $this->build_directions_link($course_location),
            'download_audio_track'         => $this->build_download_audio_track(),
            'free_pre_course_audio_track' => $this->build_free_pre_course_audio_track_link(),
            'download_relaxation_track'   => $this->build_free_pre_course_audio_track_link(),
            'feedback_url'                => $feedback_link,
            'feedback_survey'             => $feedback_link,
        ];
    }

    /**
     * Normalize location field value to a readable address.
     *
     * @param mixed $location ACF location value.
     * @return string
     */
    protected function normalize_location_address($location)
    {
        if (empty($location)) {
            return '';
        }

        if (is_array($location)) {
            return $location['address'] ?? '';
        }

        return (string) $location;
    }

    /**
     * Get template variables description for admin display.
     *
     * @return array Array of variable => description.
     */
    public function get_template_variables_description()
    {
        return [
            'customer_name'               => 'Customer name',
            'registrant_email'              => 'Customer email',
            'course_name'                 => 'Course name',
            'course_date'                 => 'Course date',
            'booking_number'              => 'Booking confirmation number',
            'course_location'             => 'Course location address',
            'course_start_time'           => 'Course start time',
            'get_directions'              => 'Google Maps directions URL to the course location',
            'download_audio_track'         => 'Download link to audio file (if configured)',
            'free_pre_course_audio_track' => 'Free pre-course audio track link',
            'download_relaxation_track'   => 'Download link to free pre-course relaxation track',
            'feedback_survey'             => 'Feedback form URL',
            'days_until'                  => 'Days until course',
            'days_after'                  => 'Days after course',
            'booking_amount'              => 'Booking amount (formatted)',
            'reason'                      => 'Cancellation reason',
            'amount'                      => 'Amount (formatted)',
            'refund_amount'               => 'Refund amount (formatted)',
            'changed_fields'              => 'Changed fields list',
            'old_values'                  => 'Old values (JSON)',
            'all_fields'                  => 'All booking form fields as a key → value table',
        ];
    }

    /**
     * Build an HTML key→value table of all booking form fields.
     *
     * @param int $booking_id Booking ID.
     * @return string HTML string, or empty string if no data found.
     */
    protected function get_all_fields_html($booking_id)
    {
        global $wpdb;

        $service   = new BookingDetailsService();
        $form_data = $service->get_form_data($booking_id) ?: [];

        // Enrich form_data with customer post meta for any missing fields.
        // Manual bookings store contact info in post meta rather than form_data,
        // so this ensures {all_fields} is always complete regardless of booking source.
        $registrant_post_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT registrant_post_id FROM {$wpdb->prefix}hmwevents_bookings WHERE id = %d",
            $booking_id
        ));

        if ($registrant_post_id > 0) {
            foreach (\HMWEvents\Registry\RegistrationFieldRegistry::registrant_meta_fields() as $key => $field) {
                if (empty($form_data[$key])) {
                    $val = get_post_meta($registrant_post_id, $field['meta_key'], true);
                    if ($val !== '' && $val !== false) {
                        $form_data[$key] = $val;
                    }
                }
            }
        }

        if (empty($form_data)) {
            return '';
        }

        $rows         = '';
        $registry_keys = [];

        // 1. Render registry fields in canonical order with proper labels.
        foreach (\HMWEvents\Registry\RegistrationFieldRegistry::for_email() as $key => $field) {
            $registry_keys[] = $key;
            $value = \HMWEvents\Registry\RegistrationFieldRegistry::resolve_legacy_value($form_data, $key);

            if ($value === null || $value === '') {
                continue;
            }

            if ($field['type'] === 'checkbox') {
                $value = ($value === true || $value === 'true' || $value === '1' || $value === 1) ? 'Yes' : 'No';
            } elseif (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            } else {
                $value = (string) $value;
            }

            $rows .= '<tr>'
                . '<td style="padding:6px 12px 6px 0;font-weight:bold;vertical-align:top;white-space:nowrap;">' . esc_html($field['label']) . '</td>'
                . '<td style="padding:6px 0;vertical-align:top;">' . nl2br(esc_html($value)) . '</td>'
                . '</tr>';
        }

        // 2. Append any extra form_data keys not covered by the registry
        //    (forward compatibility with custom or future fields).
        foreach ($form_data as $key => $value) {
            if (in_array($key, $registry_keys, true) || $value === null || $value === '') {
                continue;
            }

            $label = ucwords(str_replace(['_', '-'], ' ', (string) $key));

            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            } else {
                $value = (string) $value;
            }

            $rows .= '<tr>'
                . '<td style="padding:6px 12px 6px 0;font-weight:bold;vertical-align:top;white-space:nowrap;">' . esc_html($label) . '</td>'
                . '<td style="padding:6px 0;vertical-align:top;">' . nl2br(esc_html($value)) . '</td>'
                . '</tr>';
        }

        if (empty($rows)) {
            return '';
        }

        return '<table style="border-collapse:collapse;width:100%;">' . $rows . '</table>';
    }
}
