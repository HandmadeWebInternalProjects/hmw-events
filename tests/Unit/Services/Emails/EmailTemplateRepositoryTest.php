<?php

namespace HMWEvents\Tests\Unit\Services\Emails;

use HMWEvents\Services\Emails\EmailTemplateRepository;
use Mockery;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

/**
 * Tests for EmailTemplateRepository
 */
class EmailTemplateRepositoryTest extends TestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    Monkey\setUp();
    
    // Mock WordPress globals
    global $wpdb;
    $wpdb = Mockery::mock('wpdb');
    $wpdb->prefix = 'wp_';
    $wpdb->shouldReceive('prepare')->andReturnUsing(function($query, ...$args) {
      return vsprintf(str_replace('%s', "'%s'", str_replace('%d', '%d', $query)), $args);
    });
  }

  protected function tearDown(): void
  {
    Mockery::close();
    Monkey\tearDown();
    parent::tearDown();
  }

  public function test_get_system_templates_returns_array()
  {
    global $wpdb;
    
    $wpdb->shouldReceive('get_results')
      ->once()
      ->with(Mockery::pattern("/WHERE organizer_id IS NULL/i"))
      ->andReturn([
        (object)[
          'id' => 1,
          'template_key' => 'booking_confirmation',
          'subject' => 'Booking Confirmed',
          'body' => 'Your booking is confirmed',
          'is_active' => 1,
          'organizer_id' => null,
        ]
      ]);

    $repo = new EmailTemplateRepository();
    $result = $repo->get_system_templates();

    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertEquals('booking_confirmation', $result[0]->template_key);
  }

  public function test_get_educator_templates_filters_by_educator()
  {
    global $wpdb;
    
    $wpdb->shouldReceive('get_results')
      ->once()
      ->with(Mockery::pattern("/WHERE organizer_id = 45/i"))
      ->andReturn([
        (object)[
          'id' => 2,
          'template_key' => 'booking_confirmation',
          'subject' => 'Custom Booking Confirmed',
          'body' => 'Custom message',
          'is_active' => 1,
          'organizer_id' => 45,
        ]
      ]);

    $repo = new EmailTemplateRepository();
    $result = $repo->get_educator_templates(45);

    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertEquals(45, $result[0]->organizer_id);
  }

  public function test_get_template_falls_back_to_system()
  {
    global $wpdb;
    
    // First query for educator template (returns nothing)
    $wpdb->shouldReceive('get_row')
      ->once()
      ->with(Mockery::pattern("/WHERE organizer_id = 45.*template_key = 'booking_confirmation'/is"))
      ->andReturn(null);
    
    // Second query for system template
    $wpdb->shouldReceive('get_row')
      ->once()
      ->with(Mockery::pattern("/WHERE organizer_id IS NULL.*template_key = 'booking_confirmation'/is"))
      ->andReturn((object)[
        'id' => 1,
        'template_key' => 'booking_confirmation',
        'subject' => 'System Template',
        'body' => 'System body',
        'organizer_id' => null,
      ]);

    $repo = new EmailTemplateRepository();
    $result = $repo->get_template(45, 'booking_confirmation');

    $this->assertIsObject($result);
    $this->assertEquals('System Template', $result->subject);
    $this->assertNull($result->organizer_id);
  }

  public function test_save_creates_new_educator_template()
  {
    global $wpdb;
    
    // Check if exists (returns null = doesn't exist)
    $wpdb->shouldReceive('get_row')
      ->once()
      ->with(Mockery::pattern("/WHERE organizer_id = 45/i"))
      ->andReturn(null);
    
    // Insert new template
    $wpdb->shouldReceive('insert')
      ->once()
      ->with(
        'wp_hmwevents_email_templates',
        Mockery::on(function($data) {
          return $data['template_key'] === 'booking_confirmation'
            && $data['organizer_id'] === 45
            && $data['subject'] === 'Custom Subject';
        }),
        Mockery::any()
      )
      ->andReturn(1);
    
    $wpdb->insert_id = 123;

    $repo = new EmailTemplateRepository();
    $result = $repo->save([
      'template_key' => 'booking_confirmation',
      'educator_id' => 45,
      'subject' => 'Custom Subject',
      'body' => 'Custom Body',
      'is_active' => 1,
    ]);

    $this->assertEquals(123, $result);
  }

  public function test_save_updates_existing_educator_template()
  {
    global $wpdb;
    
    // Check if exists (returns existing)
    $wpdb->shouldReceive('get_row')
      ->once()
      ->with(Mockery::pattern("/WHERE organizer_id = 45/is"))
      ->andReturn((object)[
        'id' => 99,
        'template_key' => 'booking_confirmation',
        'organizer_id' => 45,
        'variables' => '[]',
        'version' => 0,
      ]);
    
    // Update existing template
    $wpdb->shouldReceive('update')
      ->once()
      ->with(
        'wp_hmwevents_email_templates',
        Mockery::on(function($data) {
          return $data['subject'] === 'Updated Subject';
        }),
        ['id' => 99],
        Mockery::any(),
        Mockery::any()
      )
      ->andReturn(1);

    $repo = new EmailTemplateRepository();
    $result = $repo->save([
      'template_key' => 'booking_confirmation',
      'educator_id' => 45,
      'subject' => 'Updated Subject',
      'body' => 'Updated Body',
      'is_active' => 1,
    ]);

    $this->assertEquals(99, $result);
  }

  public function test_save_system_template_doesnt_require_educator_id()
  {
    global $wpdb;
    
    // Check if exists (returns null)
    $wpdb->shouldReceive('get_row')
      ->once()
      ->with(Mockery::pattern("/WHERE organizer_id IS NULL/is"))
      ->andReturn(null);
    
    // Insert new system template
    $wpdb->shouldReceive('insert')
      ->once()
      ->with(
        'wp_hmwevents_email_templates',
        Mockery::on(function($data) {
          return $data['template_key'] === 'new_template'
            && array_key_exists('organizer_id', $data)
            && $data['organizer_id'] === null;
        }),
        Mockery::any()
      )
      ->andReturn(1);
    
    $wpdb->insert_id = 456;

    $repo = new EmailTemplateRepository();
    $result = $repo->save([
      'template_key' => 'new_template',
      'subject' => 'System Template',
      'body' => 'System Body',
      'is_active' => 1,
    ]);

    $this->assertEquals(456, $result);
  }

  public function test_delete_returns_boolean()
  {
    global $wpdb;
    
    $wpdb->shouldReceive('delete')
      ->once()
      ->with(
        'wp_hmwevents_email_templates',
        ['id' => 1],
        ['%d']
      )
      ->andReturn(1);

    $repo = new EmailTemplateRepository();
    $result = $repo->delete(1);

    $this->assertEquals(1, $result); // delete() returns affected rows, not boolean
  }
}
