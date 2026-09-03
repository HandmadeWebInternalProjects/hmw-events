<?php

namespace HMWEvents\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Admin\Documentation;
use PHPUnit\Framework\TestCase;

class DocumentationPageTest extends TestCase
{
    /** @var array */
    private $originalGet = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->originalGet = $_GET;

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        if (!defined('HMWEvents_PLUGIN_FILE')) {
            define('HMWEvents_PLUGIN_FILE', HMWEvents_ABSPATH . 'hmw-events.php');
        }

        Functions\when('sanitize_key')->alias(function ($key) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
        });
        Functions\when('wp_unslash')->returnArg();
        Functions\when('admin_url')->alias(function ($path = '') {
            return 'https://example.test/wp-admin/' . $path;
        });
        Functions\when('plugins_url')->alias(function ($path = '', $file = null) {
            return 'https://example.test/wp-content/plugins/hmw-events/' . $path;
        });
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr__')->returnArg();
        Functions\when('__')->returnArg();
        Functions\when('wp_die')->alias(function ($message = '') {
            throw new \RuntimeException('WP_DIE');
        });
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
        Monkey\tearDown();
        parent::tearDown();
    }

    private function createHandler(): Documentation
    {
        return new Documentation();
    }

    // ================================================================
    // Whitelist validation
    // ================================================================

    public function test_pages_contains_all_known_guide_slugs(): void
    {
        $pages = $this->createHandler()->pages();

        foreach (['index', 'adding-events', 'event-templates', 'assigning-templates', 'overriding-templates', 'email-templates'] as $slug) {
            $this->assertArrayHasKey($slug, $pages);
        }
    }

    public function test_is_valid_slug_accepts_known_slugs(): void
    {
        $docs = $this->createHandler();

        $this->assertTrue($docs->is_valid_slug('index'));
        $this->assertTrue($docs->is_valid_slug('adding-events'));
    }

    public function test_is_valid_slug_rejects_unknown_slugs(): void
    {
        $docs = $this->createHandler();

        $this->assertFalse($docs->is_valid_slug('bogus'));
        $this->assertFalse($docs->is_valid_slug(''));
        $this->assertFalse($docs->is_valid_slug('../wp-config'));
    }

    public function test_current_slug_defaults_to_index(): void
    {
        unset($_GET['doc']);

        $this->assertSame('index', $this->createHandler()->current_slug());
    }

    public function test_current_slug_returns_whitelisted_param(): void
    {
        $_GET['doc'] = 'adding-events';

        $this->assertSame('adding-events', $this->createHandler()->current_slug());
    }

    public function test_current_slug_falls_back_for_unknown_param(): void
    {
        $_GET['doc'] = 'bogus';

        $this->assertSame('index', $this->createHandler()->current_slug());
    }

    public function test_current_slug_falls_back_for_path_traversal(): void
    {
        $_GET['doc'] = '../wp-config';

        $this->assertSame('index', $this->createHandler()->current_slug());
    }

    public function test_current_slug_normalizes_case(): void
    {
        $_GET['doc'] = 'INDEX';

        $this->assertSame('index', $this->createHandler()->current_slug());
    }

    // ================================================================
    // Page URLs
    // ================================================================

    public function test_page_url_builds_admin_link(): void
    {
        $this->assertSame(
            'https://example.test/wp-admin/admin.php?page=hmwevents-documentation&doc=adding-events',
            $this->createHandler()->page_url('adding-events')
        );
    }

    // ================================================================
    // Title extraction
    // ================================================================

    public function test_extract_title_reads_first_h1(): void
    {
        $markdown = (string) file_get_contents(HMWEvents_ABSPATH . 'docs/user-guide/adding-events.md');

        $this->assertSame('Adding Events', $this->createHandler()->extract_title($markdown));
    }

    public function test_extract_title_ignores_subheadings(): void
    {
        $this->assertSame('Real Title', $this->createHandler()->extract_title("# Real Title\n## Sub Title\n"));
    }

    public function test_extract_title_returns_null_without_h1(): void
    {
        $this->assertNull($this->createHandler()->extract_title("## Only Sub\nSome text\n"));
    }

    public function test_extract_title_trims_whitespace(): void
    {
        $this->assertSame('Spaced', $this->createHandler()->extract_title("#   Spaced   \n"));
    }

    public function test_page_title_uses_doc_heading(): void
    {
        $this->assertSame('Adding Events', $this->createHandler()->page_title('adding-events'));
    }

    public function test_page_title_prefers_provided_markdown(): void
    {
        $this->assertSame('Inline Title', $this->createHandler()->page_title('index', "# Inline Title\n"));
    }

    public function test_page_title_falls_back_for_invalid_slug(): void
    {
        $this->assertSame('Welcome', $this->createHandler()->page_title('bogus'));
    }

    // ================================================================
    // Link and asset rewriting
    // ================================================================

    public function test_rewrite_links_rewrites_image_references(): void
    {
        $result = $this->createHandler()->rewrite_links('![alt](images/foo.png)');

        $this->assertSame(
            '![alt](https://example.test/wp-content/plugins/hmw-events/docs/user-guide/images/foo.png)',
            $result
        );
    }

    public function test_rewrite_links_rewrites_internal_md_links(): void
    {
        $result = $this->createHandler()->rewrite_links('[x](event-templates.md)');

        $this->assertSame(
            '[x](https://example.test/wp-admin/admin.php?page=hmwevents-documentation&doc=event-templates)',
            $result
        );
    }

    public function test_rewrite_links_preserves_md_anchors(): void
    {
        $result = $this->createHandler()->rewrite_links('[x](event-templates.md#pricing)');

        $this->assertStringContainsString('doc=event-templates', $result);
    }

    public function test_rewrite_links_leaves_unknown_md_files_untouched(): void
    {
        $markdown = '[x](unknown-page.md)';

        $this->assertSame($markdown, $this->createHandler()->rewrite_links($markdown));
    }

    public function test_rewrite_links_leaves_traversal_targets_untouched(): void
    {
        $markdown = '![x](images/../../secret.png)';

        $this->assertSame($markdown, $this->createHandler()->rewrite_links($markdown));
    }

    public function test_rewrite_links_leaves_external_urls_untouched(): void
    {
        $markdown = '[x](https://example.com/a.md)';

        $this->assertSame($markdown, $this->createHandler()->rewrite_links($markdown));
    }

    // ================================================================
    // Markdown rendering
    // ================================================================

    public function test_render_markdown_converts_real_index_page(): void
    {
        $markdown = (string) file_get_contents(HMWEvents_ABSPATH . 'docs/user-guide/index.md');
        $html = $this->createHandler()->render_markdown($markdown);

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringNotContainsString('<h1>', $html);
    }

    public function test_render_markdown_rewrites_images_and_internal_links(): void
    {
        $markdown = (string) file_get_contents(HMWEvents_ABSPATH . 'docs/user-guide/adding-events.md');
        $html = $this->createHandler()->render_markdown($markdown);

        $this->assertStringContainsString('docs/user-guide/images/adding-events-01-events-menu.png', $html);
        $this->assertStringContainsString('doc=event-templates', $html);
        $this->assertStringNotContainsString('](images/', $html);
    }

    // ================================================================
    // Page rendering
    // ================================================================

    public function test_render_page_requires_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WP_DIE');

        $this->createHandler()->render_page();
    }

    public function test_render_page_outputs_two_pane_layout(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET['doc'] = 'adding-events';

        ob_start();
        $this->createHandler()->render_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('hmwevents-docs-layout', $output);
        $this->assertStringContainsString('hmwevents-docs-nav', $output);
        $this->assertStringContainsString('hmwevents-docs-content', $output);
        $this->assertSame(1, substr_count($output, '<h1>'));
        $this->assertStringContainsString('Adding Events', $output);
        $this->assertStringContainsString('<li class="is-active">', $output);
        $this->assertStringContainsString('<img', $output);
    }

    public function test_render_page_marks_index_active_by_default(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        unset($_GET['doc']);

        ob_start();
        $this->createHandler()->render_page();
        $output = (string) ob_get_clean();

        $this->assertSame(1, substr_count($output, 'class="is-active"'));
    }
}
