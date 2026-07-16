<?php
/**
 * WordPress class stubs for unit testing.
 *
 * Provides minimal implementations of WordPress classes
 * that are not available in the test environment.
 *
 * @package HMWEvents\Tests
 */

if (!class_exists('WP_Error')) {
    /**
     * Minimal WP_Error stub for testing.
     */
    class WP_Error
    {
        /** @var array */
        private $errors = [];
        /** @var array */
        private $error_data = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if (!empty($code)) {
                $this->errors[$code][] = $message;
                if (!empty($data)) {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_code()
        {
            $codes = array_keys($this->errors);
            return reset($codes);
        }

        public function get_error_message($code = '')
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            $messages = $this->errors[$code] ?? [];
            return reset($messages) ?: '';
        }

        public function get_error_messages($code = '')
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->errors[$code] ?? [];
        }

        public function get_error_data($code = '')
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->error_data[$code] ?? null;
        }

        public function add($code, $message, $data = '')
        {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }

        public function get_error_codes()
        {
            return array_keys($this->errors);
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    /**
     * Minimal WP_REST_Response stub for testing.
     */
    class WP_REST_Response
    {
        /** @var mixed */
        private $data;
        /** @var int */
        private $status;
        /** @var array */
        private $headers = [];

        public function __construct($data = null, $status = 200)
        {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status()
        {
            return $this->status;
        }

        public function set_status($code)
        {
            $this->status = (int) $code;
        }

        public function header($key, $value, $replace = true)
        {
            $this->headers[$key] = $value;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    /**
     * Minimal WP_REST_Request stub for testing.
     */
    class WP_REST_Request
    {
        /** @var array */
        private $params = [];
        /** @var string */
        private $body = '';
        /** @var array */
        private $headers = [];

        public function __construct($method = 'GET', $route = '')
        {
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function set_param($key, $value)
        {
            $this->params[$key] = $value;
        }

        public function get_params()
        {
            return $this->params;
        }

        public function get_body()
        {
            return $this->body;
        }

        public function set_body($body)
        {
            $this->body = $body;
        }

        public function get_header($key)
        {
            return $this->headers[strtolower($key)] ?? null;
        }

        public function set_header($key, $value)
        {
            $this->headers[strtolower($key)] = $value;
        }
    }
}

if (!class_exists('WP_Post')) {
    /**
     * Minimal WP_Post stub for testing.
     */
    class WP_Post
    {
        public $ID;
        public $post_type;
        public $post_status;

        public function __construct($data = null)
        {
            if (is_object($data)) {
                foreach (get_object_vars($data) as $k => $v) {
                    $this->$k = $v;
                }
            }
        }
    }
}
