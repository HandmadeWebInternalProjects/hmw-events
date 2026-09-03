<?php

namespace HMWEvents\Admin;

use HMWEvents\Services\AttendancePricingService;
use HMWEvents\Services\DatabaseService;
use HMWEvents\Services\EventDataService;
use HMWEvents\Services\EventFormFieldsResolver;
use HMWEvents\Services\FormConfigResolver;
use HMWEvents\PostTypes\Event;
use HMWEvents\Registry\EventTypeRegistry;
use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventBookings
{
    public function register(): void
    {
        add_action('add_meta_boxes_' . Event::POST_TYPE, [$this, 'add_meta_box']);
        add_action('wp_ajax_hmwevents_get_booking_details', [$this, 'ajax_get_booking_details']);
        add_action('wp_ajax_hmwevents_generate_token', [$this, 'ajax_generate_token']);
        add_action('wp_ajax_hmwevents_promote_waitlist', [$this, 'ajax_promote_waitlist']);
    }

    public function add_meta_box(): void
    {
        add_meta_box(
            'hmwevents_event_bookings',
            __('Event Bookings', 'hmw-events'),
            [$this, 'render_meta_box'],
            Event::POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render_meta_box(\WP_Post $post): void
    {
        $bookings = $this->get_bookings_for_event($post->ID);
        $group_labels = $this->get_group_labels($bookings);

        $type_terms = wp_get_object_terms($post->ID, 'hmw_event_type', ['fields' => 'slugs']);
        $type_slug = (!is_wp_error($type_terms) && !empty($type_terms)) ? sanitize_key((string) $type_terms[0]) : '';

        $registration_disabled = EventTypeRegistry::is_registration_disabled($type_slug);
        $external_registration = EventTypeRegistry::is_external_registration($type_slug);

        ?>
        <div class="hmwevents-bookings-wrap">

            <?php if ($external_registration): ?>
                <p class="description"><?php esc_html_e('This event uses external registration. No internal bookings will be created.', 'hmw-events'); ?></p>
            <?php elseif ($registration_disabled): ?>
                <p class="description"><?php esc_html_e('Public registration is disabled for this event type. Bookings must be added manually.', 'hmw-events'); ?></p>
            <?php endif; ?>

            <?php if (empty($bookings)): ?>
                <p><?php esc_html_e('No bookings yet.', 'hmw-events'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped hmwevents-bookings-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Booking #', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Customer', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Email', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Amount', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Status', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Payment', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Date', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Actions', 'hmw-events'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($booking->booking_number); ?></strong>
                                    <?php if (!empty($group_labels[(int) $booking->id])): ?>
                                        <br><span class="hmwevents-group-badge" style="display:inline-block;margin-top:3px;padding:1px 8px;border-radius:10px;background:#f0f6fc;color:#1d4ed8;border:1px solid #c3d4e8;font-size:11px;"><?php echo esc_html($group_labels[(int) $booking->id]); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(trim($booking->customer_first_name . ' ' . $booking->customer_last_name) ?: $booking->customer_name); ?></td>
                                <td><?php echo esc_html($booking->registrant_email); ?></td>
                                <td><?php echo esc_html(number_format((float) $booking->booking_amount, 2)); ?></td>
                                <td><?php echo esc_html($this->status_label($booking->status)); ?></td>
                                <td><?php echo esc_html($this->payment_status_label($booking->payment_status)); ?></td>
                                <td><?php echo esc_html(date('Y-m-d', strtotime($booking->created_at))); ?></td>
                                <td class="hmwevents-row-actions">
                                    <a href="#" class="hmwevents-edit-booking"
                                       data-booking-id="<?php echo (int) $booking->id; ?>"
                                       data-nonce="<?php echo esc_attr(wp_create_nonce('hmwevents_get_booking_details')); ?>">
                                        <?php esc_html_e('Edit', 'hmw-events'); ?>
                                    </a>
                                    <span class="separator">|</span>
                                    <a href="#" class="hmwevents-resend-confirmation"
                                       data-booking-id="<?php echo (int) $booking->id; ?>">
                                        <?php esc_html_e('Resend', 'hmw-events'); ?>
                                    </a>
                                    <?php if ($booking->payment_status === 'pending'): ?>
                                        <span class="separator">|</span>
                                        <a href="#" class="hmwevents-send-payment-link"
                                           data-booking-id="<?php echo (int) $booking->id; ?>">
                                            <?php esc_html_e('Payment Link', 'hmw-events'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($booking->payment_status === 'invoiced'): ?>
                                        <span class="separator">|</span>
                                        <a href="#" class="hmwevents-resend-invoice"
                                           data-booking-id="<?php echo (int) $booking->id; ?>">
                                            <?php esc_html_e('Resend Invoice', 'hmw-events'); ?>
                                        </a>
                                        <span class="separator">|</span>
                                        <a href="#" class="hmwevents-mark-invoice-paid"
                                           data-booking-id="<?php echo (int) $booking->id; ?>">
                                            <?php esc_html_e('Mark Paid (EFT)', 'hmw-events'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($booking->booking_source === 'manual' && in_array($booking->payment_status, ['pending', 'failed'], true)): ?>
                                        <span class="separator">|</span>
                                        <a href="#" class="hmwevents-mark-as-paid"
                                           data-booking-id="<?php echo (int) $booking->id; ?>">
                                            <?php esc_html_e('Mark as Paid', 'hmw-events'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php
            $waitlist_service = new \HMWEvents\Services\WaitlistService();
            $waitlist_entries = $waitlist_service->get_for_event($post->ID, 'waiting');

            if (!empty($waitlist_entries)): ?>
                <h3 style="margin-top:20px;"><?php esc_html_e('Waitlist', 'hmw-events'); ?> (<?php echo count($waitlist_entries); ?>)</h3>
                <table class="wp-list-table widefat fixed striped hmwevents-waitlist-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Position', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Name', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Email', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Joined', 'hmw-events'); ?></th>
                            <th><?php esc_html_e('Actions', 'hmw-events'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($waitlist_entries as $entry):
                            $registrant = get_post($entry->registrant_post_id);
                            $email = $registrant ? get_post_meta($registrant->ID, 'registrant_email', true) : '';
                            $first_name = $registrant ? get_post_meta($registrant->ID, 'registrant_first_name', true) : '';
                            $last_name = $registrant ? get_post_meta($registrant->ID, 'registrant_last_name', true) : '';
                            $name = trim($first_name . ' ' . $last_name);
                        ?>
                            <tr>
                                <td><?php echo (int) $entry->position; ?></td>
                                <td><?php echo esc_html($name ?: __('Unknown', 'hmw-events')); ?></td>
                                <td><?php echo esc_html($email); ?></td>
                                <td><?php echo esc_html(date('Y-m-d', strtotime($entry->created_at))); ?></td>
                                <td>
                                    <button type="button" class="button button-small hmwevents-promote-waitlist"
                                        data-entry-id="<?php echo (int) $entry->id; ?>"
                                        data-event-id="<?php echo (int) $post->ID; ?>"
                                        data-nonce="<?php echo esc_attr(wp_create_nonce('hmwevents_promote_waitlist')); ?>">
                                        <?php esc_html_e('Promote', 'hmw-events'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!$external_registration): ?>
                <p style="margin-top:12px;">
                    <a href="#"
                       class="button button-primary"
                       id="hmwevents-add-booking-btn">
                        <?php esc_html_e('Add Manual Booking', 'hmw-events'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>

        <?php if (\HMWEvents\PostTypes\Event::is_invitation_only($post->ID)): ?>
            <div style="margin-top:16px; padding:12px; background:#f0f6fc; border:1px solid #72aee6; border-radius:3px;">
                <h3 style="margin-top:0;"><?php esc_html_e('Invitation Tokens', 'hmw-events'); ?></h3>
                <p class="description"><?php esc_html_e('Generate a private registration link. Tokens are single-use and expire after 48 hours by default.', 'hmw-events'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><label for="hmw-token-email"><?php esc_html_e('Recipient Email', 'hmw-events'); ?></label></th>
                        <td><input type="email" id="hmw-token-email" class="regular-text" placeholder="attendee@example.com"></td>
                    </tr>
                </table>

                <button type="button" class="button button-primary" id="hmwevents-generate-token" data-event-id="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('hmwevents_generate_token')); ?>">
                    <?php esc_html_e('Generate Registration Link', 'hmw-events'); ?>
                </button>

                <div id="hmwevents-token-result" style="margin-top:8px; display:none;"></div>
            </div>
        <?php endif; ?>

        <?php if (!$external_registration): ?>
        <?php $manual_ctx = $this->manual_booking_context($post->ID); ?>
        <div id="hmwevents-add-booking-modal" class="hmwevents-modal" style="display:none;">
            <div class="hmwevents-modal-content">
                <span id="hmwevents-add-booking-modal-close" class="hmwevents-modal-close">&times;</span>
                <h2><?php esc_html_e('Add Manual Booking', 'hmw-events'); ?></h2>

                <?php
                $selection = $manual_ctx['selection'];
                $allowed_roles = $selection['allowed_roles'];
                $effective_multi = $selection['effective_multi'];
                $has_age_pricing = $selection['has_age_pricing'];
                $needs_identity = $manual_ctx['needs_identity'];
                $is_parent_children = $manual_ctx['parent_children'];
                ?>

                <div id="hmwevents-add-booking-form">
                    <input type="hidden" name="event_id" value="<?php echo (int) $post->ID; ?>">

                    <?php if (count($selection['options']) <= 1): ?>
                        <input type="hidden" name="attendance_type" value="<?php echo esc_attr($selection['default_option_type']); ?>" />
                    <?php endif; ?>

                    <table class="form-table">
                        <?php if (count($selection['options']) > 1): ?>
                            <tr>
                                <th><label><?php esc_html_e('Attendance Option', 'hmw-events'); ?></label></th>
                                <td>
                                    <div class="hmwevents-attendance-options">
                                        <?php foreach ($selection['options'] as $option): ?>
                                            <label class="hmwevents-attendance-option">
                                                <input type="radio" name="attendance_type" value="<?php echo esc_attr($option['option_type']); ?>" <?php checked($selection['default_option_type'], $option['option_type']); ?> />
                                                <span class="hmwevents-attendance-option-label"><?php echo esc_html($option['label']); ?></span>
                                                <?php if (!$selection['is_free'] && ((float) $option['display_price'] > 0 || $selection['has_paid_option'])): ?>
                                                    <span class="hmwevents-attendance-option-price">
                                                        <?php echo esc_html(($option['price_mode'] ?? 'flat') === AttendancePricingService::MODE_FLAT
                                                            ? '$' . number_format((float) $option['display_price'], 2)
                                                            : __('From', 'hmw-events') . ' $' . number_format((float) $option['display_price'], 2)); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($option['max_bookings'] !== null): ?>
                                                    <span class="hmwevents-attendance-option-remaining">
                                                        <?php
                                                        /* translators: %d is the number of bookings remaining for this option */
                                                        echo esc_html(sprintf(__('%d bookings remaining', 'hmw-events'), (int) $option['remaining_bookings']));
                                                        ?>
                                                    </span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php if ($needs_identity && ($has_age_pricing || (!$is_parent_children && count($allowed_roles) > 1))): ?>
                            <?php if (!$is_parent_children && count($allowed_roles) > 1): ?>
                                <tr>
                                    <th><label for="hmw-manual-role-0"><?php esc_html_e('Attendee Type', 'hmw-events'); ?> <span class="required">*</span></label></th>
                                    <td>
                                        <select id="hmw-manual-role-0" name="attendees[0][attendee_role]" data-required>
                                            <?php foreach ($allowed_roles as $role): ?>
                                                <option value="<?php echo esc_attr($role); ?>"><?php echo esc_html(ucfirst($role)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($has_age_pricing): ?>
                                <tr class="hmwevents-age-dob">
                                    <th><label for="hmw-manual-dob-0"><?php esc_html_e('Date of Birth', 'hmw-events'); ?> <span class="required">*</span></label></th>
                                    <td>
                                        <input type="date" id="hmw-manual-dob-0" name="attendees[0][date_of_birth]" data-required max="<?php echo esc_attr(gmdate('Y-m-d')); ?>" />
                                        <p class="description"><?php esc_html_e('Required for age-based pricing — used to calculate this attendee\'s fee.', 'hmw-events'); ?></p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php elseif ($is_parent_children): ?>
                            <input type="hidden" name="attendees[0][attendee_role]" value="adult" />
                        <?php elseif (count($allowed_roles) === 1): ?>
                            <input type="hidden" name="attendees[0][attendee_role]" value="<?php echo esc_attr($allowed_roles[0]); ?>" />
                        <?php endif; ?>

                        <?php foreach ($manual_ctx['main_fields'] as $field):
                            $name = EventFormFieldsResolver::canonical_field_name($field['key']);
                            $input_id = 'hmw-manual-' . sanitize_html_class($field['key']);
                        ?>
                            <tr>
                                <th><label for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($field['label']); ?><?php if (!empty($field['required'])): ?> <span class="required">*</span><?php endif; ?></label></th>
                                <td><?php $this->render_field_control($field, $name, $input_id); ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ($effective_multi && !$is_parent_children): ?>
                            <?php foreach ($manual_ctx['per_attendee_fields'] as $field):
                                $input_id = 'hmw-manual-att0-' . sanitize_html_class($field['key']);
                            ?>
                                <tr class="hmwevents-per-attendee-row">
                                    <th><label for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($field['label']); ?><?php if (!empty($field['required'])): ?> <span class="required">*</span><?php endif; ?></label></th>
                                    <td><?php $this->render_field_control($field, 'attendees[0][' . $field['key'] . ']', $input_id); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>

                    <?php if ($manual_ctx['show_repeater']): ?>
                        <div
                            id="hmwevents-manual-attendees"
                            class="hmwevents-attendees-section"
                            data-title-prefix="<?php echo esc_attr($is_parent_children ? 'Child' : 'Attendee'); ?>"
                        >
                            <h3 style="margin-bottom:4px;">
                                <?php echo $is_parent_children
                                    ? esc_html__('Children', 'hmw-events')
                                    : esc_html__('Additional Attendees', 'hmw-events'); ?>
                            </h3>
                            <p class="description">
                                <?php echo $is_parent_children
                                    ? esc_html__('Add the children attending with you.', 'hmw-events')
                                    : esc_html__('Add other people attending with you.', 'hmw-events'); ?>
                            </p>

                            <div class="hmwevents-attendee-blocks"></div>

                            <button type="button" class="button hmwevents-add-attendee">
                                + <?php echo $is_parent_children
                                    ? esc_html__('Add Child', 'hmw-events')
                                    : esc_html__('Add Attendee', 'hmw-events'); ?>
                            </button>
                        </div>
                    <?php endif; ?>

                    <p class="hmwevents-manual-total"<?php echo ($selection['default_display_price'] > 0 || $selection['has_paid_option']) ? '' : ' style="display:none;"'; ?>>
                        <?php esc_html_e('Total:', 'hmw-events'); ?>
                        <strong class="hmwevents-total-value">$<?php echo number_format($selection['default_display_price'], 2); ?></strong>
                    </p>

                    <div id="hmwevents-add-booking-message" style="display:none; margin:12px 0;"></div>

                    <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                        <button type="button" class="button" id="hmwevents-add-booking-cancel"><?php esc_html_e('Cancel', 'hmw-events'); ?></button>
                        <button type="button" class="button button-primary" id="hmwevents-add-booking-submit"><?php esc_html_e('Create Booking', 'hmw-events'); ?></button>
                    </div>
                </div>

                <?php if ($manual_ctx['show_repeater']): ?>
                <template id="hmwevents-manual-attendee-template">
                    <div class="hmwevents-attendee-block">
                        <div class="hmwevents-attendee-block-header">
                            <strong class="hmwevents-attendee-title"></strong>
                            <button type="button" class="button-link hmwevents-remove-attendee" title="<?php esc_attr_e('Remove', 'hmw-events'); ?>">&times;</button>
                        </div>
                        <div class="hmwevents-attendee-grid">
                            <?php $this->render_attendee_block_fields($manual_ctx); ?>
                        </div>
                    </div>
                </template>
                <?php endif; ?>

                <script type="application/json" id="hmwevents-add-booking-config"><?php
                    echo wp_json_encode([
                        'eventId'          => $post->ID,
                        'eventDate'        => $manual_ctx['event_date'],
                        'options'          => $selection['options'],
                        'defaultOptionType' => $selection['default_option_type'],
                        'basePrice'        => $selection['base_price'],
                        'surcharge'        => $selection['surcharge'],
                        'isFree'           => $selection['is_free'],
                        'requiresPayment'  => $selection['default_display_price'] > 0 || $selection['has_paid_option'],
                        'minAttendees'     => $selection['min_attendees'],
                        'maxAttendees'     => $selection['max_attendees'],
                        'allowedRoles'     => $allowed_roles,
                        'hasAgePricing'    => $has_age_pricing,
                        'parentChildren'   => $is_parent_children,
                    ]);
                ?></script>
            </div>
        </div>
        <?php endif; ?>

        <div id="hmwevents-edit-booking-modal" class="hmwevents-modal" style="display:none;">
            <div class="hmwevents-modal-content">
                <span id="hmwevents-edit-booking-modal-close" class="hmwevents-modal-close">&times;</span>
                <h2 id="hmwevents-edit-booking-modal-title"><?php esc_html_e('Edit Booking', 'hmw-events'); ?></h2>
                <div id="hmwevents-edit-booking-modal-body"></div>
            </div>
        </div>
        <?php
    }

    private function field_options(array $options): array
    {
        $normalized = [];

        foreach ($options as $option_value => $option_label) {
            if (is_array($option_label) && isset($option_label['value'])) {
                $normalized[] = [(string) $option_label['value'], (string) ($option_label['label'] ?? $option_label['value'])];
            } else {
                $normalized[] = [(string) $option_value, (string) $option_label];
            }
        }

        return $normalized;
    }

    private function manual_booking_context(int $event_id): array
    {
        $config = FormConfigResolver::resolve($event_id);
        $multi_booking = is_array($config) ? ($config['multi_booking'] ?? []) : [];
        $mb_enabled = (bool) ($multi_booking['enabled'] ?? false);
        $mb_mode = (string) ($multi_booking['mode'] ?? 'attendees');
        $is_parent_children = $mb_mode === 'parent_children';

        $main_fields = [];
        $per_attendee_fields = [];
        foreach (EventFormFieldsResolver::for_event($event_id) as $field) {
            if (!empty($field['per_attendee'])) {
                $per_attendee_fields[] = $field;
            } else {
                $main_fields[] = $field;
            }
        }

        $selection = (new AttendancePricingService())->build_selection_context($event_id, '', $multi_booking, $mb_enabled);

        $needs_identity = $selection['effective_multi'] || $selection['has_age_pricing'];

        $child_fields = is_array($multi_booking['child_fields'] ?? null) ? $multi_booking['child_fields'] : [];
        $child_name_cfg = is_array($child_fields['name'] ?? null) ? $child_fields['name'] : [];
        $child_age_cfg = is_array($child_fields['age'] ?? null) ? $child_fields['age'] : [];

        return [
            'selection'           => $selection,
            'main_fields'         => $main_fields,
            'per_attendee_fields' => $per_attendee_fields,
            'parent_children'     => $is_parent_children,
            'needs_identity'      => $needs_identity,
            'show_repeater'       => $selection['effective_multi']
                && ($needs_identity || !empty($per_attendee_fields) || $is_parent_children),
            'child_name'          => [
                'enabled'  => (bool) ($child_name_cfg['enabled'] ?? true),
                'required' => (bool) ($child_name_cfg['required'] ?? true),
                'label'    => sanitize_text_field((string) ($child_name_cfg['label'] ?? '')) ?: __('Child Name', 'hmw-events'),
            ],
            'child_age'           => [
                'enabled'  => (bool) ($child_age_cfg['enabled'] ?? true),
                'required' => (bool) ($child_age_cfg['required'] ?? false),
                'label'    => sanitize_text_field((string) ($child_age_cfg['label'] ?? '')) ?: __('Date of Birth', 'hmw-events'),
            ],
            'event_date'          => (new EventDataService())->get_start_date($event_id),
        ];
    }

    private function render_field_control(array $field, string $name, string $input_id): void
    {
        $required = !empty($field['required']);
        $type = $field['type'] ?? 'text';
        $options = $field['options'] ?? [];

        if ($type === 'textarea') {
            ?>
            <textarea name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($input_id); ?>" class="large-text" rows="3"<?php echo $required ? ' data-required' : ''; ?>></textarea>
            <?php
        } elseif ($type === 'select') {
            ?>
            <select name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($input_id); ?>"<?php echo $required ? ' data-required' : ''; ?>>
                <?php foreach ($this->field_options($options) as [$option_value, $option_label]): ?>
                    <option value="<?php echo esc_attr($option_value); ?>"><?php echo esc_html($option_label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php
        } elseif ($type === 'checkbox') {
            ?>
            <label><input type="checkbox" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($input_id); ?>" value="1"></label>
            <?php
        } elseif ($type === 'radio') {
            ?>
            <div>
                <?php foreach ($this->field_options($options) as [$option_value, $option_label]): ?>
                    <label style="margin-right:12px;">
                        <input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($option_value); ?>">
                        <?php echo esc_html($option_label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php
        } else {
            ?>
            <input type="<?php echo esc_attr($type === 'tel' ? 'text' : $type); ?>" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($input_id); ?>" class="regular-text"<?php echo $required ? ' data-required' : ''; ?>>
            <?php
        }
    }

    private function render_attendee_block_fields(array $manual_ctx): void
    {
        $selection = $manual_ctx['selection'];
        $allowed_roles = $selection['allowed_roles'];
        $has_age_pricing = $selection['has_age_pricing'];
        $is_parent_children = $manual_ctx['parent_children'];
        ?>
        <?php if ($is_parent_children): ?>
            <input type="hidden" name="attendees[__INDEX__][attendee_role]" value="child" />
            <?php if ($manual_ctx['child_name']['enabled']): ?>
                <div class="hmwevents-field hmwevents-field--half">
                    <label for="hmw-manual-child-name-__INDEX__"><?php echo esc_html($manual_ctx['child_name']['label']); ?><?php if ($manual_ctx['child_name']['required']): ?> <span class="required">*</span><?php endif; ?></label>
                    <input type="text" id="hmw-manual-child-name-__INDEX__" name="attendees[__INDEX__][child_name]"<?php echo $manual_ctx['child_name']['required'] ? ' data-required' : ''; ?> />
                </div>
            <?php endif; ?>
            <?php if ($manual_ctx['child_age']['enabled'] || $has_age_pricing): ?>
                <div class="hmwevents-field hmwevents-field--half">
                    <label for="hmw-manual-child-dob-__INDEX__"><?php echo esc_html($manual_ctx['child_age']['label']); ?><?php if ($manual_ctx['child_age']['required'] || $has_age_pricing): ?> <span class="required">*</span><?php endif; ?></label>
                    <input type="date" id="hmw-manual-child-dob-__INDEX__" name="attendees[__INDEX__][date_of_birth]"<?php echo ($manual_ctx['child_age']['required'] || $has_age_pricing) ? ' data-required' : ''; ?> max="<?php echo esc_attr(gmdate('Y-m-d')); ?>" />
                </div>
            <?php endif; ?>
        <?php elseif (count($allowed_roles) > 1): ?>
            <div class="hmwevents-field hmwevents-field--half">
                <label for="hmw-manual-role-__INDEX__"><?php esc_html_e('Attendee Type', 'hmw-events'); ?> <span class="required">*</span></label>
                <select id="hmw-manual-role-__INDEX__" name="attendees[__INDEX__][attendee_role]" data-required>
                    <?php foreach ($allowed_roles as $role): ?>
                        <option value="<?php echo esc_attr($role); ?>"><?php echo esc_html(ucfirst($role)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <input type="hidden" name="attendees[__INDEX__][attendee_role]" value="<?php echo esc_attr($allowed_roles[0]); ?>" />
        <?php endif; ?>

        <?php if (!$is_parent_children && $has_age_pricing): ?>
            <div class="hmwevents-field hmwevents-field--full hmwevents-age-dob">
                <label for="hmw-manual-dob-__INDEX__"><?php esc_html_e('Date of Birth', 'hmw-events'); ?> <span class="required">*</span></label>
                <input type="date" id="hmw-manual-dob-__INDEX__" name="attendees[__INDEX__][date_of_birth]" data-required max="<?php echo esc_attr(gmdate('Y-m-d')); ?>" />
                <p class="description"><?php esc_html_e('Required for age-based pricing — used to calculate this attendee\'s fee.', 'hmw-events'); ?></p>
            </div>
        <?php endif; ?>

        <?php foreach ($manual_ctx['per_attendee_fields'] as $field): ?>
            <?php $this->render_attendee_custom_field($field); ?>
        <?php endforeach; ?>
        <?php
    }

    private function render_attendee_custom_field(array $field): void
    {
        $key_class = sanitize_html_class($field['key']);
        $types = !empty($field['attendance_types']) ? wp_json_encode(array_values($field['attendance_types'])) : '';
        ?>
        <div class="hmwevents-field hmwevents-field--<?php echo esc_attr((string) ($field['width'] ?? 'full')); ?>"<?php echo $types ? ' data-attendance-types="' . esc_attr($types) . '"' : ''; ?>>
            <label for="hmw-manual-att-__INDEX__-<?php echo esc_attr($key_class); ?>"><?php echo esc_html($field['label']); ?><?php if (!empty($field['required'])): ?> <span class="required">*</span><?php endif; ?></label>
            <?php $this->render_field_control($field, 'attendees[__INDEX__][' . $field['key'] . ']', 'hmw-manual-att-__INDEX__-' . $key_class); ?>
        </div>
        <?php
    }

    /**
     * @return object[]
     */
    /**
     * Build booking_id => label for rows belonging to multi-session groups.
     *
     * @param array $bookings Booking rows (must include booking_group_id).
     * @return array<int, string>
     */
    private function get_group_labels(array $bookings): array
    {
        global $wpdb;

        $group_ids = array_values(array_filter(array_unique(array_map(
            fn($b) => (int) ($b->booking_group_id ?? 0),
            $bookings
        ))));

        if (empty($group_ids)) {
            return [];
        }

        $groups_table   = DatabaseService::get_table_name('booking_groups');
        $bookings_table = DatabaseService::get_table_name('bookings');

        $placeholders = implode(',', array_fill(0, count($group_ids), '%d'));
        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT id, booking_type FROM {$groups_table} WHERE id IN ({$placeholders})",
            ...$group_ids
        )) ?: [];

        $labels = [];
        foreach ($groups as $group) {
            if ($group->booking_type === 'single') {
                continue;
            }

            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$bookings_table}
                 WHERE booking_group_id = %d AND status IN ('pending', 'confirmed') AND deleted_at IS NULL",
                (int) $group->id
            ));

            if ($count < 2) {
                continue;
            }

            $labels[(int) $group->id] = $group->booking_type === 'recurring'
                ? sprintf(__('Series track — %d sessions', 'hmw-events'), $count)
                : sprintf(__('Multi-session — %d sessions', 'hmw-events'), $count);
        }

        $out = [];
        foreach ($bookings as $booking) {
            $gid = (int) ($booking->booking_group_id ?? 0);
            if ($gid && isset($labels[$gid])) {
                $out[(int) $booking->id] = $labels[$gid];
            }
        }

        return $out;
    }

    private function get_bookings_for_event(int $event_id): array
    {
        global $wpdb;

        $bookings_table = DatabaseService::get_table_name('bookings');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, r.post_title AS customer_name,
                COALESCE(pm_first.meta_value, '') AS customer_first_name,
                COALESCE(pm_last.meta_value, '') AS customer_last_name,
                COALESCE(pm_email.meta_value, '') AS registrant_email,
                COALESCE(pm_phone.meta_value, '') AS customer_phone
            FROM {$bookings_table} b
            LEFT JOIN {$wpdb->posts} r ON b.registrant_post_id = r.ID
            LEFT JOIN {$wpdb->postmeta} pm_email ON b.registrant_post_id = pm_email.post_id AND pm_email.meta_key = 'registrant_email'
            LEFT JOIN {$wpdb->postmeta} pm_first ON b.registrant_post_id = pm_first.post_id AND pm_first.meta_key = 'registrant_first_name'
            LEFT JOIN {$wpdb->postmeta} pm_last ON b.registrant_post_id = pm_last.post_id AND pm_last.meta_key = 'registrant_last_name'
            LEFT JOIN {$wpdb->postmeta} pm_phone ON b.registrant_post_id = pm_phone.post_id AND pm_phone.meta_key = 'registrant_phone'
            WHERE b.event_post_id = %d AND b.deleted_at IS NULL
            ORDER BY b.created_at DESC",
            $event_id
        )) ?: [];
    }

    public function ajax_get_booking_details(): void
    {
        check_ajax_referer('hmwevents_get_booking_details', 'nonce');

        if (!current_user_can('edit_hmw_events')) {
            wp_send_json_error(['message' => __('Permission denied.', 'hmw-events')]);
        }

        $booking_id = (int) ($_POST['booking_id'] ?? 0);
        if (!$booking_id) {
            wp_send_json_error(['message' => __('Invalid booking ID.', 'hmw-events')]);
        }

        global $wpdb;
        $bookings_table = DatabaseService::get_table_name('bookings');

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$bookings_table} WHERE id = %d AND deleted_at IS NULL",
            $booking_id
        ));

        if (!$booking) {
            wp_send_json_error(['message' => __('Booking not found.', 'hmw-events')]);
        }

        $registrant_id = (int) $booking->registrant_post_id;
        $event_id = (int) $booking->event_post_id;

        $fields = EventFormFieldsResolver::for_event($event_id);

        $details_table = DatabaseService::get_table_name('booking_details');
        $form_data = [];
        $form_data_row = $wpdb->get_var($wpdb->prepare(
            "SELECT form_data FROM {$details_table} WHERE booking_id = %d",
            $booking_id
        ));

        if ($form_data_row) {
            $decoded = json_decode($form_data_row, true);
            if (is_array($decoded)) {
                $form_data = $decoded;
            }
        }

        $current_values = [
            'status'         => $booking->status,
            'payment_status' => $booking->payment_status,
        ];

        $field_list = [];
        foreach ($fields as $key => $field) {
            $source = $field['source'] ?? '';
            $value = '';

            if ($source === RegistrationFieldRegistry::SOURCE_REGISTRANT_META && !empty($field['meta_key'])) {
                $value = (string) get_post_meta($registrant_id, $field['meta_key'], true);
            } elseif ($source === RegistrationFieldRegistry::SOURCE_BOOKING_DETAILS) {
                $resolved = $form_data[$key]
                    ?? RegistrationFieldRegistry::resolve_legacy_value($form_data, $key)
                    ?? '';
                $value = is_scalar($resolved) ? (string) $resolved : '';
            }

            $current_values[$key] = $value;

            $field_list[] = [
                'key'         => $key,
                'label'       => $field['label'],
                'type'        => $field['type'],
                'required'    => $field['required'],
                'source'      => $field['source'],
                'meta_key'    => $field['meta_key'],
                'options'     => $field['options'],
                'placeholder' => $field['placeholder'],
            ];
        }

        wp_send_json_success([
            'booking_id'       => $booking->id,
            'booking_number'   => $booking->booking_number,
            'fields'           => $field_list,
            'current_values'   => $current_values,
        ]);
    }

    public function ajax_generate_token(): void
    {
        check_ajax_referer('hmwevents_generate_token', '_wpnonce');

        if (!current_user_can('edit_hmw_events')) {
            wp_send_json_error(['message' => __('Permission denied.', 'hmw-events')]);
        }

        $event_id = (int) ($_POST['event_id'] ?? 0);
        $email = sanitize_email($_POST['email'] ?? '');

        if (!$event_id) {
            wp_send_json_error(['message' => __('Invalid event.', 'hmw-events')]);
        }

        $token_service = new \HMWEvents\Services\InvitationTokenService();
        $result = $token_service->create($event_id, $email, 1, 48);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Registration link generated and invitation email queued.', 'hmw-events'),
            'registration_url' => $result['registration_url'],
            'token' => $result['token'],
        ]);
    }

    private function status_label(string $status): string
    {
        $labels = [
            'pending'    => __('Pending', 'hmw-events'),
            'confirmed'  => __('Confirmed', 'hmw-events'),
            'cancelled'  => __('Cancelled', 'hmw-events'),
            'waitlisted' => __('Waitlisted', 'hmw-events'),
        ];

        return $labels[$status] ?? $status;
    }

    private function payment_status_label(string $status): string
    {
        $labels = [
            'pending'  => __('Pending', 'hmw-events'),
            'paid'     => __('Paid', 'hmw-events'),
            'refunded' => __('Refunded', 'hmw-events'),
            'failed'   => __('Failed', 'hmw-events'),
            'invoiced' => __('Invoiced', 'hmw-events'),
        ];

        return $labels[$status] ?? $status;
    }

    public function ajax_promote_waitlist(): void
    {
        check_ajax_referer('hmwevents_promote_waitlist', '_wpnonce');

        if (!current_user_can('edit_hmw_events')) {
            wp_send_json_error(['message' => __('Permission denied.', 'hmw-events')]);
        }

        $entry_id = (int) ($_POST['entry_id'] ?? 0);
        $event_id = (int) ($_POST['event_id'] ?? 0);

        if (!$entry_id || !$event_id) {
            wp_send_json_error(['message' => __('Invalid request.', 'hmw-events')]);
        }

        $waitlist_service = new \HMWEvents\Services\WaitlistService();
        $promoted = $waitlist_service->promote_entry($entry_id, $event_id);

        if (!$promoted) {
            wp_send_json_error(['message' => __('Could not promote. No entries available.', 'hmw-events')]);
        }

        wp_send_json_success([
            'message' => sprintf(__('Promoted entry #%d. Invitation email queued.', 'hmw-events'), $promoted->id),
        ]);
    }
}
