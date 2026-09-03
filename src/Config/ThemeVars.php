<?php

namespace HMWEvents\Config;

defined('ABSPATH') || die('Don\'t run this file directly!');

class ThemeVars
{
    public static function groups(): array
    {
        return [

            'colors' => [
                'header' => '<h3>Colours</h3>',
                'fields' => [
                    [
                        'key'     => 'primary_color',
                        'var'     => '--hmw-color-primary',
                        'type'    => 'color',
                        'label'   => 'Primary Colour',
                        'default' => '#2563eb',
                        'desc'    => 'Used for buttons, links, accents, and highlights across booking forms, event cards, and filters.',
                    ],
                    [
                        'key'     => 'primary_hover',
                        'var'     => '--hmw-color-primary-hover',
                        'type'    => 'color',
                        'label'   => 'Primary Hover Colour',
                        'default' => '#1d4ed8',
                    ],
                    [
                        'key'     => 'button_text',
                        'var'     => '--hmw-color-button-text',
                        'type'    => 'color',
                        'label'   => 'Button Text Colour',
                        'default' => '#ffffff',
                        'desc'    => 'Text colour on primary buttons, badges, and active pagination items.',
                    ],
                    [
                        'key'     => 'text_color',
                        'var'     => '--hmw-color-text',
                        'type'    => 'color',
                        'label'   => 'Text Colour',
                        'default' => '#1e293b',
                    ],
                    [
                        'key'     => 'text_muted',
                        'var'     => '--hmw-color-text-muted',
                        'type'    => 'color',
                        'label'   => 'Muted Text Colour',
                        'default' => '#64748b',
                    ],
                    [
                        'key'     => 'label_color',
                        'var'     => '--hmw-color-label',
                        'type'    => 'color',
                        'label'   => 'Form Label Colour',
                        'default' => '#334155',
                    ],
                    [
                        'key'     => 'border_color',
                        'var'     => '--hmw-color-border',
                        'type'    => 'color',
                        'label'   => 'Border Colour',
                        'default' => '#e2e8f0',
                    ],
                    [
                        'key'     => 'border_focus',
                        'var'     => '--hmw-color-border-focus',
                        'type'    => 'color',
                        'label'   => 'Border Focus Colour',
                        'default' => '#93c5fd',
                        'desc'    => 'Focus rings on inputs and the border of online-delivery event cards.',
                    ],
                    [
                        'key'     => 'bg',
                        'var'     => '--hmw-color-bg',
                        'type'    => 'color',
                        'label'   => 'Surface Background',
                        'default' => '#ffffff',
                        'desc'    => 'Background of cards, the filter bar, inputs, and buttons.',
                    ],
                    [
                        'key'     => 'bg_section',
                        'var'     => '--hmw-color-bg-section',
                        'type'    => 'color',
                        'label'   => 'Section Background',
                        'default' => '#f8fafc',
                        'desc'    => 'Soft background behind secondary content blocks.',
                    ],
                    [
                        'key'     => 'bg_page',
                        'var'     => '--hmw-color-bg-page',
                        'type'    => 'color',
                        'label'   => 'Page Background',
                        'default' => '#f1f5f9',
                        'desc'    => 'Outer background of the v3 booking form.',
                    ],
                    [
                        'key'     => 'error_color',
                        'var'     => '--hmw-color-error',
                        'type'    => 'color',
                        'label'   => 'Error Colour',
                        'default' => '#dc2626',
                    ],
                    [
                        'key'     => 'success_color',
                        'var'     => '--hmw-color-success',
                        'type'    => 'color',
                        'label'   => 'Success Colour',
                        'default' => '#16a34a',
                    ],
                    [
                        'key'     => 'warning_color',
                        'var'     => '--hmw-color-warning',
                        'type'    => 'color',
                        'label'   => 'Warning Colour',
                        'default' => '#d97706',
                        'desc'    => 'Pending payment statuses and notice highlights.',
                    ],
                ],
            ],

            'typography' => [
                'header' => '<h3>Typography</h3>',
                'fields' => [
                    [
                        'key'     => 'font_family',
                        'var'     => '--hmw-font-family',
                        'type'    => 'text',
                        'label'   => 'Font Family',
                        'default' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                        'desc'    => 'CSS font-family value. Applied to booking forms and event cards.',
                    ],
                    [
                        'key'     => 'font_size_base',
                        'var'     => '--hmw-font-size-base',
                        'type'    => 'text',
                        'label'   => 'Base Font Size',
                        'default' => '16px',
                        'desc'    => 'Base font size for booking forms and event cards (e.g. 16px, 1rem).',
                    ],
                ],
            ],

            'spacing' => [
                'header' => '<h3>Spacing &amp; Borders</h3>',
                'fields' => [
                    [
                        'key'     => 'radius',
                        'var'     => '--hmw-radius',
                        'type'    => 'text',
                        'label'   => 'Border Radius',
                        'default' => '8px',
                        'desc'    => 'Default border radius for cards, panels, and form sections.',
                    ],
                    [
                        'key'     => 'radius_sm',
                        'var'     => '--hmw-radius-sm',
                        'type'    => 'text',
                        'label'   => 'Small Border Radius',
                        'default' => '4px',
                        'desc'    => 'Border radius for buttons, inputs, and tags.',
                    ],
                    [
                        'key'     => 'gap',
                        'var'     => '--hmw-gap',
                        'type'    => 'text',
                        'label'   => 'Grid Gap / Spacing',
                        'default' => '16px',
                        'desc'    => 'Default gap between grid items and form sections.',
                    ],
                    [
                        'key'     => 'shadow',
                        'var'     => '--hmw-shadow-sm',
                        'type'    => 'text',
                        'label'   => 'Card Shadow',
                        'default' => '0 1px 2px rgba(0, 0, 0, .05)',
                        'desc'    => 'CSS box-shadow value applied to cards, the filter bar, and buttons.',
                    ],
                ],
            ],

            'filters' => [
                'header' => '<h3>Filter Bar</h3><p>Overrides for the event listings filter bar. Leave a colour empty to fall back to the matching general colour above.</p>',
                'fields' => [
                    [
                        'key'     => 'filter_bg',
                        'var'     => '--hmw-filter-bg',
                        'type'    => 'color',
                        'label'   => 'Filter Bar Background',
                        'default' => '',
                        'desc'    => 'Leave empty to use the Surface Background.',
                    ],
                    [
                        'key'     => 'filter_border',
                        'var'     => '--hmw-filter-border',
                        'type'    => 'color',
                        'label'   => 'Filter Bar Border',
                        'default' => '',
                        'desc'    => 'Leave empty to use the Border Colour.',
                    ],
                    [
                        'key'     => 'filter_label',
                        'var'     => '--hmw-filter-label',
                        'type'    => 'color',
                        'label'   => 'Filter Label Colour',
                        'default' => '',
                        'desc'    => 'Leave empty to use the Muted Text Colour.',
                    ],
                    [
                        'key'     => 'filter_button_bg',
                        'var'     => '--hmw-filter-button-bg',
                        'type'    => 'color',
                        'label'   => 'Apply Button Colour',
                        'default' => '',
                        'desc'    => 'Leave empty to use the Primary Colour.',
                    ],
                    [
                        'key'     => 'filter_button_hover',
                        'var'     => '--hmw-filter-button-hover',
                        'type'    => 'color',
                        'label'   => 'Apply Button Hover Colour',
                        'default' => '',
                        'desc'    => 'Leave empty to use the Primary Hover Colour.',
                    ],
                    [
                        'key'     => 'filter_button_text',
                        'var'     => '--hmw-filter-button-text',
                        'type'    => 'color',
                        'label'   => 'Apply Button Text Colour',
                        'default' => '',
                        'desc'    => 'Leave empty to use the Button Text Colour.',
                    ],
                    [
                        'key'     => 'filter_tag_bg',
                        'var'     => '--hmw-filter-tag-bg',
                        'type'    => 'color',
                        'label'   => 'Active Filter Tag Background',
                        'default' => '',
                        'desc'    => 'Leave empty to use the Section Background.',
                    ],
                    [
                        'key'     => 'filter_tag_border',
                        'var'     => '--hmw-filter-tag-border',
                        'type'    => 'color',
                        'label'   => 'Active Filter Tag Border',
                        'default' => '',
                        'desc'    => 'Leave empty to use the Border Colour.',
                    ],
                ],
            ],

            'cards' => [
                'header' => '<h3>Event Cards</h3><p>Overrides for event cards in the listings grid. Leave a colour empty to fall back to the matching general colour above.</p>',
                'fields' => [
                    [
                        'key'     => 'card_bg',
                        'var'     => '--hmw-card-bg',
                        'type'    => 'color',
                        'label'   => 'Event Card Background',
                        'default' => '',
                        'desc'    => 'Leave empty to use the Surface Background.',
                    ],
                    [
                        'key'     => 'card_border',
                        'var'     => '--hmw-card-border',
                        'type'    => 'color',
                        'label'   => 'Event Card Border',
                        'default' => '',
                        'desc'    => 'Leave empty to use the Border Colour.',
                    ],
                ],
            ],

        ];
    }

    public static function fields(): array
    {
        $fields = [];
        foreach (self::groups() as $group) {
            foreach ($group['fields'] as $field) {
                $fields[$field['key']] = $field;
            }
        }
        return $fields;
    }
}
