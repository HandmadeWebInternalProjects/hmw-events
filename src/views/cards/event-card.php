<?php
/**
 * Event card for the listings grid.
 *
 * Override by placing hmw-events/cards/event-card.php in your theme.
 *
 * @var array $card {
 *     @type int    $id
 *     @type string $title
 *     @type string $permalink
 *     @type string $excerpt
 *     @type string $start_date
 *     @type string $end_date
 *     @type string $formatted_date
 *     @type float  $price
 *     @type bool   $is_free
 *     @type string $venue
 *     @type string $venue_address
 *     @type string $webinar_url
 *     @type string $delivery_mode
 *     @type string $delivery_mode_label
 *     @type string $delivery_mode_icon  Sanitized inline SVG or <img> markup.
 *     @type string $event_type_name
 *     @type array  $audience_terms Array of ['name' => string, 'slug' => string, 'link' => string].
 *     @type string $suburb         Suburb inferred from the venue Google Map field.
 *     @type array  $badges  Array of ['label' => string, 'class' => string].
 *     @type string $thumbnail
 *     @type bool   $is_recurring
 *     @type array  $session_dates
 *     @type string $session_summary
 *     @type int    $session_count
 * }
 */

defined('ABSPATH') || die('Don\'t run this file directly!');

$card = $args['card'] ?? null;

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
        <?php if (!empty($card['delivery_mode_label'])): ?>
            <span class="hmw-delivery-pill hmw-delivery-pill--<?php echo esc_attr($card['delivery_mode']); ?>">
                <?php echo $card['delivery_mode_icon'] ?? ''; ?>
                <span class="hmw-delivery-pill__label"><?php echo esc_html($card['delivery_mode_label']); ?></span>
            </span>
        <?php endif; ?>

        <?php if ($card['event_type_name']): ?>
            <p class="hmw-event-card__type"><?php echo esc_html($card['event_type_name']); ?></p>
        <?php endif; ?>

        <h3 class="hmw-event-card__title">
            <a href="<?php echo esc_url($card['permalink']); ?>">
                <?php echo esc_html($card['title']); ?>
            </a>
        </h3>

        <div class="hmw-event-card__meta">
            <div class="hmw-event-card__meta-item hmw-event-card__date">
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
            <div class="hmw-event-card__meta-item hmw-event-card__venue">
                <svg class="hmw-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M8 14s6-3.5 6-7A6 6 0 002 7c0 3.5 6 7 6 7z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="8" cy="7" r="1.5" stroke="currentColor" stroke-width="1.2"/>
                </svg>
                <span>
                    <?php echo esc_html($card['venue']); ?>
                    <?php if (!empty($card['suburb'])): ?>
                        &middot; <?php echo esc_html($card['suburb']); ?>
                    <?php endif; ?>
                    <?php if ($card['delivery_mode'] === 'online' && $card['webinar_url']): ?>
                        &middot; <?php esc_html_e('Online', 'hmw-events'); ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>

            <?php $card_taxonomy_terms = $card['audience_terms'] ?? []; ?>
            <?php if ($card_taxonomy_terms): ?>
            <div class="hmw-event-card__meta-item hmw-event-card__taxonomies">
                <svg class="hmw-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M2.5 7V4a1.5 1.5 0 011.5-1.5h3L13.5 9 9 13.5 2.5 7z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="5.1" cy="5.1" r="1.1" stroke="currentColor" stroke-width="1.2"/>
                </svg>
                <span class="hmw-event-card__term-list">
                    <?php foreach ($card_taxonomy_terms as $card_term): ?>
                        <?php if (!empty($card_term['link'])): ?>
                            <a class="hmw-event-card__term" href="<?php echo esc_url($card_term['link']); ?>"><?php echo esc_html($card_term['name']); ?></a>
                        <?php else: ?>
                            <span class="hmw-event-card__term"><?php echo esc_html($card_term['name']); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
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
