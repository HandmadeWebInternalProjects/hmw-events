<?php
/**
 * Email Template Repository.
 *
 * Handles database operations for email templates.
 * Provides methods for managing customizable email templates per educator.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails;

use HMWEvents\Services\DatabaseService;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Email Template Repository Class.
 */
class EmailTemplateRepository
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
        $this->table = DatabaseService::get_table_name('email_templates');
    }

    /**
     * Create or update a template.
     *
     * @param array $data Template data.
     * @return int|false Template ID or false on failure.
     */
    public function save($data)
    {
        global $wpdb;

        // Check if template exists - but don't use get_template() because it falls back to system templates
        // We only want to find existing educator templates with the EXACT educator_id match
        $existing = null;
        
        if (isset($data['educator_id']) && $data['educator_id']) {
            // For educator templates, only find educator-specific template, no fallback
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table} 
                  WHERE organizer_id = %d
                 AND template_key = %s",
                $data['educator_id'],
                $data['template_key']
            ));
        } else {
            // For system templates, find system template (educator_id IS NULL)
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table} 
                  WHERE organizer_id IS NULL
                 AND template_key = %s",
                $data['template_key']
            ));
        }

        // Encode variables if array
        if (isset($data['variables']) && is_array($data['variables'])) {
            $data['variables'] = json_encode($data['variables']);
        }

        if ($existing) {
            // Update existing
            $result = $wpdb->update(
                $this->table,
                [
                    'subject'     => $data['subject'] ?? $existing->subject,
                    'body'        => $data['body'] ?? $existing->body,
                    'variables'   => $data['variables'] ?? $existing->variables,
                    'is_active'   => $data['is_active'] ?? $existing->is_active,
                    'version'     => intval($existing->version) + 1,
                ],
                ['id' => $existing->id],
                ['%s', '%s', '%s', '%d', '%d'],
                ['%d']
            );

            return $result !== false ? $existing->id : false;
        }

        // Insert new
        $result = $wpdb->insert(
            $this->table,
            [
                'organizer_id' => $data['educator_id'] ?? $data['organizer_id'] ?? null,
                'template_key' => $data['template_key'],
                'subject'      => $data['subject'] ?? '',
                'body'         => $data['body'] ?? '',
                'variables'    => $data['variables'] ?? '[]',
                'is_active'    => $data['is_active'] ?? 1,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d']
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get template by educator and template key.
     *
     * @param int|null $educator_id Educator ID or null for system default.
     * @param string   $template_key Template key.
     * @return object|null Template object or null if not found.
     */
    public function get_template($educator_id, $template_key)
    {
        global $wpdb;

        // Try educator-specific template first
        if ($educator_id) {
            $template = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table} 
                  WHERE organizer_id = %d
                 AND template_key = %s 
                 AND is_active = 1",
                $educator_id,
                $template_key
            ));

            if ($template) {
                return $template;
            }
        }

        // Fall back to system default (educator_id is NULL)
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} 
              WHERE organizer_id IS NULL
             AND template_key = %s 
             AND is_active = 1",
            $template_key
        ));
    }

    /**
     * Get template by ID.
     *
     * @param int $template_id Template ID.
     * @return object|null Template object or null if not found.
     */
    public function get($template_id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $template_id
        ));
    }

    /**
     * Get all templates for educator.
     *
     * @param int $educator_id Educator ID.
     * @return array Template objects.
     */
    public function get_educator_templates($educator_id)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} 
              WHERE organizer_id = %d
             ORDER BY template_key ASC",
            $educator_id
        ));
    }

    /**
     * Get all system default templates.
     *
     * @return array Template objects.
     */
    public function get_system_templates()
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$this->table} 
              WHERE organizer_id IS NULL
             ORDER BY template_key ASC"
        );
    }

    /**
     * Activate template.
     *
     * @param int $template_id Template ID.
     * @return bool Success or failure.
     */
    public function activate($template_id)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            ['is_active' => 1],
            ['id' => $template_id],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Deactivate template.
     *
     * @param int $template_id Template ID.
     * @return bool Success or failure.
     */
    public function deactivate($template_id)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            ['is_active' => 0],
            ['id' => $template_id],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Delete template.
     *
     * @param int $template_id Template ID.
     * @return bool Success or failure.
     */
    public function delete($template_id)
    {
        global $wpdb;

        return $wpdb->delete(
            $this->table,
            ['id' => $template_id],
            ['%d']
        );
    }

    /**
     * Render template with variables.
     *
     * @param object $template Template object.
     * @param array  $variables Template variables.
     * @return array Array with 'subject' and 'body' keys.
     */
    public function render($template, $variables = [])
    {
        $subject = $template->subject;
        $body = $template->body;

        // Simple variable replacement
        foreach ($variables as $key => $value) {
            // Convert arrays/objects to strings (join arrays with comma)
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, 'is_scalar'));
            } elseif (is_object($value)) {
                $value = json_encode($value);
            } else {
                $value = (string) $value;
            }

            $placeholder = '{{' . $key . '}}';
            $subject = str_replace($placeholder, $value, $subject);
            $body = str_replace($placeholder, $value, $body);
        }

        // Append site logo to all emails
        $logo_url = wp_get_attachment_url(633);
        if ($logo_url) {
            $body .= '<p style="margin-top:32px;text-align:center;"><img src="' . esc_url($logo_url) . '" alt="' . esc_attr(get_option('blogname')) . '" style="max-width:200px;height:auto;" /></p>';
        }

        return [
            'subject' => $subject,
            'body'    => $body,
        ];
    }
}
