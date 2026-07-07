<?php

/**
 * BookingFields — single source of truth for all booking form fields.
 *
 * @package HMWEvents
 * @since 1.1.9
 */

namespace HMWEvents\Config;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Canonical registry of every field that flows through the booking system.
 *
 * Each entry in all() is keyed by its canonical display key and contains:
 *
 *   label                string   Human-readable column/label (CSV, email, admin modal).
 *   type                 string   Input type: text | email | tel | date | textarea | select | checkbox.
 *   source               string   'customer_meta'   → stored as WP post meta on the customer CPT.
 *                                 'booking_details' → stored as JSON in educator_booking_details.form_data.
 *   meta_key             string|null  WP post-meta key (customer_meta fields and partner_name).
 *   form_name            string   HTML name= attribute used in the PHP manual-booking form.
 *   form_description     string   (checkbox only) Secondary text shown beside the checkbox in admin form.
 *   options              array    (select only) Ordered map of value => label.
 *   manual_required      bool     Whether the field is required in the admin manual-booking form.
 *   in_csv               bool     Include as an ordered column in the attendee CSV export.
 *   in_email             bool     Include in the {all_fields} email merge-tag table.
 *
 *   Frontend form properties (null / omitted = not shown in the public booking form):
 *   frontend_section     string|null  Section key from frontend_sections() (null = admin-only).
 *   frontend_width       string       'half' (two per row) or 'full' (full row). Default: 'half'.
 *   frontend_required    bool         Adds required attribute and * indicator on the frontend.
 *   frontend_label       string|null  Override label text for the frontend form only.
 *   frontend_placeholder string       Placeholder text for text/textarea inputs.
 *   frontend_input       string|null  'radio' overrides 'select' rendering on the frontend.
 *   frontend_rows        int|null     Rows attribute for textarea inputs (default 3).
 */
