<?php

namespace HMWEvents\Admin;

defined('ABSPATH') || die('Don\'t run this file directly!');

use HMWEvents\Services\Emails\EmailTemplateRepository;
use HMWEvents\Services\Emails\EmailService;
use HMWEvents\Helpers\ConfigHelper;

/**
 * Email Templates Admin Handler
 *
 * @package HMWEvents\Admin
 */
class EmailTemplates
{
  /**
   * Constructor
   */
  public function __construct()
  {
    add_action('admin_post_hmwevents_email_template_save', [$this, 'handle_save']);
    add_action('admin_post_hmwevents_email_template_create_defaults', [$this, 'handle_create_defaults']);
    add_action('admin_post_hmwevents_email_template_regenerate_defaults', [$this, 'handle_regenerate_defaults']);

    // AJAX handlers
    add_action('wp_ajax_hmwevents_load_email_template', [$this, 'ajax_load_template']);
    add_action('wp_ajax_hmwevents_save_email_template_ajax', [$this, 'ajax_save_template']);
    add_action('wp_ajax_hmwevents_preview_email_template', [$this, 'ajax_preview_template']);
    add_action('wp_ajax_hmwevents_send_test_email_template', [$this, 'ajax_send_test_email']);
    add_action('wp_ajax_hmwevents_get_available_bookings', [$this, 'ajax_get_available_bookings']);
  }

  /**
   * Render the main email templates page
   */
  public function render_page()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $template_repo = new EmailTemplateRepository();
    $templates = $template_repo->get_system_templates();
    $selected_key = isset($_GET['template_key']) ? sanitize_text_field($_GET['template_key']) : '';
    $selected_template = null;

    foreach ($templates as $template) {
      if ($template->template_key === $selected_key) {
        $selected_template = $template;
        break;
      }
    }

    ob_start();
    ?>
    <div class="wrap hmwevents-email-template-wrap">
      <h1><?php esc_html_e('Email Templates', 'cms'); ?></h1>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 16px;">
        <input type="hidden" name="action" value="hmwevents_email_template_create_defaults">
        <?php wp_nonce_field('hmwevents_email_template_create_defaults'); ?>
        <button class="button"><?php esc_html_e('Create Default Templates', 'cms'); ?></button>
      </form>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 16px;" onsubmit="return confirm('This will overwrite all system email templates with the default versions. Continue?');">
        <input type="hidden" name="action" value="hmwevents_email_template_regenerate_defaults">
        <?php wp_nonce_field('hmwevents_email_template_regenerate_defaults'); ?>
        <button class="button button-secondary"><?php esc_html_e('Re-generate Defaults (Overwrite Existing)', 'cms'); ?></button>
      </form>

      <?php if (empty($templates)): ?>
        <p><?php esc_html_e('No templates found. Click "Create Default Templates" to generate the base set.', 'cms'); ?></p>
      <?php else: ?>
        <h2><?php esc_html_e('Select Template', 'cms'); ?></h2>
        <form method="get" class="hmwevents-template-selector-form">
          <input type="hidden" name="page" value="hmwevents-email-templates">
          <select name="template_key">
            <?php foreach ($templates as $template): ?>
              <?php if (in_array($template->template_key, ['post_course_followup', 'pending_payment_link'], true)) continue; ?>
              <option value="<?php echo esc_attr($template->template_key); ?>" <?php selected($template->template_key, $selected_key); ?>>
                <?php echo esc_html($template->template_key); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="button"><?php esc_html_e('Load', 'cms'); ?></button>
        </form>

