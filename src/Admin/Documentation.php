<?php

namespace HMWEvents\Admin;

use League\CommonMark\GithubFlavoredMarkdownConverter;

defined('ABSPATH') || die('Don\'t run this file directly!');

class Documentation
{
  public const PAGE_SLUG = 'hmwevents-documentation';
  public const URL_PARAM = 'doc';

  private const DOCS_DIR = 'docs/user-guide/';

  private const PAGES = [
    'index'               => 'Welcome',
    'adding-events'       => 'Adding Events',
    'event-templates'     => 'Event Templates',
    'assigning-templates' => 'Assigning Templates',
    'overriding-templates' => 'Overriding Templates',
    'email-templates'     => 'Email Templates',
  ];

  private ?GithubFlavoredMarkdownConverter $converter = null;

  public function pages(): array
  {
    return self::PAGES;
  }

  public function is_valid_slug(string $slug): bool
  {
    return array_key_exists($slug, self::PAGES);
  }

  public function current_slug(): string
  {
    $requested = isset($_GET[self::URL_PARAM]) ? sanitize_key((string) wp_unslash($_GET[self::URL_PARAM])) : '';

    return $this->is_valid_slug($requested) ? $requested : 'index';
  }

  public function page_url(string $slug): string
  {
    return admin_url('admin.php?page=' . self::PAGE_SLUG . '&doc=' . rawurlencode($slug));
  }

  public function page_title(string $slug, ?string $markdown = null): string
  {
    if ($markdown === null) {
      $markdown = $this->load_markdown($slug);
    }

    $title = $markdown !== null ? $this->extract_title($markdown) : null;

    return $title ?? self::PAGES[$slug] ?? self::PAGES['index'];
  }

  public function extract_title(string $markdown): ?string
  {
    if (preg_match('/^#[ \t]+(.+?)[ \t]*$/m', $markdown, $matches) !== 1) {
      return null;
    }

    $title = trim($matches[1]);

    return $title === '' ? null : $title;
  }

  public function rewrite_links(string $markdown): string
  {
    $result = preg_replace_callback(
      '/\]\(([^)]+)\)/',
      function (array $matches): string {
        return '](' . $this->rewrite_target(trim($matches[1])) . ')';
      },
      $markdown
    );

    return $result === null ? $markdown : $result;
  }

  public function render_markdown(string $markdown): string
  {
    $body = $this->strip_leading_h1($markdown);
    $body = $this->rewrite_links($body);

    $html = (string) $this->converter()->convert($body);

    return wp_kses_post($html);
  }

  public function render_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'hmw-events'));
    }

    $slug = $this->current_slug();
    $markdown = $this->load_markdown($slug);

    echo '<div class="wrap hmwevents-docs">';
    echo '<div class="hmwevents-docs-layout">';

    echo '<nav class="hmwevents-docs-nav" aria-label="' . esc_attr__('User guide pages', 'hmw-events') . '"><ul>';
    foreach ($this->pages() as $page_slug => $label) {
      $active = $page_slug === $slug ? ' class="is-active"' : '';
      echo '<li' . $active . '><a href="' . esc_url($this->page_url($page_slug)) . '">' . esc_html($label) . '</a></li>';
    }
    echo '</ul></nav>';

    echo '<article class="hmwevents-docs-content">';
    if ($markdown === null) {
      echo '<h1>' . esc_html($this->page_title($slug)) . '</h1>';
      echo '<p>' . esc_html__('The documentation file could not be found. Please ask your web team to restore the plugin docs folder.', 'hmw-events') . '</p>';
    } else {
      echo '<h1>' . esc_html($this->page_title($slug, $markdown)) . '</h1>';
      echo $this->render_markdown($markdown);
    }
    echo '</article>';

    echo '</div>';
    echo '</div>';
  }

  private function rewrite_target(string $target): string
  {
    if (str_starts_with($target, 'images/') && !str_contains($target, '..')) {
      return $this->image_url($target);
    }

    if (preg_match('/^([a-z0-9_-]+)\.md(#[a-z0-9_-]+)?$/', $target, $matches) === 1) {
      $slug = $matches[1];
      if ($this->is_valid_slug($slug)) {
        return $this->page_url($slug);
      }
    }

    return $target;
  }

  private function image_url(string $relative): string
  {
    return plugins_url(self::DOCS_DIR . $relative, HMWEvents_PLUGIN_FILE);
  }

  private function strip_leading_h1(string $markdown): string
  {
    $result = preg_replace('/^#[ \t]+.*\R+/', '', $markdown, 1);

    return $result ?? $markdown;
  }

  private function load_markdown(string $slug): ?string
  {
    if (!$this->is_valid_slug($slug)) {
      return null;
    }

    $base = realpath(HMWEvents_ABSPATH . self::DOCS_DIR);
    $path = realpath(HMWEvents_ABSPATH . self::DOCS_DIR . $slug . '.md');

    if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
      return null;
    }

    $contents = file_get_contents($path);

    return $contents === false ? null : $contents;
  }

  private function converter(): GithubFlavoredMarkdownConverter
  {
    if ($this->converter === null) {
      $this->converter = new GithubFlavoredMarkdownConverter([
        'html_input' => 'escape',
        'allow_unsafe_links' => false,
      ]);
    }

    return $this->converter;
  }
}
