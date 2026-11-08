<?php
defined('ABSPATH') || exit;

/**
 * Elementor integration: two drag-and-drop widgets ("AI Calendar" and "AI Event Cards"),
 * in a "AI Calendar" group in the widget panel. They render exactly what the shortcodes render.
 * Nothing loads unless Elementor is active.
 */
class WPAICal_Elementor {
    public static function init() {
        add_action('elementor/elements/categories_registered', function ($manager) {
            $manager->add_category('wpaical', ['title' => 'AI Calendar', 'icon' => 'eicon-calendar']);
        });
        add_action('elementor/widgets/register', function ($widgets) {
            require_once WPAICAL_DIR . 'includes/elementor/widgets.php';
            $widgets->register(new WPAICal_El_Upcoming());
            $widgets->register(new WPAICal_El_Calendar());
        });
    }

    /** Known program names for the picker (the part of event titles before " - "). */
    public static function program_options() {
        $out = [];
        foreach (WPAICal_Sync::cache()['events'] as $e) {
            $t = $e['title'];
            if (preg_match('/^(.+?)\s+[\x{2013}\x{2014}-]\s+.+$/u', $t, $m)) {
                $t = $m[1];
            }
            $out[$t] = $t;
        }
        foreach (WPAICal_Registry::rows() as $r) {
            $out[$r['title']] = $r['title'];
        }
        natcasesort($out);
        return $out;
    }

    public static function category_options() {
        $cats = WPAICal_Registry::all_categories();
        return $cats ? array_combine($cats, $cats) : [];
    }
}
