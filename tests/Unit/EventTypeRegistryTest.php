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
            'parenting-webinar',
            'professional-webinar',
            'parent-one-off-free',
            'parent-walk-in',
            'parent-course',
            'professional-online',
            'professional-in-person',
        ];

        foreach ($expected as $slug) {
            $this->assertArrayHasKey($slug, $all, "Missing archetype: {$slug}");
        }
    }

    // ============================================================
    // Field visibility
    // ============================================================

    public function test_parenting_webinar_hides_venue_fields(): void
    {
        $config = EventTypeRegistry::get('parenting-webinar');
        $this->assertContains('event_venue', $config['hidden_fields']);
        $this->assertContains('event_surcharge', $config['hidden_fields']);
        $this->assertNotContains('event_webinar_url', $config['hidden_fields']);
    }

    public function test_is_field_visible_for_parenting_webinar(): void
    {
        $this->assertFalse(EventTypeRegistry::is_field_visible('parenting-webinar', 'event_venue'));
        $this->assertTrue(EventTypeRegistry::is_field_visible('parenting-webinar', 'event_start_date'));
    }

    public function test_parent_one_off_free_does_not_hide_venue(): void
    {
        $this->assertTrue(EventTypeRegistry::is_field_visible('parent-one-off-free', 'event_venue'));
    }

    // ============================================================
    // Surcharge visibility
    // ============================================================

    public function test_professional_webinar_hides_surcharge(): void
    {
        $config = EventTypeRegistry::get('professional-webinar');
        $this->assertContains('event_surcharge', $config['hidden_fields']);
    }

    public function test_parent_one_off_free_hides_surcharge(): void
    {
        $config = EventTypeRegistry::get('parent-one-off-free');
        $this->assertContains('event_surcharge', $config['hidden_fields']);
    }

    public function test_parent_one_off_free_allows_max_per_registrant(): void
    {
        $config = EventTypeRegistry::get('parent-one-off-free');
        $this->assertNotContains('event_max_per_registrant', $config['hidden_fields']);
    }

    public function test_parent_walk_in_hides_surcharge(): void
    {
        $config = EventTypeRegistry::get('parent-walk-in');
        $this->assertContains('event_surcharge', $config['hidden_fields']);
    }

    public function test_surcharge_is_visible_on_paid_event_types(): void
    {
        $config = EventTypeRegistry::get('professional-in-person');
        $this->assertNotContains('event_surcharge', $config['hidden_fields']);
    }

    public function test_surcharge_is_visible_on_parent_course(): void
    {
        $config = EventTypeRegistry::get('parent-course');
        $this->assertNotContains('event_surcharge', $config['hidden_fields']);
    }

    // ============================================================
    // Required fields
    // ============================================================

    public function test_is_field_required(): void
    {
        $this->assertTrue(EventTypeRegistry::is_field_required('parenting-webinar', 'event_start_date'));
        $this->assertTrue(EventTypeRegistry::is_field_required('parenting-webinar', 'event_webinar_url'));
        $this->assertFalse(EventTypeRegistry::is_field_required('parenting-webinar', 'event_venue'));
    }

    public function test_professional_in_person_requires_venue(): void
    {
        $this->assertTrue(EventTypeRegistry::is_field_required('professional-in-person', 'event_venue'));
    }

    // ============================================================
    // Workflow
    // ============================================================

    public function test_workflow_has_sensible_transitions(): void
    {
        $workflow = EventTypeRegistry::get_workflow('parent-course');

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

    public function test_parent_course_has_parent_and_couple_presets(): void
    {
        $presets = EventTypeRegistry::get_attendance_option_presets('parent-course');
        $types = array_column($presets, 'option_type');
        $this->assertContains('couple', $types);
        $this->assertContains('parent', $types);
        $this->assertContains('parent_child', $types);
    }

    public function test_parent_course_has_parent_preset(): void
    {
        $presets = EventTypeRegistry::get_attendance_option_presets('parent-course');
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
            EventTypeRegistry::get_comm_template('parent-course', 'booking_confirmed')
        );
    }

    public function test_default_comm_template_falls_back_to_standard(): void
    {
        $this->assertSame(
            'booking_confirmed',
            EventTypeRegistry::get_comm_template('nonexistent-type', 'booking_confirmed')
        );
    }

    // ============================================================
    // Default meta
    // ============================================================

    public function test_parent_course_default_capacity_is_12(): void
    {
        $meta = EventTypeRegistry::get_default_meta('parent-course');
        $this->assertSame(12, $meta['event_capacity']);
    }

    public function test_parenting_webinar_default_capacity_is_500(): void
    {
        $meta = EventTypeRegistry::get_default_meta('parenting-webinar');
        $this->assertSame(500, $meta['event_capacity']);
    }

    // ============================================================
    // External registration
    // ============================================================

    public function test_parenting_webinar_has_external_registration(): void
    {
        $this->assertTrue(EventTypeRegistry::is_external_registration('parenting-webinar'));
    }

    public function test_professional_webinar_has_external_registration(): void
    {
        $this->assertTrue(EventTypeRegistry::is_external_registration('professional-webinar'));
    }

    public function test_parent_course_does_not_have_external_registration(): void
    {
        $this->assertFalse(EventTypeRegistry::is_external_registration('parent-course'));
    }

    // ============================================================
    // Registration disabled
    // ============================================================

    public function test_walk_in_has_registration_disabled(): void
    {
        $this->assertTrue(EventTypeRegistry::is_registration_disabled('parent-walk-in'));
    }

    public function test_parent_course_does_not_have_registration_disabled(): void
    {
        $this->assertFalse(EventTypeRegistry::is_registration_disabled('parent-course'));
    }

    // ============================================================
    // Hidden fields map
    // ============================================================

    public function test_get_hidden_fields_map_returns_all_types(): void
    {
        $map = EventTypeRegistry::get_hidden_fields_map();
        $this->assertCount(7, $map);
        $this->assertArrayHasKey('parenting-webinar', $map);
        $this->assertArrayHasKey('parent-walk-in', $map);
    }

    public function test_get_all_hideable_fields_returns_unique(): void
    {
        $fields = EventTypeRegistry::get_all_hideable_fields();
        $this->assertNotEmpty($fields);
        $this->assertSame($fields, array_values(array_unique($fields)));
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
