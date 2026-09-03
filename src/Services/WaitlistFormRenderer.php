<?php

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class WaitlistFormRenderer
{
    /**
     * Render the join-waitlist form shown when an event is fully booked.
     */
    public function render(int $event_id): string
    {
        $this->enqueue_assets();

        ob_start();
        ?>
        <div class="hmwevents-waitlist-wrapper" data-event-id="<?php echo esc_attr((string) $event_id); ?>">
          <form id="hmwevents-waitlist-form" class="hmwevents-waitlist-form" novalidate>
            <div class="hmwevents-form-section">
              <h3><?php esc_html_e('Join the waitlist', 'hmw-events'); ?></h3>
              <p class="hmwevents-waitlist-intro"><?php esc_html_e('This event is fully booked. Join the waitlist and we\'ll email you if a spot opens up.', 'hmw-events'); ?></p>
            </div>

            <div class="hmwevents-form-section">
              <div class="hmwevents-form-row">
                <div class="hmwevents-form-field">
                  <label for="hmw_waitlist_first_name"><?php esc_html_e('First Name', 'hmw-events'); ?></label>
                  <input type="text" id="hmw_waitlist_first_name" name="first_name" autocomplete="given-name">
                </div>
                <div class="hmwevents-form-field">
                  <label for="hmw_waitlist_last_name"><?php esc_html_e('Last Name', 'hmw-events'); ?></label>
                  <input type="text" id="hmw_waitlist_last_name" name="last_name" autocomplete="family-name">
                </div>
              </div>

              <div class="hmwevents-form-row">
                <div class="hmwevents-form-field hmwevents-form-field-full">
                  <label for="hmw_waitlist_email"><?php esc_html_e('Email', 'hmw-events'); ?> <span class="required">*</span></label>
                  <input type="email" id="hmw_waitlist_email" name="email" required autocomplete="email">
                </div>
              </div>
            </div>

            <div class="hmwevents-form-section hmwevents-waitlist-message" role="alert" style="display: none;"></div>

            <div class="hmwevents-form-section hmwevents-form-actions">
              <button type="submit" class="hmwevents-btn hmwevents-btn-primary">
                <span class="btn-text"><?php esc_html_e('Join Waitlist', 'hmw-events'); ?></span>
                <span class="btn-spinner" style="display: none;"><?php esc_html_e('Joining...', 'hmw-events'); ?></span>
              </button>
            </div>
          </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function enqueue_assets(): void
    {
        $base_url  = plugin_dir_url(dirname(__DIR__));
        $base_path = plugin_dir_path(dirname(__DIR__));

        $js_path = $base_path . 'assets/js/waitlist.js';
        wp_enqueue_script(
            'hmwevents-waitlist',
            $base_url . 'assets/js/waitlist.js',
            [],
            file_exists($js_path) ? (string) filemtime($js_path) : '1.0.0',
            true
        );

        wp_localize_script('hmwevents-waitlist', 'hmwWaitlistData', [
            'restUrl' => rest_url('hmwevents/v1/'),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);

        $css_path = $base_path . 'assets/css/waitlist.css';
        wp_enqueue_style(
            'hmwevents-waitlist',
            $base_url . 'assets/css/waitlist.css',
            [],
            file_exists($css_path) ? (string) filemtime($css_path) : '1.0.0'
        );
    }
}
