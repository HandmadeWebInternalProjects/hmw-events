<?php

namespace HMWEvents;

use HMWEvents\HMWEvents;
use HMWEvents\PostTypes\Event;

/**
 * Adbuilder Frontend Class.
 *
 * @package HMWEvents
 */

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * FrontEnd Class.
 */
class FrontEnd
{
    public function __construct()
    {
        // Frontend assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_head', [$this, 'render_theme_css'], 5);

        add_action('template_redirect', [$this, 'template_redirect']);

        // Prevent Breakdance form submission for payment forms
        add_filter('breakdance_form_should_process', [$this, 'prevent_payment_form_processing'], 10, 2);
    }

    /**
     * Replace the base page content with the event listings shortcode.
     */
    public function template_redirect()
    {
        $base_page = HMWEvents()->get_option('base_page');
        $inject_shortcode = HMWEvents()->get_option('inject_shortcode');

        if (is_page($base_page) && $inject_shortcode == 'yes') {
            add_filter('the_content', function ($content) {
                return do_shortcode('[hmw_event_listings]');
            }, 20);
        }
    }

    /**
     * Enqueue assets.
     *
     * @since 1.0.0
     */
    public function enqueue_assets()
    {

        // Styles.
        $cards_css_path = HMWEvents_ABSPATH . 'resources/css/cards.css';
        wp_enqueue_style('hmwevents-cards', HMWEvents::plugin_url() . '/resources/css/cards.css', [], file_exists($cards_css_path) ? filemtime($cards_css_path) : HMWEvents_VERSION);

        $listings_css_path = HMWEvents_ABSPATH . 'assets/css/event-listings.css';
        wp_enqueue_style('hmwevents-event-listings', HMWEvents::plugin_url() . '/assets/css/event-listings.css', [], file_exists($listings_css_path) ? filemtime($listings_css_path) : HMWEvents_VERSION);

        $category_cards_css_path = HMWEvents_ABSPATH . 'assets/css/event-category-cards.css';
        wp_enqueue_style('hmwevents-event-category-cards', HMWEvents::plugin_url() . '/assets/css/event-category-cards.css', [], file_exists($category_cards_css_path) ? filemtime($category_cards_css_path) : HMWEvents_VERSION);

        // Scripts.
        wp_register_script('hmwevents-frontend-module', HMWEvents::plugin_url() . '/resources/js/frontend.js', [], HMWEvents_VERSION, true);

        // Localize scripts.
        $localize_params = [
          'ajax_url' => admin_url('admin-ajax.php'),
          'hmwevents_rest_base_url' => rest_url('hmwevents/v1'),
        ];

        wp_localize_script('hmwevents-frontend-module', 'hmwevents_params', $localize_params);

        wp_enqueue_script('hmwevents-frontend-module');

        if (is_singular(Event::POST_TYPE)) {
          wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
          wp_enqueue_script('stripe-checkout', HMWEvents::plugin_url() . '/resources/js/components/stripe-checkout-v1.js', [], '1.0.0', true);
        }

        // Add script attributes based on handle naming conventions
        add_filter('script_loader_tag', [$this, 'add_script_attributes'], 10, 3);
    }

    /**
     * Add script attributes based on handle naming conventions.
     *
     * @param string $tag The script tag.
     * @param string $handle The script handle.
     * @param string $src The script source URL.
     * @return string Modified script tag.
     */
    public function add_script_attributes($tag, $handle, $src)
    {
        // Add type="module" for scripts with -module suffix
        if (strpos($handle, '-module') !== false) {
            $tag = str_replace('<script ', '<script type="module" ', $tag);
        }

        // Add async attribute for scripts with -async suffix
        if (strpos($handle, '-async') !== false) {
            $tag = str_replace('<script ', '<script async ', $tag);
        }

        // Add defer attribute for scripts with -defer suffix
        if (strpos($handle, '-defer') !== false) {
            $tag = str_replace('<script ', '<script defer ', $tag);
        }

        return $tag;
    }

    /**
     * Prevent Breakdance from processing payment forms.
     *
     * @since 1.0.0
     * @param bool $should_process Whether to process the form.
     * @param array $form Form data.
     * @return bool
     */
    public function prevent_payment_form_processing($should_process, $form)
    {
        // Check if this is a payment form (has Stripe publishable key field)
        if (isset($form['fields'])) {
            foreach ($form['fields'] as $field) {
                if (isset($field['advanced']['id']) && $field['advanced']['id'] === 'stripe_publishable_key') {
                    return false; // Let JavaScript handle everything
                }
            }
        }
        
        return $should_process;
    }