class BookingFields
{
    /**
     * All known booking fields in canonical display order.
     *
     * Results are statically cached so the array is only constructed once per request.
     *
     * @return array<string, array>  Associative array keyed by canonical field key.
     */
    public static function all(): array
    {
        static $fields = null;

        if ($fields !== null) {
            return $fields;
        }

        $fields = [

            // ── Contact fields (customer_meta source) ──────────────────────────────
            // Stored as WP post meta on the customer CPT.
            // The manual-booking form uses customer_* input names; the frontend form
            // uses the canonical key as the HTML input name.

            'mothers_first_name' => [
                'label'                => "Mother's First Name",
                'type'                 => 'text',
                'source'               => 'customer_meta',
                'meta_key'             => 'customer_first_name',
                'form_name'            => 'customer_first_name',
                'manual_required'      => true,
                'in_csv'               => true,
                'in_email'             => true,
                'frontend_section'     => 'your_information',
                'frontend_width'       => 'half',
                'frontend_required'    => true,
                'frontend_label'       => null,
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],

            'mothers_last_name' => [
                'label'                => "Mother's Last Name",
                'type'                 => 'text',
                'source'               => 'customer_meta',
                'meta_key'             => 'customer_last_name',
                'form_name'            => 'customer_last_name',
                'manual_required'      => true,
                'in_csv'               => true,
                'in_email'             => true,
                'frontend_section'     => 'your_information',
                'frontend_width'       => 'half',
                'frontend_required'    => true,
                'frontend_label'       => null,
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],

            'email' => [
                'label'                => 'Email',
                'type'                 => 'email',
                'source'               => 'customer_meta',
                'meta_key'             => 'registrant_email',
                'form_name'            => 'registrant_email',
                'manual_required'      => true,
                'in_csv'               => true,
                'in_email'             => true,
                'frontend_section'     => 'your_information',
                'frontend_width'       => 'half',
                'frontend_required'    => true,
                'frontend_label'       => null,
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],

            'phone' => [
                'label'                => 'Phone',
                'type'                 => 'tel',
                'source'               => 'customer_meta',
                'meta_key'             => 'customer_phone',
                'form_name'            => 'customer_phone',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => true,
                'frontend_section'     => 'your_information',
                'frontend_width'       => 'half',
                'frontend_required'    => true,
                'frontend_label'       => null,
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],

            'street_address' => [
                'label'                => 'Street Address',
                'type'                 => 'text',
                'source'               => 'customer_meta',
                'meta_key'             => 'customer_address',
                'form_name'            => 'street_address',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => false,
                'frontend_section'     => 'address',
                'frontend_width'       => 'full',
                'frontend_required'    => true,
                'frontend_label'       => null,
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],

            'city' => [
                'label'                => 'Suburb / City',
                'type'                 => 'text',
                'source'               => 'customer_meta',
                'meta_key'             => 'customer_suburb',
                'form_name'            => 'city',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => false,
                'frontend_section'     => 'address',
                'frontend_width'       => 'half',
                'frontend_required'    => true,
                'frontend_label'       => 'City/Town',
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],

            'postcode' => [
                'label'                => 'Postcode',
                'type'                 => 'text',
                'source'               => 'customer_meta',
                'meta_key'             => 'customer_postcode',
                'form_name'            => 'postcode',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => false,
                'frontend_section'     => 'address',
                'frontend_width'       => 'half',
                'frontend_required'    => true,
                'frontend_label'       => null,
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],

            // ── Questionnaire / detail fields (booking_details source) ─────────────
            // Stored as JSON in educator_booking_details.form_data.
            // partner_name is also written to customer post meta as a convenience.

            'partner_name' => [
                'label'                => 'Partner / Support Person',
                'type'                 => 'text',
                'source'               => 'booking_details',
                'meta_key'             => 'partner_name',   // also in customer CPT meta
                'form_name'            => 'partner_name',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => true,
                'frontend_section'     => 'your_information',
                'frontend_width'       => 'full',
                'frontend_required'    => true,
                'frontend_label'       => "Partner/Support Person's Name",
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],

            'health_fund' => [
                'label'                => 'Health Fund',
                'type'                 => 'text',
                'source'               => 'booking_details',
                'meta_key'             => null,
                'form_name'            => 'health_fund',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => true,
                'frontend_section'     => 'extra_details',
                'frontend_width'       => 'half',
                'frontend_required'    => true,
                'frontend_label'       => 'Health Fund Name',
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],

            'due_date' => [
                'label'                => 'Due Date',
                'type'                 => 'date',
                'source'               => 'booking_details',
                'meta_key'             => null,
                'form_name'            => 'due_date',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => true,
                'frontend_section'     => 'extra_details',
                'frontend_width'       => 'half',
                'frontend_required'    => true,
                'frontend_label'       => null,
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],

            'dietary_requirements' => [
                'label'                => 'Dietary / Allergies',
                'type'                 => 'textarea',
                'source'               => 'booking_details',
                'meta_key'             => null,
                'form_name'            => 'dietary_requirements',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => true,
                'frontend_section'     => 'extra_details',
                'frontend_width'       => 'full',
                'frontend_required'    => false,
                'frontend_label'       => 'Any Food Allergies?',
                'frontend_placeholder' => 'Please list any food allergies or dietary restrictions',
                'frontend_input'       => null,
                'frontend_rows'        => 3,
            ],

            'first_baby' => [
                'label'                => 'First Baby?',
                'type'                 => 'select',
                'source'               => 'booking_details',
                'meta_key'             => null,
                'form_name'            => 'first_baby',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => true,
                'options'              => ['' => '— Select —', 'yes' => 'Yes', 'no' => 'No'],
                'frontend_section'     => 'extra_details',
                'frontend_width'       => 'full',
                'frontend_required'    => false,
                'frontend_label'       => 'Is this your first baby?',
                'frontend_placeholder' => '',
                'frontend_input'       => 'radio',  // renders as radio group, not <select>
                'frontend_rows'        => null,
            ],

            'special_considerations' => [
                'label'                => 'Special Considerations',
                'type'                 => 'textarea',
                'source'               => 'booking_details',
                'meta_key'             => null,
                'form_name'            => 'special_considerations',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => true,
                'frontend_section'     => 'extra_details',
                'frontend_width'       => 'full',
                'frontend_required'    => false,
                'frontend_label'       => null,
                'frontend_placeholder' => 'Do you have any special considerations that your Educator needs to know about? For example any medical or psychological condition, a disability, previous birth trauma, or do you identify as LGBTQIA+?',
                'frontend_input'       => null,
                'frontend_rows'        => 5,
            ],

            'mailing_agreement' => [
                'label'                => 'Mailing Agreement',
                'type'                 => 'checkbox',
                'source'               => 'booking_details',
                'meta_key'             => null,
                'form_name'            => 'mailing_agreement',
                'form_description'     => 'Customer agrees to receive emails',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => false,
                'frontend_section'     => 'agreements',
                'frontend_width'       => 'full',
                'frontend_required'    => false,
                'frontend_label'       => 'Yes, add me to the mailing list',
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],

            'terms_accepted' => [
                'label'                => 'Terms Accepted',
                'type'                 => 'checkbox',
                'source'               => 'booking_details',
                'meta_key'             => null,
                'form_name'            => 'terms_accepted',
                'form_description'     => 'Customer accepts terms & conditions',
                'manual_required'      => false,
                'in_csv'               => true,
                'in_email'             => false,
                // Frontend label rendered via BookingForm::get_field_label_html() because
                // it contains a dynamic T&C hyperlink (see hmwevents_booking_terms_conditions_url filter).
                'frontend_section'     => 'agreements',
                'frontend_width'       => 'full',
                'frontend_required'    => true,
                'frontend_label'       => null,
                'frontend_placeholder' => '',
                'frontend_input'       => null,
                'frontend_rows'        => null,
            ],
        ];

        return $fields;
    }

