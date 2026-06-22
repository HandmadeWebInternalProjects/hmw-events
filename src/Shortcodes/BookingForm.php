<?php

/**
 * Booking Form Shortcode
 * 
 * Complete integrated booking form with voucher support and Stripe payment.
 * Usage: [hmwevents_booking_form course_id="123"]
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Shortcodes;

defined('ABSPATH') || die('Don\'t run this file directly!');

class BookingForm
{
  /**
   * Register the shortcode.
   *
   * @since 1.0.0
   */
  public function register()
  {
    add_shortcode('hmwevents_booking_form', [$this, 'render']);
  }

  /**
   * Render the booking form.
   *
   * @since 1.0.0
   * @param array $atts Shortcode attributes.
   * @return string HTML output.
   */
  public function render($atts)
  {
    $atts = shortcode_atts([
      'course_id' => get_the_ID(),
    ], $atts);

    $course_id = intval($atts['course_id']);

    if (!$course_id) {
      return '<p class="hmwevents-error">Course ID is required.</p>';
    }

    $course_cutoff_date = \HMWEvents\Meta\CourseMeta::get_course_cutoff_date($course_id);

    if ($course_cutoff_date) {
      $sydney_timezone = new \DateTimeZone('Australia/Sydney');
      $cutoff_datetime = new \DateTime($course_cutoff_date, $sydney_timezone);
      $current_datetime = new \DateTime('now', $sydney_timezone);

      if ($current_datetime >= $cutoff_datetime) {
        return '<p class="hmwevents-error">Sorry, bookings for this course have closed.</p>';
      }
    }

    // Get course details
    $course = get_post($course_id);
    if (!$course || $course->post_type !== 'educator_course') {
      return '<p class="hmwevents-error">Invalid course.</p>';
    }

    // if external booking link exists, show that instead
    $is_external = get_field('course_is_external', $course_id);
    $external_link = get_field('course_external_url', $course_id);
    if ($is_external && $external_link) {
      return '<p class="hmwevents-info">To book this course, please click the button below to visit this Educator\'s external booking website</p><p><a href="' . esc_url($external_link) . '" target="_blank" class="button-atom button-atom--primary bde-button__button"><span class="button-atom__text">Book Now</span></a></p>';
    }

    // Hide the form if the course is fully booked
    if (!\HMWEvents\Helpers\Course::check_course_availability($course_id)) {
      return '<p class="hmwevents-error">Sorry, this course is fully booked.</p>';
    }

    // Get pricing
    $full_cost = get_field('course_full_cost', $course_id);
    $deposit_cost = get_field('course_deposit_cost', $course_id);
    $currency = \HMWEvents\Meta\CourseMeta::get_course_currency($course_id);
    $currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);
    $educator_id = $course->post_author;

    // Enqueue Stripe
    wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);

    // Enqueue reCAPTCHA if keys are configured in Breakdance settings
    $recaptcha_site_key = '';
    if (function_exists('Breakdance\APIKeys\getKey')) {
      $recaptcha_site_key = \Breakdance\APIKeys\getKey(BREAKDANCE_RECAPTCHA_SITE_KEY_NAME);
    }
    if ($recaptcha_site_key) {
      wp_enqueue_script(
        'google-recaptcha',
        'https://www.google.com/recaptcha/api.js?render=' . rawurlencode($recaptcha_site_key),
        [],
        null,
        true
      );
    }

    // Enqueue our booking form script
    $booking_form_js_path = plugin_dir_path(dirname(__DIR__)) . 'assets/js/booking-form.js';
    $booking_form_css_path = plugin_dir_path(dirname(__DIR__)) . 'assets/css/booking-form.css';

    wp_enqueue_script(
      'hmwevents-booking-form',
      plugin_dir_url(dirname(__DIR__)) . 'assets/js/booking-form.js',
      $recaptcha_site_key ? ['stripe-js', 'google-recaptcha'] : ['stripe-js'],
      file_exists($booking_form_js_path) ? filemtime($booking_form_js_path) : '1.0.0',
      true
    );

    // Enqueue styles
    wp_enqueue_style(
      'hmwevents-booking-form',
      plugin_dir_url(dirname(__DIR__)) . 'assets/css/booking-form.css',
      [],
      file_exists($booking_form_css_path) ? filemtime($booking_form_css_path) : '1.0.0'
    );

    // Localize script with data
    wp_localize_script('hmwevents-booking-form', 'cmsBookingData', [
      'courseId' => $course_id,
      'educatorId' => $educator_id,
      'restUrl' => rest_url('cms/v1'),
      'nonce' => wp_create_nonce('wp_rest'),
      'fullCost' => floatval($full_cost),
      'depositCost' => floatval($deposit_cost),
      'currency' => $currency,
      'currencySymbol' => $currency_symbol,
      'recaptchaSiteKey' => $recaptcha_site_key,
      'bookingFields' => array_values(array_map(
        fn($key, $f) => ['key' => $key, 'type' => $f['type']],
        array_keys(\HMWEvents\Config\BookingFields::for_frontend()),
        \HMWEvents\Config\BookingFields::for_frontend()
      )),
    ]);

    ob_start();
