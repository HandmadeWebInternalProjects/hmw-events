# Stripe Key Migration

This migration handles educator Stripe API keys to ensure they are properly encrypted and stored.

## What it Does

1. **Migrates publishable keys**: Moves keys from `educator_payment_key` to `educator_stripe_key` 
2. **Encrypts secret keys**: Encrypts plain text secret keys that start with `sk_`
3. **Validates format**: Ensures all keys are in the correct Stripe format

## Running the Migration

### Option 1: WP-CLI (Recommended)

```bash
# Check current status
wp cms stripe-keys check

# Run migration
wp cms stripe-keys migrate
```

### Option 2: Programmatically

```php
use HMWEvents\Helpers\StripeKeyMigration;

// Migrate all educators
$results = StripeKeyMigration::migrate_all_educator_keys();

// Migrate single educator
$user_id = 'user_123';
$email = 'educator@example.com';
$result = StripeKeyMigration::migrate_educator_keys($user_id, $email);

// Generate HTML report
echo StripeKeyMigration::generate_report($results);
```

## Migration Safety

- ✅ Safe to run multiple times
- ✅ Does not modify already-encrypted keys
- ✅ Validates decryption after encryption
- ✅ Only affects educators with `educator_payment_type` = `stripe`
- ✅ Non-destructive (keeps backup of old values during migration)

## Key Format Reference

**Publishable Keys** (not encrypted):
- Format: `pk_test_...` or `pk_live_...`
- Length: ~32-108 characters
- Stored in: `educator_stripe_key`

**Secret Keys** (encrypted):
- Plain format: `sk_test_...` or `sk_live_...` 
- Encrypted format: Long hex string (200+ characters)
- Stored in: `educator_stripe_secret`

## Troubleshooting

### "Ciphertext has invalid hex encoding"

This means a secret key is stored as plain text. Run the migration to encrypt it.

### "No Stripe publishable key found"

Check if the key is in `educator_payment_key` field. The migration will move it automatically.

### "Failed to encrypt secret key"

Ensure `DEFUSE_ENCRYPTION_KEY` is defined in `wp-config.php`:

```php
define('DEFUSE_ENCRYPTION_KEY', 'def00000...');
```

Generate a new key if needed:

```php
use HMWEvents\Helpers\Encryption;
echo Encryption::generate_key();
```

## Migration Results

The migration returns detailed results:

```php
[
    'success' => 5,      // Number of successful migrations
    'errors' => 1,       // Number with errors
    'skipped' => 2,      // Number skipped (not using Stripe)
    'details' => [...]   // Detailed per-educator results
]
```

Each educator detail includes:
- `user_id`: ACF user ID
- `email`: Educator email
- `success`: Boolean success status
- `changes`: Array of changes made
- `errors`: Array of error messages
