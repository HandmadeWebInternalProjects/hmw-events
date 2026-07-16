<?php

namespace HMWEvents\Tests\Unit\Taxonomies;

use HMWEvents\Taxonomies\EventTopic;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EventTopicTest extends TestCase
{
    private EventTopic $taxonomy;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        $this->taxonomy = new EventTopic();

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('sanitize_key')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_taxonomy_constant_is_correct(): void
    {
        $this->assertEquals('hmw_event_topic', EventTopic::TAXONOMY);
    }

    public function test_registers_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(EventTopic::class, $components);
    }

    public function test_register_adds_hook(): void
    {
        Functions\expect('add_action')
            ->once()
            ->with('init', [$this->taxonomy, 'register_taxonomy']);

        $this->taxonomy->register();
        $this->assertTrue(true);
    }
}
