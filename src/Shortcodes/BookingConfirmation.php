<?php

/**
 * Booking Confirmation Shortcode
 * 
 * Displays booking confirmation details.
 * Usage: [hmwevents_booking_confirmation]
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Shortcodes;

defined('ABSPATH') || die('Don\'t run this file directly!');

class BookingConfirmation
{
  /**
   * Register the shortcode.
   *
   * @since 1.0.0
   */
  public function register()
  {
    add_shortcode('hmwevents_booking_confirmation', [$this, 'render']);

    // Enqueue styles
    add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);

    // AJAX handler for PDF download (public, no login required)
    add_action('wp_ajax_nopriv_hmwevents_booking_pdf', [$this, 'ajax_download_pdf']);
    add_action('wp_ajax_hmwevents_booking_pdf', [$this, 'ajax_download_pdf']);
  }

  /**
   * AJAX handler: Generate and stream PDF download.
   */
  public function ajax_download_pdf()
  {
    $booking_number = isset($_GET['booking']) ? sanitize_text_field($_GET['booking']) : '';

    if (empty($booking_number)) {
      wp_die('Invalid booking reference.');
    }

    // Fetch booking data (same query as render)
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("
      SELECT
        b.*,
        bg.booking_reference,
        bg.total_amount,
        bg.payment_type as group_payment_type,
        bg.payment_status,
        c.post_title as event_name,
        c.ID as event_id,
        cust.post_title as registrant_name,
        pt.amount as paid_amount,
        pt.gateway_transaction_id,
        pt.created_at as payment_date
      FROM {$wpdb->prefix}hmwevents_bookings b
      LEFT JOIN {$wpdb->prefix}hmwevents_booking_groups bg ON b.booking_group_id = bg.id
      LEFT JOIN {$wpdb->prefix}posts c ON b.event_post_id = c.ID
      LEFT JOIN {$wpdb->prefix}posts cust ON b.registrant_post_id = cust.ID
      LEFT JOIN {$wpdb->prefix}hmwevents_payment_transactions pt ON bg.id = pt.booking_group_id
      WHERE b.booking_number = %s
      AND b.deleted_at IS NULL
      ORDER BY pt.created_at DESC
      LIMIT 1
    ", $booking_number));

    if (!$booking) {
      wp_die('Booking not found.');
    }

    $registrant_email = get_post_meta($booking->registrant_post_id, 'registrant_email', true);
    $registrant_phone = get_post_meta($booking->registrant_post_id, 'registrant_phone', true);
    $event_date = get_field('_event_start_date', $booking->event_id);
    $event_time = get_field('_event_end_date', $booking->event_id);
    $event_location = get_field('_event_venue_name', $booking->event_id);
    $event_organizer = get_the_author_meta('display_name', get_post_field('post_author', $booking->event_id));

    $currency = !empty($booking->currency) ? $booking->currency : \HMWEvents\Meta\CourseMeta::get_course_currency($booking->event_id);
    $currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);

    $voucher_usage = $wpdb->get_row($wpdb->prepare("
      SELECT * FROM {$wpdb->prefix}hmwevents_voucher_usage
      WHERE booking_id = %d
    ", $booking->id));

    $generator = new \HMWEvents\Services\BookingPdfGenerator();
    $generator->set_booking($booking);
    $generator->set_meta([
      'registrant_email'   => $registrant_email,
      'registrant_phone'   => $registrant_phone,
      'event_date'         => $event_date,
      'event_time'         => $event_time,
      'event_location'     => $event_location,
      'event_organizer'    => $event_organizer,
      'currency_symbol'    => $currency_symbol,
      'voucher'            => $voucher_usage,
    ]);
    $generator->stream();
  }

  /**
   * Enqueue styles.
   *
   * @since 1.0.0
   */
  public function enqueue_styles()
  {
    global $post;

    if (!$post) {
      return;
    }
    // Check Breakdance content if function exists
    if (function_exists('\Breakdance\Data\get_tree')) {
      $breakdance_tree = \Breakdance\Data\get_tree($post->ID);
      if (has_shortcode_in_breakdance_tree($breakdance_tree, 'hmwevents_booking_confirmation')) {
        wp_enqueue_style(
          'hmwevents-booking-confirmation',
          plugin_dir_url(dirname(__DIR__)) . 'assets/css/booking-confirmation.css',
          [],
          '1.0.0'
        );
      }
    }
  }

  /**
   * Render the booking confirmation.
   *
   * @since 1.0.0
   * @param array $atts Shortcode attributes.
   * @return string HTML output.
   */
  public function render($atts)
  {
    global $wpdb;

    // Get booking number from URL
    $booking_number = isset($_GET['booking']) ? sanitize_text_field($_GET['booking']) : '';

    if (empty($booking_number)) {
      return $this->render_error('No booking reference provided.');
    }

    // Get booking details
    $booking = $wpdb->get_row($wpdb->prepare("
            SELECT 
                b.*,
                bg.booking_reference,
                bg.total_amount,
                bg.payment_type as group_payment_type,
                bg.payment_status,
                c.post_title as event_name,
                c.ID as event_id,
                cust.post_title as registrant_name,
                pt.amount as paid_amount,
                pt.gateway_transaction_id,
                pt.created_at as payment_date
            FROM {$wpdb->prefix}hmwevents_bookings b
            LEFT JOIN {$wpdb->prefix}hmwevents_booking_groups bg ON b.booking_group_id = bg.id
            LEFT JOIN {$wpdb->prefix}posts c ON b.event_post_id = c.ID
            LEFT JOIN {$wpdb->prefix}posts cust ON b.registrant_post_id = cust.ID
            LEFT JOIN {$wpdb->prefix}hmwevents_payment_transactions pt ON bg.id = pt.booking_group_id
            WHERE b.booking_number = %s
            AND b.deleted_at IS NULL
            ORDER BY pt.created_at DESC
            LIMIT 1
        ", $booking_number));

    if (!$booking) {
      return $this->render_error('Booking not found. Please check your booking reference.');
    }

    // Get customer details
    $registrant_email = get_post_meta($booking->registrant_post_id, 'registrant_email', true);
    $registrant_phone = get_post_meta($booking->registrant_post_id, 'registrant_phone', true);

    $event_date = get_field('_event_start_date', $booking->event_id);
    $event_time = get_field('_event_end_date', $booking->event_id);
    $event_location = get_field('_event_venue_name', $booking->event_id);
    $event_organizer = get_the_author_meta('display_name', get_post_field('post_author', $booking->event_id));

    // Get currency for display
    $currency = !empty($booking->currency) ? $booking->currency : \HMWEvents\Meta\CourseMeta::get_course_currency($booking->event_id);
    $currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);

    // Get voucher usage if any
    $voucher_usage = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}hmwevents_voucher_usage
            WHERE booking_id = %d
        ", $booking->id));

    ob_start();
?>
    <div class="hmwevents-confirmation-wrapper">
      <div class="hmwevents-confirmation-header">
        <div class="hmwevents-confirmation-icon">✓</div>
        <h1>Booking Confirmed!</h1>
        <p class="hmwevents-confirmation-subtitle">Thank you for your booking. We look forward to seeing you!</p>
      </div>

      <div class="hmwevents-confirmation-content">
        <!-- Booking Reference -->
        <div class="hmwevents-confirmation-section hmwevents-confirmation-highlight">
          <h2>Your Booking Reference</h2>
          <div class="hmwevents-booking-reference">
            <span class="hmwevents-reference-label">Booking Number:</span>
            <span class="hmwevents-reference-number"><?php echo esc_html($booking->booking_number); ?></span>
          </div>
          <p class="hmwevents-reference-note">Please save this reference number for your records.</p>
        </div>

        <!-- Event Details -->
        <div class="hmwevents-confirmation-section">
          <h2>Event Details</h2>
          <div class="hmwevents-detail-grid">
            <div class="hmwevents-detail-item">
              <span class="hmwevents-detail-label">Course:</span>
              <span class="hmwevents-detail-value"><?php echo esc_html($booking->event_name); ?></span>
            </div>

            <?php if ($event_organizer): ?>
              <div class="hmwevents-detail-item">
                <span class="hmwevents-detail-label">Educator:</span>
                <span class="hmwevents-detail-value"><?php echo esc_html($event_organizer); ?></span>
              </div>
            <?php endif; ?>

            <?php if ($event_date): ?>
              <div class="hmwevents-detail-item">
                <span class="hmwevents-detail-label">Date:</span>
                <span class="hmwevents-detail-value"><?php echo esc_html(date('F j, Y', strtotime($event_date))); ?></span>
              </div>
            <?php endif; ?>

            <?php if ($event_time): ?>
              <div class="hmwevents-detail-item">
                <span class="hmwevents-detail-label">Time:</span>
                <span class="hmwevents-detail-value"><?php echo esc_html($event_time); ?></span>
              </div>
            <?php endif; ?>

            <?php if ($event_location): ?>
              <div class="hmwevents-detail-item hmwevents-detail-item-full">
                <span class="hmwevents-detail-label">Location:</span>
                <span class="hmwevents-detail-value"><?php echo esc_html($event_location); ?></span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Customer Details -->
        <div class="hmwevents-confirmation-section">
          <h2>Your Details</h2>
          <div class="hmwevents-detail-grid">
            <div class="hmwevents-detail-item">
              <span class="hmwevents-detail-label">Name:</span>
              <span class="hmwevents-detail-value"><?php echo esc_html($booking->registrant_name); ?></span>
            </div>

            <div class="hmwevents-detail-item">
              <span class="hmwevents-detail-label">Email:</span>
              <span class="hmwevents-detail-value"><?php echo esc_html($registrant_email); ?></span>
            </div>

            <?php if ($registrant_phone): ?>
              <div class="hmwevents-detail-item">
                <span class="hmwevents-detail-label">Phone:</span>
                <span class="hmwevents-detail-value"><?php echo esc_html($registrant_phone); ?></span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Payment Details -->
        <div class="hmwevents-confirmation-section">
          <h2>Payment Summary</h2>
          <div class="hmwevents-payment-summary">
            <div class="hmwevents-payment-row">
              <span class="hmwevents-payment-label">Payment Status:</span>
              <span class="hmwevents-payment-status hmwevents-status-<?php echo esc_attr($booking->payment_status); ?>">
                <?php echo esc_html(ucfirst($booking->payment_status)); ?>
              </span>
            </div>

            <div class="hmwevents-payment-row">
              <span class="hmwevents-payment-label">Payment Type:</span>
              <span class="hmwevents-payment-value"><?php echo esc_html(ucfirst($booking->group_payment_type)); ?></span>
            </div>

            <?php if ($voucher_usage): ?>
              <div class="hmwevents-payment-row hmwevents-voucher-row">
                <span class="hmwevents-payment-label">Voucher Applied:</span>
                <span class="hmwevents-payment-value">
                  <?php echo esc_html($voucher_usage->voucher_code); ?>
                  (-<?php echo $currency_symbol ?><?php echo number_format($voucher_usage->redeemed_value, 2); ?>)
                </span>
              </div>
            <?php endif; ?>

            <div class="hmwevents-payment-row hmwevents-payment-total">
              <span class="hmwevents-payment-label">Amount Paid:</span>
              <span class="hmwevents-payment-value"><?php echo $currency_symbol ?><?php echo number_format($booking->paid_amount, 2); ?></span>
            </div>

            <?php if ($booking->group_payment_type === 'deposit'): ?>
              <div class="hmwevents-payment-notice">
                <strong>Note:</strong> This is a deposit payment. The remaining balance will be organised by the educator on or before your course date.
              </div>
            <?php endif; ?>

            <?php if ($booking->gateway_transaction_id): ?>
              <div class="hmwevents-payment-row hmwevents-payment-reference">
                <span class="hmwevents-payment-label">Transaction ID:</span>
                <span class="hmwevents-payment-value"><?php echo esc_html($booking->gateway_transaction_id); ?></span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Next Steps -->
        <div class="hmwevents-confirmation-section hmwevents-next-steps">
          <h2>What Happens Next?</h2>
          <ol class="hmwevents-steps-list">
            <li>
              <strong>Confirmation Email</strong>
              <p>You will receive a confirmation email at <?php echo esc_html($registrant_email); ?> with all your booking details.</p>
            </li>
            <li>
              <strong>Course Materials</strong>
              <p>Any pre-course materials or preparation instructions will be sent to you closer to the course date.</p>
            </li>
            <li>
              <strong>Questions?</strong>
              <p>If you have any questions, please don't hesitate to contact your educator.</p>
            </li>
          </ol>
        </div>

        <!-- Actions -->
        <div class="hmwevents-confirmation-actions">
          <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=hmwevents_booking_pdf&booking=' . urlencode($booking->booking_number))); ?>" class="hmwevents-btn hmwevents-btn-secondary">
            <span>Download &amp; Print Confirmation (PDF)</span>
          </a>
          <a href="<?php echo home_url(); ?>" class="hmwevents-btn hmwevents-btn-primary">
            <span>Return to Home</span>
          </a>
        </div>
      </div>
    </div>
  <?php
    return ob_get_clean();
  }

  /**
   * Render error message.
   *
   * @since 1.0.0
   * @param string $message Error message.
   * @return string HTML output.
   */
  private function render_error($message)
  {
    ob_start();
  ?>
    <div class="hmwevents-confirmation-wrapper">
      <div class="hmwevents-confirmation-error">
        <!-- <div class="hmwevents-error-icon">✕</div> -->
        <h2>Booking Not Found</h2>
        <p><?php echo esc_html($message); ?></p>
        <a href="<?php echo home_url(); ?>" class="hmwevents-btn hmwevents-btn-primary">Return to Home</a>
      </div>
    </div>
<?php
    return ob_get_clean();
  }
}
