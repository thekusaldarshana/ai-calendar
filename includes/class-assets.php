<?php
defined('ABSPATH') || exit;

/**
 * Registers the front-end styles and scripts once, so the shortcodes, the Elementor widgets and the
 * editor blocks all enqueue the same handles (page builders load registered handles in their previews).
 */
class WPAICal_Assets {
    public static function init() {
        add_action('init', [__CLASS__, 'register'], 20);
    }

    public static function url($file) {
        return plugins_url('assets/' . $file, WPAICAL_DIR . 'wp-ai-calendar.php');
    }

    public static function register() {
        wp_register_style('wpaical-widget', self::url('widget.css'), [], WPAICAL_VERSION);
        wp_register_style('wpaical-calendar', self::url('calendar.css'), [], WPAICAL_VERSION);
        wp_register_script('wpaical-widget', self::url('widget.js'), [], WPAICAL_VERSION, true);
        wp_register_script('wpaical-calendar', self::url('calendar.js'), [], WPAICAL_VERSION, true);
        wp_register_script('wpaical-elementor', self::url('elementor.js'), ['jquery'], WPAICAL_VERSION, true);

        $custom = wp_strip_all_tags((string) get_option(WPAICal_Widget::CSS_OPTION, ''));
        $rules  = '';
        foreach (WPAICal_Registry::category_colors() as $name => $hex) {
            if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
                $rules .= '.wpaical-cal [data-cat="' . sanitize_title($name) . '"]{--c:' . $hex . ';--cbg:rgba(' . hexdec($m[1]) . ',' . hexdec($m[2]) . ',' . hexdec($m[3]) . ',.14)}';
            }
        }
        wp_add_inline_style('wpaical-widget', $custom);
        wp_add_inline_style('wpaical-calendar', $rules . $custom);
    }
}
