<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\EventTypeDefaultsService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class EventTypeDefaultsServiceTest extends TestCase
{
    private EventTypeDefaultsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        $this->service = new EventTypeDefaultsService();

        Functions\when('__')->returnArg();
        Functions\when('esc_html_e')->justReturn();
        Functions\when('esc_html__')->returnArg();
        Functions\when('sanitize_key')->alias(function ($key) {
            return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $key));
        });
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_create_nonce')->justReturn('test_nonce');
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
        Functions\when('esc_js')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('update_post_meta')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_auto_apply_bails_when_config_exists()
    {
        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 123)
            ->andReturn(true);

        Functions\expect('get_post_meta')
            ->once()
            ->with(123, '_event_field_config', true)
            ->andReturn(['event_fields' => ['hidden' => ['event_webinar_url']]]);

        Functions\expect('wp_get_object_terms')->never();

        $this->service->auto_apply_on_first_save(123);
        $this->assertTrue(true);
    }

    public function test_auto_apply_bails_when_no_event_type()
    {
        Functions\expect('get_post_meta')
            ->once()
            ->with(123, '_event_field_config', true)
            ->andReturn('');

        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 123)
            ->andReturn(true);

        Functions\expect('wp_get_object_terms')
            ->once()
            ->with(123, 'hmw_event_type', ['fields' => 'slugs'])
            ->andReturn([]);

        Functions\expect('update_post_meta')->never();

        $this->service->auto_apply_on_first_save(123);
        $this->assertTrue(true);
    }

    public function test_auto_apply_bails_on_wp_error_terms()
    {
        Functions\expect('get_post_meta')
            ->once()
            ->with(123, '_event_field_config', true)
            ->andReturn('');

        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 123)
            ->andReturn(true);

        $error = new \WP_Error('test', 'test error');
        Functions\expect('wp_get_object_terms')
            ->once()
            ->with(123, 'hmw_event_type', ['fields' => 'slugs'])
            ->andReturn($error);

        Functions\expect('update_post_meta')->never();

        $this->service->auto_apply_on_first_save(123);
        $this->assertTrue(true);
    }

    public function test_auto_apply_calls_apply_defaults_with_correct_slug()
    {
        Functions\expect('get_post_meta')
            ->with(123, '_event_field_config', Mockery::any())
            ->andReturn('');

        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 123)
            ->andReturn(true);

        Functions\expect('wp_get_object_terms')
            ->once()
            ->with(123, 'hmw_event_type', ['fields' => 'slugs'])
            ->andReturn(['parent-one-off-free']);

        Functions\expect('wp_delete_object_term_relationships')->never();
        Functions\expect('wp_set_object_terms')->never();

        $this->service->auto_apply_on_first_save(123);
        $this->assertTrue(true);
    }

    public function test_auto_apply_bails_when_no_permission()
    {
        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 123)
            ->andReturn(false);

        Functions\expect('get_post_meta')->never();
        Functions\expect('wp_get_object_terms')->never();

        $this->service->auto_apply_on_first_save(123);
        $this->assertTrue(true);
    }

    public function test_localized_data_structure()
    {
        Functions\expect('get_current_screen')
            ->once()
            ->andReturn((object) ['post_type' => 'hmw_event']);

        $captured = null;
        Functions\expect('wp_localize_script')
            ->once()
            ->andReturnUsing(function ($handle, $name, $data) use (&$captured) {
                $captured = $data;
                return true;
            });

        Functions\expect('wp_enqueue_script')->once();
        Functions\expect('wp_add_inline_script')->once();

        $this->service->enqueue_assets('post-new.php');

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('hiddenFields', $captured);
        $this->assertArrayHasKey('allHideableFields', $captured);

        $expected_types = ['parenting-webinar', 'professional-webinar', 'parent-one-off-free', 'parent-walk-in', 'parent-course', 'professional-online', 'professional-in-person'];
        foreach ($expected_types as $type) {
            $this->assertArrayHasKey($type, $captured['hiddenFields']);
        }

        $this->assertContains('event_webinar_url', $captured['hiddenFields']['parent-one-off-free']);
        $this->assertContains('event_venue_name', $captured['hiddenFields']['parenting-webinar']);
        $this->assertContains('event_venue_address', $captured['hiddenFields']['parenting-webinar']);

        $this->assertIsArray($captured['hiddenFields']['professional-in-person']);
        $this->assertContains('event_webinar_url', $captured['hiddenFields']['professional-in-person']);

        $this->assertContains('event_webinar_url', $captured['allHideableFields']);
        $this->assertContains('event_venue_name', $captured['allHideableFields']);
    }

    public function test_enqueue_assets_skips_wrong_hook()
    {
        Functions\expect('get_current_screen')->never();
        Functions\expect('wp_enqueue_script')->never();

        $this->service->enqueue_assets('edit.php');
        $this->assertTrue(true);
    }

    public function test_enqueue_assets_skips_wrong_post_type()
    {
        Functions\expect('get_current_screen')
            ->once()
            ->andReturn((object) ['post_type' => 'post']);

        Functions\expect('wp_enqueue_script')->never();

        $this->service->enqueue_assets('post-new.php');
        $this->assertTrue(true);
    }
}
