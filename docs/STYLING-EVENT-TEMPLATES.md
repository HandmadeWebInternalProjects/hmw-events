# Styling Single Event Templates

There are three ways to customize the single event page without losing updates when the plugin is upgraded.

## 1. Theme override (full control)

Copy `src/views/single-event.php` into your theme:

```
your-theme/hmw-events/single-event.php
```

The plugin will load your copy instead of its own. You have complete control over the markup. This is the most flexible approach but requires maintaining compatibility with future plugin changes.

## 2. Action hooks (inject / wrap content)

Use WordPress actions to inject HTML before or after template sections. Useful for wrapping the event in Bootstrap containers, adding banner content, or inserting a sidebar.

| Hook | Fires | Arguments |
|------|-------|-----------|
| `hmwevents_before_single_event` | Before the `<article>` element | `$event` |
| `hmwevents_after_single_event` | After the `</article>` element | `$event` |
| `hmwevents_before_event_header` | Before the `<header>` block | `$event` |
| `hmwevents_after_event_header` | After the `</header>` block | `$event` |
| `hmwevents_before_event_meta` | Before the meta wrapper `<div>` | `$event` |
| `hmwevents_after_event_meta` | After the meta wrapper `</div>` | `$event` |
| `hmwevents_before_event_content` | Before the content `<div>` | `$event` |
| `hmwevents_after_event_content` | After the content `</div>` | `$event` |
| `hmwevents_before_booking_form` | Before the booking form shortcode | `$event` |
| `hmwevents_after_booking_form` | After the booking form shortcode | `$event` |

### Usage example: Bootstrap wrapper

```php
// functions.php or a custom plugin

add_action('hmwevents_before_single_event', function ($event) {
    echo '<div class="container"><div class="row justify-content-center">';
});

add_action('hmwevents_after_single_event', function ($event) {
    echo '</div></div>';
});
```

### Usage example: Custom banner

```php
add_action('hmwevents_after_event_header', function ($event) {
    $banner = get_field('custom_banner', $event->ID);
    if ($banner) {
        echo '<div class="my-custom-banner">' . esc_html($banner) . '</div>';
    }
});
```

## 3. Filter hooks (modify markup and classes)

Use WordPress filters to change CSS classes, labels, or full HTML output. Ideal for integrating a CSS framework like Bootstrap, Tailwind, or a theme's utility classes.

### CSS class filters

All class filters receive a flat array of class strings and return an array. Add, remove, or reorder classes as needed.

| Filter | Default classes | Extra arguments |
|--------|----------------|-----------------|
| `hmwevents_single_event_classes` | `['hmwevents-single-event']` | `$event` |
| `hmwevents_event_header_classes` | `['hmwevents-event-header']` | `$event` |
| `hmwevents_event_type_badge_classes` | `['hmwevents-event-type-badge']` | `$term`, `$event` |
| `hmwevents_event_meta_wrapper_classes` | `['hmwevents-event-meta']` | `$event` |
| `hmwevents_event_meta_item_classes` | `['hmwevents-meta-item']` (+ `'hmwevents-price'` for the price row) | `$meta_key`, `$event` |
| `hmwevents_event_content_classes` | `['hmwevents-event-content']` | `$event` |
| `hmwevents_booking_form_container_classes` | `['hmwevents-booking-form-container']` | `$event` |

### Usage example: Bootstrap classes

```php
add_filter('hmwevents_single_event_classes', function ($classes, $event) {
    return array_merge($classes, ['card', 'p-4']);
}, 10, 2);

add_filter('hmwevents_event_meta_wrapper_classes', function ($classes, $event) {
    return array_merge($classes, ['row', 'g-3']);
}, 10, 2);

add_filter('hmwevents_event_meta_item_classes', function ($classes, $meta_key, $event) {
    $classes[] = 'col-md-6';
    return $classes;
}, 10, 3);
```

### Usage example: Tailwind classes

