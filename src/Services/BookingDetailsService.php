<?php

namespace HMWEvents\Services;

/**
 * Service for managing flexible booking details using JSON + Metadata hybrid approach
 */
class BookingDetailsService {
  
  /**
   * @var \wpdb
   */
  private $wpdb;
  
  /**
   * @var string
   */
  private $booking_details_table;
  
  /**
   * @var string
   */
  private $booking_meta_table;
  
  /**
   * Fields that should be searchable via metadata table
   * @var array
   */
  private $searchable_fields = [
  ];
  
  public function __construct() {
    global $wpdb;
    $this->wpdb = $wpdb;
    $this->booking_details_table = $wpdb->prefix . 'educator_booking_details';
    $this->booking_meta_table = $wpdb->prefix . 'educator_booking_meta';
  }
  
  /**
   * Save booking details
   * 
   * @param int $booking_id The booking ID
   * @param array $form_data Form data to store as JSON
   * @param string $form_version Form version identifier
   * @return int|false Insert ID on success, false on failure
   */
  public function save_booking_details($booking_id, $form_data, $form_version = '1.0') {
    // Prepare form data
    $json_data = json_encode($form_data, JSON_UNESCAPED_UNICODE);
    
    // Check if details already exist for this booking
    $existing = $this->wpdb->get_var($this->wpdb->prepare(
      "SELECT id FROM {$this->booking_details_table} WHERE booking_id = %d",
      $booking_id
    ));
    
    if ($existing) {
      // Update existing
      $result = $this->wpdb->update(
        $this->booking_details_table,
        [
          'form_data' => $json_data,
          'form_version' => $form_version,
          'updated_at' => current_time('mysql')
        ],
        ['booking_id' => $booking_id],
        ['%s', '%s', '%s'],
        ['%d']
      );
    } else {
      // Insert new
      $result = $this->wpdb->insert(
        $this->booking_details_table,
        [
          'booking_id' => $booking_id,
          'form_data' => $json_data,
          'form_version' => $form_version,
          'created_at' => current_time('mysql'),
          'updated_at' => current_time('mysql')
        ],
        ['%d', '%s', '%s', '%s', '%s']
      );
    }
    
    if ($result === false) {
      error_log('BookingDetailsService: Failed to save booking details for booking_id ' . $booking_id);
      return false;
    }
    
    // Save searchable fields to meta table
    foreach ($this->searchable_fields as $field) {
      if (isset($form_data[$field]) && !empty($form_data[$field])) {
        $this->save_booking_meta($booking_id, $field, $form_data[$field]);
      }
    }
    
    return $existing ? $existing : $this->wpdb->insert_id;
  }
  
  /**
   * Get booking details
   * 
   * @param int $booking_id The booking ID
   * @return array|null Decoded form data or null if not found
   */
  public function get_booking_details($booking_id) {
    $row = $this->wpdb->get_row($this->wpdb->prepare(
      "SELECT form_data, form_version, created_at, updated_at 
       FROM {$this->booking_details_table} 
       WHERE booking_id = %d",
      $booking_id
    ), ARRAY_A);
    
    if (!$row) {
      return null;
    }
    
    $data = json_decode($row['form_data'], true);
    
    return [
      'data' => $data,
      'form_version' => $row['form_version'],
      'created_at' => $row['created_at'],
      'updated_at' => $row['updated_at']
    ];
  }
  
  /**
   * Get raw form data only
   * 
   * @param int $booking_id The booking ID
   * @return array|null Decoded form data or null if not found
   */
  public function get_form_data($booking_id) {
    $details = $this->get_booking_details($booking_id);
    return $details ? $details['data'] : null;
  }
  
