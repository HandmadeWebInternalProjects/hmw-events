<?php

/**
 * Event Category Grid Shortcode.
 *
 * Renders a responsive grid of event category cards. Each card shows the
 * category image, name, description, the next upcoming event dates in the
 * category, and a button linking to the category archive.
 *
 * [hmw_event_categories parent="35" limit="3"]
 * [hmw_event_categories categories="12,34" limit="3" columns="3" button_text="View All Courses"]
 *
 * The legacy `event_categories_list` shortcode from the old Events Manager
 * system is registered as an alias on init (priority 20) so existing content
 * renders against the new event system.
 *
 * @package HMWEvents\Shortcodes
 * @since 2.0.0
 */

namespace HMWEvents\Shortcodes;

use HMWEvents\PostTypes\Event;
use HMWEvents\Services\EventDataService;
use HMWEvents\Services\SessionService;
use HMWEvents\Taxonomies\EventType;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventCategoryGrid
{
    private ?SessionService $session_service = null;
    private ?EventDataService $event_data_service = null;

    private function session_service(): SessionService
    {
        if ($this->session_service === null) {
            $this->session_service = new SessionService();
        }
        return $this->session_service;
    }

    private function event_data(): EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new EventDataService();
        }
        return $this->event_data_service;
    }

    public function register(): void
    {
        add_shortcode('hmw_event_categories', [$this, 'render']);
        add_action('init', [$this, 'register_legacy_alias'], 20);
    }

    public function register_legacy_alias(): void
    {
        add_shortcode('event_categories_list', [$this, 'render']);
    }

    public function render($atts): string
    {
        $atts = shortcode_atts([
            'parent'      => 0,
            'categories'  => '',
            'limit'       => 3,
            'columns'     => 3,
            'image_size'  => 'large',
            'button_text' => __('View All Courses', 'hmw-events'),
            'hide_empty'  => 'yes',
        ], (array) $atts, 'hmw_event_categories');

        $terms = $this->resolve_terms($atts);
        if (empty($terms) || !is_array($terms)) {
            return '';
        }

        $args = [
            'limit'      => max(1, (int) $atts['limit']),
            'image_size' => (string) $atts['image_size'],
            'hide_empty' => $this->is_truthy((string) $atts['hide_empty']),
        ];

        $button_text = (string) $atts['button_text'];

        preg_match_all('/\d+/', (string) $atts['columns'], $matches);
        $columns = max(1, min(6, (int) (end($matches[0]) ?: 3)));

        $rendered = 0;
        ob_start();

        echo '<div class="' . esc_attr('hmw-event-category-grid hmw-event-category-grid--cols-' . $columns) . '">';

        foreach ($terms as $term) {
            $card = $this->get_category_card($term, $args);
            if ($card === null) {
                continue;
            }

            hmwevents_get_template_part('cards/event-category-card', null, [
                'card'        => $card,
                'button_text' => $button_text,
            ]);
            $rendered++;
        }

        echo '</div>';

        $html = (string) ob_get_clean();

        return $rendered > 0 ? $html : '';
    }

    /**
     * Build the card data for a single event type term.
     *
     * @return array|null Null when the category has no upcoming dates and hide_empty is on.
     */
    public function get_category_card($term, array $args = []): ?array
    {
        $limit = max(1, (int) ($args['limit'] ?? 3));
        $upcoming = $this->get_upcoming_dates((int) $term->term_id, $limit);

        if (empty($upcoming['dates']) && !empty($args['hide_empty'])) {
            return null;
        }

        $link = get_term_link($term);
        if (is_wp_error($link) || !is_string($link)) {
            return null;
        }

        return [
            'term_id'     => (int) $term->term_id,
            'name'        => $term->name,
            'url'         => $link,
            'description' => term_description($term),
            'image_html'  => $this->get_term_image($term, (string) ($args['image_size'] ?? 'large'), $upcoming['thumbnail_url']),
            'dates'       => $upcoming['dates'],
        ];
    }

    /**
     * Resolve the terms to render from the parent/categories attributes.
     *
     * @return \WP_Term[]
     */
    public function resolve_terms(array $atts): array
    {
        if (!empty($atts['categories'])) {
            $terms = [];
            foreach (explode(',', (string) $atts['categories']) as $entry) {
                $term = $this->resolve_term(trim($entry));
                if ($term !== null && !isset($terms[$term->term_id])) {
                    $terms[$term->term_id] = $term;
                }
            }
            return array_values($terms);
        }

        $parent = 0;
        if (!empty($atts['parent'])) {
            $parent_term = $this->resolve_term(trim((string) $atts['parent']));
            if ($parent_term === null) {
                return [];
            }
            $parent = (int) $parent_term->term_id;
        }

        $terms = get_terms([
            'taxonomy'   => EventType::TAXONOMY,
            'parent'     => $parent,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        return $terms;
    }

    private function resolve_term(string $value): ?object
    {
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $term = get_term((int) $value, EventType::TAXONOMY);
        } else {
            $term = get_term_by('slug', sanitize_title($value), EventType::TAXONOMY);
        }

        return $term instanceof \WP_Term ? $term : null;
    }

    /**
     * Collect the next upcoming dates for a category.
     *
     * Parent events with child sessions contribute their future session
     * dates; standalone events contribute their own start date.
     *
     * @return array{dates: array<int, array{date: string, formatted: string, url: string}>, thumbnail_url: string}
     */
    public function get_upcoming_dates(int $term_id, int $limit): array
    {
        $result = ['dates' => [], 'thumbnail_url' => ''];
        $now = current_time('mysql');

        $parents = get_posts([
            'post_type'      => Event::POST_TYPE,
            'post_status'    => ['publish', 'fully_booked'],
            'post_parent'    => 0,
            'posts_per_page' => 20,
            'no_found_rows'  => true,
            'orderby'        => 'meta_value',
            'meta_key'       => '_event_start_date',
            'order'          => 'ASC',
            'tax_query'      => [
                [
                    'taxonomy' => EventType::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ],
            ],
            'meta_query'     => [
                [
                    'key'     => '_event_start_date',
                    'value'   => $now,
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ],
            ],
        ]);

        if (empty($parents) || !is_array($parents)) {
            return $result;
        }

        $event_data = $this->event_data();
        $dates = [];

        foreach ($parents as $parent) {
            $thumbnail = get_the_post_thumbnail_url($parent->ID, 'large');
            if ($result['thumbnail_url'] === '' && is_string($thumbnail) && $thumbnail !== '') {
                $result['thumbnail_url'] = $thumbnail;
            }

            $session_dates = [];
            foreach ($this->session_service()->get_sessions($parent->ID, 'publish') as $session) {
                $start = $event_data->get_start_date((int) $session->ID);
                if ($start !== null && $start >= $now) {
                    $session_dates[] = $this->format_date_entry($start, (string) get_permalink($session->ID));
                }
            }

            if (!empty($session_dates)) {
                $dates = array_merge($dates, $session_dates);
                continue;
            }

            $own_start = $event_data->get_start_date((int) $parent->ID);
            if ($own_start !== null && $own_start >= $now) {
                $dates[] = $this->format_date_entry($own_start, (string) get_permalink($parent->ID));
            }
        }

        usort($dates, fn(array $a, array $b) => strcmp($a['date'], $b['date']));

        $result['dates'] = array_slice($dates, 0, $limit);

        return $result;
    }

    private function format_date_entry(string $date, string $url): array
    {
        return [
            'date'      => $date,
            'formatted' => date_i18n('F jS Y', strtotime($date)),
            'url'       => $url,
        ];
    }

    private function get_term_image($term, string $size, string $fallback_url): string
    {
        $attachment_id = 0;

        if (function_exists('get_field')) {
            $value = get_field('image', EventType::TAXONOMY . '_' . $term->term_id);

            if (is_numeric($value)) {
                $attachment_id = (int) $value;
            } elseif (is_array($value) && !empty($value['ID'])) {
                $attachment_id = (int) $value['ID'];
            } elseif (is_string($value) && $value !== '') {
                return sprintf(
                    '<img src="%s" alt="%s" loading="lazy" />',
                    esc_url($value),
                    esc_attr($term->name)
                );
            }
        }

        if ($attachment_id === 0) {
            $meta_id = get_term_meta($term->term_id, '_thumbnail_id', true);
            if (is_numeric($meta_id) && (int) $meta_id > 0) {
                $attachment_id = (int) $meta_id;
            }
        }

        if ($attachment_id > 0) {
            return wp_get_attachment_image($attachment_id, $size, false, [
                'alt'     => $term->name,
                'loading' => 'lazy',
            ]);
        }

        if ($fallback_url !== '') {
            return sprintf(
                '<img src="%s" alt="%s" loading="lazy" />',
                esc_url($fallback_url),
                esc_attr($term->name)
            );
        }

        return '';
    }

    private function is_truthy(string $value): bool
    {
        return in_array(strtolower($value), ['yes', '1', 'true'], true);
    }
}
