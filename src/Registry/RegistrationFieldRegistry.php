<?php

/**
 * Registration Field Registry.
 *
 * Defines all available registration form fields and their variants.
 * Fields can be scoped to audience types (parent, professional, couple, individual)
 * and have frontend/backend rendering metadata.
 *
 * Replaces and extends the legacy BookingFields config.
 *
 * @package HMWEvents\Registry
 * @since 2.0.0
 */

namespace HMWEvents\Registry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class RegistrationFieldRegistry
{
    /**
     * Field variant: shown to all audience types.
     */
    public const EVERYONE = '*';

    public const PROFESSIONAL_ONLY = 'professional';

    public const SOURCE_REGISTRANT_META = 'registrant_meta';

    public const SOURCE_BOOKING_DETAILS = 'booking_details';

    /**
     * Cached field definitions.
     */
    private static ?array $fields = null;

    /**
     * Get all defined fields.
     *
     * @return array<string, array>
     */
    public static function all(): array
    {
        if (self::$fields !== null) {
            return self::$fields;
        }

        self::$fields = self::build();
        return self::$fields;
    }

    /**
     * Get fields for a specific audience variant.
     *
     * @param string $audience 'parent', 'professional', 'couple', 'individual', or '*'
     * @return array<string, array>
     */
    public static function for_audience(string $audience): array
    {
        return array_filter(self::all(), function ($field) use ($audience) {
            $variants = $field['audience_variants'] ?? [self::EVERYONE];
            return in_array(self::EVERYONE, $variants, true)
                || in_array($audience, $variants, true);
        });
    }

    /**
     * Get fields grouped into labelled sections.
     */
    public static function get_sections(): array
    {
        return [
            'contact'      => __('Contact Information', 'hmw-events'),
            'address'      => __('Address', 'hmw-events'),
            'professional' => __('Professional Details', 'hmw-events'),
            'documents'    => __('Documents', 'hmw-events'),
            'additional'   => __('Additional Information', 'hmw-events'),
        ];
    }

    /**
     * Get fields that should appear in emails (in_email = true).
     *
     * @return array<string, array>
     */
    public static function for_email(): array
    {
        return array_filter(self::all(), fn($f) => !empty($f['in_email']));
    }

    /**
     * Get fields for admin display (edit modals, tables).
     * Returns all non-file fields with label + type.
     *
     * @return array<string, array>
     */
    public static function for_admin_display(): array
    {
        return array_filter(self::all(), fn($f) => ($f['type'] ?? '') !== 'file');
    }

    /**
     * Get fields stored as registrant post meta.
     *
     * @return array<string, array>
     */
    public static function registrant_meta_fields(): array
    {
        return array_filter(self::all(), fn($f) => ($f['source'] ?? '') === self::SOURCE_REGISTRANT_META);
    }

    /**
     * Get fields stored in booking_details.form_data JSON.
     *
     * @return array<string, array>
     */
    public static function booking_details_fields(): array
    {
        return array_filter(self::all(), fn($f) => ($f['source'] ?? '') === self::SOURCE_BOOKING_DETAILS);
    }

    /**
     * Legacy key mapping for backward compat with old BookingFields data.
     *
     * @return array<string, string|null>
     */
    private static function legacy_field_map(): array
    {
        return [
            "first_name"           => "mothers_first_name",
            "last_name"            => "mothers_last_name",
            "email"                => "email",
            "phone"                => "phone",
            "street_address"       => "street_address",
            "suburb"               => "city",
            "state"                => "state",
            "postcode"             => "postcode",
            "dietary_requirements" => "dietary_requirements",
            "special_requirements" => "special_considerations",
            "organisation"         => "organisation",
            "job_title"            => "job_title",
            "professional_body"    => "professional_body",
            "heard_about"          => null,
            "document_upload"      => null,
            "mailing_agreement"    => "mailing_agreement",
            "terms_accepted"       => "terms_accepted",
        ];
    }

    /**
     * Try to get a value using new key first, then legacy key.
     *
     * @param array<string,mixed> $form_data
     * @param string              $new_key
     * @return mixed
     */
    public static function resolve_legacy_value(array $form_data, string $new_key)
    {
        if (array_key_exists($new_key, $form_data)) {
            return $form_data[$new_key];
        }

        $map        = self::legacy_field_map();
        $legacy_key = $map[$new_key] ?? null;
        if ($legacy_key !== null && $legacy_key !== $new_key && array_key_exists($legacy_key, $form_data)) {
            return $form_data[$legacy_key];
        }

        return null;
    }

