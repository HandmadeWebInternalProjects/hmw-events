# Recurring courses

The Handmade Web Event Manager supports recurring courses using a template-based approach with automatic instance generation.

## How It Works

### Architecture

1. **Template Post**: An `educator_course` post serves as the template containing all the default settings (cost, capacity, location, etc.)
2. **Recurrence Pattern**: Stored in `wp_educator_course_recurrence` table with rules (weekly, monthly, etc.)
3. **Generated Instances**: Individual `educator_course` posts auto-created for each occurrence
4. **Linking**: All instances link back to the template via `course_template_id` and `course_recurrence_id` meta fields

### Database Tables

```sql
wp_educator_course_recurrence
- id: Primary key
- course_template_id: Links to template wp_posts.ID
- recurrence_type: 'daily', 'weekly', 'monthly', 'custom'
- recurrence_interval: Repeat every X days/weeks/months
- recurrence_days: Comma-separated day numbers (1=Mon, 7=Sun) for weekly
- start_date: When recurrence starts
- end_date: When recurrence ends (optional)
- max_occurrences: Maximum number of instances (optional)
- is_active: Whether recurrence is active
```

## Creating Recurring courses

### Method 1: Via WordPress Admin (ACF)

1. **Create/Edit an Educator Course**
   - Go to courses → Add New
   - Fill in all course details (title, description, cost, capacity, location, etc.)
   - Set the start date/time for the first occurrence

2. **Enable Recurring**
   - Check "Recurring Course" toggle
   - This reveals the recurrence configuration fields

3. **Configure Recurrence**
   - **Recurrence Type**: Choose Daily, Weekly, or Monthly
   - **Repeat Every**: Interval (e.g., "2" for every 2 weeks)
   - **Repeat On Days**: (Weekly only) Select specific days
   - **End Recurrence On**: Optional end date
   - **Maximum Occurrences**: Or limit by count (default: 52)

4. **Save/Publish**
   - When you save, the system will:
     - Create a recurrence pattern in the database
     - Generate all future course instances automatically
     - Link all instances to this template

### Method 2: Programmatically

```php
use HMWEvents\Helpers\RecurringCourse;

// Create the template post
$template_id = wp_insert_post([
    'post_title' => 'Weekly Calmbirth Course',
    'post_type' => 'educator_course',
    'post_status' => 'publish',
    'post_author' => $educator_user_id,
]);

// Set template meta
update_post_meta($template_id, 'course_start_date', '2025-12-01 18:00:00');
update_post_meta($template_id, 'course_end_date', '2025-12-01 20:00:00');
update_post_meta($template_id, 'course_full_cost', 350);
update_post_meta($template_id, 'course_capacity', 20);
update_post_meta($template_id, 'course_location_address', '123 Main St');

// Create recurring series
$recurrence_id = RecurringCourse::create_recurring_series($template_id, [
    'type' => 'weekly',           // daily, weekly, monthly
    'interval' => 1,              // Every 1 week
    'days' => '1,3',              // Monday and Wednesday
    'start_date' => '2025-12-01', // Start date
    'end_date' => '2026-06-30',   // End date (optional)
    'max_occurrences' => null,    // Or limit by count
]);
```

## Examples

### Example 1: Every Monday for 6 Months

```php
RecurringCourse::create_recurring_series($template_id, [
    'type' => 'weekly',
    'interval' => 1,              // Every week
    'days' => '1',                // Monday only
    'start_date' => '2025-12-01',
    'end_date' => '2026-06-01',
    'max_occurrences' => null,
]);
```

### Example 2: Every Monday & Thursday, 12 courses Total

```php
RecurringCourse::create_recurring_series($template_id, [
    'type' => 'weekly',
    'interval' => 1,
    'days' => '1,4',              // Monday and Thursday
    'start_date' => '2025-12-01',
    'end_date' => null,
    'max_occurrences' => 12,      // Stop after 12 courses
]);
```

### Example 3: Every 2 Weeks on Saturday

```php
RecurringCourse::create_recurring_series($template_id, [
    'type' => 'weekly',
    'interval' => 2,              // Every 2 weeks
    'days' => '6',                // Saturday
    'start_date' => '2025-12-07', // First Saturday
    'max_occurrences' => 26,      // 1 year (52 weeks / 2)
]);
```

