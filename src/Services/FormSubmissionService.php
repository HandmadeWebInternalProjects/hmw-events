<?php

namespace HMWEvents\Services;

use HMWEvents\Registry\RegistrationFieldRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class FormSubmissionService
{
    private PaymentService $payment_service;

    public function __construct()
    {
        $this->payment_service = new PaymentService();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function submit_and_pay(array $form_data, int $event_id): array|\WP_Error
    {
        $config = $this->get_event_form_config($event_id);
        $has_multi = !empty($config['multi_booking']['enabled']);

        $normalized = $this->normalize_from_post($form_data, $config, $has_multi);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $errors = $this->validate_submission($normalized, $config);
        if (!empty($errors)) {
            return new \WP_Error('validation_failed', implode(' ', $errors));
        }

        return $this->process($event_id, $normalized, $config, $has_multi);
    }

    private function normalize_from_post(array $form_data, array $config, bool $has_multi): array|\WP_Error
    {
        $normalized = [
            'event_id'        => (int) ($form_data['event_id'] ?? 0),
            'attendance_type' => sanitize_text_field($form_data['attendance_type'] ?? 'individual'),
            'attendee_count'  => 1,
            'attendees'       => [],
            'global'          => [],
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

        return $normalized;
    }

    private function process(int $event_id, array $normalized, array $config, bool $has_multi): array|\WP_Error
    {
        $attendee_count = $has_multi ? $normalized['attendee_count'] : 1;
        $unit_price = (float) get_post_meta($event_id, '_event_price', true) ?: 0;
        $total = $unit_price * $attendee_count;

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
            $this->save_registrant_meta($registrant_id, $meta);

            if ($ai === 0) {
                $booking_details_all = $this->extract_booking_details($normalized, $config);
            }
        }

        $gateway = new PaymentGateway(null, null);
        $payment_result = $gateway->process_new_booking([
            'event_id'        => $event_id,
            'amount'          => $total,
            'attendance_type' => sanitize_text_field($normalized['attendance_type'] ?? 'individual'),
            'meta'            => [
                'registrant_ids'      => $registrant_ids,
                'attendee_count'      => $attendee_count,
                'unit_price'          => $unit_price,
                'booking_details_raw' => $booking_details_all,
            ],
        ]);

        if (is_wp_error($payment_result)) {
            return $payment_result;
        }

        foreach ($registrant_ids as $id) {
            update_post_meta($id, '_booking_id', $payment_result['booking_id'] ?? 0);
        }

        return [
            'success'         => true,
            'booking_id'      => $payment_result['booking_id'] ?? 0,
            'registrant_ids'  => $registrant_ids,
            'total'           => $total,
            'client_secret'   => $payment_result['client_secret'] ?? null,
        ];
    }

    public function register_routes(): void
    {
        register_rest_route('hmwevents/v1', '/registration/v3-submit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_v3_submission'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_v3_submission(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $event_id = (int) $request->get_param('event_id');
        if (!$event_id || get_post_type($event_id) !== 'hmw_event') {
            return new \WP_Error('invalid_event', 'Invalid event.', ['status' => 400]);
        }

        $config = $this->get_event_form_config($event_id);
        $has_multi = !empty($config['multi_booking']['enabled']);

        $normalized = $this->normalize_submission($request, $config, $has_multi);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

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
        $override = get_post_meta($event_id, '_event_template_override', true);
        if (is_array($override) && !empty($override['registration_fields']['sections'])) {
            return $override['registration_fields'];
        }

        $config = get_post_meta($event_id, '_event_field_config', true);
        if (is_array($config) && !empty($config['registration_fields']['sections'])) {
            return $config['registration_fields'];
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

        return $normalized;
    }

    private function validate_submission(array $normalized, array $config): array
    {
        $errors = [];
        $all_fields = $this->flatten_fields($config);
        $has_multi = !empty($config['multi_booking']['enabled']);

        if ($has_multi) {
            foreach ($normalized['attendees'] as $ai => $attendee) {
                foreach ($all_fields as $field) {
                    if (empty($field['per_attendee'])) {
                        continue;
                    }

                    $value = $attendee[$field['key']] ?? '';
                    $err = $this->validate_field($field, $value, sprintf('Attendee %d', $ai + 1));
                    if ($err) {
                        $errors[] = $err;
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

    private function validate_field(array $field, $value, string $context = ''): ?string
    {
        $label = $context ? "$context — {$field['label']}" : $field['label'];

        if (!empty($field['required']) && (is_string($value) ? trim($value) === '' : empty($value))) {
            return sprintf('%s is required.', $label);
        }

        $type = $field['type'] ?? 'text';

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

        foreach ($all_fields as $field) {
            if (($field['source'] ?? '') === 'booking_details') {
                $value = $normalized['attendees'][0][$field['key']] ?? ($normalized['global'][$field['key']] ?? '');
                if ($value !== '' && $value !== null) {
                    $details[$field['key']] = sanitize_text_field($value);
                }
            }
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
        $first_name = sanitize_text_field($data['first_name'] ?? '');
        $last_name  = sanitize_text_field($data['last_name'] ?? '');
        $email      = sanitize_email($data['email'] ?? '');
        $full_name  = trim($first_name . ' ' . $last_name);

        if (empty($full_name)) {
            $full_name = $email ?: sprintf('Attendee %d', $index + 1);
        }

        $post_id = wp_insert_post([
            'post_type'   => 'hmw_registrant',
            'post_title'  => $full_name,
            'post_status' => 'publish',
            'meta_input'  => [
                'registrant_first_name' => $first_name,
                'registrant_last_name'  => $last_name,
                'registrant_email'      => $email,
                '_event_id'             => $event_id,
                '_attendee_index'       => $index,
            ],
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
