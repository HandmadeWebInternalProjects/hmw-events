<?php

/**
 * Event Custom Post Type.
 *
 * Registers the hmw_event CPT which is the core domain object of the
 * HMW Events system. Replaces the legacy educator_course CPT.
 *
 * Custom statuses: Draft, Published, Fully Booked, Cancelled, Archived
 *
 * @package HMWEvents\PostTypes
 * @since 2.0.0
 */

namespace HMWEvents\PostTypes;

defined('ABSPATH') || die('Don\'t run this file directly!');

class Event
{
    public const POST_TYPE = 'hmw_event';

    public const META_INVITATION_ONLY = '_event_is_invitation_only';

    public static function is_invitation_only(int $post_id): bool
    {
        return (bool) get_post_meta($post_id, self::META_INVITATION_ONLY, true);
    }

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_post_statuses']);
        add_action('init', [$this, 'grant_admin_capabilities']);
        add_filter('display_post_states', [$this, 'display_custom_states'], 10, 2);
        add_action('post_submitbox_misc_actions', [$this, 'inject_custom_status_options']);
        add_filter('wp_insert_post_data', [$this, 'preserve_custom_status'], 10, 2);
        add_action('add_meta_boxes', [$this, 'add_invitation_only_meta_box']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save_invitation_only_meta'], 10, 2);
    }

