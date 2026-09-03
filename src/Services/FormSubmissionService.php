<?php

namespace HMWEvents\Services;

use HMWEvents\Helpers\EventHelper;
use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class FormSubmissionService
{
    private PaymentService $payment_service;
    private ?EventDataService $event_data_service = null;

    public function __construct()
    {
        $this->payment_service = new PaymentService();
    }

    private function event_data(): EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new EventDataService();
        }
        return $this->event_data_service;
    }

    public function register(): void
    {
    }

    public function submit_and_pay(array $form_data, int $event_id): array|\WP_Error
    {
        if (!$this->event_data()->bookings_enabled($event_id)) {
            return new \WP_Error('bookings_disabled', __('Bookings are not available for this event.', 'hmw-events'));
        }

        $config = $this->get_event_form_config($event_id);
        $has_multi = $this->determine_multi($event_id, $config);

        $normalized = $this->normalize_from_post($form_data, $config, $has_multi);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $config = FormConfigResolver::for_attendance($config, $normalized['attendance_type']);
        $config = $this->apply_multi_config($event_id, $config, $has_multi, $normalized['attendance_type']);

        $errors = $this->validate_submission($normalized, $config);
        if (!empty($errors)) {
            return new \WP_Error('validation_failed', implode(' ', $errors));
        }

        $uploaded_files = [];
        foreach ($_FILES as $field_key => $file) {
            if (empty($file['name'])) {
                continue;
            }
            $upload_handler = new DocumentUploadHandler();
            $result = $upload_handler->handle_upload($file, 0, $event_id);
            if (!is_wp_error($result)) {
                $uploaded_files[$field_key] = $result;
            }
        }

        return $this->process($event_id, $normalized, $config, $has_multi, $uploaded_files);
    }

    private function determine_multi(int $event_id, array $config): bool
    {
        if (!empty($config['multi_booking']['enabled'])) {
            return true;
        }

        $pricing = new AttendancePricingService();

        foreach ($pricing->resolve_all($event_id) as $option) {
            if ((int) ($option['composition']['min_attendees'] ?? 1) > 1) {
                return true;
            }
        }

        return false;
    }

    private function build_attendees(array $normalized): array
    {
        $attendees = [];

        foreach ($normalized['attendees'] as $raw) {
            $attendees[] = [
                'role'          => sanitize_key((string) ($raw['attendee_role'] ?? '')) ?: 'adult',
                'date_of_birth' => sanitize_text_field((string) ($raw['date_of_birth'] ?? '')),
                'child_name'    => sanitize_text_field((string) ($raw['child_name'] ?? '')),
            ];
        }

        return $attendees;
    }

    private function extract_payment_type(array $normalized, int $event_id): string
    {
        $value = '';

        if (!empty($normalized['global']['payment_type'])) {
            $value = sanitize_key((string) $normalized['global']['payment_type']);
        } elseif (isset($normalized['attendees'][0]['payment_type'])) {
            $value = sanitize_key((string) $normalized['attendees'][0]['payment_type']);
        }

        if (!in_array($value, ['full', 'deposit', 'net_terms'], true)) {
            return 'full';
        }

        if ($value === 'net_terms' && !$this->event_data()->get_allow_net_terms($event_id)) {
            return 'full';
        }

        return $value;
    }

    /**
     * Expand the submission's session picker selection into booking rows,
     * or null when the form has no session picker selection.
     */
    private function resolve_session_booking(int $event_id, array $normalized)
    {
        if (empty($normalized['selected_sessions'])) {
            return null;
        }

        if ($this->event_data()->get_session_booking_mode($event_id) !== SessionBookingService::MODE_INDIVIDUAL) {
            return null;
        }

        $attendee_count = max(1, count($normalized['attendees'] ?? [1]));
        $service = new SessionBookingService();

        return $service->expand_selection($event_id, $normalized['selected_sessions'], null, $attendee_count);
    }


    private function is_parent_children_mode(array $config): bool
    {
        return ($config['multi_booking']['mode'] ?? 'attendees') === 'parent_children';
    }

    private function normalize_from_post(array $form_data, array $config, bool $has_multi): array|\WP_Error
    {
        $normalized = [
            'event_id'        => (int) ($form_data['event_id'] ?? 0),
            'attendance_type' => sanitize_text_field($form_data['attendance_type'] ?? 'individual'),
            'attendee_count'  => 1,
            'attendees'       => [],
            'global'          => [],
            'invite_token'    => sanitize_text_field((string) ($form_data['token'] ?? $_GET['token'] ?? '')),
        ];

        if ($has_multi) {
            $count = max(1, min(
                $config['multi_booking']['max'] ?? 10,
                (int) ($form_data['attendee_count'] ?? $config['multi_booking']['min'] ?? 1)
            ));
            $normalized['attendee_count'] = $count;

            $nested = $form_data['attendees'] ?? null;
            if (is_array($nested)) {
                for ($ai = 0; $ai < $count; $ai++) {
                    $normalized['attendees'][$ai] = is_array($nested[$ai] ?? null) ? $nested[$ai] : [];
                }
            } else {
                for ($ai = 0; $ai < $count; $ai++) {
                    $normalized['attendees'][$ai] = [];
                    $prefix = 'attendees_' . $ai . '_';
                    foreach ($form_data as $key => $value) {
                        if (str_starts_with($key, $prefix)) {
                            $field_key = substr($key, strlen($prefix));
                            $normalized['attendees'][$ai][$field_key] = $value;
                        }
                    }
                }
            }

            foreach ($form_data as $key => $value) {
                $reserved = ['event_id', 'attendance_type', 'attendee_count', 'attendees', 'action', '_hmwevents_nonce', '_wp_http_referer'];
                if (!in_array($key, $reserved, true) && !str_starts_with($key, 'attendees_')) {
                    $normalized['global'][$key] = $value;
                }
            }
        } else {
            foreach ($form_data as $key => $value) {
                if (!in_array($key, ['event_id', 'attendance_type', 'action', '_hmwevents_nonce', '_wp_http_referer'])) {
                    $normalized['attendees'][0][$key] = $value;
                }
            }
        }

        $normalized['selected_sessions'] = $this->extract_session_selection($form_data, $config);

        return $normalized;
    }

    /**
     * Pull selected session IDs from any session_picker fields defined in
     * the form config. Values may arrive as arrays (multi picker) or
     * comma-separated strings (single picker).
     */
    private function extract_session_selection(array $raw, array $config): array
    {
        $keys = [];
        foreach (($config['sections'] ?? []) as $section) {
            foreach (($section['fields'] ?? []) as $field) {
                if (($field['type'] ?? '') === 'session_picker') {
                    $keys[] = (string) ($field['key'] ?? '');
                }
            }
        }

        if (empty($keys)) {
            return [];
        }

        $picked = [];
        foreach ($keys as $key) {
            if ($key === '') {
                continue;
            }
            $value = $raw[$key] ?? ($raw[$key . '[]'] ?? []);
            if (is_string($value)) {
                $value = array_map('trim', explode(',', $value));
            }
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $id) {
                if (is_array($id)) {
                    continue;
                }
                $picked[] = (int) $id;
            }
        }

        return array_values(array_unique(array_filter($picked)));
    }

    private function process(int $event_id, array $normalized, array $config, bool $has_multi, array $uploaded_files = []): array|\WP_Error
    {
        $session_booking = $this->resolve_session_booking($event_id, $normalized);

        if (is_wp_error($session_booking)) {
            return $session_booking;
        }

        $is_free = $this->event_data()->get_is_free($event_id);
        $attendance_type = sanitize_key((string) ($normalized['attendance_type'] ?? 'individual')) ?: 'individual';

        $active_options = EventHelper::get_active_attendance_options($event_id);

        if (!empty($active_options)) {
            $option = EventHelper::resolve_attendance_option($event_id, $attendance_type);

            if ($option === null) {
                return new \WP_Error('invalid_attendance_type', __('The selected attendance option is no longer available.', 'hmw-events'));
            }
        }

        $attendees = $this->build_attendees($normalized);
        $attendee_count = count($attendees);

        $pricing = new AttendancePricingService();
        $option_config = $pricing->resolve_configuration($event_id, $attendance_type);
        if ($has_multi && !$this->is_parent_children_mode($config)) {
            $option_config['composition']['min_attendees'] = max(1, (int) ($config['multi_booking']['min'] ?? 1));
            $option_config['composition']['max_attendees'] = max(
                $option_config['composition']['min_attendees'],
                (int) ($config['multi_booking']['max'] ?? 10)
            );
            $option_config['composition']['min_children'] = 0;
            $option_config['composition']['min_adults'] = 0;
        }

        $composition_check = $pricing->validate_composition($option_config['composition'], $attendees);
        if (is_wp_error($composition_check)) {
            return $composition_check;
        }

        $capacity_check = $session_booking
            ? true
            : EventHelper::check_booking_capacity($event_id, $attendance_type, 1, $attendee_count);
        if (is_wp_error($capacity_check)) {
            return $capacity_check;
        }

        if ($session_booking) {
            $total = $session_booking['total'];
            $unit_price = $session_booking['total'];
            $surcharge = $this->event_data()->get_surcharge($event_id);

            if ($surcharge > 0 && !empty($session_booking['rows'])) {
                $total += $surcharge;
                $session_booking['rows'][0]['booking_amount'] += $surcharge;
            }
        } else {
            $amount_data = $pricing->calculate_total(
                $event_id,
                $attendance_type,
                $attendees,
                $has_multi && !$this->is_parent_children_mode($config) ? $option_config['composition'] : null
            );
            if (is_wp_error($amount_data)) {
                return $amount_data;
            }

            $total = $amount_data['total'];
            $unit_price = $amount_data['base_amount'];
            $surcharge = $amount_data['surcharge'];
        }

        $coupon_code = '';
        $coupon_data_raw = '';

        if ($has_multi) {
            $coupon_code = !empty($normalized['global']['coupon_code']) ? sanitize_text_field($normalized['global']['coupon_code']) : '';
            $coupon_data_raw = !empty($normalized['global']['coupon_data']) ? $normalized['global']['coupon_data'] : '';
        } else {
            $coupon_code = !empty($normalized['attendees'][0]['coupon_code']) ? sanitize_text_field($normalized['attendees'][0]['coupon_code']) : '';
            $coupon_data_raw = !empty($normalized['attendees'][0]['coupon_data']) ? $normalized['attendees'][0]['coupon_data'] : '';
        }

        $coupon_data = null;
        $discount_amount = 0.0;
        $registrant_email = '';

        if ($coupon_code !== '') {
            $registrant_data_0 = $this->build_registrant_data($normalized, $config, 0);
            $registrant_email = sanitize_email($registrant_data_0['email'] ?? '');

            $coupon_service = new CouponService();
            $organizer_id = $this->event_data()->get_organizer_id($event_id);
            if (!$organizer_id) {
                $post = get_post($event_id);
                $organizer_id = $post ? (int) $post->post_author : null;
            }

            $validated = $coupon_service->validate_coupon(
                $coupon_code,
                $event_id,
                $organizer_id ?? 0,
                $registrant_email,
                'full',
                $total
            );

            if (!is_wp_error($validated)) {
                $discount_calc = $coupon_service->calculate_discount($total, $validated);
                $discount_amount = $discount_calc['discount_amount'];
                $total = $discount_calc['final_amount'];
                $coupon_data = $validated;
            }
        }

        $registrant_ids = [];
        $booking_details_all = [];

        for ($ai = 0; $ai < $attendee_count; $ai++) {
            $registrant_data = $this->build_registrant_data($normalized, $config, $ai);
            $registrant_id = $this->create_registrant($event_id, $registrant_data, $ai);
            if (is_wp_error($registrant_id)) {
                return $registrant_id;
            }
            $registrant_ids[] = $registrant_id;

            $meta = $this->extract_registrant_meta($registrant_data, $config);

            if (!empty($attendees[$ai]['date_of_birth'])) {
                $meta['registrant_date_of_birth'] = $attendees[$ai]['date_of_birth'];
            }

            $meta['registrant_attendee_role'] = $attendees[$ai]['role'];

            $this->save_registrant_meta($registrant_id, $meta);

            if ($ai === 0) {
                $booking_details_all = $this->extract_booking_details($normalized, $config);
                foreach ($uploaded_files as $field_key => $file_data) {
                    $booking_details_all[$field_key . '_file_path'] = $file_data['file_path'];
                    $booking_details_all[$field_key . '_original_filename'] = $file_data['original_filename'];
                }
            }
        }

        $payment_method_id = null;

        if ($has_multi) {
            $payment_method_id = !empty($normalized['global']['payment_method_id'])
                ? sanitize_text_field($normalized['global']['payment_method_id'])
                : null;
        } else {
            $payment_method_id = !empty($normalized['attendees'][0]['payment_method_id'])
                ? sanitize_text_field($normalized['attendees'][0]['payment_method_id'])
                : null;
        }

        $gateway = new PaymentGateway(null, null);
        $booking_payload = [
            'event_id'        => $event_id,
            'amount'          => $total,
            'attendance_type' => $attendance_type,
            'payment_type'    => $this->extract_payment_type($normalized, $event_id),
            'meta'            => [
                'registrant_ids'      => $registrant_ids,
                'attendee_count'      => $attendee_count,
                'attendees'           => $attendees,
                'unit_price'          => $unit_price,
                'surcharge'           => $surcharge,
                'booking_details_raw' => $booking_details_all,
            ],
        ];

        if (!empty($registrant_ids)) {
            $first_registrant_id = (int) $registrant_ids[0];
            $booking_payload['registrant_email'] = sanitize_email(
                get_post_meta($first_registrant_id, 'registrant_email', true) ?: ''
            );
            $booking_payload['customer_name']    = sanitize_text_field(
                get_the_title($first_registrant_id) ?: ''
            );
        }

        if ($coupon_data) {
            $booking_payload['coupon_code'] = $coupon_code;
            $booking_payload['coupon_discount'] = $coupon_data;
        }

        if ($payment_method_id) {
            $booking_payload['payment_method_id'] = $payment_method_id;
        }

        if ($session_booking) {
            $booking_payload['session_rows'] = $session_booking['rows'];
            $booking_payload['booking_type'] = $session_booking['booking_type'];
            $booking_payload['group_metadata'] = [
                'series_root'    => $event_id,
                'mode'           => $session_booking['booking_type'] === 'recurring' ? 'track' : 'individual',
                'slot'           => $session_booking['slot'],
                'session_ids'    => $session_booking['session_ids'],
                'attendee_count' => $attendee_count,
            ];
        }

        $payment_result = $gateway->process_new_booking($booking_payload);

        if (is_wp_error($payment_result)) {
            return $payment_result;
        }

        foreach ($registrant_ids as $id) {
            update_post_meta($id, '_booking_id', $payment_result['booking_id'] ?? 0);
        }

        $invite_token = (string) ($normalized['invite_token'] ?? '');
        if ($invite_token !== '') {
            $token_service = new \HMWEvents\Services\InvitationTokenService();
            $token_service->consume($invite_token, $event_id);
        }

        return [
            'success'         => true,
            'booking_id'      => $payment_result['booking_id'] ?? 0,
            'booking_number'  => $payment_result['booking_number'] ?? '',
            'booking_ids'     => $payment_result['booking_ids'] ?? [$payment_result['booking_id'] ?? 0],
            'registrant_ids'  => $registrant_ids,
            'total'           => $total,
            'client_secret'   => $payment_result['client_secret'] ?? null,
            'intent_status'   => $payment_result['intent_status'] ?? null,
        ];
    }

    public function handle_v3_submission(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $event_id = (int) $request->get_param('event_id');
        if (!$event_id || get_post_type($event_id) !== 'hmw_event') {
            return new \WP_Error('invalid_event', 'Invalid event.', ['status' => 400]);
        }

        if (!$this->event_data()->bookings_enabled($event_id)) {
            return new \WP_Error('bookings_disabled', __('Bookings are not available for this event.', 'hmw-events'), ['status' => 403]);
        }

        $config = $this->get_event_form_config($event_id);
        $has_multi = $this->determine_multi($event_id, $config);

        $normalized = $this->normalize_submission($request, $config, $has_multi);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $config = FormConfigResolver::for_attendance($config, $normalized['attendance_type']);
        $config = $this->apply_multi_config($event_id, $config, $has_multi, $normalized['attendance_type']);

        $errors = $this->validate_submission($normalized, $config);
        if (!empty($errors)) {
            return new \WP_Error('validation_failed', implode(' ', $errors), ['status' => 400]);
        }

        $result = $this->process($event_id, $normalized, $config, $has_multi);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    private function get_event_form_config(int $event_id): array
    {
        $reg = \HMWEvents\Services\FormConfigResolver::resolve($event_id);
        if ($reg !== null) {
            return $reg;
        }

        return ['sections' => [], 'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10]];
    }

    private function normalize_submission(\WP_REST_Request $request, array $config, bool $has_multi): array|\WP_Error
    {
        $raw = $request->get_json_params() ?: $request->get_params();

        $normalized = [
            'event_id'        => (int) ($raw['event_id'] ?? 0),
            'attendance_type' => sanitize_text_field($raw['attendance_type'] ?? 'individual'),
            'attendee_count'  => 1,
            'attendees'       => [],
            'global'          => [],
            'invite_token'    => sanitize_text_field((string) ($raw['token'] ?? '')),
        ];

        if ($has_multi) {
            $count = max(1, min(
                $config['multi_booking']['max'] ?? 10,
                (int) ($raw['attendee_count'] ?? $config['multi_booking']['min'] ?? 1)
            ));
            $normalized['attendee_count'] = $count;

            foreach ($raw['attendees'] ?? [] as $ai => $attendee_data) {
                if ($ai >= $count) {
                    break;
                }
                $normalized['attendees'][$ai] = is_array($attendee_data) ? $attendee_data : [];
            }

            for ($i = 0; $i < $count; $i++) {
                if (!isset($normalized['attendees'][$i])) {
                    $normalized['attendees'][$i] = [];
                }
            }
        } else {
            $normalized['attendees'][0] = $raw;
        }

        $normalized['global'] = $has_multi ? ($raw['_global'] ?? []) : [];

        $normalized['selected_sessions'] = $this->extract_session_selection(
            array_merge((array) ($raw['_global'] ?? []), is_array($raw) ? $raw : []),
            $config
        );

        return $normalized;
    }

    private function validate_submission(array $normalized, array $config): array
    {
        $errors = [];
        $all_fields = $this->flatten_fields($config);
        $has_multi = !empty($config['multi_booking']['enabled']);
        $event_id = (int) ($normalized['event_id'] ?? 0);

        if (!apply_filters('hmwevents_can_register_for_event', true, $event_id, [
            'token' => (string) ($normalized['invite_token'] ?? ''),
        ])) {
            $errors[] = __('This event is invitation-only. A valid invitation link is required to register.', 'hmw-events');
        }
        $is_parent_children = $this->is_parent_children_mode($config);
        $child_name_cfg = is_array($config['multi_booking']['child_fields']['name'] ?? null)
            ? $config['multi_booking']['child_fields']['name']
            : [];
        $child_age_cfg = is_array($config['multi_booking']['child_fields']['age'] ?? null)
            ? $config['multi_booking']['child_fields']['age']
            : [];
        $child_name_label = sanitize_text_field((string) ($child_name_cfg['label'] ?? '')) ?: __('Child Name', 'hmw-events');
        $child_age_label = sanitize_text_field((string) ($child_age_cfg['label'] ?? '')) ?: __('Date of Birth', 'hmw-events');

        if ($has_multi) {
            foreach ($normalized['attendees'] as $ai => $attendee) {
                $context = $is_parent_children
                    ? sprintf('Child %d', $ai)
                    : sprintf('Attendee %d', $ai + 1);

                if ($is_parent_children && $ai === 0) {
                    continue;
                }

                foreach ($all_fields as $field) {
                    if (empty($field['per_attendee'])) {
                        continue;
                    }

                    $value = $attendee[$field['key']] ?? '';
                    $err = $this->validate_field($field, $value, $context);
                    if ($err) {
                        $errors[] = $err;
                    }
                }

                if ($is_parent_children && !empty($child_name_cfg['enabled']) && !empty($child_name_cfg['required'])) {
                    $child_name = trim((string) ($attendee['child_name'] ?? ''));
                    if ($child_name === '') {
                        $errors[] = sprintf('%s — %s is required.', $context, $child_name_label);
                    }
                }

                if ($is_parent_children && !empty($child_age_cfg['enabled']) && !empty($child_age_cfg['required'])) {
                    $date_of_birth = trim((string) ($attendee['date_of_birth'] ?? ''));
                    if ($date_of_birth === '') {
                        $errors[] = sprintf('%s — %s is required.', $context, $child_age_label);
                    }
                }
            }

            foreach ($all_fields as $field) {
                if (!empty($field['per_attendee'])) {
                    continue;
                }

                $value = $normalized['global'][$field['key']] ?? '';
                $err = $this->validate_field($field, $value, 'Global');
                if ($err) {
                    $errors[] = $err;
                }
            }
        } else {
            $attendee = $normalized['attendees'][0] ?? [];
            foreach ($all_fields as $field) {
                $value = $attendee[$field['key']] ?? '';
                $err = $this->validate_field($field, $value);
                if ($err) {
                    $errors[] = $err;
                }
            }
        }

        return $errors;
    }

    private function apply_multi_config(int $event_id, array $config, bool $has_multi, string $attendance_type): array
    {
        if (!$has_multi || !$this->is_parent_children_mode($config)) {
            return $config;
        }

        $pricing = new AttendancePricingService();
        $composition = $pricing->resolve_configuration($event_id, $attendance_type)['composition'];
        $config['multi_booking']['enabled'] = true;
        $config['multi_booking']['min'] = max(1, (int) ($composition['min_attendees'] ?? 1));
        $config['multi_booking']['max'] = max(
            $config['multi_booking']['min'],
            (int) ($composition['max_attendees'] ?? $config['multi_booking']['min'])
        );

        return $config;
    }

    private function validate_field(array $field, $value, string $context = ''): ?string
    {
        if (($field['type'] ?? '') === 'session_picker') {
            return null;
        }

        $label = $context ? "$context — {$field['label']}" : $field['label'];

        if (!empty($field['required']) && (is_string($value) ? trim($value) === '' : empty($value))) {
            return sprintf('%s is required.', $label);
        }

        $type = $field['type'] ?? 'text';

        if (in_array($type, ['select', 'radio', 'checkbox'], true) && !empty($field['options'])) {
            if ($type === 'checkbox') {
                if (!empty($value) && $value !== '1') {
                    return sprintf(__('Invalid value for field: %s', 'hmw-events'), $label);
                }
            } else {
                $allowed_keys = array_column($field['options'], 'value');
                if (!empty($value) && !in_array($value, $allowed_keys, true)) {
                    return sprintf(__('Invalid value for field: %s', 'hmw-events'), $label);
                }
            }
        }

        if ($type === 'email' && !empty($value) && !is_email($value)) {
            return sprintf('%s must be a valid email.', $label);
        }

        if ($type === 'number' && $value !== '' && !is_numeric($value)) {
            return sprintf('%s must be a number.', $label);
        }

        return null;
    }

    private function build_registrant_data(array $normalized, array $config, int $attendee_index): array
    {
        $all_fields = $this->flatten_fields($config);
        $has_multi = !empty($config['multi_booking']['enabled']);
        $is_parent_children = $this->is_parent_children_mode($config);

        $data = [];

        foreach ($all_fields as $field) {
            if ($has_multi && !empty($field['per_attendee'])) {
                $data[$field['key']] = $normalized['attendees'][$attendee_index][$field['key']] ?? '';
            } elseif (!$has_multi) {
                $data[$field['key']] = $normalized['attendees'][0][$field['key']] ?? '';
            } elseif (empty($field['per_attendee'])) {
                $data[$field['key']] = $normalized['global'][$field['key']] ?? '';
            }
        }

        if ($is_parent_children && $attendee_index > 0) {
            $data['child_name'] = sanitize_text_field((string) ($normalized['attendees'][$attendee_index]['child_name'] ?? ''));
        }

        return $data;
    }

    private function extract_registrant_meta(array $data, array $config): array
    {
        $meta = [];
        $all_fields = $this->flatten_fields($config);

        foreach ($all_fields as $field) {
            if (($field['source'] ?? '') === 'registrant_meta' && !empty($field['meta_key'])) {
                $meta[$field['meta_key']] = sanitize_text_field($data[$field['key']] ?? '');
            }
        }

        return $meta;
    }

    private function extract_booking_details(array $normalized, array $config): array
    {
        $details = [];
        $all_fields = $this->flatten_fields($config);
        $is_parent_children = $this->is_parent_children_mode($config);
        $has_multi = !empty($config['multi_booking']['enabled']);

        foreach ($all_fields as $field) {
            if (($field['source'] ?? '') !== 'booking_details') {
                continue;
            }

            if ($has_multi && !empty($field['per_attendee'])) {
                continue;
            }

            $value = $normalized['attendees'][0][$field['key']] ?? ($normalized['global'][$field['key']] ?? '');
            if ($value !== '' && $value !== null) {
                $details[$field['key']] = sanitize_text_field($value);
            }
        }

        if ($has_multi) {
            $attendee_details = [];
            $attendees = $normalized['attendees'] ?? [];
            $count = count($attendees);
            $start = $is_parent_children ? 1 : 0;

            for ($ai = $start; $ai < $count; $ai++) {
                $child = [];

                if ($is_parent_children) {
                    $child_name = sanitize_text_field((string) ($attendees[$ai]['child_name'] ?? ''));
                    if ($child_name !== '') {
                        $child['child_name'] = $child_name;
                    }
                }

                $dob = sanitize_text_field((string) ($attendees[$ai]['date_of_birth'] ?? ''));
                if ($dob !== '') {
                    $child['date_of_birth'] = $dob;
                }

                foreach ($all_fields as $field) {
                    if (($field['source'] ?? '') !== 'booking_details' || empty($field['per_attendee'])) {
                        continue;
                    }

                    $value = $attendees[$ai][$field['key']] ?? '';
                    if ($value !== '' && $value !== null) {
                        $child[$field['key']] = sanitize_text_field($value);
                    }
                }

                $attendee_details[] = $child;
            }

            $details[$is_parent_children ? 'children' : 'attendees'] = $attendee_details;
        }

        return $details;
    }

    private function flatten_fields(array $config): array
    {
        $fields = [];
        foreach ($config['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    private function create_registrant(int $event_id, array $data, int $index): int|\WP_Error
    {
        $child_name = sanitize_text_field($data['child_name'] ?? '');
        $first_name = sanitize_text_field($data['first_name'] ?? '');
        $last_name  = sanitize_text_field($data['last_name'] ?? '');
        $email      = sanitize_email($data['email'] ?? '');
        $full_name  = trim($first_name . ' ' . $last_name);

        if ($child_name !== '') {
            $full_name = $child_name;
        } elseif (empty($full_name)) {
            $full_name = $email ?: sprintf('Attendee %d', $index + 1);
        }

        $meta_input = [
            'registrant_first_name' => $first_name,
            'registrant_last_name'  => $last_name,
            'registrant_email'      => $email,
            '_event_id'             => $event_id,
            '_attendee_index'       => $index,
        ];

        if ($child_name !== '') {
            $meta_input['registrant_child_name'] = $child_name;
        }

        $post_id = wp_insert_post([
            'post_type'   => 'hmw_registrant',
            'post_title'  => $full_name,
            'post_status' => 'publish',
            'meta_input'  => $meta_input,
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if (!empty($email)) {
            wp_set_object_terms($post_id, $email, 'registrant_email', false);
        }

        return (int) $post_id;
    }

    private function save_registrant_meta(int $registrant_id, array $meta): void
    {
        foreach ($meta as $key => $value) {
            update_post_meta($registrant_id, $key, $value);
        }
    }
}
