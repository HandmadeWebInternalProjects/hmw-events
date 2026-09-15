<?php

if (!function_exists('apply_filters')) {
    function apply_filters($hook_name, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('did_action')) {
    function did_action($hook_name) {
        return 0;
    }
}
