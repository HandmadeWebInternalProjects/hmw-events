<?php

/**
 * Email Dispatch Service.
 *
 * Manages the email queue (hmwevents_email_queue): enqueue, process,
 * retry, and dead-letter handling.
 *
 * Integrates with CommunicationTriggerMatrix and EmailTemplateManager
 * to resolve templates and render email content.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

use HMWEvents\Registry\CommunicationTriggerMatrix;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EmailDispatchService
{
    private string $queue_table;

    public function __construct()
    {
        $this->queue_table = DatabaseService::get_table_name('email_queue');
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('hmwevents_process_email_queue', [$this, 'process_queue']);
        add_action('hmwevents_cleanup_email_queue', [$this, 'cleanup_old']);
    }

    // ================================================================
    // ENQUEUE
    // ================================================================

    /**
     * Enqueue an email for delivery.
     *
     * @param array $data {
     *     booking_id, organizer_id, recipient_email, recipient_name,
     *     template_key, template_data, event_type, trigger,
     *     scheduled_at (optional, default now)
     * }
     * @return int Queue entry ID.
     */
    public function enqueue(array $data): int
    {
        global $wpdb;

        $template_key = $data['template_key'] ?? $data['trigger'] ?? '';
        $event_type   = $data['event_type'] ?? '';

        // Resolve template key via trigger matrix
        if (empty($template_key) && !empty($data['trigger'])) {
            $template_key = CommunicationTriggerMatrix::resolve($data['trigger'], $event_type);
        }

        $wpdb->insert($this->queue_table, [
            'booking_id'      => isset($data['booking_id']) ? (int) $data['booking_id'] : null,
            'organizer_id'    => isset($data['organizer_id']) ? (int) $data['organizer_id'] : null,
            'recipient_email' => sanitize_email($data['recipient_email']),
            'recipient_name'  => sanitize_text_field($data['recipient_name'] ?? ''),
            'email_type'      => sanitize_text_field($data['trigger'] ?? $template_key),
            'template_key'    => $template_key,
            'template_data'   => wp_json_encode($data['template_data'] ?? []),
            'status'          => 'pending',
            'attempts'        => 0,
            'max_attempts'    => 5,
            'scheduled_at'    => $data['scheduled_at'] ?? current_time('mysql'),
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ], ['%d','%d','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s']);

        return (int) $wpdb->insert_id;
    }

    /**
     * Enqueue multiple emails at once (batch).
     *
     * @param array[] $emails Array of email data arrays.
     * @return int[] IDs of enqueued emails.
     */
    public function enqueue_batch(array $emails): array
    {
        $ids = [];
        foreach ($emails as $email) {
            $ids[] = $this->enqueue($email);
        }
        return $ids;
    }

    // ================================================================
    // PROCESS
    // ================================================================

    /**
     * Process pending emails in the queue.
     *
     * @param int $limit Max emails to process per run.
     * @return array {sent: int, failed: int, skipped: int}
     */
    public function process_queue(int $limit = 20): array
    {
        global $wpdb;

        $pending = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->queue_table} 
             WHERE status = 'pending' 
             AND scheduled_at <= %s 
             AND attempts < max_attempts 
             ORDER BY scheduled_at ASC 
             LIMIT %d",
            current_time('mysql'),
            $limit
        ));

        $sent    = 0;
        $failed  = 0;
        $skipped = 0;

        foreach ($pending as $email) {
            // Mark as processing
            $wpdb->update(
                $this->queue_table,
                ['status' => 'processing', 'attempts' => $email->attempts + 1, 'updated_at' => current_time('mysql')],
                ['id' => $email->id],
                ['%s', '%d', '%s'],
                ['%d']
            );

            // Render template
            $template_data = json_decode($email->template_data, true) ?: [];
            $template_manager = new EmailTemplateManager();
            $rendered = $template_manager->get_rendered(
                (int) ($email->organizer_id ?? 0),
                $email->template_key,
                $template_data
            );

            if (!$rendered) {
                $skipped++;
                $wpdb->update(
                    $this->queue_table,
                    ['status' => 'pending', 'updated_at' => current_time('mysql')],
                    ['id' => $email->id],
                    ['%s', '%s'],
                    ['%d']
                );
                continue;
            }

            // Send
            try {
                $sent_result = wp_mail(
                    $email->recipient_email,
                    $rendered['subject'],
                    $rendered['body'],
                    ['Content-Type: text/html; charset=UTF-8']
                );

                if ($sent_result) {
                    $wpdb->update(
                        $this->queue_table,
                        [
                            'status'    => 'sent',
                            'subject'   => $rendered['subject'],
                            'html_body' => $rendered['body'],
                            'sent_at'   => current_time('mysql'),
                            'updated_at' => current_time('mysql'),
                        ],
                        ['id' => $email->id],
                        ['%s', '%s', '%s', '%s', '%s'],
                        ['%d']
                    );
                    $sent++;
                } else {
                    $this->mark_failed($email->id, 'wp_mail returned false');
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->mark_failed($email->id, $e->getMessage());
                $failed++;
            }
        }

        return compact('sent', 'failed', 'skipped');
    }

    // ================================================================
    // RETRY / DEAD LETTER
    // ================================================================

    /**
     * Mark an email as failed (or dead_letter if max attempts reached).
     */
    private function mark_failed(int $email_id, string $error): void
    {
        global $wpdb;

        $email = $wpdb->get_row($wpdb->prepare(
            "SELECT attempts, max_attempts FROM {$this->queue_table} WHERE id = %d",
            $email_id
        ));

        if (!$email) {
            return;
        }

        $new_status = ($email->attempts >= $email->max_attempts) ? 'dead_letter' : 'pending';

        $wpdb->update(
            $this->queue_table,
            [
                'status'     => $new_status,
                'last_error' => $error,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $email_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        error_log("HMWEvents: Email {$email_id} failed (attempts: {$email->attempts}/{$email->max_attempts}) — {$error}");
    }

    /**
     * Retry a dead-lettered email.
     */
    public function retry(int $email_id): bool
    {
        global $wpdb;
        return (bool) $wpdb->update(
            $this->queue_table,
            [
                'status'     => 'pending',
                'attempts'   => 0,
                'last_error' => null,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $email_id],
            ['%s', '%d', null, '%s'],
            ['%d']
        );
    }

    // ================================================================
    // CANCEL / CLEANUP
    // ================================================================

    /**
     * Cancel all pending emails for a booking (except specific types).
     *
     * Used when a booking is cancelled — cancels future reminders,
     * but doesn't cancel the cancellation email itself.
     *
     * @param int      $booking_id
     * @param string[] $exclude_types  Email types to NOT cancel.
     */
    public function cancel_pending_for_booking(int $booking_id, array $exclude_types = []): int
    {
        global $wpdb;

        $exclude_placeholders = array_fill(0, count($exclude_types), '%s');
        $exclude_clause = '';

        if (!empty($exclude_types)) {
            $exclude_clause = ' AND email_type NOT IN (' . implode(',', $exclude_placeholders) . ')';
        }

        $args = array_merge(['pending', $booking_id], $exclude_types);

        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$this->queue_table} SET status = 'cancelled', updated_at = %s 
             WHERE status = %s AND booking_id = %d" . $exclude_clause,
            current_time('mysql'),
            ...$args
        ));
    }

    /**
     * Clean up old sent emails (retention).
     */
    public function cleanup_old(int $days = 90): int
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->queue_table} WHERE status = 'sent' AND sent_at <= %s",
            $cutoff
        ));
    }

    /**
     * Get dead letter emails for admin review.
     */
    public function get_dead_letters(int $limit = 50): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->queue_table} WHERE status = 'dead_letter' ORDER BY updated_at DESC LIMIT %d",
            $limit
        )) ?: [];
    }
}
