<?php

/**
 * Document Upload Handler.
 *
 * Handles file uploads during registration. Stores metadata in
 * hmwevents_registration_documents and manages file retention.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class DocumentUploadHandler
{
    /**
     * Upload directory relative to WP uploads.
     */
    private const UPLOAD_SUBDIR = 'hmwevents-documents';

    /**
     * Default retention period in days (90 per compliance requirement).
     */
    private const DEFAULT_RETENTION_DAYS = 90;

    /**
     * Allowed file extensions.
     */
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];

    /**
     * Maximum file size in bytes (10MB).
     */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    private string $table_name;

    public function __construct()
    {
        $this->table_name = DatabaseService::get_table_name('registration_documents');
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        // Cron job for PII document retention cleanup (90 day purge)
        add_action('hmwevents_daily_retention_cleanup', [$this, 'purge_expired_documents']);
    }

    /**
     * Handle a file upload during registration.
     *
     * @param array $file       $_FILES element
     * @param int   $booking_id Booking ID (may be 0 if booking not yet created).
     * @param int   $event_id   Event post ID.
     * @return array|\WP_Error  Document record or error.
     */
    public function handle_upload(array $file, int $booking_id, int $event_id): array|\WP_Error
    {
        $validation = $this->validate_upload($file);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $upload_dir = $this->get_upload_dir();
        $filename   = $this->generate_filename($file['name']);
        $filepath   = $upload_dir . '/' . $filename;

        if (!wp_mkdir_p($upload_dir)) {
            return new \WP_Error(
                'upload_dir_error',
                __('Could not create upload directory.', 'hmw-events')
            );
        }

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            return new \WP_Error(
                'upload_move_error',
                __('Failed to save uploaded file.', 'hmw-events')
            );
        }

        // Store relative path (from wp-content/uploads)
        $relative_path = self::UPLOAD_SUBDIR . '/' . $filename;

        // Calculate retention date
        $retention_days = apply_filters('hmwevents_document_retention_days', self::DEFAULT_RETENTION_DAYS, $event_id);
        $retention_until = gmdate('Y-m-d H:i:s', strtotime("+{$retention_days} days"));

        return [
            'file_path'        => $relative_path,
            'original_filename' => sanitize_text_field($file['name']),
            'mime_type'        => $file['type'],
            'file_size'        => $file['size'],
            'retention_until'  => $retention_until,
        ];
    }

    /**
     * Insert a document record into the registration_documents table.
     *
     * @return int|false Inserted ID or false.
     */
    public function insert_document_record(int $booking_id, array $document): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table_name,
            [
                'booking_id'         => $booking_id,
                'file_path'          => $document['file_path'],
                'original_filename'  => $document['original_filename'],
                'mime_type'          => $document['mime_type'],
                'file_size'          => $document['file_size'],
                'retention_until'    => $document['retention_until'],
                'uploaded_at'        => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($result === false) {
            error_log('HMWEvents: Failed to insert document record: ' . $wpdb->last_error);
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Get documents for a booking.
     *
     * @return object[]
     */
    public function get_documents_for_booking(int $booking_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE booking_id = %d ORDER BY uploaded_at ASC",
            $booking_id
        )) ?: [];
    }

    /**
     * Purge expired documents (called by cron).
     *
     * Deletes the physical file and the database record.
     *
     * @return int Number of documents purged.
     */
    public function purge_expired_documents(): int
    {
        global $wpdb;
        $upload_dir = wp_upload_dir();

        $expired = $wpdb->get_results($wpdb->prepare(
            "SELECT id, file_path FROM {$this->table_name} WHERE retention_until <= %s",
            current_time('mysql')
        ));

        $purged = 0;
        foreach ($expired as $doc) {
            $full_path = $upload_dir['basedir'] . '/' . $doc->file_path;
            if (file_exists($full_path)) {
                @unlink($full_path);
            }
            $wpdb->delete($this->table_name, ['id' => $doc->id], ['%d']);
            $purged++;
        }

        if ($purged > 0) {
            error_log("HMWEvents: Purged {$purged} expired registration documents.");
        }

        return $purged;
    }

    /**
     * Validate an uploaded file.
     */
    private function validate_upload(array $file): true|\WP_Error
    {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return new \WP_Error(
                'upload_error',
                sprintf(__('Upload failed with error code %s.', 'hmw-events'), $file['error'] ?? 'unknown')
            );
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return new \WP_Error(
                'invalid_file_type',
                sprintf(
                    __('Invalid file type. Allowed: %s.', 'hmw-events'),
                    implode(', ', self::ALLOWED_EXTENSIONS)
                )
            );
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            return new \WP_Error(
                'file_too_large',
                sprintf(
                    __('File too large. Maximum size is %s MB.', 'hmw-events'),
                    self::MAX_FILE_SIZE / (1024 * 1024)
                )
            );
        }

        return true;
    }

    /**
     * Get the absolute upload directory path.
     */
    private function get_upload_dir(): string
    {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR;
    }

    /**
     * Generate a unique filename with timestamp prefix.
     */
    private function generate_filename(string $original_name): string
    {
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        return time() . '_' . substr(md5($original_name . random_bytes(4)), 0, 8) . '.' . $ext;
    }
}
