<?php
/**
 * Default single event template.
 *
 * Override by placing hmw-events/single-event.php in your theme.
 *
 * @var WP_Post $event     The event post object.
 * @var array   $fields    Effective registration fields for this event.
 * @var bool    $has_booking_form Whether a booking form shortcode was detected.
 */

defined('ABSPATH') || exit;

get_header();

$event = $args['event'] ?? $post ?? null;
$fields = $args['fields'] ?? [];
$has_booking_form = $args['has_booking_form'] ?? false;

if (!$event) {
    return;
}

$event_data   = new \HMWEvents\Services\EventDataService();
$start_date   = $event_data->get_start_date($event->ID);
$end_date     = $event_data->get_end_date($event->ID);
$capacity     = $event_data->get_capacity($event->ID);
$venue        = $event_data->get_venue_name($event->ID);
$venue_addr   = $event_data->get_venue_address_string($event->ID);
$webinar_url  = $event_data->get_webinar_url($event->ID);
$price        = $event_data->get_price($event->ID);
$deposit      = $event_data->get_deposit($event->ID);
$surcharge    = $event_data->get_surcharge($event->ID);
$is_free      = $event_data->get_is_free($event->ID);
$organizer_id = $event->post_author;
$event_types  = get_the_terms($event->ID, 'hmw_event_type');
$delivery     = get_the_terms($event->ID, 'hmw_event_delivery_mode');
$audience     = get_the_terms($event->ID, 'hmw_event_audience');
$suburb       = $event_data->get_venue_suburb($event->ID);
$display_price = (float) $price + $surcharge;
$display_deposit = (float) $deposit + $surcharge;

$article_classes = apply_filters('hmwevents_single_event_classes', ['hmwevents-single-event'], $event);

do_action('hmwevents_before_single_event', $event);
?>

