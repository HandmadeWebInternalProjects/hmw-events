<?php
/**
 * Phase 3 tests — EventTypeRegistry + EventTemplateService.
 *
 * @package HMWEvents\Tests\Unit
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit;

use HMWEvents\Registry\EventTypeRegistry;
use HMWEvents\Services\EventTemplateService;
use HMWEvents\Services\DatabaseService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EventTypeRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // Archetype count
    // ============================================================

    public function test_all_archetypes_contains_seven_types(): void
    {
        $all = EventTypeRegistry::all();
        $this->assertCount(7, $all, 'Must define all 7 required archetypes');
    }

    public function test_all_archetypes_contains_expected_slugs(): void
    {
        $all = EventTypeRegistry::all();
        $expected = [
            'webinar',
            'workshop',
            'course',
            'seminar',
            'conference',
            'parent-education',
            'professional-dev',
        ];

        foreach ($expected as $slug) {
            $this->assertArrayHasKey($slug, $all, "Missing archetype: {$slug}");
        }
    }

    // ============================================================
    // Field visibility
    // ============================================================

    public function test_webinar_hides_venue_fields(): void
    {
        $config = EventTypeRegistry::get('webinar');
        $this->assertContains('event_venue_name', $config['hidden_fields']);
        $this->assertContains('event_venue_address', $config['hidden_fields']);
        $this->assertNotContains('event_webinar_url', $config['hidden_fields']);
    }

    public function test_is_field_visible_for_webinar(): void
    {
        $this->assertFalse(EventTypeRegistry::is_field_visible('webinar', 'event_venue_name'));
        $this->assertTrue(EventTypeRegistry::is_field_visible('webinar', 'event_start_date'));
    }

    public function test_workshop_does_not_hide_online_fields(): void
    {
        $this->assertTrue(EventTypeRegistry::is_field_visible('workshop', 'event_venue_address'));
    }

    // ============================================================
    // Required fields
    // ============================================================

    public function test_is_field_required(): void
    {
        $this->assertTrue(EventTypeRegistry::is_field_required('webinar', 'event_start_date'));
        $this->assertTrue(EventTypeRegistry::is_field_required('webinar', 'event_webinar_url'));
        $this->assertFalse(EventTypeRegistry::is_field_required('webinar', 'event_venue_name'));
    }

    public function test_conference_requires_venue(): void
    {
        $this->assertTrue(EventTypeRegistry::is_field_required('conference', 'event_venue_name'));
    }

    // ============================================================
    // Workflow
    // ============================================================

    public function test_workflow_has_sensible_transitions(): void
    {
        $workflow = EventTypeRegistry::get_workflow('course');

        $this->assertArrayHasKey('draft', $workflow);
        $this->assertArrayHasKey('publish', $workflow);
        $this->assertContains('publish', $workflow['draft']);
        $this->assertContains('cancelled', $workflow['publish']);
    }

    public function test_archived_can_return_to_publish(): void
    {
        $workflow = EventTypeRegistry::defaults()['workflow'];
        $this->assertContains('publish', $workflow['archived']);
    }

    // ============================================================
    // Attendance option presets
    // ============================================================

    public function test_course_has_couple_and_individual_presets(): void
    {
        $presets = EventTypeRegistry::get_attendance_option_presets('course');
        $types = array_column($presets, 'option_type');
        $this->assertContains('couple', $types);
        $this->assertContains('individual', $types);
        $this->assertContains('professional', $types);
    }

    public function test_parent_education_has_parent_preset(): void
    {
        $presets = EventTypeRegistry::get_attendance_option_presets('parent-education');
        $types = array_column($presets, 'option_type');
        $this->assertContains('parent', $types);
        $this->assertContains('parent_child', $types);
    }

    // ============================================================
    // Comm templates
    // ============================================================

    public function test_get_comm_template_returns_correct_keys(): void
    {
        $this->assertSame(
            'parent_booking_confirmed',
            EventTypeRegistry::get_comm_template('parent-education', 'booking_confirmed')
        );
    }

    public function test_default_comm_template_falls_back_to_standard(): void
    {
        $this->assertSame(
            'booking_confirmed',
            EventTypeRegistry::get_comm_template('workshop', 'booking_confirmed')
        );
    }

    // ============================================================
    // Default meta
    // ============================================================

    public function test_course_default_capacity_is_12(): void
    {
        $meta = EventTypeRegistry::get_default_meta('course');
        $this->assertSame(12, $meta['event_capacity']);
    }

    public function test_webinar_default_capacity_is_500(): void
    {
        $meta = EventTypeRegistry::get_default_meta('webinar');
        $this->assertSame(500, $meta['event_capacity']);
    }

    // ============================================================
    // Unknown type falls back to defaults
    // ============================================================

    public function test_unknown_type_returns_defaults(): void
    {
        $config = EventTypeRegistry::get('nonexistent');
        $this->assertNotEmpty($config['workflow']);
        $this->assertSame(20, $config['default_meta']['event_capacity']);
    }
}

/**
 * EventTemplateService tests (unit — no DB).
 */
class EventTemplateServiceTest extends TestCase
{
    private EventTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
        $this->service = new EventTemplateService();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_service_instantiable(): void
    {
        $this->assertInstanceOf(EventTemplateService::class, $this->service);
    }

    public function test_components_includes_template_service(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(EventTemplateService::class, $components);
    }
}
