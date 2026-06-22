<?php
/**
 * Payment Gateway Service.
 *
 * Facade for payment gateway operations using the Factory/Strategy pattern.
 * Delegates to specific gateway implementations (Stripe, PayPal, etc.).
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services;

use HMWEvents\Factory\PaymentGatewayFactory;
use HMWEvents\Interfaces\PaymentGatewayInterface;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Payment Gateway Class.
 *
 * This class acts as a facade for the payment gateway system.
 * It maintains backward compatibility while delegating to the new
 * factory/strategy pattern implementation.
 */
class PaymentGateway
{
    /**
     * Gateway instance.
     *
     * @var PaymentGatewayInterface|null
     */
    private $gateway;

    /**
     * Gateway identifier.
     *
     * @var string
     */
    private $gateway_id;

    /**
     * Educator ID for multi-tenant support.
     *
     * @var int|null
     */
    private $educator_id;

    /**
     * Constructor.
     *
     * @param string|null $gateway_id Gateway to use (null for default).
     * @param int|null    $educator_id Educator ID for multi-tenant credentials.
     */
    public function __construct($gateway_id = null, $educator_id = null)
    {
        $this->gateway_id = $gateway_id;
        $this->educator_id = $educator_id;
    }

    /**
     * Initialize the service.
     *
     * @since 1.0.0
     */
    public function register()
    {
        $gateway_result = PaymentGatewayFactory::create($this->gateway_id, $this->educator_id);

        if (is_wp_error($gateway_result)) {
            error_log('HMWEvents Payment Gateway Error: ' . $gateway_result->get_error_message());
            // Fall back to null - methods will handle this
            $this->gateway = null;
        } else {
            $this->gateway = $gateway_result;
        }
    }

    /**
     * Get the active gateway instance.
     *
     * @return PaymentGatewayInterface|\WP_Error
     */
    private function get_gateway()
    {
        if (!$this->gateway) {
            $this->register();
        }

        if (!$this->gateway || is_wp_error($this->gateway)) {
            return new \WP_Error('gateway_not_initialized', 'Payment gateway could not be initialized.');
        }

        return $this->gateway;
    }

    /**
     * Process a booking with payment.
     *
     * @since 1.0.0
     * @param array $booking_data Booking information.
     * @return array|\WP_Error Result with booking and payment info.
     */
    public function process_booking($booking_data)
    {
        $gateway = $this->get_gateway();

        if (is_wp_error($gateway)) {
            return $gateway;
        }

        return $gateway->process_booking($booking_data);
    }

  /**
   * Process payment with immediate confirmation (single-step).
   *
   * @param array $booking_data Booking data including payment_method_id.
   * @return array|WP_Error Result with booking details.
   */
  public function process_payment_with_confirmation($booking_data)
  {
        $gateway = $this->get_gateway();

        if (is_wp_error($gateway)) {
            return $gateway;
        }

        return $gateway->process_payment_with_confirmation($booking_data);
  }

    /**
     * Confirm payment and update booking status.
     *
     * @since 1.0.0
     * @param string $payment_intent_id Stripe payment intent ID.
     * @return bool|\WP_Error
     */
    public function confirm_payment($payment_intent_id)
    {
        $gateway = $this->get_gateway();

        if (is_wp_error($gateway)) {
            return $gateway;
        }

        return $gateway->confirm_payment($payment_intent_id);
    }

    // Legacy helper methods - now handled by gateway implementations
    // Kept for backward compatibility with external code that might call them directly

    /**
     * Generate recovery token for failed payment.
     *
     * @since 1.0.0
     * @param int $booking_group_id Booking group ID.
     * @return string Recovery token.
     */
    public function generate_recovery_token($booking_group_id)
    {
        global $wpdb;

        // Generate secure token
        $token = wp_generate_password(32, false);
        
        // Set expiration to 24 hours from now
        $expires = current_time('mysql', true); // GMT time
        $expires_timestamp = strtotime($expires) + (24 * HOUR_IN_SECONDS);
        $expires = gmdate('Y-m-d H:i:s', $expires_timestamp);

        // Store token and expiration in booking group metadata
        $metadata = $wpdb->get_var($wpdb->prepare(
            "SELECT metadata FROM {$wpdb->prefix}educator_booking_groups WHERE id = %d",
            $booking_group_id
        ));

        $metadata_array = $metadata ? json_decode($metadata, true) : [];
        $metadata_array['recovery_token'] = $token;
        $metadata_array['recovery_token_expires'] = $expires;

        $wpdb->update(
            $wpdb->prefix . 'educator_booking_groups',
            ['metadata' => json_encode($metadata_array)],
            ['id' => $booking_group_id],
            ['%s'],
            ['%d']
        );

        return $token;
    }

