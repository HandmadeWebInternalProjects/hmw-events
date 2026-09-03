<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Helpers\Encryption;
use HMWEvents\Services\OrganizerPaymentSettings;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class OrganizerPaymentSettingsTest extends TestCase
{
    private const TEST_ENCRYPTION_KEY = 'def00000a65fb830f18acd33d23b3690134242caa5485fc5db9f3787e3382ffc96a4f776cf709a000c7aa8b8773d3747f72faaff12ef6d2f34c6f6e005e562a70dc7f019';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_ENCRYPTION_KEY);
        }

        $this->reset_encryption_key_cache();

        Functions\when('get_user_meta')->justReturn(false);
    }

    protected function tearDown(): void
    {
        $this->reset_encryption_key_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function reset_encryption_key_cache(): void
    {
        $reflection = new \ReflectionClass(Encryption::class);
        $prop = $reflection->getProperty('key');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function test_instantiable_without_organizer_id(): void
    {
        $settings = new OrganizerPaymentSettings();
        $this->assertInstanceOf(OrganizerPaymentSettings::class, $settings);
    }

    public function test_instantiable_with_organizer_id(): void
    {
        $settings = new OrganizerPaymentSettings(42);
        $this->assertInstanceOf(OrganizerPaymentSettings::class, $settings);
    }

    public function test_get_organizer_id_returns_constructor_value(): void
    {
        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame(42, $settings->get_organizer_id());
    }

    public function test_get_organizer_id_returns_null_when_not_set(): void
    {
        $settings = new OrganizerPaymentSettings();
        $this->assertNull($settings->get_organizer_id());
    }

    public function test_set_organizer_id_updates_id(): void
    {
        $settings = new OrganizerPaymentSettings();
        $settings->set_organizer_id(99);
        $this->assertSame(99, $settings->get_organizer_id());
    }

    public function test_get_payment_type_returns_null_without_organizer(): void
    {
        $settings = new OrganizerPaymentSettings();
        $this->assertNull($settings->get_payment_type());
    }

    public function test_get_payment_type_returns_value(): void
    {
        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === 'hmw_organizer_payment_type') {
                return 'stripe';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame('stripe', $settings->get_payment_type());
    }

    public function test_get_payment_type_returns_null_for_empty(): void
    {
        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === 'hmw_organizer_payment_type') {
                return '';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertNull($settings->get_payment_type());
    }

    public function test_get_payment_type_decrypts_encrypted_stripe(): void
    {
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_ENCRYPTION_KEY);
        }

        $this->reset_encryption_key_cache();

        $encrypted = Encryption::encrypt('stripe');
        $this->assertNotFalse($encrypted);
        $this->assertNotEquals('stripe', $encrypted);

        Functions\when('get_user_meta')->alias(function ($id, $key, $single) use ($encrypted) {
            if ($id === 42 && $key === 'hmw_organizer_payment_type') {
                return $encrypted;
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame('stripe', $settings->get_payment_type());
    }

    public function test_get_payment_type_decrypts_encrypted_paypal(): void
    {
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_ENCRYPTION_KEY);
        }

        $this->reset_encryption_key_cache();

        $encrypted = Encryption::encrypt('paypal');
        $this->assertNotFalse($encrypted);
        $this->assertNotEquals('paypal', $encrypted);

        Functions\when('get_user_meta')->alias(function ($id, $key, $single) use ($encrypted) {
            if ($id === 42 && $key === 'hmw_organizer_payment_type') {
                return $encrypted;
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame('paypal', $settings->get_payment_type());
    }

    public function test_get_payment_type_returns_null_when_decryption_fails(): void
    {
        if (!defined('DEFUSE_ENCRYPTION_KEY')) {
            define('DEFUSE_ENCRYPTION_KEY', self::TEST_ENCRYPTION_KEY);
        }

        $this->reset_encryption_key_cache();

        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === 'hmw_organizer_payment_type') {
                return 'bogus_ciphertext_not_encrypted_by_defuse';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertNull($settings->get_payment_type());
    }

    public function test_get_payment_type_preserves_plain_stripe(): void
    {
        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === 'hmw_organizer_payment_type') {
                return 'stripe';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame('stripe', $settings->get_payment_type());
    }

    public function test_get_payment_type_preserves_plain_paypal(): void
    {
        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === 'hmw_organizer_payment_type') {
                return 'paypal';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame('paypal', $settings->get_payment_type());
    }

    public function test_get_stripe_publishable_key_returns_empty_without_organizer(): void
    {
        $settings = new OrganizerPaymentSettings();
        $this->assertSame('', $settings->get_stripe_publishable_key());
    }

    public function test_get_stripe_publishable_key_returns_value(): void
    {
        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === 'hmw_organizer_stripe_publishable') {
                return 'pk_test_abc123';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame('pk_test_abc123', $settings->get_stripe_publishable_key());
    }

    public function test_get_stripe_publishable_key_returns_empty_for_empty_value(): void
    {
        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === 'hmw_organizer_stripe_publishable') {
                return '';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame('', $settings->get_stripe_publishable_key());
    }

    public function test_get_stripe_secret_key_returns_empty_without_organizer(): void
    {
        $settings = new OrganizerPaymentSettings();
        $this->assertSame('', $settings->get_stripe_secret_key());
    }

    public function test_get_stripe_secret_key_returns_value(): void
    {
        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === 'hmw_organizer_stripe_secret') {
                return 'sk_test_xyz789';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame('sk_test_xyz789', $settings->get_stripe_secret_key());
    }

    public function test_get_stripe_secret_key_returns_empty_for_empty_value(): void
    {
        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === 'hmw_organizer_stripe_secret') {
                return '';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame('', $settings->get_stripe_secret_key());
    }

    public function test_get_paypal_client_id_returns_empty_without_organizer(): void
    {
        $settings = new OrganizerPaymentSettings();
        $this->assertSame('', $settings->get_paypal_client_id());
    }

    public function test_get_paypal_client_id_returns_value(): void
    {
        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === 'hmw_organizer_paypal_client_id') {
                return 'paypal_client_123';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame('paypal_client_123', $settings->get_paypal_client_id());
    }

    public function test_get_paypal_secret_returns_empty_without_organizer(): void
    {
        $settings = new OrganizerPaymentSettings();
        $this->assertSame('', $settings->get_paypal_secret());
    }

    public function test_get_paypal_secret_returns_value(): void
    {
        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === 'hmw_organizer_paypal_secret') {
                return 'paypal_secret_456';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);
        $this->assertSame('paypal_secret_456', $settings->get_paypal_secret());
    }

    public function test_no_educator_fallbacks(): void
    {
        Functions\when('get_user_meta')->alias(function ($id, $key, $single) {
            if (str_starts_with($key, 'hmw_organizer_')) {
                return '';
            }
            if ($key === 'educator_stripe_key' || $key === 'educator_stripe_secret' || $key === 'educator_payment_type') {
                return 'legacy_value';
            }
            return '';
        });

        $settings = new OrganizerPaymentSettings(42);

        $this->assertNull($settings->get_payment_type());
        $this->assertSame('', $settings->get_stripe_publishable_key());
        $this->assertSame('', $settings->get_stripe_secret_key());
    }
}