?>
    <div class="hmwevents-booking-form-wrapper" data-course-id="<?php echo esc_attr($course_id); ?>">
      <form id="hmwevents-booking-form" class="hmwevents-booking-form">

        <!-- Registry-driven booking fields (Your Information / Address / Extra Details / Agreements) -->
        <?php $this->render_booking_field_sections(); ?>

        <!-- Voucher Code Section -->
        <div class="hmwevents-form-section hmwevents-voucher-section">
          <h3>Voucher or Coupon Code</h3>

          <div class="hmwevents-voucher-input-group">
            <input type="text"
              id="voucher_code"
              name="voucher_code"
              class="hmwevents-voucher-input"
              placeholder="Enter Code">
            <button type="button" class="hmwevents-btn hmwevents-btn-secondary" id="apply-voucher">
              Apply
            </button>
          </div>

          <div class="hmwevents-voucher-message" id="voucher-message" style="display: none;"></div>

          <div class="hmwevents-voucher-details" id="voucher-details" style="display: none;">
            <div class="hmwevents-voucher-success">
              <strong>✓ Voucher Applied!</strong>
              <p id="voucher-description"></p>
              <button type="button" class="hmwevents-btn-link" id="remove-voucher">Remove</button>
            </div>
          </div>

          <input type="hidden" id="voucher_data" name="voucher_data">
        </div>

        <!-- Payment Type Selection -->
        <div class="hmwevents-form-section hmwevents-payment-type-section">
          <h3>Payment Option</h3>

          <?php if ($deposit_cost): ?>
            <div class="hmwevents-payment-options">
              <label class="hmwevents-payment-option" data-type="full">
                <input type="radio" name="payment_type" value="full" id="payment_full" checked>
                <div class="hmwevents-payment-option-content">
                  <div class="hmwevents-payment-option-header">
                    <strong>Full Payment</strong>
                    <span class="hmwevents-payment-price" data-original="<?php echo esc_attr($full_cost); ?>">
                      <?php echo esc_html($currency_symbol); ?><?php echo number_format($full_cost, 2); ?>
                    </span>
                  </div>
                  <p>Pay the full course fee upfront and secure your spot.</p>
                </div>
              </label>

              <label class="hmwevents-payment-option" data-type="deposit">
                <input type="radio" name="payment_type" value="deposit" id="payment_deposit">
                <div class="hmwevents-payment-option-content">
                  <div class="hmwevents-payment-option-header">
                    <strong>Deposit Payment</strong>
                    <span class="hmwevents-payment-price" data-original="<?php echo esc_attr($deposit_cost); ?>">
                      <?php echo esc_html($currency_symbol); ?><?php echo number_format($deposit_cost, 2); ?>
                    </span>
                  </div>
                  <p>Pay a deposit now and the remainder later.</p>
                </div>
              </label>
            </div>
          <?php else: ?>
            <input type="hidden" name="payment_type" value="full">
            <div class="hmwevents-payment-amount">
              <strong>Course Fee:</strong>
              <span class="hmwevents-payment-price" data-original="<?php echo esc_attr($full_cost); ?>">
                <?php echo esc_html($currency_symbol); ?><?php echo number_format($full_cost, 2); ?>
              </span>
            </div>
          <?php endif; ?>
        </div>

        <!-- Stripe Payment -->
        <div class="hmwevents-form-section hmwevents-payment-section">
          <h3>Payment Details</h3>

          <div id="card-element" class="hmwevents-card-element"></div>
          <div id="card-errors" class="hmwevents-card-errors" role="alert"></div>
        </div>

        <!-- Submit Button -->
        <div class="hmwevents-form-section hmwevents-form-actions">
          <button type="submit" class="hmwevents-btn hmwevents-btn-primary" id="submit-booking">
            <span class="btn-text">Complete Booking</span>
            <span class="btn-spinner" style="display: none;">Processing...</span>
          </button>
        </div>

        <!-- Messages -->
        <div id="form-messages" class="hmwevents-form-messages" style="display: none;"></div>
      </form>
    </div>
