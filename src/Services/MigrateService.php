<?php

/**
 * Migration Service — bridges legacy educator_* system to new hmwevents_* system.
 *
 * Provides migration utilities for:
 * - CPTs: educator_course → hmw_event, edu_customer → hmw_registrant
 * - Taxonomies: course_type → hmw_event_type
 * - Bookings: educator_bookings → hmwevents_bookings
 * - Payments: educator_payment_transactions → hmwevents_payment_transactions
 * - User meta: educator_stripe_* → hmw_organizer_stripe_*
 *
 * All methods are idempotent — can be run multiple times safely.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class MigrateService
{
    /**
     * Table name accessor for both old and new tables.
     */
    private function old_table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    private function new_table(string $name): string
    {
        return DatabaseService::get_table_name($name);
    }

    /**
     * Run a full migration. Idempotent — safe to run multiple times.
     *
     * @param int $batch_size Number of posts/rows per batch (default 25).
     * @return array Stats: {events, registrants, bookings, booking_groups, transactions, meta_migrated}
     */
    public function run_full(int $batch_size = 25): array
    {
        $stats = [];
        $stats['events']          = $this->migrate_events($batch_size);
        $stats['registrants']     = $this->migrate_registrants($batch_size);
        $stats['taxonomy_terms']  = $this->migrate_taxonomy_terms();
        $stats['booking_groups']  = $this->migrate_booking_groups($batch_size);
        $stats['bookings']        = $this->migrate_bookings($batch_size);
        $stats['transactions']    = $this->migrate_payment_transactions($batch_size);
        $stats['booking_details'] = $this->migrate_booking_details($batch_size);
        $stats['booking_meta']    = $this->migrate_booking_meta($batch_size);
        $stats['waitlist']        = $this->migrate_waitlist($batch_size);
        $stats['voucher_usage']   = $this->migrate_voucher_usage($batch_size);
        $stats['coupon_usage']    = $this->migrate_coupon_usage($batch_size);
        $stats['user_meta']       = $this->migrate_user_meta();

        return $stats;
    }

    // ================================================================
    // CPT MIGRATION
    // ================================================================

    /**
     * Migrate educator_course posts → hmw_event posts.
     *
     * Creates hmw_event posts for each educator_course post that doesn't
     * already have a corresponding hmw_event (tracked via _legacy_course_id meta).
     */
    public function migrate_events(int $batch_size = 50): int
    {
        global $wpdb;
        $migrated = 0;

        $courses = get_posts([
            'post_type'      => 'educator_course',
            'posts_per_page' => $batch_size,
            'post_status'    => 'any',
        ]);

        foreach ($courses as $course) {
            // Check if already migrated
            $existing = get_posts([
                'post_type'      => 'hmw_event',
                'meta_key'       => '_legacy_course_id',
                'meta_value'     => $course->ID,
                'posts_per_page' => 1,
                'post_status'    => 'any',
            ]);

            if (!empty($existing)) {
                continue;
            }

            $event_id = wp_insert_post([
                'post_type'    => 'hmw_event',
                'post_title'   => $course->post_title,
                'post_content' => $course->post_content,
                'post_excerpt' => $course->post_excerpt,
                'post_status'  => $this->map_course_status($course->post_status),
                'post_author'  => $course->post_author,
                'post_date'    => $course->post_date,
                'post_modified' => $course->post_modified,
                'meta_input'   => [
                    '_legacy_course_id'     => $course->ID,
                    '_event_start_date'     => get_post_meta($course->ID, 'course_start_date', true),
                    '_event_end_date'       => get_post_meta($course->ID, 'course_end_date', true),
                    '_event_capacity'       => get_post_meta($course->ID, '_event_capacity', true),
                    '_event_price'          => get_post_meta($course->ID, '_event_price', true),
                    '_event_deposit'        => get_post_meta($course->ID, '_event_deposit', true),
                    '_event_venue_name'     => get_post_meta($course->ID, 'course_location_address', true),
                    '_event_venue_address'  => get_post_meta($course->ID, 'course_location_address_string', true),
                    '_event_is_recurring'   => get_post_meta($course->ID, 'course_is_recurring', true),
                    '_organizer_id'         => get_post_meta($course->ID, 'course_educator_id', true),
                ],
            ], true);

            if (!is_wp_error($event_id)) {
                // Migrate taxonomies
                $this->migrate_post_taxonomies($course->ID, $event_id);
                // Migrate ACF fields
                $this->migrate_acf_fields($course->ID, $event_id, 'course');
                $migrated++;
            }
        }

        return $migrated;
    }

    /**
     * Migrate edu_customer posts → hmw_registrant posts.
     */
    public function migrate_registrants(int $batch_size = 50): int
    {
        global $wpdb;
        $migrated = 0;

        $customers = get_posts([
            'post_type'      => 'edu_customer',
            'posts_per_page' => $batch_size,
            'post_status'    => 'any',
        ]);

        foreach ($customers as $customer) {
            $existing = get_posts([
                'post_type'      => 'hmw_registrant',
                'meta_key'       => '_legacy_customer_id',
                'meta_value'     => $customer->ID,
                'posts_per_page' => 1,
                'post_status'    => 'any',
            ]);

            if (!empty($existing)) {
                continue;
            }

            $registrant_id = wp_insert_post([
                'post_type'    => 'hmw_registrant',
                'post_title'   => $customer->post_title,
                'post_content' => $customer->post_content,
                'post_status'  => 'publish',
                'post_author'  => $customer->post_author,
                'post_date'    => $customer->post_date,
                'post_modified' => $customer->post_modified,
                'meta_input'   => [
                    '_legacy_customer_id'   => $customer->ID,
                    'registrant_first_name' => get_post_meta($customer->ID, 'customer_first_name', true),
                    'registrant_last_name'  => get_post_meta($customer->ID, 'customer_last_name', true),
                    'registrant_email'      => get_post_meta($customer->ID, 'registrant_email', true),
                    'registrant_phone'      => get_post_meta($customer->ID, 'customer_phone', true),
                    'registrant_address'    => get_post_meta($customer->ID, 'customer_address', true),
                    'registrant_suburb'     => get_post_meta($customer->ID, 'customer_suburb', true),
                    'registrant_state'      => get_post_meta($customer->ID, 'customer_state', true),
                    'registrant_postcode'   => get_post_meta($customer->ID, 'customer_postcode', true),
                ],
            ], true);

            if (!is_wp_error($registrant_id)) {
                $this->migrate_acf_fields($customer->ID, $registrant_id, 'customer');
                $migrated++;
            }
        }

        return $migrated;
    }

    // ================================================================
    // TAXONOMY MIGRATION
    // ================================================================

    public function migrate_taxonomy_terms(): int
    {
        $count = 0;
        $count += $this->copy_terms('course_type', 'hmw_event_type');
        $count += $this->copy_terms('course_state', 'hmw_event_state');
        return $count;
    }

    /**
     * Migrate taxonomies for a single post.
     */
    private function migrate_post_taxonomies(int $old_post_id, int $new_post_id): void
    {
        $mappings = [
            'course_type'  => 'hmw_event_type',
            'course_state' => 'hmw_event_state',
        ];

        foreach ($mappings as $old_tax => $new_tax) {
            $terms = wp_get_object_terms($old_post_id, $old_tax, ['fields' => 'slugs']);
            if (!empty($terms) && !is_wp_error($terms)) {
                wp_set_object_terms($new_post_id, $terms, $new_tax);
            }
        }
    }

    /**
     * Copy terms from one taxonomy to another.
     */
    private function copy_terms(string $from, string $to): int
    {
        $count = 0;
        $terms = get_terms(['taxonomy' => $from, 'hide_empty' => false]);

        if (is_wp_error($terms)) {
            return 0;
        }

        foreach ($terms as $term) {
            if (!term_exists($term->slug, $to)) {
                wp_insert_term($term->name, $to, [
                    'slug'        => $term->slug,
                    'description' => $term->description,
                ]);
                $count++;
            }
        }

        return $count;
    }

    // ================================================================
    // BOOKING DATA MIGRATION
    // ================================================================

    public function migrate_booking_groups(int $batch_size = 50): int
    {
        global $wpdb;
        $old = $this->old_table('educator_booking_groups');
        $new = $this->new_table('booking_groups');
        $migrated = 0;

        $rows = $wpdb->get_results("SELECT * FROM {$old}");

        foreach ($rows as $row) {
            // Resolve old customer_post_id to new registrant_post_id
            $registrant_id = $this->resolve_registrant_id($row->customer_post_id);
            if (!$registrant_id) {
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$new} WHERE booking_reference = %s",
                $row->booking_reference
            ));

            if ($exists) {
                continue;
            }

            $wpdb->insert($new, [
                'registrant_post_id'  => $registrant_id,
                'booking_reference'   => $row->booking_reference,
                'booking_type'        => $row->booking_type ?? 'single',
                'payment_type'        => $row->payment_type ?? 'full',
                'total_bookings'      => $row->total_courses ?? 1,
                'total_amount'        => $row->total_amount,
                'currency'            => $row->currency ?? 'AUD',
                'payment_status'      => $this->map_payment_status($row->payment_status),
                'gst_amount'          => round(($row->total_amount ?? 0) / 11, 2),
                'metadata'            => $row->metadata,
                'created_at'          => $row->created_at,
                'updated_at'          => $row->updated_at,
            ]);

            if ($wpdb->insert_id) {
                update_option('_migrated_booking_group_' . $row->id, $wpdb->insert_id, 'no');
                $migrated++;
            }
        }

        return $migrated;
    }

    public function migrate_bookings(int $batch_size = 50): int
    {
        global $wpdb;
        $old = $this->old_table('educator_bookings');
        $new = $this->new_table('bookings');
        $migrated = 0;

        $rows = $wpdb->get_results("SELECT * FROM {$old}");

        foreach ($rows as $row) {
            $booking_group_id = get_option('_migrated_booking_group_' . $row->booking_group_id);
            $event_id         = $this->resolve_event_id($row->course_post_id);
            $registrant_id    = $this->resolve_registrant_id($row->customer_post_id);

            if (!$booking_group_id || !$event_id || !$registrant_id) {
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$new} WHERE booking_number = %s",
                $row->booking_number
            ));

            if ($exists) {
                continue;
            }

            $wpdb->insert($new, [
                'booking_group_id'      => $booking_group_id,
                'event_post_id'         => $event_id,
                'registrant_post_id'    => $registrant_id,
                'booking_number'        => $row->booking_number,
                'ticket_type'           => $row->ticket_type ?? 'full',
                'ticket_quantity'        => $row->ticket_quantity ?? 1,
                'booking_amount'        => $row->booking_amount,
                'currency'              => $row->currency ?? 'AUD',
                'coupon_code'           => $row->coupon_code,
                'discount_amount'       => $row->discount_amount ?? 0,
                'status'                => $this->map_booking_status($row->status),
                'attendance_status'     => $this->map_attendance_status($row->status),
                'payment_status'        => $this->map_payment_status($row->payment_status),
                'booking_source'        => $row->booking_source ?? 'website',
                'cancelled_at'          => $row->cancelled_at,
                'created_at'            => $row->created_at,
                'updated_at'            => $row->updated_at,
            ]);

            if ($wpdb->insert_id) {
                update_option('_migrated_booking_' . $row->id, $wpdb->insert_id, 'no');
                $migrated++;
            }
        }

        return $migrated;
    }

    public function migrate_booking_details(int $batch_size = 50): int
    {
        global $wpdb;
        $old = $this->old_table('educator_booking_details');
        $new = $this->new_table('booking_details');
        $migrated = 0;

        $rows = $wpdb->get_results("SELECT * FROM {$old}");

        foreach ($rows as $row) {
            $new_booking_id = get_option('_migrated_booking_' . $row->booking_id);
            if (!$new_booking_id) {
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$new} WHERE booking_id = %d",
                $new_booking_id
            ));

            if ($exists) {
                continue;
            }

            $wpdb->insert($new, [
                'booking_id'   => $new_booking_id,
                'form_data'    => $row->form_data,
                'form_version' => $row->form_version ?? '1.0',
                'created_at'   => $row->created_at,
                'updated_at'   => $row->updated_at,
            ]);

            if ($wpdb->insert_id) {
                $migrated++;
            }
        }

        return $migrated;
    }

    public function migrate_booking_meta(int $batch_size = 50): int
    {
        global $wpdb;
        $old = $this->old_table('educator_booking_meta');
        $new = $this->new_table('booking_meta');
        $migrated = 0;

        $rows = $wpdb->get_results("SELECT * FROM {$old}");

        foreach ($rows as $row) {
            $new_booking_id = get_option('_migrated_booking_' . $row->booking_id);
            if (!$new_booking_id) {
                continue;
            }

            $wpdb->insert($new, [
                'booking_id' => $new_booking_id,
                'meta_key'   => $row->meta_key,
                'meta_value' => $row->meta_value,
                'created_at' => $row->created_at ?? current_time('mysql'),
            ]);

            if ($wpdb->insert_id) {
                $migrated++;
            }
        }

        return $migrated;
    }

    public function migrate_payment_transactions(int $batch_size = 50): int
    {
        global $wpdb;
        $old = $this->old_table('educator_payment_transactions');
        $new = $this->new_table('payment_transactions');
        $migrated = 0;

        $rows = $wpdb->get_results("SELECT * FROM {$old}");

        foreach ($rows as $row) {
            $new_group_id = get_option('_migrated_booking_group_' . $row->booking_group_id);
            if (!$new_group_id) {
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$new} WHERE gateway_transaction_id = %s",
                $row->gateway_transaction_id
            ));

            if ($exists) {
                continue;
            }

            $wpdb->insert($new, [
                'booking_group_id'        => $new_group_id,
                'transaction_type'        => $row->transaction_type ?? 'charge',
                'amount'                  => $row->amount,
                'currency'                => $row->currency ?? 'AUD',
                'payment_gateway'          => $row->payment_gateway ?? 'stripe',
                'gateway'                 => $row->gateway ?? 'stripe',
                'gateway_transaction_id'  => $row->gateway_transaction_id,
                'gateway_customer_id'     => $row->gateway_customer_id,
                'gateway_payment_method_id' => $row->gateway_payment_method_id,
                'status'                  => $row->status ?? 'succeeded',
                'error_message'           => $row->error_message,
                'refund_id'               => $row->refund_id,
                'refunded_amount'         => $row->refunded_amount,
                'refunded_at'             => $row->refunded_at,
                'metadata'                => $row->metadata,
                'created_at'              => $row->created_at,
            ]);

            if ($wpdb->insert_id) {
                $migrated++;
            }
        }

        return $migrated;
    }

    public function migrate_waitlist(int $batch_size = 50): int
    {
        global $wpdb;
        $old = $this->old_table('educator_waitlist');
        $new = $this->new_table('waitlist');
        $migrated = 0;

        $rows = $wpdb->get_results("SELECT * FROM {$old}");

        foreach ($rows as $row) {
            $event_id = $this->resolve_event_id($row->course_post_id);
            $registrant_id = $this->resolve_registrant_id($row->customer_post_id);
            if (!$event_id || !$registrant_id) {
                continue;
            }

            $wpdb->insert($new, [
                'event_post_id'      => $event_id,
                'registrant_post_id' => $registrant_id,
                'position'           => $row->position,
                'notified_at'        => $row->notified_at,
                'expires_at'         => $row->expires_at,
                'status'             => $row->status ?? 'waiting',
                'created_at'         => $row->created_at,
                'updated_at'         => $row->updated_at,
            ]);

            if ($wpdb->insert_id) {
                $migrated++;
            }
        }

        return $migrated;
    }

    public function migrate_voucher_usage(int $batch_size = 50): int
    {
        global $wpdb;
        $old = $this->old_table('educator_voucher_usage');
        $new = $this->new_table('voucher_usage');
        $migrated = 0;

        $rows = $wpdb->get_results("SELECT * FROM {$old}");

        foreach ($rows as $row) {
            $new_booking_id = get_option('_migrated_booking_' . $row->booking_id);
            if (!$new_booking_id) {
                continue;
            }

            $wpdb->insert($new, [
                'booking_id'        => $new_booking_id,
                'voucher_code'      => $row->voucher_code,
                'voucher_id'        => $row->voucher_id,
                'voucher_type'      => $row->voucher_type ?? 'single',
                'redeemed_value'    => $row->redeemed_value,
                'original_amount'   => $row->original_amount,
                'discounted_amount' => $row->discounted_amount,
                'applied_at'        => $row->applied_at,
            ]);

            if ($wpdb->insert_id) {
                $migrated++;
            }
        }

        return $migrated;
    }

    public function migrate_coupon_usage(int $batch_size = 50): int
    {
        global $wpdb;
        $old = $this->old_table('educator_coupon_usage');
        $new = $this->new_table('coupon_usage');
        $migrated = 0;

        $rows = $wpdb->get_results("SELECT * FROM {$old}");

        foreach ($rows as $row) {
            $new_booking_id = get_option('_migrated_booking_' . $row->booking_id);
            if (!$new_booking_id) {
                continue;
            }

            $wpdb->insert($new, [
                'coupon_post_id'    => $row->coupon_id,
                'coupon_code'       => $row->coupon_code,
                'booking_id'        => $new_booking_id,
                'registrant_email'  => $row->registrant_email,
                'discount_amount'   => $row->discount_amount,
                'original_amount'   => $row->original_amount,
                'used_at'           => $row->used_at,
            ]);

            if ($wpdb->insert_id) {
                $migrated++;
            }
        }

        return $migrated;
    }

    // ================================================================
    // USER META MIGRATION
    // ================================================================

    /**
     * Migrate educator_* user meta to hmw_organizer_* naming.
     */
    public function migrate_user_meta(): int
    {
        $migrated = 0;
        $meta_map = [
            'educator_stripe_secret' => 'hmw_organizer_stripe_secret',
            'educator_stripe_key'    => 'hmw_organizer_stripe_publishable',
            'educator_payment_type'  => 'hmw_organizer_payment_type',
            'educator_paypal_client_id' => 'hmw_organizer_paypal_client_id',
            'educator_paypal_secret' => 'hmw_organizer_paypal_secret',
        ];

        $users = get_users(['role__in' => ['educator', 'administrator']]);

        foreach ($users as $user) {
            foreach ($meta_map as $old_key => $new_key) {
                $value = get_user_meta($user->ID, $old_key, true);
                if ($value && !get_user_meta($user->ID, $new_key, true)) {
                    // Encrypt the value if it's a Stripe key and not already encrypted
                    $encrypted = \HMWEvents\Helpers\Encryption::encrypt($value);
                    update_user_meta($user->ID, $new_key, $encrypted);
                    $migrated++;
                }
            }

            // Migrate role
            if (in_array('educator', $user->roles, true)) {
                $user->add_role('event_organizer');
                $migrated++;
            }
        }

        return $migrated;
    }

    // ================================================================
    // ACF FIELD MIGRATION
    // ================================================================

    /**
     * Copy ACF fields from old post to new post.
     */
    private function migrate_acf_fields(int $old_post_id, int $new_post_id, string $type): void
    {
        if (!function_exists('get_fields')) {
            return;
        }

        $fields = get_fields($old_post_id);
        if (!is_array($fields)) {
            return;
        }

        foreach ($fields as $key => $value) {
            // Skip internal/relational fields
            if (str_starts_with($key, '_') || $key === 'course_educator_id') {
                continue;
            }
            update_field($key, $value, $new_post_id);
        }
    }

    // ================================================================
    // HELPERS
    // ================================================================

    /**
     * Resolve old customer_post_id to new registrant_post_id.
     */
    private function resolve_registrant_id(int $old_customer_id): ?int
    {
        static $cache = [];

        if (isset($cache[$old_customer_id])) {
            return $cache[$old_customer_id];
        }

        $posts = get_posts([
            'post_type'      => 'hmw_registrant',
            'meta_key'       => '_legacy_customer_id',
            'meta_value'     => $old_customer_id,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);

        $id = $posts[0] ?? null;
        $cache[$old_customer_id] = $id;
        return $id;
    }

    /**
     * Resolve old course_post_id to new event_post_id.
     */
    private function resolve_event_id(int $old_course_id): ?int
    {
        static $cache = [];

        if (isset($cache[$old_course_id])) {
            return $cache[$old_course_id];
        }

        $posts = get_posts([
            'post_type'      => 'hmw_event',
            'meta_key'       => '_legacy_course_id',
            'meta_value'     => $old_course_id,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);

        $id = $posts[0] ?? null;
        $cache[$old_course_id] = $id;
        return $id;
    }

    private function map_course_status(string $old_status): string
    {
        return match ($old_status) {
            'expired' => 'archived',
            'draft'   => 'draft',
            'publish' => 'publish',
            'trash'   => 'trash',
            default   => 'draft',
        };
    }

    private function map_booking_status(string $old_status): string
    {
        return match ($old_status) {
            'confirmed' => 'confirmed',
            'cancelled' => 'cancelled',
            'attended'  => 'confirmed',
            'no_show'   => 'confirmed',
            'pending'   => 'pending',
            default     => 'pending',
        };
    }

    private function map_attendance_status(string $old_status): string
    {
        return match ($old_status) {
            'attended' => 'attended',
            'no_show'  => 'no_show',
            default    => 'registered',
        };
    }

    private function map_payment_status(string $old_status): string
    {
        return in_array($old_status, ['pending', 'paid', 'refunded', 'failed', 'invoiced'], true)
            ? $old_status
            : 'pending';
    }
}
