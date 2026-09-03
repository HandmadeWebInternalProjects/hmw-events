<?php

namespace HMWEvents\Api;

defined('ABSPATH') || die('Don\'t run this file directly!');

class RegisterRoutes
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        new Routes\ProcessPayment();
        new Routes\BookingActions();
        new Routes\Waitlist();
    }
}
