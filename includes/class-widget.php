<?php
defined('ABSPATH') || exit;

/**
 * [wpaical_upcoming] shortcode: upcoming events from the cached calendar as cards, plus optional
 * hand-made "no date yet" cards (managed under AI Calendar -> Program cards).
 *
 *   [wpaical_upcoming program="Meditate with a Monk" limit="10"]
 *
 * Server-rendered (works without JavaScript and is visible to search engines). A small script
 * only re-times the cards to the visitor's timezone and offers an event-time toggle.
 * Reads the cache; never calls Google.
 */
class WPAICal_Widget {
    const CSS_OPTION   = 'wpaical_widget_css';
    const CARDS_OPTION = 'wpaical_cards';

    public static function init() {
        add_shortcode('wpaical_upcoming', [__CLASS__, 'render']);
    }

    /** Date-less cards: [['id','program','title','kicker','location','note','image','link'], ...] */
    public static function cards() {
        $c = get_option(self::CARDS_OPTION, []);
        return is_array($c) ? array_values($c) : [];
    }

    /** True when $title starts with / equals / contains one of the (already key()-normalised) needles, or no needles. */
    public static function matches($title, array $needles, $mode) {
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

    public static function needles($program) {
        return array_values(array_filter(array_map([WPAICal_Registry::class, 'key'], explode(',', (string) $program)), 'strlen'));
    }

    /** The picture an event's card will show: its own image, else its program page's featured image. */
    public static function image_for(array $e) {
        if (!empty($e['image'])) {
            return $e['image'];
        }
        static $by_url = [];
        if (!empty($e['url'])) {
            if (!array_key_exists($e['url'], $by_url)) {
                $id = url_to_postid($e['url']);
                $by_url[$e['url']] = $id ? (string) get_the_post_thumbnail_url($id, 'medium_large') : '';
            }
            return $by_url[$e['url']];
        }
        return '';
    }

    /** "Program - Venue" titles become kicker + heading; other titles use the category as the kicker. */
    private static function split_title($title, $category, $split) {
        if ($split === 'dash' && preg_match('/^(.+?)\s+[\x{2013}\x{2014}-]\s+(.+)$/u', $title, $m)) {
            return [$m[1], $m[2]];
        }
        return [$category, $title];
    }

    private static function when_text(array $e, DateTimeZone $tz) {
        $start = new DateTimeImmutable($e['start_date'], $tz);
        $end   = new DateTimeImmutable($e['end_date'], $tz);
        $date  = wp_date('D, M j', $start->getTimestamp(), $tz);
        if ($e['all_day']) {
            if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
                $date .= ' – ' . wp_date('D, M j', $end->getTimestamp(), $tz);
            }
            return $date . ' · All day';
        }
        $s    = wp_date('g:i', $start->getTimestamp(), $tz);
        $ap_s = wp_date('A', $start->getTimestamp(), $tz);
        $time = ($ap_s === wp_date('A', $end->getTimestamp(), $tz) ? $s : $s . ' ' . $ap_s) . '–' . wp_date('g:i A', $end->getTimestamp(), $tz) . ' ' . $start->format('T');
        return $date . ' · ' . $time;
    }

