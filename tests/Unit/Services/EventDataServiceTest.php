<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\EventDataService;
use HMWEvents\PostTypes\Event;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class EventDataServiceTest extends TestCase
{
    private EventDataService $service;

    private const EVENT_ID = 123;
    private const NON_EVENT_ID = 999;
    private const VENUE_ID = 77;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        $this->service = new EventDataService();

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stub_event_post(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            if ($id === EventDataServiceTest::EVENT_ID) {
                return new \WP_Post((object) [
                    'ID' => EventDataServiceTest::EVENT_ID,
                    'post_type' => Event::POST_TYPE,
                    'post_status' => 'publish',
                ]);
            }
            return null;
        });
    }

    private function stub_non_event_post(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            if ($id === EventDataServiceTest::NON_EVENT_ID) {
                return new \WP_Post((object) [
                    'ID' => EventDataServiceTest::NON_EVENT_ID,
                    'post_type' => 'post',
                    'post_status' => 'publish',
                ]);
            }
            return null;
        });
    }

    private function stub_event_with_venue_post(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            if ($id === EventDataServiceTest::EVENT_ID) {
                return new \WP_Post((object) [
                    'ID' => EventDataServiceTest::EVENT_ID,
                    'post_type' => Event::POST_TYPE,
                    'post_status' => 'publish',
                ]);
            }
            if ($id === EventDataServiceTest::VENUE_ID) {
                return new \WP_Post((object) [
                    'ID' => EventDataServiceTest::VENUE_ID,
                    'post_type' => \HMWEvents\PostTypes\EventLocation::POST_TYPE,
                    'post_status' => 'publish',
                    'post_title' => 'Test Venue',
                ]);
            }
            return null;
        });
    }

    private function stub_missing_post(): void
    {
        Functions\when('get_post')->justReturn(null);
    }

    private function stub_event_type_terms(array $slugs): void
    {
        Functions\when('wp_get_object_terms')->justReturn($slugs);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
    }

    private function stub_max_per_registrant_meta(
        string $value,
        ?array $override_config = null,
        ?array $field_config = null
    ): void {
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) use ($value, $override_config, $field_config) {
            if ($key === '_event_max_per_registrant') {
                return $value;
            }
            if ($key === '_event_template_override') {
                return $override_config;
            }
            if ($key === '_event_field_config') {
                return $field_config;
            }
            return '';
        });
    }

    // ---------------------------------------------------------------
    // is_event
    // ---------------------------------------------------------------

    public function test_is_event_returns_true_for_hmw_event()
    {
        $this->stub_event_post();
        $this->assertTrue($this->service->is_event(self::EVENT_ID));
    }

    public function test_is_event_returns_false_for_non_hmw_event()
    {
        $this->stub_non_event_post();
        $this->assertFalse($this->service->is_event(self::NON_EVENT_ID));
    }

    public function test_is_event_returns_false_for_missing_post()
    {
        $this->stub_missing_post();
        $this->assertFalse($this->service->is_event(99999));
    }

    // ---------------------------------------------------------------
    // Non-event rejection — nullable getters
    // ---------------------------------------------------------------

    public function test_get_price_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_price(self::NON_EVENT_ID));
    }

    public function test_get_deposit_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_deposit(self::NON_EVENT_ID));
    }

    public function test_get_capacity_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_capacity(self::NON_EVENT_ID));
    }

    public function test_get_start_date_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_start_date(self::NON_EVENT_ID));
    }

    public function test_get_end_date_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_end_date(self::NON_EVENT_ID));
    }

    public function test_get_is_free_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_is_free(self::NON_EVENT_ID));
    }

    public function test_get_allow_net_terms_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_allow_net_terms(self::NON_EVENT_ID));
    }

    public function test_get_organizer_id_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_organizer_id(self::NON_EVENT_ID));
    }

    public function test_get_venue_name_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_venue_name(self::NON_EVENT_ID));
    }

    public function test_get_venue_address_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_venue_address(self::NON_EVENT_ID));
    }

    public function test_get_webinar_url_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_webinar_url(self::NON_EVENT_ID));
    }

    public function test_get_booking_notes_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_booking_notes(self::NON_EVENT_ID));
    }

    public function test_get_notification_email_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_notification_email(self::NON_EVENT_ID));
    }

    public function test_get_status_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_status(self::NON_EVENT_ID));
    }

    public function test_get_details_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_details(self::NON_EVENT_ID));
    }

    // ---------------------------------------------------------------
    // Safe defaults for non-event — non-nullable getters
    // ---------------------------------------------------------------

    public function test_get_surcharge_returns_zero_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertSame(0.0, $this->service->get_surcharge(self::NON_EVENT_ID));
    }

    public function test_get_max_per_registrant_returns_zero_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertSame(0, $this->service->get_max_per_registrant(self::NON_EVENT_ID));
    }

    public function test_get_venue_address_string_returns_empty_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertSame('', $this->service->get_venue_address_string(self::NON_EVENT_ID));
    }

    // ---------------------------------------------------------------
    // Numeric normalization
    // ---------------------------------------------------------------

    public function test_get_price_normalizes_numeric_string()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_price') {
                return '150';
            }
            return '';
        });

        $this->assertSame(150.0, $this->service->get_price(self::EVENT_ID));
    }

    public function test_get_price_returns_zero_for_empty()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_price') {
                return '';
            }
            return '';
        });

        $this->assertSame(0.0, $this->service->get_price(self::EVENT_ID));
    }

    public function test_get_deposit_normalizes_numeric_string()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_deposit') {
                return '50.00';
            }
            return '';
        });

        $this->assertSame(50.0, $this->service->get_deposit(self::EVENT_ID));
    }

    public function test_get_deposit_returns_zero_for_empty()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_deposit') {
                return '';
            }
            return '';
        });

        $this->assertSame(0.0, $this->service->get_deposit(self::EVENT_ID));
    }

    public function test_get_surcharge_normalizes_numeric_string()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_surcharge') {
                return '5.50';
            }
            return '';
        });

        $this->assertSame(5.5, $this->service->get_surcharge(self::EVENT_ID));
    }

    public function test_get_surcharge_returns_zero_for_empty()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_surcharge') {
                return '';
            }
            return '';
        });

        $this->assertSame(0.0, $this->service->get_surcharge(self::EVENT_ID));
    }

    // ---------------------------------------------------------------
    // get_surcharge_type
    // ---------------------------------------------------------------

    public function test_get_surcharge_type_defaults_to_flat_when_meta_empty()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_surcharge_type') {
                return '';
            }
            return '';
        });

        $this->assertSame('flat', $this->service->get_surcharge_type(self::EVENT_ID));
    }

    public function test_get_surcharge_type_returns_percent_when_meta_is_percent()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_surcharge_type') {
                return 'percent';
            }
            return '';
        });

        $this->assertSame('percent', $this->service->get_surcharge_type(self::EVENT_ID));
    }

    public function test_get_surcharge_type_returns_flat_for_unknown_value()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_surcharge_type') {
                return 'bogus';
            }
            return '';
        });

        $this->assertSame('flat', $this->service->get_surcharge_type(self::EVENT_ID));
    }

    public function test_get_surcharge_type_returns_flat_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertSame('flat', $this->service->get_surcharge_type(self::NON_EVENT_ID));
    }

    // ---------------------------------------------------------------
    // calculate_surcharge
    // ---------------------------------------------------------------

    public function test_calculate_surcharge_returns_zero_when_surcharge_missing()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            return '';
        });

        $this->assertSame(0.0, $this->service->calculate_surcharge(self::EVENT_ID, 250.0));
    }

    public function test_calculate_surcharge_returns_flat_value_ignoring_base()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_surcharge') {
                return '5';
            }
            return '';
        });

        $this->assertSame(5.0, $this->service->calculate_surcharge(self::EVENT_ID, 250.0));
        $this->assertSame(5.0, $this->service->calculate_surcharge(self::EVENT_ID, 1000.0));
    }

    public function test_calculate_surcharge_percent_computes_percentage_of_base()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_surcharge') {
                return '2.0';
            }
            if ($key === '_event_surcharge_type') {
                return 'percent';
            }
            return '';
        });

        $this->assertSame(5.0, $this->service->calculate_surcharge(self::EVENT_ID, 250.0));
    }

    public function test_calculate_surcharge_percent_rounds_to_two_decimals()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_surcharge') {
                return '2.5';
            }
            if ($key === '_event_surcharge_type') {
                return 'percent';
            }
            return '';
        });

        $this->assertSame(2.5, $this->service->calculate_surcharge(self::EVENT_ID, 100.0));
    }

    public function test_calculate_surcharge_percent_rounds_sub_cent_result()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_surcharge') {
                return '3.0';
            }
            if ($key === '_event_surcharge_type') {
                return 'percent';
            }
            return '';
        });

        $this->assertSame(1.0, $this->service->calculate_surcharge(self::EVENT_ID, 33.33));
    }

    public function test_calculate_surcharge_returns_zero_for_zero_base()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_surcharge') {
                return '2.0';
            }
            if ($key === '_event_surcharge_type') {
                return 'percent';
            }
            return '';
        });

        $this->assertSame(0.0, $this->service->calculate_surcharge(self::EVENT_ID, 0.0));
    }

    public function test_get_capacity_normalizes_numeric_string()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_capacity') {
                return '25';
            }
            return '';
        });

        $this->assertSame(25, $this->service->get_capacity(self::EVENT_ID));
    }

    public function test_get_capacity_returns_zero_for_empty()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_capacity') {
                return '';
            }
            return '';
        });

        $this->assertSame(0, $this->service->get_capacity(self::EVENT_ID));
    }

    public function test_get_capacity_clamps_negative_to_zero()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_capacity') {
                return '-5';
            }
            return '';
        });

        $this->assertSame(0, $this->service->get_capacity(self::EVENT_ID));
    }

    public function test_get_max_per_registrant_normalizes_numeric_string()
    {
        $this->stub_event_post();
        $this->stub_event_type_terms([]);
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_max_per_registrant') {
                return '3';
            }
            return '';
        });

        $this->assertSame(3, $this->service->get_max_per_registrant(self::EVENT_ID));
    }

    public function test_get_max_per_registrant_returns_zero_for_empty()
    {
        $this->stub_event_post();
        $this->stub_event_type_terms([]);
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_max_per_registrant') {
                return '';
            }
            return '';
        });

        $this->assertSame(0, $this->service->get_max_per_registrant(self::EVENT_ID));
    }

    public function test_get_max_per_registrant_ignores_stored_value_when_event_type_hides_field()
    {
        $this->stub_event_post();
        $this->stub_event_type_terms(['parenting-webinar']);
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_max_per_registrant') {
                return '5';
            }
            return '';
        });

        $this->assertSame(0, $this->service->get_max_per_registrant(self::EVENT_ID));
    }

    public function test_get_max_per_registrant_returns_stored_value_when_event_type_is_visible()
    {
        $this->stub_event_post();
        $this->stub_event_type_terms(['parent-course']);
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_max_per_registrant') {
                return '5';
            }
            return '';
        });

        $this->assertSame(5, $this->service->get_max_per_registrant(self::EVENT_ID));
    }

    public function test_get_max_per_registrant_returns_stored_value_for_unknown_event_type()
    {
        $this->stub_event_post();
        $this->stub_event_type_terms(['some-unknown-type']);
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_max_per_registrant') {
                return '5';
            }
            return '';
        });

        $this->assertSame(5, $this->service->get_max_per_registrant(self::EVENT_ID));
    }

    // ---------------------------------------------------------------
    // get_max_per_registrant — template override / field config visibility
    // takes precedence over taxonomy; taxonomy is fallback only
    // ---------------------------------------------------------------

    public function test_get_max_per_registrant_override_visible_overrides_taxonomy_hidden()
    {
        $this->stub_event_post();
        $this->stub_event_type_terms(['parenting-webinar']);
        $this->stub_max_per_registrant_meta('5', ['event_fields' => ['hidden' => []]]);

        $this->assertSame(5, $this->service->get_max_per_registrant(self::EVENT_ID));
    }

    public function test_get_max_per_registrant_field_config_visible_overrides_taxonomy_hidden()
    {
        $this->stub_event_post();
        $this->stub_event_type_terms(['parenting-webinar']);
        $this->stub_max_per_registrant_meta('5', null, ['event_fields' => ['hidden' => []]]);

        $this->assertSame(5, $this->service->get_max_per_registrant(self::EVENT_ID));
    }

    public function test_get_max_per_registrant_override_hidden_overrides_taxonomy_visible()
    {
        $this->stub_event_post();
        $this->stub_event_type_terms(['parent-course']);
        $this->stub_max_per_registrant_meta('5', ['event_fields' => ['hidden' => ['event_max_per_registrant']]]);

        $this->assertSame(0, $this->service->get_max_per_registrant(self::EVENT_ID));
    }

    public function test_get_max_per_registrant_field_config_hidden_overrides_taxonomy_visible()
    {
        $this->stub_event_post();
        $this->stub_event_type_terms(['parent-course']);
        $this->stub_max_per_registrant_meta('5', null, ['event_fields' => ['hidden' => ['event_max_per_registrant']]]);

        $this->assertSame(0, $this->service->get_max_per_registrant(self::EVENT_ID));
    }

    public function test_get_max_per_registrant_override_visible_overrides_field_config_hidden_and_taxonomy_hidden()
    {
        $this->stub_event_post();
        $this->stub_event_type_terms(['parenting-webinar']);
        $this->stub_max_per_registrant_meta(
            '5',
            ['event_fields' => ['hidden' => []]],
            ['event_fields' => ['hidden' => ['event_max_per_registrant']]]
        );

        $this->assertSame(5, $this->service->get_max_per_registrant(self::EVENT_ID));
    }

    // ---------------------------------------------------------------
    // Organizer fallback
    // ---------------------------------------------------------------

    public function test_get_organizer_id_returns_integer_for_valid_value()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_organizer_id') {
                return '42';
            }
            return '';
        });

        $this->assertSame(42, $this->service->get_organizer_id(self::EVENT_ID));
    }

    public function test_get_organizer_id_returns_null_for_empty()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_organizer_id') {
                return '';
            }
            return '';
        });

        $this->assertNull($this->service->get_organizer_id(self::EVENT_ID));
    }

    public function test_get_organizer_id_returns_null_for_null_meta()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_organizer_id') {
                return null;
            }
            return '';
        });

        $this->assertNull($this->service->get_organizer_id(self::EVENT_ID));
    }

    // ---------------------------------------------------------------
    // Date normalization
    // ---------------------------------------------------------------

    public function test_get_start_date_returns_string_for_valid_date()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_start_date') {
                return '2026-07-31 09:00:00';
            }
            return '';
        });

        $this->assertSame('2026-07-31 09:00:00', $this->service->get_start_date(self::EVENT_ID));
    }

    public function test_get_start_date_returns_null_for_empty()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_start_date') {
                return '';
            }
            return '';
        });

        $this->assertNull($this->service->get_start_date(self::EVENT_ID));
    }

    public function test_get_end_date_returns_string_for_valid_date()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_end_date') {
                return '2026-07-31 17:00:00';
            }
            return '';
        });

        $this->assertSame('2026-07-31 17:00:00', $this->service->get_end_date(self::EVENT_ID));
    }

    public function test_get_end_date_returns_null_for_empty()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_end_date') {
                return '';
            }
            return '';
        });

        $this->assertNull($this->service->get_end_date(self::EVENT_ID));
    }

    // ---------------------------------------------------------------
    // Venue (relational event_location)
    // ---------------------------------------------------------------

    public function test_get_venue_id_returns_venue_post_id()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_venue') {
                return EventDataServiceTest::VENUE_ID;
            }
            return '';
        });

        $this->assertSame(self::VENUE_ID, $this->service->get_venue_id(self::EVENT_ID));
    }

    public function test_get_venue_id_returns_null_when_not_set()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->justReturn('');

        $this->assertNull($this->service->get_venue_id(self::EVENT_ID));
    }

    public function test_get_venue_name_resolves_venue_post_title()
    {
        $this->stub_event_with_venue_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_venue') {
                return EventDataServiceTest::VENUE_ID;
            }
            return '';
        });

        $this->assertSame('Test Venue', $this->service->get_venue_name(self::EVENT_ID));
    }

    public function test_get_venue_name_falls_back_to_legacy_meta()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_venue_name') {
                return 'Legacy Venue';
            }
            return '';
        });

        $this->assertSame('Legacy Venue', $this->service->get_venue_name(self::EVENT_ID));
    }

    public function test_get_venue_address_string_resolves_from_venue_post()
    {
        $this->stub_event_with_venue_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($post_id === EventDataServiceTest::VENUE_ID && $key === '_venue_address') {
                return ['address' => '123 Main St, Sydney'];
            }
            if ($key === '_event_venue') {
                return EventDataServiceTest::VENUE_ID;
            }
            return '';
        });

        $this->assertSame('123 Main St, Sydney', $this->service->get_venue_address_string(self::EVENT_ID));
    }

    public function test_get_venue_address_string_falls_back_to_legacy_meta()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_venue_address') {
                return ['address' => 'Legacy Address'];
            }
            return '';
        });

        $this->assertSame('Legacy Address', $this->service->get_venue_address_string(self::EVENT_ID));
    }

    public function test_get_venue_address_string_returns_empty_for_event_with_no_address()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            return '';
        });

        $this->assertSame('', $this->service->get_venue_address_string(self::EVENT_ID));
    }

    public function test_get_venue_address_resolves_from_venue_post()
    {
        $this->stub_event_with_venue_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($post_id === EventDataServiceTest::VENUE_ID && $key === '_venue_address') {
                return ['address' => '123 Main St', 'lat' => '12.34', 'lng' => '56.78'];
            }
            if ($key === '_event_venue') {
                return EventDataServiceTest::VENUE_ID;
            }
            return '';
        });

        $result = $this->service->get_venue_address(self::EVENT_ID);
        $this->assertIsArray($result);
        $this->assertSame('123 Main St', $result['address']);
    }

    public function test_get_venue_address_returns_null_when_no_venue()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->justReturn('');

        $this->assertNull($this->service->get_venue_address(self::EVENT_ID));
    }

    // ---------------------------------------------------------------
    // Boolean normalization
    // ---------------------------------------------------------------

    public function test_get_is_free_returns_true_for_on_value()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_is_free') {
                return '1';
            }
            return '';
        });

        $this->assertTrue($this->service->get_is_free(self::EVENT_ID));
    }

    public function test_get_is_free_returns_false_for_off_value()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_is_free') {
                return '0';
            }
            return '';
        });

        $this->assertFalse($this->service->get_is_free(self::EVENT_ID));
    }

    public function test_get_allow_net_terms_returns_true_for_on_value()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_allow_net_terms') {
                return '1';
            }
            return '';
        });

        $this->assertTrue($this->service->get_allow_net_terms(self::EVENT_ID));
    }

    public function test_get_allow_net_terms_returns_false_for_off_value()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_allow_net_terms') {
                return '';
            }
            return '';
        });

        $this->assertFalse($this->service->get_allow_net_terms(self::EVENT_ID));
    }

    // ---------------------------------------------------------------
    // bookings_enabled
    // ---------------------------------------------------------------

    public function test_bookings_enabled_defaults_to_true_when_meta_never_saved()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            return '';
        });

        $this->assertTrue($this->service->bookings_enabled(self::EVENT_ID));
    }

    public function test_bookings_enabled_returns_true_for_on_value()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_enable_bookings') {
                return '1';
            }
            return '';
        });

        $this->assertTrue($this->service->bookings_enabled(self::EVENT_ID));
    }

    public function test_bookings_enabled_returns_false_for_off_value()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_enable_bookings') {
                return '0';
            }
            return '';
        });

        $this->assertFalse($this->service->bookings_enabled(self::EVENT_ID));
    }

    public function test_bookings_enabled_returns_false_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertFalse($this->service->bookings_enabled(self::NON_EVENT_ID));
    }

    // ---------------------------------------------------------------
    // get_currency
    // ---------------------------------------------------------------

    public function test_get_currency_returns_meta_value()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_currency') {
                return 'USD';
            }
            return '';
        });

        $this->assertSame('USD', $this->service->get_currency(self::EVENT_ID));
    }

    public function test_get_currency_defaults_to_aud_for_empty()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            return '';
        });

        $this->assertSame('AUD', $this->service->get_currency(self::EVENT_ID));
    }

    public function test_get_currency_returns_aud_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertSame('AUD', $this->service->get_currency(self::NON_EVENT_ID));
    }

    // ---------------------------------------------------------------
    // get_booking_cutoff
    // ---------------------------------------------------------------

    public function test_get_booking_cutoff_returns_value()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_booking_cutoff') {
                return '2026-08-15';
            }
            return '';
        });

        $this->assertSame('2026-08-15', $this->service->get_booking_cutoff(self::EVENT_ID));
    }

    public function test_get_booking_cutoff_returns_null_for_empty()
    {
        $this->stub_event_post();
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            return '';
        });

        $this->assertNull($this->service->get_booking_cutoff(self::EVENT_ID));
    }

    public function test_get_booking_cutoff_returns_null_for_non_event()
    {
        $this->stub_non_event_post();
        $this->assertNull($this->service->get_booking_cutoff(self::NON_EVENT_ID));
    }

    // ---------------------------------------------------------------
    // get_status
    // ---------------------------------------------------------------

    public function test_get_status_returns_post_status()
    {
        $this->stub_event_post();
        $this->assertSame('publish', $this->service->get_status(self::EVENT_ID));
    }

    // ---------------------------------------------------------------
    // get_details
    // ---------------------------------------------------------------

    public function test_get_details_returns_full_array_for_event()
    {
        $this->stub_event_with_venue_post();
        $this->stub_event_type_terms([]);
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($post_id === EventDataServiceTest::VENUE_ID && $key === '_venue_address') {
                return ['address' => '123 Main St'];
            }
            $meta = [
                '_event_start_date'        => '2026-07-31 09:00:00',
                '_event_end_date'          => '2026-07-31 17:00:00',
                '_event_price'             => '150',
                '_event_deposit'           => '50',
                '_event_surcharge'         => '5.00',
                '_event_currency'          => 'USD',
                '_event_booking_cutoff'    => '2026-08-15',
                '_event_capacity'          => '25',
                '_event_max_per_registrant' => '3',
                '_event_is_free'           => '0',
                '_event_enable_bookings'   => '1',
                '_event_allow_net_terms'   => '1',
                '_organizer_id'            => '42',
                '_event_venue'             => EventDataServiceTest::VENUE_ID,
                '_event_webinar_url'       => 'https://example.com',
                '_event_booking_notes'     => 'Bring water',
                '_event_notification_email' => 'org@test.com',
            ];
            return $meta[$key] ?? '';
        });

        $details = $this->service->get_details(self::EVENT_ID);

        $this->assertIsArray($details);
        $this->assertSame(self::EVENT_ID, $details['id']);
        $this->assertSame('2026-07-31 09:00:00', $details['start_date']);
        $this->assertSame('2026-07-31 17:00:00', $details['end_date']);
        $this->assertSame(150.0, $details['price']);
        $this->assertSame(50.0, $details['deposit']);
        $this->assertSame(5.0, $details['surcharge']);
        $this->assertSame('flat', $details['surcharge_type']);
        $this->assertSame('USD', $details['currency']);
        $this->assertSame('2026-08-15', $details['booking_cutoff']);
        $this->assertSame(25, $details['capacity']);
        $this->assertSame(3, $details['max_per_registrant']);
        $this->assertFalse($details['is_free']);
        $this->assertTrue($details['bookings_enabled']);
        $this->assertTrue($details['allow_net_terms']);
        $this->assertSame(42, $details['organizer_id']);
        $this->assertSame(self::VENUE_ID, $details['venue_id']);
        $this->assertSame('Test Venue', $details['venue_name']);
        $this->assertIsArray($details['venue_address']);
        $this->assertSame('123 Main St', $details['venue_address_string']);
        $this->assertSame('https://example.com', $details['webinar_url']);
        $this->assertSame('Bring water', $details['booking_notes']);
        $this->assertSame('org@test.com', $details['notification_email']);
        $this->assertSame('publish', $details['status']);
    }
}
