<?php

/**
 * Course Type Taxonomy.
 *
 * Manages the Course Type taxonomy for categorizing educator courses.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Taxonomies;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Course Type Taxonomy.
 */
class CourseType
{
  /**
   * Taxonomy slug.
   *
   * @var string
   */
  public const TAXONOMY = 'course_type';

  /**
   * Initialize the taxonomy.
   *
   * @since 1.0.0
   */
  public function register()
  {
    add_action('init', [$this, 'register_taxonomy']);
    add_action('init', [$this, 'insert_default_terms'], 20);
    add_action('admin_head', [$this, 'add_required_asterisk_style']);
  }

  /**
   * Register the Course Type taxonomy.
   *
   * @since 1.0.0
   */
  public function register_taxonomy()
  {
    $labels = [
      'name'                       => _x('Course Types', 'taxonomy general name', 'hmw-events'),
      'singular_name'              => _x('Course Type', 'taxonomy singular name', 'hmw-events'),
      'search_items'               => __('Search Course Types', 'hmw-events'),
      'popular_items'              => __('Popular Course Types', 'hmw-events'),
      'all_items'                  => __('All Course Types', 'hmw-events'),
      'parent_item'                => __('Parent Course Type', 'hmw-events'),
      'parent_item_colon'          => __('Parent Course Type:', 'hmw-events'),
      'edit_item'                  => __('Edit Course Type', 'hmw-events'),
      'update_item'                => __('Update Course Type', 'hmw-events'),
      'add_new_item'               => __('Add New Course Type', 'hmw-events'),
      'new_item_name'              => __('New Course Type Name', 'hmw-events'),
      'separate_items_with_commas' => __('Separate course types with commas', 'hmw-events'),
      'add_or_remove_items'        => __('Add or remove course types', 'hmw-events'),
      'choose_from_most_used'      => __('Choose from most used course types', 'hmw-events'),
      'not_found'                  => __('No course types found.', 'hmw-events'),
      'menu_name'                  => __('Course Types', 'hmw-events'),
      'back_to_items'              => __('← Back to Course Types', 'hmw-events'),
    ];

    $args = [
      'labels'                => $labels,
      'description'           => __('Categories for different types of courses', 'hmw-events'),
      'hierarchical'          => true,
      'public'                => true,
      'publicly_queryable'    => true,
      'show_ui'               => true,
      'show_in_menu'          => true,
      'show_in_nav_menus'     => true,
      'show_in_rest'          => true,
      'show_tagcloud'         => false,
      'show_in_quick_edit'    => true,
      'show_admin_column'     => true,
      'meta_box_cb'           => [self::class, 'render_meta_box'],
      'rewrite'               => [
        'slug'         => 'course-type',
        'with_front'   => true,
        'hierarchical' => true,
      ],
      'query_var'             => true,
      'capabilities'          => [
        'manage_terms' => 'manage_categories',
        'edit_terms'   => 'manage_categories',
        'delete_terms' => 'manage_categories',
        'assign_terms' => 'edit_posts',
      ],
    ];

    register_taxonomy(
      self::TAXONOMY,
      ['educator_course'],
      $args
    );
  }

  /**
   * Insert default class type terms if they don't exist.
   *
   * This ensures the four main course types from the old system are available.
   *
   * @since 1.0.0
   */
  public function insert_default_terms()
  {
    // Only run once
    if (get_option('hmwevents_course_types_inserted')) {
      return;
    }

    $default_types = [
      'Calmbirth Course' => [
        'slug'        => 'hmwevents-course',
        'description' => 'Standard Calmbirth childbirth education course',
      ],
      'Calmbirth Caesarean Birth Course' => [
        'slug'        => 'hmwevents-caesarean-course',
        'description' => 'Calmbirth course focused on caesarean birth preparation',
      ],
      'Refresher Course' => [
        'slug'        => 'refresher-course',
        'description' => 'Refresher course for parents who have previously completed Calmbirth',
      ],
      'Educator Course' => [
        'slug'        => 'educator-course',
        'description' => 'Training course for new Calmbirth educators',
      ],
    ];

    foreach ($default_types as $name => $args) {
      if (!term_exists($name, self::TAXONOMY)) {
        wp_insert_term($name, self::TAXONOMY, $args);
      }
    }

    // Mark as inserted
    update_option('hmwevents_course_types_inserted', true);
  }

  /**
   * Render the Course Type metabox with instruction text.
   *
   * @param WP_Post $post Current post object.
   * @param array   $box  Metabox arguments.
   * @return void
   */
  public static function render_meta_box($post, $box)
  {
    $taxonomy = !empty($box['args']['taxonomy']) ? $box['args']['taxonomy'] : self::TAXONOMY;
    post_categories_meta_box($post, $box);
?>
    <p style="margin: 6px 0 0; color: #555; font-style: italic; font-size: 12px;">
      <?php esc_html_e('You can tick more than one type, e.g. tick both \'Calmbirth\' and \'Online\' for an online Calmbirth course.', 'hmw-events'); ?>
    </p>
  <?php
  }

  /**
   * Add required asterisk to the Course Types metabox heading.
   *
   * @since 1.0.0
   * @return void
   */
  public function add_required_asterisk_style()
  {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'educator_course') {
      return;
    }
  ?>
    <style>
      #<?php
        echo esc_attr(self::TAXONOMY); ?>div h2 {
          flex-grow: 0;
          align-items: center;
          gap: 0.2em;
          &::after {
          content: " *";
          color: #d63638;
        }
      }
    </style>
<?php
  }

  /**
   * Get the term ID for a class type by old event_type_id.
   *
   * Helper function for migration mapping.
   *
   * @since 1.0.0
   * @param int $old_event_type_id The old event_type_id (1-4).
   * @return int|false Term ID or false if not found.
   */
  public static function get_term_id_by_old_id($old_event_type_id)
  {
    // Default mapping based on old system IDs
    $mapping = [
      1 => 'refresher-course',
      2 => 'educator-course',
      3 => 'hmwevents-course',
      4 => 'hmwevents-caesarean-course',
    ];

    if (!isset($mapping[$old_event_type_id])) {
      return false;
    }

    $term = get_term_by('slug', $mapping[$old_event_type_id], self::TAXONOMY);

    return $term ? $term->term_id : false;
  }
}
