<?php
/**
 * Encryption Helper.
 *
 * Handles encryption/decryption of sensitive data using Defuse library.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Helpers;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Encryption Helper Class.
 */
class Encryption
{
    /**
     * Encryption key.
     *
     * @var Key|null
     */
    private static $key = null;

    /**
     * Get the encryption key.
     *
     * @since 1.0.0
     * @return Key
     * @throws \Exception If key is not configured.
     */
    private static function get_key()
    {
        if (self::$key === null) {
            $key_ascii = defined('DEFUSE_ENCRYPTION_KEY') ? DEFUSE_ENCRYPTION_KEY : false;
            
            if (!$key_ascii) {
                throw new \Exception('DEFUSE_ENCRYPTION_KEY not defined in wp-config.php or .env');
            }

            try {
                self::$key = Key::loadFromAsciiSafeString($key_ascii);
            } catch (\Exception $e) {
                throw new \Exception('Invalid DEFUSE_ENCRYPTION_KEY: ' . $e->getMessage());
            }
        }

        return self::$key;
    }

    /**
     * Encrypt a string.
     *
     * @since 1.0.0
     * @param string $plaintext The data to encrypt.
     * @return string|false The encrypted string, or false on failure.
     */
    public static function encrypt($plaintext)
    {
        if ($plaintext === '') {
            return '';
        }

        // Validate that we received a string
        if (!is_string($plaintext)) {
            error_log('HMWEvents Encryption Error: Expected string, got ' . gettype($plaintext));
            return false;
        }

        try {
            $key = self::get_key();
            return Crypto::encrypt($plaintext, $key);
        } catch (\Exception $e) {
            error_log('HMWEvents Encryption Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Decrypt a string.
     *
     * @since 1.0.0
     * @param string $ciphertext The encrypted data.
     * @return string|false The decrypted string, or false on failure.
     */
    public static function decrypt($ciphertext)
    {
        if ($ciphertext === '') {
            return '';
        }

        // Validate that we received a string
        if (!is_string($ciphertext)) {
            error_log('HMWEvents Decryption Error: Expected string, got ' . gettype($ciphertext));
            return false;
        }

        try {
            $key = self::get_key();
            return Crypto::decrypt($ciphertext, $key);
        } catch (WrongKeyOrModifiedCiphertextException $e) {
            error_log('HMWEvents Decryption Error (wrong key or tampered data): ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log('HMWEvents Decryption Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if encryption is available.
     *
     * @since 1.0.0
     * @return bool
     */
    public static function is_available()
    {
        try {
            self::get_key();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate a new encryption key.
     * 
     * This should only be used during initial setup.
     * Run this once and add the output to your .env or wp-config.php
     *
     * @since 1.0.0
     * @return string ASCII-safe key string.
     */
    public static function generate_key()
    {
        $key = Key::createNewRandomKey();
        return $key->saveToAsciiSafeString();
    }
}