    /**
     * Output CSS custom property overrides from theme settings.
     */
    public function render_theme_css(): void
    {
        $options = \HMWEvents\Helpers\ConfigHelper::get_options();
        $vars    = [];

        foreach (\HMWEvents\Config\ThemeVars::fields() as $field) {
            $raw   = $options['hmwevents_theme_' . $field['key']] ?? null;
            $value = $this->sanitize_theme_value($raw, $field);
            if ($value === null || $value === '') {
                continue;
            }
            $vars[] = sprintf('%s: %s;', $field['var'], $value);
        }

        $primary = $this->sanitize_theme_value($options['hmwevents_theme_primary_color'] ?? null, ['type' => 'color', 'default' => '#2563eb']) ?? '#2563eb';
        $error   = $this->sanitize_theme_value($options['hmwevents_theme_error_color'] ?? null, ['type' => 'color', 'default' => '#dc2626']) ?? '#dc2626';
        $success = $this->sanitize_theme_value($options['hmwevents_theme_success_color'] ?? null, ['type' => 'color', 'default' => '#16a34a']) ?? '#16a34a';

        $vars[] = sprintf('%s: %s;', '--hmw-color-primary-light', $this->color_to_rgba($primary, 0.10));
        $vars[] = sprintf('%s: %s;', '--hmw-color-error-bg', $this->color_to_rgba($error, 0.12));
        $vars[] = sprintf('%s: %s;', '--hmw-color-success-bg', $this->color_to_rgba($success, 0.10));

        echo "\n<style id=\"hmwevents-theme-css\" type=\"text/css\">\n:root:root {\n";
        foreach ($vars as $decl) {
            echo "\t" . $decl . "\n";
        }
        echo "}\n</style>\n";
    }

    private function sanitize_theme_value($raw, array $field): ?string
    {
        $value = is_string($raw) ? trim($raw) : '';

        if ($value === '') {
            $value = $field['default'] ?? '';
        }

        if ($value === '') {
            return null;
        }

        if (($field['type'] ?? 'text') === 'color') {
            return $this->sanitize_color_value($value);
        }

        $value = wp_strip_all_tags($value);
        return str_replace(['<', '>', '\\', '`'], '', $value);
    }

    private function sanitize_color_value(string $value): ?string
    {
        if (preg_match('/^var\(--[a-z0-9_-]+(\s*,\s*[a-z0-9#%._ -]+)?\)$/i', $value)) {
            return $value;
        }

        $color = $this->parse_color($value);
        if ($color === null) {
            return null;
        }

        if ($color['a'] < 1) {
            $alpha = rtrim(rtrim(sprintf('%.3f', $color['a']), '0'), '.');
            return sprintf('rgba(%d, %d, %d, %s)', $color['r'], $color['g'], $color['b'], $alpha);
        }

        return sprintf('#%02x%02x%02x', $color['r'], $color['g'], $color['b']);
    }

    private function parse_color(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value)) {
            $hex = ltrim($value, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            return [
                'r' => hexdec(substr($hex, 0, 2)),
                'g' => hexdec(substr($hex, 2, 2)),
                'b' => hexdec(substr($hex, 4, 2)),
                'a' => 1.0,
            ];
        }

        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(0|1|0?\.\d+)\s*)?\)$/i', $value, $m)) {
            return [
                'r' => min(255, (int) $m[1]),
                'g' => min(255, (int) $m[2]),
                'b' => min(255, (int) $m[3]),
                'a' => isset($m[4]) ? min(1, (float) $m[4]) : 1.0,
            ];
        }

        return null;
    }

    private function color_to_rgba(string $color, float $alpha): string
    {
        if (preg_match('/^var\(--/i', $color)) {
            $percent = rtrim(rtrim(sprintf('%.1f', $alpha * 100), '0'), '.');
            return sprintf('color-mix(in srgb, %s %s%%, transparent)', $color, $percent);
        }

        $parsed = $this->parse_color($color);
        if ($parsed === null) {
            return 'rgba(0,0,0,' . $alpha . ')';
        }

        $blended = $parsed['a'] * $alpha;
        return sprintf('rgba(%d,%d,%d,%.2f)', $parsed['r'], $parsed['g'], $parsed['b'], $blended);
    }
}