<?php
    return ob_get_clean();
  }

  /**
   * Renders the registry-driven booking field sections (Your Information / Address /
   * Extra Details / Agreements). Half-width fields are paired two-per-row; full-width
   * fields each get their own row.
   */
  private function render_booking_field_sections(): void
  {
    foreach (\HMWEvents\Config\BookingFields::frontend_sections() as $section_key => $section_heading) {
      $fields = \HMWEvents\Config\BookingFields::for_frontend_section($section_key);
      if (empty($fields)) {
        continue;
      }

      // Build a flat indexed list of [key, field] pairs for look-ahead pairing.
      $entries = [];
      foreach ($fields as $key => $field) {
        $entries[] = [$key, $field];
      }
      $count = count($entries);

      echo '<div class="hmwevents-form-section">';
      echo '<h3>' . esc_html($section_heading) . '</h3>';

      for ($i = 0; $i < $count;) {
        [$key, $field] = $entries[$i];
        $width = $field['frontend_width'] ?? 'half';

        echo '<div class="hmwevents-form-row">';
        $this->render_booking_field($key, $field);
        $i++;

        // Pair two consecutive half-width fields into the same row.
        if ($width === 'half' && $i < $count
          && ($entries[$i][1]['frontend_width'] ?? 'half') === 'half'
        ) {
          $this->render_booking_field($entries[$i][0], $entries[$i][1]);
          $i++;
        }

        echo '</div>';
      }

      echo '</div>';
    }
  }

  /**
   * Renders a single booking field (label + input) inside a hmwevents-form-field div.
   * Handles text / email / tel / date inputs, textareas, radio groups, and checkboxes.
   */
  private function render_booking_field(string $key, array $field): void
  {
    $input_type  = $field['frontend_input'] ?? $field['type'];
    $required    = !empty($field['frontend_required']);
    $placeholder = $field['frontend_placeholder'] ?? '';
    $label_html  = $this->get_field_label_html($key, $field);
    $css_class   = ($field['frontend_width'] ?? 'half') === 'full'
                   ? 'hmwevents-form-field hmwevents-form-field-full'
                   : 'hmwevents-form-field';
    $req_attr    = $required ? ' required' : '';
    $req_star    = $required ? ' <span class="required">*</span>' : '';

    echo '<div class="' . $css_class . '">';

    if ($input_type === 'checkbox') {
      echo '<label>';
      echo '<input type="checkbox" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="1"' . $req_attr . '>';
      if ($required) {
        echo '<span class="required">*</span> ';
      }
      echo $label_html;
      echo '</label>';

    } elseif ($input_type === 'radio') {
      echo '<label>' . $label_html . '</label>';
      echo '<div class="hmwevents-radio-group">';
      foreach (($field['options'] ?? []) as $opt_val => $opt_label) {
        if ($opt_val === '') {
          continue;
        }
        echo '<label class="hmwevents-radio-option">';
        echo '<input type="radio" name="' . esc_attr($key) . '" value="' . esc_attr($opt_val) . '"' . $req_attr . '>';
        echo esc_html($opt_label);
        echo '</label>';
      }
      echo '</div>';

    } elseif ($input_type === 'select') {
      echo '<label for="' . esc_attr($key) . '">' . $label_html . $req_star . '</label>';
      echo '<select id="' . esc_attr($key) . '" name="' . esc_attr($key) . '"' . $req_attr . '>';
      foreach (($field['options'] ?? []) as $opt_val => $opt_label) {
        echo '<option value="' . esc_attr($opt_val) . '">' . esc_html($opt_label) . '</option>';
      }
      echo '</select>';

    } elseif ($input_type === 'textarea') {
      $rows = (int) ($field['frontend_rows'] ?? 3);
      echo '<label for="' . esc_attr($key) . '">' . $label_html . $req_star . '</label>';
      echo '<textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="' . $rows . '"'
           . ($placeholder ? ' placeholder="' . esc_attr($placeholder) . '"' : '')
           . $req_attr . '></textarea>';

    } else {
      echo '<label for="' . esc_attr($key) . '">' . $label_html . $req_star . '</label>';
      echo '<input type="' . esc_attr($input_type) . '" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '"'
           . ($placeholder ? ' placeholder="' . esc_attr($placeholder) . '"' : '')
           . $req_attr . '>';
    }

    echo '</div>';
  }

  /**
   * Returns safe HTML for a field's label in the frontend form.
   *
   * Most fields: escaped plain text (using frontend_label override or canonical label).
   * terms_accepted: inline hyperlink generated from the hmwevents_booking_terms_conditions_url filter.
   */
  private function get_field_label_html(string $key, array $field): string
  {
    if ($key === 'terms_accepted') {
      $url = apply_filters('hmwevents_booking_terms_conditions_url', '/terms-conditions/');
      return 'I have read and agree to the <a href="' . esc_url($url) . '" target="_blank">Terms &amp; Conditions</a>';
    }

    return esc_html($field['frontend_label'] ?? $field['label']);
  }
}
