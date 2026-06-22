# Multi-Tenant Gateway Implementation - Quick Reference

## Summary

✅ **Implemented**: Factory/Strategy pattern with multi-tenant support
✅ **Credential Resolution**: Smart key selection based on mode and educator
✅ **Backward Compatible**: Existing code still works
✅ **Secure**: All credentials encrypted at rest

## Quick Start

### 1. Get Gateway Configuration (Frontend)

```javascript
// Fetch Stripe publishable key for educator
const response = await fetch(
  '/wp-json/cms/v1/payment/gateway-config?educator_id=123'
);
const { config } = await response.json();

// Initialize Stripe with educator's key
const stripe = Stripe(config.publishable_key);
```

### 2. Process Payment (Backend)

```php
use HMWEvents\Factory\PaymentGatewayFactory;

// Create gateway for specific educator
$gateway = PaymentGatewayFactory::create('stripe', $educator_id);

$result = $gateway->process_payment_with_confirmation([
    'customer_name' => 'John Doe',
    'customer_email' => 'john@example.com',
    'course_id' => 123,
    'educator_id' => $educator_id,
    'payment_method_id' => 'pm_xxxx',
]);
```

### 3. Process Payment (REST API)

```bash
curl -X POST https://yoursite.com/wp-json/cms/v1/payment/process \
  -H "Content-Type: application/json" \
  -d '{
    "educator_id": 123,
    "customer_name": "John Doe",
    "customer_email": "john@example.com",
    "course_id": 456,
    "payment_method_id": "pm_xxxx"
  }'
```

## Credential Resolution Logic

```
IF test_mode THEN
    ✓ Use plugin test keys (hmwevents_stripe_test_*)
ELSE (live mode)
    IF educator has stripe credentials THEN
        ✓ Use educator's live keys (educator_stripe_*)
    ELSE
        ✓ Fallback to plugin live keys (hmwevents_stripe_live_*)
    END IF
END IF
```

## Configuration Locations

### Plugin Settings (Global)
```
Admin → Settings → Payment Gateways

hmwevents_stripe_mode: 'test' or 'live'
hmwevents_stripe_test_secret_key: 'sk_test_...'
hmwevents_stripe_test_publishable_key: 'pk_test_...'
hmwevents_stripe_live_secret_key: 'sk_live_...' (fallback)
hmwevents_stripe_live_publishable_key: 'pk_live_...' (fallback)
```

### Educator Profile (User Meta)
```
Educator Profile → Payment Settings

educator_payment_type: 'stripe' | 'paypal' | 'manual'
educator_stripe_key: 'pk_live_...'
educator_stripe_secret: 'sk_live_...' (encrypted)
```

## Files Changed

### New Files
- `src/Interfaces/PaymentGatewayInterface.php` - Gateway contract
- `src/Services/Gateways/AbstractPaymentGateway.php` - Base implementation
- `src/Services/Gateways/StripePaymentGateway.php` - Stripe implementation
- `src/Services/Gateways/PayPalPaymentGateway.php` - PayPal stub
- `src/Factory/PaymentGatewayFactory.php` - Factory pattern
- `docs/PAYMENT_GATEWAY_ARCHITECTURE.md` - Architecture docs
- `docs/MULTI_TENANT_PAYMENT_GATEWAYS.md` - Multi-tenant docs

### Modified Files
- `src/Services/PaymentGateway.php` - Now a facade with educator support
- `src/Services/StripeService.php` - Added `init_stripe_with_key()`
- `src/Api/Routes/ProcessPayment.php` - Educator context support

## Testing

### Test Mode (All Educators)
```php
update_option('hmwevents_stripe_mode', 'test');
// All payments use plugin test keys
```

### Live Mode (Educator-Specific)
```php
update_option('hmwevents_stripe_mode', 'live');

// Educator 123 uses their own keys
update_user_meta(123, 'educator_payment_type', 'stripe');
update_user_meta(123, 'educator_stripe_secret', $encrypted_key);
update_user_meta(123, 'educator_stripe_key', 'pk_live_...');

// Educator 456 without keys uses plugin fallback
// (no user meta set)
```

## Debugging

```php
$gateway = PaymentGatewayFactory::create('stripe', $educator_id);
$info = $gateway->get_key_info();

print_r($info);
/*
Array (
    [source] => educator_123          // or plugin_test, plugin_live
    [has_secret_key] => 1
    [has_publishable_key] => 1
    [is_test_mode] => 
    [educator_id] => 123
)
*/
```

## Next Steps

1. ✅ Create UI for educators to manage payment credentials
2. ✅ Add credential validation
3. ✅ Implement PayPal gateway
4. ✅ Add webhook routing per educator
5. ✅ Consider Stripe Connect for better platform model

## Questions?

See full documentation:
- `docs/PAYMENT_GATEWAY_ARCHITECTURE.md` - Architecture overview
- `docs/MULTI_TENANT_PAYMENT_GATEWAYS.md` - Detailed multi-tenant guide
