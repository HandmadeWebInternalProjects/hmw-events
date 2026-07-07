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
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventListingService
{
    /**
     * Register hooks and shortcodes.
     */
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
     *     delivery_mode:    string|string[]    Delivery mode slug(s).
     *     state:            string|string[]    State slug(s).
     *     free_only:        bool               Only free events.
     *     paid_only:        bool               Only paid events.
     *     date_from:        string             Y-m-d.
     *     date_to:          string             Y-m-d.
     *     search:           string             Keyword search.
     *     posts_per_page:   int                Default 12.
     *     page:             int                Page number.
     *     exclude_full:     bool               Exclude fully-booked events.
     * }
     * @return array WP_Query args.
     */
    public function build_query(array $filters = []): array
    {
        $args = [
            'post_type'      => 'hmw_event',
            'post_status'    => ['publish', 'fully_booked'],
            'posts_per_page' => $filters['posts_per_page'] ?? 12,
            'paged'          => max(1, (int) ($filters['page'] ?? 1)),
            'orderby'        => 'meta_value',
            'meta_key'       => '_event_start_date',
            'order'          => 'ASC',
            'meta_query'     => [],
            'tax_query'      => [],
            's'              => $filters['search'] ?? '',
        ];

        // Exclude "By Invitation" events from public listings
        $args['post_status'] = ['publish', 'fully_booked'];

        // Exclude child sessions
        $args['post_parent'] = 0;

        // Date range
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

        // Free / paid
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

        // Taxonomies
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
    public function get_event_card(\WP_Post $post): ?array
    {
        $start_date = get_post_meta($post->ID, '_event_start_date', true);
        $end_date   = get_post_meta($post->ID, '_event_end_date', true);
        $price      = (float) get_post_meta($post->ID, '_event_price', true);
        $venue       = get_post_meta($post->ID, '_event_venue_name', true);
        $venue_addr  = get_post_meta($post->ID, '_event_venue_address', true);
        $capacity    = (int) get_post_meta($post->ID, '_event_capacity', true);
        $webinar_url = get_post_meta($post->ID, '_event_webinar_url', true);
        $is_free     = (bool) get_post_meta($post->ID, '_event_is_free', true);

        $event_types    = wp_get_object_terms($post->ID, 'hmw_event_type', ['fields' => 'slugs']);
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
            'audiences'       => $audiences ?: [],
            'badges'          => $badges,
            'thumbnail'       => get_the_post_thumbnail_url($post->ID, 'medium'),
        ];
    }

    // ================================================================
    // RENDERING
    // ================================================================

    /**
     * Render the event listings shortcode.
     *
     * [hmw_event_listings type="workshop" audience="parents" mode="in-person" limit="12"]
     */
    public function render_listings(array $atts = [], string $content = ''): string
    {
        $atts = shortcode_atts([
            'type'     => '',
            'audience' => '',
            'mode'     => '',
            'state'    => '',
            'free'     => '',
            'limit'    => 12,
        ], $atts);

        $filters = [];
        if ($atts['type'])     $filters['event_type'] = explode(',', $atts['type']);
        if ($atts['audience']) $filters['audience']   = explode(',', $atts['audience']);
        if ($atts['mode'])     $filters['delivery_mode'] = explode(',', $atts['mode']);
        if ($atts['state'])    $filters['state']      = explode(',', $atts['state']);
        if ($atts['free'] === '1') $filters['free_only'] = true;
        $filters['posts_per_page'] = (int) $atts['limit'];

        $query = $this->query($filters);

        ob_start();

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
            echo '<p class="hmw-no-events">' . esc_html__('No events found.', 'hmw-events') . '</p>';
        }

        wp_reset_postdata();
        return ob_get_clean();
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
                </div>
            <?php endif; ?>

            <div class="hmw-event-card__content">
                <div class="hmw-event-card__badges">
                    <?php foreach ($card['badges'] as $badge): ?>
                        <span class="hmw-badge <?php echo esc_attr($badge['class']); ?>">
                            <?php echo esc_html($badge['label']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <h3 class="hmw-event-card__title">
                    <a href="<?php echo esc_url($card['permalink']); ?>">
                        <?php echo esc_html($card['title']); ?>
                    </a>
                </h3>

                <p class="hmw-event-card__date">
                    <?php echo esc_html($card['formatted_date']); ?>
                </p>

                <?php if ($card['venue']): ?>
                    <p class="hmw-event-card__venue">
                        <?php echo esc_html($card['venue']); ?>
                        <?php if ($card['delivery_mode'] === 'online'): ?>
                            — <?php esc_html_e('Online', 'hmw-events'); ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if ($card['excerpt']): ?>
                    <p class="hmw-event-card__excerpt">
                        <?php echo esc_html($card['excerpt']); ?>
                    </p>
                <?php endif; ?>

                <div class="hmw-event-card__footer">
                    <?php if (!$card['is_free']): ?>
                        <span class="hmw-event-card__price">
                            <?php echo $card['price'] ? '$' . esc_html(number_format($card['price'], 2)) : ''; ?>
                        </span>
                    <?php endif; ?>

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
     * AJAX filter endpoint.
     */
    public function ajax_filter(): void
    {
        $filters = [
            'event_type'    => $_POST['event_type'] ?? '',
            'audience'      => $_POST['audience'] ?? '',
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
