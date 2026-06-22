<?php

/**
 * Courses REST API Endpoint.
 *
 * Handles course-related operations.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Api\Routes;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Courses API Class.
 */
class Courses
{
    /**
     * Initialize the API endpoint.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->register_routes();
    }

    /**
     * Register REST API routes.
     *
     * @since 1.0.0
     */
    public function register_routes()
    {
        register_rest_route('cms/v1', '/courses/educator/(?P<educator_id>\\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_educator_courses'],
            'permission_callback' => '__return_true',
            'args' => [
                'educator_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ],
                'offset' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                ],
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 5,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route('cms/v1', '/educators/search', [
            'methods' => 'GET',
            'callback' => [$this, 'search_educators'],
            'permission_callback' => '__return_true',
            'args' => [
                'search' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'page' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 10,
                    'sanitize_callback' => 'absint',
                ],
                'course_type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Get educator courses.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request Full request data.
     * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
     */
    public function get_educator_courses($request)
    {
        $educator_id = $request->get_param('educator_id');
        $offset = $request->get_param('offset');
        $limit = $request->get_param('limit');

        $args = [
            'post_type' => 'educator_course',
            'author' => $educator_id,
            'posts_per_page' => $limit,
            'offset' => $offset,
            'post_status' => 'publish',
            'meta_key' => 'course_start_date',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key' => 'course_start_date',
                    'value' => current_time('mysql'),
                    'compare' => '>=',
                    'type' => 'DATETIME',
                ],
            ],
        ];

        $courses = get_posts($args);

        $formatted_courses = [];
        foreach ($courses as $course) {
            $start_date = get_field('course_start_date', $course->ID);
            $course_suburb = get_field('course_location_suburb', $course->ID);

            // Get course type (taxonomy).
            $course_type = '';
            $terms = get_the_terms($course->ID, 'course_type');
            if ($terms && ! is_wp_error($terms)) {
                $course_type = esc_html($terms[0]->name);
            }

            $formatted_courses[] = [
                'url' => get_permalink($course->ID),
                'date' => date_i18n('M j, Y', strtotime($start_date)),
                'suburb' => $course_suburb,
                'course_type' => $course_type,
            ];
        }

        return rest_ensure_response([
            'success' => true,
            'courses' => $formatted_courses,
        ]);
    }

    /**
     * Search educators with pagination.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request Full request data.
     * @return \WP_REST_Response|\\WP_Error Response object on success, or WP_Error object on failure.
     */
    public function search_educators($request)
    {
        $search_param = $request->get_param('search');
        $page = $request->get_param('page');
        $per_page = $request->get_param('per_page');
        $filter_country = strtoupper(trim($request->get_param('country') ?? ''));

        $course_type_param = $request->get_param('course_type');
        $filters = [];
        if ($course_type_param !== '') {
            $raw = array_map('sanitize_text_field', explode(',', $course_type_param));
            $course_types = array_values(array_filter($raw));
            if (!empty($course_types)) {
                $filters['course_type'] = $course_types;
            }
        }

        // Include the search functions from SSR
        require_once plugin_dir_path(__FILE__) . '../../Breakdance/elements/Educator_Search_Results/search-functions.php';

        // Parse search type
        $state = '';
        $country = '';
        $location = '';
        $hospital = false;
        $name = '';

        if (strpos($search_param, 'state:') === 0) {
            $state = substr($search_param, 6);
        } elseif (strpos($search_param, 'country:') === 0) {
            $country = substr($search_param, 8);
        } elseif (strpos($search_param, 'location:') === 0) {
            $location = substr($search_param, 9);
        } elseif ($search_param === 'hospital') {
            $hospital = true;
        } elseif (strpos($search_param, 'name:') === 0) {
            $name = substr($search_param, 5);
        } else {
            if (preg_match('/^\d{4}$/', $search_param)) {
                $location = $search_param;
            } elseif (preg_match('/[0-9]/', $search_param)) {
                $location = $search_param;
            } else {
                $name = $search_param;
            }
        }

        $show_distance = false;
        $educators = [];

        // Execute search
        if ($location) {
            $result = search_educators_by_location($location, $page, $per_page, $filters, $filter_country);
            $educators = $result['educators'];
            $show_distance = $result['show_distance'];
        } elseif ($state) {
            $result = search_educators_by_state($state, $page, $per_page, $filters);
            $educators = $result['educators'];
        } elseif ($country) {
            $result = search_educators_by_country($country, $page, $per_page, $filters);
            $educators = $result['educators'];
        } elseif ($name) {
            $result = search_educators_by_name($name, $page, $per_page, $filters);
            $educators = $result['educators'];
            
            if (empty($educators)) {
                $result = search_educators_by_location($name, $page, $per_page, $filters, $filter_country);
                $educators = $result['educators'];
                $show_distance = $result['show_distance'];
            }
        } elseif ($hospital) {
            $result = search_educators_by_hospital($page, $per_page, $filters);
            $educators = $result['educators'];
        }

        // Generate HTML for educators
        ob_start();
        foreach ($educators as $educator) {
            hmwevents_get_template_part(
                "cards/educator-card",
                null,
                ['educator' => $educator, 'show_distance' => $show_distance]
            );
        }
        $html = ob_get_clean();

        return rest_ensure_response([
            'success' => true,
            'html' => $html,
        ]);
    }
}
