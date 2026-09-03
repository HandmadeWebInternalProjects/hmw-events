<?php

namespace HMWEvents\Api\Routes;

use HMWEvents\Services\FormSubmissionService;

defined('ABSPATH') || die('Don\'t run this file directly!');

class V3Registration
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('hmwevents/v1', '/registration/v3-submit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_submission'],
            'permission_callback' => function () {
                return wp_verify_nonce(
                    $_REQUEST['_wpnonce'] ?? ($_SERVER['HTTP_X_WP_NONCE'] ?? ''),
                    'wp_rest'
                );
            },
        ]);
    }

    public function handle_submission(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return (new FormSubmissionService())->handle_v3_submission($request);
    }
}