    private static function card_html($kick, $heading, $where, $pill, $when, $img, $link, $button, array $data = []) {
        ob_start();
        $attrs = '';
        foreach ($data as $k => $v) {
            $attrs .= ' data-' . esc_attr($k) . '="' . esc_attr($v) . '"';
        }
        ?>
        <article class="wpaical-card"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr() above ?>>
            <div class="wpaical-pic">
                <?php if ($img !== '') : ?>
                    <img src="<?php echo esc_url($img); ?>" alt="" loading="lazy" decoding="async">
                <?php else : ?>
                    <div class="wpaical-ph"><?php echo esc_html($kick !== '' ? $kick : $heading); ?></div>
                <?php endif; ?>
            </div>
            <div class="wpaical-body">
                <?php if ($kick !== '') : ?><p class="wpaical-kick"><?php echo esc_html($kick); ?></p><?php endif; ?>
                <h3><?php echo esc_html($heading); ?></h3>
                <?php if ($where !== '') : ?><p class="wpaical-where"><?php echo esc_html($where); ?></p><?php endif; ?>
                <p class="wpaical-when"><?php if ($pill !== '') : ?><span class="wpaical-next"><?php echo esc_html($pill); ?></span> <?php endif; ?><span class="wpaical-dt"><?php echo esc_html($when); ?></span></p>
                <?php if ($link !== '') : ?>
                    <a class="wpaical-btn" href="<?php echo esc_url($link); ?>"><?php echo esc_html($button); ?></a>
                <?php endif; ?>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    public static function render($atts) {
        $a = shortcode_atts([
            'program'  => '',                // title(s) to show, comma separated; empty = every event
            'match'    => 'prefix',          // prefix | exact | contains
            'category' => '',                // only this category (name)
            'limit'    => 10,
            'days'     => 365,
            'series'   => 'all',             // all | next (one card per recurring series)
            'split'    => 'dash',            // dash: "Program - Venue" titles become label + heading | none
            'heading'  => '',
            'lead'     => '',
            'button'   => 'Details',
            'empty'    => 'No upcoming events right now. Please check back soon.',
        ], $atts, 'wpaical_upcoming');

        $limit   = max(1, min(50, (int) $a['limit']));
        $days    = max(1, min(730, (int) $a['days']));
        $mode    = in_array($a['match'], ['prefix', 'exact', 'contains'], true) ? $a['match'] : 'prefix';
        $split   = $a['split'] === 'none' ? 'none' : 'dash';
        $needles = self::needles($a['program']);
        $cat     = strtolower(trim((string) $a['category']));

        $tz    = wp_timezone();
        $now   = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
        $until = (new DateTimeImmutable('now', $tz))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');

        $out    = [];
        $seen   = [];
        $titles = [];
        $count  = 0;
        foreach (WPAICal_Sync::cache()['events'] as $e) {
            if ($count >= $limit) {
                break;
            }
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
            $start = new DateTimeImmutable($e['start_date'], $tz);
            $cname = !empty($e['categories'][0]['name']) ? $e['categories'][0]['name'] : '';
            list($kick, $heading) = self::split_title($e['title'], $cname, $split);
            $tkey = WPAICal_Registry::key($e['title']);
            $out[] = self::card_html(
                $kick, $heading, $e['location'],
                isset($titles[$tkey]) ? '' : 'Next',
                self::when_text($e, $tz),
                self::image_for($e), isset($e['url']) ? $e['url'] : '', $a['button'],
                ['start' => $start->format('c'), 'end' => (new DateTimeImmutable($e['end_date'], $tz))->format('c'), 'allday' => $e['all_day'] ? '1' : '']
            );
            $titles[$tkey] = true;
            $count++;
        }

        // Cards without a date (announcements for programs whose dates are not set yet).
        if ($needles || $a['program'] === '') {
            foreach (self::cards() as $c) {
                if ($count >= $limit) {
                    break;
                }
                $prog = isset($c['program']) ? (string) $c['program'] : '';
                if ($needles ? !self::matches($prog, $needles, $mode) : $prog !== '') {
                    continue;
                }
                $out[] = self::card_html(
                    isset($c['kicker']) && $c['kicker'] !== '' ? $c['kicker'] : $prog,
                    isset($c['title']) ? $c['title'] : '',
                    isset($c['location']) ? $c['location'] : '',
                    'Coming soon',
                    isset($c['note']) && $c['note'] !== '' ? $c['note'] : 'Dates to be announced',
                    isset($c['image']) ? $c['image'] : '', isset($c['link']) ? $c['link'] : '', $a['button']
                );
                $count++;
            }
        }

        wp_enqueue_style('wpaical-widget');
        wp_enqueue_script('wpaical-widget');

        ob_start();
        ?>
        <section class="wpaical-up" data-tz="<?php echo esc_attr(wp_timezone_string()); ?>">
            <?php if ($a['heading'] !== '') : ?><h2 class="wpaical-up-title"><?php echo esc_html($a['heading']); ?></h2><?php endif; ?>
            <?php if ($a['lead'] !== '') : ?><p class="wpaical-up-lead"><?php echo esc_html($a['lead']); ?></p><?php endif; ?>
            <?php if (!$out) : ?>
                <p class="wpaical-up-empty"><?php echo esc_html($a['empty']); ?></p>
            <?php else : ?>
                <div class="wpaical-up-grid"><?php echo implode('', $out); // phpcs:ignore WordPress.Security.EscapeOutput -- each card is escaped in card_html() ?></div>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }
}
