<?php
defined('ABSPATH') || exit;

/**
 * [wpaical_upcoming] shortcode: upcoming events from the cached calendar as cards.
 *
 *   [wpaical_upcoming program="Meditate with a Monk" limit="10"]
 *
 * Server-rendered (works without JavaScript and is visible to search engines). A small script
 * only re-times the cards to the visitor's timezone and offers a site-time toggle.
 * Reads the cache; never calls Google.
 */
class WPAICal_Widget {
    const CSS_OPTION = 'wpaical_widget_css';

    public static function init() {
        add_shortcode('wpaical_upcoming', [__CLASS__, 'render']);
    }

    /** Events that start with / equal / contain one of the given titles, or all when none given. */
    private static function matches($title, array $needles, $mode) {
        if (!$needles) {
            return true;
        }
        $t = WPAICal_Registry::key($title);
        foreach ($needles as $n) {
            if ($mode === 'exact' ? $t === $n : ($mode === 'contains' ? strpos($t, $n) !== false : strpos($t, $n) === 0)) {
                return true;
            }
        }
        return false;
    }

    private static function image_for(array $e) {
        if (!empty($e['image'])) {
            return $e['image'];
        }
        static $by_url = [];
        if ($e['url'] !== '') {
            if (!array_key_exists($e['url'], $by_url)) {
                $id = url_to_postid($e['url']);
                $by_url[$e['url']] = $id ? (string) get_the_post_thumbnail_url($id, 'medium_large') : '';
            }
            return $by_url[$e['url']];
        }
        return '';
    }

    public static function render($atts) {
        $a = shortcode_atts([
            'program'  => '',                // title(s) to show, comma separated; empty = every event
            'match'    => 'prefix',          // prefix | exact | contains
            'category' => '',                // only this category (name)
            'limit'    => 10,
            'days'     => 365,
            'series'   => 'all',             // all | next (one card per recurring series)
            'heading'  => '',
            'lead'     => '',
            'button'   => 'Details',
            'empty'    => 'No upcoming events right now. Please check back soon.',
        ], $atts, 'wpaical_upcoming');

        $limit = max(1, min(50, (int) $a['limit']));
        $days  = max(1, min(730, (int) $a['days']));
        $mode  = in_array($a['match'], ['prefix', 'exact', 'contains'], true) ? $a['match'] : 'prefix';
        $needles = array_values(array_filter(array_map([WPAICal_Registry::class, 'key'], explode(',', (string) $a['program'])), 'strlen'));
        $cat   = strtolower(trim((string) $a['category']));

        $tz    = wp_timezone();
        $now   = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
        $until = (new DateTimeImmutable('now', $tz))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');

        $rows = [];
        $seen = [];
        foreach (WPAICal_Sync::cache()['events'] as $e) {
            if ($e['end_date'] < $now || $e['start_date'] > $until || !self::matches($e['title'], $needles, $mode)) {
                continue;
            }
            if ($cat !== '' && (empty($e['categories'][0]['name']) || strtolower($e['categories'][0]['name']) !== $cat)) {
                continue;
            }
            if ($a['series'] === 'next' && !empty($e['series_id'])) {
                if (isset($seen[$e['series_id']])) {
                    continue;
                }
                $seen[$e['series_id']] = true;
            }
            $rows[] = $e;
            if (count($rows) >= $limit) {
                break;
            }
        }

        wp_enqueue_style('wpaical-widget', plugins_url('assets/widget.css', WPAICAL_DIR . 'wp-ai-calendar.php'), [], WPAICAL_VERSION);
        $css = trim((string) get_option(self::CSS_OPTION, ''));
        if ($css !== '') {
            wp_add_inline_style('wpaical-widget', wp_strip_all_tags($css));
        }
        wp_enqueue_script('wpaical-widget', plugins_url('assets/widget.js', WPAICAL_DIR . 'wp-ai-calendar.php'), [], WPAICAL_VERSION, true);

        ob_start();
        ?>
        <section class="wpaical-up" data-tz="<?php echo esc_attr(wp_timezone_string()); ?>">
            <?php if ($a['heading'] !== '') : ?><h2 class="wpaical-up-title"><?php echo esc_html($a['heading']); ?></h2><?php endif; ?>
            <?php if ($a['lead'] !== '') : ?><p class="wpaical-up-lead"><?php echo esc_html($a['lead']); ?></p><?php endif; ?>
            <?php if (!$rows) : ?>
                <p class="wpaical-up-empty"><?php echo esc_html($a['empty']); ?></p>
            <?php else : ?>
                <div class="wpaical-up-grid">
                <?php foreach ($rows as $e) :
                    $start = new DateTimeImmutable($e['start_date'], $tz);
                    $end   = new DateTimeImmutable($e['end_date'], $tz);
                    $img   = self::image_for($e);
                    $cname = !empty($e['categories'][0]['name']) ? $e['categories'][0]['name'] : '';
                    $date  = $e['all_day'] && $start->format('Y-m-d') !== $end->format('Y-m-d')
                        ? wp_date('D, M j', $start->getTimestamp(), $tz) . ' – ' . wp_date('D, M j', $end->getTimestamp(), $tz)
                        : wp_date('D, M j', $start->getTimestamp(), $tz);
                    if ($e['all_day']) {
                        $time = 'All day';
                    } else {
                        $s    = wp_date('g:i', $start->getTimestamp(), $tz);
                        $ap_s = wp_date('A', $start->getTimestamp(), $tz);
                        $f    = wp_date('g:i A', $end->getTimestamp(), $tz);
                        $time = ($ap_s === wp_date('A', $end->getTimestamp(), $tz) ? $s : $s . ' ' . $ap_s) . '–' . $f . ' ' . $start->format('T');
                    }
                    ?>
                    <article class="wpaical-card"
                        data-start="<?php echo esc_attr($start->format('c')); ?>"
                        data-end="<?php echo esc_attr($end->format('c')); ?>"
                        data-allday="<?php echo $e['all_day'] ? '1' : ''; ?>">
                        <div class="wpaical-pic">
                            <?php if ($img !== '') : ?>
                                <img src="<?php echo esc_url($img); ?>" alt="" loading="lazy" decoding="async">
                            <?php else : ?>
                                <div class="wpaical-ph"><?php echo esc_html($cname !== '' ? $cname : $e['title']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="wpaical-body">
                            <?php if ($cname !== '') : ?><p class="wpaical-kick"><?php echo esc_html($cname); ?></p><?php endif; ?>
                            <h3><?php echo esc_html($e['title']); ?></h3>
                            <?php if ($e['location'] !== '') : ?><p class="wpaical-where"><?php echo esc_html($e['location']); ?></p><?php endif; ?>
                            <p class="wpaical-when"><span class="wpaical-date"><?php echo esc_html($date); ?></span> <span class="wpaical-time"><?php echo esc_html($time); ?></span></p>
                            <?php if ($e['url'] !== '') : ?>
                                <a class="wpaical-btn" href="<?php echo esc_url($e['url']); ?>"><?php echo esc_html($a['button']); ?></a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }
}
