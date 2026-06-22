<?php
/**
 * Email Queue Repository.
 *
 * Handles database operations for the email queue.
 * Provides methods for adding, updating, retrieving, and deleting email records.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Email Queue Repository Class.
 */
class EmailQueueRepository
{
    /**
     * Table name.
     *
     * @var string
     */
    private $table;

    /**
     * Constructor.
     */
    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'email_queue';
    }

    /**
     * Add email to queue.
     *
     * @param array $data Email data.
     * @return int|false Email ID or false on failure.
     */
    public function add($data)
    {
        global $wpdb;

        $defaults = [
            'booking_id'      => null,
            'educator_id'     => null,
            'recipient_email' => '',
            'recipient_name'  => '',
            'email_type'      => '',
            'subject'         => '',
            'template_key'    => '',
            'template_data'   => '{}',
            'html_body'       => '',
            'status'          => 'pending',
            'attempts'        => 0,
            'max_attempts'    => 5,
            'scheduled_at'    => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        // Convert array to JSON if needed
        if (is_array($data['template_data'])) {
            $data['template_data'] = json_encode($data['template_data']);
        }

        $result = $wpdb->insert(
            $this->table,
            [
                'booking_id'      => $data['booking_id'],
                'educator_id'     => $data['educator_id'],
                'recipient_email' => $data['recipient_email'],
                'recipient_name'  => $data['recipient_name'],
                'email_type'      => $data['email_type'],
                'subject'         => $data['subject'],
                'template_key'    => $data['template_key'],
                'template_data'   => $data['template_data'],
                'html_body'       => $data['html_body'],
                'status'          => $data['status'],
                'attempts'        => $data['attempts'],
                'max_attempts'    => $data['max_attempts'],
                'scheduled_at'    => $data['scheduled_at'],
            ],
            [
                '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s',
            ]
        );

        if ($result) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Get email by ID.
     *
     * @param int $email_id Email ID.
     * @return object|null Email object or null if not found.
     */
    public function get($email_id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $email_id
        ));
    }

    /**
     * Get pending emails to process.
     *
     * @param int $limit Number of emails to retrieve.
     * @return array Email objects.
     */
    public function get_pending($limit = 10)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE status = 'pending' 
             AND (scheduled_at IS NULL OR scheduled_at <= NOW())
             AND attempts < max_attempts
             ORDER BY scheduled_at ASC, created_at ASC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Get failed emails (dead letter queue).
     *
     * @param int $limit Number of emails to retrieve.
     * @return array Email objects.
     */
    public function get_failed($limit = 10)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE status = 'dead_letter'
             ORDER BY updated_at DESC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Get emails by booking ID.
     *
     * @param int $booking_id Booking ID.
     * @return array Email objects.
     */
    public function get_by_booking($booking_id)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE booking_id = %d
             ORDER BY created_at DESC",
            $booking_id
        ));
    }

    /**
     * Cancel pending emails for a booking.
     *
     * @param int   $booking_id Booking ID.
     * @param array $exclude_types Email types to exclude from cancellation.
     * @param string $reason Cancellation reason.
     * @return int|false Number of rows updated or false on failure.
     */
    public function cancel_pending_by_booking($booking_id, $exclude_types = [], $reason = 'Cancelled due to booking update')
    {
        global $wpdb;

        $booking_id = (int) $booking_id;
        $reason = (string) $reason;

        $conditions = "booking_id = %d AND status = 'pending'";
        $params = [$booking_id];

        if (!empty($exclude_types)) {
            $placeholders = implode(', ', array_fill(0, count($exclude_types), '%s'));
            $conditions .= " AND email_type NOT IN ({$placeholders})";
            $params = array_merge($params, array_values($exclude_types));
        }

        $sql = $wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'cancelled', last_error = %s
             WHERE {$conditions}",
            array_merge([$reason], $params)
        );

        return $wpdb->query($sql);
    }

    /**
     * Get recent emails.
     *
     * @param int    $limit  Number of emails.
     * @param string $status Optional status filter.
     * @return array Email objects.
     */
    public function get_recent($limit = 50, $status = '')
    {
        global $wpdb;

        if (!empty($status)) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = %s
                 ORDER BY created_at DESC
                 LIMIT %d",
                $status,
                $limit
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             ORDER BY created_at DESC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Get filtered emails with pagination.
     *
     * @param array $args Filter arguments.
     * @return array Array with 'emails' and 'total' keys.
     */
    public function get_filtered($args = [])
    {
        global $wpdb;

        $defaults = [
            'status'    => '',
            'recipient' => '',
            'per_page'  => 20,
            'page'      => 1,
            'orderby'   => 'created_at',
            'order'     => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        // Build WHERE clause
        $where = [];
        $where_params = [];

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $where_params[] = $args['status'];
        }

        if (!empty($args['recipient'])) {
            $where[] = 'recipient_email LIKE %s';
            $where_params[] = '%' . $wpdb->esc_like($args['recipient']) . '%';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total count
        if (!empty($where_params)) {
            $count_query = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} {$where_clause}",
                $where_params
            );
        } else {
            $count_query = "SELECT COUNT(*) FROM {$this->table}";
        }
        $total = (int) $wpdb->get_var($count_query);

        // Get paginated results
        $offset = ($args['page'] - 1) * $args['per_page'];
        $orderby = in_array($args['orderby'], ['created_at', 'scheduled_at', 'status', 'attempts']) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        if (!empty($where_params)) {
            $query = $wpdb->prepare(
                "SELECT * FROM {$this->table} 
                 {$where_clause}
                 ORDER BY {$orderby} {$order}
                 LIMIT %d OFFSET %d",
                array_merge($where_params, [$args['per_page'], $offset])
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 ORDER BY {$orderby} {$order}
                 LIMIT %d OFFSET %d",
                $args['per_page'],
                $offset
            );
        }

        $emails = $wpdb->get_results($query);

        return [
            'emails' => $emails,
            'total'  => $total,
        ];
    }

    /**
     * Update email status.
     *
     * @param int    $email_id Email ID.
     * @param string $status New status.
     * @param string $error Optional error message.
     * @return bool Success or failure.
     */
    public function update_status($email_id, $status, $error = '')
    {
        global $wpdb;

        $data = ['status' => $status];
        $format = ['%s'];

        if ($error) {
            $data['last_error'] = $error;
            $format[] = '%s';
        }

        return $wpdb->update(
            $this->table,
            $data,
            ['id' => $email_id],
            $format,
            ['%d']
        );
    }

    /**
     * Increment attempt counter and mark as processing.
     *
     * @param int $email_id Email ID.
     * @return bool Success or failure.
     */
    public function mark_processing($email_id)
    {
        global $wpdb;

        $table = $this->table;
        $email_id = (int) $email_id;

        return $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} 
                 SET status = 'processing', attempts = attempts + 1
                 WHERE id = %d",
                $email_id
            )
        );
    }

    /**
     * Mark email as sent.
     *
     * @param int $email_id Email ID.
     * @return bool Success or failure.
     */
    public function mark_sent($email_id)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            [
                'status'  => 'sent',
                'sent_at' => current_time('mysql'),
            ],
            ['id' => $email_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Reset email for retry.
     *
     * @param int $email_id Email ID.
     * @return bool|int Success or failure.
     */
    public function reset_for_retry($email_id)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            [
                'status'     => 'pending',
                'attempts'   => 0,
                'last_error' => null,
            ],
            ['id' => $email_id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    /**
     * Mark email as failed and increment attempts.
     *
     * @param int    $email_id Email ID.
     * @param string $error Error message.
     * @return bool Success or failure.
     */
    public function mark_failed($email_id, $error = '')
    {
        global $wpdb;

        // Get the current attempts
        $email = $this->get($email_id);
        if (!$email) {
            return false;
        }

        $new_attempts = $email->attempts + 1;
        $status = $new_attempts >= $email->max_attempts ? 'dead_letter' : 'pending';

        $data = [
            'status'   => $status,
            'attempts' => $new_attempts,
        ];

        $format = ['%s', '%d'];

        if ($error) {
            $data['last_error'] = $error;
            $format[] = '%s';
        }

        return $wpdb->update(
            $this->table,
            $data,
            ['id' => $email_id],
            $format,
            ['%d']
        );
    }

    /**
     * Delete old sent emails (cleanup).
     *
     * @param int $days Delete emails older than X days.
     * @return int Number of deleted rows.
     */
    public function delete_old_sent($days = 90)
    {
        global $wpdb;

        $cutoff = current_time('mysql', true);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days", strtotime($cutoff)));

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} 
             WHERE status = 'sent' 
             AND sent_at < %s",
            $cutoff
        ));
    }

    /**
     * Get email statistics.
     *
     * @return array Statistics.
     */
    public function get_stats()
    {
        global $wpdb;

        return $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'sent' THEN 1 END) as sent,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(CASE WHEN status = 'dead_letter' THEN 1 END) as dead_letter,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled
            FROM {$this->table}"
        );
    }
}