<article <?php post_class($article_classes, $event->ID); ?>>

    <?php do_action('hmwevents_before_event_header', $event); ?>

    <header class="<?php echo esc_attr(implode(' ', apply_filters('hmwevents_event_header_classes', ['hmwevents-event-header'], $event))); ?>">

        <?php
        $title_html = '<h1 class="hmwevents-event-title">' . esc_html(get_the_title($event)) . '</h1>';
        echo apply_filters('hmwevents_event_title_html', $title_html, $event);
        ?>

        <?php if ($event_types && !is_wp_error($event_types)) : ?>
            <div class="hmwevents-event-types">
                <?php foreach ($event_types as $type) : ?>
                    <?php
                    $badge_classes = apply_filters('hmwevents_event_type_badge_classes', ['hmwevents-event-type-badge'], $type, $event);
                    $badge_class_attr = esc_attr(implode(' ', $badge_classes));
                    $type_link = get_term_link($type);
                    ?>
                    <?php if (!is_wp_error($type_link)) : ?>
                        <a class="<?php echo $badge_class_attr; ?>" href="<?php echo esc_url($type_link); ?>"><?php echo esc_html($type->name); ?></a>
                    <?php else : ?>
                        <span class="<?php echo $badge_class_attr; ?>"><?php echo esc_html($type->name); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </header>

    <?php do_action('hmwevents_after_event_header', $event); ?>

    <?php do_action('hmwevents_event_map', $event); ?>

    <?php do_action('hmwevents_before_event_meta', $event); ?>

    <div class="<?php echo esc_attr(implode(' ', apply_filters('hmwevents_event_meta_wrapper_classes', ['hmwevents-event-meta'], $event))); ?>">

        <?php if ($start_date) : ?>
            <?php
            $meta_key = 'start_date';
            $meta_classes = apply_filters('hmwevents_event_meta_item_classes', ['hmwevents-meta-item'], $meta_key, $event);
            $meta_label = apply_filters('hmwevents_event_meta_label', __('Starts', 'hmw-events'), $meta_key, $event);
            ob_start();
            ?>
                <span class="hmwevents-meta-heading"><?php echo hmwevents_meta_icon($meta_key); ?><strong><?php echo esc_html($meta_label); ?>:</strong></span>
                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($start_date))); ?>
            <?php
            $meta_value_html = apply_filters('hmwevents_event_meta_value_html', ob_get_clean(), $meta_key, $event);
            ?>
            <div class="<?php echo esc_attr(implode(' ', $meta_classes)); ?>">
                <?php echo $meta_value_html; ?>
            </div>
        <?php endif; ?>

        <?php if ($end_date) : ?>
            <?php
            $meta_key = 'end_date';
            $meta_classes = apply_filters('hmwevents_event_meta_item_classes', ['hmwevents-meta-item'], $meta_key, $event);
            $meta_label = apply_filters('hmwevents_event_meta_label', __('Ends', 'hmw-events'), $meta_key, $event);
            ob_start();
            ?>
                <span class="hmwevents-meta-heading"><?php echo hmwevents_meta_icon($meta_key); ?><strong><?php echo esc_html($meta_label); ?>:</strong></span>
                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($end_date))); ?>
            <?php
            $meta_value_html = apply_filters('hmwevents_event_meta_value_html', ob_get_clean(), $meta_key, $event);
            ?>
            <div class="<?php echo esc_attr(implode(' ', $meta_classes)); ?>">
                <?php echo $meta_value_html; ?>
            </div>
        <?php endif; ?>

        <?php if ($venue) : ?>
            <?php
            $meta_key = 'venue';
            $meta_classes = apply_filters('hmwevents_event_meta_item_classes', ['hmwevents-meta-item'], $meta_key, $event);
            $meta_label = apply_filters('hmwevents_event_meta_label', __('Venue', 'hmw-events'), $meta_key, $event);
            ob_start();
            ?>
                <span class="hmwevents-meta-heading"><?php echo hmwevents_meta_icon($meta_key); ?><strong><?php echo esc_html($meta_label); ?>:</strong></span>
                <?php echo esc_html($venue); ?>
                <?php if ($venue_addr) : ?>
                    <br><?php echo esc_html($venue_addr); ?>
                <?php endif; ?>
            <?php
            $meta_value_html = apply_filters('hmwevents_event_meta_value_html', ob_get_clean(), $meta_key, $event);
            ?>
            <div class="<?php echo esc_attr(implode(' ', $meta_classes)); ?>">
                <?php echo $meta_value_html; ?>
            </div>
        <?php endif; ?>

        <?php if ($webinar_url) : ?>
            <?php
            $meta_key = 'webinar_url';
            $meta_classes = apply_filters('hmwevents_event_meta_item_classes', ['hmwevents-meta-item'], $meta_key, $event);
            $meta_label = apply_filters('hmwevents_event_meta_label', __('Webinar Link', 'hmw-events'), $meta_key, $event);
            ob_start();
            ?>
                <span class="hmwevents-meta-heading"><?php echo hmwevents_meta_icon($meta_key); ?><strong><?php echo esc_html($meta_label); ?>:</strong></span>
                <a href="<?php echo esc_url($webinar_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Join Webinar', 'hmw-events'); ?></a>
            <?php
            $meta_value_html = apply_filters('hmwevents_event_meta_value_html', ob_get_clean(), $meta_key, $event);
            ?>
            <div class="<?php echo esc_attr(implode(' ', $meta_classes)); ?>">
                <?php echo $meta_value_html; ?>
            </div>
        <?php endif; ?>

        <?php if ($delivery && !is_wp_error($delivery)) : ?>
            <?php
            $meta_key = 'delivery';
            $meta_classes = apply_filters('hmwevents_event_meta_item_classes', ['hmwevents-meta-item'], $meta_key, $event);
            $meta_label = apply_filters('hmwevents_event_meta_label', __('Delivery', 'hmw-events'), $meta_key, $event);
            ob_start();
            ?>
                <span class="hmwevents-meta-heading"><?php echo hmwevents_meta_icon($meta_key); ?><strong><?php echo esc_html($meta_label); ?>:</strong></span>
                <?php foreach ($delivery as $mode) : ?>
                    <?php $mode_link = get_term_link($mode); ?>
                    <?php if (!is_wp_error($mode_link)) : ?>
                        <a href="<?php echo esc_url($mode_link); ?>"><?php echo esc_html($mode->name); ?></a>
                    <?php else : ?>
                        <span><?php echo esc_html($mode->name); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php
            $meta_value_html = apply_filters('hmwevents_event_meta_value_html', ob_get_clean(), $meta_key, $event);
            ?>
            <div class="<?php echo esc_attr(implode(' ', $meta_classes)); ?>">
                <?php echo $meta_value_html; ?>
            </div>
        <?php endif; ?>

        <?php if ($audience && !is_wp_error($audience)) : ?>
            <?php
            $meta_key = 'audience';
            $meta_classes = apply_filters('hmwevents_event_meta_item_classes', ['hmwevents-meta-item'], $meta_key, $event);
            $meta_label = apply_filters('hmwevents_event_meta_label', __('Audience', 'hmw-events'), $meta_key, $event);
            ob_start();
            ?>
                <span class="hmwevents-meta-heading"><?php echo hmwevents_meta_icon($meta_key); ?><strong><?php echo esc_html($meta_label); ?>:</strong></span>
                <?php foreach ($audience as $audience_term) : ?>
                    <?php $audience_link = get_term_link($audience_term); ?>
                    <?php if (!is_wp_error($audience_link)) : ?>
                        <a href="<?php echo esc_url($audience_link); ?>"><?php echo esc_html($audience_term->name); ?></a>
                    <?php else : ?>
                        <span><?php echo esc_html($audience_term->name); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php
            $meta_value_html = apply_filters('hmwevents_event_meta_value_html', ob_get_clean(), $meta_key, $event);
            ?>
            <div class="<?php echo esc_attr(implode(' ', $meta_classes)); ?>">
                <?php echo $meta_value_html; ?>
            </div>
        <?php endif; ?>

        <?php if ($suburb) : ?>
            <?php
            $meta_key = 'location';
            $meta_classes = apply_filters('hmwevents_event_meta_item_classes', ['hmwevents-meta-item'], $meta_key, $event);
            $meta_label = apply_filters('hmwevents_event_meta_label', __('Location', 'hmw-events'), $meta_key, $event);
            ob_start();
            ?>
                <span class="hmwevents-meta-heading"><?php echo hmwevents_meta_icon($meta_key); ?><strong><?php echo esc_html($meta_label); ?>:</strong></span>
                <span><?php echo esc_html($suburb); ?></span>
            <?php
            $meta_value_html = apply_filters('hmwevents_event_meta_value_html', ob_get_clean(), $meta_key, $event);
            ?>
            <div class="<?php echo esc_attr(implode(' ', $meta_classes)); ?>">
                <?php echo $meta_value_html; ?>
            </div>
        <?php endif; ?>

        <?php foreach (\HMWEvents\Registry\TaxonomyRegistry::single_meta_taxonomies() as $meta_taxonomy => $meta_def) : ?>
            <?php
            $meta_terms = get_the_terms($event->ID, $meta_taxonomy);
            if (!$meta_terms || is_wp_error($meta_terms)) {
                continue;
            }
            $meta_key      = $meta_def['filter_key'] ?: $meta_def['event_field_key'];
            $meta_classes  = apply_filters('hmwevents_event_meta_item_classes', ['hmwevents-meta-item'], $meta_key, $event);
            $meta_label    = apply_filters('hmwevents_event_meta_label', __($meta_def['singular'], 'hmw-events'), $meta_key, $event);
            ob_start();
            ?>
                <span class="hmwevents-meta-heading"><?php echo hmwevents_meta_icon($meta_key); ?><strong><?php echo esc_html($meta_label); ?>:</strong></span>
                <?php foreach ($meta_terms as $meta_term) : ?>
                    <?php $meta_term_link = get_term_link($meta_term); ?>
                    <?php if (!is_wp_error($meta_term_link)) : ?>
                        <a href="<?php echo esc_url($meta_term_link); ?>"><?php echo esc_html($meta_term->name); ?></a>
                    <?php else : ?>
                        <span><?php echo esc_html($meta_term->name); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php
            $meta_value_html = apply_filters('hmwevents_event_meta_value_html', ob_get_clean(), $meta_key, $event);
            ?>
            <div class="<?php echo esc_attr(implode(' ', $meta_classes)); ?>">
                <?php echo $meta_value_html; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($capacity > 0) : ?>
            <?php
            $capacity_service = new \HMWEvents\Services\CapacityService();
            $places_remaining = $capacity_service->get_remaining_places($event->ID);
            $meta_key = 'places_remaining';
            $meta_classes = apply_filters('hmwevents_event_meta_item_classes', ['hmwevents-meta-item'], $meta_key, $event);
            $meta_label = apply_filters('hmwevents_event_meta_label', __('Places remaining', 'hmw-events'), $meta_key, $event);
            ob_start();
            ?>
                <span class="hmwevents-meta-heading"><?php echo hmwevents_meta_icon($meta_key); ?><strong><?php echo esc_html($meta_label); ?>:</strong></span>
                <?php echo (int) $places_remaining; ?>
            <?php
            $meta_value_html = apply_filters('hmwevents_event_meta_value_html', ob_get_clean(), $meta_key, $event);
            ?>
            <div class="<?php echo esc_attr(implode(' ', $meta_classes)); ?>">
                <?php echo $meta_value_html; ?>
            </div>
        <?php endif; ?>

        <?php if ($price && !$is_free) : ?>
            <?php
            $meta_key = 'price';
            $meta_classes = apply_filters('hmwevents_event_meta_item_classes', ['hmwevents-meta-item', 'hmwevents-price'], $meta_key, $event);
            $meta_label = apply_filters('hmwevents_event_meta_label', __('Price', 'hmw-events'), $meta_key, $event);
            ob_start();
            ?>
                <span class="hmwevents-meta-heading"><?php echo hmwevents_meta_icon($meta_key); ?><strong><?php echo esc_html($meta_label); ?>:</strong></span>
                $<?php echo number_format($display_price, 2); ?>
                <?php if ($surcharge > 0) : ?>
                    <small>(<?php esc_html_e('includes surcharge', 'hmw-events'); ?>: $<?php echo number_format($surcharge, 2); ?>)</small>
                <?php endif; ?>
                <?php if ($deposit && apply_filters('hmwevents_deposit_enabled', false)) : ?>
                    <small>(<?php esc_html_e('Deposit', 'hmw-events'); ?>: $<?php echo number_format($display_deposit, 2); ?>)</small>
                <?php endif; ?>
            <?php
            $meta_value_html = apply_filters('hmwevents_event_meta_value_html', ob_get_clean(), $meta_key, $event);
            ?>
            <div class="<?php echo esc_attr(implode(' ', $meta_classes)); ?>">
                <?php echo $meta_value_html; ?>
            </div>
        <?php endif; ?>

        <?php if ($organizer_id) : ?>
            <?php
            $meta_key = 'organizer';
            $meta_classes = apply_filters('hmwevents_event_meta_item_classes', ['hmwevents-meta-item'], $meta_key, $event);
            $meta_label = apply_filters('hmwevents_event_meta_label', __('Organiser', 'hmw-events'), $meta_key, $event);
            ob_start();
            ?>
                <span class="hmwevents-meta-heading"><?php echo hmwevents_meta_icon($meta_key); ?><strong><?php echo esc_html($meta_label); ?>:</strong></span>
                <?php echo esc_html(get_the_author_meta('display_name', $organizer_id)); ?>
            <?php
            $meta_value_html = apply_filters('hmwevents_event_meta_value_html', ob_get_clean(), $meta_key, $event);
            ?>
            <div class="<?php echo esc_attr(implode(' ', $meta_classes)); ?>">
                <?php echo $meta_value_html; ?>
            </div>
        <?php endif; ?>

    </div>

    <?php do_action('hmwevents_after_event_meta', $event); ?>

    <?php if ($event->post_content) : ?>
        <?php do_action('hmwevents_before_event_content', $event); ?>
        <div class="<?php echo esc_attr(implode(' ', apply_filters('hmwevents_event_content_classes', ['hmwevents-event-content'], $event))); ?>">
            <?php echo apply_filters('the_content', $event->post_content); ?>
        </div>
        <?php do_action('hmwevents_after_event_content', $event); ?>
    <?php endif; ?>

    <?php do_action('hmwevents_before_booking_form', $event); ?>

    <?php if (!$has_booking_form && $event_data->bookings_enabled($event->ID)) : ?>
        <div class="<?php echo esc_attr(implode(' ', apply_filters('hmwevents_booking_form_container_classes', ['hmwevents-booking-form-container'], $event))); ?>">
            <?php echo do_shortcode('[hmwevents_booking_form event_id="' . $event->ID . '"]'); ?>
        </div>
    <?php endif; ?>

    <?php do_action('hmwevents_after_booking_form', $event); ?>

</article>

<?php
do_action('hmwevents_after_single_event', $event);

get_footer();
