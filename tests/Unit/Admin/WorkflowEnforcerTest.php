<?php

namespace HMWEvents\Tests\Unit\Admin;

use HMWEvents\Admin\WorkflowEnforcer;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class WorkflowEnforcerTest extends TestCase
{
    private WorkflowEnforcer $enforcer;

    private array $wpUpdatePostCalls = [];

    private array $errorLogCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->wpUpdatePostCalls = [];
        $this->errorLogCalls     = [];

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        if (!defined('HMWEvents_PLUGIN_NAME')) {
            define('HMWEvents_PLUGIN_NAME', 'hmw-events');
        }

        if (!defined('HMWEvents_VERSION')) {
            define('HMWEvents_VERSION', '1.0.0');
        }

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('add_action')->justReturn(true);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('get_post_statuses')->justReturn([
            'draft'         => 'Draft',
            'publish'       => 'Published',
            'fully_booked'  => 'Fully Booked',
            'cancelled'     => 'Cancelled',
            'archived'      => 'Archived',
            'trash'         => 'Trash',
        ]);

        Functions\when('wp_get_post_terms')->justReturn([]);

        Functions\when('wp_update_post')->alias(function ($data) {
            $this->wpUpdatePostCalls[] = $data;
            return $data['ID'] ?? 0;
        });

        Functions\when('error_log')->alias(function ($message) {
            $this->errorLogCalls[] = $message;
            return true;
        });

        $this->enforcer = new WorkflowEnforcer();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function create_event_post(string $post_type, string $post_status, int $post_parent = 0): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = 123;
        $post->post_type = $post_type;
        $post->post_status = $post_status;
        $post->post_parent = $post_parent;
        return $post;
    }

    private function set_event_type_term(string $slug): void
    {
        $term = new \stdClass();
        $term->slug = $slug;
        Functions\when('wp_get_post_terms')->justReturn([$term]);
    }

    private function assertWorkflowBlocked(string $expectedOldStatus): void
    {
        $this->assertCount(1, $this->wpUpdatePostCalls, 'Expected wp_update_post to be called once.');
        $this->assertEquals(
            $expectedOldStatus,
            $this->wpUpdatePostCalls[0]['post_status'] ?? '',
            'Expected wp_update_post to revert to old status.'
        );
        $this->assertEquals(123, $this->wpUpdatePostCalls[0]['ID'] ?? 0);
    }

    private function assertWorkflowAllowed(): void
    {
        $this->assertCount(0, $this->wpUpdatePostCalls, 'Expected wp_update_post NOT to be called.');
    }

    // ============================================================
    // Valid transitions
    // ============================================================

    public function test_valid_transition_draft_to_publish_is_allowed(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('publish', 'draft', $this->create_event_post('hmw_event', 'draft'));
        $this->assertWorkflowAllowed();
    }

    public function test_valid_transition_publish_to_fully_booked_is_allowed(): void
    {
        $this->set_event_type_term('professional-online');
        $this->enforcer->enforce_workflow('fully_booked', 'publish', $this->create_event_post('hmw_event', 'publish'));
        $this->assertWorkflowAllowed();
    }

    public function test_valid_transition_cancelled_to_publish_is_allowed(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('publish', 'cancelled', $this->create_event_post('hmw_event', 'cancelled'));
        $this->assertWorkflowAllowed();
    }

    // ============================================================
    // WordPress core 'new' sentinel (previous status of fresh inserts)
    // ============================================================

    public function test_fresh_insert_new_to_auto_draft_is_allowed(): void
    {
        Functions\when('wp_get_post_terms')->justReturn([]);
        $this->enforcer->enforce_workflow('auto-draft', 'new', $this->create_event_post('hmw_event', 'new'));
        $this->assertWorkflowAllowed();
        $this->assertCount(0, $this->errorLogCalls);
    }

    public function test_parent_event_new_to_publish_is_allowed_when_taxonomy_missing(): void
    {
        Functions\when('wp_get_post_terms')->justReturn([]);
        $this->enforcer->enforce_workflow('publish', 'new', $this->create_event_post('hmw_event', 'publish'));
        $this->assertWorkflowAllowed();
        $this->assertCount(0, $this->errorLogCalls);
    }

    public function test_child_session_new_to_publish_is_allowed_when_taxonomy_missing(): void
    {
        Functions\when('wp_get_post_terms')->justReturn([]);
        $this->enforcer->enforce_workflow('publish', 'new', $this->create_event_post('hmw_event', 'publish', 99));
        $this->assertWorkflowAllowed();
        $this->assertCount(0, $this->errorLogCalls);
    }

    public function test_child_session_new_to_publish_is_allowed_when_taxonomy_present(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('publish', 'new', $this->create_event_post('hmw_event', 'publish', 99));
        $this->assertWorkflowAllowed();
        $this->assertCount(0, $this->errorLogCalls);
    }

    public function test_auto_draft_to_draft_is_allowed(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('draft', 'auto-draft', $this->create_event_post('hmw_event', 'auto-draft'));
        $this->assertWorkflowAllowed();
        $this->assertCount(0, $this->errorLogCalls);
    }

    public function test_future_to_publish_is_allowed(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('publish', 'future', $this->create_event_post('hmw_event', 'future'));
        $this->assertWorkflowAllowed();
        $this->assertCount(0, $this->errorLogCalls);
    }

    public function test_child_session_later_transition_is_still_enforced(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('draft', 'archived', $this->create_event_post('hmw_event', 'archived', 99));
        $this->assertWorkflowBlocked('archived');
    }

    // ============================================================
    // Invalid transitions
    // ============================================================

    public function test_invalid_transition_archived_to_draft_is_blocked(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('draft', 'archived', $this->create_event_post('hmw_event', 'archived'));

        $this->assertWorkflowBlocked('archived');

        $this->assertCount(1, $this->errorLogCalls);
        $this->assertStringContainsString('Blocked invalid status transition', $this->errorLogCalls[0]);
        $this->assertStringContainsString('archived', $this->errorLogCalls[0]);
        $this->assertStringContainsString('draft', $this->errorLogCalls[0]);
    }

    public function test_invalid_transition_fully_booked_to_draft_is_blocked(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('draft', 'fully_booked', $this->create_event_post('hmw_event', 'fully_booked'));
        $this->assertWorkflowBlocked('fully_booked');
    }

    public function test_invalid_transition_cancelled_to_draft_is_blocked(): void
    {
        $this->set_event_type_term('professional-in-person');
        $this->enforcer->enforce_workflow('draft', 'cancelled', $this->create_event_post('hmw_event', 'cancelled'));
        $this->assertWorkflowBlocked('cancelled');
    }

    public function test_invalid_transition_archived_to_fully_booked_is_blocked(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('fully_booked', 'archived', $this->create_event_post('hmw_event', 'archived'));
        $this->assertWorkflowBlocked('archived');
    }

    // ============================================================
    // Trash always allowed
    // ============================================================

    public function test_trash_from_draft_is_allowed(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('trash', 'draft', $this->create_event_post('hmw_event', 'draft'));
        $this->assertWorkflowAllowed();
    }

    public function test_trash_from_publish_is_allowed(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('trash', 'publish', $this->create_event_post('hmw_event', 'publish'));
        $this->assertWorkflowAllowed();
    }

    public function test_trash_from_archived_is_allowed(): void
    {
        $this->enforcer->enforce_workflow('trash', 'archived', $this->create_event_post('hmw_event', 'archived'));
        $this->assertWorkflowAllowed();
    }

    public function test_trash_from_any_status_is_allowed_regardless_of_workflow(): void
    {
        $this->set_event_type_term('parent-course');
        $this->enforcer->enforce_workflow('trash', 'cancelled', $this->create_event_post('hmw_event', 'cancelled'));
        $this->assertWorkflowAllowed();
    }

    // ============================================================
    // Non-hmw_event posts are ignored
    // ============================================================

    public function test_non_hmw_event_post_is_ignored(): void
    {
        $this->enforcer->enforce_workflow('draft', 'publish', $this->create_event_post('post', 'publish'));
        $this->assertWorkflowAllowed();
        $this->assertCount(0, $this->errorLogCalls);
    }

    public function test_hmw_registrant_post_is_ignored(): void
    {
        $this->enforcer->enforce_workflow('draft', 'publish', $this->create_event_post('hmw_registrant', 'publish'));
        $this->assertWorkflowAllowed();
        $this->assertCount(0, $this->errorLogCalls);
    }

    // ============================================================
    // Unknown event type falls back to defaults
    // ============================================================

    public function test_unknown_event_type_uses_default_workflow_valid_transition(): void
    {
        Functions\when('wp_get_post_terms')->justReturn([]);
        $this->enforcer->enforce_workflow('publish', 'draft', $this->create_event_post('hmw_event', 'draft'));
        $this->assertWorkflowAllowed();
    }

    public function test_unknown_event_type_uses_default_workflow_invalid_transition(): void
    {
        Functions\when('wp_get_post_terms')->justReturn([]);
        $this->enforcer->enforce_workflow('draft', 'archived', $this->create_event_post('hmw_event', 'archived'));
        $this->assertWorkflowBlocked('archived');
    }

    // ============================================================
    // is_transition_allowed() public method
    // ============================================================

    public function test_is_transition_allowed_returns_true_for_valid(): void
    {
        $result = $this->enforcer->is_transition_allowed('parent-course', 'draft', 'publish');
        $this->assertTrue($result);
    }

    public function test_is_transition_allowed_returns_false_for_invalid(): void
    {
        $result = $this->enforcer->is_transition_allowed('parent-course', 'archived', 'draft');
        $this->assertFalse($result);
    }

    public function test_is_transition_allowed_trash_always_true(): void
    {
        $result = $this->enforcer->is_transition_allowed('parent-course', 'archived', 'trash');
        $this->assertTrue($result);
    }

    public function test_is_transition_allowed_trash_always_true_with_empty_type(): void
    {
        $result = $this->enforcer->is_transition_allowed('', 'cancelled', 'trash');
        $this->assertTrue($result);
    }

    public function test_is_transition_allowed_unknown_from_status_returns_false(): void
    {
        $result = $this->enforcer->is_transition_allowed('parent-course', 'nonexistent', 'publish');
        $this->assertFalse($result);
    }

    public function test_is_transition_allowed_unknown_type_falls_back_to_defaults(): void
    {
        $result = $this->enforcer->is_transition_allowed('nonexistent', 'draft', 'publish');
        $this->assertTrue($result);

        $result = $this->enforcer->is_transition_allowed('nonexistent', 'archived', 'draft');
        $this->assertFalse($result);
    }

    // ============================================================
    // Edge case: same status (no change)
    // ============================================================

    public function test_same_status_no_change_is_ignored(): void
    {
        $this->enforcer->enforce_workflow('publish', 'publish', $this->create_event_post('hmw_event', 'publish'));
        $this->assertWorkflowAllowed();
        $this->assertCount(0, $this->errorLogCalls);
    }

    // ============================================================
    // Registration in components
    // ============================================================

    public function test_workflow_enforcer_is_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(WorkflowEnforcer::class, $components);
    }
}
