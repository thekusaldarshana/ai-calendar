<?php
// Runs when the plugin is deleted from the WordPress admin: remove everything it stored.
defined('WP_UNINSTALL_PLUGIN') || exit;

foreach (['wpaical_calendar_id', 'wpaical_service_account', 'wpaical_programs', 'wpaical_categories', 'wpaical_instructions', 'wpaical_cache', 'wpaical_widget_css', 'wpaical_cards', 'wpaical_category_colors'] as $wpaical_option) {
    delete_option($wpaical_option);
}
delete_transient('wpaical_gtoken');
wp_clear_scheduled_hook('wpaical_sync');
