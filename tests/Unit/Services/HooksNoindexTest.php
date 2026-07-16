<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\Hooks;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class HooksNoindexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_noindex_outputs_meta_for_archived_event(): void
    {
        Functions\when('is_singular')->justReturn(true);

        $post = new \WP_Post((object) ['post_status' => 'archived']);
        Functions\when('get_post')->justReturn($post);

        ob_start();
        Hooks::noindex_archived_events();
        $output = ob_get_clean();

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $output);
    }

    public function test_noindex_outputs_meta_for_cancelled_event(): void
    {
        Functions\when('is_singular')->justReturn(true);

        $post = new \WP_Post((object) ['post_status' => 'cancelled']);
        Functions\when('get_post')->justReturn($post);

        ob_start();
        Hooks::noindex_archived_events();
        $output = ob_get_clean();

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $output);
    }

    public function test_noindex_skips_published_event(): void
    {
        Functions\when('is_singular')->justReturn(true);

        $post = new \WP_Post((object) ['post_status' => 'publish']);
        Functions\when('get_post')->justReturn($post);

        ob_start();
        Hooks::noindex_archived_events();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function test_noindex_skips_non_event_page(): void
    {
        Functions\when('is_singular')->justReturn(false);

        ob_start();
        Hooks::noindex_archived_events();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function test_noindex_skips_when_no_post(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_post')->justReturn(null);

        ob_start();
        Hooks::noindex_archived_events();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    /**
     * Verify that register() runs without error.
     *
     * The actual hook registration is verified via integration and the
     * component system's HasComponents trait. We stub `add_filter` and
     * `add_action` to prevent them from executing during the test.
     */
    public function test_register_runs_without_errors(): void
    {
        Functions\when('add_filter')->justReturn(true);
        Functions\when('add_action')->justReturn(true);

        Hooks::register();

        $this->assertTrue(true);
    }
}
