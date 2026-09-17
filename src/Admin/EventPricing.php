<?php

namespace HMWEvents\Admin;

use HMWEvents\Helpers\EventHelper;
use HMWEvents\PostTypes\Event;
use HMWEvents\Services\AttendancePricingService;
use HMWEvents\Services\DatabaseService;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventPricing
{
    public function register(): void
    {
        add_action('add_meta_boxes_' . Event::POST_TYPE, [$this, 'add_meta_box']);
        add_action('save_post_' . Event::POST_TYPE, [$this, 'save'], 30);
        add_filter('acf/prepare_field/name=_event_price', [$this, 'hide_simple_price_for_multi_event'], 20);
    }

    public function add_meta_box(?\WP_Post $post = null): void
    {
        $post_id = $post ? (int) $post->ID : (int) get_the_ID();
        if (!$this->has_multi_options($post_id)) {
            return;
        }

        add_meta_box(
            'hmwevents-event-pricing',
            __('Attendance Pricing', 'hmw-events'),
            [$this, 'render_meta_box'],
            Event::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function render_meta_box(\WP_Post $post): void
    {
        $options = EventHelper::get_active_attendance_options($post->ID);

        wp_nonce_field('hmwevents_event_pricing', 'hmwevents_event_pricing_nonce');
        echo '<p>' . esc_html__('Set the prices for this event. Template prices are used only as defaults.', 'hmw-events') . '</p>';

        echo '<table class="widefat striped overflow-x-auto"><thead><tr>';
        echo '<th>' . esc_html__('Attendance option', 'hmw-events') . '</th>';
        echo '<th>' . esc_html__('Pricing', 'hmw-events') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($options as $option) {
            $rules = AttendancePricingService::decode_rules($option->pricing_rules ?? null) ?? [];
            $mode = AttendancePricingService::normalize_mode((string) ($option->price_mode ?? ''), $rules);
            $flat_price = (float) $option->price;

            $adult_price = $this->rule_price_for_role($rules, AttendancePricingService::ROLE_ADULT, $flat_price);
            $child_price = $this->rule_price_for_role($rules, AttendancePricingService::ROLE_CHILD, 0.0);

            echo '<tr class="hmwevents-pricing-row" data-option-id="' . (int) $option->id . '">';
            echo '<td><strong>' . esc_html($option->label) . '</strong><br><small>' . esc_html($option->option_type) . '</small></td>';
            echo '<td>';
            echo '<select class="hmwevents-price-mode" name="hmwevents_attendance_options[' . (int) $option->id . '][price_mode]">';
            foreach ([
                AttendancePricingService::MODE_FLAT          => __('Flat price', 'hmw-events'),
                AttendancePricingService::MODE_PER_ATTENDEE  => __('Per attendee (role)', 'hmw-events'),
                AttendancePricingService::MODE_AGE_BAND      => __('Age bands', 'hmw-events'),
            ] as $value => $label) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($value),
                    selected($mode, $value, false),
                    esc_html($label)
                );
            }
            echo '</select>';

            echo '<div class="hmwevents-pricing-panel hmwevents-pricing-panel--flat" data-panel="flat">';
            echo '<p class="hmwevents-pricing-help">' . esc_html__('One price for the complete booking.', 'hmw-events') . '</p>';
            echo '<label class="hmwevents-pricing-control"><span>' . esc_html__('Booking price ($)', 'hmw-events') . '</span><input type="number" min="0" step="0.01" name="hmwevents_attendance_options[' . (int) $option->id . '][price]" value="' . esc_attr((string) $flat_price) . '" class="small-text"></label>';
            echo '</div>';

            echo '<div class="hmwevents-pricing-panel hmwevents-pricing-panel--per_attendee" data-panel="per_attendee" style="display:none;">';
            echo '<p class="hmwevents-pricing-help">' . esc_html__('Each attendee is charged the Adult or Child price below, based on their Attendee Type.', 'hmw-events') . '</p>';
            echo '<div class="hmwevents-pricing-control-grid">';
            echo '<label class="hmwevents-pricing-control"><span>' . esc_html__('Adult / parent ($)', 'hmw-events') . '</span><input type="number" min="0" step="0.01" name="hmwevents_attendance_options[' . (int) $option->id . '][adult_price]" value="' . esc_attr((string) $adult_price) . '" class="small-text"></label>';
            echo '<label class="hmwevents-pricing-control"><span>' . esc_html__('Child ($)', 'hmw-events') . '</span><input type="number" min="0" step="0.01" name="hmwevents_attendance_options[' . (int) $option->id . '][child_price]" value="' . esc_attr((string) $child_price) . '" class="small-text"></label>';
            echo '</div>';
            echo '</div>';

            echo '<div class="hmwevents-pricing-panel hmwevents-pricing-panel--age_band" data-panel="age_band" style="display:none;">';
            echo '<p class="hmwevents-pricing-help">' . esc_html__('Set the price for each age range. Leave the upper age blank for no limit.', 'hmw-events') . '</p>';
            echo '<div class="hmwevents-age-band-rules" data-option-id="' . (int) $option->id . '">';
            foreach ($this->age_band_rules_for($rules, $mode) as $index => $rule) {
                echo $this->render_age_band_rule((int) $option->id, $rule, (int) $index);
            }
            echo '</div>';
            echo '<button type="button" class="button button-small hmwevents-add-age-band" data-option-id="' . (int) $option->id . '">+ ' . esc_html__('Add age band', 'hmw-events') . '</button>';
            echo '</div>';

            echo '<div class="hmwevents-pricing-description">';
            echo '<p class="hmwevents-pricing-help">' . esc_html__('Description shown with this option on the booking form. Overrides the template description.', 'hmw-events') . '</p>';
            wp_editor(
                (string) ($option->description ?? ''),
                'hmwevents_option_description_' . (int) $option->id,
                [
                    'textarea_name' => 'hmwevents_attendance_options[' . (int) $option->id . '][description]',
                    'textarea_rows' => 5,
                    'media_buttons' => false,
                    'tinymce'       => ['toolbar1' => 'bold,italic,bullist,numlist,link,unlink,undo,redo'],
                    'quicktags'     => ['buttons' => 'strong,em,ul,ol,li,link'],
                ]
            );
            echo '</div>';

            echo '</td></tr>';
        }

        echo '</tbody></table>';

        $this->render_inline_script();
    }

    public function save(int $post_id): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['hmwevents_event_pricing_nonce'])
            || !wp_verify_nonce($_POST['hmwevents_event_pricing_nonce'], 'hmwevents_event_pricing')
            || !current_user_can('edit_post', $post_id)
        ) {
            return;
        }

        if (empty($_POST['hmwevents_attendance_options'])) {
            return;
        }

        global $wpdb;
        $table = DatabaseService::get_table_name('event_attendance_options');

        foreach ((array) wp_unslash($_POST['hmwevents_attendance_options']) as $option_id => $data) {
            if (!is_array($data)) {
                continue;
            }

            $mode = AttendancePricingService::normalize_mode((string) ($data['price_mode'] ?? ''));
            $rules = $this->build_rules_from_post($mode, $data);
            $flat_price = max(0, (float) ($data['price'] ?? 0));

            $wpdb->update(
                $table,
                [
                    'price'         => $mode === AttendancePricingService::MODE_FLAT ? $flat_price : 0.0,
                    'price_mode'    => $mode,
                    'pricing_rules' => AttendancePricingService::encode_rules($rules),
                    'description'   => wp_kses_post((string) ($data['description'] ?? '')),
                    'updated_at'    => current_time('mysql'),
                ],
                ['id' => (int) $option_id, 'event_post_id' => $post_id, 'is_active' => 1],
                ['%f', '%s', '%s', '%s', '%s'],
                ['%d', '%d', '%d']
            );
        }
    }

    public function hide_simple_price_for_multi_event($field): mixed
    {
        $post_id = (int) ($field['post_id'] ?? get_the_ID());
        if (!$post_id) {
            return $field;
        }

        if ($this->has_multi_options($post_id)) {
            return false;
        }

        if (isset($field['wrapper']['class'])) {
            $field['wrapper']['class'] = trim(str_replace('hmwevents-hidden-by-type', '', $field['wrapper']['class']));
        }

        return $field;
    }

    private function has_multi_options(int $event_id): bool
    {
        if (!$event_id) {
            return false;
        }

        $options = EventHelper::get_active_attendance_options($event_id);
        $mapped = array_map(static function ($option): array {
            return ['option_type' => $option->option_type];
        }, $options);

        return AttendancePricingService::is_multi_options($mapped);
    }


    private function build_rules_from_post(string $mode, array $data): array
    {
        if ($mode === AttendancePricingService::MODE_PER_ATTENDEE) {
            return [
                [
                    'role'    => AttendancePricingService::ROLE_ADULT,
                    'min_age' => 0,
                    'max_age' => null,
                    'price'   => max(0, (float) ($data['adult_price'] ?? 0)),
                ],
                [
                    'role'    => AttendancePricingService::ROLE_CHILD,
                    'min_age' => 0,
                    'max_age' => null,
                    'price'   => max(0, (float) ($data['child_price'] ?? 0)),
                ],
            ];
        }

        if ($mode === AttendancePricingService::MODE_AGE_BAND) {
            $rules = [];

            foreach ((array) ($data['age_bands'] ?? []) as $raw) {
                if (!is_array($raw)) {
                    continue;
                }

                $rules[] = [
                    'role'    => sanitize_key((string) ($raw['role'] ?? AttendancePricingService::ROLE_ANY)) ?: AttendancePricingService::ROLE_ANY,
                    'min_age' => max(0, (int) ($raw['min_age'] ?? 0)),
                    'max_age' => ($raw['max_age'] ?? '') === '' ? null : max(0, (int) $raw['max_age']),
                    'price'   => max(0, (float) ($raw['price'] ?? 0)),
                ];
            }

            return $rules;
        }

        return [];
    }

    private function rule_price_for_role(array $rules, string $role, float $fallback): float
    {
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            if (($rule['role'] ?? AttendancePricingService::ROLE_ANY) === $role) {
                return (float) ($rule['price'] ?? 0);
            }
        }

        return $fallback;
    }

    private function age_band_rules_for(array $rules, string $mode): array
    {
        if ($mode !== AttendancePricingService::MODE_AGE_BAND || empty($rules)) {
            return [
                ['role' => AttendancePricingService::ROLE_ANY, 'min_age' => 0, 'max_age' => '', 'price' => 0],
            ];
        }

        return array_map(static function ($rule): array {
            return [
                'role'    => $rule['role'] ?? AttendancePricingService::ROLE_ANY,
                'min_age' => (int) ($rule['min_age'] ?? 0),
                'max_age' => $rule['max_age'] ?? '',
                'price'   => (float) ($rule['price'] ?? 0),
            ];
        }, array_values($rules));
    }

    private function render_age_band_rule(int $option_id, array $rule, int $index): string
    {
        $role_options = [
            AttendancePricingService::ROLE_ANY   => __('Any', 'hmw-events'),
            AttendancePricingService::ROLE_ADULT => __('Adult', 'hmw-events'),
            AttendancePricingService::ROLE_CHILD => __('Child', 'hmw-events'),
        ];

        $html = '<div class="hmwevents-age-band-rule">';
        $html .= '<label class="hmwevents-age-band-control"><span>' . esc_html__('Applies to', 'hmw-events') . '</span><select name="hmwevents_attendance_options[' . $option_id . '][age_bands][' . $index . '][role]">';
        foreach ($role_options as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '" ' . selected($rule['role'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<label class="hmwevents-age-band-control"><span>' . esc_html__('From age', 'hmw-events') . '</span><span class="hmwevents-age-band-field"><input type="number" min="0" step="1" name="hmwevents_attendance_options[' . $option_id . '][age_bands][' . $index . '][min_age]" value="' . esc_attr((string) $rule['min_age']) . '"><small>' . esc_html__('years', 'hmw-events') . '</small></span></label>';
        $html .= '<label class="hmwevents-age-band-control"><span>' . esc_html__('To age', 'hmw-events') . '</span><span class="hmwevents-age-band-field"><input type="number" min="0" step="1" name="hmwevents_attendance_options[' . $option_id . '][age_bands][' . $index . '][max_age]" value="' . esc_attr((string) $rule['max_age']) . '"><small>' . esc_html__('blank = no limit', 'hmw-events') . '</small></span></label>';
        $html .= '<label class="hmwevents-age-band-control"><span>' . esc_html__('Price per attendee ($)', 'hmw-events') . '</span><input type="number" min="0" step="0.01" name="hmwevents_attendance_options[' . $option_id . '][age_bands][' . $index . '][price]" value="' . esc_attr((string) $rule['price']) . '"></label>';
        $html .= '<button type="button" class="hmwevents-age-band-remove" aria-label="' . esc_attr__('Remove age band', 'hmw-events') . '">&times;</button>';
        $html .= '</div>';

        return $html;
    }

    private function render_inline_script(): void
    {
        ?>
        <script>
        (function ($) {
            function updatePanels($row) {
                var mode = $row.find('.hmwevents-price-mode').val();
                $row.find('.hmwevents-pricing-panel').hide();
                $row.find('.hmwevents-pricing-panel[data-panel="' + mode + '"]').show();
            }

            $(document).on('change', '.hmwevents-price-mode', function () {
                updatePanels($(this).closest('.hmwevents-pricing-row'));
            });

            function reindexAgeBands($container) {
                $container.find('.hmwevents-age-band-rule').each(function (i) {
                    $(this).find('input, select').each(function () {
                        var name = $(this).attr('name');
                        if (!name) return;
                        name = name.replace(/\[age_bands\]\[\d+\]/, '[age_bands][' + i + ']');
                        $(this).attr('name', name);
                    });
                });
            }

            $(document).on('click', '.hmwevents-add-age-band', function () {
                var optionId = $(this).data('option-id');
                var container = $('.hmwevents-age-band-rules[data-option-id="' + optionId + '"]');
                var index = container.find('.hmwevents-age-band-rule').length;
                var block = '<div class="hmwevents-age-band-rule">' +
                    '<label class="hmwevents-age-band-control"><span>Applies to</span><select name="hmwevents_attendance_options[' + optionId + '][age_bands][' + index + '][role]">' +
                    '<option value="any">Any</option><option value="adult">Adult</option><option value="child">Child</option>' +
                    '</select></label>' +
                    '<label class="hmwevents-age-band-control"><span>From age</span><span class="hmwevents-age-band-field"><input type="number" min="0" step="1" name="hmwevents_attendance_options[' + optionId + '][age_bands][' + index + '][min_age]" value="0"><small>years</small></span></label>' +
                    '<label class="hmwevents-age-band-control"><span>To age</span><span class="hmwevents-age-band-field"><input type="number" min="0" step="1" name="hmwevents_attendance_options[' + optionId + '][age_bands][' + index + '][max_age]" value=""><small>blank = no limit</small></span></label>' +
                    '<label class="hmwevents-age-band-control"><span>Price per attendee ($)</span><input type="number" min="0" step="0.01" name="hmwevents_attendance_options[' + optionId + '][age_bands][' + index + '][price]" value="0"></label>' +
                    '<button type="button" class="hmwevents-age-band-remove" aria-label="Remove age band">&times;</button>' +
                    '</div>';
                container.append(block);
            });

            $(document).on('click', '.hmwevents-age-band-remove', function () {
                var container = $(this).closest('.hmwevents-age-band-rules');
                $(this).closest('.hmwevents-age-band-rule').remove();
                reindexAgeBands(container);
            });

            $(function () {
                $('.hmwevents-pricing-row').each(function () {
                    updatePanels($(this));
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}
