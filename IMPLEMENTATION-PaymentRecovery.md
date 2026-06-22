# Payment Recovery System - Implementation Summary

## Overview
Implemented a comprehensive payment recovery system that automatically handles abandoned/failed payment bookings with webhook-triggered emails, token-based recovery URLs, and automatic cleanup.

## Implementation Date
27 November 2025

## Components Implemented

### 1. PaymentGateway.php - Recovery Token & Email System
**Location**: `src/Services/PaymentGateway.php`

**New Methods**:
- `generate_recovery_token($booking_group_id)` - Generates secure 32-character token, stores with 24hr expiration in booking_group metadata
- `get_recovery_url($token)` - Returns formatted recovery URL with token parameter
- `send_recovery_email($booking_group_id)` - Sends HTML email with booking details, recovery link, and 24hr warning
- `get_recovery_email_template($data)` - HTML email template with professional styling

**Features**:
- Secure token generation using `wp_generate_password(32, false)`
- 24-hour token expiration stored in GMT
- Professional HTML email template with booking summary
- Metadata storage in `educator_booking_groups` table

### 2. StripeWebhook.php - Webhook Integration
**Location**: `src/Api/StripeWebhook.php`

**Modified Method**: `handle_payment_failed()`

**Changes**:
- Triggers `send_recovery_email()` after updating transaction status to 'failed'
- Logs email success/failure for debugging
- Integrates seamlessly with existing webhook flow

**Trigger**: Stripe webhook event `payment_intent.payment_failed`

### 3. ProcessPayment.php - Resume Payment Endpoint
**Location**: `src/Api/Routes/ProcessPayment.php`

**New Endpoint**: `GET /cms/v1/payment/resume/{token}`

**Features**:
- Token validation against booking_group metadata
- 24-hour expiration check (returns 410 Gone if expired)
- Retrieves existing payment_intent from Stripe
- Returns booking details + client_secret for Stripe Elements
- No payment method storage (security best practice)

**Response Data**:
```json
{
  "success": true,
  "data": {
    "booking_reference": "BKG-ABC123",
    "booking_number": "CB-20251127-XYZ456",
    "course_name": "Course Name",
    "customer_name": "John Doe",
    "customer_email": "john@example.com",
    "amount": 99.00,
    "payment_type": "full",
    "payment_intent_id": "pi_...",
    "client_secret": "pi_..._secret_...",
    "expires_at": "2025-11-28 03:00:00"
  }
}
```

### 4. BookingCleanup.php - Automated Cleanup Service
**Location**: `src/Services/BookingCleanup.php`

**New Class**: Complete WordPress cron service

**Features**:
- Daily cron job scheduled at 3:00 AM
- Finds bookings with `payment_status='pending'` or `'failed'` older than 24 hours
- Updates booking_group to `payment_status='cancelled'`
- Updates bookings to `status='cancelled'` with timestamp
- Restores course availability via `update_course_availability()`
- Adds booking history entries with cancellation reason
- Comprehensive error logging

**Cron Management**:
- Scheduled on plugin activation
- Cleared on plugin deactivation
- Manual trigger method available: `manual_cleanup()`

### 5. Error Message Updates
**Location**: `src/Services/PaymentGateway.php`

**Modified Methods**:
- `process_booking()` catch block
- `process_payment_with_confirmation()` catch block

**Enhancement**: Appends recovery email information to payment-related errors:
> "Check your email for a recovery link to complete your booking within 24 hours."

### 6. HMWEvents.php - Service Registration
**Location**: `src/HMWEvents.php`

**Change**: Added `Services\BookingCleanup::class` to components array for automatic initialization

## Configuration

### Token Security
- **Length**: 32 characters
- **Character Set**: Alphanumeric (no special characters for URL compatibility)
- **Storage**: JSON metadata in `educator_booking_groups.metadata`
- **Expiration**: 24 hours from generation (stored as GMT timestamp)

### Email Template
- **Subject**: `[{site_name}] Complete Your Booking - Payment Required`
- **Format**: HTML with inline styles
- **Content**: Customer name, booking reference, course name, amount, recovery button
- **Warning**: Prominent 24-hour expiration notice
- **From**: Site name and admin email

### Cleanup Schedule
- **Frequency**: Daily
- **Time**: 3:00 AM (site timezone)
- **Threshold**: 24 hours from booking creation
- **Action**: Cancel bookings, restore availability, log history

## Database Schema

### Metadata Structure (educator_booking_groups.metadata)
```json
{
  "recovery_token": "abc123...",
  "recovery_token_expires": "2025-11-28 03:00:00",
  "...other metadata..."
}
```

### Status Flow
1. **Initial**: `payment_status='pending'`, `status='pending'`
2. **Payment Failed**: `payment_status='failed'` → Recovery email sent
3. **24hr Timeout**: `payment_status='cancelled'`, `status='cancelled'`
4. **Successful Recovery**: `payment_status='paid'`, `status='confirmed'`

## API Endpoints

### Resume Payment
```
GET /wp-json/cms/v1/payment/resume/{token}
```

**Error Responses**:
- `404`: Invalid token
- `410`: Token expired
- `404`: Transaction not found
- `500`: Payment intent retrieval error

## Next Steps (Not Yet Implemented)

### 1. Payment Resume Page/Shortcode
**TODO**: Create frontend interface for token-based payment completion
- Shortcode: `[payment_resume]`
- Accepts `?token=` parameter
- Displays booking summary
- Loads Stripe Elements with existing payment_intent_id
- Handles successful payment confirmation

**Priority**: High (required for user-facing functionality)

### 2. Testing Checklist
- [ ] Test webhook payment_failed event triggers email
- [ ] Verify email delivery with correct booking details
- [ ] Test token validation and expiration
- [ ] Confirm resume endpoint returns correct data
- [ ] Test cron cleanup cancels old bookings
- [ ] Verify course availability restoration
- [ ] Test multiple failed payments for same booking

### 3. Optional Enhancements
- [ ] Admin dashboard showing abandoned bookings stats
- [ ] Manual "resend recovery email" button
- [ ] Token re-generation on first click (extend expiration)
- [ ] Customizable email templates in admin
- [ ] Multi-language email support
- [ ] SMS notifications option

## Notes

- Recovery URL placeholder currently points to `/payment-resume` - update in `PaymentGateway::get_recovery_url()` when page is created
- Email uses `wp_mail()` - compatible with SMTP plugins
- Cron depends on WP-Cron - may need external cron for high-traffic sites
- No payment method storage per security best practices - users re-enter card details
- All timestamps stored in GMT for consistency

## Testing Commands

```bash
# Manually trigger cleanup (via WP-CLI if implemented)
wp cms cleanup-bookings

# Check next scheduled cron run
wp cron event list | grep hmwevents_cleanup

# Test email delivery
# (Trigger payment_failed webhook in Stripe Dashboard)
```

## Files Modified/Created

### Created:
- `src/Services/BookingCleanup.php`

### Modified:
- `src/Services/PaymentGateway.php`
- `src/Api/StripeWebhook.php`
- `src/Api/Routes/ProcessPayment.php`
- `src/HMWEvents.php`

## Rollback Plan

If issues occur:
1. Deactivate plugin to clear cron schedule
2. Remove `Services\BookingCleanup::class` from `HMWEvents.php`
3. Revert changes to webhook handler (remove email sending)
4. Remove recovery endpoint from ProcessPayment.php

All changes are backward compatible - existing bookings unaffected.