  /**
   * Save a single metadata value
   * 
   * @param int $booking_id The booking ID
   * @param string $meta_key The metadata key
   * @param mixed $meta_value The metadata value
   * @return int|false Insert ID on success, false on failure
   */
  public function save_booking_meta($booking_id, $meta_key, $meta_value) {
    // Convert value to string for storage
    $value_str = is_array($meta_value) ? json_encode($meta_value) : (string) $meta_value;
    
    // Check if meta already exists
    $existing = $this->wpdb->get_var($this->wpdb->prepare(
      "SELECT id FROM {$this->booking_meta_table} 
       WHERE booking_id = %d AND meta_key = %s",
      $booking_id,
      $meta_key
    ));
    
    if ($existing) {
      // Update existing
      $result = $this->wpdb->update(
        $this->booking_meta_table,
        [
          'meta_value' => $value_str,
          'updated_at' => current_time('mysql')
        ],
        [
          'booking_id' => $booking_id,
          'meta_key' => $meta_key
        ],
        ['%s', '%s'],
        ['%d', '%s']
      );
      
      return $result !== false ? $existing : false;
    } else {
      // Insert new
      $result = $this->wpdb->insert(
        $this->booking_meta_table,
        [
          'booking_id' => $booking_id,
          'meta_key' => $meta_key,
          'meta_value' => $value_str,
          'created_at' => current_time('mysql'),
          'updated_at' => current_time('mysql')
        ],
        ['%d', '%s', '%s', '%s', '%s']
      );
      
      return $result !== false ? $this->wpdb->insert_id : false;
    }
  }
  
  /**
   * Get a single metadata value
   * 
   * @param int $booking_id The booking ID
   * @param string $meta_key The metadata key
   * @param mixed $default Default value if not found
   * @return mixed The metadata value or default
   */
  public function get_booking_meta($booking_id, $meta_key, $default = null) {
    $value = $this->wpdb->get_var($this->wpdb->prepare(
      "SELECT meta_value FROM {$this->booking_meta_table} 
       WHERE booking_id = %d AND meta_key = %s",
      $booking_id,
      $meta_key
    ));
    
    if ($value === null) {
      return $default;
    }
    
    // Try to decode if it's JSON
    $decoded = json_decode($value, true);
    return $decoded !== null ? $decoded : $value;
  }
  
  /**
   * Get all metadata for a booking
   * 
   * @param int $booking_id The booking ID
   * @return array Associative array of meta_key => meta_value
   */
  public function get_all_booking_meta($booking_id) {
    $results = $this->wpdb->get_results($this->wpdb->prepare(
      "SELECT meta_key, meta_value FROM {$this->booking_meta_table} 
       WHERE booking_id = %d",
      $booking_id
    ), ARRAY_A);
    
    $meta = [];
    foreach ($results as $row) {
      $decoded = json_decode($row['meta_value'], true);
      $meta[$row['meta_key']] = $decoded !== null ? $decoded : $row['meta_value'];
    }
    
    return $meta;
  }
  
  /**
   * Search bookings by metadata
   * 
   * @param string $meta_key The metadata key to search
   * @param mixed $meta_value The value to match
   * @param string $comparison Comparison operator (=, LIKE, etc.)
   * @return array Array of booking IDs
   */
  public function search_by_meta($meta_key, $meta_value, $comparison = '=') {
    $allowed_comparisons = ['=', '!=', 'LIKE', 'NOT LIKE', '>', '<', '>=', '<='];
    
    if (!in_array(strtoupper($comparison), $allowed_comparisons)) {
      $comparison = '=';
    }
    
    $value_str = is_array($meta_value) ? json_encode($meta_value) : (string) $meta_value;
    
    if (strtoupper($comparison) === 'LIKE' || strtoupper($comparison) === 'NOT LIKE') {
      $value_str = '%' . $this->wpdb->esc_like($value_str) . '%';
    }
    
    $results = $this->wpdb->get_col($this->wpdb->prepare(
      "SELECT DISTINCT booking_id FROM {$this->booking_meta_table} 
       WHERE meta_key = %s AND meta_value {$comparison} %s",
      $meta_key,
      $value_str
    ));
    
    return array_map('intval', $results);
  }
  
  /**
   * Delete booking details and all associated metadata
   * 
   * @param int $booking_id The booking ID
   * @return bool True on success, false on failure
   */
  public function delete_booking_details($booking_id) {
    // Delete from details table
    $details_deleted = $this->wpdb->delete(
      $this->booking_details_table,
      ['booking_id' => $booking_id],
      ['%d']
    );
    
    // Delete from meta table
    $meta_deleted = $this->wpdb->delete(
      $this->booking_meta_table,
      ['booking_id' => $booking_id],
      ['%d']
    );
    
    return $details_deleted !== false && $meta_deleted !== false;
  }
  
  /**
   * Get searchable field names
   * 
   * @return array List of searchable field names
   */
  public function get_searchable_fields() {
    return $this->searchable_fields;
  }
}
