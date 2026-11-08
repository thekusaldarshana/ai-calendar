<?php
defined('ABSPATH') || exit;

/**
 * [wpaical_calendar] shortcode: a month / week / list calendar of the cached events.
 *
 *   [wpaical_calendar]
 *   [wpaical_calendar views="month,list" default_view="list" program="Yoga" legend="no"]
 *
 * The page ships a server-rendered "upcoming" list (works without JavaScript, readable by search
 * engines); the script replaces it with the interactive calendar. Colours, fonts and radii are CSS
 * variables, and category colours are set in AI Calendar -> Settings.
 */
class WPAICal_Calendar {
    public static function init() {
        add_shortcode('wpaical_calendar', [__CLASS__, 'render']);
    }

    private static function yes($v) {
        return !in_array(strtolower(trim((string) $v)), ['no', 'false', '0', 'off', ''], true);
    }

    public static function render($atts) {
        $a = shortcode_atts([
            'views'           => 'month,week,list', // any of month, week, list
            'default_view'    => 'month',
            'program'         => '',                // only events whose title starts with this (comma separated)
            'match'           => 'prefix',
            'categories'      => '',                // only these categories (comma separated names)
            'legend'          => 'yes',             // category filter chips
            'timezone_toggle' => 'yes',             // "Your time / Event time" for visitors in another timezone
            'week_start'      => '',                // 0 = Sunday ... 6; empty = the WordPress setting
            'chips'           => 2,                 // events shown per day cell before "+N more"
            'eyebrow'         => '',
            'title'           => '',                // empty = the month name
        ], $atts, 'wpaical_calendar');

        $views = array_values(array_intersect(array_map('trim', explode(',', strtolower($a['views']))), ['month', 'week', 'list']));
        if (!$views) {
            $views = ['month'];
        }
        $default = in_array($a['default_view'], $views, true) ? $a['default_view'] : $views[0];
        $mode    = in_array($a['match'], ['prefix', 'exact', 'contains'], true) ? $a['match'] : 'prefix';
        $only    = array_values(array_filter(array_map('trim', explode(',', (string) $a['categories'])), 'strlen'));
        $ws      = $a['week_start'] === '' ? (int) get_option('start_of_week', 0) : (int) $a['week_start'];

        $cats = [];
        foreach (WPAICal_Registry::category_colors() as $name => $hex) {
            if (!$only || in_array(strtolower($name), array_map('strtolower', $only), true)) {
                $cats[] = ['name' => $name, 'color' => $hex];
            }
        }
        $config = [
            'rest'     => esc_url_raw(rest_url('wpaical/v1/events')),
            'tz'       => wp_timezone_string(),
            'views'    => $views,
            'view'     => $default,
            'program'  => (string) $a['program'],
            'match'    => $mode,
            'cats'     => $cats,
            'only'     => $only,
            'legend'   => self::yes($a['legend']),
            'tzToggle' => self::yes($a['timezone_toggle']),
            'weekStart' => max(0, min(6, $ws)),
            'chips'    => max(1, min(6, (int) $a['chips'])),
            'eyebrow'  => (string) $a['eyebrow'],
            'title'    => (string) $a['title'],
        ];

        $main = WPAICAL_DIR . 'wp-ai-calendar.php';
        wp_enqueue_style('wpaical-calendar', plugins_url('assets/calendar.css', $main), [], WPAICAL_VERSION);
        $css = trim((string) get_option(WPAICal_Widget::CSS_OPTION, ''));
        if ($css !== '') {
            wp_add_inline_style('wpaical-calendar', wp_strip_all_tags($css));
        }
        wp_enqueue_script('wpaical-calendar', plugins_url('assets/calendar.js', $main), [], WPAICAL_VERSION, true);

        // Fallback list (no JavaScript / search engines): the next events, replaced by the calendar.
        $tz      = wp_timezone();
        $now     = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
        $needles = WPAICal_Widget::needles($a['program']);
        $items   = [];
        foreach (WPAICal_Sync::cache()['events'] as $e) {
            if ($e['end_date'] < $now || !WPAICal_Widget::matches($e['title'], $needles, $mode)) {
                continue;
            }
            $cn = !empty($e['categories'][0]['name']) ? $e['categories'][0]['name'] : '';
            if ($only && !in_array(strtolower($cn), array_map('strtolower', $only), true)) {
                continue;
            }
            $items[] = $e;
            if (count($items) >= 12) {
                break;
            }
        }

        ob_start();
        ?>
        <section class="wpaical-cal" data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <div class="wpaical-cal-fallback">
                <?php if ($a['title'] !== '') : ?><h2><?php echo esc_html($a['title']); ?></h2><?php endif; ?>
                <ul>
                    <?php foreach ($items as $e) :
                        $s = new DateTimeImmutable($e['start_date'], $tz); ?>
                        <li><?php echo esc_html($e['all_day'] ? wp_date('D, M j', $s->getTimestamp(), $tz) : wp_date('D, M j, g:i A', $s->getTimestamp(), $tz)); ?> &ndash;
                            <?php echo !empty($e['url']) ? '<a href="' . esc_url($e['url']) . '">' . esc_html($e['title']) . '</a>' : esc_html($e['title']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }
}
