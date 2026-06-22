# Multi-Tenant Payment Gateway System

## Overview

The payment gateway system supports **multi-tenant mode** where each educator can have their own payment gateway credentials. The system intelligently selects credentials based on:

1. **Test Mode**: Uses plugin-level test keys from settings
2. **Live Mode**: Uses educator-specific keys from their user meta
3. **Fallback**: Falls back to plugin settings if educator keys aren't configured

## Architecture

### Key Components

1. **PaymentGatewayFactory** - Creates gateway instances with educator context
2. **AbstractPaymentGateway** - Base class with educator ID support
3. **StripePaymentGateway** - Implements intelligent credential resolution
4. **ProcessPayment API** - Passes educator context to gateway

## Credential Resolution Flow

```
┌─────────────────────────────────────────────────────────┐
│                   Payment Request                        │
│              (includes educator_id)                      │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│         PaymentGatewayFactory::create()                  │
│    (gateway_id, educator_id)                            │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│        Check Gateway Mode                                │
│    get_option('hmwevents_stripe_mode')                        │
└────────────────────┬────────────────────────────────────┘
                     │
         ┌───────────┴───────────┐
         ▼                       ▼
    ┌─────────┐           ┌──────────┐
    │  TEST   │           │   LIVE   │
    └────┬────┘           └─────┬────┘
         │                      │
         ▼                      ▼
┌─────────────────┐    ┌─────────────────────┐
│  Plugin Test    │    │ Educator-Specific   │
│     Keys        │    │     Live Keys       │
│                 │    │ get_user_meta()     │
│ hmwevents_stripe_     │    │ educator_stripe_*   │
│   test_*        │    └──────────┬──────────┘
└─────────────────┘               │
                                  ▼
                          ┌───────────────┐
                          │  Keys Found?  │
                          └───┬───────────┘
                              │
                    ┌─────────┴─────────┐
                    ▼                   ▼
               ┌────────┐         ┌──────────┐
               │  YES   │         │    NO    │
               └────┬───┘         └─────┬────┘
                    │                   │
                    │                   ▼
                    │         ┌──────────────────┐
                    │         │  Plugin Live     │
                    │         │     Keys         │
                    │         │ hmwevents_stripe_      │
                    │         │   live_*         │
                    │         └──────────────────┘
                    │
                    ▼
         ┌────────────────────┐
         │  Initialize Gateway │
         │   with Keys         │
         └────────────────────┘
```

## Configuration

### Plugin Settings (Admin)

**Location**: Plugin Settings > Payment Gateways

```php
// Test Mode Keys (global override)
hmwevents_stripe_mode = 'test'
hmwevents_stripe_test_secret_key = 'sk_test_...'
hmwevents_stripe_test_publishable_key = 'pk_test_...'

// Live Mode Fallback Keys
hmwevents_stripe_live_secret_key = 'sk_live_...'
hmwevents_stripe_live_publishable_key = 'pk_live_...'
```

### Educator Settings (User Meta)

**Location**: Educator Profile > Payment Settings

```php
// Educator's chosen gateway
educator_payment_type = 'stripe'  // or 'paypal', 'manual'

// Stripe credentials (stored encrypted)
educator_stripe_key = 'pk_live_...'
educator_stripe_secret = 'sk_live_...' (encrypted)

// PayPal credentials (for future)
educator_paypal_client_id = '...'
educator_paypal_secret = '...' (encrypted)
```

## Usage Examples

### Frontend: Get Gateway Configuration

```javascript
// Get Stripe publishable key for educator
fetch('/wp-json/cms/v1/payment/gateway-config?educator_id=123')
  .then(res => res.json())
  .then(data => {
    const publishableKey = data.config.publishable_key;
    // Initialize Stripe.js with educator's key
    const stripe = Stripe(publishableKey);
  });
```

### Backend: Process Payment

