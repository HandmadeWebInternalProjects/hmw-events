# Handmade Web Event Manager - Test Suite Summary

## 📊 Test Suite Overview

This document provides a comprehensive overview of the test suite for the Handmade Web Event Manager plugin.

## ✅ Implemented Tests (Phase 1)

### Unit Tests - Core Helpers

#### 1. **Encryption Helper Tests** (`tests/Unit/Helpers/EncryptionTest.php`)
- ✅ 13 tests covering encryption/decryption functionality
- Tests encrypt/decrypt cycle with valid keys
- Tests various data types (Stripe keys, PayPal IDs, special characters)
- Tests non-deterministic encryption (same plaintext = different ciphertext)
- Tests error handling (invalid data types, tampered ciphertext)
- Tests key generation and availability checks

**Key Coverage:**
- `encrypt()` - String encryption with Defuse library
- `decrypt()` - String decryption with validation
- `is_available()` - Encryption availability check
- `generate_key()` - New key generation

#### 2. **Course Helper Tests** (`tests/Unit/Helpers/CourseTest.php`)
- ✅ 10 tests covering course data retrieval and availability
- Tests complete course details with all fields
- Tests Google Maps address format handling
- Tests missing/optional fields with defaults
- Tests invalid course scenarios (non-existent, wrong post type)
- Tests availability checking logic
- Tests external course handling
- Tests numeric field type casting

**Key Coverage:**
- `get_course_details()` - Retrieve course data from ACF fields
- `check_course_availability()` - Query availability from database

#### 3. **Search Functions Tests** (`tests/Unit/Breakdance/SearchFunctionsTest.php`)
- ✅ 9 tests covering educator search functionality
- Tests location-based search with distance calculation
- Tests state-based search with pagination
- Tests name-based search with wildcard matching
- Tests hospital certification filtering
- Tests distance sorting and minimum distance per educator
- Tests error handling (no results, WP_Error)

**Key Coverage:**
- `search_educators_by_location()` - Geocoded search within radius
- `search_educators_by_state()` - State filtering
- `search_educators_by_name()` - Name matching search
- `search_educators_by_hospital()` - Hospital certification filter

#### 4. **RecurringCourse Helper Tests** (`tests/Unit/Helpers/RecurringCourseTest.php`)
- ✅ 34 tests covering recurrence pattern creation, date calculation, and series management
- Tests all 6 recurrence types: daily, weekly, fortnightly, monthly, bimonthly, custom
- Tests weekly with same day and specific days (Mon/Wed/Fri) patterns
- Tests weekly starting midweek and on the last selected day (Friday)
- Tests fortnightly with same day and specific day patterns
- Tests custom occurrences from ACF fields with date sorting
- Tests end_date boundary and max_occurrences limits
- Tests missing template, wrong post type, and empty date handling
- Tests instance generation skips existing dates
- Tests series updates (all and future-only)
- Tests deactivation and deletion (with/without instances, hard vs soft delete)
- Tests template post protection (never deletes the template)
- Tests series regeneration and pattern updates

**Key Coverage:**
- `create_recurring_series()` - Full series creation with instance generation
- `generate_instances()` - Instance generation from recurrence pattern
- `calculate_occurrences()` - All recurrence types and configurations
- `calculate_custom_occurrences()` - Custom date handling
- `get_series_instances()` - Instance querying (all/future)
- `update_series()` - Bulk meta updates
- `deactivate_series()` - Pattern deactivation with cleanup
- `delete_series()` - Instance deletion (hard/soft/template-safe)
- `regenerate_series()` - Full pattern regeneration
- `update_pattern()` - Pattern configuration updates
- `delete_recurrence_pattern()` - Pattern deletion

### Service Layer Tests

#### 4. **Booking Cleanup Service Tests** (`tests/Unit/Services/BookingCleanupTest.php`)
- ✅ 11 tests covering automated booking cleanup
- Tests cron scheduling and unscheduling
- Tests cleanup of abandoned bookings (24+ hours old)
- Tests booking group status updates
- Tests course availability restoration
- Tests booking history creation
- Tests handling of already cancelled bookings

**Key Coverage:**
- `schedule_cleanup()` - Cron job scheduling
- `clear_schedule()` - Cron cleanup
- `cleanup_abandoned_bookings()` - Main cleanup logic
- `manual_cleanup()` - Manual trigger for testing

