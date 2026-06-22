<?php
/**
 * Migration script from hmw_educators to Handmade Web Event Manager.
 *
 * This script migrates data from the old educator system to HMWEvents:
 * 
 * OLD SYSTEM (hmw_educators):
 * - wp_educator                    -> Educator profiles in custom table
 * - wp_educator_classes            -> Course events in custom table
 * - wp_educator_class_bookings     -> Booking records in custom table
 * - wp_educator_socialmedia        -> Social media links
 * - wp_educator_testimonies        -> Testimonials
 * - wp_educator_email_content      -> Custom email templates
 * 
 * NEW SYSTEM (HMWEvents):
 * - WordPress Users with 'educator' role
 * - User Meta for educator profile data
 * - educator_course CPT (Custom Post Type)
 * - edu_customer CPT (Custom Post Type)
 * - wp_educator_booking_groups     -> Booking groups
 * - wp_educator_bookings           -> Individual bookings
 * - wp_educator_booking_details    -> Booking questionnaire data
 * - wp_educator_payment_transactions -> Payment records
 *
 * USAGE:
 * 1. Backup your database first!
 * 2. Access via: /wp-admin/admin.php?page=hmwevents-migrate-educators
 * 3. Or run via WP-CLI: wp eval-file migrate-hmw-educators.php
 *
 * @package HMWEvents
 * @since 1.0.0
 */

defined('ABSPATH') || die('Don\'t run this file directly!');

class HMWEvents_HMW_Educators_Migration
{
    private $wpdb;
    private $old_prefix;
    private $new_prefix;
    private $migration_log = [];
    private $errors = [];
    
