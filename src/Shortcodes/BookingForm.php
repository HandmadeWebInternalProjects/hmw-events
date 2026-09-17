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

use HMWEvents\Helpers\EventFieldConfig;
use HMWEvents\Registry\RegistrationFieldRegistry;
use HMWEvents\Services\EventDataService;

defined('ABSPATH') || die('Don\'t run this file directly!');

class BookingForm
{
    private ?EventDataService $event_data_service = null;

    private function event_data(): EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new EventDataService();
        }
        return $this->event_data_service;
    }

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
      'event_id'  => 0,
    ], $atts);

    $course_id = (int) ($atts['event_id'] ?: $atts['course_id']);

    if (!$course_id) {
      return '<p class="hmwevents-error">Event ID is required.</p>';
    }

    $course = get_post($course_id);
    if (!$course || $course->post_type !== 'hmw_event') {
      return '<p class="hmwevents-error">Invalid event.</p>';
    }

    if (!$this->event_data()->bookings_enabled($course_id)) {
      return '';
    }

    // Check cutoff date
    $cutoff = $this->event_data()->get_booking_cutoff($course_id);
    if ($cutoff) {
      $sydney_timezone = new \DateTimeZone('Australia/Sydney');
      $cutoff_datetime = new \DateTime($cutoff, $sydney_timezone);
      $current_datetime = new \DateTime('now', $sydney_timezone);
      if ($current_datetime >= $cutoff_datetime) {
        return '<p class="hmwevents-error">Sorry, bookings for this event have closed.</p>';
      }
    }

    // if external booking link exists, show that instead
    $is_external = function_exists('get_field') ? get_field('_event_is_external', $course_id) : false;
    $external_link = get_field('_event_external_url', $course_id);
    if ($is_external && $external_link) {
      return '<p class="hmwevents-info">To book this course, please click the button below to visit this Educator\'s external booking website</p><p><a href="' . esc_url($external_link) . '" target="_blank" class="button-atom button-atom--primary bde-button__button"><span class="button-atom__text">Book Now</span></a></p>';
    }

    if (\HMWEvents\PostTypes\Event::is_invitation_only($course_id)) {
      $token = $_GET['token'] ?? '';
      $token_service = new \HMWEvents\Services\InvitationTokenService();
      $validation = $token_service->validate($token, $course_id);
      if (is_wp_error($validation)) {
        return '<p class="hmwevents-notice">' . esc_html($validation->get_error_message()) . '</p>';
      }
    } else {
      if (!\HMWEvents\Helpers\EventHelper::check_course_availability($course_id)) {
        $renderer = new \HMWEvents\Services\WaitlistFormRenderer();
        return $renderer->render($course_id);
      }
    }

    $capacity_service = new \HMWEvents\Services\CapacityService();
    if ($capacity_service->get_capacity($course_id) > 0 && $capacity_service->get_remaining_places($course_id) <= 0) {
      $renderer = new \HMWEvents\Services\WaitlistFormRenderer();
      return $renderer->render($course_id);
    }

    $v3_config = $this->get_v3_config($course_id);
    if ($v3_config) {
      $renderer = new \HMWEvents\Services\RegistrationFormRenderer();
      return $renderer->render_form(['event_id' => (string) $course_id, 'attendance_type' => 'individual']);
    }

    // Get pricing
    $full_cost = $this->event_data()->get_price($course_id);
    $deposit_cost = $this->event_data()->get_deposit($course_id);
    $surcharge = $this->event_data()->calculate_surcharge($course_id, floatval($full_cost));
    $currency = $this->event_data()->get_currency($course_id);
    $currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);
    $organizer_id = $course->post_author;

    $display_full = floatval($full_cost) + $surcharge;
    $display_deposit = floatval($deposit_cost) + $this->event_data()->calculate_surcharge($course_id, floatval($deposit_cost));

    $deposits_enabled = apply_filters('hmwevents_deposit_enabled', false);
    $deposit_cost = $deposits_enabled ? $deposit_cost : 0;

    $is_private_access = \HMWEvents\PostTypes\Event::is_invitation_only($course_id);
    $show_net_terms = $this->event_data()->get_allow_net_terms($course_id) && $is_private_access;

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

    // Get effective fields for this event (template snapshot aware)
    $fields = $this->get_effective_fields_for_event($course_id);

    // Localize script with data
    wp_localize_script('hmwevents-booking-form', 'cmsBookingData', [
      'courseId' => $course_id,
      'organizerId' => $organizer_id,
      'restUrl' => rest_url('hmwevents/v1'),
      'nonce' => wp_create_nonce('wp_rest'),
      'fullCost' => floatval($full_cost),
      'depositCost' => floatval($deposit_cost),
      'surcharge' => $surcharge,
      'currency' => $currency,
      'currencySymbol' => $currency_symbol,
      'recaptchaSiteKey' => $recaptcha_site_key,
      'bookingFields' => $this->build_js_field_list($fields),
    ]);

    ob_start();
