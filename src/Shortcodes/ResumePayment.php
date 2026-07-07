<?php
/**
 * Resume Payment Shortcode.
 *
 * Allows customers to complete failed/abandoned payments via recovery token.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Shortcodes;

use HMWEvents\HMWEvents;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Resume Payment Shortcode Class.
 */
class ResumePayment
{
    /**
     * Initialize the shortcode.
     *
     * @since 1.0.0
     */
    public function register()
    {
        add_shortcode('payment_resume', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Enqueue scripts and styles for the shortcode.
     *
     * @since 1.0.0
     */
    public function enqueue_scripts()
    {
        // Only enqueue on pages with the shortcode
        global $post;
        if (!is_a($post, 'WP_Post')) {
            return;
        }

        // Check regular post content first
        if (has_shortcode($post->post_content, 'payment_resume')) {
            $this->enqueue_payment_assets();
            return;
        }

        // Check Breakdance content if function exists
        if (function_exists('\Breakdance\Data\get_tree')) {
            $breakdance_tree = \Breakdance\Data\get_tree($post->ID);
            if (has_shortcode_in_breakdance_tree($breakdance_tree, 'payment_resume')) {
                $this->enqueue_payment_assets();
                return;
            }
        }
    }

   

    /**
     * Enqueue payment assets (scripts and styles).
     *
     * @since 1.0.0
     */
    private function enqueue_payment_assets()
    {

        // Enqueue Stripe.js
        wp_enqueue_script(
            'stripe-js',
            'https://js.stripe.com/v3/',
            [],
            null,
            true
        );

        // Enqueue custom payment resume script
        wp_enqueue_script(
            'hmwevents-payment-resume',
            HMWEvents::plugin_url() . '/resources/js/components/payment-resume.js',
            ['jquery', 'stripe-js'],
            HMWEvents_VERSION,
            true
        );

        // Enqueue styles
        wp_enqueue_style(
            'hmwevents-payment-resume',
            HMWEvents::plugin_url() . '/resources/css/payment-resume.css',
            [],
            HMWEvents_VERSION
        );

        // Localize script with settings
        wp_localize_script('hmwevents-payment-resume', 'cmsPaymentResume', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('hmwevents/v1/'),
            'token' => isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '',
        ]);
    }

    /**
     * Render the shortcode.
     *
     * @since 1.0.0
     * @param array $atts Shortcode attributes.
     * @return string Shortcode output.
     */
    public function render($atts)
    {
        $atts = shortcode_atts([
            'title' => 'Complete Your Booking',
            'show_summary' => 'yes',
        ], $atts, 'payment_resume');

        // Get token from URL
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

        if (empty($token)) {
            return $this->render_error('Invalid payment recovery link. Please check your email for the correct link.');
        }

        ob_start();
        ?>
        <div class="hmwevents-payment-resume-container">
            <div class="hmwevents-payment-resume-header">
                <h2><?php echo esc_html($atts['title']); ?></h2>
            </div>

            <!-- Loading State -->
            <div id="hmwevents-payment-loading" class="hmwevents-payment-section">
                <div class="hmwevents-loading-spinner"></div>
                <p>Loading your booking details...</p>
            </div>

            <!-- Error State -->
            <div id="hmwevents-payment-error" class="hmwevents-payment-section hmwevents-error" style="display: none;">
                <div class="hmwevents-error-icon">⚠️</div>
                <h3>Unable to Load Booking</h3>
                <p id="hmwevents-error-message"></p>
                <a href="<?php echo esc_url(home_url('/contact')); ?>" class="hmwevents-button hmwevents-button-secondary">
                    Contact Support
                </a>
            </div>

            <!-- Expired State -->
            <div id="hmwevents-payment-expired" class="hmwevents-payment-section hmwevents-warning" style="display: none;">
                <div class="hmwevents-warning-icon">⏰</div>
                <h3>Recovery Link Expired</h3>
                <p>This payment recovery link has expired. Recovery links are valid for 24 hours.</p>
                <p>Please contact our support team for assistance with completing your booking.</p>
                <a href="<?php echo esc_url(home_url('/contact')); ?>" class="hmwevents-button hmwevents-button-secondary">
                    Contact Support
                </a>
            </div>

            <!-- Booking Summary & Payment Form -->
            <div id="hmwevents-payment-form-container" class="hmwevents-payment-section" style="display: none;">
                <?php if ($atts['show_summary'] === 'yes') : ?>
                <div class="hmwevents-booking-summary">
                    <h3>Booking Summary</h3>
                    <div class="hmwevents-summary-grid">
                        <div class="hmwevents-summary-item">
                            <span class="hmwevents-summary-label">Booking Reference:</span>
                            <span class="hmwevents-summary-value" id="hmwevents-booking-reference">-</span>
                        </div>
                        <div class="hmwevents-summary-item">
                            <span class="hmwevents-summary-label">Course:</span>
                            <span class="hmwevents-summary-value" id="hmwevents-course-name">-</span>
                        </div>
                        <div class="hmwevents-summary-item">
                            <span class="hmwevents-summary-label">Customer:</span>
                            <span class="hmwevents-summary-value" id="hmwevents-customer-name">-</span>
                        </div>
                        <div class="hmwevents-summary-item">
                            <span class="hmwevents-summary-label">Amount:</span>
                            <span class="hmwevents-summary-value hmwevents-amount" id="hmwevents-amount">$0.00</span>
                        </div>
                        <div class="hmwevents-summary-item hmwevents-expiry-warning">
                            <span class="hmwevents-summary-label">⏰ Complete by:</span>
                            <span class="hmwevents-summary-value" id="hmwevents-expires-at">-</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="hmwevents-payment-form">
                    <h3>Complete Payment</h3>
                    <p class="hmwevents-form-description">Enter your payment details below to complete your booking.</p>

                    <form id="hmwevents-resume-payment-form">
                        <div class="hmwevents-form-group">
                            <label for="hmwevents-card-element">Card Details</label>
                            <div id="hmwevents-card-element" class="hmwevents-stripe-element">
                                <!-- Stripe Elements will be inserted here -->
                            </div>
                            <div id="hmwevents-card-errors" class="hmwevents-field-error" role="alert"></div>
                        </div>

                        <div class="hmwevents-form-actions">
                            <button type="submit" id="hmwevents-submit-payment" class="hmwevents-button hmwevents-button-primary">
                                <span class="hmwevents-button-text">Complete Payment</span>
                                <span class="hmwevents-button-spinner" style="display: none;">Processing...</span>
                            </button>
                        </div>

                        <div id="hmwevents-payment-result" class="hmwevents-payment-result" style="display: none;"></div>
                    </form>
                </div>
            </div>

            <!-- Success State -->
            <div id="hmwevents-payment-success" class="hmwevents-payment-section hmwevents-success" style="display: none;">
                <div class="hmwevents-success-icon">✓</div>
                <h3>Payment Successful!</h3>
                <p>Your booking has been confirmed. You'll receive a confirmation email shortly.</p>
                <div class="hmwevents-success-details">
                    <p><strong>Booking Number:</strong> <span id="hmwevents-success-booking-number"></span></p>
                </div>
                <a href="<?php echo esc_url(home_url()); ?>" class="hmwevents-button hmwevents-button-primary">
                    Return to Home
                </a>
            </div>

            <!-- Already Completed State -->
            <div id="hmwevents-payment-completed" class="hmwevents-payment-section hmwevents-success" style="display: none;">
                <div class="hmwevents-success-icon">✓</div>
                <h3>Payment Already Completed</h3>
                <p>This payment has already been completed. Thank you for your booking!</p>
                <p class="hmwevents-note">If you have any questions about your booking, please contact support.</p>
                <div class="hmwevents-completed-actions">
                    <a href="<?php echo esc_url(home_url()); ?>" class="hmwevents-button hmwevents-button-primary">
                        Return to Home
                    </a>
                    <a href="<?php echo esc_url(home_url('/contact')); ?>" class="hmwevents-button hmwevents-button-secondary">
                        Contact Support
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
     * @return string Error HTML.
     */
    private function render_error($message)
    {
        ob_start();
        ?>
        <div class="hmwevents-payment-resume-container">
            <div class="hmwevents-payment-section hmwevents-error">
                <div class="hmwevents-error-icon">⚠️</div>
                <h3>Invalid Link</h3>
                <p><?php echo esc_html($message); ?></p>
                <a href="<?php echo esc_url(home_url('/contact')); ?>" class="hmwevents-button hmwevents-button-secondary">
                    Contact Support
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}