#### 5. **Stripe Service Tests** (`tests/Unit/Services/StripeServiceTest.php`)
- ✅ 15 tests covering Stripe payment integration
- Tests amount conversion (dollars ↔ cents)
- Tests payment intent creation with metadata
- Tests create and confirm payment flow
- Tests refund creation (full and partial)
- Tests API error handling (card errors, API errors)
- Tests automatic payment methods configuration
- Tests currency conversion to lowercase

**Key Coverage:**
- `convert_to_cents()` / `convert_to_dollars()` - Currency conversion
- `create_payment_intent()` - Payment intent with metadata
- `create_and_confirm_payment()` - Single-step payment
- `create_refund()` - Full and partial refunds
- `init_stripe_with_key()` - Service initialization

### Integration Tests

#### 6. **Stripe Webhook Integration Tests** (`tests/Integration/StripeWebhookIntegrationTest.php`)
- ✅ 7 tests covering end-to-end webhook handling
- Tests route registration
- Tests successful payment webhook flow
- Tests failed payment webhook flow
- Tests webhook signature verification
- Tests idempotency (duplicate event handling)
- Tests payment transaction record creation
- Tests missing metadata handling

**Key Coverage:**
- `handle_webhook()` - Main webhook handler
- `handle_payment_succeeded()` - Payment success flow
- `handle_payment_failed()` - Payment failure flow
- Database transaction updates
- Stripe event verification

#### 7. **Base Integration Test Class** (`tests/Integration/BaseIntegrationTest.php`)
- ✅ Reusable test infrastructure for integration tests
- Mock data generation helpers
- Database assertion helpers
- ACF function mocking utilities
- WordPress function mocking setup

**Utilities Provided:**
- `createMockCourse()` - Generate test course data
- `createMockCustomer()` - Generate test customer data
- `createMockBookingGroup()` - Generate booking group
- `createMockBooking()` - Generate individual booking
- `assertDatabaseInsert()` - Assert insert operations
- `assertDatabaseUpdate()` - Assert update operations

## 📈 Test Coverage Summary

| Component | Tests | Status |
|-----------|-------|--------|
| **Helpers** | **66 tests** | ✅ Implemented |
| - Encryption | 13 | ✅ |
| - Course | 10 | ✅ |
| - Search Functions | 9 | ✅ |
| - **RecurringCourse** | **34** | **✅ Implemented** |
| **Services** | 26 tests | ✅ Implemented |
| - BookingCleanup | 11 | ✅ |
| - StripeService | 15 | ✅ |
| **Integration** | 7 tests | ✅ Implemented |
| - Stripe Webhook | 7 | ✅ |
| **Total** | **128 tests** | ✅ |

## 🎯 Priority Components Not Yet Tested

### High Priority (Recommended Next Steps)

1. **Payment Gateway Integration** (`src/Services/PaymentGateway.php`)
   - Complete payment processing flow
   - Booking creation with transactions
   - Multi-gateway support (Stripe, PayPal)
   - Database rollback on failure

2. **REST API Endpoints** (`src/Api/RegisterRoutes.php`)
   - Payment processing endpoints
   - Booking transfer endpoints
   - Input validation and sanitization
   - Permission callbacks

3. **RecurringCourseHandler** (`src/Admin/RecurringCourseHandler.php`)
   - ACF save post hooks
   - Series creation and regeneration
   - Template/instance relationship management
   - Admin notices

4. **Educator Payments Dashboard** (`src/Admin/EducatorPaymentsDashboard.php`)
   - Complex financial queries
   - Australian financial year calculations
   - CSV export functionality
   - Refund/cancellation actions

## 🚀 Running Tests

### Run All Tests
```bash
./vendor/bin/phpunit
```

### Run with Verbose Output
```bash
./vendor/bin/phpunit --testdox
```

### Run Specific Test Suite
```bash
# Unit tests only
./vendor/bin/phpunit tests/Unit/

# Integration tests only
./vendor/bin/phpunit tests/Integration/

# Specific test class
./vendor/bin/phpunit tests/Unit/Services/StripeServiceTest.php

# Specific test method
./vendor/bin/phpunit --filter test_encrypt_decrypt_cycle
```

### Run with Coverage
```bash
./vendor/bin/phpunit --coverage-html coverage/
```

## 🔧 Test Configuration