    // Mapping arrays to track old ID -> new ID
    private $educator_map = [];      // old educator.id -> new user_id
    private $course_map = [];         // old classes.id -> new post_id
    private $customer_map = [];      // old booking.id -> new customer post_id
    private $booking_group_map = []; // old booking.id -> new booking_group_id
    
    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->old_prefix = $wpdb->prefix;
        $this->new_prefix = $wpdb->prefix;
    }

    /**
     * Main migration orchestrator.
     * 
     * @param array $options Migration options (which types to migrate)
     */
    public function migrate($options = [])
    {
        // Default: migrate everything if no options provided
        $defaults = [
            'educators'                  => true,
            'update_existing_educators'  => false,
            'fresh_migration_educators'  => false,
            'classes'                    => true,
            'update_existing_classes'    => false,
            'fresh_migration_classes'    => false,
            'bookings'                   => true,
            'update_existing_bookings'   => false,
            'fresh_migration_bookings'   => false,
            'additional'                 => true,
            'update_existing_additional' => false,
            'fresh_migration_additional' => false,
        ];
        $options = array_merge($defaults, $options);
        
        $this->log('=== Starting Migration from hmw_educators to HMWEvents ===');
        $this->log('Time: ' . current_time('mysql'));
        $this->log('Migration options: ' . implode(', ', array_keys(array_filter($options))));
        
        // Pre-flight checks
        if (!$this->pre_flight_checks()) {
            $this->log('Pre-flight checks failed. Aborting migration.');
            return false;
        }
        
        // Step 1: Migrate Educators (to WordPress Users + User Meta)
        if ($options['educators']) {
            if ($options['fresh_migration_educators']) {
                $this->log('Fresh migration enabled — deleting existing educator data first.');
                $this->delete_educators();
            }
            $this->migrate_educators($options['update_existing_educators']);
        } else {
            $this->log('Skipping educator migration (not selected)');
        }
        
        // Step 2: Migrate Courses (to educator_course CPT)
        if ($options['classes']) {
            if ($options['fresh_migration_classes']) {
                $this->log('Fresh migration enabled — deleting existing course/class data first.');
                $this->delete_classes();
            }
            $this->migrate_classes($options['update_existing_classes']);
        } else {
            $this->log('Skipping classes migration (not selected)');
        }
        
        // Step 3: Migrate Bookings (to booking tables + edu_customer CPT)
        if ($options['bookings']) {
            if ($options['fresh_migration_bookings']) {
                $this->log('Fresh migration enabled — deleting existing booking data first.');
                $this->delete_bookings();
            }
            $this->migrate_bookings($options['update_existing_bookings']);
        } else {
            $this->log('Skipping bookings migration (not selected)');
        }
        
        // Step 4: Migrate additional data (testimonials, social media, etc.)
        if ($options['additional']) {
            if ($options['fresh_migration_additional']) {
                $this->log('Fresh migration enabled — deleting existing additional data first.');
                $this->delete_additional_data();
            }
            $this->migrate_additional_data($options['update_existing_additional']);
        } else {
            $this->log('Skipping additional data migration (not selected)');
        }
        
        // Step 5: Generate migration report
        $this->generate_report();
        
        $this->log('=== Migration Complete ===');
        
        return true;
    }

    /**
     * Pre-flight checks to ensure safe migration.
     */
    private function pre_flight_checks()
    {
        $this->log('Running pre-flight checks...');
        
        // Check if old tables exist
        $old_tables = [
            "{$this->old_prefix}educator",
            "{$this->old_prefix}educator_classes",
            "{$this->old_prefix}educator_class_bookings"
        ];
        
        foreach ($old_tables as $table) {
            $like_pattern = $this->wpdb->esc_like($table);
            $result = $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $like_pattern));
            if ($result !== $table) {
                $this->error("Old table {$table} does not exist! Found: " . ($result ?: 'nothing'));
                return false;
            }
        }
        
        // Check if new tables exist
        $new_tables = [
            "{$this->new_prefix}educator_booking_groups",
            "{$this->new_prefix}educator_bookings",
            "{$this->new_prefix}educator_booking_details"
        ];
        
        foreach ($new_tables as $table) {
            $like_pattern = $this->wpdb->esc_like($table);
            $result = $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $like_pattern));
            if ($result !== $table) {
                $this->error("New table {$table} does not exist! Run HMWEvents installation first. Found: " . ($result ?: 'nothing'));
                return false;
            }
        }
        
        $this->log('Pre-flight checks passed.');
        return true;
    }

    /**
     * Migrate educators from custom table to WordPress Users + User Meta.
     */
    private function migrate_educators($update_existing = false)
    {
        $this->log('--- Migrating Educators ---');
        $this->log('Update existing educators: ' . ($update_existing ? 'yes' : 'no (existing will be skipped)'));
        
        $educators = $this->wpdb->get_results(
            "SELECT * FROM {$this->old_prefix}educator ORDER BY id ASC"
        );
        
        if (empty($educators)) {
            $this->log('No educators found to migrate.');
            return;
        }
        
        $this->log('Found ' . count($educators) . ' educators to migrate.');
        
        foreach ($educators as $educator) {
            $user_id = null;
            
            $is_existing_user = false;

            // Check if educator already has a user_id
            if (!empty($educator->user_id) && $educator->user_id > 0) {
                $existing_user = get_user_by('ID', $educator->user_id);
                if ($existing_user) {
                    $user_id = $educator->user_id;
                    $is_existing_user = true;
                    $this->log("Educator {$educator->id} already linked to user {$user_id}");
                }
            }
            
            // If no user exists, check by email
            if (!$user_id && !empty($educator->email)) {
                $existing_user = get_user_by('email', $educator->email);
                if ($existing_user) {
                    $user_id = $existing_user->ID;
                    $is_existing_user = true;
                    $this->log("Found existing user by email for educator {$educator->id} -> user {$user_id}");
                }
            }

            // Skip data update for existing educators unless explicitly enabled
            if ($is_existing_user && !$update_existing) {
                $this->educator_map[$educator->id] = $user_id;
                $this->log("Skipping existing educator {$educator->id} (user {$user_id}) — update_existing is off.");
                continue;
            }

            // Create new user if needed
            if (!$user_id) {
                $username = sanitize_user($educator->name ?: 'educator_' . $educator->id);
                $email = $educator->email ?: '';
                
                if (empty($email)) {
                    $this->error("Educator {$educator->id} has no email. Skipping user creation.");
                    continue;
                }
                
                $user_id = wp_create_user(
                    $username,
                    wp_generate_password(20, true, true),
                    $email
                );
                
                if (is_wp_error($user_id)) {
                    $this->error("Failed to create user for educator {$educator->id}: " . $user_id->get_error_message());
                    continue;
                }
                
                $this->log("Created new user {$user_id} for educator {$educator->id}");
            }
            
            // Add educator role
            $user = new WP_User($user_id);
            $user->add_role('educator');

            // Migrate educator profile data to user meta
            update_user_meta($user_id, 'educator_id', $educator->id);
            update_user_meta($user_id, 'educator_name', $educator->name);
            update_user_meta($user_id, 'educator_phone', $educator->phone);
            update_user_meta($user_id, 'educator_mobile', $educator->mobile);
            update_user_meta($user_id, 'educator_address_1', $educator->address1);
            update_user_meta($user_id, 'educator_address_2', $educator->address2);
            update_user_meta($user_id, 'educator_suburb', $educator->suburb);
            update_user_meta($user_id, 'educator_state', $educator->state);
            update_user_meta($user_id, 'educator_postcode', $educator->postcode);
            update_user_meta($user_id, 'educator_bio', $educator->bio);
            update_user_meta($user_id, 'educator_accreditation', $educator->accreditation);
            update_user_meta($user_id, 'educator_website', $educator->website);
            update_user_meta($user_id, 'educator_profile_image', $educator->profileImage);
            update_user_meta($user_id, 'educator_hospital', $educator->hospital);
            update_user_meta($user_id, 'educator_main_profile', $educator->mainprofile);
            update_user_meta($user_id, 'educator_payment_type', $educator->payment_type);
            update_user_meta($user_id, 'educator_payment_key', $educator->payment_key);
            update_user_meta($user_id, 'educator_stripe_secret', $educator->stripe_secret ?? '');
            update_user_meta($user_id, 'educator_lat', $educator->lat);
            update_user_meta($user_id, 'educator_lng', $educator->lng);
            update_user_meta($user_id, 'educator_material_link', $educator->materiallink);
            
            // Store mapping
            $this->educator_map[$educator->id] = $user_id;
            
            $this->log("Migrated educator {$educator->id} -> user {$user_id}");
        }
        
        $this->log('Educators migration complete: ' . count($this->educator_map) . ' migrated.');
    }

    /**
     * Migrate classes from custom table to educator_course CPT.
     */
    private function migrate_classes($update_existing = false)
    {
        $this->log('--- Migrating Classes ---');
        $this->log('Update existing courses: ' . ($update_existing ? 'yes' : 'no (existing will be skipped)'));

        // Pre-populate educator_map from already-migrated users so this step
        // works even when educators migration was not run in this pass.
        if (empty($this->educator_map)) {
            $existing_users = get_users([
                'meta_key'     => 'educator_id',
                'meta_compare' => '!=',
                'meta_value'   => '',
                'fields'       => ['ID'],
                'number'       => -1,
            ]);
            foreach ($existing_users as $u) {
                $old_id = get_user_meta($u->ID, 'educator_id', true);
                if ($old_id) {
                    $this->educator_map[(int) $old_id] = $u->ID;
                }
            }
            $this->log('Pre-populated educator_map with ' . count($this->educator_map) . ' already-migrated educators.');
        }

        // Migrate future/current courses AND any past courses that have
        // bookings within the 12-month migration window.
        $booking_cutoff = date('Y-m-d H:i:s', strtotime('-12 months'));

        $classes = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT DISTINCT c.* FROM {$this->old_prefix}educator_classes c
                 WHERE c.enddate >= NOW()
                    OR c.enddate IS NULL
                    OR c.id IN (
                        SELECT DISTINCT b.class_id
                        FROM {$this->old_prefix}educator_class_bookings b
                        WHERE b.createddate >= %s
                    )
                 ORDER BY c.id ASC",
                $booking_cutoff
            )
        );

        if (empty($classes)) {
            $this->log('No classes found to migrate.');
            return;
        }

        $this->log('Found ' . count($classes) . ' courses to migrate (future/current + any with recent bookings).');

        foreach ($classes as $class) {
            // Get educator's WordPress user ID
            $author_id = $this->educator_map[$class->educator_id] ?? 0;

            if (!$author_id) {
                $this->error("Course {$class->id}: Educator {$class->educator_id} not found in mapping. Skipping.");
                continue;
            }
            
            // Create the course post
            // Check if class already migrated (idempotency)
            $existing_posts = get_posts([
                'post_type' => 'educator_course',
                'meta_query' => [
                    [
                        'key' => 'old_course_id',
                        'value' => $class->id,
                        'compare' => '='
                    ]
                ],
                'posts_per_page' => 1,
            ]);

            if (!empty($existing_posts)) {
                $post_id = $existing_posts[0]->ID;
                $this->course_map[$class->id] = $post_id;

                if (!$update_existing) {
                    $this->log("Skipping existing course {$class->id} (post {$post_id}) — update_existing is off.");
                    continue;
                }

                $this->log("Course {$class->id} already migrated to post {$post_id}. Updating meta/terms.");

                // Re-assign taxonomy term (in case it was missing or updated)
                if ($class->event_type_id) {
                    $term_id = \HMWEvents\Taxonomies\CourseType::get_term_id_by_old_id($class->event_type_id);
                    if ($term_id) {
                        wp_set_object_terms($post_id, [(int)$term_id], 'course_type');
                    }
                }

                // Update meta to ensure it's in sync (using ACF field names)
                update_post_meta($post_id, 'old_course_id', $class->id);
                update_post_meta($post_id, 'course_educator_id', $class->educator_id);
                update_post_meta($post_id, 'course_start_date', $class->startdate);
                update_post_meta($post_id, 'course_end_date', $class->enddate);
                update_post_meta($post_id, 'course_location_address', $class->event_venue);
                update_post_meta($post_id, 'booking_notes', $class->event_venue);
                update_post_meta($post_id, 'course_capacity', $class->event_capacity);
                update_post_meta($post_id, 'course_full_cost', $class->event_cost);
                update_post_meta($post_id, 'course_deposit_cost', $class->event_cost_deposit);
                update_post_meta($post_id, 'course_is_external', $class->event_external);
                update_post_meta($post_id, 'course_external_url', $class->event_external_url);
                update_post_meta($post_id, 'course_cutoff_date', $class->cutoff_date ?? null);
                
                // Legacy fields
                update_post_meta($post_id, 'event_type_id', $class->event_type_id);
                update_post_meta($post_id, 'educator_name', $class->educator_name);
                update_post_meta($post_id, 'educator_phone', $class->educator_phone);

                // Ensure availability row exists
                $avail = $this->wpdb->get_row(
                    $this->wpdb->prepare(
                        "SELECT * FROM {$this->new_prefix}educator_course_availability WHERE course_post_id = %d",
                        $post_id
                    )
                );

                if (!$avail) {
                    $this->wpdb->replace(
                        "{$this->new_prefix}educator_course_availability",
                        [
                            'course_post_id' => $post_id,
                            'capacity' => $class->event_capacity,
                            'booked_count' => 0,
                            'available_count' => $class->event_capacity,
                        ],
                        ['%d', '%d', '%d', '%d']
                    );
                }

                $this->log("Updated existing course post {$post_id} for old class {$class->id}");
                continue; // move to next class
            }

            $post_data = [
                'post_title'    => $class->event_name,
                'post_content'  => '', // Classes in old system don't have content
                'post_status'   => 'publish',
                'post_type'     => 'educator_course',
                'post_author'   => $author_id,
                'post_date'     => $class->createddate ?: current_time('mysql'),
                'post_modified' => $class->updateddate ?: current_time('mysql'),
            ];
            
            $post_id = wp_insert_post($post_data, true);
            
            if (is_wp_error($post_id)) {
                $this->error("Failed to create course post for class {$class->id}: " . $post_id->get_error_message());
                continue;
            }
            
            // Assign class type taxonomy term based on old event_type_id
            if ($class->event_type_id) {
                $term_id = \HMWEvents\Taxonomies\CourseType::get_term_id_by_old_id($class->event_type_id);
                if ($term_id) {
                    wp_set_object_terms($post_id, [(int)$term_id], 'course_type');
                    $this->log("  Assigned class type term {$term_id} for event_type_id {$class->event_type_id}");
                } else {
                    $this->error("  No taxonomy term found for event_type_id {$class->event_type_id}");
                }
            }
            
            // Add class meta data (using ACF field names)
            update_post_meta($post_id, 'old_course_id', $class->id); // Legacy tracking field
            update_post_meta($post_id, 'course_educator_id', $class->educator_id);
            update_post_meta($post_id, 'course_start_date', $class->startdate);
            update_post_meta($post_id, 'course_end_date', $class->enddate);
            update_post_meta($post_id, 'course_location_address', $class->event_venue);
            update_post_meta($post_id, 'booking_notes', $class->event_venue);
            update_post_meta($post_id, 'course_capacity', $class->event_capacity);
            update_post_meta($post_id, 'course_full_cost', $class->event_cost);
            update_post_meta($post_id, 'course_deposit_cost', $class->event_cost_deposit);
            update_post_meta($post_id, 'course_is_external', $class->event_external);
            update_post_meta($post_id, 'course_external_url', $class->event_external_url);
            update_post_meta($post_id, 'course_cutoff_date', $class->cutoff_date ?? null);
            
            // Legacy fields for backward compatibility (if needed)
            update_post_meta($post_id, 'event_type_id', $class->event_type_id);
            update_post_meta($post_id, 'educator_name', $class->educator_name);
            update_post_meta($post_id, 'educator_phone', $class->educator_phone);
            
            // Initialize availability cache
            $this->wpdb->replace(
                "{$this->new_prefix}educator_course_availability",
                [
                    'course_post_id' => $post_id,
                    'capacity' => $class->event_capacity,
                    'booked_count' => 0, // Will be updated when bookings are migrated
                    'available_count' => $class->event_capacity,
                ],
                ['%d', '%d', '%d', '%d']
            );
            
            // Store mapping
            $this->course_map[$class->id] = $post_id;
            
            $this->log("Migrated class {$class->id} -> post {$post_id}");
        }
        
        $this->log('Courses migration complete: ' . count($this->course_map) . ' migrated.');
    }

    /**
     * Migrate bookings from custom table to new booking system + customer CPT.
     */
    private function migrate_bookings($update_existing = false)
    {
        $this->log('--- Migrating Bookings ---');
        $this->log('Update existing bookings: ' . ($update_existing ? 'yes' : 'no (existing will be skipped)'));

        // Pre-populate course_map from already-migrated posts so bookings migration
        // works even when classes migration was not run in this pass.
        // Use a direct DB query rather than get_posts() + get_post_meta() to avoid
        // WordPress object-cache issues and N+1 query overhead.
        if (empty($this->course_map)) {
            $rows = $this->wpdb->get_results(
                "SELECT pm.post_id, pm.meta_value AS old_course_id
                 FROM {$this->wpdb->postmeta} pm
                 INNER JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = 'old_course_id'
                   AND p.post_type = 'educator_course'
                   AND p.post_status != 'trash'"
            );
            foreach ($rows as $row) {
                $this->course_map[(int) $row->old_course_id] = (int) $row->post_id;
            }
            $this->log('Pre-populated course_map with ' . count($this->course_map) . ' already-migrated courses.');
        }

        // Only migrate bookings from the past 12 months
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-12 months'));
        
        $bookings = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->old_prefix}educator_class_bookings 
                 WHERE createddate >= %s
                 ORDER BY id ASC",
                $cutoff_date
            )
        );
        
        if (empty($bookings)) {
            $this->log('No bookings found to migrate (within 14 month window).');
            return;
        }
        
        $this->log('Found ' . count($bookings) . ' bookings to migrate (from ' . $cutoff_date . ' onwards).');
        
        foreach ($bookings as $booking) {
            // Get new course post ID
            $class_post_id = $this->course_map[$booking->class_id] ?? 0;
            
            // Fallback: if not in the in-memory map, do a targeted DB lookup.
            // This handles courses migrated after the map was built, or any edge
            // case the pre-population query missed.
            if (!$class_post_id) {
                $class_post_id = (int) $this->wpdb->get_var(
                    $this->wpdb->prepare(
                        "SELECT pm.post_id
                         FROM {$this->wpdb->postmeta} pm
                         INNER JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = 'old_course_id'
                           AND pm.meta_value = %s
                           AND p.post_type = 'educator_course'
                           AND p.post_status != 'trash'
                         LIMIT 1",
                        $booking->class_id
                    )
                );
                if ($class_post_id) {
                    $this->course_map[$booking->class_id] = $class_post_id;
                    $this->log("Booking {$booking->id}: Found course {$booking->class_id} via fallback DB lookup -> post {$class_post_id}");
                }
            }

            if (!$class_post_id) {
                $this->error("Booking {$booking->id}: Course {$booking->class_id} has not been migrated yet — run Classes migration first. Skipping.");
                continue;
            }
            
            // Create or find customer
            $customer_post_id = $this->create_or_find_customer($booking);
            
            if (!$customer_post_id) {
                $this->error("Booking {$booking->id}: Failed to create customer. Skipping.");
                continue;
            }
            
            // Create booking group (idempotent) - check existing by booking_reference
            $booking_reference = $this->generate_booking_reference($booking->id);

            $existing_group_id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->new_prefix}educator_booking_groups WHERE booking_reference = %s",
                    $booking_reference
                )
            );

            if ($existing_group_id) {
                $booking_group_id = (int) $existing_group_id;
                $this->booking_group_map[$booking->id] = $booking_group_id;
                $this->log("Booking group already exists for booking {$booking->id} -> group {$booking_group_id}");
            } else {
                $group_result = $this->wpdb->insert(
                    "{$this->new_prefix}educator_booking_groups",
                    [
                        'customer_post_id' => $customer_post_id,
                        'booking_reference' => $booking_reference,
                        'booking_type' => 'single',
                        'total_courses' => 1,
                        'total_amount' => $booking->cls_booking_cost ?? 0,
                        'payment_status' => $this->map_payment_status($booking->cls_payment_status),
                        'created_at' => $booking->createddate ?: current_time('mysql'),
                        'updated_at' => $booking->updateddate ?: current_time('mysql'),
                    ],
                    ['%d', '%s', '%s', '%d', '%f', '%s', '%s', '%s']
                );

                if (!$group_result) {
                    $this->error("Booking {$booking->id}: Failed to create booking group: " . $this->wpdb->last_error);
                    continue;
                }

                $booking_group_id = $this->wpdb->insert_id;
                $this->booking_group_map[$booking->id] = $booking_group_id;
            }
            
            // Create individual booking
            $booking_number = $this->generate_booking_number($booking->id);
            $ticket_type = ($booking->cls_deposit_ticket > 0) ? 'deposit' : 'full';
            $ticket_quantity = (int) ($booking->cls_ticket_count ?? 1);

            // Defensive normalization for legacy migration anomalies.
            if ($ticket_quantity < 1 || $ticket_quantity > 100) {
                $this->log("Booking {$booking->id}: Invalid cls_ticket_count '{$booking->cls_ticket_count}', defaulting ticket_quantity to 1.");
                $ticket_quantity = 1;
            }
            
            // Insert individual booking (idempotent) - check by booking_number
            $existing_booking_id = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->new_prefix}educator_bookings WHERE booking_number = %s",
                    $booking_number
                )
            );

            $is_existing_booking = false;
            if ($existing_booking_id) {
                $new_booking_id = (int) $existing_booking_id;
                $is_existing_booking = true;
                $this->log("Booking already exists for booking {$booking->id} -> booking {$new_booking_id}");
            } else {
                // Build booking data, omitting deleted_at when null so MySQL
                // defaults to NULL instead of receiving an empty string which
                // fails on DATETIME columns in MySQL strict mode.
                $booking_data = [
                    'booking_group_id' => $booking_group_id,
                    'course_post_id'   => $class_post_id,
                    'customer_post_id' => $customer_post_id,
                    'booking_number'   => $booking_number,
                    'ticket_type'      => $ticket_type,
                    'ticket_quantity'  => $ticket_quantity,
                    'booking_amount'   => $booking->cls_booking_cost ?? 0,
                    'coupon_code'      => $booking->cls_coupon,
                    'discount_amount'  => 0,
                    'status'           => $this->map_booking_status($booking->cls_status),
                    'payment_status'   => $this->map_payment_status($booking->cls_payment_status),
                    'booking_source'   => 'migration',
                    'created_at'       => $booking->createddate ?: current_time('mysql'),
                    'updated_at'       => $booking->updateddate ?: current_time('mysql'),
                ];
                $booking_formats = ['%d', '%d', '%d', '%s', '%s', '%d', '%f', '%s', '%f', '%s', '%s', '%s', '%s', '%s'];

                if (!empty($booking->soft_delete)) {
                    $booking_data['deleted_at'] = current_time('mysql');
                    $booking_formats[]          = '%s';
                }

                $booking_result = $this->wpdb->insert(
                    "{$this->new_prefix}educator_bookings",
                    $booking_data,
                    $booking_formats
                );

                if (!$booking_result) {
                    $this->error("Booking {$booking->id}: Failed to create booking record: " . $this->wpdb->last_error);
                    continue;
                }

                $new_booking_id = $this->wpdb->insert_id;
            }
            
            // Skip details/payment/availability for existing bookings unless update is enabled
            if ($is_existing_booking && !$update_existing) {
                $this->log("Skipping existing booking {$booking->id} details/payment/availability — update_existing is off.");
                $this->log("Booking {$booking->id} -> booking_group {$booking_group_id}, booking {$new_booking_id} (already existed)");
                continue;
            }

            // Create booking details (questionnaire)
            $this->migrate_booking_details($booking, $new_booking_id);
            
            // Create payment transaction if paid
            if ($booking->cls_payment_status === 'paid') {
                $this->migrate_payment_transaction($booking, $booking_group_id);
            }
            
            // Update course availability
            $this->update_class_availability($class_post_id, $ticket_quantity);
            
            $this->log("Migrated booking {$booking->id} -> booking_group {$booking_group_id}, booking {$new_booking_id}");
        }
        
        $this->log('Bookings migration complete: ' . count($this->booking_group_map) . ' migrated.');
    }

    /**
     * Create or find customer CPT from booking data.
     */
    private function create_or_find_customer($booking)
    {
        $email = $booking->cls_email ?? '';
        
        if (empty($email)) {
            return null;
        }

        // Check in-memory cache first — avoids repeated DB queries for customers
        // who appear in multiple bookings, and prevents WP_Query cache misses
        // that can cause duplicate customer posts within a single migration run.
        if (isset($this->customer_map[$email])) {
            return $this->customer_map[$email];
        }

        // Direct SQL lookup so we bypass WP_Query's object cache entirely.
        $existing_id = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT pm.post_id
                 FROM {$this->wpdb->postmeta} pm
                 INNER JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = 'customer_email'
                   AND pm.meta_value = %s
                   AND p.post_type = 'edu_customer'
                   AND p.post_status != 'trash'
                 LIMIT 1",
                $email
            )
        );

        if ($existing_id) {
            $this->customer_map[$email] = $existing_id;
            return $existing_id;
        }
        
        // Create new customer
        $customer_name = trim(($booking->cls_mothers_name ?? '') . ' ' . ($booking->cls_mothers_surname ?? ''));
        if (empty($customer_name)) {
            $customer_name = $email;
        }
        
        $post_data = [
            'post_title' => $customer_name,
            'post_type' => 'edu_customer',
            'post_status' => 'publish',
            'post_date' => $booking->createddate ?: current_time('mysql'),
        ];
        
        $customer_post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($customer_post_id)) {
            $this->error("Failed to create customer: " . $customer_post_id->get_error_message());
            return null;
        }
        
        // Add customer meta
        update_post_meta($customer_post_id, 'customer_email', $email);
        update_post_meta($customer_post_id, 'customer_phone', $booking->cls_phone ?? '');
        update_post_meta($customer_post_id, 'customer_first_name', $booking->cls_mothers_name ?? '');
        update_post_meta($customer_post_id, 'customer_last_name', $booking->cls_mothers_surname ?? '');
        update_post_meta($customer_post_id, 'customer_pronoun', $booking->cls_mothers_pronoun ?? '');
        update_post_meta($customer_post_id, 'customer_address', $booking->cls_address ?? '');
        update_post_meta($customer_post_id, 'customer_suburb', $booking->cls_town ?? '');
        update_post_meta($customer_post_id, 'customer_state', $booking->cls_state ?? '');
        update_post_meta($customer_post_id, 'customer_postcode', $booking->cls_postcode ?? '');
        
        $this->customer_map[$email] = $customer_post_id;
        
        return $customer_post_id;
    }

    /**
     * Migrate booking details (questionnaire data).
     * 
     * The educator_booking_details table stores all questionnaire responses as
     * a single JSON blob in the form_data column.
     */
    private function migrate_booking_details($old_booking, $new_booking_id)
    {
        $form_data = [
            'partner_name'           => $old_booking->cls_partner_name ?? null,
            'occupation'             => $old_booking->cls_occupation ?? null,
            'partner_occupation'     => $old_booking->cls_partner_occupation ?? null,
            'health_fund'            => $old_booking->cls_health_fund ?? null,
            'due_date'               => $old_booking->cls_due_date ?? null,
            'model_of_care'          => $old_booking->cls_model_of_care ?? null,
            'caregiver'              => $old_booking->cls_caregiver ?? null,
            'hospital_location'      => $old_booking->cls_hospital_location ?? null,
            'dietary_requirements'   => $old_booking->cls_diet ?? null,
            'medical_conditions'     => $old_booking->cls_medical ?? null,
            'medications'            => $old_booking->cls_medications ?? null,
            'disabilities'           => $old_booking->cls_disability ?? null,
            'first_baby'             => $old_booking->cls_first ?? null,
            'birth_trauma'           => $old_booking->cls_birth_trauma ?? null,
            'fears'                  => $old_booking->cls_fears ?? null,
            'feelings'               => $old_booking->cls_feelings ?? null,
            'expectations'           => $old_booking->cls_get_out_of ?? null,
            'hear_about'             => $old_booking->cls_hear_about ?? null,
            'mailing_agreement'      => (int) ($old_booking->cls_mailing_agreement ?? 0),
            'additional_information' => $old_booking->cls_additional_information ?? null,
        ];

        $result = $this->wpdb->insert(
            "{$this->new_prefix}educator_booking_details",
            [
                'booking_id'   => $new_booking_id,
                'form_data'    => wp_json_encode($form_data),
                'form_version' => '1.0',
                'created_at'   => $old_booking->createddate ?: current_time('mysql'),
                'updated_at'   => $old_booking->updateddate ?: current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
        
        if (!$result) {
            $this->error("Failed to create booking details for booking {$new_booking_id}: " . $this->wpdb->last_error);
        }
    }

    /**
     * Migrate payment transaction.
     */
    private function migrate_payment_transaction($old_booking, $booking_group_id)
    {
        $result = $this->wpdb->insert(
            "{$this->new_prefix}educator_payment_transactions",
            [
                'booking_group_id' => $booking_group_id,
                'transaction_type' => 'charge',
                'amount' => $old_booking->cls_booking_cost ?? 0,
                'currency' => 'AUD',
                'payment_gateway' => 'stripe',
                'gateway_transaction_id' => $old_booking->cls_payment_id ?? null,
                'gateway_customer_id' => $old_booking->cls_stripe_customer_id ?? null,
                'gateway_payment_method_id' => $old_booking->cls_payment_token ?? null,
                'status' => ($old_booking->cls_payment_captured ?? 0) ? 'succeeded' : 'pending',
                'error_message' => null,
                'metadata' => json_encode(['migrated_from' => $old_booking->id]),
                'created_at' => $old_booking->createddate ?: current_time('mysql'),
            ],
            ['%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        if (!$result) {
            $this->error("Failed to create payment transaction for booking group {$booking_group_id}: " . $this->wpdb->last_error);
        }
    }

    /**
     * Migrate additional data (testimonials, social media, email templates).
     */
    private function migrate_additional_data($update_existing = false)
    {
        $this->log('--- Migrating Additional Data ---');
        $this->log('Update existing additional data: ' . ($update_existing ? 'yes' : 'no (existing will be skipped)'));
        
        // Migrate social media
        $this->migrate_social_media($update_existing);
        
        // Migrate testimonials
        $this->migrate_testimonials($update_existing);
        
        // Migrate email templates
        $this->migrate_email_templates($update_existing);
        
        $this->log('Additional data migration complete.');
    }

    /**
     * Migrate social media links to user meta.
     */
    private function migrate_social_media($update_existing = false)
    {
        $social_media = $this->wpdb->get_results(
            "SELECT * FROM {$this->old_prefix}educator_socialmedia"
        );
        
        if (empty($social_media)) {
            $this->log('No social media data found.');
            return;
        }
        
        foreach ($social_media as $sm) {
            $user_id = $this->educator_map[$sm->educator_id] ?? 0;
            
            if (!$user_id) {
                continue;
            }
            
            // Store as array in user meta
            $existing = get_user_meta($user_id, 'educator_social_media', true);
            if (!is_array($existing)) {
                $existing = [];
            }

            // Skip if educator already has social media data and update is off
            if (!empty($existing) && !$update_existing) {
                continue;
            }

            $existing[] = [
                'type' => $sm->socialtype,
                'link' => $sm->link,
            ];
            
            update_user_meta($user_id, 'educator_social_media', $existing);
        }
        
        $this->log('Social media migration complete.');
    }

    /**
     * Migrate testimonials to user meta.
     */
    private function migrate_testimonials($update_existing = false)
    {
        $testimonials = $this->wpdb->get_results(
            "SELECT * FROM {$this->old_prefix}educator_testimonies"
        );
        
        if (empty($testimonials)) {
            $this->log('No testimonials found.');
            return;
        }
        
        foreach ($testimonials as $testimony) {
            $user_id = $this->educator_map[$testimony->educator_id] ?? 0;
            
            if (!$user_id) {
                continue;
            }
            
            // Store as array in user meta
            $existing = get_user_meta($user_id, 'educator_testimonials', true);
            if (!is_array($existing)) {
                $existing = [];
            }

            // Skip if educator already has testimonials and update is off
            if (!empty($existing) && !$update_existing) {
                continue;
            }

            $existing[] = [
                'testimony' => $testimony->testimony,
                'name' => $testimony->testimony_name,
                'created' => $testimony->createddate,
            ];
            
            update_user_meta($user_id, 'educator_testimonials', $existing);
        }
        
        $this->log('Testimonials migration complete.');
    }

    /**
     * Migrate email templates to user meta.
     */
    private function migrate_email_templates($update_existing = false)
    {
        $email_templates = $this->wpdb->get_results(
            "SELECT * FROM {$this->old_prefix}educator_email_content"
        );
        
        if (empty($email_templates)) {
            $this->log('No email templates found.');
            return;
        }
        
        foreach ($email_templates as $template) {
            $user_id = $this->educator_map[$template->educator_id] ?? 0;
            
            if (!$user_id) {
                continue;
            }

            // Skip if educator already has email templates and update is off
            if (!$update_existing && get_user_meta($user_id, 'educator_email_booked', true) !== '') {
                continue;
            }

            update_user_meta($user_id, 'educator_email_booked', $template->email_content_booked);
            update_user_meta($user_id, 'educator_email_postclass', $template->email_content_postclass);
            update_user_meta($user_id, 'educator_email_postclass30', $template->email_content_postclass30);
            update_user_meta($user_id, 'educator_email_reminder', $template->email_content_reminder);
        }
        
        $this->log('Email templates migration complete.');
    }

    /**
     * Delete all migrated educators (WordPress users with 'educator' role + their meta).
     */
    private function delete_educators()
    {
        $this->log('Deleting existing educator users...');

        $users = get_users([
            'role'   => 'educator',
            'fields' => ['ID'],
            'number' => -1,
        ]);

        if (empty($users)) {
            $this->log('No existing educator users found to delete.');
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        $deleted = 0;
        foreach ($users as $u) {
            if (wp_delete_user($u->ID)) {
                $deleted++;
            } else {
                $this->error("Failed to delete user {$u->ID}");
            }
        }

        $this->log("Deleted {$deleted} educator user(s).");
    }

    /**
     * Delete all migrated courses (educator_course posts + availability rows).
     */
    private function delete_classes()
    {
        $this->log('Deleting existing educator_course posts...');

        $post_ids = get_posts([
            'post_type'      => 'educator_course',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'any',
        ]);

        if (empty($post_ids)) {
            $this->log('No existing educator_course posts found to delete.');
            return;
        }

        foreach ($post_ids as $post_id) {
            wp_delete_post($post_id, true); // true = force delete, bypass trash
        }

        // Delete availability rows for those posts
        foreach ($post_ids as $post_id) {
            $this->wpdb->delete(
                "{$this->new_prefix}educator_course_availability",
                ['course_post_id' => $post_id],
                ['%d']
            );
        }

        $this->log('Deleted ' . count($post_ids) . ' educator_course post(s) and their availability rows.');
    }

    /**
     * Delete all migrated booking data (four tables + edu_customer posts).
     */
    private function delete_bookings()
    {
        $this->log('Deleting existing booking data...');

        // Disable FK checks so TRUNCATE isn't silently blocked by child-table
        // foreign key constraints (MySQL ignores TRUNCATE on referenced tables
        // without raising an error, leaving all rows intact).
        $this->wpdb->query("SET FOREIGN_KEY_CHECKS = 0");

        $tables = [
            "{$this->new_prefix}educator_booking_details",
            "{$this->new_prefix}educator_booking_meta",
            "{$this->new_prefix}educator_payment_transactions",
            "{$this->new_prefix}educator_bookings",
            "{$this->new_prefix}educator_booking_groups",
        ];

        foreach ($tables as $table) {
            $this->wpdb->query("TRUNCATE TABLE `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->log("Truncated {$table}");
        }

        $this->wpdb->query("SET FOREIGN_KEY_CHECKS = 1");

        // Delete customer CPT posts
        $customer_ids = get_posts([
            'post_type'      => 'edu_customer',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'any',
        ]);

        foreach ($customer_ids as $post_id) {
            wp_delete_post($post_id, true);
        }

        $this->log('Deleted ' . count($customer_ids) . ' edu_customer post(s).');
    }

    /**
     * Delete additional migrated data (social media, testimonials, email templates) from user meta.
     */
    private function delete_additional_data()
    {
        $this->log('Deleting existing additional data (social media, testimonials, email templates) from user meta...');

        $meta_keys = [
            'educator_social_media',
            'educator_testimonials',
            'educator_email_booked',
            'educator_email_postclass',
            'educator_email_postclass30',
            'educator_email_reminder',
        ];

        foreach ($meta_keys as $key) {
            $this->wpdb->delete(
                $this->wpdb->usermeta,
                ['meta_key' => $key],
                ['%s']
            );
        }

        $this->log('Additional data user meta deleted.');
    }

    /**
     * Update course availability cache.
     */
    private function update_class_availability($class_post_id, $ticket_count)
    {
        $current = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->new_prefix}educator_course_availability WHERE course_post_id = %d",
                $class_post_id
            )
        );
        
        if ($current) {
            $new_booked = $current->booked_count + $ticket_count;
            $new_available = max(0, $current->capacity - $new_booked);
            
            $this->wpdb->update(
                "{$this->new_prefix}educator_course_availability",
                [
                    'booked_count' => $new_booked,
                    'available_count' => $new_available,
                ],
                ['course_post_id' => $class_post_id],
                ['%d', '%d'],
                ['%d']
            );
        }
    }

    /**
     * Helper functions.
     */
    private function generate_booking_reference($old_id)
    {
        return 'MIG-' . str_pad($old_id, 8, '0', STR_PAD_LEFT);
    }

    private function generate_booking_number($old_id)
    {
        return 'BOOK-MIG-' . str_pad($old_id, 8, '0', STR_PAD_LEFT);
    }

    private function map_payment_status($old_status)
    {
        $map = [
            'pending' => 'pending',
            'paid' => 'paid',
            'refunded' => 'refunded',
            'failed' => 'failed',
        ];
        
        return $map[$old_status] ?? 'pending';
    }

    private function map_booking_status($old_status)
    {
        // Old system didn't track booking status separately
        // Default to 'confirmed' for migrated bookings
        return 'confirmed';
    }

    /**
     * Logging functions.
     */
    private function log($message)
    {
        $this->migration_log[] = '[' . current_time('H:i:s') . '] ' . $message;
        error_log('HMWEvents Migration: ' . $message);
    }

    private function error($message)
    {
        $this->errors[] = $message;
        $this->log('ERROR: ' . $message);
    }

    /**
     * Generate migration report.
     */
    private function generate_report()
    {
        $this->log('--- Migration Report ---');
        $this->log('Educators migrated: ' . count($this->educator_map));
        $this->log('Courses migrated: ' . count($this->course_map));
        $this->log('Customers created: ' . count($this->customer_map));
        $this->log('Booking groups created: ' . count($this->booking_group_map));
        $this->log('Errors encountered: ' . count($this->errors));
        
        if (!empty($this->errors)) {
            $this->log('Error details:');
            foreach ($this->errors as $error) {
                $this->log('  - ' . $error);
            }
        }
    }

    /**
     * Get migration log.
     */
    public function get_log()
    {
        return $this->migration_log;
    }

    /**
     * Get errors.
     */
    public function get_errors()
    {
        return $this->errors;
    }
}

/**
 * Run migration if called directly or via admin page.
 */
if (!function_exists('hmwevents_run_hmw_educators_migration')) {
    function hmwevents_run_hmw_educators_migration($options = [])
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        $migration = new HMWEvents_HMW_Educators_Migration();
        $migration->migrate($options);
        
        // Display log
        echo '<div class="wrap">';
        echo '<h1>HMW Educators to HMWEvents Migration</h1>';
        echo '<div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">';
        echo '<h2>Migration Log</h2>';
        echo '<pre style="background: #f5f5f5; padding: 15px; overflow-x: auto;">';
        echo implode("\n", $migration->get_log());
        echo '</pre>';
        echo '</div>';
        
        if (!empty($migration->get_errors())) {
            echo '<div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #c00; border-left: 4px solid #c00;">';
            echo '<h2 style="color: #c00;">Errors</h2>';
            echo '<ul>';
            foreach ($migration->get_errors() as $error) {
                echo '<li style="color: #c00;">' . esc_html($error) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
        
        echo '</div>';
    }
}