```php
add_filter('hmwevents_event_header_classes', function ($classes) {
    return ['mb-6', 'border-b', 'border-gray-200', 'pb-4'];
});

add_filter('hmwevents_event_content_classes', function ($classes) {
    return ['prose', 'max-w-none', 'my-8'];
});

add_filter('hmwevents_single_event_classes', function ($classes) {
    return ['bg-white', 'shadow-md', 'rounded-lg', 'p-6', 'max-w-3xl', 'mx-auto'];
});
```

### Title HTML filter

| Filter | Receives | Extra arguments |
|--------|----------|-----------------|
| `hmwevents_event_title_html` | Full `<h1>` element string | `$event` |

```php
add_filter('hmwevents_event_title_html', function ($html, $event) {
    return '<h1 class="display-4 fw-bold">' . esc_html(get_the_title($event)) . '</h1>';
}, 10, 2);
```

### Meta label filter

Change the label text for a meta field. Useful for translation or relabeling.

| Filter | Receives | Extra arguments |
|--------|----------|-----------------|
| `hmwevents_event_meta_label` | Label string (e.g. `'Starts'`) | `$meta_key`, `$event` |

`$meta_key` is one of: `start_date`, `end_date`, `venue`, `webinar_url`, `delivery`, `capacity`, `price`, `organizer`.

```php
add_filter('hmwevents_event_meta_label', function ($label, $meta_key) {
    if ($meta_key === 'start_date') {
        return 'Date & Time';
    }
    if ($meta_key === 'organizer') {
        return 'Facilitator';
    }
    return $label;
}, 10, 2);
```

### Meta value HTML filter

Replace the full inner HTML of a meta row. Receives the rendered `<strong>Label:</strong> <value>` block.

| Filter | Receives | Extra arguments |
|--------|----------|-----------------|
| `hmwevents_event_meta_value_html` | Full rendered inner HTML for the meta row | `$meta_key`, `$event` |

```php
add_filter('hmwevents_event_meta_value_html', function ($html, $meta_key, $event) {
    if ($meta_key === 'price') {
        $price = get_post_meta($event->ID, '_event_price', true);
        return sprintf(
            '<div class="badge bg-success fs-5">$%s</div>',
            number_format((float) $price, 2)
        );
    }
    if ($meta_key === 'capacity') {
        $booked = get_number_of_bookings($event->ID);
        $capacity = (int) get_post_meta($event->ID, '_event_capacity', true);
        return sprintf('<strong>Spots:</strong> %d / %d available', $booked, $capacity);
    }
    return $html;
}, 10, 3);
```

## Full real-world example

Pulling everything together for a Bootstrap + utility class setup:

```php
add_action('hmwevents_before_single_event', function () {
    echo '<div class="container py-5"><div class="row"><div class="col-lg-8 mx-auto">';
});
add_action('hmwevents_after_single_event', function () {
    echo '</div></div></div>';
});

add_filter('hmwevents_single_event_classes', function ($classes) {
    return array_merge($classes, ['card', 'shadow-sm']);
});

add_filter('hmwevents_event_header_classes', function () {
    return ['card-header', 'bg-primary', 'text-white', 'rounded-top'];
});

add_filter('hmwevents_event_type_badge_classes', function ($classes) {
    return ['badge', 'bg-light', 'text-dark', 'me-1'];
});

add_filter('hmwevents_event_meta_wrapper_classes', function () {
    return ['card-body', 'd-grid', 'gap-2'];
});

add_filter('hmwevents_event_meta_item_classes', function ($classes, $meta_key) {
    return ['d-flex', 'justify-content-between', 'align-items-center', 'py-1', 'border-bottom'];
}, 10, 2);

add_filter('hmwevents_event_content_classes', function () {
    return ['card-body', 'border-top'];
});

add_filter('hmwevents_booking_form_container_classes', function () {
    return ['card-footer', 'bg-light'];
});

add_filter('hmwevents_event_title_html', function ($html, $event) {
    return '<h1 class="h3 mb-0">' . esc_html(get_the_title($event)) . '</h1>';
}, 10, 2);
```

## Notes

- The `$event` argument is the WordPress `WP_Post` object for the event.
- The two existing hooks `hmwevents_before_booking_form` and `hmwevents_after_booking_form` are unchanged and remain available.
- For full control beyond what hooks provide, use a theme override (method 1).
