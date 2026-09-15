<?php

defined('ABSPATH') || die('Don\'t run this file directly!');

$card        = $args['card'] ?? null;
$button_text = $args['button_text'] ?? __('View All Courses', 'hmw-events');

if (!$card) {
    return;
}
?>

<div class="hmw-event-category-card">
    <?php if (!empty($card['image_html'])) : ?>
        <a class="hmw-event-category-card__media" href="<?php echo esc_url($card['url']); ?>">
            <?php echo $card['image_html']; ?>
        </a>
    <?php endif; ?>

    <div class="hmw-event-category-card__body">
        <h3 class="hmw-event-category-card__title">
            <a href="<?php echo esc_url($card['url']); ?>"><?php echo esc_html($card['name']); ?></a>
        </h3>

        <?php if (!empty($card['description'])) : ?>
            <div class="hmw-event-category-card__description"><?php echo wp_kses_post($card['description']); ?></div>
        <?php endif; ?>

        <?php if (!empty($card['dates'])) : ?>
            <ul class="hmw-event-category-card__dates">
                <?php foreach ($card['dates'] as $date_entry) : ?>
                    <li>
                        <a href="<?php echo esc_url($date_entry['url']); ?>"><?php echo esc_html($date_entry['formatted']); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <a class="hmw-event-category-card__button" href="<?php echo esc_url($card['url']); ?>">
            <?php echo esc_html($button_text); ?>
        </a>
    </div>
</div>
