<?php
/**
 * Plugin Name: WP AI Calendar
 * Description: Uses a Google Calendar as the source of truth for your events: syncs it, serves a JSON API for websites and signage, and exposes an MCP server so AI agents can manage events.
 * Plugin URI:  https://github.com/thekusaldarshana/wp-ai-calendar
 * Version:     0.1.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      Kusal Darshana
 * Author URI:  https://kusaldarshana.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-calendar
 *
 * Copyright (C) 2026 Kusal Darshana
 */

defined('ABSPATH') || exit;

define('WPAICAL_VERSION', '0.1.3');
define('WPAICAL_DIR', plugin_dir_path(__FILE__));

require_once WPAICAL_DIR . 'includes/class-google-client.php';
require_once WPAICAL_DIR . 'includes/class-registry.php';
require_once WPAICAL_DIR . 'includes/class-sync.php';
require_once WPAICAL_DIR . 'includes/class-rest.php';
require_once WPAICAL_DIR . 'includes/class-mcp.php';
require_once WPAICAL_DIR . 'includes/class-admin.php';

add_action('plugins_loaded', function () {
    WPAICal_Sync::init();
    WPAICal_Rest::init();
    WPAICal_MCP::init();
    WPAICal_Admin::init();
});

// "Settings" shortcut on the Plugins screen.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(WPAICal_Admin::page_url()) . '">Settings</a>');
    return $links;
});

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled(WPAICal_Sync::CRON_HOOK)) {
        wp_schedule_event(time() + 60, 'wpaical_5min', WPAICal_Sync::CRON_HOOK);
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(WPAICal_Sync::CRON_HOOK);
});
