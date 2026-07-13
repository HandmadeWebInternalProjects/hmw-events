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

use HMWEvents\Registry\RegistrationFieldRegistry;
use HMWEvents\Registry\EventTypeRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class RegistrationFormRenderer
{
    private RegistrationFormPreset $presets;
    private DocumentUploadHandler $uploads;

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

        $snapshot_config = get_post_meta($event_id, '_event_field_config', true);
        $snapshot_defaults = get_post_meta($event_id, '_event_default_values', true);
        $has_snapshot_config = is_array($snapshot_config)
            && isset($snapshot_config['registration_fields'])
            && is_array($snapshot_config['registration_fields']);

        $template_override = get_post_meta($event_id, '_event_template_override', true);
        $has_template_override = is_array($template_override)
            && isset($template_override['registration_fields'])
            && is_array($template_override['registration_fields']);

        if ($has_template_override) {
            $fields = $this->apply_registration_template_override(
                $fields,
                (array) $template_override['registration_fields'],
                is_array($snapshot_defaults) ? $snapshot_defaults : []
            );
            return $fields;
        }

        if ($has_snapshot_config) {
            $fields = $this->apply_registration_snapshot_config(
                $fields,
                (array) $snapshot_config['registration_fields'],
                is_array($snapshot_defaults) ? $snapshot_defaults : []
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

        $v3_config = $this->get_v3_sections($event_id);

        if ($v3_config) {
            return $this->render_v3_form($event_id, $atts['attendance_type'], $v3_config);
        }

        $fields = $this->get_fields_for_event($event_id, $atts['attendance_type']);
        $sections = $this->group_fields_by_section($fields);

        ob_start();
        ?>
        <form class="hmw-registration-form" method="post" enctype="multipart/form-data"
              action="" data-event-id="<?php echo esc_attr($event_id); ?>"
              data-attendance-type="<?php echo esc_attr($atts['attendance_type']); ?>">

            <?php wp_nonce_field('hmwevents_registration_' . $event_id, '_hmwevents_nonce'); ?>
            <input type="hidden" name="event_id" value="<?php echo esc_attr($event_id); ?>" />
            <input type="hidden" name="attendance_type" value="<?php echo esc_attr($atts['attendance_type']); ?>" />
            <input type="hidden" name="action" value="hmwevents_submit_registration" />

            <?php foreach ($sections as $section_key => $section): ?>
                <fieldset class="hmw-form-section hmw-form-section--<?php echo esc_attr($section_key); ?>">
                    <legend class="hmw-form-section__title">
                        <?php echo esc_html($section['label']); ?>
                    </legend>

                    <div class="hmw-form-fields">
                        <?php foreach ($section['fields'] as $field_key => $field): ?>
                            <?php echo $this->render_field($field_key, $field); ?>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>

            <div class="hmw-form-actions">
                <button type="submit" class="hmw-btn hmw-btn--primary">
                    <?php esc_html_e('Submit Registration', 'hmw-events'); ?>
                </button>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    private function get_v3_sections(int $event_id): ?array
    {
        $override = get_post_meta($event_id, '_event_template_override', true);
        if (is_array($override) && !empty($override['registration_fields']['sections'])) {
            return $override['registration_fields'];
        }

        $config = get_post_meta($event_id, '_event_field_config', true);
        if (is_array($config) && !empty($config['registration_fields']['sections'])) {
            return $config['registration_fields'];
        }

        return null;
    }

    private function render_v3_form(int $event_id, string $attendance_type, array $reg_config): string
    {
        $sections = $reg_config['sections'] ?? [];
        $multi_booking = $reg_config['multi_booking'] ?? ['enabled' => false, 'min' => 1, 'max' => 10];
        $mb_enabled = (bool) ($multi_booking['enabled'] ?? false);

        $price = (float) get_post_meta($event_id, '_event_price', true) ?: 0;
        $requires_payment = $price > 0;

        wp_enqueue_style(
            'hmwevents-v3-booking',
            \HMWEvents\HMWEvents::plugin_url() . '/assets/css/v3-booking.css',
            [],
            defined('HMWEvents_VERSION') ? HMWEvents_VERSION : '1.0.0'
        );

        $all_per_attendee = [];
        $all_global = [];

        foreach ($sections as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if ($mb_enabled && !empty($field['per_attendee'])) {
                    $all_per_attendee[] = $field;
                } else {
                    $all_global[] = $field;
                }
            }
        }

        if ($requires_payment) {
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
            wp_enqueue_script(
                'hmwevents-v3-booking',
                \HMWEvents\HMWEvents::plugin_url() . '/assets/js/v3-booking.js',
                ['jquery', 'stripe-js'],
                defined('HMWEvents_VERSION') ? HMWEvents_VERSION : '1.0.0',
                true
            );
            $publishable_key = \HMWEvents\Helpers\StripeHelper::get_publishable_key(null);
            $has_stripe = !empty($publishable_key);
            wp_localize_script('hmwevents-v3-booking', 'hmwV3Booking', [
                'ajaxUrl'         => admin_url('admin-ajax.php'),
                'restUrl'         => rest_url('hmwevents/v1/registration/v3-submit'),
                'nonce'           => wp_create_nonce('hmwevents_registration_' . $event_id),
                'stripeKey'       => $has_stripe ? $publishable_key : '',
                'eventId'         => $event_id,
                'price'           => $price,
                'currency'        => 'aud',
                'priceLabel'      => '$' . number_format($price, 2),
                'isMultiBooking'  => $mb_enabled,
                'maxAttendees'    => $multi_booking['max'] ?? 10,
            ]);
        } else {
            $has_stripe = false;
        }

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
            <input type="hidden" name="attendance_type" value="<?php echo esc_attr($attendance_type); ?>" />
            <input type="hidden" name="action" value="hmwevents_submit_registration" />
            <input type="hidden" name="attendee_count" value="1" id="hmw_attendee_count_hidden" />

            <?php foreach ($sections as $section): ?>
                <div class="hmw-reg-section">
                    <h3 class="hmw-reg-section-title"><?php echo esc_html($section['label'] ?? ''); ?></h3>
                    <div class="hmw-reg-grid">
                        <?php foreach ($section['fields'] ?? [] as $field): ?>
                            <?php if ($mb_enabled && !empty($field['per_attendee'])): ?>
                                <?php echo $this->render_field_v3('attendees[0][' . $field['key'] . ']', $field); ?>
                            <?php else: ?>
                                <?php echo $this->render_field_v3($field['key'], $field); ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($mb_enabled && !empty($all_per_attendee)): ?>
                <div class="hmw-reg-section hmw-reg-section--attendees" id="hmw-v3-additional-attendees">
                    <h3 class="hmw-reg-section-title"><?php esc_html_e('Additional Attendees', 'hmw-events'); ?></h3>
                    <p class="hmw-reg-section-desc"><?php esc_html_e('Add other people attending with you.', 'hmw-events'); ?></p>
                    <div id="hmw-v3-attendee-blocks"></div>
                    <button type="button" class="hmw-btn hmw-btn--secondary hmw-v3-add-attendee" id="hmw-v3-add-attendee-btn">
                        + <?php esc_html_e('Add Attendee', 'hmw-events'); ?>
                    </button>
                </div>
            <?php endif; ?>

            <?php if ($requires_payment): ?>
                <div class="hmw-reg-section hmw-reg-section--payment">
                    <h3 class="hmw-reg-section-title"><?php esc_html_e('Payment', 'hmw-events'); ?></h3>
                    <div class="hmw-reg-payment-summary">
                        <p><?php printf(esc_html__('Total: %s', 'hmw-events'), '<strong>$' . number_format($price, 2) . '</strong>'); ?></p>
                    </div>
                    <?php if ($has_stripe): ?>
                    <div id="hmwevents-card-element" class="hmwevents-stripe-element" style="padding:12px;border:1px solid #d1d5db;border-radius:4px;background:#fff;min-height:42px;"></div>
                    <div id="hmwevents-card-errors" style="color:#b32d2e; margin-top:4px;"></div>
                    <?php else: ?>
                    <p style="color:#b32d2e;"><?php esc_html_e('Payment system is not configured. Please contact the site administrator.', 'hmw-events'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="hmw-form-actions">
                <button type="submit" class="hmw-btn hmw-btn--primary hmw-v3-submit-btn">
                    <?php echo $requires_payment
                        ? esc_html__('Pay $' . number_format($price, 2) . ' — Submit Registration', 'hmw-events')
                        : esc_html__('Submit Registration', 'hmw-events'); ?>
                </button>
                <div id="hmwevents-submit-spinner" class="hmwevents-spinner" style="display:none;"></div>
            </div>
        </form>
        </div>

        <?php if ($mb_enabled && !empty($all_per_attendee)): ?>
        <template id="hmw-v3-attendee-template">
            <div class="hmw-v3-attendee-block" data-attendee-index="1">
                <div class="hmw-v3-attendee-block-header">
                    <h4 class="hmw-reg-attendee-title"></h4>
                    <button type="button" class="hmw-v3-remove-attendee">&times;</button>
                </div>
                <div class="hmw-reg-grid">
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
        $label   = $field['label'] ?? '';
        $req     = !empty($field['required']);
        $width   = $field['width'] ?? 'full';
        $ph      = $field['placeholder'] ?? '';
        $value   = $_POST[$key] ?? '';

        $id      = 'hmw_field_' . sanitize_key($key);
        $classes = ['hmw-reg-field', 'hmw-reg-field--' . $type, 'hmw-reg-field--' . $width];
        if ($req) {
            $classes[] = 'hmw-reg-field--required';
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>">
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

    public function render_field(string $key, array $field): string
    {
        $type    = $field['type'] ?? 'text';
        $label   = $field['label'] ?? '';
        $req     = !empty($field['required']);
        $width   = $field['width'] ?? 'full';
        $ph      = $field['placeholder'] ?? '';
        $value   = $_POST[$key] ?? '';

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
        $event_id       = (int) ($_POST['event_id'] ?? 0);
        $attendance_type = sanitize_text_field($_POST['attendance_type'] ?? 'individual');

        if (!$event_id || !wp_verify_nonce($_POST['_hmwevents_nonce'] ?? '', 'hmwevents_registration_' . $event_id)) {
            wp_send_json_error(['message' => __('Invalid request.', 'hmw-events')]);
        }

        $v3_config = $this->get_v3_sections($event_id);

        if ($v3_config) {
            $submission = new FormSubmissionService();
            $result = $submission->submit_and_pay(array_merge($_POST, $_FILES), $event_id);

            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            }

            wp_send_json_success($result);
            return;
        }

        $fields = $this->get_fields_for_event($event_id, $attendance_type);

        $errors = [];
        $data = [];
        foreach ($fields as $key => $field) {
            $value = $_POST[$key] ?? '';

            if ($field['type'] === 'file') {
                continue;
            }

            if (!empty($field['required']) && trim((string) $value) === '') {
                $errors[] = sprintf(
                    __('%s is required.', 'hmw-events'),
                    $field['label']
                );
                continue;
            }

            if ($field['type'] === 'email' && !empty($value) && !is_email($value)) {
                $errors[] = __('Please enter a valid email address.', 'hmw-events');
                continue;
            }

            if ($field['source'] === RegistrationFieldRegistry::SOURCE_REGISTRANT_META) {
                $data['meta'][$field['meta_key'] ?? $key] = sanitize_text_field($value);
            } else {
                $data['details'][$key] = sanitize_text_field($value);
            }
        }

        $files = $_FILES;
        foreach ($fields as $key => $field) {
            if ($field['type'] !== 'file' || empty($files[$key]['name'])) {
                continue;
            }

            $upload_result = $this->uploads->handle_upload(
                $files[$key],
                (int) ($data['booking_id'] ?? 0),
                $event_id
            );

            if (is_wp_error($upload_result)) {
                $errors[] = $upload_result->get_error_message();
            } else {
                $data['documents'][] = $upload_result;
            }
        }

        if (!empty($errors)) {
            wp_send_json_error(['message' => implode('<br>', $errors)]);
        }

        do_action('hmwevents_registration_validated', $event_id, $data, $attendance_type);

        wp_send_json_success([
            'message'  => __('Registration submitted successfully!', 'hmw-events'),
            'event_id' => $event_id,
        ]);
    }

    // ================================================================
    // HELPERS
    // ================================================================

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
}
