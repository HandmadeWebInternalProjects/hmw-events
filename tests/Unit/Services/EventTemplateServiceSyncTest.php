<?php

namespace HMWEvents\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Services\EventTemplateService;
use PHPUnit\Framework\TestCase;
use Patchwork;

class EventTemplateServiceSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        Functions\when('__')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('sanitize_key')->alias(function ($value) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
        });
        Functions\when('is_wp_error')->alias(function ($value) {
            return $value instanceof \WP_Error;
        });
        Functions\when('error_log')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_post')->justReturn((object) [
            'post_type'  => 'hmw_event',
            'post_title' => 'Ev',
        ]);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('wp_get_object_terms')->justReturn([]);

        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);

        Patchwork\restoreAll();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_reapply_template_preserves_existing_event_level_description(): void
    {
        [$service, $wpdb] = $this->makeReapplyEnvironment([
            (object) [
                'id'            => 11,
                'option_type'   => 'couple',
                'price'         => 500,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'description'   => 'Event-level description',
            ],
        ]);

        $result = $service->reapply_template_to_event(123, 4, ['attendance_options']);

        $this->assertSame(1, $result);

        $this->assertCount(1, $wpdb->deletes);
        $this->assertSame('wp_hmwevents_event_attendance_options', $wpdb->deletes[0]['table']);
        $this->assertSame(['event_post_id' => 123], $wpdb->deletes[0]['where']);

        $this->assertCount(1, $wpdb->inserts);
        $insert = $wpdb->inserts[0];

        $this->assertSame('wp_hmwevents_event_attendance_options', $insert['table']);
        $this->assertSame('Event-level description', $insert['data']['description']);
        $this->assertSame(500.0, $insert['data']['price']);
        $this->assertSame('couple', $insert['data']['option_type']);
        $this->assertSame('couple', $insert['data']['option_key']);
        $this->assertSame('flat', $insert['data']['price_mode']);
        $this->assertNull($insert['data']['capacity']);
        $this->assertSame(0, $insert['data']['sort_order']);
        $this->assertSame(1, $insert['data']['is_active']);
        $this->assertSame('2026-01-01 00:00:00', $insert['data']['created_at']);
        $this->assertSame('2026-01-01 00:00:00', $insert['data']['updated_at']);
    }

    public function test_reapply_template_falls_back_to_preset_description_when_existing_is_empty(): void
    {
        [$service, $wpdb] = $this->makeReapplyEnvironment([
            (object) [
                'id'            => 11,
                'option_type'   => 'couple',
                'price'         => 500,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'description'   => '',
            ],
        ]);

        $result = $service->reapply_template_to_event(123, 4, ['attendance_options']);

        $this->assertSame(1, $result);

        $this->assertCount(1, $wpdb->inserts);
        $insert = $wpdb->inserts[0];

        $this->assertSame('Template description', $insert['data']['description']);
        $this->assertSame(500.0, $insert['data']['price']);
    }

    private function makeReapplyEnvironment(array $existing_rows): array
    {
        $wpdb = $this->makeWpdbFake($existing_rows);
        $GLOBALS['wpdb'] = $wpdb;

        $payload = $this->template_payload();

        Patchwork\replace('HMWEvents\\Services\\EventTemplateService::get', function () use ($payload) {
            return (object) [
                'id'              => 4,
                'title'           => 'T',
                'event_type_slug' => 'professional-in-person',
                'template_data'   => $payload,
                'is_retired'      => 0,
            ];
        });

        return [new EventTemplateService(), $wpdb];
    }

    private function makeWpdbFake(array $existing_rows): object
    {
        return new class($existing_rows) {
            public array $inserts = [];
            public array $deletes = [];
            private array $existing;

            public function __construct(array $existing)
            {
                $this->existing = $existing;
            }

            public function get_results($query = null)
            {
                return $this->existing;
            }

            public function get_col($query = null)
            {
                return [];
            }

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function esc_like($text)
            {
                return addcslashes((string) $text, '_%\\');
            }

            public function delete($table, $where, $where_format = [])
            {
                $this->deletes[] = [
                    'table'        => $table,
                    'where'        => $where,
                    'where_format' => $where_format,
                ];

                return 1;
            }

            public function insert($table, $data, $format = [])
            {
                $this->inserts[] = [
                    'table'  => $table,
                    'data'   => $data,
                    'format' => $format,
                ];

                return 1;
            }
        };
    }

    private function template_payload(): array
    {
        return [
            'schema_version'      => 3,
            'template_version'    => 1,
            'post'                => [
                'title_pattern' => '',
                'post_content'  => '',
                'post_title'    => '',
            ],
            'event_fields'        => [
                'required' => [],
                'optional' => [],
                'hidden'   => [],
            ],
            'registration_fields' => [
                'sections'      => [],
                'multi_booking' => [
                    'enabled' => false,
                    'min'     => 1,
                    'max'     => 10,
                ],
            ],
            'defaults'            => [
                'event_meta'         => [],
                'registration'       => [
                    'attendance_default' => 'individual',
                    'field_overrides'    => [],
                ],
                'attendance_options' => [
                    [
                        'option_type'   => 'couple',
                        'label'         => 'Couple',
                        'description'   => 'Template description',
                        'price'         => 400,
                        'capacity'      => null,
                        'price_mode'    => 'flat',
                        'pricing_rules' => [],
                    ],
                ],
            ],
        ];
    }
}
