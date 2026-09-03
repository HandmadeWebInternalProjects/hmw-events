<?php

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class SessionFlash
{

  const FLASH_KEYS = 'flash_message_stored_keys';
  const SKIP_FLAG = 'skip_flash_clean_up';
  private $xRedirectBy = 'flash';
  private $statusCode = 302;
  private $redirectUrl;

  public function __construct()
  {
    self::init();
  }

  public static function init()
  {
    if (!defined('FLASH_INIT')) {
      if (!headers_sent() && (session_id() == '' || !isset($_SESSION) || session_status() !== PHP_SESSION_ACTIVE)) {
        session_start();
      }

      register_shutdown_function([SessionFlash::class, 'cleanUpFlashMessages']);

      define('FLASH_INIT', true);
    }
  }

  public static function initFlash()
  {
    // ensure session is started
    if (!headers_sent() && (session_id() == '' || !isset($_SESSION) || session_status() !== PHP_SESSION_ACTIVE)) {
      session_start();
    }
    // clean up flash messages from session on script finishing point
    register_shutdown_function([SessionFlash::class, 'cleanUpFlashMessages']);
    // define flash is initialized
  }

  /**
   * Its a callback for (@register_shutdown_function) which registered in constructor
   */
  public static function cleanUpFlashMessages()
  {
    if (!defined(self::SKIP_FLAG)) {

      if (isset($_SESSION[self::FLASH_KEYS])) {

        //clean flash messages by using stored keys
        foreach ($_SESSION[self::FLASH_KEYS] as $message_key) {
          unset($_SESSION[$message_key]);
        }

        //then clean stored keys itself
        unset($_SESSION[self::FLASH_KEYS]);
      }
    }
  }

  /**
   * @param string $key flash message key in session storage
   * @param mixed $message message value
   *
   * @return Flash
   */
  public function message($key, mixed $message)
  {
    $_SESSION[self::FLASH_KEYS][] = $key;
    $_SESSION[$key] = $message;

    //skip cleaning once for redirection
    if (!defined(self::SKIP_FLAG)) {
      define(self::SKIP_FLAG, true);
    }

    return $this;
  }

  /**
   * @param $url
   *
   * @return Flash
   */
  public function redirectLocation($url)
  {
    $this->redirectUrl = $url;
    return $this;
  }

  /**
   * @param $status
   *
   * @return Flash
   */
  public function withStatus($status = 302)
  {
    $this->statusCode = $status;
    return $this;
  }

  /**
   * @param $xRedirectBy
   *
   * @return Flash
   */
  public function redirectBy($xRedirectBy = 'flash')
  {
    $this->xRedirectBy = $xRedirectBy;
    return $this;
  }

  public function redirect()
  {
    if (!isset($this->redirectUrl)) {
      $this->redirectBack();
      return;
    }

    @header("X-Redirect-By: $this->xRedirectBy", true, $this->statusCode);
    @header("Location: $this->redirectUrl", true, $this->statusCode);
    exit();
  }

  public function redirectBack()
  {
    $this->redirectUrl = $_SERVER['HTTP_REFERER'];
    $this->redirect();
  }

  /**
   * Convenience method to add success message (Laravel compatible)
   */
  public function success($message)
  {
    return $this->message('success', $message);
  }

  /**
   * Convenience method to add error message (Laravel compatible)
   */
  public function error($message)
  {
    return $this->message('error', $message);
  }

  /**
   * Convenience method to add info message
   */
  public function info($message)
  {
    return $this->message('info', $message);
  }

  /**
   * Convenience method to add warning message
   */
  public function warning($message)
  {
    return $this->message('warning', $message);
  }

  /**
   * Get a flash message
   */
  public static function get($key, $default = null)
  {
    return $_SESSION[$key] ?? $default;
  }

  /**
   * Check if a flash message exists
   */
  public static function has($key)
  {
    return isset($_SESSION[$key]);
  }

  /**
   * Flash multiple messages at once
   */
  public function messages(array $messages)
  {
    foreach ($messages as $key => $message) {
      $this->message($key, $message);
    }
    return $this;
  }

  /**
   * Clear all flash messages immediately (without waiting for cleanup)
   */
  public static function clear()
  {
    if (isset($_SESSION[self::FLASH_KEYS])) {
      foreach ($_SESSION[self::FLASH_KEYS] as $message_key) {
        unset($_SESSION[$message_key]);
      }
      unset($_SESSION[self::FLASH_KEYS]);
    }
  }

  /**
   * Get all flash messages
   */
  public static function all()
  {
    $messages = [];
    if (isset($_SESSION[self::FLASH_KEYS])) {
      foreach ($_SESSION[self::FLASH_KEYS] as $key) {
        if (isset($_SESSION[$key])) {
          $messages[$key] = $_SESSION[$key];
        }
      }
    }
    return $messages;
  }

  /**
   * Check if there are any flash messages
   */
  public static function any()
  {
    return isset($_SESSION[self::FLASH_KEYS]) && !empty($_SESSION[self::FLASH_KEYS]);
  }

  /**
   * Convenience method for chaining success message with redirect
   */
  public function successAndRedirect($message, $url = null)
  {
    $this->success($message);
    if ($url) {
      $this->redirectLocation($url);
    }
    return $this;
  }

  /**
   * Convenience method for chaining error message with redirect
   */
  public function errorAndRedirect($message, $url = null)
  {
    $this->error($message);
    if ($url) {
      $this->redirectLocation($url);
    }
    return $this;
  }
}