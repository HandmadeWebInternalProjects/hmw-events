<?php
/**
 * Migration Admin Page.
 *
 * Provides a UI in WordPress admin to run the migration from hmw_educators to HMWEvents.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Add migration admin page.
 */
function hmwevents_add_migration_admin_page()
{
    add_submenu_page(
        'edit.php?post_type=educator_course',
        'Migrate from HMW Educators',
        'Migration Tool',
        'manage_options',
        'hmwevents-migrate-educators',
        'hmwevents_render_migration_admin_page'
    );
}
add_action('admin_menu', 'hmwevents_add_migration_admin_page', 100);

/**
 * Render migration admin page.
 */
function hmwevents_render_migration_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }
    
    // Check if migration should run
    if (isset($_POST['run_migration']) && check_admin_referer('hmwevents_migration_nonce')) {
        // Get selected migration types
        $migration_options = [
            'educators'                  => isset($_POST['migrate_educators']),
            'update_existing_educators'  => isset($_POST['update_existing_educators']),
            'fresh_migration_educators'  => isset($_POST['fresh_migration_educators']),
            'classes'                    => isset($_POST['migrate_classes']),
            'update_existing_classes'    => isset($_POST['update_existing_classes']),
            'fresh_migration_classes'    => isset($_POST['fresh_migration_classes']),
            'bookings'                   => isset($_POST['migrate_bookings']),
            'update_existing_bookings'   => isset($_POST['update_existing_bookings']),
            'fresh_migration_bookings'   => isset($_POST['fresh_migration_bookings']),
            'additional'                 => isset($_POST['migrate_additional']),
            'update_existing_additional' => isset($_POST['update_existing_additional']),
            'fresh_migration_additional' => isset($_POST['fresh_migration_additional']),
        ];
        
        require_once __DIR__ . '/migrate-hmw-educators.php';
        hmwevents_run_hmw_educators_migration($migration_options);
        return;
    }

    // Handle booking meta backfill
    if (isset($_POST['run_backfill_meta']) && check_admin_referer('hmwevents_migration_nonce')) {
        $result = hmwevents_backfill_booking_meta();
        echo '<div class="wrap"><div class="notice notice-success"><p>';
        printf(
            '<strong>Backfill complete.</strong> Processed %d booking(s). Inserted/updated %d meta row(s). Skipped %d (no value in JSON).',
            $result['processed'],
            $result['written'],
            $result['skipped']
        );
        echo '</p></div></div>';
        return;
    }
    
    // Display migration form
    ?>
    <div class="wrap">
        <h1>Migrate from HMW Educators to Handmade Web Event Manager</h1>
        
        <div class="notice notice-warning">
            <p><strong>⚠️ Important: Backup your database before proceeding!</strong></p>
            <p>Select which data types you want to migrate below. Use <strong style="color:#c00;">Fresh migration</strong> to wipe existing data for that type before re-migrating — this cannot be undone.</p>
        </div>
        
        <div class="card" style="max-width: 800px; margin: 20px 0;">
            <h2>Pre-Migration Checklist</h2>
            
            <?php
            $checks = hmwevents_run_pre_migration_checks();
            ?>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Check</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $check): ?>
                    <tr>
                        <td><strong><?php echo esc_html($check['name']); ?></strong></td>
                        <td>
                            <?php if ($check['passed']): ?>
                                <span style="color: green;">✓ Passed</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Failed</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($check['message']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (hmwevents_all_checks_passed($checks)): ?>
                <div class="notice notice-success inline" style="margin: 20px 0;">
                    <p><strong>✓ All pre-migration checks passed!</strong></p>
                    <p>You can proceed with the migration.</p>
                </div>
                
                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field('hmwevents_migration_nonce'); ?>
                    
                    <h3>Select Migration Types</h3>
                    <p style="margin-bottom: 15px;">
                        <label>
                            <input type="checkbox" name="migrate_educators" id="migrate_educators" value="1">
                            <strong>Educators</strong> - Convert educator profiles to WordPress Users with 'educator' role
                        </label>
                        <span style="display: block; margin: 6px 0 0 22px;">
                            <label>
                                <input type="checkbox" name="update_existing_educators" id="update_existing_educators" value="1">
                                Update existing educators (overwrite their data) — <em>off by default, existing educators are skipped</em>
                            </label>
                        </span>
                        <span style="display: block; margin: 4px 0 0 22px;">
                            <label style="color: #c00;">
                                <input type="checkbox" name="fresh_migration_educators" id="fresh_migration_educators" value="1">
                                <strong>Fresh migration</strong> — <em>delete all existing educator users before migrating (cannot be undone!)</em>
                            </label>
                        </span>
                    </p>
                    <p style="margin-bottom: 15px;">
                        <label>
                            <input type="checkbox" name="migrate_classes" id="migrate_classes" value="1" checked>
                            <strong>Classes/Courses</strong> - Convert classes to 'educator_course' Custom Post Type
                        </label>
                        <span style="display: block; margin: 6px 0 0 22px;">
                            <label>
                                <input type="checkbox" name="update_existing_classes" id="update_existing_classes" value="1">
                                Update existing courses (overwrite their data) &mdash; <em>off by default, existing courses are skipped</em>
                            </label>
                        </span>
                        <span style="display: block; margin: 4px 0 0 22px;">
                            <label style="color: #c00;">
                                <input type="checkbox" name="fresh_migration_classes" id="fresh_migration_classes" value="1">
                                <strong>Fresh migration</strong> — <em>delete all existing courses &amp; availability data before migrating (cannot be undone!)</em>
                            </label>
                        </span>
                    </p>
                    <p style="margin-bottom: 15px;">
                        <label>
                            <input type="checkbox" name="migrate_bookings" id="migrate_bookings" value="1" checked>
                            <strong>Bookings</strong> - Migrate bookings, customers, and payment transactions
                        </label>
                        <span style="display: block; margin: 6px 0 0 22px;">
                            <label>
                                <input type="checkbox" name="update_existing_bookings" id="update_existing_bookings" value="1">
                                Update existing bookings (overwrite details &amp; payment records) &mdash; <em>off by default, existing bookings are skipped</em>
                            </label>
                        </span>
                        <span style="display: block; margin: 4px 0 0 22px;">
                            <label style="color: #c00;">
                                <input type="checkbox" name="fresh_migration_bookings" id="fresh_migration_bookings" value="1">
                                <strong>Fresh migration</strong> — <em>delete all existing bookings, customers &amp; payment records before migrating (cannot be undone!)</em>
                            </label>
                        </span>
                    </p>
                    <p style="margin-bottom: 15px;">
                        <label>
                            <input type="checkbox" name="migrate_additional" id="migrate_additional" value="1" checked>
                            <strong>Additional Data</strong> - Social media links, testimonials, and email templates
                        </label>
                        <span style="display: block; margin: 6px 0 0 22px;">
                            <label>
                                <input type="checkbox" name="update_existing_additional" id="update_existing_additional" value="1">
                                Update existing additional data (overwrite social media, testimonials &amp; email templates) &mdash; <em>off by default, existing data is skipped</em>
                            </label>
                        </span>
                        <span style="display: block; margin: 4px 0 0 22px;">
                            <label style="color: #c00;">
                                <input type="checkbox" name="fresh_migration_additional" id="fresh_migration_additional" value="1">
                                <strong>Fresh migration</strong> — <em>delete all existing social media, testimonials &amp; email template user meta before migrating (cannot be undone!)</em>
                            </label>
                        </span>
                    </p>
                    
                    <hr style="margin: 20px 0;">
                    
                    <p>
                        <input type="checkbox" name="confirm_backup" id="confirm_backup" required>
                        <label for="confirm_backup">
                            <strong>I confirm that I have backed up my database</strong>
                        </label>
                    </p>
                    <p>
                        <button type="submit" name="run_migration" class="button button-primary button-large">
                            Run Migration
                        </button>
                    </p>
                </form>
            <?php else: ?>
                <div class="notice notice-error inline" style="margin: 20px 0;">
                    <p><strong>✗ Some pre-migration checks failed.</strong></p>
                    <p>Please resolve the issues above before proceeding.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="card" style="max-width: 800px; margin: 20px 0;">
            <h2>What Gets Migrated?</h2>
            
            <h3>Old System (hmw_educators) → New System (HMWEvents)</h3>
            
            <table class="wp-list-table widefat fixed">
                <thead>
                    <tr>
                        <th>Old System</th>
                        <th>New System</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>wp_educator</code> (table)</td>
                        <td>WordPress Users + User Meta</td>
                    </tr>
                    <tr>
                        <td><code>wp_educator_coursees</code> (table)</td>
                        <td><code>educator_course</code> CPT + Post Meta</td>
                    </tr>
                    <tr>
                        <td><code>wp_educator_class_bookings</code> (table)</td>
                        <td>
                            <code>edu_customer</code> CPT<br>
                            <code>wp_educator_booking_groups</code><br>
                            <code>wp_educator_bookings</code><br>
                            <code>wp_educator_booking_details</code>
                        </td>
                    </tr>
                    <tr>
                        <td>Payment info in booking table</td>
                        <td><code>wp_educator_payment_transactions</code></td>
                    </tr>
                    <tr>
                        <td><code>wp_educator_socialmedia</code></td>
                        <td>User Meta (educator_social_media)</td>
                    </tr>
                    <tr>
                        <td><code>wp_educator_testimonies</code></td>
                        <td>User Meta (educator_testimonials)</td>
                    </tr>
                    <tr>
                        <td><code>wp_educator_email_content</code></td>
                        <td>User Meta (educator_email_*)</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="card" style="max-width: 800px; margin: 20px 0;">
            <h2>Post-Migration Steps</h2>
            <ol>
                <li>Review the migration log for any errors</li>
                <li>Verify educator users can log in</li>
                <li>Check that courses appear correctly</li>
                <li>Verify booking data is complete</li>
                <li>Test the booking system with a test booking</li>
                <li>Once verified, you can deactivate the hmw_educators plugin</li>
                <li><strong>Keep the old tables for at least 30 days as a backup</strong></li>
            </ol>
        </div>

        <div class="card" style="max-width: 800px; margin: 20px 0;">
            <h2>Backfill Booking Meta</h2>
            <p>
                If the <code>educator_booking_meta</code> table is empty (or incomplete) after migration,
                use this to populate it from the JSON data already stored in <code>educator_booking_details</code>.
                Safe to run multiple times &mdash; it will not duplicate rows.
            </p>
            <form method="post">
                <?php wp_nonce_field('hmwevents_migration_nonce'); ?>
                <button type="submit" name="run_backfill_meta" class="button button-secondary">
                    Backfill Booking Meta
                </button>
            </form>
        </div>
    </div>
    
    <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .card h2 {
            margin-top: 0;
        }
        .card h3 {
            margin-top: 20px;
            margin-bottom: 10px;
        }
    </style>
    <script>
    (function() {
        var freshIds = [
            'fresh_migration_educators',
            'fresh_migration_classes',
            'fresh_migration_bookings',
            'fresh_migration_additional'
        ];
        freshIds.forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', function() {
                if (this.checked) {
                    var label = this.closest('label').textContent.trim();
                    if (!confirm('⚠️ WARNING: Fresh migration will permanently delete the existing data for this type before re-migrating.\n\nAre you sure you want to enable this option?')) {
                        this.checked = false;
                    }
                }
            });
        });
    })();
    </script>
    <?php
}

