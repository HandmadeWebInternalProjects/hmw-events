<?php

/**
 * Waitlist REST API Endpoint.
 *
 * Public endpoint allowing customers to join the waitlist
 * for a fully-booked event.
 *
 * @package HMWEvents
 * @since 2.0.0
 */

namespace HMWEvents\Api\Routes;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use HMWEvents\PostTypes\Event;
use HMWEvents\Services\CapacityService;
use HMWEvents\Services\WaitlistService;

defined('ABSPATH') || die('Don\'t run this file directly!');

class Waitlist
{
    public function __construct()
    {
        $this->register_routes();
    }

    public function register_routes(): void
    {
        register_rest_route('hmwevents/v1', '/waitlist/join', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_join'],
            'permission_callback' => '__return_true',
            'args' => [
                'event_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'minimum' => 1,
                ],
                'first_name' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'last_name' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'email' => [
                    'required' => true,
                    'type' => 'string',
                    'format' => 'email',
                    'sanitize_callback' => 'sanitize_email',
                ],
            ],
        ]);
    }

    /**
     * Handle a waitlist join request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handle_join(WP_REST_Request $request)
    {
        $event_id = (int) $request->get_param('event_id');

        $event = get_post($event_id);
        if (!$event || $event->post_type !== Event::POST_TYPE) {
            return new WP_Error('invalid_event', __('Event not found.', 'hmw-events'), ['status' => 404]);
        }

        $capacity_service = new CapacityService();
        $capacity = $capacity_service->get_capacity($event_id);
        if ($capacity > 0 && $capacity_service->get_remaining_places($event_id) > 0) {
            return new WP_Error(
                'not_full',
                __('Spots are still available for this event — please book directly.', 'hmw-events'),
                ['status' => 400]
            );
        }

        $service = new WaitlistService();
        $result = $service->join(
            $event_id,
            (string) $request->get_param('first_name'),
            (string) $request->get_param('last_name'),
            (string) $request->get_param('email')
        );

        if (is_wp_error($result)) {
            $status = $result->get_error_code() === 'already_waitlisted' ? 409 : 400;
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => $status]);
        }

        return new WP_REST_Response([
            'success'  => true,
            'position' => (int) $result->position,
            /* translators: %d: waitlist position. */
            'message'  => sprintf(__("You're on the waitlist — you're number %d in line.", 'hmw-events'), (int) $result->position),
        ], 201);
    }
}
