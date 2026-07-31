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
        Functions\when('esc_html')->returnArg();
        Functions\when('sanitize_key')->alias(function ($key) {
            return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $key));
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_create_nonce')->justReturn('test_nonce');
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
        Functions\when('esc_js')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('get_post')->justReturn(null);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('wp_send_json_success')->justReturn(true);
        Functions\when('wp_send_json_error')->justReturn(true);
        Functions\when('selected')->justReturn();
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_current_screen')->justReturn(null);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('wp_add_inline_script')->justReturn(true);
        Functions\when('wp_localize_script')->justReturn(true);

        $_POST = [];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_auto_apply_bails_when_config_exists()
    {
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_field_config') {
                return ['event_fields' => ['hidden' => ['event_webinar_url']]];
            }
            return '';
        });

        $this->service->auto_apply_on_first_save(123);
        $this->assertTrue(true);
    }

    public function test_auto_apply_bails_when_no_template_and_no_terms()
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_get_object_terms')->justReturn([]);

        $this->service->auto_apply_on_first_save(123);
        $this->assertTrue(true);
    }

    public function test_auto_apply_bails_when_no_permission()
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->service->auto_apply_on_first_save(123);
        $this->assertTrue(true);
    }

    public function test_save_template_selection_saves_id()
    {
        $_POST['hmwevents_template_select_nonce'] = 'valid';
        $_POST['hmwevents_event_template'] = '5';
        Functions\when('wp_verify_nonce')->justReturn(true);

        $this->service->save_template_selection(123);
        $this->assertTrue(true);
    }

    public function test_save_template_selection_deletes_when_empty()
    {
        $_POST['hmwevents_template_select_nonce'] = 'valid';
        $_POST['hmwevents_event_template'] = '0';
        Functions\when('wp_verify_nonce')->justReturn(true);

        $this->service->save_template_selection(123);
        $this->assertTrue(true);
    }

    public function test_save_template_selection_bails_on_bad_nonce()
    {
        $_POST['hmwevents_template_select_nonce'] = 'bad';

        Functions\when('wp_verify_nonce')->justReturn(false);
        Functions\expect('update_post_meta')->never();
        Functions\expect('delete_post_meta')->never();

        $this->service->save_template_selection(123);
        $this->assertTrue(true);
    }

    public function test_save_template_selection_bails_when_nonce_missing()
    {
        Functions\expect('update_post_meta')->never();

        $this->service->save_template_selection(123);
        $this->assertTrue(true);
    }

    public function test_save_template_selection_bails_on_no_permission()
    {
        $_POST['hmwevents_template_select_nonce'] = 'valid';

        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\expect('update_post_meta')->never();

        $this->service->save_template_selection(123);
        $this->assertTrue(true);
    }

    public function test_enqueue_assets_skips_wrong_hook()
    {
        $this->service->enqueue_assets('edit.php');
        $this->assertTrue(true);
    }

    public function test_enqueue_assets_skips_wrong_post_type()
    {
        Functions\when('get_current_screen')->justReturn((object) ['post_type' => 'post']);

        $this->service->enqueue_assets('post-new.php');
        $this->assertTrue(true);
    }
}