    public function register_post_type(): void
    {
        $labels = [
            'name'                  => _x('Events', 'Post type general name', 'hmw-events'),
            'singular_name'         => _x('Event', 'Post type singular name', 'hmw-events'),
            'menu_name'             => _x('Events', 'Admin Menu text', 'hmw-events'),
            'name_admin_bar'        => _x('Event', 'Add New on Toolbar', 'hmw-events'),
            'add_new'               => __('Add New', 'hmw-events'),
            'add_new_item'          => __('Add New Event', 'hmw-events'),
            'new_item'              => __('New Event', 'hmw-events'),
            'edit_item'             => __('Edit Event', 'hmw-events'),
            'view_item'             => __('View Event', 'hmw-events'),
            'all_items'             => __('All Events', 'hmw-events'),
            'search_items'          => __('Search Events', 'hmw-events'),
            'parent_item_colon'     => __('Parent Events:', 'hmw-events'),
            'not_found'             => __('No events found.', 'hmw-events'),
            'not_found_in_trash'    => __('No events found in Trash.', 'hmw-events'),
            'filter_items_list'     => _x('Filter events list', 'Screen reader text', 'hmw-events'),
            'items_list_navigation' => _x('Events list navigation', 'Screen reader text', 'hmw-events'),
            'items_list'            => _x('Events list', 'Screen reader text', 'hmw-events'),
            'archives'              => _x('Event archives', 'Post type archive label', 'hmw-events'),
            'attributes'            => _x('Event Attributes', 'Post type attributes label', 'hmw-events'),
            'item_published'        => __('Event published.', 'hmw-events'),
            'item_updated'          => __('Event updated.', 'hmw-events'),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => true,
            'show_in_rest'        => true,
            'query_var'           => true,
            'rewrite'             => ['slug' => 'events', 'with_front' => false],
            'capability_type'     => ['hmw_event', 'hmw_events'],
            'map_meta_cap'        => true,
            'has_archive'         => true,
            'hierarchical'        => true,
            'menu_position'       => 20,
            'menu_icon'           => 'dashicons-calendar-alt',
            'supports'            => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'custom-fields', 'page-attributes'],
            'taxonomies'          => ['category', 'post_tag'],
            'delete_with_user'    => false,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Register custom post statuses.
     */
    public function register_post_statuses(): void
    {
        register_post_status('fully_booked', [
            'label'                     => _x('Fully Booked', 'post status', 'hmw-events'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Fully Booked <span class="count">(%s)</span>',
                'Fully Booked <span class="count">(%s)</span>',
                'hmw-events'
            ),
        ]);

        register_post_status('cancelled', [
            'label'                     => _x('Cancelled', 'post status', 'hmw-events'),
            'public'                    => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Cancelled <span class="count">(%s)</span>',
                'Cancelled <span class="count">(%s)</span>',
                'hmw-events'
            ),
        ]);

        register_post_status('archived', [
            'label'                     => _x('Archived', 'post status', 'hmw-events'),
            'public'                    => false,
            'exclude_from_search'       => true,
            'publicly_queryable'        => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Archived <span class="count">(%s)</span>',
                'Archived <span class="count">(%s)</span>',
                'hmw-events'
            ),
        ]);
    }

    /**
     * Display custom post states in the admin list.
     */
    public function display_custom_states(array $post_states, \WP_Post $post): array
    {
        if ($post->post_type !== self::POST_TYPE) {
            return $post_states;
        }

        $status_labels = [
            'fully_booked'  => __('Fully Booked', 'hmw-events'),
            'cancelled'     => __('Cancelled', 'hmw-events'),
            'archived'      => __('Archived', 'hmw-events'),
        ];

        if (isset($status_labels[$post->post_status])) {
            $post_states[$post->post_status] = $status_labels[$post->post_status];
        }

        if ($post->post_status === 'publish' && get_post_meta($post->ID, self::META_INVITATION_ONLY, true)) {
            $post_states['invitation_only'] = __('By Invitation', 'hmw-events');
        }

        return $post_states;
    }

    /**
     * Inject custom post status options into the Publish meta box dropdown.
     *
     * WordPress does not automatically include custom post statuses registered
     * via register_post_status() in the edit screen's Publish/Status dropdown.
     * This method adds them via inline JavaScript, filtered by the current
     * event type's workflow so only valid transitions are shown.
     */
    public function inject_custom_status_options(\WP_Post $post): void
    {
        if ($post->post_type !== self::POST_TYPE) {
            return;
        }

        $current_status = $post->post_status;

        if ($current_status === 'auto-draft' || $current_status === 'trash') {
            return;
        }

        $terms = wp_get_post_terms($post->ID, \HMWEvents\Taxonomies\EventType::TAXONOMY);
        $type_slug = (!empty($terms) && !is_wp_error($terms)) ? $terms[0]->slug : '';

        $workflow = \HMWEvents\Registry\EventTypeRegistry::get_workflow($type_slug);
        $allowed = $workflow[$current_status] ?? [];

        if (empty($allowed)) {
            return;
        }

        $statuses = get_post_statuses();
        $core_statuses = ['publish', 'draft', 'pending', 'future', 'private', 'trash', 'auto-draft'];
        $custom_options = [];

        foreach ($allowed as $slug) {
            if (in_array($slug, $core_statuses, true)) {
                continue;
            }
            $custom_options[$slug] = $statuses[$slug] ?? $slug;
        }

        if (!in_array($current_status, $core_statuses, true) && !isset($custom_options[$current_status])) {
            $custom_options[$current_status] = $statuses[$current_status] ?? $current_status;
        }

        if (empty($custom_options)) {
            return;
        }
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var select = document.getElementById('post_status');
            if (!select) return;
            var current = <?php echo wp_json_encode($current_status, JSON_HEX_TAG); ?>;
            var opts = <?php echo wp_json_encode((object) $custom_options, JSON_HEX_TAG | JSON_FORCE_OBJECT); ?>;
            var publishBtn = document.getElementById('publish');
            var statusDisplay = document.getElementById('post-status-display');
            var postForm = document.getElementById('post');

            Object.keys(opts).forEach(function(slug) {
                var o = document.createElement('option');
                o.value = slug;
                o.textContent = opts[slug];
                if (slug === current) o.selected = true;
                select.appendChild(o);
            });

            if (publishBtn && Object.keys(opts).length > 0) {
                var origName = publishBtn.getAttribute('name');
                var origText = publishBtn.value;

                function updatePublishButton() {
                    var val = select.value;
                    if (statusDisplay && opts.hasOwnProperty(val)) {
                        statusDisplay.textContent = opts[val];
                    }

                    if (opts.hasOwnProperty(val)) {
                        publishBtn.setAttribute('name', 'save');
                        publishBtn.value = 'Save as ' + opts[val];
                    } else {
                        publishBtn.setAttribute('name', origName);
                        publishBtn.value = origText;
                    }
                }

                select.addEventListener('change', updatePublishButton);
                updatePublishButton();
            } else if (statusDisplay && opts.hasOwnProperty(current)) {
                statusDisplay.textContent = opts[current];
            }

            if (postForm && current !== 'cancelled') {
                postForm.addEventListener('submit', function(event) {
                    if (select.value === 'cancelled' && !window.confirm('<?php echo esc_js(__('This will cancel all confirmed bookings for this event. Continue?', 'hmw-events')); ?>')) {
                        event.preventDefault();
                    }
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Preserve the submitted custom post status when saving.
     *
     * WordPress core's edit_post() overrides post_status to 'publish' when
     * the Publish button is clicked (i.e. $_POST['publish'] is set). The
     * inline JS in inject_custom_status_options() changes the button's name
     * from "publish" to "save" when a custom status is selected, preventing
     * the override. This filter is a server-side safety net — it reads the
     * raw $_POST['post_status'] and applies it when it's one of our custom
     * statuses.
     */
    public function preserve_custom_status(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? '') !== self::POST_TYPE) {
            return $data;
        }

        $raw_status = $_POST['post_status'] ?? '';
        $custom_statuses = ['fully_booked', 'cancelled', 'archived'];

        if (in_array($raw_status, $custom_statuses, true)) {
            $data['post_status'] = $raw_status;
        }

        return $data;
    }

    /**
     * Add the "Invitation Only" meta box to the event edit screen.
     */
    public function add_invitation_only_meta_box(): void
    {
        add_meta_box(
            'hmwevents_invitation_only',
            __('Access Control', 'hmw-events'),
            [$this, 'render_invitation_only_meta_box'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    /**
     * Render the "Invitation Only" checkbox.
     */
    public function render_invitation_only_meta_box(\WP_Post $post): void
    {
        $is_invitation_only = (bool) get_post_meta($post->ID, self::META_INVITATION_ONLY, true);

        wp_nonce_field('hmwevents_invitation_only', 'hmwevents_invitation_only_nonce');
        ?>
        <label for="hmwevents-invitation-only">
            <input type="checkbox"
                   name="<?php echo esc_attr(self::META_INVITATION_ONLY); ?>"
                   id="hmwevents-invitation-only"
                   value="1"
                   <?php checked($is_invitation_only); ?>>
            <?php esc_html_e('Invitation Only', 'hmw-events'); ?>
        </label>
        <p class="description" style="margin-top:4px;">
            <?php esc_html_e('When enabled, this event is hidden from public listings. Access requires a valid invitation token.', 'hmw-events'); ?>
        </p>
        <?php
    }

    /**
     * Save the "Invitation Only" meta when the post is saved.
     */
    public function save_invitation_only_meta(int $post_id, \WP_Post $post): void
    {
        if (!isset($_POST['hmwevents_invitation_only_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['hmwevents_invitation_only_nonce'], 'hmwevents_invitation_only')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (empty($_POST[self::META_INVITATION_ONLY])) {
            delete_post_meta($post_id, self::META_INVITATION_ONLY);
            return;
        }

        update_post_meta($post_id, self::META_INVITATION_ONLY, '1');
    }

    /**
     * Grant custom capabilities to the admin role.
     */
    public function grant_admin_capabilities(): void
    {
        $admin = get_role('administrator');
        if (!$admin) {
            return;
        }

        $capabilities = [
            'edit_hmw_event',
            'read_hmw_event',
            'delete_hmw_event',
            'edit_hmw_events',
            'edit_others_hmw_events',
            'publish_hmw_events',
            'read_private_hmw_events',
            'delete_hmw_events',
            'delete_private_hmw_events',
            'delete_published_hmw_events',
            'delete_others_hmw_events',
            'edit_private_hmw_events',
            'edit_published_hmw_events',
        ];

        foreach ($capabilities as $cap) {
            $admin->add_cap($cap);
        }
    }
}
