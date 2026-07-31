<?php

namespace HMWEvents\Tests\Unit\Taxonomies;

use HMWEvents\Taxonomies\EventType;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery;

class EventTypeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_taxonomy_constant_is_correct()
    {
        $this->assertEquals('hmw_event_type', EventType::TAXONOMY);
    }
}
