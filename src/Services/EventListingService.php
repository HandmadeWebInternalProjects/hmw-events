<?php

/**
 * Event Listing Service.
 *
 * Provides front-end event listing and filtering via WP_Query wrapper.
 * Supports filtering by:
 * - Event type (webinar, workshop, course, etc.)
 * - Audience (parents, professionals, etc.)
 * - Delivery mode (in-person, online, hybrid)
 * - State (AU state)
 * - Free vs paid
 * - Date range
 * - Search keyword
 * - Sort (date, price, title)
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventListingService
{
    private ?SessionService $session_service = null;

    private function session_service(): SessionService
    {
        if ($this->session_service === null) {
            $this->session_service = new SessionService();
        }
        return $this->session_service;
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
     *     topic:            string|string[]    Topic slug(s).
     *     delivery_mode:    string|string[]    Delivery mode slug(s).
     *     state:            string|string[]    State slug(s).
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

        $args = [
            'post_type'      => 'hmw_event',
            'post_status'    => ['publish', 'fully_booked'],
            'posts_per_page' => $filters['posts_per_page'] ?? 12,
            'paged'          => $paged,
            'meta_query'     => [
                [
                    'key'     => \HMWEvents\PostTypes\Event::META_INVITATION_ONLY,
                    'compare' => 'NOT EXISTS',
                ],
            ],
            'tax_query'      => [],
            's'              => $filters['search'] ?? '',
            'post_parent'    => !empty($filters['show_child_sessions']) ? '' : 0,
        ];

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

        if (!empty($filters['topic'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'hmw_event_topic',
                'field'    => 'slug',
                'terms'    => (array) $filters['topic'],
            ];
        }

        if (!empty($filters['delivery_mode'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'hmw_event_delivery_mode',
                'field'    => 'slug',
                'terms'    => (array) $filters['delivery_mode'],
            ];
        }

        if (!empty($filters['state'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'hmw_event_state',
                'field'    => 'slug',
                'terms'    => (array) $filters['state'],
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
        return new \WP_Query($this->build_query($filters));
    }

    /**
     * Get an event card data array for frontend rendering.
     *
     * @return array|null
     */
    public function get_event_card($post): ?array
    {
        $start_date = get_post_meta($post->ID, '_event_start_date', true);
        $end_date   = get_post_meta($post->ID, '_event_end_date', true);
        $price      = (float) get_post_meta($post->ID, '_event_price', true);
        $venue      = get_post_meta($post->ID, '_event_venue_name', true);
        $venue_addr = get_post_meta($post->ID, '_event_venue_address', true);
        $capacity   = (int) get_post_meta($post->ID, '_event_capacity', true);
        $webinar_url = get_post_meta($post->ID, '_event_webinar_url', true);
        $is_free    = (bool) get_post_meta($post->ID, '_event_is_free', true);

        $event_types    = wp_get_object_terms($post->ID, 'hmw_event_type', ['fields' => 'slugs']);
        $event_type_names = wp_get_object_terms($post->ID, 'hmw_event_type', ['fields' => 'names']);
        $event_type_name  = $event_type_names[0] ?? '';
        $audiences      = wp_get_object_terms($post->ID, 'hmw_event_audience', ['fields' => 'slugs']);
        $delivery_modes = wp_get_object_terms($post->ID, 'hmw_event_delivery_mode', ['fields' => 'slugs']);

        $badges = [];
        $delivery_mode = $delivery_modes[0] ?? 'in-person';

        switch ($delivery_mode) {
            case 'online':
                $badges[] = ['label' => __('Online', 'hmw-events'), 'class' => 'badge--online'];
                break;
            case 'hybrid':
                $badges[] = ['label' => __('Hybrid', 'hmw-events'), 'class' => 'badge--hybrid'];
                break;
            default:
                $badges[] = ['label' => __('In Person', 'hmw-events'), 'class' => 'badge--in-person'];
        }

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
            'event_types'     => $event_types ?: [],
            'event_type_name' => $event_type_name,
            'audiences'       => $audiences ?: [],
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

        foreach ($sessions as $session) {
            $start = get_post_meta($session->ID, '_event_start_date', true);
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
     * [hmw_event_listings type="workshop" audience="parents" topic="sleep" mode="in-person" state="nsw" free="1" limit="12" show_filters="yes" sort="date" sort_order="ASC"]
     */
    public function render_listings(array $atts = [], string $content = ''): string
    {
        $atts = shortcode_atts([
            'type'         => '',
            'audience'     => '',
            'topic'        => '',
            'mode'         => '',
            'state'        => '',
            'free'         => '',
            'limit'        => 12,
            'show_filters' => 'yes',
            'sort'         => 'date',
            'sort_order'   => 'ASC',
        ], $atts);

        $show_filters = $this->is_truthy($atts['show_filters']);

        $filters = $this->parse_filter_params($atts);

        $query = $this->query($filters);

        ob_start();

        echo '<div class="hmw-event-listings-wrapper">';

        if ($show_filters) {
            $this->render_results_bar($query, $filters);
            $this->render_filter_bar($filters, $atts);
            $this->render_active_filters($filters);
        }

        if ($query->have_posts()) {
            echo '<div class="hmw-event-listings">';
            while ($query->have_posts()) {
                $query->the_post();
                $card = $this->get_event_card($query->post);
                $this->render_card($card);
            }
            echo '</div>';

            if (!$show_filters && $query->max_num_pages > 1) {
                $this->render_pagination($query);
            }
        } else {
            $this->render_empty_state();
        }

        if ($show_filters && $query->max_num_pages > 1) {
            $this->render_pagination($query);
        }

        echo '</div>'; // .hmw-event-listings-wrapper

        wp_reset_postdata();
        return ob_get_clean();
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
        $filters                = [];
        $filters['posts_per_page'] = (int) $atts['limit'];
        $filters['show_type_filter'] = true;

        if ($atts['type']) {
            $filters['event_type'] = explode(',', $atts['type']);
            $filters['show_type_filter'] = false;
        } else {
            $url_type = isset($_GET['ev_type']) ? (array) $_GET['ev_type'] : [];
            $url_type = array_map('sanitize_text_field', $url_type);
            $url_type = array_filter($url_type);
            if ($url_type) {
                $filters['event_type'] = $url_type;
            }
        }

        if ($atts['audience']) {
            $filters['audience'] = explode(',', $atts['audience']);
        }

        if ($atts['topic']) {
            $filters['topic'] = explode(',', $atts['topic']);
        } else {
            $url_topic = isset($_GET['ev_topic']) ? (array) $_GET['ev_topic'] : [];
            $url_topic = array_map('sanitize_text_field', $url_topic);
            $url_topic = array_filter($url_topic);
            if ($url_topic) {
                $filters['topic'] = $url_topic;
            }
        }

        if ($atts['mode']) {
            $filters['delivery_mode'] = explode(',', $atts['mode']);
        } else {
            $url_mode = isset($_GET['ev_mode']) ? (array) $_GET['ev_mode'] : [];
            $url_mode = array_map('sanitize_text_field', $url_mode);
            $url_mode = array_filter($url_mode);
            if ($url_mode) {
                $filters['delivery_mode'] = $url_mode;
            }
        }

        if ($atts['state']) {
            $filters['state'] = explode(',', $atts['state']);
        } else {
            $url_state = isset($_GET['ev_state']) ? (array) $_GET['ev_state'] : [];
            $url_state = array_map('sanitize_text_field', $url_state);
            $url_state = array_filter($url_state);
            if ($url_state) {
                $filters['state'] = $url_state;
            }
        }

        if ($atts['free'] === '1') {
            $filters['free_only'] = true;
        } else {
            $url_price = sanitize_text_field($_GET['ev_price'] ?? '');
            if ($url_price === 'free') {
                $filters['free_only'] = true;
            } elseif ($url_price === 'paid') {
                $filters['paid_only'] = true;
            }
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
        $valid_sort = ['date_asc', 'date_desc', 'price_asc', 'price_desc', 'title_asc', 'title_desc'];

        if ($url_sort && in_array($url_sort, $valid_sort, true)) {
            $filters['sort'] = $url_sort;
        } else {
            $sort_field = $atts['sort'];
            $sort_order = strtoupper($atts['sort_order']) === 'DESC' ? 'desc' : 'asc';
            $default    = $sort_field . '_' . $sort_order;
            $filters['sort'] = in_array($default, $valid_sort, true) ? $default : 'date_asc';
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
        $event_types     = $this->get_filter_terms('hmw_event_type');
        $delivery_modes  = $this->get_filter_terms('hmw_event_delivery_mode');
        $states          = $this->get_filter_terms('hmw_event_state');
        $topics          = $this->get_filter_terms('hmw_event_topic');

        $active_types      = isset($filters['event_type']) ? (array) $filters['event_type'] : [];
        $active_modes      = isset($filters['delivery_mode']) ? (array) $filters['delivery_mode'] : [];
        $active_states     = isset($filters['state']) ? (array) $filters['state'] : [];
        $active_topics     = isset($filters['topic']) ? (array) $filters['topic'] : [];
        $active_price      = isset($filters['free_only']) && $filters['free_only'] ? 'free'
            : (isset($filters['paid_only']) && $filters['paid_only'] ? 'paid' : '');
        $active_date_from  = $filters['date_from'] ?? '';
        $active_date_to    = $filters['date_to'] ?? '';
        $active_sort       = $filters['sort'] ?? 'date_asc';

        ?>
        <div class="hmw-event-filters" id="hmw-event-filters">
            <form method="get" class="hmw-event-filters__form">
                <div class="hmw-event-filters__inner">

                    <?php if ($filters['show_type_filter'] && $event_types): ?>
                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label"><?php esc_html_e('Event Type', 'hmw-events'); ?></span>
                        <div class="hmw-event-filters__options">
                            <?php foreach ($event_types as $term): ?>
                                <label class="hmw-event-filters__checkbox">
                                    <input type="checkbox" name="ev_type[]" value="<?php echo esc_attr($term->slug); ?>"
                                        <?php checked(in_array($term->slug, $active_types, true)); ?> />
                                    <?php echo esc_html($term->name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($delivery_modes): ?>
                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label"><?php esc_html_e('Delivery', 'hmw-events'); ?></span>
                        <div class="hmw-event-filters__options">
                            <?php foreach ($delivery_modes as $term): ?>
                                <label class="hmw-event-filters__checkbox">
                                    <input type="checkbox" name="ev_mode[]" value="<?php echo esc_attr($term->slug); ?>"
                                        <?php checked(in_array($term->slug, $active_modes, true)); ?> />
                                    <?php echo esc_html($term->name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($topics): ?>
                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label"><?php esc_html_e('Topic', 'hmw-events'); ?></span>
                        <div class="hmw-event-filters__options">
                            <?php foreach ($topics as $term): ?>
                                <label class="hmw-event-filters__checkbox">
                                    <input type="checkbox" name="ev_topic[]" value="<?php echo esc_attr($term->slug); ?>"
                                        <?php checked(in_array($term->slug, $active_topics, true)); ?> />
                                    <?php echo esc_html($term->name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label"><?php esc_html_e('Price', 'hmw-events'); ?></span>
                        <div class="hmw-event-filters__options">
                            <label class="hmw-event-filters__radio">
                                <input type="radio" name="ev_price" value=""
                                    <?php checked($active_price, ''); ?> />
                                <?php esc_html_e('All', 'hmw-events'); ?>
                            </label>
                            <label class="hmw-event-filters__radio">
                                <input type="radio" name="ev_price" value="free"
                                    <?php checked($active_price, 'free'); ?> />
                                <?php esc_html_e('Free', 'hmw-events'); ?>
                            </label>
                            <label class="hmw-event-filters__radio">
                                <input type="radio" name="ev_price" value="paid"
                                    <?php checked($active_price, 'paid'); ?> />
                                <?php esc_html_e('Paid', 'hmw-events'); ?>
                            </label>
                        </div>
                    </div>

                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label"><?php esc_html_e('Date Range', 'hmw-events'); ?></span>
                        <label class="hmw-event-filters__date">
                            <span><?php esc_html_e('From', 'hmw-events'); ?></span>
                            <input type="date" name="ev_date_from" value="<?php echo esc_attr($active_date_from); ?>" />
                        </label>
                        <label class="hmw-event-filters__date">
                            <span><?php esc_html_e('To', 'hmw-events'); ?></span>
                            <input type="date" name="ev_date_to" value="<?php echo esc_attr($active_date_to); ?>" />
                        </label>
                    </div>

                    <?php if ($states): ?>
                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label"><?php esc_html_e('Location', 'hmw-events'); ?></span>
                        <div class="hmw-event-filters__options">
                            <?php foreach ($states as $term): ?>
                                <label class="hmw-event-filters__checkbox">
                                    <input type="checkbox" name="ev_state[]" value="<?php echo esc_attr($term->slug); ?>"
                                        <?php checked(in_array($term->slug, $active_states, true)); ?> />
                                    <?php echo esc_html($term->name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="hmw-event-filters__section">
                        <span class="hmw-event-filters__label"><?php esc_html_e('Sort By', 'hmw-events'); ?></span>
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
                        <a href="<?php echo esc_url(get_permalink()); ?>" class="hmw-event-filters__clear">
                            <?php esc_html_e('Clear all', 'hmw-events'); ?>
                        </a>
                    </div>

                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render active filter tags with remove links.
     */
    private function render_active_filters(array $filters): void
    {
        $tags = [];

        if (isset($filters['event_type']) && $filters['show_type_filter']) {
            $terms = get_terms(['taxonomy' => 'hmw_event_type', 'slug' => (array) $filters['event_type'], 'hide_empty' => false]);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $tags[] = [
                        'label'  => $term->name,
                        'group'  => __('Type', 'hmw-events'),
                        'remove' => 'ev_type',
                        'value'  => $term->slug,
                    ];
                }
            }
        }

        if (isset($filters['delivery_mode'])) {
            $terms = get_terms(['taxonomy' => 'hmw_event_delivery_mode', 'slug' => (array) $filters['delivery_mode'], 'hide_empty' => false]);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $tags[] = [
                        'label'  => $term->name,
                        'group'  => __('Mode', 'hmw-events'),
                        'remove' => 'ev_mode',
                        'value'  => $term->slug,
                    ];
                }
            }
        }

        if (isset($filters['topic'])) {
            $terms = get_terms(['taxonomy' => 'hmw_event_topic', 'slug' => (array) $filters['topic'], 'hide_empty' => false]);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $tags[] = [
                        'label'  => $term->name,
                        'group'  => __('Topic', 'hmw-events'),
                        'remove' => 'ev_topic',
                        'value'  => $term->slug,
                    ];
                }
            }
        }

        if (isset($filters['state'])) {
            $terms = get_terms(['taxonomy' => 'hmw_event_state', 'slug' => (array) $filters['state'], 'hide_empty' => false]);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $tags[] = [
                        'label'  => $term->name,
                        'group'  => __('Location', 'hmw-events'),
                        'remove' => 'ev_state',
                        'value'  => $term->slug,
                    ];
                }
            }
        }

        if (isset($filters['free_only']) && $filters['free_only']) {
            $tags[] = ['label' => __('Free', 'hmw-events'), 'group' => __('Price', 'hmw-events'), 'remove' => 'ev_price', 'value' => ''];
        } elseif (isset($filters['paid_only']) && $filters['paid_only']) {
            $tags[] = ['label' => __('Paid', 'hmw-events'), 'group' => __('Price', 'hmw-events'), 'remove' => 'ev_price', 'value' => ''];
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
        unset($params['pg']);

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

        return add_query_arg($params, get_permalink());
    }

    /**
     * Get terms for filter checkboxes, sorted by name.
     */
    private function get_filter_terms(string $taxonomy): array
    {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }
        return $terms;
    }

    /**
     * Render a single event card.
     */
    private function render_card(?array $card): void
    {
        if (!$card) {
            return;
        }
        ?>
        <article class="hmw-event-card<?php echo $card['delivery_mode'] === 'online' ? ' hmw-event-card--online' : ''; ?>">
            <?php if ($card['thumbnail']): ?>
                <div class="hmw-event-card__image">
                    <img src="<?php echo esc_url($card['thumbnail']); ?>" alt="<?php echo esc_attr($card['title']); ?>" loading="lazy" />
                    <div class="hmw-event-card__badges">
                        <?php foreach ($card['badges'] as $badge): ?>
                            <span class="hmw-badge <?php echo esc_attr($badge['class']); ?>">
                                <?php echo esc_html($badge['label']); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="hmw-event-card__content">
                <?php if ($card['event_type_name']): ?>
                    <p class="hmw-event-card__type"><?php echo esc_html($card['event_type_name']); ?></p>
                <?php endif; ?>

                <h3 class="hmw-event-card__title">
                    <a href="<?php echo esc_url($card['permalink']); ?>">
                        <?php echo esc_html($card['title']); ?>
                    </a>
                </h3>

                <div class="hmw-event-card__meta">
                    <div class="hmw-event-card__date">
                        <svg class="hmw-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <path d="M5.5 1v2m5-2v2M2 6.5h12M3.5 3h9a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 012 13.5v-9A1.5 1.5 0 013.5 3z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span>
                        <?php if ($card['is_recurring']): ?>
                            <?php echo esc_html($card['session_summary']); ?>
                        <?php else: ?>
                            <?php echo esc_html($card['formatted_date']); ?>
                            <?php if ($card['end_date'] && $card['end_date'] !== $card['start_date']): ?>
                                <span class="hmw-event-card__date-sep">&ndash;</span>
                                <?php echo esc_html(date_i18n('j M Y', strtotime($card['end_date']))); ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($card['venue']): ?>
                    <div class="hmw-event-card__venue">
                        <svg class="hmw-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <path d="M8 14s6-4.5 6-8.5A6 6 0 002 5.5C2 9.5 8 14 8 14z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="8" cy="5.5" r="1.5" stroke="currentColor" stroke-width="1.2"/>
                        </svg>
                        <span>
                            <?php echo esc_html($card['venue']); ?>
                            <?php if ($card['delivery_mode'] === 'online' && $card['webinar_url']): ?>
                                &middot; <?php esc_html_e('Online', 'hmw-events'); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($card['excerpt']): ?>
                    <p class="hmw-event-card__excerpt">
                        <?php echo esc_html($card['excerpt']); ?>
                    </p>
                <?php endif; ?>

                <div class="hmw-event-card__footer">
                    <div class="hmw-event-card__price<?php echo $card['is_free'] ? ' hmw-event-card__price--free' : ''; ?>">
                        <?php if ($card['is_free']): ?>
                            <span class="hmw-event-card__price-label"><?php esc_html_e('Price', 'hmw-events'); ?></span>
                            <span class="hmw-event-card__price-value"><?php esc_html_e('Free', 'hmw-events'); ?></span>
                        <?php elseif ($card['price']): ?>
                            <span class="hmw-event-card__price-label"><?php esc_html_e('From', 'hmw-events'); ?></span>
                            <span class="hmw-event-card__price-value">$<?php echo esc_html(number_format($card['price'], 2)); ?></span>
                        <?php endif; ?>
                    </div>

                    <a href="<?php echo esc_url($card['permalink']); ?>" class="hmw-btn hmw-btn--secondary">
                        <?php esc_html_e('View Details', 'hmw-events'); ?>
                    </a>
                </div>
            </div>
        </article>
        <?php
    }

    /**
     * Simple pagination output.
     */
    private function render_pagination(\WP_Query $query): void
    {
        $big = 999999999;
        $links = paginate_links([
            'base'      => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
            'format'    => '?paged=%#%',
            'current'   => max(1, $query->get('paged')),
            'total'     => $query->max_num_pages,
            'type'      => 'list',
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
        $filters = [
            'event_type'    => $_POST['event_type'] ?? '',
            'audience'      => $_POST['audience'] ?? '',
            'topic'         => $_POST['topic'] ?? '',
            'delivery_mode' => $_POST['delivery_mode'] ?? '',
            'state'         => $_POST['state'] ?? '',
            'free_only'     => !empty($_POST['free_only']),
            'paid_only'     => !empty($_POST['paid_only']),
            'date_from'     => $_POST['date_from'] ?? '',
            'date_to'       => $_POST['date_to'] ?? '',
            'search'        => $_POST['search'] ?? '',
            'page'          => (int) ($_POST['page'] ?? 1),
            'posts_per_page' => (int) ($_POST['per_page'] ?? 12),
        ];

        $query = $this->query($filters);

        $events = [];
        while ($query->have_posts()) {
            $query->the_post();
            $events[] = $this->get_event_card($query->post);
        }
        wp_reset_postdata();

        wp_send_json_success([
            'events'      => $events,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
        ]);
    }
}