### Example 4: First Monday of Every Month

```php
RecurringCourse::create_recurring_series($template_id, [
    'type' => 'monthly',
    'interval' => 1,              // Every month
    'days' => '',                 // Same day-of-month as start_date
    'start_date' => '2025-12-01', // December 1st
    'max_occurrences' => 12,      // 12 months
]);
```

## Managing Recurring Series

### Update All Future courses

```php
// Update cost for all future courses in series
RecurringCourse::update_series($recurrence_id, [
    'course_full_cost' => 400,
    'course_deposit_cost' => 150,
], $future_only = true);
```

### Get All Instances

```php
// Get all courses in the series
$all_courses = RecurringCourse::get_series_instances($recurrence_id, false);

// Get only future courses
$future_courses = RecurringCourse::get_series_instances($recurrence_id, true);
```

### Delete Recurring Series

```php
// Deactivate recurrence pattern only (keep existing instances)
RecurringCourse::delete_series($recurrence_id, false);

// Delete all future instances too
RecurringCourse::delete_series($recurrence_id, true, $future_only = true);

// Delete entire series (all instances)
RecurringCourse::delete_series($recurrence_id, true, $future_only = false);
```

## ACF Integration

### Hooking Into Save

Create a handler to process recurring courses when saved via ACF:

```php
add_action('acf/save_post', function($post_id) {
    // Only process educator_course posts
    if (get_post_type($post_id) !== 'educator_course') {
        return;
    }

    // Check if recurring is enabled
    $is_recurring = get_field('course_is_recurring', $post_id);
    
    if (!$is_recurring) {
        return;
    }

    // Check if recurrence already exists
    $existing_recurrence_id = get_post_meta($post_id, 'course_recurrence_id', true);
    
    if ($existing_recurrence_id) {
        // Update existing recurrence (if needed)
        return;
    }

    // Create new recurring series
    $recurrence_config = [
        'type' => get_field('course_recurrence_type', $post_id) ?: 'weekly',
        'interval' => get_field('course_recurrence_interval', $post_id) ?: 1,
        'days' => implode(',', get_field('course_recurrence_days', $post_id) ?: []),
        'start_date' => get_field('course_start_date', $post_id),
        'end_date' => get_field('course_recurrence_end_date', $post_id),
        'max_occurrences' => get_field('course_max_occurrences', $post_id) ?: 52,
    ];

    RecurringCourse::create_recurring_series($post_id, $recurrence_config);
}, 20);
```

## Important Notes

### Instance Independence

- Each generated instance is a separate `educator_course` post
- Instances can be edited individually without affecting the template
- Bookings are made on individual instances, not the template
- Each instance has its own availability tracking

### Modifying Templates

- Changing the template post does NOT automatically update existing instances
- Use `RecurringCourse::update_series()` to push changes to future instances
- Consider only updating future instances to avoid conflicts with bookings

### Timezone Considerations

- All dates use WordPress timezone settings
- Start/end times are preserved across occurrences
- Date calculations use `DateTime` for accurate recurrence

### Performance

- Generating many instances (100+) may take a few seconds
- Consider limiting `max_occurrences` to reasonable numbers
- Recurrence generation is one-time at creation, not on every page load

## Best Practices

1. **Set Reasonable Limits**: Don't generate years worth of courses at once
2. **Use End Dates**: Prefer `end_date` over very high `max_occurrences`
3. **Template Naming**: Include "Template" in template post titles for clarity
4. **Future Updates**: Always use `$future_only = true` when updating series
5. **Test First**: Create a test series with 3-5 instances before production

## Troubleshooting

### Instances Not Being Created

1. Check template has `course_start_date` set
2. Verify recurrence pattern exists in database
3. Check `is_active` = 1 in recurrence table
4. Review error logs for PHP warnings

### Wrong Dates Generated

1. Verify WordPress timezone settings
2. Check `recurrence_interval` is reasonable
3. For weekly: ensure `recurrence_days` is comma-separated numbers (1-7)
4. Confirm `start_date` format is 'Y-m-d'

### Cannot Edit Template

- Template posts are regular posts - edit normally
- To update all future courses, use `update_series()` method
- Individual instances can always be edited separately