```php
use HMWEvents\Factory\PaymentGatewayFactory;

// Create gateway for specific educator
$gateway = PaymentGatewayFactory::create('stripe', $educator_id);

// Gateway automatically uses correct credentials
$result = $gateway->process_payment_with_confirmation([
    'customer_name' => 'John Doe',
    'customer_email' => 'john@example.com',
    'course_id' => 123,
    'educator_id' => $educator_id,
    'payment_method_id' => 'pm_xxxx',
]);
```

### API: Process Payment with Educator Context

```json
POST /wp-json/cms/v1/payment/process
{
    "educator_id": 123,
    "customer_name": "John Doe",
    "customer_email": "john@example.com",
    "course_id": 456,
    "payment_method_id": "pm_xxxx",
    "gateway": "stripe"
}
```

## Credential Storage

All API keys are **encrypted at rest** using the Encryption helper:

```php
// Encrypting
$encrypted = \HMWEvents\Helpers\Encryption::encrypt($secret_key);
update_user_meta($educator_id, 'educator_stripe_secret', $encrypted);

// Decrypting (handled automatically by gateway)
$decrypted = \HMWEvents\Helpers\Encryption::decrypt($encrypted);
```

## Test Mode Behavior

When `hmwevents_stripe_mode = 'test'`:

- ✅ **Always** uses plugin-level test keys
- ✅ Ignores educator-specific credentials
- ✅ Allows testing without affecting live payments
- ✅ All educators use same test account

When `hmwevents_stripe_mode = 'live'`:

- ✅ Uses educator's live credentials if configured
- ✅ Falls back to plugin live keys if educator has no keys
- ✅ Each educator gets paid to their own Stripe account

## Security Considerations

1. **Encryption**: All secret keys encrypted with WordPress salts
2. **Permission Checks**: Only educators can edit their own keys
3. **No Exposure**: Keys never sent to frontend (only publishable keys)
4. **Audit Trail**: Key source tracked in `get_key_info()`

## Debugging

### Check Which Keys Are Being Used

```php
$gateway = PaymentGatewayFactory::create('stripe', $educator_id);

if (method_exists($gateway, 'get_key_info')) {
    $info = $gateway->get_key_info();
    /*
    Array (
        'source' => 'educator_123' or 'plugin_test' or 'plugin_live',
        'has_secret_key' => true,
        'has_publishable_key' => true,
        'is_test_mode' => false,
        'educator_id' => 123
    )
    */
}
```

### API Endpoint for Admins

```
GET /wp-json/cms/v1/payment/gateway-config?educator_id=123
```

Response (admin only sees key_info):
```json
{
    "success": true,
    "config": {
        "gateway_id": "stripe",
        "gateway_name": "Stripe",
        "publishable_key": "pk_live_...",
        "key_info": {
            "source": "educator_123",
            "has_secret_key": true,
            "has_publishable_key": true,
            "is_test_mode": false,
            "educator_id": 123
        }
    }
}
```

## Benefits

1. **🏢 Multi-Tenant**: Each educator has their own Stripe account
2. **💰 Direct Payment**: Money goes directly to educator's account
3. **🧪 Test Mode**: Easy testing with plugin-level test keys
4. **🔒 Secure**: All credentials encrypted at rest
5. **🔄 Fallback**: Graceful degradation to plugin keys
6. **📊 Transparent**: Clear tracking of which keys are used

## Migration from Single-Tenant

Existing code continues to work:

```php
// Old way (still works, uses plugin keys)
$gateway = new PaymentGateway();
$gateway->register();

// New way (educator-specific)
$gateway = new PaymentGateway(null, $educator_id);
$gateway->register();

// Factory way (recommended)
$gateway = PaymentGatewayFactory::create('stripe', $educator_id);
```

## Future Enhancements

- [ ] Add UI for educators to manage their credentials
- [ ] Support for Stripe Connect (platform model)
- [ ] PayPal multi-tenant implementation
- [ ] Credential validation on save
- [ ] Automatic key rotation/expiry
- [ ] Webhook routing per educator
- [ ] Commission/fee calculation