        <?php if ($selected_template): ?>
          <div class="hmwevents-email-template-editor">
            <?php $this->render_template_editor($selected_template); ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
    echo ob_get_clean();
  }

  /**
   * Render the template editor form
   *
   * @param object $template Template object
   */
  private function render_template_editor($template)
  {
    ?>
    <h2><?php esc_html_e('Edit Template', 'cms'); ?></h2>
    <?php
    $this->render_template_form([
      'template'      => $template,
      'form_action'   => 'hmwevents_email_template_save',
      'nonce_action'  => 'hmwevents_email_template_save',
      'hidden_fields' => [
        'template_key' => $template->template_key,
      ],
      'editor_id'     => 'email_template_body',
      'editor_rows'   => 20,
      'submit_text'   => __('Save Template', 'cms'),
    ]);
  }

  /**
   * Render the template form
   *
   * @param array $args Form configuration arguments
   */
  private function render_template_form($args)
  {
    $defaults = [
      'template'      => null,
      'form_action'   => '',
      'nonce_action'  => '',
      'hidden_fields' => [],
      'editor_id'     => 'email_template_body',
      'editor_rows'   => 20,
      'submit_text'   => __('Save', 'cms'),
    ];

    $args = wp_parse_args($args, $defaults);
    $template = $args['template'];
    $variables = $this->get_template_variable_help($template->template_key);
    ?>
    <div class="hmwevents-email-template-form" data-action="<?php echo esc_attr($args['form_action']); ?>" data-nonce-action="<?php echo esc_attr($args['nonce_action']); ?>">
      
      <?php foreach ($args['hidden_fields'] as $name => $value): ?>
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
      <?php endforeach; ?>

      <p>
        <label><strong><?php esc_html_e('Subject', 'cms'); ?></strong></label><br>
        <input type="text" name="subject" value="<?php echo esc_attr($template->subject); ?>" class="large-text">
      </p>

      <div style="margin: 20px 0;">
        <label><strong><?php esc_html_e('Body (HTML)', 'cms'); ?></strong></label>
        <?php
        // Get variables for this template to add to editor
        $template_variables = $this->get_template_variable_help($template->template_key);
        $variables_json = json_encode($template_variables);
        ?>
        <script type="text/javascript">
          if (typeof window.cmsTemplateVariables === 'undefined') {
            window.cmsTemplateVariables = {};
          }
          window.cmsTemplateVariables['<?php echo esc_js($args['editor_id']); ?>'] = <?php echo $variables_json; ?>;
        </script>
        <?php
        wp_editor(
          $template->body,
          $args['editor_id'],
          [
            'textarea_name' => 'body',
            'textarea_rows' => $args['editor_rows'],
            'teeny' => false,
            'media_buttons' => true,
            'tinymce' => [
              'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,forecolor,backcolor',
              'toolbar2' => 'undo,redo,removeformat,code,hmwevents_merge_tags',
              'setup' => 'function(editor) { if (typeof window.setupCmsMergeTagsButton !== "undefined") { window.setupCmsMergeTagsButton(editor); } }',
            ],
          ]
        );
        ?>
      </div>

      <p>
        <label>
          <input type="checkbox" name="is_active" value="1" <?php checked($template->is_active, 1); ?>>
          <?php esc_html_e('Active', 'cms'); ?>
        </label>
      </p>

      <div class="hmwevents-template-variables">
        <?php if (!empty($variables)): ?>
          <p><strong><?php esc_html_e('Available Variables - copy/paste or use the Merge Tags dropdown in the editor', 'cms'); ?></strong></p>
          <ul>
            <?php foreach ($variables as $key => $desc): ?>
              <li>
                <code>{{<?php echo esc_html($key); ?>}}</code> - <?php echo esc_html($desc); ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <p>
        <button type="button" class="button button-primary hmwevents-save-template-btn"><?php echo esc_html($args['submit_text']); ?></button>
      </p>

      <hr style="margin: 20px 0;">

      <p>
        <label><strong><?php esc_html_e('Preview & Test Options', 'cms'); ?></strong></label><br>
        <label style="display: block; margin-bottom: 10px;">
          <input type="checkbox" class="hmwevents-use-real-booking" value="1">
          <?php esc_html_e('Use Real Booking (optional) - Numbers only, eg for booking CB-203xxx just start typing 203', 'cms'); ?>
        </label>
        <div class="hmwevents-booking-selector" style="display: none; margin-bottom: 10px;">
          <input type="number" class="regular-text hmwevents-booking-id-input" placeholder="Enter booking ID or start typing to search..." min="1">
          <div class="hmwevents-booking-suggestions" style="border: 1px solid #ccc; background: #f5f5f5; max-height: 200px; overflow-y: auto; display: none; margin-top: 4px;"></div>
        </div>
        <p style="color: #666; font-size: 12px; display: block; margin-bottom: 4px;"><?php esc_html_e('Using a real booking? Don\'t worry — this is just for preview and the customer won\'t be notified or emailed.', 'cms'); ?></p>
        <strong style="color: #666; font-size: 12px;"><?php esc_html_e('No booking selected? Sample data will be used for the preview and will also appear in any test email you send yourself.', 'cms'); ?></strong>
      </p>

      <p>
        <button type="button" class="button hmwevents-preview-template-btn"><?php esc_html_e('Preview', 'cms'); ?></button>
      </p>

      <p>
        <label><strong><?php esc_html_e('Send Test To', 'cms'); ?></strong></label><br>
        <input type="email" class="regular-text hmwevents-test-email-input" value="<?php echo esc_attr(wp_get_current_user()->user_email ?? ''); ?>" placeholder="you@example.com">
        <button type="button" class="button hmwevents-send-test-email-btn"><?php esc_html_e('Send Test Email', 'cms'); ?></button>
      </p>
    </div>
    <?php
  }

  /**
   * Handle saving email template
   */
  public function handle_save()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    check_admin_referer('hmwevents_email_template_save');

    $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';
    $subject = isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '';
    $body = isset($_POST['body']) ? wp_unslash($_POST['body']) : '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $repo = new EmailTemplateRepository();
    $saved = $repo->save([
      'template_key' => $template_key,
      'subject' => $subject,
      'body' => $body,
      'is_active' => $is_active,
    ]);

    if ($saved) {
      set_transient('hmwevents_success_notice', 'Template saved.', 30);
    } else {
      set_transient('hmwevents_error_notice', 'Failed to save template.', 30);
    }

    wp_safe_redirect(admin_url('admin.php?page=hmwevents-email-templates&template_key=' . urlencode($template_key)));
    exit;
  }

  /**
   * Handle creating default templates
   */
  public function handle_create_defaults()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    check_admin_referer('hmwevents_email_template_create_defaults');

    $service = new EmailService();
    $service->create_default_templates();

    set_transient('hmwevents_success_notice', 'Default templates created.', 30);
    wp_safe_redirect(admin_url('admin.php?page=hmwevents-email-templates'));
    exit;
  }

  /**
   * Handle regenerating (overwriting) default templates
   */
  public function handle_regenerate_defaults()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    check_admin_referer('hmwevents_email_template_regenerate_defaults');

    $service = new EmailService();
    $service->create_default_templates(true);

    set_transient('hmwevents_success_notice', 'Default templates re-generated and existing system templates were overwritten.', 30);
    wp_safe_redirect(admin_url('admin.php?page=hmwevents-email-templates'));
    exit;
  }

  /**
   * Get template variable help by template key
   *
   * @param string $template_key Template key
   * @return array
   */
  private function get_template_variable_help($template_key)
  {
    // Map template keys to their handler type in EmailService.
    $template_key_to_handler = [
      'booking_confirmation'  => 'booking_confirmation',
      'course_reminder'       => 'reminder',
      'post_course_feedback'  => 'post_course',
      'post_course_followup'  => 'post_course',
      'booking_cancelled'     => 'status_change',
      'refund_issued'         => 'status_change',
      'course_changed'        => 'status_change',
      'payment_link'          => 'status_change',
      'remaining_payment_link'=> 'status_change',
      'organizer_new_booking' => 'organizer_new_booking',
    ];

    $handler_type = $template_key_to_handler[$template_key] ?? null;

    if ($handler_type) {
      $service = new EmailService();
      $handler = $service->get_handler($handler_type);
      if ($handler) {
        return $handler->get_template_variables_description();
      }
    }

    // Fallback: return base variables common to all templates.
    $service = new EmailService();
    $handler = $service->get_handler('booking_confirmation');
    return $handler
      ? $handler->get_template_variables_description()
      : [];
  }

  /**
   * Get variables from a real booking for preview/test rendering.
   *
   * @param int $booking_id
   * @param string $template_key
   * @return array|false
   */
  private function get_booking_variables($booking_id, $template_key)
  {
    global $wpdb;

    $booking = $wpdb->get_row($wpdb->prepare(
      "SELECT b.* FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " b
       WHERE b.id = %d AND b.deleted_at IS NULL",
      $booking_id
    ));

    if (!$booking) {
      return false;
    }

    // Use the email handler to prepare fresh data with all variables
    $service = new EmailService();

    // Map template key → handler type (mirrors the mapping in EmailService)
    $template_key_to_handler = [
      'booking_confirmation'   => 'booking_confirmation',
      'course_reminder'        => 'reminder',
      'post_course_feedback'   => 'post_course',
      'post_course_followup'   => 'post_course',
      'booking_cancelled'      => 'status_change',
      'refund_issued'          => 'status_change',
      'course_changed'         => 'status_change',
      'payment_link'           => 'status_change',
      'remaining_payment_link' => 'status_change',
      'organizer_new_booking'  => 'organizer_new_booking',
    ];
    $handler_type = $template_key_to_handler[$template_key] ?? $template_key;
    $handler = $service->get_handler($handler_type);

    if (!$handler) {
      return false;
    }

    // Get fresh data from the handler (this properly loads all booking info)
    $variables = $handler->get_template_data_for_booking($booking_id);

    return !empty($variables) ? $variables : false;
  }

  /**
   * AJAX handler to load a template
   */
  public function ajax_load_template()
  {
    check_ajax_referer('hmwevents_email_templates', 'nonce');

    $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';

    if (empty($template_key)) {
      wp_send_json_error(['message' => 'Template key is required']);
    }

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Permission denied']);
    }

    $template_repo = new EmailTemplateRepository();
    $template = null;

    // Get system template
    $system_templates = $template_repo->get_system_templates();
    foreach ($system_templates as $t) {
      if ($t->template_key === $template_key) {
        $template = $t;
        break;
      }
    }

    $editor_id = 'email_template_body';

    if (!$template) {
      wp_send_json_error(['message' => 'Template not found']);
    }

    // Get variables for this template
    $variables = $this->get_template_variable_help($template_key);
    
    // Build variables HTML
    $variables_html = '';
    if (!empty($variables)) {
      $variables_html .= '<p><strong>' . esc_html__('Available Variables - copy/paste or use the Merge Tags dropdown in the editor', 'cms') . '</strong></p><ul>';
      foreach ($variables as $key => $desc) {
        $variables_html .= '<li><code>{{' . esc_html($key) . '}}</code> - ' . esc_html($desc) . '</li>';
      }
      $variables_html .= '</ul>';
    }

    wp_send_json_success([
      'template' => [
        'template_key' => $template->template_key,
        'subject' => $template->subject,
        'body' => $template->body,
        'is_active' => $template->is_active,
      ],
      'context' => 'system',
      'editor_id' => $editor_id,
      'status_message' => '',
      'variables_html' => $variables_html,
      'variables' => $variables, // Add as object for TinyMCE button
    ]);
  }

  /**
   * AJAX handler to save system template
   */
  public function ajax_save_template()
  {
    check_ajax_referer('hmwevents_email_templates', 'ajax_nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Permission denied']);
    }

    $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';
    $subject = isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '';
    $body = isset($_POST['body']) ? wp_unslash($_POST['body']) : '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $repo = new EmailTemplateRepository();
    $saved = $repo->save([
      'template_key' => $template_key,
      'subject' => $subject,
      'body' => $body,
      'is_active' => $is_active,
    ]);

    if ($saved) {
      wp_send_json_success([
        'message' => 'Template saved successfully',
      ]);
    } else {
      wp_send_json_error(['message' => 'Failed to save template']);
    }
  }

  /**
   * AJAX handler to preview a rendered template using sample data.
   */
  public function ajax_preview_template()
  {
    check_ajax_referer('hmwevents_email_templates', 'nonce');

    $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';
    $subject = isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '';
    $body = isset($_POST['body']) ? wp_unslash($_POST['body']) : '';

    if (empty($template_key)) {
      wp_send_json_error(['message' => 'Template key is required']);
    }

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Permission denied']);
    }

    $repo = new EmailTemplateRepository();

    if ($subject === '' && $body === '') {
      $template = $repo->get_template(null, $template_key);

      if (!$template) {
        wp_send_json_error(['message' => 'Template not found']);
      }
    } else {
      $template = (object) [
        'subject' => $subject,
        'body' => $body,
      ];
    }

    // Check if using real booking data
    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    if ($booking_id > 0) {
      $variables = $this->get_booking_variables($booking_id, $template_key);
      if (!is_array($variables) || empty($variables)) {
        wp_send_json_error(['message' => 'Could not load booking data, using sample data instead']);
      }
    } else {
      $variables = $this->get_preview_variables($template_key);
    }

    $rendered = $repo->render($template, $variables);

    wp_send_json_success([
      'subject' => $rendered['subject'],
      'body' => $rendered['body'],
      'variables' => $variables,
    ]);
  }

  /**
   * AJAX handler to send test email for a template using sample data.
   */
  public function ajax_send_test_email()
  {
    check_ajax_referer('hmwevents_email_templates', 'nonce');

    $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';
    $subject = isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '';
    $body = isset($_POST['body']) ? wp_unslash($_POST['body']) : '';
    $recipient_email = isset($_POST['recipient_email']) ? sanitize_email($_POST['recipient_email']) : '';

    if (empty($template_key)) {
      wp_send_json_error(['message' => 'Template key is required']);
    }

    if (!is_email($recipient_email)) {
      wp_send_json_error(['message' => 'A valid test recipient email is required']);
    }

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Permission denied']);
    }

    $repo = new EmailTemplateRepository();

    if ($subject === '' && $body === '') {
      $template = $repo->get_template(null, $template_key);

      if (!$template) {
        wp_send_json_error(['message' => 'Template not found']);
      }
    } else {
      $template = (object) [
        'subject' => $subject,
        'body' => $body,
      ];
    }

    // Check if using real booking data
    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    if ($booking_id > 0) {
      $variables = $this->get_booking_variables($booking_id, $template_key);
      if (!is_array($variables) || empty($variables)) {
        wp_send_json_error(['message' => 'Could not load booking data']);
      }
    } else {
      $variables = $this->get_preview_variables($template_key);
    }

    $rendered = $repo->render($template, $variables);

    $headers = [
      'Content-Type: text/html; charset=UTF-8',
      'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>',
    ];

    $sent = wp_mail($recipient_email, $rendered['subject'], $rendered['body'], $headers);

    if (!$sent) {
      wp_send_json_error(['message' => 'Failed to send test email']);
    }

    wp_send_json_success([
      'message' => sprintf('Test email sent to %s', $recipient_email),
    ]);
  }

  /**
   * AJAX handler to fetch available bookings for autocomplete.
   */
  public function ajax_get_available_bookings()
  {
    check_ajax_referer('hmwevents_email_templates', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Permission denied']);
    }

    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

    global $wpdb;

    $query = "SELECT b.id, b.booking_number, c.post_title as course_name, 
                     cu.post_title as customer_name, b.created_at
              FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " b
              INNER JOIN {$wpdb->posts} c ON b.event_post_id = c.ID
              INNER JOIN {$wpdb->posts} cu ON b.registrant_post_id = cu.ID
              WHERE b.deleted_at IS NULL";

    $params = [];

    if ($search) {
      $query .= " AND (b.booking_number LIKE %s OR cu.post_title LIKE %s OR c.post_title LIKE %s)";
      $search_term = '%' . $wpdb->esc_like($search) . '%';
      $params[] = $search_term;
      $params[] = $search_term;
      $params[] = $search_term;
    }

    $query .= " ORDER BY b.created_at DESC LIMIT 20";

    if (!empty($params)) {
      $bookings = $wpdb->get_results($wpdb->prepare($query, ...$params));
    } else {
      $bookings = $wpdb->get_results($query);
    }

    $results = [];
    if (!empty($bookings)) {
      foreach ($bookings as $booking) {
        $results[] = [
          'id' => $booking->id,
          'text' => sprintf('%s - %s (%s)', $booking->booking_number, $booking->customer_name, $booking->course_name),
        ];
      }
    }

    wp_send_json_success($results);
  }

  /**
   * Build sample variables for preview/test rendering.
   *
   * @param string $template_key
   * @return array
   */
  private function get_preview_variables($template_key)
  {
    $course_location = '123 Calm Street, Brisbane QLD 4000 - (Sample)';

    $sample_values = [
      'mothers_first_name' => 'Jane',
      'mothers_last_name'  => 'Smith',
      'email'              => 'jane.smith@example.com',
      'phone'              => '0400 000 000',
    ];
    $sample_fields = [];
    foreach (\HMWEvents\Registry\RegistrationFieldRegistry::for_email() as $key => $field) {
      $val = $sample_values[$key] ?? '';
      if ($val !== '') {
        $sample_fields[$field['label']] = $val;
      }
    }
    $sample_rows = '';
    foreach ($sample_fields as $label => $val) {
      $sample_rows .= '<tr>'
        . '<td style="padding:6px 12px 6px 0;font-weight:bold;vertical-align:top;white-space:nowrap;">' . esc_html($label) . '</td>'
        . '<td style="padding:6px 0;vertical-align:top;">' . esc_html($val) . '</td>'
        . '</tr>';
    }
    $sample_all_fields = '<table style="border-collapse:collapse;width:100%;">' . $sample_rows . '</table>';

    $base = [
      'customer_name'     => 'Jane Smith',
      'registrant_email'  => 'jane.smith@example.com',
      'organiser_name'    => 'Sarah Smith',
      'organiser_email'   => 'sarah@example.com',
      'event_title'       => 'Calmbirth Weekend Intensive - (Sample Title)',
      'event_date'        => 'Saturday, June 15, 2026 - (Sample)',
      'event_start_time'  => '9:00 AM (Sample)',
      'event_location'    => $course_location,
      'booking_number'    => 'CB-2026-00123',
      'get_directions'    => "<a href='https://www.google.com/maps/dir/?api=1&destination=" . rawurlencode($course_location) . "' target='_blank'>Get Directions</a>",
      'all_fields'        => $sample_all_fields,
    ];

    $specific = [
      'booking_confirmation' => [
        'booking_amount' => '$840.00',
      ],
      'course_reminder' => [
        'days_until'     => '7',
      ],
      'post_course_feedback' => [
        'days_after' => '1',
      ],
      'post_course_followup' => [
        'days_after' => '7',
      ],
      'booking_cancelled' => [
        'reason' => 'Schedule conflict',
      ],
      'booking_confirmed' => [
        'amount' => '$840.00',
        'status_change' => 'booking_confirmed',
      ],
      'payment_received' => [
        'amount' => '$420.00',
        'status_change' => 'payment_received',
      ],
      'refund_issued' => [
        'amount' => '$120.00',
        'refund_amount' => '$120.00',
        'status_change' => 'refund_issued',
      ],
      'course_changed' => [
        'status_change' => 'course_changed',
        'changed_fields' => 'Start time moved from 9:00 AM (Sample) to 9:30 AM (Sample)',
        'old_values' => '{"event_start_time":"9:00 AM (Sample)"}',
        'new_course_date' => 'Sunday, June 16, 2026 (Sample)',
      ],
      'pending_payment_link' => [
        'amount_due' => '420.00',
        'payment_url' => home_url('/payment-resume?token=previewtoken123'),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
      ],
      'remaining_payment_link' => [
        'amount_due' => '420.00',
        'payment_url' => home_url('/payment-resume?token=previewtoken123'),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
      ],
      'course_updated' => [
        'status_change' => 'course_updated',
      ],
    ];

    return array_merge($base, $specific[$template_key] ?? []);
  }
}