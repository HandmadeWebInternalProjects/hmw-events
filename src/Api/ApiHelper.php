<?php

namespace HMWEvents\Api;

class ApiHelper
{

  public static function required_params($required_params, $request): bool
  {
    $params = $request->get_params();
    return self::compare_params($required_params, $params);
  }

  public static function required_json_params($required_params, $request): bool
  {
    $params = $request->get_json_params();
    return self::compare_params($required_params, $params);
  }

  public static function compare_params($required_params, $params)
  {
    return count(array_intersect_key(array_flip($required_params), $params)) === count($required_params);
  }

  public static function create_error_response(string $message, int $status = 400, array $additionalData = [], $headers = null): \WP_REST_Response
  {
    $data = array_merge(['error' => true, 'message' => $message], $additionalData);
    $request = new \WP_REST_Response($data, $status);

    if ($headers) {
      $request->set_headers($headers);
    }

    return $request;
  }

  public static function create_success_response(string $message, int $status = 200, array $additionalData = [], $headers = null): \WP_REST_Response
  {
    $data = array_merge(['success' => true, 'message' => $message], $additionalData);
    $request = new \WP_REST_Response($data, $status);

    if ($headers) {
      $request->set_headers($headers);
    }

    return $request;
  }
}