/**
 * Run pre-migration checks.
 */
function hmwevents_run_pre_migration_checks()
{
    global $wpdb;
    $checks = [];
    
    // Check old tables exist
    $old_tables = [
        'educator' => 'Educator profiles table',
        'educator_classes' => 'Classes table',
        'educator_class_bookings' => 'Bookings table',
        'educator_classe_types' => 'Class types table (reference only)',
    ];
    
    foreach ($old_tables as $table => $name) {
        $full_table = $wpdb->prefix . $table;
        $like_pattern = $wpdb->esc_like($full_table);
        $result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like_pattern));
        $exists = ($result === $full_table);
        
        $checks[] = [
            'name' => "Old table: {$name}",
            'passed' => $exists,
            'message' => $exists 
                ? "Table {$full_table} exists" 
                : "Table {$full_table} not found (got: " . ($result ?: 'nothing') . ")",
        ];
    }
    
    // Check new tables exist
    $new_tables = [
        'educator_booking_groups' => 'Booking groups table',
        'educator_bookings' => 'Bookings table',
        'educator_booking_details' => 'Booking details table',
        'educator_payment_transactions' => 'Payment transactions table',
    ];
    
    foreach ($new_tables as $table => $name) {
        $full_table = $wpdb->prefix . $table;
        $like_pattern = $wpdb->esc_like($full_table);
        $result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like_pattern));
        $exists = ($result === $full_table);
        
        $checks[] = [
            'name' => "New table: {$name}",
            'passed' => $exists,
            'message' => $exists 
                ? "Table {$full_table} exists" 
                : "Table {$full_table} not found (got: " . ($result ?: 'nothing') . ") - Run HMWEvents installation first",
        ];
    }
    
    // Check educator role exists
    $role_exists = get_role('educator') !== null;
    $checks[] = [
        'name' => 'Educator role',
        'passed' => $role_exists,
        'message' => $role_exists 
            ? 'Educator role exists' 
            : 'Educator role not found',
    ];
    
    // Check CPTs registered
    $cpt_class_exists = post_type_exists('educator_course');
    $checks[] = [
        'name' => 'Educator Course CPT',
        'passed' => $cpt_class_exists,
        'message' => $cpt_class_exists 
            ? 'educator_course post type registered' 
            : 'educator_course post type not registered',
    ];
    
    $cpt_customer_exists = post_type_exists('edu_customer');
    $checks[] = [
        'name' => 'Customer CPT',
        'passed' => $cpt_customer_exists,
        'message' => $cpt_customer_exists 
            ? 'edu_customer post type registered' 
            : 'edu_customer post type not registered',
    ];
    
    // Check class_type taxonomy registered and has terms
    $taxonomy_exists = taxonomy_exists('course_type');
    $checks[] = [
        'name' => 'Course Type Taxonomy',
        'passed' => $taxonomy_exists,
        'message' => $taxonomy_exists 
            ? 'class_type taxonomy registered' 
            : 'class_type taxonomy not registered',
    ];
    
    if ($taxonomy_exists) {
        $term_count = wp_count_terms(['taxonomy' => 'course_type', 'hide_empty' => false]);
        $checks[] = [
            'name' => 'Course Type Terms',
            'passed' => $term_count >= 4,
            'message' => $term_count >= 4 
                ? "Found {$term_count} course type terms (expected 4)" 
                : "Only found {$term_count} course type terms (expected 4) - Plugin may need reactivation",
        ];
    }
    
    // Check if data exists to migrate
    $educator_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}educator");
    $checks[] = [
        'name' => 'Educators to migrate',
        'passed' => $educator_count > 0,
        'message' => "Found {$educator_count} educators",
    ];
    
    // Only count classes where the end date is not in the past
    $current_date = date('Y-m-d H:i:s');
    $class_count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}educator_coursees WHERE enddate >= %s OR enddate IS NULL",
            $current_date
        )
    );
    $checks[] = [
        'name' => 'Classes to migrate',
        'passed' => true, // Not critical if 0
        'message' => "Found {$class_count} classes (future/current only)",
    ];
    
    // Only count bookings from the past 14 months
    $cutoff_date = date('Y-m-d H:i:s', strtotime('-12 months'));
    $booking_count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}educator_class_bookings WHERE createddate >= %s",
            $cutoff_date
        )
    );
    $checks[] = [
        'name' => 'Bookings to migrate',
        'passed' => true, // Not critical if 0
        'message' => "Found {$booking_count} bookings (from past 14 months)",
    ];
    
    return $checks;
}

