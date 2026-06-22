<?php

namespace HMWEvents\Admin;

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
    add_action('admin_post_hmwevents_educator_email_template_save', [$this, 'handle_educator_save']);
    add_action('admin_post_hmwevents_educator_email_template_reset', [$this, 'handle_educator_reset']);
    add_action('admin_post_hmwevents_educator_email_template_regenerate_defaults', [$this, 'handle_educator_regenerate_defaults']);
    add_action('admin_post_hmwevents_save_locked_templates', [$this, 'handle_save_locked_templates']);
    add_action('show_user_profile', [$this, 'render_educator_profile_section']);
    add_action('edit_user_profile', [$this, 'render_educator_profile_section']);

    // AJAX handlers
    add_action('wp_ajax_hmwevents_load_email_template', [$this, 'ajax_load_template']);
    add_action('wp_ajax_hmwevents_save_email_template_ajax', [$this, 'ajax_save_template']);
    add_action('wp_ajax_hmwevents_save_educator_email_template_ajax', [$this, 'ajax_save_educator_template']);
    add_action('wp_ajax_hmwevents_reset_educator_email_template_ajax', [$this, 'ajax_reset_educator_template']);
    add_action('wp_ajax_hmwevents_regenerate_educator_templates_ajax', [$this, 'ajax_regenerate_educator_templates']);
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

        <hr style="margin: 30px 0;">
        <h2><?php esc_html_e('Educator Template Permissions', 'cms'); ?></h2>
        <p><?php esc_html_e('Locked templates are hidden from educator profiles and cannot be customised. Educators will always receive the system version.', 'cms'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="hmwevents_save_locked_templates">
          <?php wp_nonce_field('hmwevents_save_locked_templates'); ?>
          <table class="wp-list-table widefat fixed striped" style="max-width: 600px;">
            <thead>
              <tr>
                <th><?php esc_html_e('Template Key', 'cms'); ?></th>
                <th style="width: 180px; text-align: center;"><?php esc_html_e('Locked (educators cannot customise)', 'cms'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php $locked_templates = self::get_educator_locked_templates(); ?>
              <?php foreach ($templates as $tpl): ?>
              <tr>
                <td><code><?php echo esc_html($tpl->template_key); ?></code></td>
                <td style="text-align: center;">
                  <input type="checkbox" name="hmwevents_locked_templates[]" value="<?php echo esc_attr($tpl->template_key); ?>" <?php checked(in_array($tpl->template_key, $locked_templates, true)); ?>>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p>
            <button type="submit" class="button button-primary"><?php esc_html_e('Save Permissions', 'cms'); ?></button>
          </p>
        </form>
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
   * Render the template form (shared between system and educator templates)
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
      'status_message' => '',
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
   * Render educator email templates in user profile
   *
   * @param \WP_User $user User object
   */
  public function render_educator_profile_section($user)
  {
    if (!$user || !$this->is_educator_user($user) || !current_user_can('edit_user', $user->ID)) {
      return;
    }

    $template_repo = new EmailTemplateRepository();
    $system_templates = $template_repo->get_system_templates();
    $educator_templates = $template_repo->get_educator_templates($user->ID);

    if (empty($system_templates)) {
      ?>
      <h2><?php esc_html_e('Email Templates', 'cms'); ?></h2>
      <p><?php esc_html_e('No system templates found. Please ask an administrator to create default templates.', 'cms'); ?></p>
      <?php
      return;
    }

    $educator_templates_by_key = [];
    foreach ($educator_templates as $template) {
      $educator_templates_by_key[$template->template_key] = $template;
    }

    $selected_key = isset($_GET['hmwevents_template_key']) ? sanitize_text_field($_GET['hmwevents_template_key']) : $system_templates[0]->template_key;
    $selected_system_template = null;

    foreach ($system_templates as $template) {
      if ($template->template_key === $selected_key) {
        $selected_system_template = $template;
        break;
      }
    }

    if (!$selected_system_template) {
      $selected_system_template = $system_templates[0];
      $selected_key = $selected_system_template->template_key;
    }

    $selected_educator_template = $educator_templates_by_key[$selected_key] ?? null;
    $active_template = $selected_educator_template ?: $selected_system_template;

    ob_start();
    ?>
    <div class="hmwevents-educator-templates">
      <h2><?php esc_html_e('Email Templates', 'cms'); ?></h2>
      <p><?php esc_html_e('Customize your email templates. If you don\'t create an override, the system default will be used.', 'cms'); ?></p>

      <div class="hmwevents-template-selector" style="margin-bottom: 12px;">
        <input type="hidden" name="user_id" value="<?php echo intval($user->ID); ?>" class="hmwevents-template-user-id">
        <select name="hmwevents_template_key" class="hmwevents-template-selector-dropdown">
          <?php foreach ($system_templates as $template): ?>
            <?php if (in_array($template->template_key, self::get_educator_locked_templates(), true)) continue; ?>
            <?php $suffix = isset($educator_templates_by_key[$template->template_key]) ? ' (customized)' : ''; ?>
            <option value="<?php echo esc_attr($template->template_key); ?>" <?php selected($template->template_key, $selected_key); ?>>
              <?php echo esc_html($template->template_key . $suffix); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="button hmwevents-template-load-btn"><?php esc_html_e('Load', 'cms'); ?></button>
      </div>

      <div class="hmwevents-email-template-editor">
        <?php
    // Prepare status message
    if ($selected_educator_template) {
      $status_message = '<strong>' . esc_html__('Status:', 'cms') . '</strong> ' . esc_html__('Using your customized template.', 'cms');
    } else {
      $status_message = '<strong>' . esc_html__('Status:', 'cms') . '</strong> ' . esc_html__('Using system default. Save below to create your own override.', 'cms');
    }

    // Render the form using shared method
    $this->render_template_form([
      'template'       => $active_template,
      'form_action'    => 'hmwevents_educator_email_template_save',
      'nonce_action'   => 'hmwevents_educator_email_template_save',
      'hidden_fields'  => [
        'user_id'      => $user->ID,
        'template_key' => $selected_key,
      ],
      'editor_id'      => 'educator_email_template_body_' . $user->ID . '_' . sanitize_key($selected_key),
      'editor_rows'    => 15,
      'submit_text'    => __('Save Email Template', 'cms'),
      'status_message' => $status_message,
    ]);

    if ($selected_educator_template): ?>
      <div class="hmwevents-template-reset-wrap" style="margin-top: 8px;">
        <input type="hidden" class="hmwevents-reset-user-id" value="<?php echo intval($user->ID); ?>">
        <input type="hidden" class="hmwevents-reset-template-key" value="<?php echo esc_attr($selected_key); ?>">
        <?php wp_nonce_field('hmwevents_educator_email_template_reset', '_hmwevents_reset_nonce'); ?>
        <button type="button" class="button hmwevents-reset-educator-template-btn"><?php esc_html_e('Reset to System Default', 'cms'); ?></button>
      </div>
    <?php endif; ?>

      <div class="hmwevents-template-regenerate-wrap" style="margin-top: 8px;"
           data-user-id="<?php echo intval($user->ID); ?>">
        <?php wp_nonce_field('hmwevents_email_templates', '_hmwevents_regenerate_nonce'); ?>
        <button type="button" class="button button-secondary hmwevents-regenerate-educator-templates-btn"><?php esc_html_e('Re-generate All Defaults (Overwrite Existing)', 'cms'); ?></button>
      </div>
      </div>
    </div>
    <?php
    echo ob_get_clean();
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
      set_transient('lhmwevents_success_notice', 'Template saved.', 30);
    } else {
      set_transient('lhmwevents_error_notice', 'Failed to save template.', 30);
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

    set_transient('lhmwevents_success_notice', 'Default templates created.', 30);
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

    set_transient('lhmwevents_success_notice', 'Default templates re-generated and existing system templates were overwritten.', 30);
    wp_safe_redirect(admin_url('admin.php?page=hmwevents-email-templates'));
    exit;
  }

  /**
   * Handle regenerating all default templates for a specific educator
   */
  public function handle_educator_regenerate_defaults()
  {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if (!$user_id || !current_user_can('edit_user', $user_id)) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    check_admin_referer('hmwevents_educator_email_template_regenerate_defaults');

    $user = get_userdata($user_id);
    if (!$user || !$this->is_educator_user($user)) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $service = new EmailService();
    $service->create_educator_default_templates($user_id);

    set_transient('lhmwevents_success_notice', 'Default templates re-generated for this educator.', 30);
    wp_safe_redirect(add_query_arg(['user_id' => $user_id], admin_url('user-edit.php')));
    exit;
  }

  /**
   * Handle saving the educator template locked list.
   */
  public function handle_save_locked_templates()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    check_admin_referer('hmwevents_save_locked_templates');

    $locked = isset($_POST['hmwevents_locked_templates']) && is_array($_POST['hmwevents_locked_templates'])
      ? array_map('sanitize_text_field', $_POST['hmwevents_locked_templates'])
      : [];

    update_option('hmwevents_educator_locked_templates', $locked);

    set_transient('lhmwevents_success_notice', 'Educator template permissions saved.', 30);
    wp_safe_redirect(admin_url('admin.php?page=hmwevents-email-templates'));
    exit;
  }

  /**
   * Handle saving educator email template override
   */
  public function handle_educator_save()
  {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if (!$user_id || !current_user_can('edit_user', $user_id)) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    check_admin_referer('hmwevents_educator_email_template_save');

    $user = get_userdata($user_id);
    if (!$user || !$this->is_educator_user($user)) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';

    if (in_array($template_key, self::get_educator_locked_templates(), true)) {
      wp_die(__('This template cannot be customised.'));
    }

    $subject = isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '';
    $body = isset($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $repo = new EmailTemplateRepository();
    $saved = $repo->save([
      'educator_id'  => $user_id,
      'template_key' => $template_key,
      'subject'      => $subject,
      'body'         => $body,
      'is_active'    => $is_active,
    ]);

    if ($saved) {
      set_transient('lhmwevents_success_notice', 'Template saved.', 30);
    } else {
      set_transient('lhmwevents_error_notice', 'Failed to save template.', 30);
    }

    $redirect = add_query_arg(
      ['hmwevents_template_key' => $template_key],
      get_edit_user_link($user_id)
    );
    wp_safe_redirect($redirect);
    exit;
  }

  /**
   * Handle resetting educator email template override
   */
  public function handle_educator_reset()
  {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if (!$user_id || !current_user_can('edit_user', $user_id)) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    check_admin_referer('hmwevents_educator_email_template_reset');

    $user = get_userdata($user_id);
    if (!$user || !$this->is_educator_user($user)) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';

    if (in_array($template_key, self::get_educator_locked_templates(), true)) {
      wp_die(__('This template cannot be reset.'));
    }

    $repo = new EmailTemplateRepository();
    $educator_templates = $repo->get_educator_templates($user_id);

    $deleted = false;
    foreach ($educator_templates as $template) {
      if ($template->template_key === $template_key) {
        $deleted = $repo->delete($template->id);
        break;
      }
    }

    if ($deleted) {
      set_transient('lhmwevents_success_notice', 'Template reset to system default.', 30);
    } else {
      set_transient('lhmwevents_error_notice', 'Failed to reset template.', 30);
    }

    $redirect = add_query_arg(
      ['hmwevents_template_key' => $template_key],
      get_edit_user_link($user_id)
    );
    wp_safe_redirect($redirect);
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
      'educator_new_booking'  => 'educator_new_booking',
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
   * @param string $context
   * @param int $user_id
   * @return array|false
   */
  private function get_booking_variables($booking_id, $template_key, $context, $user_id)
  {
    global $wpdb;

    // Security check: verify access
    if ($context === 'educator' && $user_id) {
      // Educator must own the course
      $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, c.post_author
         FROM {$wpdb->prefix}educator_bookings b
         INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
         WHERE b.id = %d AND c.post_author = %d AND b.deleted_at IS NULL",
        $booking_id,
        $user_id
      ));
    } else {
      // Admin sees all
      $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT b.* FROM {$wpdb->prefix}educator_bookings b
         WHERE b.id = %d AND b.deleted_at IS NULL",
        $booking_id
      ));
    }

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
      'educator_new_booking'   => 'educator_new_booking',
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
   * Check if user has educator role
   *
   * @param \WP_User $user
   * @return bool
   */
  private function is_educator_user($user)
  {
    return in_array('educator', (array) $user->roles, true);
  }

  /**
   * Return the list of template keys educators are not allowed to customise.
   *
   * Reads from the 'hmwevents_educator_locked_templates' option. Falls back to a
   * sensible default when the option has never been saved.
   *
   * @return string[]
   */
  private static function get_educator_locked_templates(): array
  {
    $saved = get_option('hmwevents_educator_locked_templates', null);

    if ($saved === null) {
      // Default: lock templates that educators should never need to change.
      return ['post_course_followup', 'pending_payment_link', 'educator_new_booking'];
    }

    return is_array($saved) ? $saved : [];
  }

  /**
   * AJAX handler to load a template
   */
  public function ajax_load_template()
  {
    check_ajax_referer('hmwevents_email_templates', 'nonce');

    $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';
    $context = isset($_POST['context']) ? sanitize_text_field($_POST['context']) : 'system';
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

    if (empty($template_key)) {
      wp_send_json_error(['message' => 'Template key is required']);
    }

    $template_repo = new EmailTemplateRepository();
    $template = null;

    if ($context === 'educator' && $user_id > 0) {
      // Check permissions
      if (!current_user_can('edit_user', $user_id)) {
        wp_send_json_error(['message' => 'Permission denied']);
      }

      if (in_array($template_key, self::get_educator_locked_templates(), true)) {
        wp_send_json_error(['message' => 'Permission denied']);
      }

      // Try to get educator override first
      $educator_templates = $template_repo->get_educator_templates($user_id);
      foreach ($educator_templates as $t) {
        if ($t->template_key === $template_key) {
          $template = $t;
          break;
        }
      }

      // Fall back to system template
      if (!$template) {
        $system_templates = $template_repo->get_system_templates();
        foreach ($system_templates as $t) {
          if ($t->template_key === $template_key) {
            $template = $t;
            break;
          }
        }
      }

      $editor_id = 'educator_email_template_body_' . $user_id . '_' . sanitize_key($template_key);
      $is_customized = !empty($educator_templates) && isset($educator_templates[0]) && $educator_templates[0]->template_key === $template_key;
      
      $status_message = $is_customized 
        ? '<strong>' . esc_html__('Status:', 'cms') . '</strong> ' . esc_html__('Using your customized template.', 'cms')
        : '<strong>' . esc_html__('Status:', 'cms') . '</strong> ' . esc_html__('Using system default. Save below to create your own override.', 'cms');
    } else {
      // Check permissions
      if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
      }

      // Get system template
      $system_templates = $template_repo->get_system_templates();
      foreach ($system_templates as $t) {
        if ($t->template_key === $template_key) {
          $template = $t;
          break;
        }
      }

      $editor_id = 'email_template_body';
      $status_message = '';
    }

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
      'context' => $context,
      'user_id' => $user_id,
      'editor_id' => $context === 'educator' ? 'educator_email_template_body_' . $user_id . '_' . sanitize_key($template_key) : 'email_template_body',
      'status_message' => $context === 'educator' ? $status_message : '',
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
   * AJAX handler to save educator template
   */
  public function ajax_save_educator_template()
  {
    check_ajax_referer('hmwevents_email_templates', 'ajax_nonce');

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    
    if (!$user_id || !current_user_can('edit_user', $user_id)) {
      wp_send_json_error(['message' => 'Permission denied']);
    }

    $user = get_userdata($user_id);
    if (!$user || !$this->is_educator_user($user)) {
      wp_send_json_error(['message' => 'Invalid user']);
    }

    $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';

    if (in_array($template_key, self::get_educator_locked_templates(), true)) {
      wp_send_json_error(['message' => 'This template cannot be customised.']);
    }

    $subject = isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '';
    $body = isset($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $repo = new EmailTemplateRepository();
    $saved = $repo->save([
      'educator_id'  => $user_id,
      'template_key' => $template_key,
      'subject'      => $subject,
      'body'         => $body,
      'is_active'    => $is_active,
    ]);

    if ($saved) {
      $status_message = '<strong>' . esc_html__('Status:', 'cms') . '</strong> ' . esc_html__('Using your customized template.', 'cms');
      
      wp_send_json_success([
        'message' => 'Template saved successfully',
        'status_message' => $status_message,
      ]);
    } else {
      wp_send_json_error(['message' => 'Failed to save template']);
    }
  }

  /**
   * AJAX handler to regenerate all educator templates from system defaults.
   */
  public function ajax_regenerate_educator_templates()
  {
    check_ajax_referer('hmwevents_email_templates', 'ajax_nonce');

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

    if (!$user_id || !current_user_can('edit_user', $user_id)) {
      wp_send_json_error(['message' => 'Permission denied']);
    }

    $user = get_userdata($user_id);
    if (!$user || !$this->is_educator_user($user)) {
      wp_send_json_error(['message' => 'Invalid user']);
    }

    $service = new EmailService();
    $service->create_educator_default_templates($user_id);

    wp_send_json_success([
      'message' => 'All templates re-generated from system defaults.',
    ]);
  }

  /**
   * AJAX handler to reset educator template
   */
  public function ajax_reset_educator_template()
  {
    check_ajax_referer('hmwevents_email_templates', 'ajax_nonce');

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    
    if (!$user_id || !current_user_can('edit_user', $user_id)) {
      wp_send_json_error(['message' => 'Permission denied']);
    }

    $user = get_userdata($user_id);
    if (!$user || !$this->is_educator_user($user)) {
      wp_send_json_error(['message' => 'Invalid user']);
    }

    $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';

    if (in_array($template_key, self::get_educator_locked_templates(), true)) {
      wp_send_json_error(['message' => 'This template cannot be reset.']);
    }

    $repo = new EmailTemplateRepository();
    $educator_templates = $repo->get_educator_templates($user_id);

    $deleted = false;
    foreach ($educator_templates as $template) {
      if ($template->template_key === $template_key) {
        $deleted = $repo->delete($template->id);
        break;
      }
    }

    if ($deleted) {
      wp_send_json_success([
        'message' => 'Template reset to system default successfully',
      ]);
    } else {
      wp_send_json_error(['message' => 'Failed to reset template']);
    }
  }

  /**
   * AJAX handler to preview a rendered template using sample data.
   */
  public function ajax_preview_template()
  {
    check_ajax_referer('hmwevents_email_templates', 'nonce');

    $template_key = isset($_POST['template_key']) ? sanitize_text_field($_POST['template_key']) : '';
    $context = isset($_POST['context']) ? sanitize_text_field($_POST['context']) : 'system';
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $subject = isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '';
    $body = isset($_POST['body']) ? wp_unslash($_POST['body']) : '';

    if (empty($template_key)) {
      wp_send_json_error(['message' => 'Template key is required']);
    }

    if ($context === 'educator') {
      if (!$user_id || !current_user_can('edit_user', $user_id)) {
        wp_send_json_error(['message' => 'Permission denied']);
      }
    } else {
      if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
      }
    }

    $repo = new EmailTemplateRepository();

    if ($subject === '' && $body === '') {
      $educator_id = ($context === 'educator' && $user_id > 0) ? $user_id : null;
      $template = $repo->get_template($educator_id, $template_key);

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
      $variables = $this->get_booking_variables($booking_id, $template_key, $context, $user_id);
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
    $context = isset($_POST['context']) ? sanitize_text_field($_POST['context']) : 'system';
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $subject = isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '';
    $body = isset($_POST['body']) ? wp_unslash($_POST['body']) : '';
    $recipient_email = isset($_POST['recipient_email']) ? sanitize_email($_POST['recipient_email']) : '';

    if (empty($template_key)) {
      wp_send_json_error(['message' => 'Template key is required']);
    }

    if (!is_email($recipient_email)) {
      wp_send_json_error(['message' => 'A valid test recipient email is required']);
    }

    if ($context === 'educator') {
      if (!$user_id || !current_user_can('edit_user', $user_id)) {
        wp_send_json_error(['message' => 'Permission denied']);
      }
    } else {
      if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
      }
    }

    $repo = new EmailTemplateRepository();

    if ($subject === '' && $body === '') {
      $educator_id = ($context === 'educator' && $user_id > 0) ? $user_id : null;
      $template = $repo->get_template($educator_id, $template_key);

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
      $variables = $this->get_booking_variables($booking_id, $template_key, $context, $user_id);
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

    $context = isset($_POST['context']) ? sanitize_text_field($_POST['context']) : 'system';
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

    // Determine who can view bookings
    if ($context === 'educator') {
      if (!$user_id || !current_user_can('edit_user', $user_id)) {
        wp_send_json_error(['message' => 'Permission denied']);
      }
      $educator_id = $user_id;
    } else {
      if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
      }
      $educator_id = null; // Admin sees all
    }

    global $wpdb;

    $query = "SELECT b.id, b.booking_number, c.post_title as course_name, 
                     cu.post_title as customer_name, b.created_at
              FROM {$wpdb->prefix}educator_bookings b
              INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
              INNER JOIN {$wpdb->posts} cu ON b.customer_post_id = cu.ID
              WHERE b.deleted_at IS NULL";

    $params = [];

    if ($educator_id) {
      $query .= " AND c.post_author = %d";
      $params[] = $educator_id;
    }

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
    $free_audio_url  = ConfigHelper::get_option('hmwevents_free_pre_course_audio_url', '');

    $sample_values = [
      'mothers_first_name' => 'Jane',
      'mothers_last_name'  => 'Smith',
      'email'              => 'jane.smith@example.com',
      'phone'              => '0400 000 000',
      'partner_name'       => 'John Smith',
      'due_date'           => 'August 2026',
      'first_baby'         => 'Yes',
      'health_fund'        => 'Medibank',
    ];
    $sample_fields = [];
    foreach (\HMWEvents\Config\BookingFields::for_email() as $key => $field) {
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
      'customer_name'               => 'Jane Smith',
      'customer_email'              => 'jane.smith@example.com',
      'course_name'                 => 'Calmbirth Weekend Intensive - (Sample Title)',
      'course_date'                 => 'Saturday, June 15, 2026 - (Sample)',
      'booking_number'              => 'CB-2026-00123',
      'course_location'             => $course_location,
      'get_directions'              => "<a href='https://www.google.com/maps/dir/?api=1&destination=" . rawurlencode($course_location) . "' target='_blank'>Get Directions</a>",
      'download_audio_track'         => "<a href='" . ConfigHelper::get_option('hmwevents_audio_download_url', '') . "' target='_blank'>Download Audio Track</a>",
      'download_relaxation_track'   => "<a href='" . $free_audio_url . "' target='_blank'>Download Relaxation Track</a>",
      'feedback_survey'             => "<a href='" . ConfigHelper::get_option('hmwevents_feedback_form_url', 'https://example.com/feedback') . "' target='_blank'>Take the educator and course feedback survey</a>",
      'all_fields'                  => $sample_all_fields,
    ];

    $specific = [
      'booking_confirmation' => [
        'booking_amount'    => '$840.00',
        'course_start_time' => '9:00 AM (Sample)',
      ],
      'course_reminder' => [
        'days_until'        => '7',
        'course_start_time' => '9:00 AM (Sample)',
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
        'old_values' => '{"course_start_time":"9:00 AM (Sample)"}',
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