    // ── Filtered subsets ────────────────────────────────────────────────────────

    /**
     * Fields stored as WP post meta on the customer CPT.
     *
     * @return array<string, array>
     */
    public static function from_customer_meta(): array
    {
        return array_filter(self::all(), fn($f) => $f['source'] === 'customer_meta');
    }

    /**
     * Fields stored in educator_booking_details.form_data JSON.
     *
     * @return array<string, array>
     */
    public static function from_booking_details(): array
    {
        return array_filter(self::all(), fn($f) => $f['source'] === 'booking_details');
    }

    /**
     * Fields that have a WP post-meta fallback key (used for CSV / email enrichment).
     * Includes all customer_meta fields plus any booking_details fields that also
     * write to customer post meta (currently: partner_name).
     *
     * @return array<string, array>
     */
    public static function with_meta_fallback(): array
    {
        return array_filter(self::all(), fn($f) => !empty($f['meta_key']));
    }

    /**
     * Fields included as ordered columns in the attendee CSV export.
     *
     * @return array<string, array>
     */
    public static function for_csv(): array
    {
        return array_filter(self::all(), fn($f) => $f['in_csv']);
    }

    /**
     * Fields included in the {all_fields} email merge-tag table.
     *
     * @return array<string, array>
     */
    public static function for_email(): array
    {
        return array_filter(self::all(), fn($f) => $f['in_email']);
    }

    /**
     * Fields appropriate for the admin Edit Booking modal.
     * Excludes agreement-only checkbox fields (mailing_agreement, terms_accepted)
     * that should not be silently toggled by an admin.
     *
     * @return array<string, array>
     */
    public static function for_edit_modal(): array
    {
        $exclude = ['mailing_agreement', 'terms_accepted'];
        return array_filter(
            self::all(),
            fn($key) => !in_array($key, $exclude, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    // ── Frontend form helpers ───────────────────────────────────────────────────

    /**
     * Ordered section definitions for the public-facing booking form shortcode.
     *
     * @return array<string, string>  Map of section key → display heading.
     */
    public static function frontend_sections(): array
    {
        return [
            'your_information' => 'Your Information',
            'address'          => 'Address',
            'extra_details'    => 'Extra Details',
            'agreements'       => 'Agreements',
        ];
    }

    /**
     * All fields that appear in the public-facing booking form (have a frontend_section).
     *
     * @return array<string, array>
     */
    public static function for_frontend(): array
    {
        return array_filter(self::all(), fn($f) => !empty($f['frontend_section']));
    }

    /**
     * Fields belonging to one section of the public-facing booking form.
     *
     * @param string $section  One of the keys returned by frontend_sections().
     * @return array<string, array>
     */
    public static function for_frontend_section(string $section): array
    {
        return array_filter(self::all(), fn($f) => ($f['frontend_section'] ?? null) === $section);
    }

    // ── Key / label helpers ─────────────────────────────────────────────────────

    /**
     * Ordered list of all canonical field keys.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Map of canonical key → human-readable label for all fields.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(fn($f) => $f['label'], self::all());
    }

    /**
     * Ordered list of canonical keys for CSV export columns.
     *
     * @return string[]
     */
    public static function keys_for_csv(): array
    {
        return array_keys(self::for_csv());
    }

    /**
     * Map of canonical key → label for CSV fields.
     *
     * @return array<string, string>
     */
    public static function labels_for_csv(): array
    {
        return array_map(fn($f) => $f['label'], self::for_csv());
    }
}
