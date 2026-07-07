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

$start_date   = get_post_meta($event->ID, '_event_start_date', true);
$end_date     = get_post_meta($event->ID, '_event_end_date', true);
$capacity     = (int) get_post_meta($event->ID, '_event_capacity', true);
$venue        = get_post_meta($event->ID, '_event_venue_name', true);
$venue_addr   = get_post_meta($event->ID, '_event_venue_address', true);
$webinar_url  = get_post_meta($event->ID, '_event_webinar_url', true);
$price        = get_post_meta($event->ID, '_event_price', true);
$deposit      = get_post_meta($event->ID, '_event_deposit', true);
$is_free      = get_post_meta($event->ID, '_event_is_free', true);
$organizer_id = $event->post_author;
$event_types  = get_the_terms($event->ID, 'hmw_event_type');
$delivery     = get_the_terms($event->ID, 'hmw_event_delivery_mode');
$audience     = get_the_terms($event->ID, 'hmw_event_audience');
?>

<article <?php post_class('hmwevents-single-event', $event->ID); ?>>

    <header class="hmwevents-event-header">
        <h1 class="hmwevents-event-title"><?php echo esc_html(get_the_title($event)); ?></h1>

        <?php if ($event_types && !is_wp_error($event_types)) : ?>
            <div class="hmwevents-event-types">
                <?php foreach ($event_types as $type) : ?>
                    <span class="hmwevents-event-type-badge"><?php echo esc_html($type->name); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </header>

    <div class="hmwevents-event-meta">
        <?php if ($start_date) : ?>
            <div class="hmwevents-meta-item">
                <strong><?php esc_html_e('Starts', 'hmw-events'); ?>:</strong>
                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($start_date))); ?>
            </div>
        <?php endif; ?>

        <?php if ($end_date) : ?>
            <div class="hmwevents-meta-item">
                <strong><?php esc_html_e('Ends', 'hmw-events'); ?>:</strong>
                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($end_date))); ?>
            </div>
        <?php endif; ?>

        <?php if ($venue) : ?>
            <div class="hmwevents-meta-item">
                <strong><?php esc_html_e('Venue', 'hmw-events'); ?>:</strong>
                <?php echo esc_html($venue); ?>
                <?php if ($venue_addr) : ?>
                    <br><small><?php echo esc_html($venue_addr); ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($webinar_url) : ?>
            <div class="hmwevents-meta-item">
                <strong><?php esc_html_e('Webinar Link', 'hmw-events'); ?>:</strong>
                <a href="<?php echo esc_url($webinar_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Join Webinar', 'hmw-events'); ?></a>
            </div>
        <?php endif; ?>

        <?php if ($delivery && !is_wp_error($delivery)) : ?>
            <div class="hmwevents-meta-item">
                <strong><?php esc_html_e('Delivery', 'hmw-events'); ?>:</strong>
                <?php foreach ($delivery as $mode) : ?>
                    <span><?php echo esc_html($mode->name); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($capacity > 0) : ?>
            <div class="hmwevents-meta-item">
                <strong><?php esc_html_e('Capacity', 'hmw-events'); ?>:</strong>
                <?php echo (int) $capacity; ?>
            </div>
        <?php endif; ?>

        <?php if ($price && !$is_free) : ?>
            <div class="hmwevents-meta-item hmwevents-price">
                <strong><?php esc_html_e('Price', 'hmw-events'); ?>:</strong>
                $<?php echo number_format((float) $price, 2); ?>
                <?php if ($deposit) : ?>
                    <small>(<?php esc_html_e('Deposit', 'hmw-events'); ?>: $<?php echo number_format((float) $deposit, 2); ?>)</small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($organizer_id) : ?>
            <div class="hmwevents-meta-item">
                <strong><?php esc_html_e('Organizer', 'hmw-events'); ?>:</strong>
                <?php echo esc_html(get_the_author_meta('display_name', $organizer_id)); ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($event->post_content) : ?>
        <div class="hmwevents-event-content">
            <?php echo apply_filters('the_content', $event->post_content); ?>
        </div>
    <?php endif; ?>

    <?php do_action('hmwevents_before_booking_form', $event); ?>

    <?php if (!$has_booking_form) : ?>
        <div class="hmwevents-booking-form-container">
            <?php echo do_shortcode('[hmwevents_booking_form event_id="' . $event->ID . '"]'); ?>
        </div>
    <?php endif; ?>

    <?php do_action('hmwevents_after_booking_form', $event); ?>

</article>

<?php get_footer();
