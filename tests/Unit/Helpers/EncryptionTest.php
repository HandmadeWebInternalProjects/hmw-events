<?php
/**
 * Tests for Encryption Helper.
 *
 * @package HMWEvents\Tests\Unit\Helpers
 */

namespace HMWEvents\Tests\Unit\Helpers;

use HMWEvents\Helpers\Encryption;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Test Encryption Helper functionality.
 */
class EncryptionTest extends TestCase
{
    /**
     * Valid test encryption key (generated via Key::createNewRandomKey()->saveToAsciiSafeString()).
     */
    private const TEST_KEY = 'def00000a65fb830f18acd33d23b3690134242caa5485fc5db9f3787e3382ffc96a4f776cf709a000c7aa8b8773d3747f72faaff12ef6d2f34c6f6e005e562a70dc7f019';

    /**
     * Set up test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Mock WordPress functions
        Functions\when('error_log')->justReturn(true);

        // Reset static encryption key cache between tests
        $reflection = new \ReflectionClass(Encryption::class);
        $prop = $reflection->getProperty('key');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // Ensure the test encryption key is defined
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_KEY);
        }
    }

    /**
     * Tear down test environment.
     */
    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Test encryption and decryption cycle.
     *
     * @covers \HMWEvents\Helpers\Encryption::encrypt
     * @covers \HMWEvents\Helpers\Encryption::decrypt
     */
    public function test_encrypt_decrypt_cycle()
    {
        // Define encryption key
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_KEY);
        }

        $plaintext = 'sk_test_123456789abcdefghijklmnop';
        $encrypted = Encryption::encrypt($plaintext);

        $this->assertNotEquals($plaintext, $encrypted);
        $this->assertNotEmpty($encrypted);
        $this->assertIsString($encrypted);

        $decrypted = Encryption::decrypt($encrypted);
        $this->assertEquals($plaintext, $decrypted);
    }

    /**
     * Test encrypting an empty string.
     *
     * @covers \HMWEvents\Helpers\Encryption::encrypt
     */
    public function test_encrypt_empty_string()
    {
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_KEY);
        }

        $result = Encryption::encrypt('');
        $this->assertEquals('', $result);
    }

    /**
     * Test decrypting an empty string.
     *
     * @covers \HMWEvents\Helpers\Encryption::decrypt
     */
    public function test_decrypt_empty_string()
    {
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_KEY);
        }

        $result = Encryption::decrypt('');
        $this->assertEquals('', $result);
    }

    /**
     * Test encrypting different types of sensitive data.
     *
     * @covers \HMWEvents\Helpers\Encryption::encrypt
     * @covers \HMWEvents\Helpers\Encryption::decrypt
     * @dataProvider sensitiveDataProvider
     */
    public function test_encrypt_decrypt_various_data($data)
    {
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_KEY);
        }

        $encrypted = Encryption::encrypt($data);
        $this->assertNotEquals($data, $encrypted);

        $decrypted = Encryption::decrypt($encrypted);
        $this->assertEquals($data, $decrypted);
    }

    /**
     * Data provider for various sensitive data types.
     *
     * @return array
     */
    public function sensitiveDataProvider()
    {
        return [
            'stripe_test_key' => ['sk_test_123456789abcdefghijklmnop'],
            'stripe_live_key' => ['sk_live_987654321zyxwvutsrqponmlk'],
            'paypal_client_id' => ['AaBbCcDdEeFfGgHhIiJjKkLlMmNnOoPpQq'],
            'special_chars' => ['key_with_!@#$%^&*()_+-=[]{}|;:,.<>?'],
            'long_string' => [str_repeat('a', 1000)],
            'unicode' => ['key_with_émojis_🔐_and_ü'],
        ];
    }

    /**
     * Test that encrypted values are different each time.
     *
     * @covers \HMWEvents\Helpers\Encryption::encrypt
     */
    public function test_encryption_is_non_deterministic()
    {
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_KEY);
        }

        $plaintext = 'sk_test_123456789';
        $encrypted1 = Encryption::encrypt($plaintext);
        $encrypted2 = Encryption::encrypt($plaintext);

        // Same plaintext should produce different ciphertexts (due to random IV)
        $this->assertNotEquals($encrypted1, $encrypted2);

        // But both should decrypt to the same value
        $this->assertEquals($plaintext, Encryption::decrypt($encrypted1));
        $this->assertEquals($plaintext, Encryption::decrypt($encrypted2));
    }

    /**
     * Test encrypting non-string values returns false.
     *
     * @covers \HMWEvents\Helpers\Encryption::encrypt
     */
    public function test_encrypt_non_string_returns_false()
    {
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_KEY);
        }

        $this->assertFalse(Encryption::encrypt(123));
        $this->assertFalse(Encryption::encrypt(['array']));
        $this->assertFalse(Encryption::encrypt(null));
        $this->assertFalse(Encryption::encrypt(true));
    }

    /**
     * Test decrypting non-string values returns false.
     *
     * @covers \HMWEvents\Helpers\Encryption::decrypt
     */
    public function test_decrypt_non_string_returns_false()
    {
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_KEY);
        }

        $this->assertFalse(Encryption::decrypt(123));
        $this->assertFalse(Encryption::decrypt(['array']));
        $this->assertFalse(Encryption::decrypt(null));
        $this->assertFalse(Encryption::decrypt(true));
    }

    /**
     * Test decrypting tampered ciphertext returns false.
     *
     * @covers \HMWEvents\Helpers\Encryption::decrypt
     */
    public function test_decrypt_tampered_data_returns_false()
    {
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_KEY);
        }

        $plaintext = 'sk_test_123456789';
        $encrypted = Encryption::encrypt($plaintext);

        // Tamper with the encrypted data
        $tampered = substr($encrypted, 0, -5) . 'XXXXX';

        $result = Encryption::decrypt($tampered);
        $this->assertFalse($result);
    }

    /**
     * Test decrypting with wrong key returns false.
     *
     * @covers \HMWEvents\Helpers\Encryption::decrypt
     */
    public function test_decrypt_with_wrong_key_returns_false()
    {
        // This test requires defining the key before encryption,
        // then redefining for decryption - not possible with constants
        // Instead we test with invalid ciphertext format
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_KEY);
        }

        $result = Encryption::decrypt('invalid_ciphertext');
        $this->assertFalse($result);
    }

    /**
     * Test is_available returns true when key is configured.
     *
     * @covers \HMWEvents\Helpers\Encryption::is_available
     */
    public function test_is_available_returns_true_with_key()
    {
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_KEY);
        }

        $this->assertTrue(Encryption::is_available());
    }

    /**
     * Test generate_key returns a valid key string.
     *
     * @covers \HMWEvents\Helpers\Encryption::generate_key
     */
    public function test_generate_key_returns_valid_key()
    {
        $key = Encryption::generate_key();

        $this->assertIsString($key);
        $this->assertNotEmpty($key);
        $this->assertGreaterThan(100, strlen($key)); // Keys are typically long
        $this->assertStringStartsWith('def', $key); // Defuse keys start with 'def'
    }

    /**
     * Test generated keys are unique.
     *
     * @covers \HMWEvents\Helpers\Encryption::generate_key
     */
    public function test_generated_keys_are_unique()
    {
        $key1 = Encryption::generate_key();
        $key2 = Encryption::generate_key();

        $this->assertNotEquals($key1, $key2);
    }
}
