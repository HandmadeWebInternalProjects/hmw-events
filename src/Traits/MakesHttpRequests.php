<?php

/**
 * MakesHttpRequests Trait.
 *
 * Reusable WordPress HTTP API helpers for JSON REST clients.
 * Any mailing service (or other HTTP-based service) can `use` this
 * trait to avoid duplicating request/response boilerplate.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Traits;

defined('ABSPATH') || die('Don\'t run this file directly!');

trait MakesHttpRequests
{
    /**
     * Make a GET request to a JSON REST endpoint.
     *
     * @param string $url     Absolute URL.
     * @param array  $headers Additional request headers.
     * @param array  $params  Query-string parameters to append to the URL.
     * @return array|\WP_Error Decoded JSON body or WP_Error on failure.
     */
    protected function http_get(string $url, array $headers = [], array $params = [])
    {
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => 15,
        ]);

        return $this->parse_http_response($response);
    }

    /**
     * Make a POST, PUT, or PATCH request to a JSON REST endpoint.
     *
     * @param string $url     Absolute URL.
     * @param array  $body    Request body (will be JSON-encoded).
     * @param array  $headers Additional request headers.
     * @param string $method  HTTP method ('POST', 'PUT', 'PATCH', …).
     * @return array|\WP_Error Decoded JSON body or WP_Error on failure.
     */
    protected function http_post(string $url, array $body, array $headers = [], string $method = 'POST')
    {
        $response = wp_remote_request($url, [
            'method'  => $method,
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            'body'    => wp_json_encode($body),
            'timeout' => 15,
        ]);

        return $this->parse_http_response($response);
    }

    /**
     * Parse a wp_remote_* response into a decoded array or WP_Error.
     *
     * @param array|\WP_Error $response Raw response from a wp_remote_* call.
     * @return array|\WP_Error
     */
    protected function parse_http_response($response)
    {
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            return new \WP_Error(
                'http_request_failed',
                sprintf('HTTP %d: %s', $code, $body)
            );
        }

        return $data ?: [];
    }
}
