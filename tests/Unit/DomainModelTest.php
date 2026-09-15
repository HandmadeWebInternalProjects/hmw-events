<?php
/**
 * Phase 2 Domain Model tests — CPTs, taxonomies, statuses, roles.
 *
 * @package HMWEvents\Tests\Unit
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

class DomainModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // CPT slug constants
    // ============================================================

    public function test_event_post_type_constant(): void
    {
        $this->assertSame('hmw_event', \HMWEvents\PostTypes\Event::POST_TYPE);
    }

    public function test_registrant_post_type_constant(): void
    {
        $this->assertSame('hmw_registrant', \HMWEvents\PostTypes\Registrant::POST_TYPE);
    }

    public function test_coupon_post_type_constant(): void
    {
        $this->assertSame('hmw_coupon', \HMWEvents\PostTypes\Coupon::POST_TYPE);
    }

    // ============================================================
    // Taxonomy slug constants
    // ============================================================

    public function test_event_type_taxonomy_constant(): void
    {
        $this->assertSame('hmw_event_type', \HMWEvents\Taxonomies\EventType::TAXONOMY);
    }

    public function test_event_audience_taxonomy_constant(): void
    {
        $this->assertSame('hmw_event_audience', \HMWEvents\Taxonomies\EventAudience::TAXONOMY);
    }

    public function test_event_delivery_mode_taxonomy_constant(): void
    {
        $this->assertSame('hmw_event_delivery_mode', \HMWEvents\Taxonomies\EventDeliveryMode::TAXONOMY);
    }

    // ============================================================
    // Role constant
    // ============================================================

    public function test_event_organizer_role_constant(): void
    {
        $this->assertSame('event_organizer', \HMWEvents\Roles\EventOrganizerRole::ROLE);
    }

    // ============================================================
    // No legacy slugs in new constants
    // ============================================================

    public function test_no_legacy_educator_in_new_post_types(): void
    {
        $cpts = [
            \HMWEvents\PostTypes\Event::POST_TYPE,
            \HMWEvents\PostTypes\Registrant::POST_TYPE,
            \HMWEvents\PostTypes\Coupon::POST_TYPE,
        ];

        foreach ($cpts as $cpt) {
            $this->assertStringNotContainsString('educator', $cpt);
            $this->assertStringNotContainsString('customer', $cpt);
            $this->assertStringNotContainsString('edu_', $cpt);
        }
    }

    public function test_no_legacy_course_in_new_taxonomies(): void
    {
        $taxonomies = [
            \HMWEvents\Taxonomies\EventType::TAXONOMY,
            \HMWEvents\Taxonomies\EventAudience::TAXONOMY,
            \HMWEvents\Taxonomies\EventDeliveryMode::TAXONOMY,
        ];

        foreach ($taxonomies as $tax) {
            $this->assertStringNotContainsString('course', $tax);
        }
    }

    public function test_no_legacy_educator_in_new_role(): void
    {
        $this->assertStringNotContainsString('educator', \HMWEvents\Roles\EventOrganizerRole::ROLE);
    }

    // ============================================================
    // Event class instantiation
    // ============================================================

    public function test_event_class_instantiable(): void
    {
        $event = new \HMWEvents\PostTypes\Event();
        $this->assertInstanceOf(\HMWEvents\PostTypes\Event::class, $event);
    }

    public function test_registrant_class_instantiable(): void
    {
        $registrant = new \HMWEvents\PostTypes\Registrant();
        $this->assertInstanceOf(\HMWEvents\PostTypes\Registrant::class, $registrant);
    }

    public function test_coupon_class_instantiable(): void
    {
        $coupon = new \HMWEvents\PostTypes\Coupon();
        $this->assertInstanceOf(\HMWEvents\PostTypes\Coupon::class, $coupon);
    }

    public function test_event_organizer_role_instantiable(): void
    {
        $role = new \HMWEvents\Roles\EventOrganizerRole();
        $this->assertInstanceOf(\HMWEvents\Roles\EventOrganizerRole::class, $role);
    }

    // ============================================================
    // Components list checks
    // ============================================================

    public function test_components_include_new_post_types(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();

        $this->assertContains(\HMWEvents\PostTypes\Event::class, $components);
        $this->assertContains(\HMWEvents\PostTypes\Registrant::class, $components);
        $this->assertContains(\HMWEvents\PostTypes\Coupon::class, $components);
    }

    public function test_components_include_new_taxonomies(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();

        $this->assertContains(\HMWEvents\Taxonomies\EventType::class, $components);
        $this->assertContains(\HMWEvents\Taxonomies\EventAudience::class, $components);
        $this->assertContains(\HMWEvents\Taxonomies\EventDeliveryMode::class, $components);
    }

    public function test_components_include_taxonomy_registrar(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();

        $this->assertContains(\HMWEvents\Services\TaxonomyRegistrar::class, $components);
    }

    public function test_components_exclude_legacy_topic_taxonomy_classes(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $component_strings = array_map(function ($class) {
            return is_string($class) ? $class : '';
        }, $components);

        $legacy_strings = [
            'HMWEvents\\Taxonomies\\ParentingTopic',
            'HMWEvents\\Taxonomies\\ProfessionalTopic',
            'HMWEvents\\Taxonomies\\Program',
        ];

        foreach ($legacy_strings as $legacy) {
            $this->assertNotContains($legacy, $component_strings, "Legacy class {$legacy} should not be in components");
        }
    }

    public function test_components_include_event_organizer_role(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();

        $this->assertContains(\HMWEvents\Roles\EventOrganizerRole::class, $components);
    }

    public function test_components_exclude_legacy_class_names(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $component_strings = array_map(function ($class) {
            return is_string($class) ? $class : '';
        }, $components);

        $legacy_strings = [
            'HMWEvents\\PostTypes\\EducatorCourse',
            'HMWEvents\\PostTypes\\Customer',
            'HMWEvents\\Taxonomies\\CourseType',
            'HMWEvents\\Taxonomies\\CourseState',
            'HMWEvents\\Roles\\EducatorRole',
            'HMWEvents\\Roles\\HospitalRole',
            'HMWEvents\\Meta\\CourseMeta',
            'HMWEvents\\Meta\\CustomerMeta',
            'HMWEvents\\Meta\\EducatorMeta',
        ];

        foreach ($legacy_strings as $legacy) {
            $this->assertNotContains($legacy, $component_strings, "Legacy class {$legacy} should not be in components");
        }
    }
}
