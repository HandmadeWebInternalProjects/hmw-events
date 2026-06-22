<?php

namespace HMWEvents\Tests\Unit\Services\Emails;

use HMWEvents\Services\Emails\EmailQueueRepository;
use Mockery;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Tests for EmailQueueRepository
 */
class EmailQueueRepositoryTest extends TestCase
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
      // Flatten nested arrays (wpdb::prepare accepts scalars or arrays)
      $flat = [];
      foreach ($args as $arg) {
        if (is_array($arg)) {
          foreach ($arg as $v) {
            $flat[] = $v;
          }
        } else {
          $flat[] = $arg;
        }
      }
      // Replace placeholders with quoted values for a usable test string
      $result = $query;
      foreach ($flat as $v) {
        $pos = strpos($result, '%s');
        if ($pos !== false) {
          $result = substr_replace($result, "'" . $v . "'", $pos, 2);
        } else {
          $pos = strpos($result, '%d');
          if ($pos !== false) {
            $result = substr_replace($result, (int) $v, $pos, 2);
          }
        }
      }
      return $result;
    });
    $wpdb->shouldReceive('esc_like')->andReturnArg(0);

    Functions\when('wp_parse_args')->alias(function($args, $defaults = []) {
      return array_merge((array) $defaults, (array) $args);
    });
    Functions\when('current_time')->justReturn('2025-01-15 10:00:00');
  }

  protected function tearDown(): void
  {
    Mockery::close();
    Monkey\tearDown();
    parent::tearDown();
  }

  public function test_get_pending_returns_array()
  {
    global $wpdb;
    
    $wpdb->shouldReceive('get_results')
      ->once()
      ->with(Mockery::pattern("/SELECT.*FROM.*email_queue.*status = 'pending'/is"))
      ->andReturn([
        (object)[
          'id' => 1,
          'recipient_email' => 'test@example.com',
          'subject' => 'Test Email',
          'body' => 'Test body',
          'status' => 'pending',
          'attempts' => 0,
          'created_at' => '2026-02-06 10:00:00',
        ]
      ]);

    $repo = new EmailQueueRepository();
    $result = $repo->get_pending(10);

    $this->assertIsArray($result);
    $this->assertCount(1, $result);
    $this->assertEquals('test@example.com', $result[0]->recipient_email);
    $this->assertEquals('pending', $result[0]->status);
  }

  public function test_add_inserts_email()
  {
    global $wpdb;
    
    $wpdb->shouldReceive('insert')
      ->once()
      ->with(
        'wp_email_queue',
        Mockery::on(function($data) {
          return $data['recipient_email'] === 'test@example.com'
            && $data['subject'] === 'Test Subject'
            && $data['html_body'] === 'Test Body'
            && $data['status'] === 'pending';
        }),
        Mockery::any()
      )
      ->andReturn(1);
    
    $wpdb->insert_id = 123;

    $repo = new EmailQueueRepository();
    $result = $repo->add([
      'recipient_email' => 'test@example.com',
      'subject' => 'Test Subject',
      'html_body' => 'Test Body',
    ]);

    $this->assertEquals(123, $result);
  }

  public function test_mark_sent_updates_status()
  {
    global $wpdb;
    
    $wpdb->shouldReceive('update')
      ->once()
      ->with(
        'wp_email_queue',
        Mockery::on(function($data) {
          return $data['status'] === 'sent';
        }),
        ['id' => 1],
        Mockery::any(),
        Mockery::any()
      )
      ->andReturn(1);

    $repo = new EmailQueueRepository();
    $result = $repo->mark_sent(1);

    $this->assertNotFalse($result);
  }

  public function test_mark_failed_updates_status()
  {
    global $wpdb;
    
    // get() is called internally to fetch current email state
    $wpdb->shouldReceive('get_row')
      ->andReturn((object)['id' => 1, 'attempts' => 2, 'max_attempts' => 5]);
    
    $wpdb->shouldReceive('update')
      ->once()
      ->with(
        'wp_email_queue',
        Mockery::on(function($data) {
          // new_attempts = 2+1=3, max_attempts=5, 3<5 → status='pending'
          return $data['status'] === 'pending'
            && $data['attempts'] === 3;
        }),
        ['id' => 1],
        Mockery::any(),
        Mockery::any()
      )
      ->andReturn(1);

    $repo = new EmailQueueRepository();
    $result = $repo->mark_failed(1, 'Test error message');

    $this->assertNotFalse($result);
  }

  public function test_get_filtered_with_status()
  {
    global $wpdb;
    
    $wpdb->shouldReceive('get_var')
      ->once()
      ->andReturn(2); // Count
    
    $wpdb->shouldReceive('get_results')
      ->once()
      ->with(Mockery::pattern("/WHERE.*status = 'sent'/is"))
      ->andReturn([
        (object)['id' => 1, 'status' => 'sent'],
        (object)['id' => 2, 'status' => 'sent'],
      ]);

    $repo = new EmailQueueRepository();
    $result = $repo->get_filtered(['status' => 'sent', 'per_page' => 20, 'page' => 1]);

    $this->assertIsArray($result);
    $this->assertArrayHasKey('emails', $result);
    $this->assertCount(2, $result['emails']);
  }

  public function test_get_filtered_with_recipient()
  {
    global $wpdb;
    
    $wpdb->shouldReceive('get_var')
      ->once()
      ->andReturn(1);
    
    $wpdb->shouldReceive('get_results')
      ->once()
      ->with(Mockery::pattern("/recipient_email LIKE '%test@example.com%'/is"))
      ->andReturn([
        (object)['id' => 1, 'recipient_email' => 'test@example.com'],
      ]);

    $repo = new EmailQueueRepository();
    $result = $repo->get_filtered(['recipient' => 'test@example.com', 'per_page' => 20, 'page' => 1]);

    $this->assertIsArray($result);
    $this->assertArrayHasKey('emails', $result);
    $this->assertCount(1, $result['emails']);
  }

  public function test_reset_for_retry_resets_status_to_pending()
  {
    global $wpdb;
    
    $wpdb->shouldReceive('update')
      ->once()
      ->with(
        'wp_email_queue',
        Mockery::on(function($data) {
          return $data['status'] === 'pending'
            && $data['attempts'] === 0;
        }),
        ['id' => 1],
        Mockery::any(),
        Mockery::any()
      )
      ->andReturn(1);

    $repo = new EmailQueueRepository();
    $result = $repo->reset_for_retry(1);

    $this->assertNotFalse($result);
  }
}

