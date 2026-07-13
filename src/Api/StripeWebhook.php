<?php
/**
 * Stripe Webhook Handler.
 *
 * Processes Stripe webhook events.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Api;

use HMWEvents\Services\StripeService;
use HMWEvents\Services\PaymentGateway;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Stripe Webhook Class.
 */
class StripeWebhook
{
    /**
     * Stripe service instance.
     *
     * @var StripeService
     */
    private $stripe;

    /**
     * Payment gateway instance.
     *
     * @var PaymentGateway
     */
    private $gateway;

    /**
     * Initialize the webhook handler.
     *
     * @since 1.0.0
     */
    public function register()
    {
        $this->stripe = new StripeService();
        $this->stripe->init_stripe();
        
        $this->gateway = new PaymentGateway();
        $this->gateway->register();

        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register webhook route.
     *
     * @since 1.0.0
     */
    public function register_routes()
    {
        register_rest_route('hmwevents/v1', '/webhook/stripe', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handle Stripe webhook.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function handle_webhook(\WP_REST_Request $request)
    {
        $payload = $request->get_body();
        $signature = $request->get_header('stripe-signature');

        // Construct and verify event
        $event = $this->stripe->construct_webhook_event($payload, $signature);

        if (is_wp_error($event)) {
            error_log('HMWEvents Webhook Error: ' . $event->get_error_message());
            return new \WP_REST_Response(['error' => 'Webhook verification failed'], 400);
        }

        // Log event
        error_log('HMWEvents Stripe Webhook: ' . $event->type);

        // Handle event types
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->handle_payment_succeeded($event->data->object);
                break;

            case 'payment_intent.payment_failed':
                $this->handle_payment_failed($event->data->object);
                break;

            case 'charge.refunded':
                $this->handle_refund($event->data->object);
                break;

            case 'charge.dispute.created':
                $this->handle_dispute_created($event->data->object);
                break;

            default:
                error_log('HMWEvents: Unhandled webhook event type: ' . $event->type);
        }

        return new \WP_REST_Response(['received' => true], 200);
    }

    /**
     * Handle successful payment.
     *
     * @since 1.0.0
     * @param object $payment_intent Stripe payment intent object.
     */
    private function handle_payment_succeeded($payment_intent)
    {
        $this->gateway->confirm_payment($payment_intent->id);
        error_log('HMWEvents: Payment succeeded for ' . $payment_intent->id);
    }

    /**
     * Handle failed payment.
     *
     * @since 1.0.0
     * @param object $payment_intent Stripe payment intent object.
     */
    private function handle_payment_failed($payment_intent)
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'hmwevents_payment_transactions',
            [
                'status' => 'failed',
                'error_message' => $payment_intent->last_payment_error->message ?? 'Payment failed',
            ],
            ['gateway_transaction_id' => $payment_intent->id],
            ['%s', '%s'],
            ['%s']
        );

        // Update bookings to failed
        $transaction = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}hmwevents_payment_transactions
            WHERE gateway_transaction_id = %s
        ", $payment_intent->id));

        if ($transaction) {
            $wpdb->update(
                $wpdb->prefix . 'hmwevents_bookings',
                ['payment_status' => 'failed'],
                ['booking_group_id' => $transaction->booking_group_id],
                ['%s'],
                ['%d']
            );

            // Send recovery email to customer
            $email_sent = $this->gateway->send_recovery_email($transaction->booking_group_id);
            
            if ($email_sent) {
                error_log('HMWEvents: Recovery email sent for booking group ' . $transaction->booking_group_id);
            } else {
                error_log('HMWEvents: Failed to send recovery email for booking group ' . $transaction->booking_group_id);
            }
        }

        error_log('HMWEvents: Payment failed for ' . $payment_intent->id);
    }

    /**
     * Handle refund.
     *
     * @since 1.0.0
     * @param object $charge Stripe charge object.
     */
    private function handle_refund($charge)
    {
        global $wpdb;

        // Find the payment transaction
        $transaction = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}hmwevents_payment_transactions
            WHERE gateway_transaction_id = %s
        ", $charge->payment_intent));

        if (!$transaction) {
            error_log('HMWEvents: Could not find transaction for refund: ' . $charge->id);
            return;
        }

        // Update transaction
        $wpdb->update(
            $wpdb->prefix . 'hmwevents_payment_transactions',
            [
                'status' => 'refunded',
                'refund_id' => $charge->refunds->data[0]->id ?? null,
                'refunded_amount' => $charge->amount_refunded / 100,
                'refunded_at' => current_time('mysql'),
            ],
            ['id' => $transaction->id],
            ['%s', '%s', '%f', '%s'],
            ['%d']
        );

        // Update bookings
        $wpdb->update(
            $wpdb->prefix . 'hmwevents_bookings',
            [
                'payment_status' => 'refunded',
                'status' => 'cancelled',
                'cancelled_at' => current_time('mysql'),
            ],
            ['booking_group_id' => $transaction->booking_group_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        error_log('HMWEvents: Refund processed for charge: ' . $charge->id);
    }

    /**
     * Handle dispute created.
     *
     * @since 1.0.0
     * @param object $dispute Stripe dispute object.
     */
    private function handle_dispute_created($dispute)
    {
        global $wpdb;

        // Log dispute
        $wpdb->insert(
            $wpdb->prefix . 'hmwevents_booking_history',
            [
                'booking_id' => 0, // We'd need to look this up
                'old_status' => null,
                'new_status' => 'disputed',
                'notes' => sprintf('Dispute created: %s - Amount: $%s', $dispute->id, $dispute->amount / 100),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        // TODO: Send notification email to admin

        error_log('HMWEvents: Dispute created: ' . $dispute->id);
    }
}
