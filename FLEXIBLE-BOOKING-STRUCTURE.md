# Flexible Booking Details Structure

## Overview
The booking details system has been refactored from a rigid column-based structure to a flexible JSON + Metadata hybrid approach. This allows easy addition and modification of form fields without database schema changes.

## Database Structure

### educator_booking_details Table
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `booking_id` (BIGINT UNSIGNED, UNIQUE, FOREIGN KEY to educator_bookings)
- `form_data` (LONGTEXT) - JSON-encoded form data
- `form_version` (VARCHAR(20)) - Version identifier (e.g., "1.0")
- `created_at` (DATETIME)
- `updated_at` (DATETIME)

### educator_booking_meta Table (NEW)
For searchable/filterable fields:
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `booking_id` (BIGINT UNSIGNED, FOREIGN KEY to educator_bookings)
- `meta_key` (VARCHAR(255), INDEXED)
- `meta_value` (TEXT, INDEXED)
- `created_at` (DATETIME)
- `updated_at` (DATETIME)

## Form Version 1.0 Fields

### Required Fields
1. `mothers_first_name` (text) - Searchable
2. `mothers_last_name` (text) - Searchable
3. `email` (email) - Searchable
4. `phone` (tel)
5. `street_address` (text)
6. `city` (text)
7. `postcode` (text)
8. `terms_accepted` (checkbox)

### Optional Fields
9. `partner_name` (text) - Searchable
10. `health_fund` (text) - Searchable
11. `due_date` (date) - Searchable
12. `dietary_requirements` (textarea)
13. `mailing_agreement` (checkbox)

## Searchable Fields
These fields are automatically stored in the `educator_booking_meta` table:
- mothers_first_name
- mothers_last_name
- partner_name
- email
- due_date
- health_fund

## BookingDetailsService

### Location
`src/Services/BookingDetailsService.php`

### Key Methods

#### save_booking_details($booking_id, $form_data, $form_version = '1.0')
Saves form data as JSON and stores searchable fields in meta table.

**Parameters:**
- `$booking_id` (int) - The booking ID
- `$form_data` (array) - Form data to store
- `$form_version` (string) - Form version identifier

**Returns:** `int|false` - Insert ID on success, false on failure

#### get_booking_details($booking_id)
Retrieves booking details including form data, version, and timestamps.

**Returns:** Array with keys: `data`, `form_version`, `created_at`, `updated_at`

#### get_form_data($booking_id)
Gets just the decoded form data array.

**Returns:** `array|null`

#### save_booking_meta($booking_id, $meta_key, $meta_value)
Saves a single metadata value.

#### get_booking_meta($booking_id, $meta_key, $default = null)
Gets a single metadata value.

#### search_by_meta($meta_key, $meta_value, $comparison = '=')
Searches for bookings by metadata.

**Parameters:**
- `$meta_key` (string) - The metadata key
- `$meta_value` (mixed) - The value to match
- `$comparison` (string) - Comparison operator (=, LIKE, >, <, etc.)

**Returns:** `array` - Array of booking IDs

## Usage Examples

### Saving Booking Details
```php
use HMWEvents\Services\BookingDetailsService;

$service = new BookingDetailsService();

$form_data = [
    'mothers_first_name' => 'Jane',
    'mothers_last_name' => 'Smith',
    'email' => 'jane@example.com',
    'phone' => '0412345678',
    'street_address' => '123 Main St',
    'city' => 'Sydney',
    'postcode' => '2000',
    'partner_name' => 'John Smith',
    'health_fund' => 'Medibank',
    'due_date' => '2024-06-15',
    'dietary_requirements' => 'Vegetarian',
    'mailing_agreement' => true,
    'terms_accepted' => true
];

$result = $service->save_booking_details($booking_id, $form_data, '1.0');
```

### Retrieving Booking Details
```php
$details = $service->get_booking_details($booking_id);
echo $details['data']['mothers_first_name']; // 'Jane'
echo $details['form_version']; // '1.0'
```

### Searching by Metadata
```php
// Find all bookings with a specific health fund
$booking_ids = $service->search_by_meta('health_fund', 'Medibank');

// Find bookings by email (partial match)
$booking_ids = $service->search_by_meta('email', 'jane@example.com', 'LIKE');

// Find bookings with due date after specific date
$booking_ids = $service->search_by_meta('due_date', '2024-06-01', '>');
```

## Migration from Old Structure

If you have existing bookings with the old rigid column structure, you need to migrate them to the new JSON format. The old structure had these columns:
- partner_name
- occupation
- partner_occupation
- health_fund
- due_date
- model_of_care
- caregiver
- hospital_location
- dietary_requirements
- medical_conditions
- medications
- disabilities
- first_baby
- birth_trauma
- fears
- feelings
- expectations
- hear_about
- mailing_agreement
- additional_information
- custom_fields

### Migration Script
Create a script to:
1. Read old column data
2. Convert to JSON format
3. Save using BookingDetailsService
4. Verify migration
5. Optionally drop old columns (after backup!)

Example migration:
```php
global $wpdb;

$old_bookings = $wpdb->get_results("
    SELECT * FROM {$wpdb->prefix}educator_booking_details
");

$service = new \HMWEvents\Services\BookingDetailsService();

foreach ($old_bookings as $old) {
    // Convert old structure to new format
    $form_data = [
        'partner_name' => $old->partner_name,
        'health_fund' => $old->health_fund,
        'due_date' => $old->due_date,
        'dietary_requirements' => $old->dietary_requirements,
        'mailing_agreement' => $old->mailing_agreement,
        // Add other fields as needed
    ];
    
    // Remove null values
    $form_data = array_filter($form_data, function($value) {
        return $value !== null && $value !== '';
    });
    
    // Save with version 0.9 to indicate migrated data
    $service->save_booking_details($old->booking_id, $form_data, '0.9');
}
```

## Adding New Form Fields

To add new fields in the future:

1. Update the BookingForm.php shortcode HTML
2. Update ProcessPayment.php to collect the new field:
   ```php
   $booking_details = [
       // ... existing fields ...
       'new_field_name' => $request->get_param('new_field_name'),
   ];
   ```
3. Add to validation args if required:
   ```php
   'new_field_name' => [
       'required' => false,
       'type' => 'string',
       'sanitize_callback' => 'sanitize_text_field',
   ],
   ```
4. If searchable, add to BookingDetailsService::$searchable_fields array
5. Increment form version (e.g., "1.1")

No database changes required!

## Benefits

1. **Flexibility** - Add/remove/modify form fields without database migrations
2. **Searchability** - Important fields indexed in meta table for fast queries
3. **Version Tracking** - Form version helps handle backward compatibility
4. **Simplicity** - Single service class handles all booking details operations
5. **Future-Proof** - Easy to adapt to changing requirements

## Related Files

- Database Schema: `includes/install.php`
- Service Class: `src/Services/BookingDetailsService.php`
- Form Display: `src/Shortcodes/BookingForm.php`
- Payment Processing: `src/Api/Routes/ProcessPayment.php`
- Data Saving: `src/Services/Gateways/AbstractPaymentGateway.php`
- Frontend JavaScript: `assets/js/booking-form.js`