### PHPUnit Configuration (`phpunit.xml`)
- **Test Suites:** Unit and Integration
- **Code Coverage:** `src/` directory
- **Bootstrap:** `tests/bootstrap.php` with Brain Monkey setup

### Dependencies
- **PHPUnit:** 9.6+ (Testing framework)
- **Brain Monkey:** 2.6+ (WordPress function mocking)
- **Mockery:** 1.4+ (Object mocking)
- **Defuse Crypto:** For encryption testing

### Test Environment
- **WordPress Functions:** Mocked with Brain Monkey
- **Database:** Mocked with Mockery `wpdb` mock
- **ACF Fields:** Mocked with custom helpers
- **Stripe SDK:** Mocked for unit tests, can use test mode for integration

## 📝 Writing New Tests

### Unit Test Template
```php
<?php
namespace HMWEvents\Tests\Unit\YourNamespace;

use HMWEvents\YourClass;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class YourClassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // Your setup code
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_your_functionality()
    {
        // Arrange
        // Act
        // Assert
    }
}
```

### Integration Test Template
```php
<?php
namespace HMWEvents\Tests\Integration;

class YourIntegrationTest extends BaseIntegrationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        // Additional setup
    }

    public function test_complete_workflow()
    {
        // Use helper methods from BaseIntegrationTest
        $course = $this->createMockCourse();
        $customer = $this->createMockCustomer();
        
        // Test complete flow
        // Assert database operations
    }
}
```

## 🎓 Testing Best Practices

### Test Naming
- Use descriptive test names: `test_encrypt_decrypt_cycle()`
- Include expected behavior: `test_cleanup_restores_course_availability()`
- Use data providers for multiple scenarios

### Assertions
- One logical assertion per test
- Use specific assertions (`assertIsFloat`, `assertInstanceOf`)
- Test both success and failure paths

### Mocking
- Mock external dependencies (Stripe API, database)
- Use Brain Monkey for WordPress functions
- Mock only what's necessary

### Test Data
- Use realistic test data
- Create reusable fixtures
- Clear test data in `tearDown()`

## 🔐 Security Testing Notes

### Payment Processing
- Always test with Stripe test mode keys
- Verify webhook signature validation
- Test for SQL injection in user inputs
- Validate all sanitization/escaping

### Encryption
- Test with valid encryption keys
- Verify key rotation scenarios
- Test backward compatibility with plain text keys

## 📊 CI/CD Integration

### GitHub Actions Workflow
- Automatically runs on push to `main` and `develop`
- Tests on PHP 8.0, 8.1, and 8.2
- Generates code coverage report
- Uploads to Codecov (optional)

### Pre-commit Hooks (Recommended)
```bash
#!/bin/sh
# .git/hooks/pre-commit
./vendor/bin/phpunit --stop-on-failure
```

## 🐛 Debugging Tests

### Common Issues

1. **Mockery Expectations Not Met**
   - Check that mocked methods are actually called
   - Verify method signatures match expectations
   - Use `Mockery::on()` for flexible argument matching

2. **Brain Monkey Function Mocking**
   - Ensure `Monkey\setUp()` is called in `setUp()`
   - Use `Functions\expect()` for strict expectations
   - Use `Functions\when()` for loose stubs

3. **Database Mocking Issues**
   - Verify `$wpdb->prefix` is set correctly
   - Check SQL query preparation patterns
   - Use `Mockery::pattern()` for regex matching

### Verbose Test Output
```bash
./vendor/bin/phpunit --testdox --verbose
```

### Debug Specific Test
```bash
./vendor/bin/phpunit --filter test_name_here --debug
```

## 📚 Additional Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Brain Monkey Documentation](https://brain-wp.github.io/BrainMonkey/)
- [Mockery Documentation](http://docs.mockery.io/)
- [Stripe Testing Guide](https://stripe.com/docs/testing)

## 🎯 Coverage Goals

- **Target:** 80% overall code coverage
- **Critical Components:** 90%+ coverage
  - Payment processing
  - Booking management
  - Encryption
  - Database operations

## 📞 Support

For questions or issues with the test suite:
1. Check this documentation first
2. Review existing test examples
3. Consult the PHPUnit/Brain Monkey docs
4. Contact the development team

---

**Last Updated:** December 11, 2025
**Test Suite Version:** 1.0.0
**Total Tests:** 65 tests covering critical functionality
