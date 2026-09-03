<?php

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

use HMWEvents\Helpers\Encryption;

class OrganizerPaymentSettings
{
    private ?int $organizer_id;

    public function __construct(?int $organizer_id = null)
    {
        $this->organizer_id = $organizer_id;
    }

    public function set_organizer_id(int $organizer_id): void
    {
        $this->organizer_id = $organizer_id;
    }

    public function get_organizer_id(): ?int
    {
        return $this->organizer_id;
    }

    public function get_payment_type(): ?string
    {
        if (!$this->organizer_id) {
            return null;
        }
        return $this->normalize_payment_type(
            get_user_meta($this->organizer_id, 'hmw_organizer_payment_type', true)
        );
    }

    private function normalize_payment_type($value): ?string
    {
        if (empty($value) || !is_string($value)) {
            return null;
        }

        static $known = ['stripe', 'paypal'];

        if (in_array($value, $known, true)) {
            return $value;
        }

        $decrypted = Encryption::decrypt($value);
        if ($decrypted !== false && $decrypted !== '') {
            return $decrypted;
        }

        return null;
    }

    public function get_stripe_publishable_key(): string
    {
        if (!$this->organizer_id) {
            return '';
        }
        $value = get_user_meta($this->organizer_id, 'hmw_organizer_stripe_publishable', true);
        return !empty($value) ? (string) $value : '';
    }

    public function get_stripe_secret_key(): string
    {
        if (!$this->organizer_id) {
            return '';
        }
        $value = get_user_meta($this->organizer_id, 'hmw_organizer_stripe_secret', true);
        return !empty($value) ? (string) $value : '';
    }

    public function get_paypal_client_id(): string
    {
        if (!$this->organizer_id) {
            return '';
        }
        $value = get_user_meta($this->organizer_id, 'hmw_organizer_paypal_client_id', true);
        return !empty($value) ? (string) $value : '';
    }

    public function get_paypal_secret(): string
    {
        if (!$this->organizer_id) {
            return '';
        }
        $value = get_user_meta($this->organizer_id, 'hmw_organizer_paypal_secret', true);
        return !empty($value) ? (string) $value : '';
    }
}
