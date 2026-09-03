<?php

/**
 * Email Attachment Service.
 *
 * Stores file attachments against queued emails and resolves them at
 * send time so that attachments can be passed to wp_mail().
 *
 * @package HMWEvents\Services\Emails
 * @since 2.0.0
 */

namespace HMWEvents\Services\Emails;

use HMWEvents\Services\DatabaseService;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EmailAttachmentService
{
    /**
     * Attach a file to a queued email.
     *
     * @param int    $email_queue_id Queue row ID.
     * @param string $file_path      Absolute filesystem path.
     * @param string $file_name      Display name.
     * @param string $mime_type      MIME type.
     * @return bool
     */
    public function add(int $email_queue_id, string $file_path, string $file_name, string $mime_type = 'application/pdf'): bool
    {
        global $wpdb;

        $table = DatabaseService::get_table_name('email_attachments');

        $result = $wpdb->insert(
            $table,
            [
                'email_queue_id' => $email_queue_id,
                'file_path'      => $file_path,
                'file_name'      => $file_name,
                'mime_type'      => $mime_type,
                'created_at'     => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Resolve existing attachment file paths for a queued email.
     *
     * @param int $email_queue_id Queue row ID.
     * @return array Absolute paths of files that still exist on disk.
     */
    public function get_for_queue(int $email_queue_id): array
    {
        global $wpdb;

        $table = DatabaseService::get_table_name('email_attachments');

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT file_path FROM {$table} WHERE email_queue_id = %d",
            $email_queue_id
        ));

        if (empty($rows)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $rows), 'is_file'));
    }
}
