<?php

add_action('breakdance_loaded', function () {
    \Breakdance\AJAX\register_handler(
        'hmwevents_get_course_types',
        'hmwevents_ajax_get_course_types',
        'edit',
        true
    );
});

/**
 * @return array{value: string, text: string}[]
 */
function hmwevents_ajax_get_course_types(): array
{
    $terms = get_terms([
        'taxonomy'   => 'course_type',
        'hide_empty' => false,
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    return array_map(fn($term) => [
        'value' => $term->slug,
        'text'  => $term->name,
    ], $terms);
}
