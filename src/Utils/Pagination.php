<?php
/**
 * Pagination Utility.
 *
 * Reusable pagination helper for admin dashboards.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Utils;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Pagination helper class.
 */
class Pagination
{
    /**
     * Items per page.
     *
     * @var int
     */
    private $per_page;

    /**
     * Current page number.
     *
     * @var int
     */
    private $current_page;

    /**
     * Total number of items.
     *
     * @var int
     */
    private $total_items;

    /**
     * Base URL for pagination links.
     *
     * @var string
     */
    private $base_url;

    /**
     * Constructor.
     *
     * @since 1.0.0
     * @param int    $total_items Total number of items.
     * @param int    $per_page    Items per page (default: 25).
     * @param int    $current_page Current page number (default: 1).
     * @param string $base_url    Base URL for pagination links.
     */
    public function __construct($total_items, $per_page = 25, $current_page = 1, $base_url = '')
    {
        $this->total_items = max(0, (int) $total_items);
        $this->per_page = max(1, (int) $per_page);
        $this->current_page = max(1, (int) $current_page);
        $this->base_url = $base_url ?: $this->get_current_url();
    }

    /**
     * Get the SQL LIMIT clause.
     *
     * @since 1.0.0
     * @return string SQL LIMIT clause.
     */
    public function get_limit()
    {
        return sprintf('LIMIT %d OFFSET %d', $this->per_page, $this->get_offset());
    }

    /**
     * Get the offset for the current page.
     *
     * @since 1.0.0
     * @return int Offset value.
     */
    public function get_offset()
    {
        return ($this->current_page - 1) * $this->per_page;
    }

    /**
     * Get total number of pages.
     *
     * @since 1.0.0
     * @return int Total pages.
     */
    public function get_total_pages()
    {
        return (int) ceil($this->total_items / $this->per_page);
    }

    /**
     * Check if there are multiple pages.
     *
     * @since 1.0.0
     * @return bool True if pagination is needed.
     */
    public function has_pages()
    {
        return $this->get_total_pages() > 1;
    }

    /**
     * Get current page number.
     *
     * @since 1.0.0
     * @return int Current page.
     */
    public function get_current_page()
    {
        return $this->current_page;
    }

    /**
     * Get items per page.
     *
     * @since 1.0.0
     * @return int Items per page.
     */
    public function get_per_page()
    {
        return $this->per_page;
    }

    /**
     * Get total items.
     *
     * @since 1.0.0
     * @return int Total items.
     */
    public function get_total_items()
    {
        return $this->total_items;
    }

    /**
     * Render pagination links.
     *
     * @since 1.0.0
     * @param string $format Output format: 'wordpress' or 'custom' (default: 'wordpress').
     * @return string Pagination HTML.
     */
    public function render($format = 'wordpress')
    {
        if (!$this->has_pages()) {
            return '';
        }

        if ($format === 'wordpress') {
            return $this->render_wordpress_style();
        }

        return $this->render_custom_style();
    }

