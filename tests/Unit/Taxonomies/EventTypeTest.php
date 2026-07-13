<?php

namespace HMWEvents\Tests\Unit\Taxonomies;

use HMWEvents\Taxonomies\EventType;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class EventTypeTest extends TestCase
{
    private EventType $taxonomy;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        if (!class_exists('WP_Post')) {
            eval('class WP_Post { public $ID; public $post_title; public $post_type; }');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        $this->taxonomy = new EventType();

        Functions\when('__')->returnArg();
        Functions\when('_x')->returnArg();
        Functions\when('esc_html_e')->justReturn();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('sanitize_key')->alias(function ($key) {
            $key = (string) $key;
            if ($key === '') {
                return '';
            }
            return preg_replace('/[^a-z0-9_-]/', '', strtolower($key));
        });
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });

        $_POST = [];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_replace_meta_box_removes_default()
    {
        Functions\expect('remove_meta_box')
            ->once()
            ->with('hmw_event_typediv', 'hmw_event', 'side');

        Functions\expect('add_meta_box')
            ->once()
            ->with(
                'hmwevents_event_type_select',
                Mockery::type('string'),
                Mockery::type('array'),
                'hmw_event',
                'side',
                'high'
            );

        $this->taxonomy->replace_taxonomy_meta_box();
        $this->assertTrue(true);
    }

    public function test_save_event_type_bails_on_bad_nonce()
    {
        $_POST['hmwevents_event_type_nonce'] = 'bad_nonce';

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('bad_nonce', 'hmwevents_event_type_save')
            ->andReturn(false);

        Functions\expect('current_user_can')->never();
        Functions\expect('wp_set_object_terms')->never();
        Functions\expect('wp_delete_object_term_relationships')->never();

        $this->taxonomy->save_event_type(123);
        $this->assertTrue(true);
    }

    public function test_save_event_type_bails_when_nonce_not_set()
    {
        Functions\expect('wp_verify_nonce')->never();
        Functions\expect('current_user_can')->never();
        Functions\expect('wp_set_object_terms')->never();
        Functions\expect('wp_delete_object_term_relationships')->never();

        $this->taxonomy->save_event_type(123);
        $this->assertTrue(true);
    }

    public function test_save_event_type_bails_on_no_permission()
    {
        $_POST['hmwevents_event_type_nonce'] = 'valid_nonce';

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('valid_nonce', 'hmwevents_event_type_save')
            ->andReturn(true);

        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 123)
            ->andReturn(false);

        Functions\expect('wp_set_object_terms')->never();
        Functions\expect('wp_delete_object_term_relationships')->never();

        $this->taxonomy->save_event_type(123);
        $this->assertTrue(true);
    }

    public function test_save_event_type_assigns_term()
    {
        $_POST['hmwevents_event_type_nonce'] = 'valid_nonce';
        $_POST['hmwevents_event_type'] = 'parenting-webinar';

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('valid_nonce', 'hmwevents_event_type_save')
            ->andReturn(true);

        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 123)
            ->andReturn(true);

        Functions\expect('wp_set_object_terms')
            ->once()
            ->with(123, 'parenting-webinar', 'hmw_event_type', false);

        Functions\expect('wp_delete_object_term_relationships')->never();

        $this->taxonomy->save_event_type(123);
        $this->assertTrue(true);
    }

    public function test_save_event_type_clears_terms_when_empty_string()
    {
        $_POST['hmwevents_event_type_nonce'] = 'valid_nonce';
        $_POST['hmwevents_event_type'] = '';

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('valid_nonce', 'hmwevents_event_type_save')
            ->andReturn(true);

        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 123)
            ->andReturn(true);

        Functions\expect('wp_delete_object_term_relationships')
            ->once()
            ->with(123, 'hmw_event_type');

        Functions\expect('wp_set_object_terms')->never();

        $this->taxonomy->save_event_type(123);
        $this->assertTrue(true);
    }

    public function test_save_event_type_clears_terms_when_not_set()
    {
        $_POST['hmwevents_event_type_nonce'] = 'valid_nonce';

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('valid_nonce', 'hmwevents_event_type_save')
            ->andReturn(true);

        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 123)
            ->andReturn(true);

        Functions\expect('wp_delete_object_term_relationships')
            ->once()
            ->with(123, 'hmw_event_type');

        Functions\expect('wp_set_object_terms')->never();

        $this->taxonomy->save_event_type(123);
        $this->assertTrue(true);
    }

    public function test_render_event_type_select_passes_correct_args()
    {
        $post = new \WP_Post();
        $post->ID = 123;

        Functions\expect('wp_get_object_terms')
            ->once()
            ->with(123, 'hmw_event_type', ['fields' => 'slugs'])
            ->andReturn(['parenting-webinar']);

        Functions\expect('wp_nonce_field')
            ->once()
            ->with('hmwevents_event_type_save', 'hmwevents_event_type_nonce');

        $dropdown_args = null;
        Functions\expect('wp_dropdown_categories')
            ->once()
            ->andReturnUsing(function ($args) use (&$dropdown_args) {
                $dropdown_args = $args;
            });

        $this->taxonomy->render_event_type_select($post);

        $this->assertIsArray($dropdown_args);
        $this->assertEquals('hmw_event_type', $dropdown_args['taxonomy']);
        $this->assertEquals('slug', $dropdown_args['value_field']);
        $this->assertEquals('parenting-webinar', $dropdown_args['selected']);
        $this->assertEquals('hmwevents-event-type-select', $dropdown_args['id']);
        $this->assertEquals('hmwevents_event_type', $dropdown_args['name']);
        $this->assertEquals('', $dropdown_args['option_none_value']);
        $this->assertFalse($dropdown_args['hide_empty']);
        $this->assertTrue($dropdown_args['hierarchical']);
    }

    public function test_render_event_type_select_handles_no_terms()
    {
        $post = new \WP_Post();
        $post->ID = 123;

        Functions\expect('wp_get_object_terms')
            ->once()
            ->with(123, 'hmw_event_type', ['fields' => 'slugs'])
            ->andReturn([]);

        Functions\expect('wp_nonce_field')->once();

        $dropdown_args = null;
        Functions\expect('wp_dropdown_categories')
            ->once()
            ->andReturnUsing(function ($args) use (&$dropdown_args) {
                $dropdown_args = $args;
            });

        $this->taxonomy->render_event_type_select($post);

        $this->assertEquals('', $dropdown_args['selected']);
    }

    public function test_render_event_type_select_handles_wp_error()
    {
        $post = new \WP_Post();
        $post->ID = 123;

        Functions\expect('wp_get_object_terms')
            ->once()
            ->with(123, 'hmw_event_type', ['fields' => 'slugs'])
            ->andReturn(new \WP_Error('test', 'test error'));

        Functions\expect('wp_nonce_field')->once();

        $dropdown_args = null;
        Functions\expect('wp_dropdown_categories')
            ->once()
            ->andReturnUsing(function ($args) use (&$dropdown_args) {
                $dropdown_args = $args;
            });

        $this->taxonomy->render_event_type_select($post);

        $this->assertEquals('', $dropdown_args['selected']);
    }

    public function test_taxonomy_constant_is_correct()
    {
        $this->assertEquals('hmw_event_type', EventType::TAXONOMY);
    }
}
