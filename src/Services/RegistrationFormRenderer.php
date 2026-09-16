<?php

/**
 * Registration Form Renderer.
 *
 * Renders the front-end registration form for an event. The form is assembled
 * dynamically based on:
 *   1. The event's type (from EventTypeRegistry)
 *   2. The event's audience (from hmw_event_audience taxonomy)
 *   3. Any saved form preset (from RegistrationFormPreset)
 *   4. The selected attendance option (parent/professional/couple/individual)
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

use HMWEvents\Helpers\EventFieldConfig;
use HMWEvents\Helpers\EventHelper;
use HMWEvents\Registry\RegistrationFieldRegistry;
use HMWEvents\Registry\EventTypeRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class RegistrationFormRenderer
{
    private RegistrationFormPreset $presets;
    private DocumentUploadHandler $uploads;
    private ?EventDataService $event_data_service = null;

    private ?int $render_event_id = null;

    private function event_data(): EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new EventDataService();
        }
        return $this->event_data_service;
    }

    public function __construct()
    {
        $this->presets = new RegistrationFormPreset();
        $this->uploads = new DocumentUploadHandler();
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_shortcode('hmw_registration_form', [$this, 'render_form']);
        add_action('wp_ajax_hmwevents_submit_registration', [$this, 'handle_submission']);
        add_action('wp_ajax_nopriv_hmwevents_submit_registration', [$this, 'handle_submission']);
    }

    // ================================================================
    // RENDERING
    // ================================================================

    /**
     * Get the fully resolved field list for a given event and attendance option.
     *
     * @param int    $event_id         
     * @param string $attendance_type  e.g. 'parent', 'professional', 'couple', 'individual'
     * @return array<string, array>
     */
    public function get_fields_for_event(int $event_id, string $attendance_type = 'individual'): array
    {
        $event_type = $this->get_event_type_slug($event_id);
        $audience = $this->get_event_audience_slug($event_id);

        // Get base fields filtered by attendance type variant
        $fields = RegistrationFieldRegistry::for_audience($attendance_type);

        // Apply event-type-level field visibility from EventTypeRegistry
        foreach ($fields as $key => $field) {
            // Check if the event type hides this field
            if (!EventTypeRegistry::is_field_visible($event_type, $field['meta_key'] ?? $key)) {
                unset($fields[$key]);
                continue;
            }

            // Apply requiredness from EventTypeRegistry
            if ($field['meta_key'] && EventTypeRegistry::is_field_required($event_type, $field['meta_key'])) {
                $fields[$key]['required'] = true;
            }
        }

        $snapshot_config = EventFieldConfig::read($event_id);
        $snapshot_defaults = EventFieldConfig::read($event_id, '_event_default_values');
        $has_snapshot_config = $snapshot_config !== null
            && isset($snapshot_config['registration_fields'])
            && is_array($snapshot_config['registration_fields']);

        $template_override = EventFieldConfig::read($event_id, '_event_template_override');
        $has_template_override = $template_override !== null
            && isset($template_override['registration_fields'])
            && is_array($template_override['registration_fields']);

        if ($has_template_override) {
            $fields = $this->apply_registration_template_override(
                $fields,
                (array) $template_override['registration_fields'],
                $snapshot_defaults ?? []
            );
            return $fields;
        }

        if ($has_snapshot_config) {
            $fields = $this->apply_registration_snapshot_config(
                $fields,
                (array) $snapshot_config['registration_fields'],
                $snapshot_defaults ?? []
            );
            return $fields;
        }

        // Apply any saved preset overrides
        $preset_fields = $this->presets->get_effective_fields($event_type, $audience);

        foreach ($preset_fields as $key => $preset_field) {
            if (!isset($fields[$key])) {
                continue;
            }
            if (!empty($preset_field['label'])) {
                $fields[$key]['label'] = $preset_field['label'];
            }
            if (isset($preset_field['placeholder'])) {
                $fields[$key]['placeholder'] = $preset_field['placeholder'] ?? '';
            }
            if (isset($preset_field['required'])) {
                $fields[$key]['required'] = $preset_field['required'];
            }
        }

        // Remove preset-hidden fields
        $fields = array_filter($fields, function ($f) {
            return empty($f['preset_hidden']);
        });

        return $fields;
    }

    /**
     * Apply per-event snapshot config for registration fields.
     *
     * @param array<string,array> $fields
     * @param array<string,array> $registration_config
     * @param array<string,mixed> $snapshot_defaults
     * @return array<string,array>
     */
    private function apply_registration_snapshot_config(array $fields, array $registration_config, array $snapshot_defaults): array
    {
        $hidden = array_flip(array_map('sanitize_key', (array) ($registration_config['hidden'] ?? [])));
        $required = array_flip(array_map('sanitize_key', (array) ($registration_config['required'] ?? [])));
        $optional = array_flip(array_map('sanitize_key', (array) ($registration_config['optional'] ?? [])));

        foreach (array_keys($fields) as $field_key) {
            if (isset($hidden[$field_key])) {
                unset($fields[$field_key]);
            }
        }

        foreach ($fields as $field_key => $field) {
            if (isset($required[$field_key])) {
                $fields[$field_key]['required'] = true;
                continue;
            }

            if (isset($optional[$field_key])) {
                $fields[$field_key]['required'] = false;
            }
        }

        $field_overrides = $snapshot_defaults['registration']['field_overrides'] ?? [];
        if (!is_array($field_overrides)) {
            return $fields;
        }

        foreach ($field_overrides as $field_key => $override) {
            $field_key = sanitize_key((string) $field_key);
            if (!isset($fields[$field_key]) || !is_array($override)) {
                continue;
            }

            if (!empty($override['label'])) {
                $fields[$field_key]['label'] = sanitize_text_field($override['label']);
            }

            if (array_key_exists('placeholder', $override)) {
                $fields[$field_key]['placeholder'] = sanitize_text_field((string) $override['placeholder']);
            }
        }

        return $fields;
    }

    /**
     * Apply per-event template override config for registration fields.
     *
     * Supports ordering via registration_fields.order and field-level overrides
     * via registration_fields.field_overrides. Template override values take
     * priority over _event_default_values.registration.field_overrides.
     *
     * @param array<string,array> $fields
     * @param array<string,array> $template_registration
     * @param array<string,mixed> $snapshot_defaults
     * @return array<string,array>
     */
    private function apply_registration_template_override(array $fields, array $template_registration, array $snapshot_defaults): array
    {
        $hidden = array_flip(array_map('sanitize_key', (array) ($template_registration['hidden'] ?? [])));
        $required = array_flip(array_map('sanitize_key', (array) ($template_registration['required'] ?? [])));
        $optional = array_flip(array_map('sanitize_key', (array) ($template_registration['optional'] ?? [])));

        foreach (array_keys($fields) as $field_key) {
            if (isset($hidden[$field_key])) {
                unset($fields[$field_key]);
            }
        }

        foreach ($fields as $field_key => $field) {
            if (isset($required[$field_key])) {
                $fields[$field_key]['required'] = true;
                continue;
            }

            if (isset($optional[$field_key])) {
                $fields[$field_key]['required'] = false;
            }
        }

        $default_overrides = $snapshot_defaults['registration']['field_overrides'] ?? [];
        if (is_array($default_overrides)) {
            foreach ($default_overrides as $field_key => $override) {
                $field_key = sanitize_key((string) $field_key);
                if (!isset($fields[$field_key]) || !is_array($override)) {
                    continue;
                }

                if (!empty($override['label'])) {
                    $fields[$field_key]['label'] = sanitize_text_field($override['label']);
                }

                if (array_key_exists('placeholder', $override)) {
                    $fields[$field_key]['placeholder'] = sanitize_text_field((string) $override['placeholder']);
                }

                if (!empty($override['width'])) {
                    $fields[$field_key]['width'] = sanitize_text_field($override['width']);
                }

                if (!empty($override['section'])) {
                    $fields[$field_key]['section'] = sanitize_text_field($override['section']);
                }
            }
        }

        $template_overrides = $template_registration['field_overrides'] ?? [];
        if (is_array($template_overrides)) {
            foreach ($template_overrides as $field_key => $override) {
                $field_key = sanitize_key((string) $field_key);
                if (!isset($fields[$field_key]) || !is_array($override)) {
                    continue;
                }

                if (!empty($override['label'])) {
                    $fields[$field_key]['label'] = sanitize_text_field($override['label']);
                }

                if (array_key_exists('placeholder', $override)) {
                    $fields[$field_key]['placeholder'] = sanitize_text_field((string) $override['placeholder']);
                }

                if (!empty($override['width'])) {
                    $fields[$field_key]['width'] = sanitize_text_field($override['width']);
                }

                if (!empty($override['section'])) {
                    $fields[$field_key]['section'] = sanitize_text_field($override['section']);
                }
            }
        }

        $order = $template_registration['order'] ?? [];
        if (is_array($order) && !empty($order)) {
            $ordered = [];
            foreach ($order as $field_key) {
                $field_key = sanitize_key((string) $field_key);
                if (isset($fields[$field_key])) {
                    $ordered[$field_key] = $fields[$field_key];
                    unset($fields[$field_key]);
                }
            }
            foreach ($fields as $field_key => $field) {
                $ordered[$field_key] = $field;
            }
            $fields = $ordered;
        }

        return $fields;
    }

    /**
     * Group fields into sections for rendering.
     */
    public function group_fields_by_section(array $fields): array
    {
        $sections = RegistrationFieldRegistry::get_sections();
        $grouped = [];

        foreach ($fields as $key => $field) {
            $section = $field['section'] ?? 'additional';
            if (!isset($grouped[$section])) {
                $grouped[$section] = [
                    'label'  => $sections[$section] ?? $section,
                    'fields' => [],
                ];
            }
            $grouped[$section]['fields'][$key] = $field;
        }

        return $grouped;
    }

    /**
     * Render the full registration form for an event.
     *
     * Shortcode: [hmw_registration_form event_id="123"]
     */
    public function render_form(array $atts = [], string $content = ''): string
    {
        $atts = shortcode_atts([
            'event_id'        => 0,
            'attendance_type' => 'individual',
        ], $atts);

        $event_id = (int) $atts['event_id'];
        if (!$event_id) {
            return '<p class="hmw-error">' . esc_html__('Invalid event.', 'hmw-events') . '</p>';
        }

        if (!$this->event_data()->bookings_enabled($event_id)) {
            return '';
        }

        $capacity_service = new CapacityService();
        if ($capacity_service->get_capacity($event_id) > 0 && $capacity_service->get_remaining_places($event_id) <= 0) {
            return (new WaitlistFormRenderer())->render($event_id);
        }

        $v3_config = $this->get_v3_sections($event_id);

        if (!$v3_config) {
            return '<p class="hmw-error">' . esc_html__('Registration for this event is not available.', 'hmw-events') . '</p>';
        }

        return $this->render_v3_form($event_id, $atts['attendance_type'], $v3_config);
    }

    private function get_v3_sections(int $event_id): ?array
    {
        return \HMWEvents\Services\FormConfigResolver::resolve($event_id);
    }

    private function render_v3_form(int $event_id, string $attendance_type, array $reg_config): string
    {
        $this->render_event_id = $event_id;

        $sections = $reg_config['sections'] ?? [];
        $multi_booking = $reg_config['multi_booking'] ?? ['enabled' => false, 'min' => 1, 'max' => 10];
        $mb_enabled = (bool) ($multi_booking['enabled'] ?? false);

        $mb_mode = (string) ($multi_booking['mode'] ?? 'attendees');
        $is_parent_children = $mb_mode === 'parent_children';

        $child_name_cfg = is_array($multi_booking['child_fields']['name'] ?? null) ? $multi_booking['child_fields']['name'] : [];
        $child_age_cfg  = is_array($multi_booking['child_fields']['age'] ?? null) ? $multi_booking['child_fields']['age'] : [];

        $child_name_enabled  = (bool) ($child_name_cfg['enabled'] ?? true);
        $child_name_required = (bool) ($child_name_cfg['required'] ?? true);
        $child_name_label    = sanitize_text_field((string) ($child_name_cfg['label'] ?? '')) ?: __('Child Name', 'hmw-events');

        $child_age_enabled  = (bool) ($child_age_cfg['enabled'] ?? true);
        $child_age_required = (bool) ($child_age_cfg['required'] ?? false);
        $child_age_label    = sanitize_text_field((string) ($child_age_cfg['label'] ?? '')) ?: __('Date of Birth', 'hmw-events');

        $pricing_service = new AttendancePricingService();
        $context = $pricing_service->build_selection_context($event_id, $attendance_type, $multi_booking, $mb_enabled);

        $is_free = $context['is_free'];
        $surcharge = $context['surcharge'];
        $price = $context['default_display_price'];
        $total = $price + $surcharge;

        // Session-picker pricing lives on the individual sessions, not on the
        // parent event — the form requires payment when any bookable session
        // is priced, even if the parent's own price is empty or free.
        $has_priced_sessions = false;
        if ($this->event_data()->get_session_booking_mode($event_id) === 'individual') {
            $has_priced_sessions = (new SessionBookingService())->has_priced_bookable_sessions($event_id);
        }

        $requires_payment = $total > 0 || $context['has_paid_option'] || $has_priced_sessions;

        $attendance_options = EventHelper::get_active_attendance_options($event_id);
        $attendance_default = $context['default_option_type'];

        $capacity_service = new CapacityService();
        $event_capacity = $capacity_service->get_capacity($event_id);
        $event_places_remaining = $capacity_service->get_remaining_places($event_id);

        $option_payload = $context['options'];

        $is_private_access = \HMWEvents\PostTypes\Event::is_invitation_only((int) $event_id);
        $show_net_terms = $is_private_access && $this->event_data()->get_allow_net_terms($event_id);

        wp_enqueue_style(
            'hmwevents-v3-booking',
            \HMWEvents\HMWEvents::plugin_url() . '/assets/css/v3-booking.css',
            [],
            file_exists(\HMWEvents\HMWEvents::plugin_path() . '/assets/css/v3-booking.css')
                ? filemtime(\HMWEvents\HMWEvents::plugin_path() . '/assets/css/v3-booking.css')
                : '1.0.0'
        );

        $has_paid_option = $context['has_paid_option'];
        $has_age_pricing = $context['has_age_pricing'];
        $effective_multi = $context['effective_multi'];
        $global_max_attendees = $context['max_attendees'];
        $selected_composition = $context['selected_composition'];

        $all_per_attendee = [];
        $all_global = [];

        foreach ($sections as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if ($effective_multi && !empty($field['per_attendee'])) {
                    $all_per_attendee[] = $field;
                } else {
                    $all_global[] = $field;
                }
            }
        }

        $has_multi_booking = $effective_multi;
        $needs_attendee_identity = $effective_multi || $has_age_pricing;
        $allowed_roles = $context['allowed_roles'];

        $hmw_v3_deps = ['jquery'];

        if ($requires_payment) {
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
            $hmw_v3_deps[] = 'stripe-js';
        }

        wp_enqueue_script(
            'hmwevents-v3-booking',
            \HMWEvents\HMWEvents::plugin_url() . '/assets/js/v3-booking.js',
            $hmw_v3_deps,
            file_exists(\HMWEvents\HMWEvents::plugin_path() . '/assets/js/v3-booking.js')
                ? filemtime(\HMWEvents\HMWEvents::plugin_path() . '/assets/js/v3-booking.js')
                : '1.0.0',
            true
        );

        $publishable_key = $requires_payment ? \HMWEvents\Helpers\StripeHelper::get_publishable_key(null) : '';
        $has_stripe = $requires_payment && !empty($publishable_key);

        $organizer_id = $this->event_data()->get_organizer_id($event_id);
        if (!$organizer_id) {
            $post = get_post($event_id);
            $organizer_id = $post ? (int) $post->post_author : null;
        }

        wp_localize_script('hmwevents-v3-booking', 'hmwV3Booking', [
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'restUrl'          => rest_url('hmwevents/v1'),
            'nonce'            => wp_create_nonce('hmwevents_registration_' . $event_id),
            'stripeKey'        => $has_stripe ? $publishable_key : '',
            'eventId'          => $event_id,
            'organizerId'      => $organizer_id,
            'price'            => $price,
            'basePrice'        => $context['base_price'],
            'surcharge'        => $surcharge,
            'total'            => $total,
            'requiresPayment'  => $requires_payment,
            'currency'         => 'aud',
            'currencySymbol'   => '$',
            'priceLabel'       => '$' . number_format($total, 2),
            'courseFeeLabel'   => __('Course fee:', 'hmw-events'),
            'courseFeePerAttendeeLabel' => __('Course fee per attendee:', 'hmw-events'),
            'isMultiBooking'   => $effective_multi,
            'multiBookingMode' => $mb_mode,
            'minAttendees'     => max(1, (int) ($selected_composition['min_attendees'] ?? 1)),
            'maxAttendees'     => $global_max_attendees,
            'eventDate'        => $this->event_data()->get_start_date($event_id),
            'hasAgePricing'    => $has_age_pricing,
            'attendanceOptions' => $option_payload,
            'attendanceDefault' => $attendance_default,
            'eventCapacity'     => $event_capacity > 0 ? $event_capacity : null,
            'eventPlacesRemaining' => $event_capacity > 0 ? $event_places_remaining : null,
        ]);

        ob_start();
        ?>
        <div class="hmw-v3-booking-wrapper" data-requires-payment="<?php echo $requires_payment ? '1' : '0'; ?>">
        <form class="hmw-registration-form hmw-registration-form--v3" method="post"
              data-event-id="<?php echo esc_attr($event_id); ?>"
              data-multi-booking="<?php echo $mb_enabled ? '1' : '0'; ?>"
              data-requires-payment="<?php echo $requires_payment ? '1' : '0'; ?>"
              data-attendee-count="1">

            <?php wp_nonce_field('hmwevents_registration_' . $event_id, '_hmwevents_nonce'); ?>
            <input type="hidden" name="event_id" value="<?php echo esc_attr($event_id); ?>" />
            <input type="hidden" name="action" value="hmwevents_submit_registration" />
            <input type="hidden" name="attendee_count" value="1" id="hmw_attendee_count_hidden" />
            <?php if (!empty($_GET['token'])): ?>
                <input type="hidden" name="token" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_GET['token']))); ?>" />
            <?php endif; ?>

            <!-- <?php if ($event_capacity > 0): ?>
                <p class="hmw-v3-places-remaining">
                    <?php
                    /* translators: %d is the number of places remaining for the event */
                    echo esc_html(sprintf(__('%d places remaining', 'hmw-events'), $event_places_remaining));
                    ?>
                </p>
            <?php endif; ?> -->

            <?php if (count($attendance_options) > 1): ?>
                <div class="hmw-reg-section hmw-reg-section--attendance-options">
                    <h3 class="hmw-reg-section-title"><?php esc_html_e('Attendance Option', 'hmw-events'); ?></h3>
                    <div class="hmw-v3-attendance-options" role="radiogroup">
                        <?php foreach ($option_payload as $option): ?>
                            <?php $option_price = (float) $option['display_price']; ?>
                            <label class="hmw-v3-attendance-option" data-option-type="<?php echo esc_attr($option['option_type']); ?>">
                                <input type="radio" name="attendance_type" value="<?php echo esc_attr($option['option_type']); ?>"
                                       <?php checked($attendance_default, $option['option_type']); ?> />
                                <span class="hmw-v3-attendance-option-label"><?php echo esc_html($option['label']); ?></span>
                                <?php if (!$is_free && ($option_price > 0 || $has_paid_option)): ?>
                                    <span class="hmw-v3-attendance-option-price">
                                        <?php echo esc_html(($option['price_mode'] ?? 'flat') === AttendancePricingService::MODE_FLAT ? '$' . number_format($option_price, 2) : __('From', 'hmw-events') . ' $' . number_format($option_price, 2)); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($option['max_bookings'] !== null): ?>
                                    <span class="hmw-v3-attendance-option-remaining">
                                        <?php
                                        /* translators: %d is the number of bookings remaining for this option */
                                        echo esc_html(sprintf(__('%d bookings remaining', 'hmw-events'), (int) $option['remaining_bookings']));
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <input type="hidden" name="attendance_type" value="<?php echo esc_attr($attendance_default); ?>" />
            <?php endif; ?>

            <?php if ($is_parent_children): ?>
                <input type="hidden" name="attendees[0][attendee_role]" value="adult" />
            <?php elseif (count($allowed_roles) === 1): ?>
                <input type="hidden" name="attendees[0][attendee_role]" value="<?php echo esc_attr($allowed_roles[0]); ?>" />
            <?php endif; ?>

            <?php if ($needs_attendee_identity && ($has_age_pricing || (!$is_parent_children && count($allowed_roles) > 1))): ?>
                <div class="hmw-reg-section hmw-reg-section--attendee-details">
                    <h3 class="hmw-reg-section-title"><?php echo $is_parent_children ? esc_html__('Parent Details', 'hmw-events') : esc_html__('Attendee Details', 'hmw-events'); ?></h3>
                    <div class="hmw-reg-grid">
                        <?php if (!$is_parent_children && count($allowed_roles) > 1): ?>
                            <div class="hmw-reg-field hmw-reg-field--select hmw-reg-field--half">
                                <label for="hmw_attendee_role_0"><?php esc_html_e('Attendee Type', 'hmw-events'); ?><span class="hmw-required" aria-hidden="true">*</span></label>
                                <select id="hmw_attendee_role_0" name="attendees[0][attendee_role]" required>
                                    <?php foreach ($allowed_roles as $role): ?>
                                        <option value="<?php echo esc_attr($role); ?>"><?php echo esc_html(ucfirst($role)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <?php if ($has_age_pricing): ?>
                            <div class="hmw-reg-field hmw-reg-field--date hmw-reg-field--full hmw-v3-age-dob-field">
                                <label for="hmw_attendee_dob_0"><?php esc_html_e('Date of Birth', 'hmw-events'); ?><span class="hmw-required" aria-hidden="true">*</span></label>
                                <input type="date" id="hmw_attendee_dob_0" name="attendees[0][date_of_birth]" required max="<?php echo esc_attr(gmdate('Y-m-d')); ?>" />
                                <p class="hmw-v3-field-hint"><?php esc_html_e('Required for age-based pricing — used to calculate this attendee\'s fee.', 'hmw-events'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($sections as $section): ?>
                <div class="hmw-reg-section">
                    <h3 class="hmw-reg-section-title"><?php echo esc_html($section['label'] ?? ''); ?></h3>
                    <div class="hmw-reg-grid">
                        <?php foreach ($section['fields'] ?? [] as $field): ?>
                            <?php if ($effective_multi && !empty($field['per_attendee'])): ?>
                                <?php if (!$is_parent_children): ?>
                                    <?php echo $this->render_field_v3('attendees[0][' . $field['key'] . ']', $field); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php echo $this->render_field_v3($field['key'], $field); ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($effective_multi && ($needs_attendee_identity || !empty($all_per_attendee) || $is_parent_children)): ?>
                <?php if ($is_parent_children): ?>
                    <div class="hmw-reg-section hmw-reg-section--children" id="hmw-v3-additional-attendees">
                        <h3 class="hmw-reg-section-title"><?php esc_html_e('Children', 'hmw-events'); ?></h3>
                        <p class="hmw-reg-section-desc"><?php esc_html_e('Add the children attending with you.', 'hmw-events'); ?></p>
                        <div id="hmw-v3-attendee-blocks"></div>
                        <button type="button" class="hmw-btn hmw-btn--secondary hmw-v3-add-attendee" id="hmw-v3-add-attendee-btn">
                            + <?php esc_html_e('Add Child', 'hmw-events'); ?>
                        </button>
                    </div>
                <?php else: ?>
                    <div class="hmw-reg-section hmw-reg-section--attendees" id="hmw-v3-additional-attendees">
                        <h3 class="hmw-reg-section-title"><?php esc_html_e('Additional Attendees', 'hmw-events'); ?></h3>
                        <p class="hmw-reg-section-desc"><?php esc_html_e('Add other people attending with you.', 'hmw-events'); ?></p>
                        <div id="hmw-v3-attendee-blocks"></div>
                        <button type="button" class="hmw-btn hmw-btn--secondary hmw-v3-add-attendee" id="hmw-v3-add-attendee-btn">
                            + <?php esc_html_e('Add Attendee', 'hmw-events'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="hmw-reg-section hmw-reg-section--coupon">
                <h3 class="hmw-reg-section-title"><?php esc_html_e('Coupon Code', 'hmw-events'); ?></h3>
                <div class="hmw-v3-coupon-row">
                    <input type="text" id="hmw-v3-coupon-code" name="coupon_code"
                           placeholder="<?php esc_attr_e('Enter coupon code', 'hmw-events'); ?>"
                           autocomplete="off" />
                    <button type="button" class="hmw-btn hmw-btn--secondary hmw-v3-coupon-apply"
                            id="hmw-v3-coupon-apply"><?php esc_html_e('Apply', 'hmw-events'); ?></button>
                </div>
                <div id="hmw-v3-coupon-message" class="hmw-v3-coupon-message" style="display:none;"></div>
                <div id="hmw-v3-coupon-details" class="hmw-v3-coupon-details" style="display:none;">
                    <span id="hmw-v3-coupon-description"></span>
                    <button type="button" class="hmw-v3-coupon-remove" id="hmw-v3-coupon-remove"
                            title="<?php esc_attr_e('Remove coupon', 'hmw-events'); ?>">&times;</button>
                </div>
                <input type="hidden" name="coupon_data" id="hmw-v3-coupon-data" value="" />
            </div>

            <?php if ($requires_payment): ?>
                <div class="hmw-reg-section hmw-reg-section--payment">
                    <h3 class="hmw-reg-section-title"><?php esc_html_e('Payment', 'hmw-events'); ?></h3>
                    <div class="hmw-reg-payment-summary">
                        <p><span class="hmw-v3-course-fee-label"><?php esc_html_e('Course fee:', 'hmw-events'); ?></span> <span class="hmw-v3-course-fee">$<?php echo number_format($price, 2); ?></span></p>
                        <p><?php esc_html_e('Attendees:', 'hmw-events'); ?> <span class="hmw-v3-attendee-count">1</span></p>
                        <?php if ($surcharge > 0): ?>
                            <p><?php esc_html_e('Surcharge:', 'hmw-events'); ?> <span>$<?php echo number_format($surcharge, 2); ?></span></p>
                        <?php endif; ?>
                        <p><?php esc_html_e('Total:', 'hmw-events'); ?> <strong id="hmw-v3-total">$<?php echo number_format($total, 2); ?></strong></p>
                    </div>
                    <?php if ($show_net_terms): ?>
                    <div class="hmw-reg-payment-options" style="margin-bottom:12px;">
                        <label class="hmw-reg-payment-option">
                            <input type="radio" name="payment_type" value="full" checked>
                            <?php esc_html_e('Pay Online (Credit Card)', 'hmw-events'); ?>
                        </label>
                        <label class="hmw-reg-payment-option">
                            <input type="radio" name="payment_type" value="net_terms">
                            <?php esc_html_e('Pay by Invoice', 'hmw-events'); ?>
                        </label>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="payment_type" value="full">
                    <?php endif; ?>
                    <?php if ($has_stripe): ?>
                    <div class="hmw-v3-card-fields">
                        <div id="hmwevents-card-element" class="hmwevents-stripe-element" style="padding:12px;border:1px solid #d1d5db;border-radius:4px;background:#fff;min-height:42px;"></div>
                        <div id="hmwevents-card-errors" style="color:#b32d2e; margin-top:4px;"></div>
                    </div>
                    <?php else: ?>
                    <p style="color:#b32d2e;"><?php esc_html_e('Payment system is not configured. Please contact the site administrator.', 'hmw-events'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="hmw-form-actions">
                <button type="submit" class="hmw-btn hmw-btn--primary hmw-v3-submit-btn">
                    <?php echo $requires_payment
                        ? esc_html__('Pay $' . number_format($total, 2) . ' — Submit Registration', 'hmw-events')
                        : esc_html__('Submit Registration', 'hmw-events'); ?>
                </button>
                <div id="hmwevents-submit-spinner" class="hmwevents-spinner" style="display:none;"></div>
            </div>
        </form>
        </div>

        <?php if ($effective_multi && ($needs_attendee_identity || !empty($all_per_attendee) || $is_parent_children)): ?>
        <template id="hmw-v3-attendee-template">
            <div class="hmw-v3-attendee-block" data-attendee-index="1">
                <div class="hmw-v3-attendee-block-header">
                    <h4 class="hmw-reg-attendee-title"></h4>
                    <button type="button" class="hmw-v3-remove-attendee">&times;</button>
                </div>
                <div class="hmw-reg-grid">
                    <?php if ($is_parent_children): ?>
                        <input type="hidden" name="attendees[__INDEX__][attendee_role]" value="child" />
                        <?php if ($child_name_enabled): ?>
                            <div class="hmw-reg-field hmw-reg-field--text hmw-reg-field--half<?php echo $child_name_required ? ' hmw-reg-field--required' : ''; ?>">
                                <label for="hmw_child_name___INDEX__"><?php echo esc_html($child_name_label); ?><?php if ($child_name_required): ?><span class="hmw-required" aria-hidden="true">*</span><?php endif; ?></label>
                                <input type="text" id="hmw_child_name___INDEX__" name="attendees[__INDEX__][child_name]" <?php echo $child_name_required ? 'required' : ''; ?> />
                            </div>
                        <?php endif; ?>
                        <?php if ($child_age_enabled || $has_age_pricing): ?>
                            <div class="hmw-reg-field hmw-reg-field--date hmw-reg-field--half<?php echo ($child_age_required || $has_age_pricing) ? ' hmw-reg-field--required' : ''; ?>">
                                <label for="hmw_child_dob___INDEX__"><?php echo esc_html($child_age_label); ?><?php if ($child_age_required || $has_age_pricing): ?><span class="hmw-required" aria-hidden="true">*</span><?php endif; ?></label>
                                <input type="date" id="hmw_child_dob___INDEX__" name="attendees[__INDEX__][date_of_birth]" <?php echo ($child_age_required || $has_age_pricing) ? 'required' : ''; ?> max="<?php echo esc_attr(gmdate('Y-m-d')); ?>" />
                            </div>
                        <?php endif; ?>
                    <?php elseif (count($allowed_roles) > 1): ?>
                        <div class="hmw-reg-field hmw-reg-field--select hmw-reg-field--half">
                            <label for="hmw_attendee_role___INDEX__"><?php esc_html_e('Attendee Type', 'hmw-events'); ?><span class="hmw-required" aria-hidden="true">*</span></label>
                            <select id="hmw_attendee_role___INDEX__" name="attendees[__INDEX__][attendee_role]" required>
                                <?php foreach ($allowed_roles as $role): ?>
                                    <option value="<?php echo esc_attr($role); ?>"><?php echo esc_html(ucfirst($role)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="attendees[__INDEX__][attendee_role]" value="<?php echo esc_attr($allowed_roles[0]); ?>" />
                    <?php endif; ?>
                    <?php if (!$is_parent_children && $has_age_pricing): ?>
                        <div class="hmw-reg-field hmw-reg-field--date hmw-reg-field--full hmw-v3-age-dob-field">
                            <label for="hmw_attendee_dob___INDEX__"><?php esc_html_e('Date of Birth', 'hmw-events'); ?><span class="hmw-required" aria-hidden="true">*</span></label>
                            <input type="date" id="hmw_attendee_dob___INDEX__" name="attendees[__INDEX__][date_of_birth]" required max="<?php echo esc_attr(gmdate('Y-m-d')); ?>" />
                            <p class="hmw-v3-field-hint"><?php esc_html_e('Required for age-based pricing — used to calculate this attendee\'s fee.', 'hmw-events'); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($all_per_attendee as $field): ?>
                        <?php echo $this->render_field_v3('attendees[__INDEX__][' . $field['key'] . ']', $field); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </template>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    public function render_field_v3(string $key, array $field): string
    {
        $type    = $field['type'] ?? 'text';

        if ($type === 'session_picker') {
            $mode = $this->render_event_id
                ? $this->event_data()->get_session_booking_mode((int) $this->render_event_id)
                : 'track';
            if ($mode !== 'individual') {
                return '';
            }
        }
        $label   = $field['label'] ?? '';
        $req     = !empty($field['required']);
        $width   = $field['width'] ?? 'full';
        $ph      = $field['placeholder'] ?? '';
        $value   = $this->get_post_value($key);

        $id      = 'hmw_field_' . sanitize_key($key);
        $classes = ['hmw-reg-field', 'hmw-reg-field--' . $type, 'hmw-reg-field--' . $width];
        $attendance_types = array_values(array_filter(array_map('sanitize_key', (array) ($field['attendance_types'] ?? []))));
        if ($req) {
            $classes[] = 'hmw-reg-field--required';
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>"<?php if ($attendance_types) : ?> data-attendance-types="<?php echo esc_attr(wp_json_encode($attendance_types)); ?>"<?php endif; ?>>
            <?php if ($type !== 'checkbox'): ?>
                <label for="<?php echo esc_attr($id); ?>">
                    <?php echo esc_html($label); ?>
                    <?php if ($req): ?><span class="hmw-required" aria-hidden="true">*</span><?php endif; ?>
                </label>
            <?php endif; ?>

            <?php switch ($type):
                case 'textarea': ?>
                    <textarea id="<?php echo esc_attr($id); ?>"
                              name="<?php echo esc_attr($key); ?>"
                              rows="<?php echo esc_attr($field['rows'] ?? 3); ?>"
                              placeholder="<?php echo esc_attr($ph); ?>"
                              <?php echo $req ? 'required' : ''; ?>><?php echo esc_textarea($value); ?></textarea>
                    <?php break;

                case 'select': ?>
                    <select id="<?php echo esc_attr($id); ?>"
                            name="<?php echo esc_attr($key); ?>"
                            <?php echo $req ? 'required' : ''; ?>>
                        <?php $option_value = esc_attr($ph); ?>
                        <option value=""><?php echo $ph ? esc_html($ph) : esc_html__('Select...', 'hmw-events'); ?></option>
                        <?php foreach (($field['options'] ?? []) as $opt): ?>
                            <?php $opt_val = is_array($opt) ? ($opt['value'] ?? '') : ''; ?>
                            <?php $opt_lbl = is_array($opt) ? ($opt['label'] ?? '') : (string) $opt; ?>
                            <?php if ($opt_val === '') continue; ?>
                            <option value="<?php echo esc_attr($opt_val); ?>"
                                    <?php selected($value, $opt_val); ?>>
                                <?php echo esc_html($opt_lbl); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php break;

                case 'radio': ?>
                    <fieldset class="hmw-reg-radio-group">
                        <legend><?php echo esc_html($label); ?><?php if ($req): ?><span class="hmw-required">*</span><?php endif; ?></legend>
                        <?php foreach (($field['options'] ?? []) as $opt): ?>
                            <?php $opt_val = is_array($opt) ? ($opt['value'] ?? '') : ''; ?>
                            <?php $opt_lbl = is_array($opt) ? ($opt['label'] ?? '') : (string) $opt; ?>
                            <?php if ($opt_val === '') continue; ?>
                            <?php $radio_id = $id . '_' . sanitize_key($opt_val); ?>
                            <label for="<?php echo esc_attr($radio_id); ?>" class="hmw-reg-radio-label">
                                <input type="radio" id="<?php echo esc_attr($radio_id); ?>"
                                       name="<?php echo esc_attr($key); ?>"
                                       value="<?php echo esc_attr($opt_val); ?>"
                                       <?php checked($value, $opt_val); ?>
                                       <?php echo $req ? 'required' : ''; ?>>
                                <?php echo esc_html($opt_lbl); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <?php break;

                case 'checkbox': ?>
                    <label for="<?php echo esc_attr($id); ?>" class="hmw-reg-checkbox-label">
                        <input type="checkbox" id="<?php echo esc_attr($id); ?>"
                               name="<?php echo esc_attr($key); ?>"
                               value="1"
                               <?php checked($value, '1'); ?>
                               <?php echo $req ? 'required' : ''; ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                    <?php break;

                case 'file': ?>
                    <input type="file"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           accept="<?php echo esc_attr(implode(',', array_map(fn($t) => '.' . $t, $field['allowed_types'] ?? ['pdf', 'jpg', 'png']))); ?>"
                           <?php echo $req ? 'required' : ''; ?> />
                    <?php break;

                case 'email': ?>
                    <input type="email"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($value); ?>"
                           placeholder="<?php echo esc_attr($ph); ?>"
                           <?php echo $req ? 'required' : ''; ?> />
                    <?php break;

                case 'tel': ?>
                    <input type="tel"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($value); ?>"
                           placeholder="<?php echo esc_attr($ph); ?>"
                           <?php echo $req ? 'required' : ''; ?> />
                    <?php break;

                case 'date': ?>
                    <input type="date"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($value); ?>"
                           <?php echo $req ? 'required' : ''; ?> />
                    <?php break;

                case 'number': ?>
                    <input type="number"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($value); ?>"
                           placeholder="<?php echo esc_attr($ph); ?>"
                           <?php echo $req ? 'required' : ''; ?> />
                    <?php break;

                case 'session_picker': ?>
                    <?php echo $this->render_session_picker($key, $field, $req); ?>
                    <?php break;

                default: ?>
                    <input type="text"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($value); ?>"
                           placeholder="<?php echo esc_attr($ph); ?>"
                           <?php echo $req ? 'required' : ''; ?> />
            <?php endswitch; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a session picker: the series' bookable occurrences grouped by
     * date, each with price/remaining data, full sessions disabled with a
     * waitlist link.
     */
    private function render_session_picker(string $key, array $field, bool $required): string
    {
        $event_id = $this->render_event_id;
        if (!$event_id) {
            return '';
        }

        $service = new SessionBookingService();
        $occurrences = $service->get_bookable_sessions((int) $event_id);

        if (empty($occurrences)) {
            return '';
        }

        $multi = (string) ($field['picker_mode'] ?? 'multi') !== 'single';
        $input_type = $multi ? 'checkbox' : 'radio';
        $id = 'hmw_field_' . sanitize_key($key);
        $currency = $this->event_data()->get_currency((int) $event_id);
        $symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);

        $grouped = [];
        foreach ($occurrences as $occurrence) {
            $day = $occurrence['start'] !== '' ? substr($occurrence['start'], 0, 10) : 'tba';
            $grouped[$day][] = $occurrence;
        }

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');

        ob_start();
        ?>
        <div class="hmw-reg-field hmw-reg-field--session_picker hmw-reg-field--full" data-session-picker="<?php echo esc_attr($key); ?>">
            <fieldset class="hmw-session-picker">
                <?php foreach ($grouped as $day => $day_occurrences): ?>
                    <div class="hmw-session-picker-day">
                        <div class="hmw-session-picker-date"><?php echo $day !== 'tba' ? esc_html(date_i18n($date_format, strtotime($day))) : esc_html__('Date TBA', 'hmw-events'); ?></div>
                        <?php foreach ($day_occurrences as $occurrence): ?>
                            <?php
                            $opt_id = $id . '_' . (int) $occurrence['id'];
                            $time_text = $occurrence['start'] !== ''
                                ? date_i18n($time_format, strtotime($occurrence['start']))
                                : __('Time TBA', 'hmw-events');
                            $price_text = (float) $occurrence['price'] > 0
                                ? $symbol . number_format((float) $occurrence['price'], 2)
                                : __('Free', 'hmw-events');
                            ?>
                            <label for="<?php echo esc_attr($opt_id); ?>"
                                   class="hmw-session-picker-option<?php echo $occurrence['is_full'] ? ' hmw-session-picker-option--full' : ''; ?>">
                                <input type="<?php echo esc_attr($input_type); ?>"
                                       id="<?php echo esc_attr($opt_id); ?>"
                                       name="<?php echo esc_attr($multi ? $key . '[]' : $key); ?>"
                                       value="<?php echo esc_attr((string) $occurrence['id']); ?>"
                                       data-price="<?php echo esc_attr((string) (float) $occurrence['price']); ?>"
                                       data-session-title="<?php echo esc_attr($occurrence['title']); ?>"
                                       <?php disabled($occurrence['is_full']); ?> />
                                <span class="hmw-session-picker-main">
                                    <span class="hmw-session-picker-time"><?php echo esc_html($time_text); ?></span>
                                    <span class="hmw-session-picker-meta">
                                        <span class="hmw-session-picker-price"><?php echo esc_html($price_text); ?></span>
                                        <?php if ($occurrence['is_full']) : ?>
                                            <span class="hmw-session-picker-full">
                                                (<?php esc_html_e('Full', 'hmw-events'); ?> —
                                                <a href="<?php echo esc_url((string) get_permalink($occurrence['id'])); ?>"><?php esc_html_e('waitlist', 'hmw-events'); ?></a>)
                                            </span>
                                        <?php elseif ($occurrence['remaining'] !== null) : ?>
                                            <span class="hmw-session-picker-remaining"><?php echo esc_html(sprintf(__('%d left', 'hmw-events'), (int) $occurrence['remaining'])); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </fieldset>
            <p class="hmw-session-picker-total" data-session-total-for="<?php echo esc_attr($key); ?>"></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function render_field(string $key, array $field): string
    {
        $type    = $field['type'] ?? 'text';
        $label   = $field['label'] ?? '';
        $req     = !empty($field['required']);
        $width   = $field['width'] ?? 'full';
        $ph      = $field['placeholder'] ?? '';
        $value   = $this->get_post_value($key);

        $id      = 'hmw_field_' . $key;
        $classes = ['hmw-form-field', 'hmw-form-field--' . $type, 'hmw-form-field--' . $width];
        if ($req) {
            $classes[] = 'hmw-form-field--required';
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>">
            <label for="<?php echo esc_attr($id); ?>">
                <?php echo esc_html($label); ?>
                <?php if ($req): ?><span class="hmw-required" aria-hidden="true">*</span><?php endif; ?>
            </label>

            <?php switch ($type):
                case 'textarea': ?>
                    <textarea id="<?php echo esc_attr($id); ?>"
                              name="<?php echo esc_attr($key); ?>"
                              rows="<?php echo esc_attr($field['rows'] ?? 3); ?>"
                              placeholder="<?php echo esc_attr($ph); ?>"
                              <?php echo $req ? 'required' : ''; ?>><?php echo esc_textarea($value); ?></textarea>
                    <?php break;

                case 'select': ?>
                    <select id="<?php echo esc_attr($id); ?>"
                            name="<?php echo esc_attr($key); ?>"
                            <?php echo $req ? 'required' : ''; ?>>
                        <?php foreach (($field['options'] ?? []) as $opt_val => $opt_label): ?>
                            <option value="<?php echo esc_attr($opt_val); ?>"
                                    <?php selected($value, $opt_val); ?>>
                                <?php echo esc_html($opt_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php break;

                case 'file': ?>
                    <input type="file"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           accept="<?php echo esc_attr(implode(',', array_map(fn($t) => '.' . $t, $field['allowed_types'] ?? []))); ?>"
                           <?php echo $req ? 'required' : ''; ?>
                           data-max-size="<?php echo esc_attr($field['max_size'] ?? 0); ?>" />
                    <?php break;

                case 'email': ?>
                    <input type="email"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($value); ?>"
                           placeholder="<?php echo esc_attr($ph); ?>"
                           <?php echo $req ? 'required' : ''; ?> />
                    <?php break;

                case 'tel': ?>
                    <input type="tel"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($value); ?>"
                           placeholder="<?php echo esc_attr($ph); ?>"
                           <?php echo $req ? 'required' : ''; ?> />
                    <?php break;

                case 'date': ?>
                    <input type="date"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($value); ?>"
                           <?php echo $req ? 'required' : ''; ?> />
                    <?php break;

                case 'checkbox': ?>
                    <?php $attrs = $req ? 'required' : ''; ?>
                    <?php $checked = !empty($value) ? 'checked' : ''; ?>
                    <label class="hmwevents-field hmwevents-field--checkbox">
                        <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php echo $checked; ?> <?php echo $attrs; ?>>
                        <?php echo esc_html($field['label']); ?>
                    </label>
                    <?php break;

                case 'number': ?>
                    <input type="number"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($value); ?>"
                           class="hmwevents-input"
                           <?php echo $req ? 'required' : ''; ?> />
                    <?php break;

                case 'radio': ?>
                    <div class="hmwevents-radio-group">
                        <?php foreach (($field['options'] ?? []) as $opt_val => $opt_label): ?>
                            <?php $checked = ($value == $opt_val) ? 'checked' : ''; ?>
                            <label><input type="radio" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($opt_val); ?>" <?php echo $checked; ?> <?php echo $req ? 'required' : ''; ?>> <?php echo esc_html($opt_label); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <?php break;

                default: ?>
                    <input type="text"
                           id="<?php echo esc_attr($id); ?>"
                           name="<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($value); ?>"
                           placeholder="<?php echo esc_attr($ph); ?>"
                           <?php echo $req ? 'required' : ''; ?> />
            <?php endswitch; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ================================================================
    // SUBMISSION
    // ================================================================

    /**
     * Handle AJAX form submission.
     */
    public function handle_submission(): void
    {
        $event_id = (int) ($_POST['event_id'] ?? 0);
        $attendance_type = sanitize_text_field($_POST['attendance_type'] ?? 'individual');

        if (!$event_id || !wp_verify_nonce($_POST['_hmwevents_nonce'] ?? '', 'hmwevents_registration_' . $event_id)) {
            wp_send_json_error(['message' => __('Invalid request.', 'hmw-events')]);
        }

        $v3_config = $this->get_v3_sections($event_id);

        if (!$v3_config) {
            wp_send_json_error(['message' => __('Registration for this event is not available.', 'hmw-events')]);
        }

        $submission = new FormSubmissionService();
        $result = $submission->submit_and_pay(array_merge($_POST, $_FILES), $event_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $confirmation_url = $this->get_confirmation_url();
        if ($confirmation_url && !empty($result['booking_id'])) {
            $booking_reference = sanitize_text_field((string) ($result['booking_number'] ?? ''));
            if ($booking_reference !== '') {
                (new SessionFlash())->message('hmwevents_confirmed_booking', $booking_reference);
                $confirmation_token = bin2hex(random_bytes(16));
                set_transient('hmwevents_confirmation_' . hash('sha256', $confirmation_token), $booking_reference, 10 * MINUTE_IN_SECONDS);
                if (!headers_sent()) {
                    setcookie('hmwevents_confirmation', $booking_reference, [
                        'expires'  => time() + (10 * MINUTE_IN_SECONDS),
                        'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
                        'secure'   => is_ssl(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }
                $result['redirect'] = add_query_arg('hmwevents_token', $confirmation_token, $confirmation_url);
            }
        }

        wp_send_json_success($result);
    }

    // ================================================================
    // HELPERS
    // ================================================================

    private function get_post_value(string $dot_key): string
    {
        if (str_contains($dot_key, '[')) {
            $parts = preg_split('/\[([^\]]*)\]/', $dot_key, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
            $value = $_POST;
            foreach ($parts as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return '';
                }
                $value = $value[$part];
            }
            return is_scalar($value) ? (string) $value : '';
        }

        return $_POST[$dot_key] ?? '';
    }

    private function get_event_type_slug(int $event_id): string
    {
        $terms = wp_get_object_terms($event_id, 'hmw_event_type');
        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0]->slug;
        }
        return '';
    }

    private function get_event_audience_slug(int $event_id): string
    {
        $terms = wp_get_object_terms($event_id, 'hmw_event_audience');
        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0]->slug;
        }
        return '';
    }

    private function get_confirmation_url(): string
    {
        $page = get_page_by_path('booking-confirmation');
        $url = $page ? get_permalink($page) : '';

        return (string) apply_filters('hmwevents_confirmation_url', $url);
    }
}