    /**
     * Render WordPress-style pagination.
     *
     * @since 1.0.0
     * @return string Pagination HTML.
     */
    private function render_wordpress_style()
    {
        $total_pages = $this->get_total_pages();
        $current = $this->current_page;

        $page_links = paginate_links([
            'base' => add_query_arg('paged', '%#%', $this->base_url),
            'format' => '',
            'prev_text' => __('&laquo; Previous', 'hmw-events'),
            'next_text' => __('Next &raquo;', 'hmw-events'),
            'total' => $total_pages,
            'current' => $current,
            'type' => 'list',
            'end_size' => 3,
            'mid_size' => 2,
        ]);

        if (!$page_links) {
            return '';
        }

        $output = '<div class="tablenav">';
        $output .= '<div class="tablenav-pages">';
        $output .= sprintf(
            '<span class="displaying-num">%s</span>',
            sprintf(
                _n('%s item', '%s items', $this->total_items, 'hmw-events'),
                number_format_i18n($this->total_items)
            )
        );
        $output .= $page_links;
        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render custom pagination style.
     *
     * @since 1.0.0
     * @return string Pagination HTML.
     */
    private function render_custom_style()
    {
        $total_pages = $this->get_total_pages();
        $current = $this->current_page;

        $output = '<div class="hmwevents-pagination" style="display: flex; justify-content: space-between; align-items: center; padding: 15px; background: #fff; border: 1px solid #ddd; margin-top: 20px;">';
        
        // Showing X-Y of Z items
        $start = $this->get_offset() + 1;
        $end = min($start + $this->per_page - 1, $this->total_items);
        $output .= sprintf(
            '<span class="hmwevents-pagination-info">Showing %d-%d of %d items</span>',
            $start,
            $end,
            $this->total_items
        );

        // Pagination links
        $output .= '<div class="hmwevents-pagination-links" style="display: flex; gap: 5px;">';

        // Previous button
        if ($current > 1) {
            $output .= sprintf(
                '<a href="%s" class="button">&laquo; Previous</a>',
                esc_url(add_query_arg('paged', $current - 1, $this->base_url))
            );
        } else {
            $output .= '<span class="button disabled" style="opacity: 0.5; cursor: not-allowed;">&laquo; Previous</span>';
        }

        // Page numbers
        $range = 2; // Number of pages to show on each side of current
        $start_page = max(1, $current - $range);
        $end_page = min($total_pages, $current + $range);

        if ($start_page > 1) {
            $output .= sprintf(
                '<a href="%s" class="button">1</a>',
                esc_url(add_query_arg('paged', 1, $this->base_url))
            );
            if ($start_page > 2) {
                $output .= '<span class="button disabled" style="border: none; cursor: default;">...</span>';
            }
        }

        for ($i = $start_page; $i <= $end_page; $i++) {
            if ($i === $current) {
                $output .= sprintf(
                    '<span class="button button-primary" style="pointer-events: none;">%d</span>',
                    $i
                );
            } else {
                $output .= sprintf(
                    '<a href="%s" class="button">%d</a>',
                    esc_url(add_query_arg('paged', $i, $this->base_url)),
                    $i
                );
            }
        }

        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                $output .= '<span class="button disabled" style="border: none; cursor: default;">...</span>';
            }
            $output .= sprintf(
                '<a href="%s" class="button">%d</a>',
                esc_url(add_query_arg('paged', $total_pages, $this->base_url)),
                $total_pages
            );
        }

        // Next button
        if ($current < $total_pages) {
            $output .= sprintf(
                '<a href="%s" class="button">Next &raquo;</a>',
                esc_url(add_query_arg('paged', $current + 1, $this->base_url))
            );
        } else {
            $output .= '<span class="button disabled" style="opacity: 0.5; cursor: not-allowed;">Next &raquo;</span>';
        }

        $output .= '</div>'; // .hmwevents-pagination-links
        $output .= '</div>'; // .hmwevents-pagination

        return $output;
    }

    /**
     * Get current URL with query parameters.
     *
     * @since 1.0.0
     * @return string Current URL.
     */
    private function get_current_url()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Remove existing paged parameter
        $url = $protocol . '://' . $host . $uri;
        return remove_query_arg('paged', $url);
    }

    /**
     * Get pagination data as array.
     *
     * @since 1.0.0
     * @return array Pagination data.
     */
    public function to_array()
    {
        return [
            'current_page' => $this->current_page,
            'per_page' => $this->per_page,
            'total_items' => $this->total_items,
            'total_pages' => $this->get_total_pages(),
            'offset' => $this->get_offset(),
            'has_previous' => $this->current_page > 1,
            'has_next' => $this->current_page < $this->get_total_pages(),
            'previous_page' => max(1, $this->current_page - 1),
            'next_page' => min($this->get_total_pages(), $this->current_page + 1),
        ];
    }
}
