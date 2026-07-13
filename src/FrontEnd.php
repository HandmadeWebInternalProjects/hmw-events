<?php

namespace HMWEvents;

use HMWEvents\HMWEvents;

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

        // Run anything that needs init hook
        add_action('init', [$this, 'on_init']);

        add_action('template_redirect', [$this, 'template_redirect']);

        // Prevent Breakdance form submission for payment forms
        add_filter('breakdance_form_should_process', [$this, 'prevent_payment_form_processing'], 10, 2);

    }

    public function on_init()
    {

        // Add base page to breadcrumbs
        if (function_exists('yoast_breadcrumb')) {
            $this->update_yoast_breadcrumbs();
        }
    }

    private function update_yoast_breadcrumbs()
    {
        add_filter('wpseo_breadcrumb_links', function ($links) {
            $base_page = HMWEvents()->get_option('base_page');

            if (!isset($base_page)) {
                return $links;
            }

            $base_page = get_post($base_page);

            // if (Services\VirtualPages::is_hmw_events_page()) {
            //   $link = [
            //     'text' => $base_page->post_title,
            //     'url' => get_permalink($base_page->ID),
            //   ];

            //   array_splice($links, 1, 0, [$link]);
            // }

            return $links;
        });

        add_filter('wpseo_breadcrumb_single_link_info', function ($link_info, $index, $crumbs) {
            $base_page = HMWEvents()->get_option('base_page');

            if (!isset($base_page)) {
                return $link_info;
            }

            $current_page = get_post();


            return $link_info;
        }, 10, 3);
    }

    /**
     * Inject shortcode into base page.
     *
     * @since 1.0.0
     */

    public function template_redirect()
    {
        $base_page = HMWEvents()->get_option('base_page');
        $inject_shortcode = HMWEvents()->get_option('inject_shortcode');

        if (is_page($base_page) && $inject_shortcode == 'yes') {
            // we have a shortcode called 'hmw-events-listing', replace the content of this page with the shortcode
            add_filter('the_content', function ($content) {
                return '';
            });
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
        wp_enqueue_style('hmwevents-frontend-style', HMWEvents::plugin_url() . '/resources/css/frontend.css', [], HMWEvents_VERSION);
        wp_enqueue_style('hmwevents-event-listings', HMWEvents::plugin_url() . '/assets/css/event-listings.css', [], HMWEvents_VERSION);

        // Scripts.
        wp_register_script('hmwevents-frontend-module', HMWEvents::plugin_url() . '/resources/js/frontend.js', [], HMWEvents_VERSION, true);

        // Localize scripts.
        $localize_params = [
          'ajax_url' => admin_url('admin-ajax.php'),
          'hmwevents_rest_base_url' => rest_url('hmwevents/v1'),
        ];

        wp_localize_script('hmwevents-frontend-module', 'hmwevents_params', $localize_params);

        wp_enqueue_script('hmwevents-frontend-module');

        // if is courses post type
        if (is_singular('educator_course')) {
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
        $vars   = [];

        $mappings = [
            'primary_color'   => '--hmw-color-primary',
            'primary_hover'   => '--hmw-color-primary-hover',
            'text_color'      => '--hmw-color-text',
            'text_muted'      => '--hmw-color-text-muted',
            'border_color'    => '--hmw-color-border',
            'border_focus'    => '--hmw-color-border-focus',
            'bg_section'      => '--hmw-color-bg-section',
            'error_color'     => '--hmw-color-error',
            'success_color'   => '--hmw-color-success',
            'font_family'     => '--hmw-font-family',
            'font_size_base'  => '--hmw-font-size-base',
            'radius'          => '--hmw-radius',
            'gap'             => '--hmw-gap',
        ];

        $defaults = [
            'primary_color'   => '#2563eb',
            'primary_hover'   => '#1d4ed8',
            'text_color'      => '#1e293b',
            'text_muted'      => '#64748b',
            'border_color'    => '#e2e8f0',
            'border_focus'    => '#93c5fd',
            'bg_section'      => '#f8fafc',
            'error_color'     => '#dc2626',
            'success_color'   => '#16a34a',
            'font_family'     => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            'font_size_base'  => '16px',
            'radius'          => '8px',
            'gap'             => '16px',
        ];

        foreach ($mappings as $opt_key => $css_var) {
            $value = $options['hmwevents_theme_' . $opt_key] ?? $defaults[$opt_key];
            if ($value && $value !== $defaults[$opt_key]) {
                $vars[] = sprintf('%s: %s;', $css_var, esc_attr($value));
            }
        }

        if (empty($vars)) {
            return;
        }

        $primary = $options['hmwevents_theme_primary_color'] ?? '#2563eb';
        $error   = $options['hmwevents_theme_error_color'] ?? '#dc2626';
        $success = $options['hmwevents_theme_success_color'] ?? '#16a34a';

        $vars[] = sprintf('%s: %s;', '--hmw-color-primary-light', $this->hex_to_rgba($primary, 0.10));
        $vars[] = sprintf('%s: %s;', '--hmw-color-error-bg', $this->hex_to_rgba($error, 0.12));
        $vars[] = sprintf('%s: %s;', '--hmw-color-success-bg', $this->hex_to_rgba($success, 0.10));

        echo "\n<style id=\"hmwevents-theme-css\" type=\"text/css\">\n:root {\n";
        foreach ($vars as $decl) {
            echo "\t" . $decl . "\n";
        }
        echo "}\n</style>\n";
    }

    private function hex_to_rgba(string $hex, float $alpha): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return 'rgba(0,0,0,' . $alpha . ')';
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return sprintf('rgba(%d,%d,%d,%.2f)', $r, $g, $b, $alpha);
    }
}