?>
    <div class="hmwevents-booking-form-wrapper" data-course-id="<?php echo esc_attr($course_id); ?>">
      <form id="hmwevents-booking-form" class="hmwevents-booking-form">
        <?php if (!empty($_GET['token'])): ?>
          <input type="hidden" name="token" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_GET['token']))); ?>">
        <?php endif; ?>

        <!-- Registry-driven booking fields -->
        <?php $this->render_booking_field_sections($fields); ?>

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

          <?php if ($deposit_cost && $deposits_enabled): ?>
            <div class="hmwevents-payment-options">
              <label class="hmwevents-payment-option" data-type="full">
                <input type="radio" name="payment_type" value="full" id="payment_full" checked>
                <div class="hmwevents-payment-option-content">
                  <div class="hmwevents-payment-option-header">
                    <strong>Full Payment</strong>
                    <span class="hmwevents-payment-price" data-original="<?php echo esc_attr($full_cost); ?>">
                      <?php echo esc_html($currency_symbol); ?><?php echo number_format($display_full, 2); ?>
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
                      <?php echo esc_html($currency_symbol); ?><?php echo number_format($display_deposit, 2); ?>
                    </span>
                  </div>
                  <p>Pay a deposit now and the remainder later.</p>
                </div>
              </label>

              <?php if ($show_net_terms): ?>
              <label class="hmwevents-payment-option" data-type="net_terms">
                <input type="radio" name="payment_type" value="net_terms" id="payment_net_terms">
                <div class="hmwevents-payment-option-content">
                  <div class="hmwevents-payment-option-header">
                    <strong>Pay by Invoice</strong>
                    <span class="hmwevents-payment-price">—</span>
                  </div>
                  <p>An invoice will be sent to you after registration.</p>
                </div>
              </label>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <?php if ($show_net_terms): ?>
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
              <label class="hmwevents-payment-option" data-type="net_terms">
                <input type="radio" name="payment_type" value="net_terms" id="payment_net_terms">
                <div class="hmwevents-payment-option-content">
                  <div class="hmwevents-payment-option-header">
                    <strong>Pay by Invoice</strong>
                    <span class="hmwevents-payment-price">—</span>
                  </div>
                  <p>An invoice will be sent to you after registration.</p>
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
   * Renders registry-driven booking field sections, filtered by template config.
   */
  private function render_booking_field_sections(array $fields): void
  {
    $sections = RegistrationFieldRegistry::get_sections();
    $grouped = $this->group_fields_by_section($fields);

    foreach ($sections as $section_key => $section_heading) {
      if (empty($grouped[$section_key])) {
        continue;
      }

      $section_fields = $grouped[$section_key];

      echo '<div class="hmwevents-form-section">';
      echo '<h3>' . esc_html($section_heading) . '</h3>';

      $entries = [];
      foreach ($section_fields as $key => $field) {
        $entries[] = [$key, $field];
      }
      $count = count($entries);

      for ($i = 0; $i < $count;) {
        [$key, $field] = $entries[$i];
        $width = $field['width'] ?? 'half';

        echo '<div class="hmwevents-form-row">';
        $this->render_booking_field($key, $field);
        $i++;

        if ($width === 'half' && $i < $count
          && ($entries[$i][1]['width'] ?? 'half') === 'half'
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
   * Renders a single booking field using RegistrationFieldRegistry structure.
   */
  private function render_booking_field(string $key, array $field): void
  {
    $input_type  = $field['type'];
    $required    = !empty($field['required']);
    $placeholder = $field['placeholder'] ?? '';
    $label       = $field['label'] ?? $key;
    $css_class   = ($field['width'] ?? 'half') === 'full'
                   ? 'hmwevents-form-field hmwevents-form-field-full'
                   : 'hmwevents-form-field';
    $req_attr    = $required ? ' required' : '';
    $req_star    = $required ? ' <span class="required">*</span>' : '';

    echo '<div class="' . $css_class . '">';

    if ($input_type === 'checkbox') {
      if ($key === 'terms_accepted') {
        $url = apply_filters('hmwevents_booking_terms_conditions_url', '/terms-conditions/');
        $label = __('I have read and agree to the', 'hmw-events')
          . ' <a href="' . esc_url($url) . '" target="_blank">'
          . __('Terms &amp; Conditions', 'hmw-events') . '</a>';
      }

      echo '<label>';
      echo '<input type="checkbox" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="1"' . $req_attr . '>';
      if ($required) {
        echo '<span class="required">*</span> ';
      }
      // terms_accepted contains an anchor tag — allow the HTML
      if ($key === 'terms_accepted') {
        echo $label;
      } else {
        echo esc_html($label);
      }
      echo '</label>';

    } elseif ($input_type === 'select') {
      echo '<label for="' . esc_attr($key) . '">' . esc_html($label) . $req_star . '</label>';
      echo '<select id="' . esc_attr($key) . '" name="' . esc_attr($key) . '"' . $req_attr . '>';
      foreach (($field['options'] ?? []) as $opt_val => $opt_label) {
        echo '<option value="' . esc_attr((string) $opt_val) . '">' . esc_html($opt_label) . '</option>';
      }
      echo '</select>';

    } elseif ($input_type === 'textarea') {
      $rows = (int) ($field['rows'] ?? 3);
      echo '<label for="' . esc_attr($key) . '">' . esc_html($label) . $req_star . '</label>';
      echo '<textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="' . $rows . '"'
           . ($placeholder ? ' placeholder="' . esc_attr($placeholder) . '"' : '')
           . $req_attr . '></textarea>';

    } elseif ($input_type === 'file') {
      echo '<label for="' . esc_attr($key) . '">' . esc_html($label) . $req_star . '</label>';
      echo '<input type="file" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '"'
           . (!empty($field['allowed_types']) ? ' accept="' . esc_attr(implode(',', array_map(fn($t) => '.' . $t, $field['allowed_types']))) . '"' : '')
           . $req_attr . '>';

    } else {
      echo '<label for="' . esc_attr($key) . '">' . esc_html($label) . $req_star . '</label>';
      echo '<input type="' . esc_attr($input_type) . '" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '"'
           . ($placeholder ? ' placeholder="' . esc_attr($placeholder) . '"' : '')
           . $req_attr . '>';
    }

    echo '</div>';
  }

  /**
   * Get effective fields for a given event, applying template registration_fields config.
   *
   * @return array<string, array>
   */
  private function get_effective_fields_for_event(int $event_id): array
  {
    $all = RegistrationFieldRegistry::all();

    // Remove file upload fields from frontend Stripe form (handled differently)
    foreach ($all as $key => $field) {
      if (($field['type'] ?? '') === 'file') {
        unset($all[$key]);
      }
    }

        $template_override = get_post_meta($event_id, '_event_template_override', true);
        $template_registration = null;
        if (is_array($template_override) && isset($template_override['registration_fields']) && is_array($template_override['registration_fields'])) {
            $template_registration = $template_override['registration_fields'];
        }

        $snapshot = EventFieldConfig::read($event_id);
        $registration_config = null;
        if ($snapshot !== null && isset($snapshot['registration_fields']) && is_array($snapshot['registration_fields'])) {
            $registration_config = $snapshot['registration_fields'];
        }

        $snapshot_defaults = EventFieldConfig::read($event_id, '_event_default_values');
        $field_overrides = [];
        if (is_array($snapshot_defaults) && isset($snapshot_defaults['registration']['field_overrides'])) {
            $field_overrides = $snapshot_defaults['registration']['field_overrides'];
        }

        $active_config = $template_registration ?? $registration_config;
        $is_template = $template_registration !== null;

        if (!$active_config) {
            return $all;
        }

        $hidden   = (array) ($active_config['hidden'] ?? []);
        $required = (array) ($active_config['required'] ?? []);
        $optional = (array) ($active_config['optional'] ?? []);

        $has_config = !empty($hidden) || !empty($required) || !empty($optional);
        if (!$has_config) {
            return $all;
        }

        $hidden_set   = array_flip($hidden);
        $required_set = array_flip($required);
        $optional_set = array_flip($optional);

        foreach (array_keys($all) as $key) {
            if (isset($hidden_set[$key])) {
                unset($all[$key]);
            }
        }

        foreach (array_keys($all) as $key) {
            if (!isset($required_set[$key]) && !isset($optional_set[$key])) {
                unset($all[$key]);
            }
        }

        foreach ($all as $key => &$field) {
            if (isset($required_set[$key])) {
                $field['required'] = true;
            } elseif (isset($optional_set[$key])) {
                $field['required'] = false;
            }

            if (isset($field_overrides[$key]) && is_array($field_overrides[$key])) {
                if (!empty($field_overrides[$key]['label'])) {
                    $field['label'] = $field_overrides[$key]['label'];
                }
                if (array_key_exists('placeholder', $field_overrides[$key])) {
                    $field['placeholder'] = $field_overrides[$key]['placeholder'];
                }
                if (!empty($field_overrides[$key]['width'])) {
                    $field['width'] = $field_overrides[$key]['width'];
                }
                if (!empty($field_overrides[$key]['section'])) {
                    $field['section'] = $field_overrides[$key]['section'];
                }
            }

            if ($is_template && isset($active_config['field_overrides'][$key]) && is_array($active_config['field_overrides'][$key])) {
                $tpl_override = $active_config['field_overrides'][$key];
                if (!empty($tpl_override['label'])) {
                    $field['label'] = $tpl_override['label'];
                }
                if (array_key_exists('placeholder', $tpl_override)) {
                    $field['placeholder'] = $tpl_override['placeholder'];
                }
                if (!empty($tpl_override['width'])) {
                    $field['width'] = $tpl_override['width'];
                }
                if (!empty($tpl_override['section'])) {
                    $field['section'] = $tpl_override['section'];
                }
            }
        }
        unset($field);

        if ($is_template) {
            $order = $active_config['order'] ?? [];
            if (is_array($order) && !empty($order)) {
                $ordered = [];
                foreach ($order as $field_key) {
                    $field_key = sanitize_key((string) $field_key);
                    if (isset($all[$field_key])) {
                        $ordered[$field_key] = $all[$field_key];
                        unset($all[$field_key]);
                    }
                }
                foreach ($all as $field_key => $field) {
                    $ordered[$field_key] = $field;
                }
                $all = $ordered;
            }
        }

        return $all;
  }

  /**
   * Group fields by their section key.
   *
   * @return array<string, array<string, array>>
   */
  private function group_fields_by_section(array $fields): array
  {
    $grouped = [];
    foreach ($fields as $key => $field) {
      $section = $field['section'] ?? 'additional';
      if (!isset($grouped[$section])) {
        $grouped[$section] = [];
      }
      $grouped[$section][$key] = $field;
    }

    return $grouped;
  }

  /**
   * Build a flat field list for the JS booking handler.
   *
   * @param array<string, array> $fields
   * @return array<int, array{key: string, type: string}>
   */
  private function build_js_field_list(array $fields): array
  {
    $list = [];
    foreach ($fields as $key => $field) {
      $list[] = ['key' => $key, 'type' => $field['type'] ?? 'text'];
    }

    return $list;
  }

  private function get_v3_config(int $event_id): ?array
  {
    $override = EventFieldConfig::read($event_id, '_event_template_override');
    if ($override !== null
      && isset($override['registration_fields']['sections'])
      && is_array($override['registration_fields']['sections'])
    ) {
      return $override['registration_fields'];
    }

    $config = EventFieldConfig::read($event_id);
    if ($config !== null
      && isset($config['registration_fields']['sections'])
      && is_array($config['registration_fields']['sections'])
    ) {
      return $config['registration_fields'];
    }

    return null;
  }
}
