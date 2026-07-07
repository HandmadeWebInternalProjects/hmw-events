<?php
namespace HMWEvents\Tests\Unit;

use HMWEvents\Registry\RegistrationFieldRegistry;
use HMWEvents\Services\RegistrationFormPreset;
use HMWEvents\Services\RegistrationFormRenderer;
use HMWEvents\Services\DocumentUploadHandler;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class RegistrationFormTest extends TestCase
{
    public function test_field_registry_returns_fields(): void
    {
        $fields = RegistrationFieldRegistry::all();
        $this->assertIsArray($fields);
        $this->assertNotEmpty($fields);
    }

    public function test_core_contact_fields_exist(): void
    {
        $fields = RegistrationFieldRegistry::all();
        $this->assertArrayHasKey('first_name', $fields);
        $this->assertArrayHasKey('last_name', $fields);
        $this->assertArrayHasKey('email', $fields);
        $this->assertArrayHasKey('phone', $fields);
    }

    public function test_professional_fields_exist(): void
    {
        $fields = RegistrationFieldRegistry::all();
        $this->assertArrayHasKey('organisation', $fields);
        $this->assertArrayHasKey('job_title', $fields);
        $this->assertArrayHasKey('professional_body', $fields);
    }

    public function test_parent_fields_exist(): void
    {
        $fields = RegistrationFieldRegistry::all();
        $this->assertArrayHasKey('due_date', $fields);
        $this->assertArrayHasKey('first_baby', $fields);
        $this->assertArrayHasKey('health_fund', $fields);
        $this->assertArrayHasKey('partner_name', $fields);
    }

    public function test_child_fields_exist(): void
    {
        $fields = RegistrationFieldRegistry::all();
        $this->assertArrayHasKey('child_name', $fields);
        $this->assertArrayHasKey('child_age', $fields);
    }

    public function test_document_upload_field_exists(): void
    {
        $fields = RegistrationFieldRegistry::all();
        $this->assertArrayHasKey('document_upload', $fields);
        $this->assertSame('file', $fields['document_upload']['type']);
    }

    public function test_for_audience_parent(): void
    {
        $parent_fields = RegistrationFieldRegistry::for_audience('parent');
        $this->assertArrayHasKey('due_date', $parent_fields);
        $this->assertArrayHasKey('first_name', $parent_fields);
    }

    public function test_for_audience_professional(): void
    {
        $pro_fields = RegistrationFieldRegistry::for_audience('professional');
        $this->assertArrayHasKey('organisation', $pro_fields);
        $this->assertArrayNotHasKey('due_date', $pro_fields);
        $this->assertArrayHasKey('first_name', $pro_fields);
    }

    public function test_individual_audience_excludes_parent_pro(): void
    {
        $fields = RegistrationFieldRegistry::for_audience('individual');
        $this->assertArrayHasKey('first_name', $fields);
        $this->assertArrayNotHasKey('due_date', $fields);
        $this->assertArrayNotHasKey('organisation', $fields);
        $this->assertArrayNotHasKey('partner_name', $fields);
    }

    public function test_get_sections_returns_all(): void
    {
        $sections = RegistrationFieldRegistry::get_sections();
        $this->assertArrayHasKey('contact', $sections);
        $this->assertArrayHasKey('professional', $sections);
        $this->assertArrayHasKey('documents', $sections);
        $this->assertArrayHasKey('parent_details', $sections);
    }

    public function test_preset_service_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(RegistrationFormPreset::class, $components);
    }

    public function test_renderer_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(RegistrationFormRenderer::class, $components);
    }

    public function test_handler_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(DocumentUploadHandler::class, $components);
    }

    public function test_preset_service_instantiable(): void
    {
        Functions\when('sanitize_key')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->justReturn(null);

        $service = new RegistrationFormPreset();
        $this->assertInstanceOf(RegistrationFormPreset::class, $service);
    }

    public function test_renderer_instantiable(): void
    {
        $renderer = new RegistrationFormRenderer();
        $this->assertInstanceOf(RegistrationFormRenderer::class, $renderer);
    }

    public function test_handler_instantiable(): void
    {
        $handler = new DocumentUploadHandler();
        $this->assertInstanceOf(DocumentUploadHandler::class, $handler);
    }

    public function test_group_fields_by_section(): void
    {
        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html__')->returnArg();

        $renderer = new RegistrationFormRenderer();
        $fields = RegistrationFieldRegistry::for_audience('parent');
        $sections = $renderer->group_fields_by_section($fields);
        $this->assertArrayHasKey('contact', $sections);
        $this->assertArrayHasKey('parent_details', $sections);
    }

    public function test_render_field_returns_html(): void
    {
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_textarea')->returnArg();

        $renderer = new RegistrationFormRenderer();
        $html = $renderer->render_field('first_name', [
            'label' => 'First Name', 'type' => 'text', 'required' => true, 'placeholder' => '',
        ]);
        $this->assertStringContainsString('First Name', $html);
        $this->assertStringContainsString('type="text"', $html);
    }

    public function test_validate_upload_rejects_empty(): void
    {
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp']);
        Functions\when('wp_mkdir_p')->justReturn(true);

        $handler = new DocumentUploadHandler();
        $result = $handler->handle_upload(['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE], 0, 1);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        Functions\when('__')->returnArg();

        Functions\when('sanitize_key')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->justReturn([]);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('maybe_unserialize')->returnArg();
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp']);
        Functions\when('wp_mkdir_p')->justReturn(true);
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_textarea')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('is_email')->justReturn(true);
        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('error_log')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
