<?php
/**
 * PayPal Payment Gateway.
 *
 * PayPal-specific payment gateway implementation.
 * This is a stub implementation for future PayPal integration.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Gateways;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * PayPal Payment Gateway Class.
 */
class PayPalPaymentGateway extends AbstractPaymentGateway
{
    /**
     * Get the gateway identifier.
     *
     * @return string
     */
    public function get_gateway_id()
    {
        return 'paypal';
    }

    /**
     * Get the gateway display name.
     *
     * @return string
     */
    public function get_gateway_name()
    {
        return 'PayPal';
    }

    /**
     * Check if gateway is properly configured and available.
     *
     * @return bool
     */
    public function is_available()
    {
        // TODO: Check PayPal API credentials
        return false; // Not implemented yet
    }

    /**
     * Initialize the gateway.
     *
     * @return void
     */
    public function register()
    {
        // TODO: Initialize PayPal SDK
    }

    /**
     * Process a booking with payment.
     *
     * @param array $booking_data Booking information.
     * @return array|\WP_Error
     */
    public function process_booking($booking_data)
    {
        return new \WP_Error(
            'not_implemented',
            'PayPal gateway is not yet implemented. Please use Stripe instead.'
        );
    }

    /**
     * Process payment with immediate confirmation.
     *
     * @param array $booking_data Booking data.
     * @return array|\WP_Error
     */
    public function process_payment_with_confirmation($booking_data)
    {
        return new \WP_Error(
            'not_implemented',
            'PayPal gateway is not yet implemented. Please use Stripe instead.'
        );
    }

    /**
     * Confirm a payment.
     *
     * @param string $transaction_id Gateway transaction ID.
     * @return bool|\WP_Error
     */
    public function confirm_payment($transaction_id)
    {
        return new \WP_Error(
            'not_implemented',
            'PayPal gateway is not yet implemented. Please use Stripe instead.'
        );
    }

    /**
     * Get payment status from gateway.
     *
     * @param string $transaction_id Gateway transaction ID.
     * @return array|\WP_Error
     */
    public function get_payment_status($transaction_id)
    {
        return new \WP_Error(
            'not_implemented',
            'PayPal gateway is not yet implemented. Please use Stripe instead.'
        );
    }

    /**
     * Create or get a customer in PayPal.
     *
     * @param string $email Customer email.
     * @param array  $data Additional customer data.
     * @return string|\WP_Error
     */
    public function create_or_get_customer($email, $data = [])
    {
        return new \WP_Error(
            'not_implemented',
            'PayPal gateway is not yet implemented. Please use Stripe instead.'
        );
    }

    /**
     * Refund a payment.
     *
     * @param string $transaction_id Gateway transaction ID.
     * @param float  $amount Amount to refund (null for full refund).
     * @param string $reason Refund reason.
     * @return array|\WP_Error
     */
    public function refund_payment($transaction_id, $amount = null, $reason = '')
    {
        return new \WP_Error(
            'not_implemented',
            'PayPal gateway is not yet implemented. Please use Stripe instead.'
        );
    }

    /**
     * Get supported currencies.
     *
     * @return array
     */
    public function get_supported_currencies()
    {
        return ['AUD', 'USD', 'EUR', 'GBP', 'CAD'];
    }

    /**
     * Verify webhook signature.
     *
     * @param string $payload Webhook payload.
     * @param string $signature Webhook signature.
     * @return bool|\WP_Error
     */
    public function verify_webhook_signature($payload, $signature)
    {
        return new \WP_Error(
            'not_implemented',
            'PayPal gateway is not yet implemented. Please use Stripe instead.'
        );
    }

    /**
     * Get the publishable/public key for frontend use.
     *
     * @return string
     */
    public function get_publishable_key()
    {
        // PayPal uses client_id instead of publishable key
        // Will be implemented when PayPal integration is complete
        return '';
    }

    /**
     * Get the gateway client instance.
     *
     * @return object|\WP_Error
     */
    public function get_gateway_client()
    {
        return new \WP_Error(
            'not_implemented',
            'PayPal gateway is not yet implemented. Please use Stripe instead.'
        );
    }

    /**
     * Get diagnostic information about the gateway configuration.
     *
     * @return array
     */
    public function get_key_info()
    {
        return array_merge(parent::get_key_info(), [
            'status' => 'not_implemented',
        ]);
    }
}
