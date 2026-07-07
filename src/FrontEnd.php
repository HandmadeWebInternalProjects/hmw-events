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
}
