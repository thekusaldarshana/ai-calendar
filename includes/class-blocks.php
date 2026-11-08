<?php
defined('ABSPATH') || exit;

/**
 * WordPress block editor (Gutenberg) blocks: "WP AI Calendar" and "WP AI Event Cards".
 * Rendered on the server by the same code as the shortcodes; the editor shows a live server-side preview.
 */
class WPAICal_Blocks {
    public static function init() {
        add_action('init', [__CLASS__, 'register'], 25);
    }

    public static function register() {
        if (!function_exists('register_block_type')) {
            return;
        }
        wp_register_script('wpaical-blocks', WPAICal_Assets::url('blocks.js'), ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n'], WPAICAL_VERSION, true);

        $str = ['type' => 'string', 'default' => ''];
        register_block_type('wpaical/upcoming', [
            'editor_script'   => 'wpaical-blocks',
            'style'           => 'wpaical-widget',
            'script'          => 'wpaical-widget',
            'render_callback' => function ($a) {
                return WPAICal_Widget::render($a);
            },
            'attributes' => [
                'program' => $str, 'match' => ['type' => 'string', 'default' => 'prefix'], 'category' => $str,
                'limit' => ['type' => 'number', 'default' => 10], 'days' => ['type' => 'number', 'default' => 365],
                'series' => ['type' => 'string', 'default' => 'all'], 'heading' => ['type' => 'string', 'default' => 'Upcoming Events'],
                'lead' => $str, 'button' => ['type' => 'string', 'default' => 'Details'],
                'empty' => ['type' => 'string', 'default' => 'No upcoming events right now. Please check back soon.'],
            ],
        ]);
        register_block_type('wpaical/calendar', [
            'editor_script'   => 'wpaical-blocks',
            'style'           => 'wpaical-calendar',
            'script'          => 'wpaical-calendar',
            'render_callback' => function ($a) {
                $a['legend']          = !empty($a['legend']) ? 'yes' : 'no';
                $a['timezone_toggle'] = !empty($a['timezone_toggle']) ? 'yes' : 'no';
                $a['new_tab']         = !empty($a['new_tab']) ? 'yes' : 'no';
                return WPAICal_Calendar::render($a);
            },
            'attributes' => [
                'views' => ['type' => 'string', 'default' => 'month,week,list'], 'default_view' => ['type' => 'string', 'default' => 'month'],
                'program' => $str, 'categories' => $str, 'labels' => $str, 'eyebrow' => $str, 'title' => $str,
                'week_start' => $str, 'chips' => ['type' => 'number', 'default' => 2],
                'legend' => ['type' => 'boolean', 'default' => true], 'timezone_toggle' => ['type' => 'boolean', 'default' => true], 'new_tab' => ['type' => 'boolean', 'default' => true],
            ],
        ]);
    }
}
