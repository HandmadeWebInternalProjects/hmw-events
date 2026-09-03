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
use HMWEvents\Services\EventDataService;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Abstract Email Handler Class.
 */
abstract class AbstractEmailHandler
{
    private ?EventDataService $event_data_service = null;

    protected function event_data(): EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new EventDataService();
        }
        return $this->event_data_service;
    }

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
            'organizer_id'    => $data['organizer_id'] ?? $data['educator_id'] ?? null,
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
        $organizer_id = $data['organizer_id'] ?? $data['educator_id'] ?? null;
        $template_key = $data['template_key'] ?? $this->template_key;
        $template = $this->template_repo->get_template($organizer_id, $template_key);

        if (!$template) {
            error_log('Email template not found: ' . $template_key . ' for organizer ' . ($organizer_id ?? 'system'));
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
                $volatile_keys = ['payment_type_label', 'payment_status', 'payment_type', 'cancel_link'];
                foreach ($volatile_keys as $key) {
                    unset($stored_data[$key]);
                }
            }
            $template_data = array_merge($fresh_data, $stored_data);

            // Inject all booking form fields as a formatted HTML table
            $template_data['all_fields'] = $this->get_all_fields_html($booking_id);

            // Render template with fresh data
            $rendered = $this->get_rendered_content([
                'organizer_id' => $email->organizer_id ?? $email->educator_id,
                'template_key' => $email->template_key,
                'template_data' => $template_data,
            ]);

            if (!$rendered) {
                throw new \Exception('Failed to render email template: ' . ($email->template_key ?? $this->template_key) . ' for organizer ' . ($email->organizer_id ?? $email->educator_id ?? 'system'));
            }

            // Before send hook
            do_action('hmwevents_before_email_send', $email);

            // Send email using WordPress with freshly rendered content
            $educator_email = '';
            $email_organizer_id = $email->organizer_id ?? $email->educator_id;
            if (!empty($email_organizer_id)) {
                $educator_user = get_userdata($email_organizer_id);
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

            $sent = wp_mail(
                $email->recipient_email,
                $rendered['subject'],
                $rendered['body'],
                $this->get_email_headers($educator_email),
                $this->get_attachments($email->id)
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
     * Resolve file attachments queued for an email.
     *
     * @param int $email_id Email queue ID.
     * @return array Absolute file paths.
     */
    protected function get_attachments($email_id): array
    {
        $service = new EmailAttachmentService();
        return $service->get_for_queue((int) $email_id);
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
            "SELECT status, payment_status FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " WHERE id = %d",
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
            'event_changed',
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
     * Get the registrant email address from a registrant post.
     *
     * Tries post meta first, then the ACF field.
     *
     * @param int|null $customer_post_id Registrant post ID.
     * @return string|false Email address or false when unavailable.
     */
    protected function get_registrant_email_address($customer_post_id)
    {
        if (!$customer_post_id) {
            return false;
        }

        $email = get_post_meta($customer_post_id, 'registrant_email', true);
        if ($email) {
            return $email;
        }

        if (function_exists('get_field')) {
            $email = get_field('registrant_email', $customer_post_id);
            if ($email) {
                return $email;
            }
        }

        return false;
    }

    /**
     * Format a date string as "F j, Y", returning the input when unparseable.
     *
     * @param string|int $date Date string or timestamp.
     * @return string
     */
    protected function format_event_date($date): string
    {
        $timestamp = is_numeric($date) ? (int) $date : strtotime((string) $date);
        if ($timestamp === false || ($timestamp === 0 && !is_numeric($date))) {
            return (string) $date;
        }

        return date('F j, Y', $timestamp);
    }

    /**
     * Format an amount with the currency symbol for the given currency code.
     *
     * @param float|string|int $amount Amount.
     * @param string           $currency Currency code (defaults to AUD).
     * @return string
     */
    protected function format_money($amount, string $currency = 'AUD'): string
    {
        $currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);
        return $currency_symbol . number_format((float) $amount, 2);
    }

    /**
     * Get normalized course location address.
     *
     * @param int $course_id Course post ID.
     * @return string
     */
    protected function get_course_location_address($course_id)
    {
        if (!$course_id) {
            return '';
        }

        $name    = $this->event_data()->get_venue_name($course_id) ?? '';
        $address = $this->event_data()->get_venue_address_string($course_id);

        $parts = array_filter([trim((string) $name), trim((string) $address)]);

        return implode(', ', $parts);
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

        return "<a href='" . esc_url($feedback_url) . "' target='_blank'>Take the event feedback survey</a>";
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
        $event     = $this->resolve_booking_event($booking_id);
        $organiser = $this->resolve_organiser($event);

        $event_title      = '';
        $event_date       = '';
        $event_start_time = '';

        if ($event) {
            $event_title = $event->post_title;

            $datetime = $this->event_data()->get_start_date((int) $event->ID);
            if ($datetime) {
                $event_date = $this->format_event_date($datetime);
                $timestamp  = strtotime($datetime);
                if ($timestamp !== false) {
                    $event_start_time = date('g:i A', $timestamp);
                }
            }
        }

        $feedback_link = $this->build_feedback_url($booking_id);

        return [
            'organiser_name'              => $organiser['name'],
            'organiser_email'             => $organiser['email'],
            'event_title'                 => $event_title,
            'event_date'                  => $event_date,
            'event_start_time'            => $event_start_time,
            'event_location'              => $course_location,
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
     * Resolve the event post for a booking.
     *
     * @param int $booking_id Booking ID.
     * @return \WP_Post|null
     */
    protected function resolve_booking_event(int $booking_id): ?\WP_Post
    {
        global $wpdb;

        $event_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT event_post_id FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " WHERE id = %d",
            $booking_id
        ));

        if (!$event_id) {
            return null;
        }

        $post = get_post($event_id);

        return $post instanceof \WP_Post ? $post : null;
    }

    /**
     * Resolve the event organiser's display name and email.
     *
     * Uses the assigned organizer, falling back to the event author.
     *
     * @param \WP_Post|null $event Event post.
     * @return array{name:string, email:string}
     */
    protected function resolve_organiser(?\WP_Post $event): array
    {
        $name  = '';
        $email = '';

        if ($event) {
            $organizer_id = $this->event_data()->get_organizer_id((int) $event->ID);
            if (!$organizer_id) {
                $organizer_id = (int) $event->post_author;
            }

            $user = get_userdata($organizer_id);
            if ($user) {
                $name  = $user->display_name;
                $email = $user->user_email;
            }
        }

        return ['name' => $name, 'email' => $email];
    }

    /**
     * Get template variables description for admin display.
     *
     * @return array Array of variable => description.
     */
    public function get_template_variables_description()
    {
        return [
            'customer_name'     => 'Customer name',
            'registrant_email'  => 'Customer email',
            'organiser_name'    => 'Event organiser name',
            'organiser_email'   => 'Event organiser email',
            'event_title'       => 'Event title',
            'event_date'        => 'Event date',
            'event_start_time'  => 'Event start time',
            'event_location'    => 'Event venue name and address',
            'booking_number'    => 'Booking confirmation number',
            'get_directions'    => 'Google Maps directions URL to the event location',
            'days_until'        => 'Days until event',
            'days_after'        => 'Days after event',
            'booking_amount'    => 'Booking amount (formatted)',
            'reason'            => 'Cancellation reason',
            'amount'            => 'Amount (formatted)',
            'refund_amount'     => 'Refund amount (formatted)',
            'changed_fields'    => 'Changed fields list',
            'old_values'        => 'Old values (JSON)',
            'all_fields'        => 'All booking form fields as a key → value table',
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
            "SELECT registrant_post_id FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " WHERE id = %d",
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
                $value = $this->format_nested_field_value($value);
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

    private function format_nested_field_value(array $value): string
    {
        $parts = [];

        foreach ($value as $index => $item) {
            if (is_array($item)) {
                $fields = [];
                foreach ($item as $key => $field_value) {
                    $label = ucwords(str_replace(['_', '-'], ' ', (string) $key));
                    $fields[] = $label . ': ' . (is_array($field_value) ? implode(', ', array_filter(array_map('strval', $field_value))) : (string) $field_value);
                }
                $parts[] = 'Attendee ' . ((int) $index + 1) . ' - ' . implode('; ', $fields);
            } else {
                $parts[] = (string) $item;
            }
        }

        return implode("\n", $parts);
    }
}
