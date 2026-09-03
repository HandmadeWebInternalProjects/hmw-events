<?php

namespace HMWEvents\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Services\FormConfigResolver;
use PHPUnit\Framework\TestCase;

class FormConfigResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_key')->alias(function ($value) {
            return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value));
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_unrestricted_fields_are_retained_for_every_option(): void
    {
        $config = FormConfigResolver::for_attendance([
            'sections' => [[
                'id' => 'details',
                'fields' => [
                    ['key' => 'name'],
                    ['key' => 'child_age', 'attendance_types' => ['parent_child']],
                ],
            ]],
        ], 'individual');

        $this->assertSame(['name'], array_column($config['sections'][0]['fields'], 'key'));
    }

    public function test_targeted_fields_are_retained_only_for_selected_option(): void
    {
        $config = FormConfigResolver::for_attendance([
            'sections' => [[
                'id' => 'details',
                'fields' => [
                    ['key' => 'child_age', 'attendance_types' => ['parent_child']],
                    ['key' => 'professional_id', 'attendance_types' => ['professional']],
                ],
            ]],
        ], 'professional');

        $this->assertSame(['professional_id'], array_column($config['sections'][0]['fields'], 'key'));
    }

    public function test_invalid_attendance_type_uses_individual_rules(): void
    {
        $config = FormConfigResolver::for_attendance([
            'sections' => [[
                'id' => 'details',
                'fields' => [
                    ['key' => 'name'],
                    ['key' => 'child_age', 'attendance_types' => ['parent_child']],
                ],
            ]],
        ], 'not-valid');

        $this->assertSame(['name'], array_column($config['sections'][0]['fields'], 'key'));
    }
}