/**
 * Check if all critical checks passed.
 */
function hmwevents_all_checks_passed($checks)
{
    foreach ($checks as $check) {
        // Skip informational checks (ones that show counts)
        if (strpos($check['name'], 'to migrate') !== false) {
            continue;
        }
        
        if (!$check['passed']) {
            return false;
        }
    }
    
    return true;
}

/**
 * Backfill educator_booking_meta from existing JSON in educator_booking_details.
 *
 * Iterates every row in educator_booking_details, decodes the stored JSON, and
 * calls BookingDetailsService::save_booking_meta() for each searchable field
 * that has a non-empty value. The service method is an upsert, so running
 * this multiple times is safe.
 *
 * @return array{ processed: int, written: int, skipped: int }
 */
function hmwevents_backfill_booking_meta()
{
    global $wpdb;

    $service = new \HMWEvents\Services\BookingDetailsService();
    $searchable_fields = $service->get_searchable_fields();

    $rows = $wpdb->get_results(
        "SELECT booking_id, form_data FROM {$wpdb->prefix}educator_booking_details"
    );

    $processed = 0;
    $written   = 0;
    $skipped   = 0;

    foreach ($rows as $row) {
        $processed++;
        $form_data = json_decode($row->form_data, true);
        if (!is_array($form_data)) {
            continue;
        }

        foreach ($searchable_fields as $field) {
            if (empty($form_data[$field])) {
                $skipped++;
                continue;
            }

            $result = $service->save_booking_meta((int) $row->booking_id, $field, $form_data[$field]);
            if ($result !== false) {
                $written++;
            }
        }
    }

    return compact('processed', 'written', 'skipped');
}
