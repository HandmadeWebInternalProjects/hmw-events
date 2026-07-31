<?php

namespace HMWEvents\Admin;

use HMWEvents\Services\Emails\EmailQueueRepository;
use HMWEvents\Services\Emails\EmailService;

/**
 * Email Queue Admin Handler
 *
 * @package HMWEvents\Admin
 */
class EmailQueue
{
  /**
   * Constructor
   */
  public function __construct()
  {
    add_action('admin_post_hmwevents_email_retry', [$this, 'handle_retry']);
    add_action('admin_post_hmwevents_email_send_now', [$this, 'handle_send_now']);
  }

  /**
   * Render the email queue page
   */
  public function render_page()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hmwevents_email_settings'])) {
      check_admin_referer('hmwevents_email_settings');
      $to_disable = array_keys($_POST['disable'] ?? []);
      update_option('hmwevents_disabled_emails', $to_disable);
      echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Email settings saved.', 'hmw-events') . '</p></div>';
    }

    $queue_repo = new EmailQueueRepository();
    $stats = $queue_repo->get_stats();

    // Get filter parameters
    $current_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
    $current_recipient = isset($_GET['filter_recipient']) ? sanitize_text_field($_GET['filter_recipient']) : '';
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 20;

    // Get filtered results
    $results = $queue_repo->get_filtered([
      'status'    => $current_status,
      'recipient' => $current_recipient,
      'per_page'  => $per_page,
      'page'      => $current_page,
    ]);

    $emails = $results['emails'];
    $total_items = $results['total'];
    $total_pages = ceil($total_items / $per_page);

    ob_start();
    ?>
    <div class="wrap hmwevents-email-queue-wrap">
      <h1><?php esc_html_e('Email Queue', 'cms'); ?></h1>

      <?php
      $disabled = get_option('hmwevents_disabled_emails', []);
      $all_types = [
        'booking_confirmation' => __('Booking Confirmation (Customer)', 'hmw-events'),
        'new_booking_notify'   => __('New Booking Notification (Admin)', 'hmw-events'),
        'payment_received'     => __('Payment Receipt', 'hmw-events'),
        'booking_cancelled'    => __('Booking Cancelled', 'hmw-events'),
        'reminder_7_days'      => __('7-Day Reminder', 'hmw-events'),
        'reminder_1_day'       => __('1-Day Reminder', 'hmw-events'),
        'post_event'           => __('Post-Event Follow-up', 'hmw-events'),
        'invitation_sent'      => __('Waitlist Invitation', 'hmw-events'),
      ];
      ?>
      <div class="hmwevents-email-settings card" style="margin-bottom: 20px; padding: 15px 20px; max-width: none;">
        <form method="post">
          <?php wp_nonce_field('hmwevents_email_settings'); ?>
          <input type="hidden" name="hmwevents_email_settings" value="1">
          <h2 style="margin-top: 0;"><?php esc_html_e('Notification Settings', 'hmw-events'); ?></h2>
          <p class="description"><?php esc_html_e('Disable email types you do not want to send. Disabled emails will not be queued.', 'hmw-events'); ?></p>
          <table class="form-table">
            <?php foreach ($all_types as $type => $label): ?>
              <tr>
                <th scope="row"><?php echo esc_html($label); ?></th>
                <td>
                  <label>
                    <input type="checkbox" name="disable[<?php echo esc_attr($type); ?>]" value="1"
                      <?php checked(in_array($type, $disabled, true)); ?>>
                    <?php esc_html_e('Disable', 'hmw-events'); ?>
                  </label>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
          <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'hmw-events'); ?></button>
          </p>
        </form>
      </div>

      <!-- Stats Dashboard -->
      <div class="hmwevents-email-dashboard">
        <a href="<?php echo esc_url(remove_query_arg(['filter_status', 'paged'])); ?>" 
           class="hmwevents-email-card <?php echo empty($current_status) ? 'active' : ''; ?>">
          <h3><?php esc_html_e('All', 'cms'); ?></h3>
          <div class="stat"><?php echo intval($stats->total ?? 0); ?></div>
        </a>
        <a href="<?php echo esc_url(add_query_arg(['filter_status' => 'pending', 'paged' => 1])); ?>" 
           class="hmwevents-email-card status-pending <?php echo $current_status === 'pending' ? 'active' : ''; ?>">
          <h3><?php esc_html_e('Pending', 'cms'); ?></h3>
          <div class="stat"><?php echo intval($stats->pending ?? 0); ?></div>
        </a>
        <a href="<?php echo esc_url(add_query_arg(['filter_status' => 'processing', 'paged' => 1])); ?>" 
           class="hmwevents-email-card status-processing <?php echo $current_status === 'processing' ? 'active' : ''; ?>">
          <h3><?php esc_html_e('Processing', 'cms'); ?></h3>
          <div class="stat"><?php echo intval($stats->processing ?? 0); ?></div>
        </a>
        <a href="<?php echo esc_url(add_query_arg(['filter_status' => 'sent', 'paged' => 1])); ?>" 
           class="hmwevents-email-card status-sent <?php echo $current_status === 'sent' ? 'active' : ''; ?>">
          <h3><?php esc_html_e('Sent', 'cms'); ?></h3>
          <div class="stat"><?php echo intval($stats->sent ?? 0); ?></div>
        </a>
        <a href="<?php echo esc_url(add_query_arg(['filter_status' => 'dead_letter', 'paged' => 1])); ?>" 
           class="hmwevents-email-card status-dead_letter <?php echo $current_status === 'dead_letter' ? 'active' : ''; ?>">
          <h3><?php esc_html_e('Dead Letter', 'cms'); ?></h3>
          <div class="stat"><?php echo intval($stats->dead_letter ?? 0); ?></div>
        </a>
        <a href="<?php echo esc_url(add_query_arg(['filter_status' => 'cancelled', 'paged' => 1])); ?>" 
           class="hmwevents-email-card status-cancelled <?php echo $current_status === 'cancelled' ? 'active' : ''; ?>">
          <h3><?php esc_html_e('Cancelled', 'cms'); ?></h3>
          <div class="stat"><?php echo intval($stats->cancelled ?? 0); ?></div>
        </a>
      </div>

      <!-- Filters -->
      <div class="hmwevents-email-filters">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="hmwevents-filter-form">
          <input type="hidden" name="page" value="hmwevents-email-queue">
          <?php if (!empty($current_status)): ?>
            <input type="hidden" name="filter_status" value="<?php echo esc_attr($current_status); ?>">
          <?php endif; ?>
          
          <div class="filter-group">
            <label for="filter_recipient"><?php esc_html_e('Filter by Recipient:', 'cms'); ?></label>
            <input 
              type="text" 
              id="filter_recipient" 
              name="filter_recipient" 
              value="<?php echo esc_attr($current_recipient); ?>"
              placeholder="<?php esc_attr_e('Enter email address...', 'cms'); ?>"
              class="regular-text">
            <button type="submit" class="button"><?php esc_html_e('Filter', 'cms'); ?></button>
            <?php if (!empty($current_recipient) || !empty($current_status)): ?>
              <a href="<?php echo esc_url(remove_query_arg(['filter_status', 'filter_recipient', 'paged'])); ?>" 
                 class="button"><?php esc_html_e('Clear Filters', 'cms'); ?></a>
            <?php endif; ?>
          </div>
        </form>

        <div class="results-info">
          <?php
          $start = ($current_page - 1) * $per_page + 1;
          $end = min($current_page * $per_page, $total_items);
          /* translators: %1$d: start number, %2$d: end number, %3$d: total number */
          printf(
            esc_html__('Showing %1$d-%2$d of %3$d emails', 'cms'),
            $start,
            $end,
            $total_items
          );
          ?>
        </div>
      </div>

      <!-- Email Table -->
      <table class="wp-list-table widefat fixed striped hmwevents-email-table">
        <thead>
          <tr>
            <th class="column-id"><?php esc_html_e('ID', 'cms'); ?></th>
            <th class="column-status"><?php esc_html_e('Status', 'cms'); ?></th>
            <th class="column-type"><?php esc_html_e('Type', 'cms'); ?></th>
            <th class="column-recipient"><?php esc_html_e('Recipient', 'cms'); ?></th>
            <th class="column-subject"><?php esc_html_e('Subject', 'cms'); ?></th>
            <th class="column-scheduled"><?php esc_html_e('Scheduled', 'cms'); ?></th>
            <th class="column-attempts"><?php esc_html_e('Attempts', 'cms'); ?></th>
            <th class="column-actions"><?php esc_html_e('Actions', 'cms'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($emails)): ?>
            <tr>
              <td colspan="8" class="no-items"><?php esc_html_e('No emails found.', 'cms'); ?></td>
            </tr>
          <?php else: ?>
            <?php foreach ($emails as $email): ?>
              <tr>
                <td class="column-id"><?php echo intval($email->id); ?></td>
                <td class="column-status">
                  <span class="badge badge-<?php echo esc_attr($email->status); ?>">
                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $email->status))); ?>
                  </span>
                </td>
                <td class="column-type"><?php echo esc_html($email->email_type); ?></td>
                <td class="column-recipient">
                  <a href="<?php echo esc_url(add_query_arg(['filter_recipient' => $email->recipient_email, 'paged' => 1])); ?>">
                    <?php echo esc_html($email->recipient_email); ?>
                  </a>
                </td>
                <td class="column-subject"><?php echo esc_html($email->subject); ?></td>
                <td class="column-scheduled">
                  <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($email->scheduled_at))); ?>
                </td>
                <td class="column-attempts">
                  <span class="attempts-info">
                    <?php echo intval($email->attempts); ?>/<?php echo intval($email->max_attempts); ?>
                  </span>
                </td>
                <td class="column-actions">
                  <div class="row-actions-wrapper">
                    <!-- Retry -->
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="inline-form">
                      <input type="hidden" name="action" value="hmwevents_email_retry">
                      <input type="hidden" name="email_id" value="<?php echo intval($email->id); ?>">
                      <?php wp_nonce_field('hmwevents_email_retry'); ?>
                      <button type="submit" class="button button-small" title="<?php esc_attr_e('Reset and retry', 'cms'); ?>">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Retry', 'cms'); ?>
                      </button>
                    </form>

                    <!-- Send Now -->
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="inline-form">
                      <input type="hidden" name="action" value="hmwevents_email_send_now">
                      <input type="hidden" name="email_id" value="<?php echo intval($email->id); ?>">
                      <?php wp_nonce_field('hmwevents_email_send_now'); ?>
                      <button type="submit" class="button button-primary button-small" title="<?php esc_attr_e('Send immediately', 'cms'); ?>">
                        <span class="dashicons dashicons-email"></span>
                        <?php esc_html_e('Send Now', 'cms'); ?>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom">
          <div class="tablenav-pages">
            <span class="displaying-num">
              <?php
              /* translators: %s: number of items */
              printf(
                _n('%s item', '%s items', $total_items, 'cms'),
                number_format_i18n($total_items)
              );
              ?>
            </span>
            <span class="pagination-links">
              <?php
              $base_url = add_query_arg(['page' => 'hmwevents-email-queue']);
              if (!empty($current_status)) {
                $base_url = add_query_arg('filter_status', $current_status, $base_url);
              }
              if (!empty($current_recipient)) {
                $base_url = add_query_arg('filter_recipient', $current_recipient, $base_url);
              }

              // First page
              if ($current_page > 1) {
                echo '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '">';
                echo '<span class="screen-reader-text">' . esc_html__('First page', 'cms') . '</span>';
                echo '<span aria-hidden="true">&laquo;</span>';
                echo '</a>';

                echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $current_page - 1, $base_url)) . '">';
                echo '<span class="screen-reader-text">' . esc_html__('Previous page', 'cms') . '</span>';
                echo '<span aria-hidden="true">&lsaquo;</span>';
                echo '</a>';
              } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
              }

              echo '<span class="paging-input">';
              echo '<label for="current-page-selector" class="screen-reader-text">' . esc_html__('Current Page', 'cms') . '</label>';
              echo '<input class="current-page" id="current-page-selector" type="text" name="paged" value="' . esc_attr($current_page) . '" size="' . strlen($total_pages) . '" aria-describedby="table-paging">';
              echo '<span class="tablenav-paging-text"> ' . esc_html__('of', 'cms') . ' <span class="total-pages">' . number_format_i18n($total_pages) . '</span></span>';
              echo '</span>';

              // Next page
              if ($current_page < $total_pages) {
                echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $current_page + 1, $base_url)) . '">';
                echo '<span class="screen-reader-text">' . esc_html__('Next page', 'cms') . '</span>';
                echo '<span aria-hidden="true">&rsaquo;</span>';
                echo '</a>';

                echo '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">';
                echo '<span class="screen-reader-text">' . esc_html__('Last page', 'cms') . '</span>';
                echo '<span aria-hidden="true">&raquo;</span>';
                echo '</a>';
              } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
              }
              ?>
            </span>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php
    echo ob_get_clean();
  }

  /**
   * Handle retry of queued email
   */
  public function handle_retry()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    check_admin_referer('hmwevents_email_retry');

    $email_id = isset($_POST['email_id']) ? intval($_POST['email_id']) : 0;
    $service = new EmailService();
    $result = $service->retry_email($email_id);

    if ($result) {
      set_transient('hmwevents_success_notice', 'Email reset for retry.', 30);
    } else {
      set_transient('hmwevents_error_notice', 'Failed to retry email.', 30);
    }

    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=hmwevents-email-queue'));
    exit;
  }

  /**
   * Handle sending queued email immediately
   */
  public function handle_send_now()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    check_admin_referer('hmwevents_email_send_now');

    $email_id = isset($_POST['email_id']) ? intval($_POST['email_id']) : 0;
    $service = new EmailService();
    $result = $service->resend_email($email_id);

    if ($result) {
      set_transient('hmwevents_success_notice', 'Email sent successfully.', 30);
    } else {
      set_transient('hmwevents_error_notice', 'Failed to send email.', 30);
    }

    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=hmwevents-email-queue'));
    exit;
  }
}