    /**
     * Get recovery URL for a token.
     *
     * @since 1.0.0
     * @param string $token Recovery token.
     * @return string Recovery URL.
     */
    public function get_recovery_url($token)
    {
        // TODO: Update this to actual page URL when payment resume page is created
        return add_query_arg('token', $token, home_url('/payment-resume'));
    }

    /**
     * Send recovery email for failed payment.
     *
     * @since 1.0.0
     * @param int $booking_group_id Booking group ID.
     * @return bool Whether email was sent successfully.
     */
    public function send_recovery_email($booking_group_id)
    {
        global $wpdb;

        // Get booking group details
        $booking_group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}educator_booking_groups WHERE id = %d",
            $booking_group_id
        ));

        if (!$booking_group) {
            return false;
        }

        // Get first booking in group for course details
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, c.post_title as course_name 
            FROM {$wpdb->prefix}educator_bookings b
            LEFT JOIN {$wpdb->prefix}posts c ON b.course_post_id = c.ID
            WHERE b.booking_group_id = %d
            LIMIT 1",
            $booking_group_id
        ));

        if (!$booking) {
            return false;
        }

        // Get customer details
        $customer_email = get_post_meta($booking_group->customer_post_id, 'customer_email', true);
        $customer_name = get_the_title($booking_group->customer_post_id);

        if (!$customer_email) {
            return false;
        }

        // Generate recovery token
        $token = $this->generate_recovery_token($booking_group_id);
        $recovery_url = $this->get_recovery_url($token);

        // Email subject
        $subject = sprintf('[%s] Complete Your Booking - Payment Required', get_bloginfo('name'));

        // Email body (HTML)
        $message = $this->get_recovery_email_template([
            'customer_name' => $customer_name,
            'booking_reference' => $booking_group->booking_reference,
            'course_name' => $booking->course_name,
            'amount' => $booking_group->total_amount,
            'recovery_url' => $recovery_url,
            'site_name' => get_bloginfo('name'),
        ]);

        // Email headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', get_bloginfo('name'), get_option('admin_email')),
        ];

        // Send email
        return wp_mail($customer_email, $subject, $message, $headers);
    }

    /**
     * Get recovery email template.
     *
     * @since 1.0.0
     * @param array $data Email template data.
     * @return string HTML email content.
     */
    private function get_recovery_email_template($data)
    {
        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background-color: #f8f9fa; border-radius: 10px; padding: 30px; margin-bottom: 20px;">
                <h2 style="color: #2c3e50; margin-top: 0;">Complete Your Booking</h2>
                
                <p>Hi {customer_name},</p>
                
                <p>We noticed that your payment for the following booking was not completed:</p>
                
                <div style="background-color: #fff; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0;">
                    <p style="margin: 5px 0;"><strong>Booking Reference:</strong> {booking_reference}</p>
                    <p style="margin: 5px 0;"><strong>Course:</strong> {course_name}</p>
                    <p style="margin: 5px 0;"><strong>Amount:</strong> ${amount}</p>
                </div>
                
                <p><strong style="color: #e74c3c;">Important:</strong> Your booking will be automatically cancelled if payment is not completed within <strong>24 hours</strong>.</p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="{recovery_url}" style="background-color: #3498db; color: #fff; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Complete Payment Now</a>
                </div>
                
                <p style="color: #7f8c8d; font-size: 14px;">If you did not make this booking or have any questions, please contact us immediately.</p>
            </div>
            
            <div style="text-align: center; color: #95a5a6; font-size: 12px;">
                <p>&copy; ' . date('Y') . ' {site_name}. All rights reserved.</p>
            </div>
        </body>
        </html>
        ';

        // Replace placeholders
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', esc_html($value), $template);
        }

        return $template;
    }
}
