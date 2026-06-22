<?php
/**
 * Payment Gateway Factory.
 *
 * Creates payment gateway instances based on configuration.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Factory;

use HMWEvents\Interfaces\PaymentGatewayInterface;
use HMWEvents\Services\Gateways\StripePaymentGateway;
use HMWEvents\Services\Gateways\PayPalPaymentGateway;
use HMWEvents\Helpers\ConfigHelper;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Payment Gateway Factory Class.
 */
class PaymentGatewayFactory
{
    /**
     * Registered gateway classes.
     *
     * @var array
     */
    private static $gateways = [
        'stripe' => StripePaymentGateway::class,
        'paypal' => PayPalPaymentGateway::class,
    ];

    /**
     * Gateway instances cache.
     *
     * @var array
     */
    private static $instances = [];

    /**
     * Create a payment gateway instance.
     *
     * @param string|null $gateway_id Gateway identifier (stripe, paypal, etc.).
     *                                 If null, uses default from settings.
     * @param int|null    $educator_id Optional educator ID for multi-tenant credentials.
     * @return PaymentGatewayInterface|\WP_Error Gateway instance or error.
     */
    public static function create($gateway_id = null, $educator_id = null)
    {
        // Get default gateway if not specified
        if ($gateway_id === null) {
            $gateway_id = self::get_default_gateway();
        }

        // Normalize gateway ID
        $gateway_id = strtolower($gateway_id);

        // If educator is specified, check their preferred gateway
        if ($educator_id !== null) {
            $educator_gateway = get_user_meta($educator_id, 'educator_payment_type', true);
            if (!empty($educator_gateway) && isset(self::$gateways[$educator_gateway])) {
                $gateway_id = $educator_gateway;
            }
        }

        // Check if gateway is registered
        if (!isset(self::$gateways[$gateway_id])) {
            return new \WP_Error(
                'invalid_gateway',
                sprintf('Payment gateway "%s" is not registered.', $gateway_id)
            );
        }

        // Create cache key including educator ID
        $cache_key = $gateway_id . ($educator_id ? '_edu_' . $educator_id : '');

        // Return cached instance if exists
        if (isset(self::$instances[$cache_key])) {
            return self::$instances[$cache_key];
        }

        // Create new instance
        $gateway_class = self::$gateways[$gateway_id];

        if (!class_exists($gateway_class)) {
            return new \WP_Error(
                'gateway_not_found',
                sprintf('Payment gateway class "%s" not found.', $gateway_class)
            );
        }

        // Instantiate with educator ID if supported
        $reflection = new \ReflectionClass($gateway_class);
        $constructor = $reflection->getConstructor();
        
        if ($constructor && $constructor->getNumberOfParameters() > 0) {
            $gateway = new $gateway_class($educator_id);
        } else {
            $gateway = new $gateway_class();
            // Set educator ID if method exists
            if (method_exists($gateway, 'set_educator_id')) {
                $gateway->set_educator_id($educator_id);
            }
        }

        // Verify interface implementation
        if (!$gateway instanceof PaymentGatewayInterface) {
            return new \WP_Error(
                'invalid_gateway_class',
                sprintf('Gateway class "%s" must implement PaymentGatewayInterface.', $gateway_class)
            );
        }

        // Check if gateway is available
        if (!$gateway->is_available()) {
            return new \WP_Error(
                'gateway_unavailable',
                sprintf(
                    'Payment gateway "%s" is not properly configured or unavailable.%s',
                    $gateway->get_gateway_name(),
                    $educator_id ? ' (Educator ID: ' . $educator_id . ')' : ''
                )
            );
        }

        // Initialize the gateway
        $gateway->register();

        // Cache the instance
        self::$instances[$cache_key] = $gateway;

        return $gateway;
    }

    /**
     * Register a new payment gateway.
     *
     * @param string $gateway_id Gateway identifier.
     * @param string $gateway_class Fully qualified class name.
     * @return bool True if registered successfully.
     */
    public static function register_gateway($gateway_id, $gateway_class)
    {
        $gateway_id = strtolower($gateway_id);

        if (isset(self::$gateways[$gateway_id])) {
            return false; // Already registered
        }

        self::$gateways[$gateway_id] = $gateway_class;

        return true;
    }

    /**
     * Get all registered gateways.
     *
     * @return array Array of gateway IDs and their class names.
     */
    public static function get_registered_gateways()
    {
        return self::$gateways;
    }

    /**
     * Get all available (properly configured) gateways.
     *
     * @return array Array of available gateway instances.
     */
    public static function get_available_gateways()
    {
        $available = [];

        foreach (self::$gateways as $gateway_id => $gateway_class) {
            try {
                if (class_exists($gateway_class)) {
                    $gateway = new $gateway_class();
                    if ($gateway instanceof PaymentGatewayInterface && $gateway->is_available()) {
                        $available[$gateway_id] = $gateway;
                    }
                }
            } catch (\Exception $e) {
                error_log(sprintf(
                    'HMWEvents: Error checking gateway availability for %s: %s',
                    $gateway_id,
                    $e->getMessage()
                ));
            }
        }

        return $available;
    }

    /**
     * Get default payment gateway from settings.
     *
     * @return string Default gateway ID.
     */
    public static function get_default_gateway()
    {
        // Get from options, default to 'stripe'
        return ConfigHelper::get_option('hmwevents_default_payment_gateway', 'stripe');
    }

    /**
     * Set default payment gateway.
     *
     * @param string $gateway_id Gateway identifier.
     * @return bool True if set successfully.
     */
    public static function set_default_gateway($gateway_id)
    {
        $gateway_id = strtolower($gateway_id);

        if (!isset(self::$gateways[$gateway_id])) {
            return false;
        }

        return ConfigHelper::update_option('hmwevents_default_payment_gateway', $gateway_id);
    }

    /**
     * Clear gateway instances cache.
     *
     * @return void
     */
    public static function clear_cache()
    {
        self::$instances = [];
    }

    /**
     * Get a specific gateway instance (creates if not cached).
     *
     * @param string $gateway_id Gateway identifier.
     * @return PaymentGatewayInterface|\WP_Error
     */
    public static function get($gateway_id)
    {
        return self::create($gateway_id);
    }
}
