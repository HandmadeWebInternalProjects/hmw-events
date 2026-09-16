<?php

/**
 * Event Listing Service.
 *
 * Provides front-end event listing and filtering via WP_Query wrapper.
 * Supports filtering by:
 * - Event type (webinar, workshop, course, etc.)
 * - Audience (parents, professionals, etc.)
 * - Delivery mode (in-person, online, hybrid)
 * - Location (suburb inferred from the venue Google Map field)
 * - Free vs paid
 * - Date range
 * - Search keyword
 * - Sort (date, price, title)
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

use HMWEvents\Registry\TaxonomyRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventListingService
{
    private const ATTS_DEFAULTS = [
        'type'         => '',
        'audience'     => '',
        'mode'         => '',
        'state'        => '',
        'free'         => '',
        'limit'        => 12,
        'show_filters' => 'yes',
        'sort'         => 'date',
        'sort_order'   => 'ASC',
        'event_type_filter_parent' => '',
    ];

    private const VALID_SORTS = ['date_asc', 'date_desc', 'price_asc', 'price_desc', 'title_asc', 'title_desc'];

    private const TERM_TAXONOMY_ATT_KEYS = [
        \HMWEvents\Taxonomies\EventType::TAXONOMY          => 'type',
        \HMWEvents\Taxonomies\EventAudience::TAXONOMY      => 'audience',
        \HMWEvents\Taxonomies\EventDeliveryMode::TAXONOMY  => 'mode',
    ];

    private ?SessionService $session_service = null;
    private ?EventDataService $event_data_service = null;
    private ?VenueSuburbService $venue_suburb_service = null;
    private string $request_base_url = '';

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

    private function venue_suburbs(): array
    {
        if ($this->venue_suburb_service === null) {
            $this->venue_suburb_service = new VenueSuburbService();
        }
        return $this->venue_suburb_service->get_available_suburbs();
    }

    public function register(): void
    {
        add_shortcode('hmw_event_listings', [$this, 'render_listings']);
        add_action('wp_ajax_hmwevents_filter_events', [$this, 'ajax_filter']);
        add_action('wp_ajax_nopriv_hmwevents_filter_events', [$this, 'ajax_filter']);
    }

    // ================================================================
    // QUERY
    // ================================================================

    /**
     * Build a WP_Query args array from filter parameters.
     *
     * @param array $filters {
     *     event_type:       string|string[]    Event type slug(s).
     *     audience:         string|string[]    Audience slug(s).
     *     topic:            string|string[]    Content-filter slug(s) keyed by registry filter_key (e.g. topic, program).
     *     delivery_mode:    string|string[]    Delivery mode slug(s).
     *     location:         string|string[]    Suburb name(s) inferred from venue addresses.
     *     free_only:        bool               Only free events.
     *     paid_only:        bool               Only paid events.
     *     date_from:        string             Y-m-d.
     *     date_to:          string             Y-m-d.
     *     search:           string             Keyword search.
     *     posts_per_page:   int                Default 12.
     *     page:             int                Page number.
     *     sort:             string             date_asc, date_desc, price_asc, price_desc, title_asc, title_desc.
     * }
     * @return array WP_Query args.
     */
    public function build_query(array $filters = []): array
    {
        $sort  = $filters['sort'] ?? 'date_asc';
        $paged = max(1, (int) ($filters['page'] ?? 1));
        $invitation_only_ids = get_posts([
            'post_type'      => 'hmw_event',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => \HMWEvents\PostTypes\Event::META_INVITATION_ONLY,
            'meta_value'     => '1',
        ]);
        $args = [
            'post_type'      => 'hmw_event',
            'post_status'    => ['publish', 'fully_booked'],
            'posts_per_page' => $filters['posts_per_page'] ?? 12,
            'paged'          => $paged,
            'meta_query'     => [
                [
                    'key'     => '_event_start_date',
                    'compare' => 'EXISTS',
                ],
            ],
            'tax_query'      => [],
            'post_parent'    => !empty($filters['show_child_sessions']) ? '' : 0,
        ];

        if (!empty($filters['search'])) {
            $args['s'] = $filters['search'];
        }

        if (!empty($invitation_only_ids)) {
            $args['post__not_in'] = array_map('intval', (array) $invitation_only_ids);
        }

        if ($sort === 'title_asc' || $sort === 'title_desc') {
            $args['orderby'] = 'title';
            $args['order']   = $sort === 'title_asc' ? 'ASC' : 'DESC';
        } else {
            $args['orderby'] = match ($sort) {
                'date_desc'  => 'meta_value',
                'price_asc', 'price_desc' => 'meta_value_num',
                default      => 'meta_value',
            };
            $args['meta_key'] = match ($sort) {
                'price_asc', 'price_desc' => '_event_price',
                default => '_event_start_date',
            };
            $args['order'] = match ($sort) {
                'date_desc', 'price_desc' => 'DESC',
                default => 'ASC',
            };
        }

        if (!empty($filters['date_from'])) {
            $args['meta_query'][] = [
                'key'     => '_event_start_date',
                'value'   => $filters['date_from'],
                'compare' => '>=',
                'type'    => 'DATE',
            ];
        }

        if (!empty($filters['date_to'])) {
            $args['meta_query'][] = [
                'key'     => '_event_end_date',
                'value'   => $filters['date_to'],
                'compare' => '<=',
                'type'    => 'DATE',
            ];
        }

        if (!empty($filters['free_only'])) {
            $args['meta_query'][] = [
                'relation' => 'OR',
                ['key' => '_event_is_free', 'value' => '1'],
                ['key' => '_event_price', 'value' => '0', 'compare' => '<='],
            ];
        }

        if (!empty($filters['paid_only'])) {
            $args['meta_query'][] = [
                'key'     => '_event_price',
                'value'   => 0,
                'compare' => '>',
                'type'    => 'NUMERIC',
            ];
        }

        if (!empty($filters['months'])) {
            $month_clauses = [];
            foreach ((array) $filters['months'] as $month) {
                $month = (int) $month;
                if ($month < 1 || $month > 12) {
                    continue;
                }
                $month_clauses[] = [
                    'key'     => '_event_start_date',
                    'value'   => sprintf('-%02d-', $month),
                    'compare' => 'LIKE',
                ];
            }

            if (count($month_clauses) === 1) {
                $args['meta_query'][] = $month_clauses[0];
            } elseif ($month_clauses) {
                $args['meta_query'][] = array_merge(['relation' => 'OR'], $month_clauses);
            }
        }

        if (!empty($filters['event_day'])) {
            $day_numbers = [];
            foreach ((array) $filters['event_day'] as $day) {
                foreach ($day === 'weekend' ? [1, 7] : [2, 3, 4, 5, 6] as $number) {
                    $day_numbers[] = $number;
                }
            }
            $day_numbers = array_values(array_unique($day_numbers));
            if ($day_numbers && count($day_numbers) < 7) {
                $args['hmwevents_event_day'] = $day_numbers;
            }
        }

        if (!empty($filters['event_type'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'hmw_event_type',
                'field'    => 'slug',
                'terms'    => (array) $filters['event_type'],
            ];
        }

        if (!empty($filters['audience'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'hmw_event_audience',
                'field'    => 'slug',
                'terms'    => (array) $filters['audience'],
            ];
        }

        foreach (TaxonomyRegistry::filter_key_map() as $content_taxonomy => $filter_key) {
            if (!empty($filters[$filter_key])) {
                $args['tax_query'][] = [
                    'taxonomy' => $content_taxonomy,
                    'field'    => 'slug',
                    'terms'    => (array) $filters[$filter_key],
                ];
            }
        }

        if (!empty($filters['delivery_mode'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'hmw_event_delivery_mode',
                'field'    => 'slug',
                'terms'    => (array) $filters['delivery_mode'],
            ];
        }

        if (!empty($filters['location'])) {
            $args['meta_query'][] = [
                'key'     => VenueSuburbService::META_EVENT_SUBURB,
                'value'   => (array) $filters['location'],
                'compare' => 'IN',
            ];
        }

        if (count($args['meta_query']) > 1) {
            $args['meta_query']['relation'] = 'AND';
        }

        if (count($args['tax_query']) > 1) {
            $args['tax_query']['relation'] = 'AND';
        }

        return $args;
    }

    /**
     * Query events with filters.
     *
     * @return \WP_Query
     */
    public function query(array $filters = []): \WP_Query
    {
        $args        = $this->build_query($filters);
        $day_numbers = $args['hmwevents_event_day'] ?? [];
        unset($args['hmwevents_event_day']);

        $where_filter = null;
        if ($day_numbers) {
            $where_filter = function (string $where) use ($day_numbers): string {
                return $where . $this->event_day_where_sql($day_numbers);
            };
            add_filter('posts_where', $where_filter);
        }

        $query = new \WP_Query($args);

        if ($where_filter) {
            remove_filter('posts_where', $where_filter);
        }

        return $query;
    }

    /**
     * Correlated-subquery WHERE fragment restricting events to the given
     * DAYOFWEEK numbers (1 = Sunday … 7 = Saturday) of _event_start_date.
     *
     * @param int[] $day_numbers
     */
    private function event_day_where_sql(array $day_numbers): string
    {
        global $wpdb;

        $numbers = implode(',', array_map('intval', $day_numbers));

        return " AND ( ( SELECT DAYOFWEEK(pm.meta_value) FROM {$wpdb->postmeta} pm"
            . " WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = '_event_start_date' LIMIT 1 )"
            . " IN ({$numbers}) )";
    }

    /**
     * Get an event card data array for frontend rendering.
     *
     * @return array|null
     */
    public function get_event_card($post): ?array
    {
        $eds        = $this->event_data();
        $start_date = $eds->get_start_date($post->ID);
        $end_date   = $eds->get_end_date($post->ID);
        $price      = $eds->get_price($post->ID);
        $venue      = $eds->get_venue_name($post->ID);
        $venue_addr = $eds->get_venue_address_string($post->ID);
        $capacity   = $eds->get_capacity($post->ID);
        $webinar_url= $eds->get_webinar_url($post->ID);
        $is_free    = $eds->get_is_free($post->ID);

        $event_types    = wp_get_object_terms($post->ID, 'hmw_event_type', ['fields' => 'slugs']);
        $event_type_names = wp_get_object_terms($post->ID, 'hmw_event_type', ['fields' => 'names']);
        $event_type_name  = $event_type_names[0] ?? '';
        $audiences      = wp_get_object_terms($post->ID, 'hmw_event_audience', ['fields' => 'slugs']);
        $delivery_terms = wp_get_object_terms($post->ID, 'hmw_event_delivery_mode');
        $audience_terms = $this->term_link_list(wp_get_object_terms($post->ID, 'hmw_event_audience'), 'hmw_event_audience');
        $suburb         = $eds->get_venue_suburb($post->ID);

        [$delivery_mode, $delivery_mode_label] = $this->resolve_delivery_mode($delivery_terms[0] ?? null);

        $badges = [];

        if ($is_free || $price <= 0) {
            $badges[] = ['label' => __('Free', 'hmw-events'), 'class' => 'badge--free'];
        }

        if ($post->post_status === 'fully_booked') {
            $badges[] = ['label' => __('Fully Booked', 'hmw-events'), 'class' => 'badge--full'];
        }

        if (\HMWEvents\PostTypes\Event::is_invitation_only($post->ID)) {
            $badges[] = ['label' => __('Invitation Only', 'hmw-events'), 'class' => 'badge--invitation'];
            $badges[] = ['label' => __('Fully Booked', 'hmw-events'), 'class' => 'badge--full'];
        }

        $session_info = $this->get_session_date_info($post->ID);

        if ($session_info['session_count'] > 0) {
            $badges[] = ['label' => __('Multi-Session', 'hmw-events'), 'class' => 'badge--multi-session'];
        }

        return [
            'id'              => $post->ID,
            'title'           => $post->post_title,
            'permalink'       => get_permalink($post),
            'excerpt'         => wp_trim_words($post->post_excerpt ?: $post->post_content, 30),
            'start_date'      => $start_date,
            'end_date'        => $end_date,
            'formatted_date'  => $start_date ? date_i18n('j M Y', strtotime($start_date)) : '',
            'price'           => $price,
            'is_free'         => $is_free || $price <= 0,
            'venue'           => $venue,
            'venue_address'   => $venue_addr,
            'webinar_url'     => $webinar_url,
            'capacity'        => $capacity,
            'delivery_mode'   => $delivery_mode,
            'delivery_mode_label' => $delivery_mode_label,
            'delivery_mode_icon'  => $this->delivery_mode_icon($delivery_terms[0] ?? null),
            'event_types'     => $event_types ?: [],
            'event_type_name' => $event_type_name,
            'audiences'       => $audiences ?: [],
            'audience_terms'  => $audience_terms,
            'suburb'          => $suburb,
            'badges'          => $badges,
            'thumbnail'       => get_the_post_thumbnail_url($post->ID, 'medium'),
            'is_recurring'    => $session_info['is_recurring'],
            'session_dates'   => $session_info['session_dates'],
            'session_summary' => $session_info['session_summary'],
            'session_count'   => $session_info['session_count'],
            'is_multi_session' => $session_info['session_count'] > 0,
        ];
    }

    /**
     * Resolve the delivery mode slug and label from a term (or slug fallback).
     *
     * @param mixed $term WP_Term object or slug string.
     * @return array{0: string, 1: string} Slug and display label.
     */
    private function resolve_delivery_mode(mixed $term): array
    {
        if (is_object($term) && isset($term->slug)) {
            $slug = (string) $term->slug;
            $label = (string) ($term->name ?? '');

            if ($label === '') {
                $label = $slug;
            }

            return [$slug, $label];
        }

        if (is_string($term) && $term !== '') {
            $label = match ($term) {
                'online' => __('Online', 'hmw-events'),
                'hybrid' => __('Hybrid', 'hmw-events'),
                default => __('In Person', 'hmw-events'),
            };

            return [$term, $label];
        }

        return ['in-person', __('In Person', 'hmw-events')];
    }

    /**
     * Build icon HTML for the delivery mode pill from the term's ACF icon field.
     *
     * @param mixed $term WP_Term object for the assigned delivery mode.
     */
    private function delivery_mode_icon(mixed $term): string
    {
        if (!is_object($term) || !isset($term->term_id) || !function_exists('get_field')) {
            return '';
        }

        $icon = get_field('icon', \HMWEvents\Taxonomies\EventDeliveryMode::TAXONOMY . '_' . (int) $term->term_id);

        $attachment_id = 0;
        $url = '';

        if (is_array($icon)) {
            $attachment_id = (int) ($icon['ID'] ?? 0);
            $url = (string) ($icon['url'] ?? '');
        } elseif (is_numeric($icon)) {
            $attachment_id = (int) $icon;
            $url = (string) wp_get_attachment_url($attachment_id);
        } elseif (is_string($icon) && $icon !== '') {
            $url = $icon;
        }

        if ($url === '') {
            return '';
        }

        if ($this->is_svg_attachment($attachment_id, $url)) {
            $svg = $this->load_svg($attachment_id);
            if ($svg !== '') {
                return '<span class="hmw-delivery-pill__icon" aria-hidden="true">' . $this->sanitize_svg($svg) . '</span>';
            }
        }

        return '<img class="hmw-delivery-pill__icon" src="' . esc_url($url) . '" alt="" aria-hidden="true" loading="lazy" />';
    }

    private function is_svg_attachment(int $attachment_id, string $url): bool
    {
        if ($attachment_id > 0) {
            $mime = (string) get_post_mime_type($attachment_id);
            if (str_contains($mime, 'svg')) {
                return true;
            }
        }

        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?: $url);

        return str_ends_with(strtolower($path), '.svg');
    }

    private function load_svg(int $attachment_id): string
    {
        if ($attachment_id <= 0) {
            return '';
        }

        $file = (string) get_attached_file($attachment_id);
        if ($file === '' || !is_readable($file)) {
            return '';
        }

        $contents = file_get_contents($file);

        return is_string($contents) ? $contents : '';
    }

    private function sanitize_svg(string $svg): string
    {
        $patterns = [
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<\?php\b.*?\?>/is',
            '/<\?xml[^>]*\?>/i',
            '/<!DOCTYPE[^>]*>/i',
            '/\son\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            '/\s(?:href|xlink:href)\s*=\s*(?:"\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)/i',
        ];

        foreach ($patterns as $pattern) {
            $svg = preg_replace($pattern, '', $svg) ?? '';
        }

        return trim($svg);
    }

    /**
     * Normalize taxonomy terms into name/slug/link arrays.
     *
     * Accepts WP_Term objects or slug strings (as returned when a caller
     * passes ['fields' => 'slugs']).
     *
     * @param mixed  $terms    Terms from wp_get_object_terms().
     * @param string $taxonomy Taxonomy slug used to resolve term links.
     * @return array<int, array{name: string, slug: string, link: string}>
     */
    private function term_link_list(mixed $terms, string $taxonomy): array
    {
        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        $list = [];

        foreach ($terms as $term) {
            if (is_object($term)) {
                $slug = (string) ($term->slug ?? '');
                $name = (string) ($term->name ?? '');
            } elseif (is_string($term)) {
                $slug = $term;
                $name = $term;
            } else {
                continue;
            }

            if ($slug === '' || $name === '') {
                continue;
            }

            $link = get_term_link(is_object($term) ? $term : $slug, $taxonomy);

            $list[] = [
                'name' => $name,
                'slug' => $slug,
                'link' => is_wp_error($link) ? '' : (string) $link,
            ];
        }

        return $list;
    }

    /**
     * Get session date info for a parent event.
     */
    private function get_session_date_info(int $event_id): array
    {
        $result = [
            'is_recurring'    => false,
            'session_dates'   => [],
            'session_summary' => '',
            'session_count'   => 0,
        ];

        $sessions = $this->session_service()->get_sessions($event_id, 'publish');
        if (empty($sessions)) {
            return $result;
        }

        $dates = [];
        $now = current_time('mysql');
        $eds = $this->event_data();

        foreach ($sessions as $session) {
            $start = $eds->get_start_date($session->ID);
            if ($start && $start >= $now) {
                $dates[] = [
                    'raw'       => $start,
                    'formatted' => date_i18n('j M Y', strtotime($start)),
                ];
            }
        }

        if (empty($dates)) {
            return $result;
        }

        $count = count($dates);
        $result['is_recurring']  = true;
        $result['session_dates'] = $dates;
        $result['session_count'] = $count;

        if ($count <= 3) {
            $formatted = [];
            foreach ($dates as $d) {
                $formatted[] = $d['formatted'];
            }
            $result['session_summary'] = implode(', ', $formatted);
        } else {
            $first_date = $dates[0]['formatted'];
            $session_label = sprintf(
                _n('%d session', '%d sessions', $count, 'hmw-events'),
                $count
            );
            $result['session_summary'] = sprintf(
                '%s %s — %s',
                __('Multiple dates from', 'hmw-events'),
                $first_date,
                $session_label
            );
        }

        return $result;
    }

    // ================================================================
    // RENDERING
    // ================================================================

    /**
     * Render the event listings shortcode.
     *
     * [hmw_event_listings type="workshop" audience="parents" topic="sleep" mode="in-person" location="Penrith" free="1" limit="12" show_filters="yes" sort="date" sort_order="ASC" event_type_filter_parent="23"]
     */
    public function render_listings(array $atts = [], string $content = '', ?string $base_url = null): string
    {
        $atts = $this->sanitize_atts(shortcode_atts($this->att_defaults(), $atts));

        $show_filters = $this->is_truthy($atts['show_filters']);

        $page_link = $base_url ?? get_permalink();
        $this->request_base_url = is_string($page_link) && $page_link !== '' ? $page_link : '';

        $filters = $this->parse_filter_params($atts);

        $query = $this->query($filters);

        ob_start();

        echo '<div class="hmw-event-listings-wrapper">';

        if ($show_filters) {
            echo '<div class="hmw-event-listings-layout">';
            echo '<div class="hmw-event-listings-region hmw-event-listings-layout__main" id="hmw-event-listings-region" aria-live="polite">';
            echo $this->render_results_region($query, $filters, $atts);
            echo '</div>';
            echo '<aside class="hmw-event-listings-layout__sidebar">';
            $this->render_filter_bar($filters, $atts);
            echo '</aside>';
            echo '</div>';
        } else {
            if ($query->have_posts()) {
                echo '<div class="hmw-event-listings">';
                while ($query->have_posts()) {
                    $query->the_post();
                    $card = $this->get_event_card($query->post);
                    $this->render_card($card);
                }
                echo '</div>';

                if ($query->max_num_pages > 1) {
                    $this->render_pagination($query);
                }
            } else {
                $this->render_empty_state();
            }
        }

        echo '</div>'; // .hmw-event-listings-wrapper

        wp_reset_postdata();
        return ob_get_clean();
    }

    /**
     * Render listings restricted to a single taxonomy term, for archive pages.
     *
     * Uses the same card grid as the listings shortcode with the filter bar
     * disabled, and bases pagination on the term archive URL.
     */
    public function render_term_listings(object $term, array $atts = []): string
    {
        $link = get_term_link($term);
        $base_url = is_wp_error($link) || !is_string($link) ? '' : $link;

        $att_key = $this->att_key_for_taxonomy($term->taxonomy);

        return $this->render_listings(array_merge([
            $att_key      => $term->slug,
            'show_filters' => 'no',
        ], $atts), '', $base_url);
    }

    /**
     * Render the filterable results region fragment shared by the page
     * render and the AJAX endpoint.
     */
    private function render_results_region(\WP_Query $query, array $filters, array $atts): string
    {
        ob_start();

        $this->render_results_bar($query, $filters);
        $this->render_active_filters($filters);

        if ($query->have_posts()) {
            echo '<div class="hmw-event-listings">';
            while ($query->have_posts()) {
                $query->the_post();
                $card = $this->get_event_card($query->post);
                $this->render_card($card);
            }
            echo '</div>';
        } else {
            $this->render_empty_state();
        }

        if ($query->max_num_pages > 1) {
            $this->render_pagination($query);
        }

        return (string) ob_get_clean();
    }

    /**
     * Render the results bar with count and mobile filter toggle.
     */
    private function render_results_bar(\WP_Query $query, array $filters): void
    {
        ?>
        <div class="hmw-results-bar">
            <span class="hmw-results-bar__count">
                <?php
                printf(
                    /* translators: %d: number of events found */
                    wp_kses_post(_n('<strong>%d</strong> event found', '<strong>%d</strong> events found', (int) $query->found_posts, 'hmw-events')),
                    (int) $query->found_posts
                );
                ?>
            </span>
            <button type="button" class="hmw-results-bar__toggle" aria-expanded="false" aria-controls="hmw-event-filters">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M1.5 3.5h13M4 8h8M6.5 12.5h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <?php esc_html_e('Filters', 'hmw-events'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render the empty state.
     */
    private function render_empty_state(): void
    {
        ?>
        <div class="hmw-no-events">
            <svg class="hmw-no-events__icon" width="48" height="48" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M8 2v2m8-2v2M3 10h18M5 4h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <p class="hmw-no-events__text"><?php esc_html_e('No events found.', 'hmw-events'); ?></p>
            <p class="hmw-no-events__sub"><?php esc_html_e('Try adjusting your filters or check back later.', 'hmw-events'); ?></p>
        </div>
        <?php
    }

    /**
     * Parse URL filter parameters, merging shortcode pre-set values.
     */
    private function parse_filter_params(array $atts): array
    {
        $filters                   = [];
        $filters['posts_per_page'] = (int) $atts['limit'];
        $filters['show_type_filter'] = true;

        $type_filter_parent = (int) $atts['event_type_filter_parent'];
        if ($type_filter_parent > 0) {
            $filters['type_filter_parent'] = $type_filter_parent;
        }

        if ($atts['type']) {
            $filters['event_type'] = explode(',', $atts['type']);
            $filters['event_type_is_restriction'] = true;
            if ($type_filter_parent === 0) {
                $filters['show_type_filter'] = false;
            }
        }

        $url_type = isset($_GET['ev_type']) ? (array) $_GET['ev_type'] : [];
        $url_type = array_map('sanitize_text_field', $url_type);
        $url_type = array_filter($url_type);

        if ($url_type && ($type_filter_parent > 0 || empty($filters['event_type']))) {
            $filters['event_type'] = $url_type;
            unset($filters['event_type_is_restriction']);
        }

        if ($atts['audience']) {
            $filters['audience'] = explode(',', $atts['audience']);
        }

        foreach (TaxonomyRegistry::filter_key_map() as $content_taxonomy => $filter_key) {
            if (!empty($atts[$filter_key])) {
                $filters[$filter_key] = explode(',', (string) $atts[$filter_key]);
                $filters[$filter_key . '_is_restriction'] = true;
            } else {
                $url_values = isset($_GET['ev_' . $filter_key]) ? (array) $_GET['ev_' . $filter_key] : [];
                $url_values = array_filter(array_map('sanitize_text_field', $url_values));
                if ($url_values) {
                    $filters[$filter_key] = $url_values;
                }
            }
        }

        if ($atts['mode']) {
            $filters['delivery_mode'] = explode(',', $atts['mode']);
            $filters['delivery_mode_is_restriction'] = true;
        } else {
            $url_mode = isset($_GET['ev_mode']) ? (array) $_GET['ev_mode'] : [];
            $url_mode = array_map('sanitize_text_field', $url_mode);
            $url_mode = array_filter($url_mode);
            if ($url_mode) {
                $filters['delivery_mode'] = $url_mode;
            }
        }

        if ($atts['location']) {
            $filters['location'] = explode(',', $atts['location']);
            $filters['location_is_restriction'] = true;
        } elseif ($atts['state']) {
            $filters['location'] = explode(',', $atts['state']);
            $filters['location_is_restriction'] = true;
        } else {
            $url_location = isset($_GET['ev_location']) ? (array) $_GET['ev_location'] : [];
            if (!$url_location) {
                $url_location = isset($_GET['ev_state']) ? (array) $_GET['ev_state'] : [];
            }
            $url_location = array_map('sanitize_text_field', $url_location);
            $url_location = array_filter($url_location);
            if ($url_location) {
                $filters['location'] = $url_location;
            }
        }

        if ($atts['free'] === '1') {
            $filters['free_only'] = true;
        } else {
            $url_price = array_map('sanitize_text_field', (array) ($_GET['ev_price'] ?? []));
            $wants_free = in_array('free', $url_price, true);
            $wants_paid = in_array('paid', $url_price, true);
            if ($wants_free && !$wants_paid) {
                $filters['free_only'] = true;
            } elseif ($wants_paid && !$wants_free) {
                $filters['paid_only'] = true;
            }
        }

        $url_day = array_map('sanitize_text_field', (array) ($_GET['ev_day'] ?? []));
        $url_day = array_values(array_intersect($url_day, ['weekday', 'weekend']));
        if (count($url_day) === 1) {
            $filters['event_day'] = $url_day;
        }

        $url_month = array_map('intval', (array) ($_GET['ev_month'] ?? []));
        $url_month = array_values(array_unique(array_filter($url_month, fn($m) => $m >= 1 && $m <= 12)));
        sort($url_month);
        if ($url_month && count($url_month) < 12) {
            $filters['months'] = $url_month;
        }

        $url_date_from = sanitize_text_field($_GET['ev_date_from'] ?? '');
        if ($url_date_from) {
            $filters['date_from'] = $url_date_from;
        }

        $url_date_to = sanitize_text_field($_GET['ev_date_to'] ?? '');
        if ($url_date_to) {
            $filters['date_to'] = $url_date_to;
        }

        $url_search = sanitize_text_field($_GET['ev_s'] ?? '');
        if ($url_search) {
            $filters['search'] = $url_search;
        }

        $url_sort   = sanitize_text_field($_GET['ev_sort'] ?? '');

        if ($url_sort && in_array($url_sort, self::VALID_SORTS, true)) {
            $filters['sort'] = $url_sort;
        } else {
            $sort_field = $atts['sort'];
            $sort_order = strtoupper($atts['sort_order']) === 'DESC' ? 'desc' : 'asc';
            $default    = $sort_field . '_' . $sort_order;
            $filters['sort'] = in_array($default, self::VALID_SORTS, true) ? $default : 'date_asc';
        }

        $url_page = (int) ($_GET['pg'] ?? 1);
        if ($url_page > 1) {
            $filters['page'] = $url_page;
        }

        return $filters;
    }

    /**
     * Render the filter bar.
     */
    private function render_filter_bar(array $filters, array $atts): void
    {
        $type_filter_parent = (int) ($filters['type_filter_parent'] ?? 0);
        $event_types        = $this->get_filter_terms('hmw_event_type', $type_filter_parent);
        $delivery_modes  = $this->get_filter_terms('hmw_event_delivery_mode');
        $suburbs         = $this->venue_suburbs();
        $content_filters = $this->content_filter_sections($filters);

        $active_types      = isset($filters['event_type']) ? (array) $filters['event_type'] : [];
        $active_modes      = isset($filters['delivery_mode']) ? (array) $filters['delivery_mode'] : [];
        $active_location   = isset($filters['location']) ? (array) $filters['location'] : [];
        $active_location   = $active_location[0] ?? '';
        $active_days       = isset($filters['event_day']) ? (array) $filters['event_day'] : [];
        $active_months     = isset($filters['months']) ? array_map('intval', (array) $filters['months']) : [];
        $active_price      = isset($filters['free_only']) && $filters['free_only'] ? 'free'
            : (isset($filters['paid_only']) && $filters['paid_only'] ? 'paid' : '');
        $active_date_from  = $filters['date_from'] ?? '';
        $active_date_to    = $filters['date_to'] ?? '';
        $active_sort       = $filters['sort'] ?? 'date_asc';
        $active_search     = $filters['search'] ?? '';

        ?>
        <div class="hmw-event-filters" id="hmw-event-filters">
            <form method="get" class="hmw-event-filters__form"
                data-hmw-atts="<?php echo esc_attr($this->encode_atts_payload($atts)); ?>"
                data-hmw-base-url="<?php echo esc_url($this->get_request_base_url()); ?>"
            >

                <label class="hmw-event-filters__search">
                    <span class="screen-reader-text"><?php esc_html_e('Search Events', 'hmw-events'); ?></span>
                    <input type="search" name="ev_s" value="<?php echo esc_attr($active_search); ?>"
                        placeholder="<?php esc_attr_e('Search Events', 'hmw-events'); ?>" />
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <circle cx="7" cy="7" r="4.5" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </label>

                <a href="<?php echo esc_url($this->get_request_base_url()); ?>" class="hmw-event-filters__clear">
                    <?php esc_html_e('Clear All Filters', 'hmw-events'); ?>
                </a>

                <div class="hmw-event-filters__inner">

                    <?php if ($suburbs): ?>
                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <path d="M8 14s6-3.5 6-7A6 6 0 002 7c0 3.5 6 7 6 7z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <circle cx="8" cy="7" r="1.5" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <?php esc_html_e('Location', 'hmw-events'); ?>
                        </span>
                        <select name="ev_location" class="hmw-event-filters__select">
                            <option value=""><?php esc_html_e('All Locations', 'hmw-events'); ?></option>
                            <?php foreach ($suburbs as $suburb): ?>
                                <option value="<?php echo esc_attr($suburb); ?>" <?php selected($active_location, $suburb); ?>>
                                    <?php echo esc_html($suburb); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php foreach ($content_filters as $content_filter): ?>
                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <path d="M2 2.8c0-.44.36-.8.8-.8h4.5c.21 0 .42.08.57.23l5.9 5.9a.8.8 0 010 1.14l-4.5 4.5a.8.8 0 01-1.14 0l-5.9-5.9A.8.8 0 012 7.3V2.8z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                                <circle cx="5.4" cy="5.4" r="1" fill="currentColor"/>
                            </svg>
                            <?php echo esc_html($content_filter['label']); ?>
                        </span>
                        <div class="hmw-event-filters__options">
                            <?php foreach ($content_filter['terms'] as $term): ?>
                                <label class="hmw-event-filters__checkbox">
                                    <input type="checkbox" name="ev_<?php echo esc_attr($content_filter['filter_key']); ?>[]" value="<?php echo esc_attr($term->slug); ?>"
                                        <?php checked(in_array($term->slug, $content_filter['active'], true)); ?> />
                                    <?php echo esc_html($this->get_term_label($term)); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($delivery_modes): ?>
                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <rect x="2" y="2.5" width="12" height="8.5" rx="1" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M6 14h4M8 11v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <?php esc_html_e('Delivery Mode', 'hmw-events'); ?>
                        </span>
                        <div class="hmw-event-filters__options">
                            <?php foreach ($delivery_modes as $term): ?>
                                <label class="hmw-event-filters__checkbox">
                                    <input type="checkbox" name="ev_mode[]" value="<?php echo esc_attr($term->slug); ?>"
                                        <?php checked(in_array($term->slug, $active_modes, true)); ?> />
                                    <?php echo esc_html($this->get_term_label($term)); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <path d="M8 1.5v13M10.8 4C10.2 3.4 9.2 3 8 3 6.3 3 5 3.9 5 5.1c0 2.8 6 1.5 6 4.5 0 1.3-1.3 2.2-3 2.2-1.4 0-2.6-.5-3.1-1.3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <?php esc_html_e('Cost', 'hmw-events'); ?>
                        </span>
                        <div class="hmw-event-filters__options">
                            <label class="hmw-event-filters__checkbox">
                                <input type="checkbox" name="ev_price[]" value="paid"
                                    <?php checked($active_price, 'paid'); ?> />
                                <?php esc_html_e('Paid', 'hmw-events'); ?>
                            </label>
                            <label class="hmw-event-filters__checkbox">
                                <input type="checkbox" name="ev_price[]" value="free"
                                    <?php checked($active_price, 'free'); ?> />
                                <?php esc_html_e('Free', 'hmw-events'); ?>
                            </label>
                        </div>
                    </div>

                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M8 4.5V8l2.5 1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <?php esc_html_e('Event Day', 'hmw-events'); ?>
                        </span>
                        <div class="hmw-event-filters__options">
                            <label class="hmw-event-filters__checkbox">
                                <input type="checkbox" name="ev_day[]" value="weekday"
                                    <?php checked(in_array('weekday', $active_days, true)); ?> />
                                <?php esc_html_e('Weekdays', 'hmw-events'); ?>
                            </label>
                            <label class="hmw-event-filters__checkbox">
                                <input type="checkbox" name="ev_day[]" value="weekend"
                                    <?php checked(in_array('weekend', $active_days, true)); ?> />
                                <?php esc_html_e('Weekends', 'hmw-events'); ?>
                            </label>
                        </div>
                    </div>

                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <rect x="2" y="3.5" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M2 6.5h12M5.5 1.5v3M10.5 1.5v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <circle cx="5.5" cy="9.7" r=".9" fill="currentColor"/>
                                <circle cx="8" cy="9.7" r=".9" fill="currentColor"/>
                                <circle cx="10.5" cy="9.7" r=".9" fill="currentColor"/>
                            </svg>
                            <?php esc_html_e('Month', 'hmw-events'); ?>
                        </span>
                        <div class="hmw-event-filters__options hmw-event-filters__options--grid">
                            <?php for ($month = 1; $month <= 12; $month++): ?>
                                <label class="hmw-event-filters__checkbox">
                                    <input type="checkbox" name="ev_month[]" value="<?php echo esc_attr((string) $month); ?>"
                                        <?php checked(in_array($month, $active_months, true)); ?> />
                                    <?php echo esc_html($this->month_label($month)); ?>
                                </label>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <?php if ($filters['show_type_filter'] && $event_types): ?>
                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <rect x="2" y="4.5" width="12" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M6 4.5V3.4c0-.77.63-1.4 1.4-1.4h1.2c.77 0 1.4.63 1.4 1.4v1.1M2 8.5h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <?php esc_html_e('Event Type', 'hmw-events'); ?>
                        </span>
                        <div class="hmw-event-filters__options">
                            <?php foreach ($event_types as $term): ?>
                                <label class="hmw-event-filters__checkbox">
                                    <input type="checkbox" name="ev_type[]" value="<?php echo esc_attr($term->slug); ?>"
                                        <?php checked(in_array($term->slug, $active_types, true)); ?> />
                                    <?php echo esc_html($this->get_term_label($term)); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <path d="M5.5 1v2m5-2v2M2 6.5h12M3.5 3h9a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 012 13.5v-9A1.5 1.5 0 013.5 3z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <?php esc_html_e('Date Range', 'hmw-events'); ?>
                        </span>
                        <label class="hmw-event-filters__date">
                            <span><?php esc_html_e('From', 'hmw-events'); ?></span>
                            <input type="date" name="ev_date_from" value="<?php echo esc_attr($active_date_from); ?>" />
                        </label>
                        <label class="hmw-event-filters__date">
                            <span><?php esc_html_e('To', 'hmw-events'); ?></span>
                            <input type="date" name="ev_date_to" value="<?php echo esc_attr($active_date_to); ?>" />
                        </label>
                    </div>

                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <path d="M5 3.5v9M5 12.5L2.5 10M5 12.5L7.5 10M11 12.5v-9M11 3.5L8.5 6M11 3.5L13.5 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <?php esc_html_e('Sort By', 'hmw-events'); ?>
                        </span>
                        <select name="ev_sort" class="hmw-event-filters__select">
                            <option value="date_desc" <?php selected($active_sort, 'date_desc'); ?>>
                                <?php esc_html_e('Date: Newest First', 'hmw-events'); ?>
                            </option>
                            <option value="date_asc" <?php selected($active_sort, 'date_asc'); ?>>
                                <?php esc_html_e('Date: Oldest First', 'hmw-events'); ?>
                            </option>
                            <option value="price_asc" <?php selected($active_sort, 'price_asc'); ?>>
                                <?php esc_html_e('Price: Low to High', 'hmw-events'); ?>
                            </option>
                            <option value="price_desc" <?php selected($active_sort, 'price_desc'); ?>>
                                <?php esc_html_e('Price: High to Low', 'hmw-events'); ?>
                            </option>
                            <option value="title_asc" <?php selected($active_sort, 'title_asc'); ?>>
                                <?php esc_html_e('Title: A\u{2013}Z', 'hmw-events'); ?>
                            </option>
                            <option value="title_desc" <?php selected($active_sort, 'title_desc'); ?>>
                                <?php esc_html_e('Title: Z\u{2013}A', 'hmw-events'); ?>
                            </option>
                        </select>
                    </div>

                    <div class="hmw-event-filters__actions">
                        <button type="submit" class="hmw-event-filters__apply">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                <path d="M1.5 3.5h13M4 8h8M6.5 12.5h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <?php esc_html_e('Apply Filters', 'hmw-events'); ?>
                        </button>
                    </div>

                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Month name for 1-12, empty string otherwise.
     */
    private function month_label(int $month): string
    {
        return match ($month) {
            1 => __('January', 'hmw-events'),
            2 => __('February', 'hmw-events'),
            3 => __('March', 'hmw-events'),
            4 => __('April', 'hmw-events'),
            5 => __('May', 'hmw-events'),
            6 => __('June', 'hmw-events'),
            7 => __('July', 'hmw-events'),
            8 => __('August', 'hmw-events'),
            9 => __('September', 'hmw-events'),
            10 => __('October', 'hmw-events'),
            11 => __('November', 'hmw-events'),
            12 => __('December', 'hmw-events'),
            default => '',
        };
    }

    /**
     * Render active filter tags with remove links.
     */
    private function render_active_filters(array $filters): void
    {
        $tags = [];

        if (isset($filters['event_type']) && $filters['show_type_filter'] && empty($filters['event_type_is_restriction'])) {
            $terms = get_terms(['taxonomy' => 'hmw_event_type', 'slug' => (array) $filters['event_type'], 'hide_empty' => false]);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $tags[] = [
                        'label'  => $this->get_term_label($term),
                        'group'  => __('Type', 'hmw-events'),
                        'remove' => 'ev_type',
                        'value'  => $term->slug,
                    ];
                }
            }
        }

        if (isset($filters['delivery_mode']) && empty($filters['delivery_mode_is_restriction'])) {
            $terms = get_terms(['taxonomy' => 'hmw_event_delivery_mode', 'slug' => (array) $filters['delivery_mode'], 'hide_empty' => false]);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $tags[] = [
                        'label'  => $this->get_term_label($term),
                        'group'  => __('Mode', 'hmw-events'),
                        'remove' => 'ev_mode',
                        'value'  => $term->slug,
                    ];
                }
            }
        }

        foreach (TaxonomyRegistry::filter_key_map() as $content_taxonomy => $filter_key) {
            if (!isset($filters[$filter_key]) || !empty($filters[$filter_key . '_is_restriction'])) {
                continue;
            }

            $terms = get_terms(['taxonomy' => $content_taxonomy, 'slug' => (array) $filters[$filter_key], 'hide_empty' => false]);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $tags[] = [
                        'label'  => $this->get_term_label($term),
                        'group'  => $this->content_filter_label($content_taxonomy),
                        'remove' => 'ev_' . $filter_key,
                        'value'  => $term->slug,
                    ];
                }
            }
        }

        if (isset($filters['location']) && empty($filters['location_is_restriction'])) {
            foreach (array_values(array_filter((array) $filters['location'])) as $suburb) {
                $tags[] = [
                    'label'  => $suburb,
                    'group'  => __('Location', 'hmw-events'),
                    'remove' => 'ev_location',
                    'value'  => $suburb,
                ];
            }
        }

        if (isset($filters['free_only']) && $filters['free_only']) {
            $tags[] = ['label' => __('Free', 'hmw-events'), 'group' => __('Cost', 'hmw-events'), 'remove' => 'ev_price', 'value' => ''];
        } elseif (isset($filters['paid_only']) && $filters['paid_only']) {
            $tags[] = ['label' => __('Paid', 'hmw-events'), 'group' => __('Cost', 'hmw-events'), 'remove' => 'ev_price', 'value' => ''];
        }

        if (!empty($filters['event_day'])) {
            foreach ((array) $filters['event_day'] as $day) {
                if ($day === 'weekday' || $day === 'weekend') {
                    $tags[] = [
                        'label'  => $day === 'weekday' ? __('Weekdays', 'hmw-events') : __('Weekends', 'hmw-events'),
                        'group'  => __('Event Day', 'hmw-events'),
                        'remove' => 'ev_day',
                        'value'  => $day,
                    ];
                }
            }
        }

        if (!empty($filters['months'])) {
            foreach ((array) $filters['months'] as $month) {
                $month_label = $this->month_label((int) $month);
                if ($month_label !== '') {
                    $tags[] = [
                        'label'  => $month_label,
                        'group'  => __('Month', 'hmw-events'),
                        'remove' => 'ev_month',
                        'value'  => (string) (int) $month,
                    ];
                }
            }
        }

        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $label_parts = [];
            if (!empty($filters['date_from'])) $label_parts[] = $filters['date_from'];
            if (!empty($filters['date_to']))   $label_parts[] = $filters['date_to'];
            $tags[] = [
                'label'  => implode(' — ', $label_parts),
                'group'  => __('Dates', 'hmw-events'),
                'remove' => 'date_range',
                'value'  => '',
            ];
        }

        if (!empty($filters['search'])) {
            $tags[] = [
                'label'  => $filters['search'],
                'group'  => __('Search', 'hmw-events'),
                'remove' => 'ev_s',
                'value'  => '',
            ];
        }

        if (empty($tags)) {
            return;
        }

        echo '<div class="hmw-active-filters">';
        echo '<span class="hmw-active-filters__label">' . esc_html__('Active Filters:', 'hmw-events') . '</span>';

        foreach ($tags as $tag) {
            $remove_url = $this->build_filter_remove_url($tag['remove'], $tag['value']);
            printf(
                '<span class="hmw-active-filter">%s: %s <a href="%s" class="hmw-active-filter__remove" aria-label="%s">&times;</a></span>',
                esc_html($tag['group']),
                esc_html($tag['label']),
                esc_url($remove_url),
                esc_attr(sprintf(__('Remove %s filter', 'hmw-events'), $tag['group']))
            );
        }

        echo '</div>';
    }

    /**
     * Build a URL that removes a specific filter param.
     */
    private function build_filter_remove_url(string $remove_key, string $remove_value): string
    {
        $params = $_GET;
        unset(
            $params['pg'],
            $params['paged'],
            $params['action'],
            $params['hmw_atts'],
            $params['hmw_base_url']
        );

        if ($remove_key === 'date_range') {
            unset($params['ev_date_from'], $params['ev_date_to']);
        } elseif ($remove_key === 'ev_price') {
            unset($params['ev_price']);
        } elseif ($remove_key === 'ev_s') {
            unset($params['ev_s']);
        } else {
            if (isset($params[$remove_key]) && is_array($params[$remove_key])) {
                $params[$remove_key] = array_values(array_filter(
                    $params[$remove_key],
                    fn($v) => $v !== $remove_value
                ));
                if (empty($params[$remove_key])) {
                    unset($params[$remove_key]);
                }
            }
        }

        return add_query_arg($params, $this->get_request_base_url());
    }

    private function get_request_base_url(): string
    {
        if (wp_doing_ajax()) {
            $candidate = esc_url_raw((string) ($_GET['hmw_base_url'] ?? ''));
            if ($candidate !== '' && $this->is_same_site_url($candidate)) {
                return $candidate;
            }

            $referer = wp_get_referer();
            if (is_string($referer) && $referer !== '' && $this->is_same_site_url($referer)) {
                return $referer;
            }

            return home_url('/');
        }

        return $this->request_base_url !== '' ? $this->request_base_url : (string) get_permalink();
    }

    private function is_same_site_url(string $url): bool
    {
        $host      = wp_parse_url($url, PHP_URL_HOST);
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

        return is_string($host)
            && is_string($home_host)
            && strcasecmp($host, $home_host) === 0;
    }

    private function current_filter_query_args(): array
    {
        $params = [];

        foreach ((array) ($_GET ?? []) as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'ev_')) {
                continue;
            }

            if (is_array($value)) {
                $params[$key] = array_map('sanitize_text_field', array_values($value));
            } else {
                $params[$key] = sanitize_text_field((string) $value);
            }
        }

        return $params;
    }

    private function pagination_base(): string
    {
        $params   = $this->current_filter_query_args();
        $params['pg'] = 'HMPAGETOKEN';

        $template = add_query_arg($params, $this->get_request_base_url());

        return str_replace('HMPAGETOKEN', '%#%', $template);
    }

    private function build_canonical_url(array $filters, array $atts): string
    {
        $params = [];

        if (!empty($filters['event_type']) && empty($filters['event_type_is_restriction'])) {
            $params['ev_type'] = array_values((array) $filters['event_type']);
        }

        foreach (TaxonomyRegistry::filter_key_map() as $content_taxonomy => $filter_key) {
            if (!empty($filters[$filter_key]) && $atts[$filter_key] === '') {
                $params['ev_' . $filter_key] = array_values((array) $filters[$filter_key]);
            }
        }

        if (!empty($filters['delivery_mode']) && $atts['mode'] === '') {
            $params['ev_mode'] = array_values((array) $filters['delivery_mode']);
        }

        if (!empty($filters['location']) && $atts['location'] === '' && $atts['state'] === '') {
            $params['ev_location'] = array_values((array) $filters['location']);
        }

        if (!empty($filters['free_only']) && $atts['free'] !== '1') {
            $params['ev_price'] = 'free';
        } elseif (!empty($filters['paid_only'])) {
            $params['ev_price'] = 'paid';
        }

        if (!empty($filters['date_from'])) {
            $params['ev_date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $params['ev_date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $params['ev_s'] = $filters['search'];
        }

        $url_sort = sanitize_text_field((string) ($_GET['ev_sort'] ?? ''));
        if ($url_sort && in_array($url_sort, self::VALID_SORTS, true)) {
            $params['ev_sort'] = $url_sort;
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        if ($page > 1) {
            $params['pg'] = $page;
        }

        $base = (string) preg_replace('/\?.*$/', '', $this->get_request_base_url());

        if (empty($params)) {
            return $base;
        }

        return add_query_arg($params, $base);
    }

    private function att_defaults(): array
    {
        return array_merge(
            self::ATTS_DEFAULTS,
            array_fill_keys(array_values(TaxonomyRegistry::filter_key_map()), ''),
            ['location' => '']
        );
    }

    private function sanitize_atts(array $atts): array
    {
        $content_keys = array_values(TaxonomyRegistry::filter_key_map());
        $defaults = $this->att_defaults();

        $atts = array_merge($defaults, array_intersect_key($atts, $defaults));

        $text_keys = array_merge(['type', 'audience', 'mode', 'location', 'state', 'event_type_filter_parent'], $content_keys);
        foreach ($text_keys as $key) {
            $atts[$key] = is_scalar($atts[$key]) ? sanitize_text_field((string) $atts[$key]) : '';
        }

        $atts['free'] = is_scalar($atts['free']) && $this->is_truthy((string) $atts['free']) ? '1' : '';

        $atts['limit'] = is_numeric($atts['limit']) ? min(48, max(1, (int) $atts['limit'])) : 12;

        $atts['show_filters'] = is_scalar($atts['show_filters']) && $this->is_truthy((string) $atts['show_filters']) ? 'yes' : 'no';

        $sort = is_scalar($atts['sort']) ? (string) $atts['sort'] : '';
        $atts['sort'] = in_array($sort, ['date', 'price', 'title'], true) ? $sort : 'date';

        $order = is_scalar($atts['sort_order']) ? strtoupper((string) $atts['sort_order']) : '';
        $atts['sort_order'] = $order === 'DESC' ? 'DESC' : 'ASC';

        return $atts;
    }

    private function encode_atts_payload(array $atts): string
    {
        return base64_encode((string) wp_json_encode($this->sanitize_atts($atts)));
    }

    private function decode_atts_payload(?string $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = base64_decode(str_replace(' ', '+', $raw), true);

        if ($decoded === false) {
            return null;
        }

        $parsed = json_decode($decoded, true);

        if (!is_array($parsed)) {
            return null;
        }

        return $this->sanitize_atts($parsed);
    }

    /**
     * Get terms for filter checkboxes, sorted by name.
     */
    private function content_filter_sections(array $filters): array
    {
        $sections = [];

        foreach (TaxonomyRegistry::filter_key_map() as $content_taxonomy => $filter_key) {
            $terms = $this->get_filter_terms($content_taxonomy);
            if (!$terms) {
                continue;
            }

            $sections[] = [
                'filter_key' => $filter_key,
                'label'      => $this->content_filter_label($content_taxonomy),
                'terms'      => $terms,
                'active'     => isset($filters[$filter_key]) ? (array) $filters[$filter_key] : [],
            ];
        }

        return $sections;
    }

    private function content_filter_label(string $taxonomy): string
    {
        $def = TaxonomyRegistry::get($taxonomy);
        $name = $def['name'] ?? $taxonomy;

        return _x($name, 'taxonomy general name', 'hmw-events');
    }

    private function att_key_for_taxonomy(string $taxonomy): string
    {
        $content_keys = TaxonomyRegistry::filter_key_map();
        if (isset($content_keys[$taxonomy])) {
            return $content_keys[$taxonomy];
        }

        return self::TERM_TAXONOMY_ATT_KEYS[$taxonomy] ?? 'type';
    }

    private function get_filter_terms(string $taxonomy, int $parent_id = 0): array
    {
        $args = ['taxonomy' => $taxonomy, 'hide_empty' => true];
        if ($parent_id > 0) {
            $args['parent'] = $parent_id;
        }
        $terms = get_terms($args);
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }
        return $terms;
    }

    private function get_term_label(object $term): string
    {
        if ($term->name !== $term->slug) {
            return $term->name;
        }

        return ucwords(str_replace(['-', '_'], ' ', $term->slug));
    }

    /**
     * Render a single event card.
     *
     * Markup lives in views/cards/event-card.php, overridable by the theme
     * via hmw-events/cards/event-card.php.
     */
    private function render_card(?array $card): void
    {
        if (!$card) {
            return;
        }

        hmwevents_get_template_part('cards/event-card', null, ['card' => $card]);
    }

    /**
     * Simple pagination output.
     */
    private function render_pagination(\WP_Query $query): void
    {
        $total = (int) $query->max_num_pages;

        if ($total <= 1) {
            return;
        }

        $current = max(1, (int) $query->get('paged'));

        $links = paginate_links([
            'base'         => $this->pagination_base(),
            'format'       => '',
            'current'      => $current,
            'total'        => $total,
            'type'         => 'list',
            'add_args'     => false,
            'add_fragment' => '',
        ]);

        if ($links) {
            echo '<nav class="hmw-pagination">' . $links . '</nav>';
        }
    }

    /**
     * Check if a string is truthy.
     */
    private function is_truthy(string $value): bool
    {
        return in_array(strtolower($value), ['yes', '1', 'true'], true);
    }

    /**
     * AJAX filter endpoint.
     */
    public function ajax_filter(): void
    {
        $atts = $this->decode_atts_payload(wp_unslash($_GET['hmw_atts'] ?? null));

        if ($atts === null) {
            wp_send_json_error(['message' => __('Invalid listing configuration.', 'hmw-events')]);
            return;
        }

        $filters = $this->parse_filter_params($atts);

        $query = $this->query($filters);

        $fragment = $this->render_results_region($query, $filters, $atts);

        wp_reset_postdata();

        wp_send_json_success([
            'html'        => $fragment,
            'url'         => $this->build_canonical_url($filters, $atts),
            'found_posts' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        ]);
    }
}