    /**
     * Get a single field definition.
     */
    public static function get_field(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function presets(): array
    {
        return [
            'first_name' => [
                'key'         => 'first_name',
                'label'       => __('First Name', 'hmw-events'),
                'type'        => 'text',
                'source'      => self::SOURCE_REGISTRANT_META,
                'meta_key'    => 'registrant_first_name',
                'width'       => 'half',
                'required'    => true,
                'placeholder' => '',
            ],
            'last_name'  => [
                'key'         => 'last_name',
                'label'       => __('Last Name', 'hmw-events'),
                'type'        => 'text',
                'source'      => self::SOURCE_REGISTRANT_META,
                'meta_key'    => 'registrant_last_name',
                'width'       => 'half',
                'required'    => true,
                'placeholder' => '',
            ],
            'email'      => [
                'key'         => 'email',
                'label'       => __('Email', 'hmw-events'),
                'type'        => 'email',
                'source'      => self::SOURCE_REGISTRANT_META,
                'meta_key'    => 'registrant_email',
                'width'       => 'full',
                'required'    => true,
                'placeholder' => '',
            ],
            'phone'      => [
                'key'         => 'phone',
                'label'       => __('Phone', 'hmw-events'),
                'type'        => 'tel',
                'source'      => self::SOURCE_REGISTRANT_META,
                'meta_key'    => 'registrant_phone',
                'width'       => 'full',
                'required'    => true,
                'placeholder' => '',
            ],
        ];
    }

    /**
     * Build the complete field registry.
     */
    private static function build(): array
    {
        return [

            // ──── Contact (everyone) ───────────────────────────────
            'first_name' => [
                'label'              => __('First Name', 'hmw-events'),
                'type'               => 'text',
                'source'             => self::SOURCE_REGISTRANT_META,
                'meta_key'           => 'registrant_first_name',
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'contact',
                'width'              => 'half',
                'required'           => true,
                'in_csv'             => true,
                'in_email'           => true,
                'placeholder'        => '',
            ],
            'last_name' => [
                'label'              => __('Last Name', 'hmw-events'),
                'type'               => 'text',
                'source'             => self::SOURCE_REGISTRANT_META,
                'meta_key'           => 'registrant_last_name',
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'contact',
                'width'              => 'half',
                'required'           => true,
                'in_csv'             => true,
                'in_email'           => true,
                'placeholder'        => '',
            ],
            'email' => [
                'label'              => __('Email', 'hmw-events'),
                'type'               => 'email',
                'source'             => self::SOURCE_REGISTRANT_META,
                'meta_key'           => 'registrant_email',
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'contact',
                'width'              => 'half',
                'required'           => true,
                'in_csv'             => true,
                'in_email'           => true,
                'placeholder'        => '',
            ],
            'phone' => [
                'label'              => __('Phone', 'hmw-events'),
                'type'               => 'tel',
                'source'             => self::SOURCE_REGISTRANT_META,
                'meta_key'           => 'registrant_phone',
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'contact',
                'width'              => 'half',
                'required'           => true,
                'in_csv'             => true,
                'in_email'           => true,
                'placeholder'        => '',
            ],

            // ──── Address (everyone) ──────────────────────────────
            'street_address' => [
                'label'              => __('Street Address', 'hmw-events'),
                'type'               => 'text',
                'source'             => self::SOURCE_REGISTRANT_META,
                'meta_key'           => 'registrant_address',
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'address',
                'width'              => 'full',
                'required'           => false,
                'in_csv'             => true,
                'in_email'           => false,
                'placeholder'        => '',
            ],
            'suburb' => [
                'label'              => __('Suburb / City', 'hmw-events'),
                'type'               => 'text',
                'source'             => self::SOURCE_REGISTRANT_META,
                'meta_key'           => 'registrant_suburb',
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'address',
                'width'              => 'half',
                'required'           => false,
                'in_csv'             => true,
                'in_email'           => false,
                'placeholder'        => '',
            ],
            'state' => [
                'label'              => __('State', 'hmw-events'),
                'type'               => 'select',
                'source'             => self::SOURCE_REGISTRANT_META,
                'meta_key'           => 'registrant_state',
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'address',
                'width'              => 'half',
                'required'           => false,
                'in_csv'             => true,
                'in_email'           => false,
                'options'            => self::au_states(),
                'placeholder'        => '',
            ],
            'postcode' => [
                'label'              => __('Postcode', 'hmw-events'),
                'type'               => 'text',
                'source'             => self::SOURCE_REGISTRANT_META,
                'meta_key'           => 'registrant_postcode',
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'address',
                'width'              => 'half',
                'required'           => false,
                'in_csv'             => true,
                'in_email'           => false,
                'placeholder'        => '',
            ],

            // ──── Professional-specific fields ────────────────────
            'organisation' => [
                'label'              => __('Organisation', 'hmw-events'),
                'type'               => 'text',
                'source'             => self::SOURCE_BOOKING_DETAILS,
                'meta_key'           => null,
                'audience_variants'  => [self::PROFESSIONAL_ONLY],
                'section'            => 'professional',
                'width'              => 'full',
                'required'           => true,
                'in_csv'             => true,
                'in_email'           => true,
                'placeholder'        => '',
            ],
            'job_title' => [
                'label'              => __('Job Title', 'hmw-events'),
                'type'               => 'text',
                'source'             => self::SOURCE_BOOKING_DETAILS,
                'meta_key'           => null,
                'audience_variants'  => [self::PROFESSIONAL_ONLY],
                'section'            => 'professional',
                'width'              => 'half',
                'required'           => false,
                'in_csv'             => true,
                'in_email'           => true,
                'placeholder'        => '',
            ],
            'professional_body' => [
                'label'              => __('Professional Body / AHPRA Number', 'hmw-events'),
                'type'               => 'text',
                'source'             => self::SOURCE_BOOKING_DETAILS,
                'meta_key'           => null,
                'audience_variants'  => [self::PROFESSIONAL_ONLY],
                'section'            => 'professional',
                'width'              => 'half',
                'required'           => false,
                'in_csv'             => true,
                'in_email'           => false,
                'placeholder'        => '',
            ],

            // ──── Documents ───────────────────────────────────────
            'document_upload' => [
                'label'              => __('Upload Document', 'hmw-events'),
                'type'               => 'file',
                'source'             => self::SOURCE_BOOKING_DETAILS,
                'meta_key'           => null,
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'documents',
                'width'              => 'full',
                'required'           => false,
                'in_csv'             => false,
                'in_email'           => false,
                'allowed_types'      => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'],
                'max_size'           => 10 * 1024 * 1024, // 10MB
                'placeholder'        => '',
            ],

            // ──── Additional (everyone) ───────────────────────────
            'dietary_requirements' => [
                'label'              => __('Dietary / Allergies', 'hmw-events'),
                'type'               => 'textarea',
                'source'             => self::SOURCE_BOOKING_DETAILS,
                'meta_key'           => null,
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'additional',
                'width'              => 'full',
                'required'           => false,
                'in_csv'             => true,
                'in_email'           => true,
                'placeholder'        => __('Please list any food allergies or dietary restrictions', 'hmw-events'),
                'rows'               => 3,
            ],
            'special_requirements' => [
                'label'              => __('Special Requirements', 'hmw-events'),
                'type'               => 'textarea',
                'source'             => self::SOURCE_BOOKING_DETAILS,
                'meta_key'           => null,
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'additional',
                'width'              => 'full',
                'required'           => false,
                'in_csv'             => true,
                'in_email'           => true,
                'placeholder'        => __('Any accessibility, language, or other requirements', 'hmw-events'),
                'rows'               => 3,
            ],
            'heard_about' => [
                'label'              => __('How did you hear about this event?', 'hmw-events'),
                'type'               => 'select',
                'source'             => self::SOURCE_BOOKING_DETAILS,
                'meta_key'           => null,
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'additional',
                'width'              => 'full',
                'required'           => false,
                'in_csv'             => true,
                'in_email'           => false,
                'options'            => [
                    ''               => '— Select —',
                    'google'         => 'Google Search',
                    'social_media'   => 'Social Media',
                    'word_of_mouth'  => 'Word of Mouth',
                    'healthcare_provider' => 'Healthcare Provider',
                    'email'          => 'Email Newsletter',
                    'other'          => 'Other',
                ],
                'placeholder'        => '',
            ],

            // ──── Agreements (everyone) ────────────────────────────
            'mailing_agreement' => [
                'label'              => __('I would like to receive email updates and newsletters', 'hmw-events'),
                'type'               => 'checkbox',
                'source'             => self::SOURCE_BOOKING_DETAILS,
                'meta_key'           => null,
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'additional',
                'width'              => 'full',
                'required'           => false,
                'in_csv'             => false,
                'in_email'           => false,
                'placeholder'        => '',
            ],
            'terms_accepted' => [
                'label'              => __('I have read and agree to the Terms & Conditions', 'hmw-events'),
                'type'               => 'checkbox',
                'source'             => self::SOURCE_BOOKING_DETAILS,
                'meta_key'           => null,
                'audience_variants'  => [self::EVERYONE],
                'section'            => 'additional',
                'width'              => 'full',
                'required'           => true,
                'in_csv'             => false,
                'in_email'           => false,
                'placeholder'        => '',
            ],
        ];
    }

    /**
     * Get Australian states for select fields.
     */
    private static function au_states(): array
    {
        return [
            ''    => '— Select —',
            'nsw' => 'New South Wales',
            'vic' => 'Victoria',
            'qld' => 'Queensland',
            'wa'  => 'Western Australia',
            'sa'  => 'South Australia',
            'tas' => 'Tasmania',
            'act' => 'Australian Capital Territory',
            'nt'  => 'Northern Territory',
        ];
    }
}
