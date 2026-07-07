<?php

namespace HMWEvents\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Services\TemplateResolver;
use HMWEvents\Services\TemplateSchemaValidator;
use PHPUnit\Framework\TestCase;

class TemplateConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('__')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('sanitize_key')->alias(function ($value) {
            $value = strtolower((string) $value);
            return preg_replace('/[^a-z0-9_\-]/', '', $value);
        });
        Functions\when('is_wp_error')->alias(function ($value) {
            return $value instanceof \WP_Error;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_validator_normalizes_legacy_template_shape(): void
    {
        $validator = new TemplateSchemaValidator();

        $normalized = $validator->normalize([
            'post_title'          => 'Legacy Template',
            'post_content'        => 'Intro',
            'meta'                => ['event_capacity' => 40],
            'event_delivery_mode' => 'online',
        ]);

        $this->assertIsArray($normalized);
        $this->assertSame(1, $normalized['schema_version']);
        $this->assertSame(1, $normalized['template_version']);
        $this->assertSame('Legacy Template', $normalized['post']['post_title']);
        $this->assertSame('Intro', $normalized['post']['post_content']);
        $this->assertSame(40, $normalized['defaults']['event_meta']['event_capacity']);
        $this->assertSame('online', $normalized['defaults']['event_meta']['event_delivery_mode']);
    }

    public function test_validator_rejects_unknown_registration_field(): void
    {
        $validator = new TemplateSchemaValidator();

        $result = $validator->normalize([
            'registration_fields' => [
                'required' => ['this_field_does_not_exist'],
            ],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_template_schema', $result->get_error_code());
    }

    public function test_resolver_prioritizes_hidden_over_required(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('webinar', [
            'registration_fields' => [
                'required' => ['phone'],
                'hidden'   => ['phone'],
            ],
        ]);

        $this->assertIsArray($resolved);
        $this->assertContains('phone', $resolved['field_config']['registration_fields']['hidden']);
        $this->assertNotContains('phone', $resolved['field_config']['registration_fields']['required']);
    }

    public function test_resolver_merges_registry_template_and_overrides(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('workshop', [
            'defaults' => [
                'event_meta' => [
                    'event_capacity' => 44,
                ],
            ],
        ], [
            'post_title' => 'Snapshot Event',
            'event_meta' => [
                'event_capacity' => 12,
                'event_delivery_mode' => 'online',
            ],
        ]);

        $this->assertIsArray($resolved);
        $this->assertSame('Snapshot Event', $resolved['post']['post_title']);
        $this->assertSame(12, $resolved['event_meta']['event_capacity']);
        $this->assertSame('online', $resolved['event_meta']['event_delivery_mode']);
    }
}
