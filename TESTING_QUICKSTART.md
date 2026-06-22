# Quick Start Guide - Running Tests

## Prerequisites

1. **PHP 8.0+** installed
2. **Composer** installed
3. Plugin dependencies installed: `composer install`

## Installation

From the plugin root directory:

```bash
cd "/Users/johnp/Local Sites/calmerbirth/app/public/wp-content/plugins/hmw-events"
composer install --dev
```

## Running Tests

### Basic Commands

```bash
# Run all tests
./vendor/bin/phpunit

# Run with readable output
./vendor/bin/phpunit --testdox

# Run verbose (see each test description)
./vendor/bin/phpunit --testdox --verbose
```

### Run Specific Test Types

```bash
# Unit tests only
./vendor/bin/phpunit tests/Unit/

# Integration tests only
./vendor/bin/phpunit tests/Integration/

# Specific test file
./vendor/bin/phpunit tests/Unit/Services/StripeServiceTest.php

# Specific test method
./vendor/bin/phpunit --filter test_encrypt_decrypt_cycle
```

### Code Coverage

```bash
# Generate HTML coverage report
./vendor/bin/phpunit --coverage-html coverage/

# View report
open coverage/index.html
```

## Current Test Status

✅ **65 tests implemented** covering:
- Encryption functionality
- Course management
- Educator search
- Booking cleanup
- Stripe payment processing
- Webhook handling

## Expected Output

```
PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

Search Functions (9 tests)
 ✔ Search educators by location returns educators with distance
 ✔ Search educators by state returns educators
 ✔ Search educators by name returns matching educators
 ...

Course Helper (10 tests)
 ✔ Get course details returns complete data
 ✔ Check course availability returns true when available
 ...

Encryption (13 tests)
 ✔ Encrypt decrypt cycle
 ✔ Encrypt decrypt various data
 ...

Booking Cleanup (11 tests)
 ✔ Cleanup processes abandoned bookings
 ✔ Cleanup restores course availability
 ...

Stripe Service (15 tests)
 ✔ Create payment intent includes metadata
 ✔ Create refund partial amount
 ...

Stripe Webhook Integration (7 tests)
 ✔ Payment succeeded webhook updates booking
 ✔ Webhook with invalid signature returns error
 ...

Time: XX.XX seconds, Memory: XXX MB

OK (65 tests, XXX assertions)
```

## Troubleshooting

### Issue: "Cannot find PHPUnit"
**Solution:** Run `composer install --dev` to install test dependencies

### Issue: "Class not found"
**Solution:** Check autoloading in `composer.json` and run `composer dump-autoload`

### Issue: Encryption tests failing
**Solution:** Encryption tests require a valid key. The tests generate one automatically.

### Issue: Tests hanging
**Solution:** Check for infinite loops or missing mocks. Run with `--verbose` for details.

## CI/CD

Tests automatically run via GitHub Actions on:
- Push to `main` or `develop` branches
- Pull requests to `main` or `develop`

## Next Steps

1. **Review failing tests** (if any) and fix issues
2. **Add more tests** for uncovered components
3. **Set up pre-commit hooks** to run tests before committing
4. **Increase coverage** to target 80%+

## Resources

- **Full Documentation:** See `tests/TEST_SUITE_SUMMARY.md`
- **Test Examples:** Browse `tests/Unit/` and `tests/Integration/`
- **PHPUnit Docs:** https://phpunit.de/documentation.html

---

**Quick Reference:**
- ✅ 65 tests implemented
- 🎯 Critical components covered
- 🚀 CI/CD configured
- 📊 Ready for expansion
