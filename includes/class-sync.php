<?php
defined('ABSPATH') || exit;

/**
 * Pulls events from Google Calendar on a schedule and caches the normalised
 * result. The REST API only ever reads this cache, so a Google outage or a
 * burst of public requests never touches Google.
 */
class WPAICal_Sync {
    const CRON_HOOK    = 'wpaical_sync';
    const CACHE_OPTION = 'wpaical_cache';
    const DAYS_BEHIND  = 7;
    const DAYS_AHEAD   = 365;

    public static function init() {
        add_action(self::CRON_HOOK, [__CLASS__, 'run']);
        // Safety net in case activation scheduling was missed.
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'wpaical_5min', self::CRON_HOOK);
        }
    }

    /** @return true|WP_Error */
    public static function run() {
        $cache = self::cache();

        $calendar_id = get_option('wpaical_calendar_id', '');
        if ($calendar_id === '') {
            return self::fail($cache, new WP_Error('wpaical_no_calendar', 'No Google Calendar ID is set.'));
        }
        $client = WPAICal_Google_Client::from_settings();
        if (is_wp_error($client)) {
            return self::fail($cache, $client);
        }

        $tz   = wp_timezone();
        $from = (new DateTimeImmutable('today', $tz))->modify('-' . self::DAYS_BEHIND . ' days');
        $to   = (new DateTimeImmutable('today', $tz))->modify('+' . self::DAYS_AHEAD . ' days');

        $items = $client->list_events($calendar_id, $from->format(DATE_RFC3339), $to->format(DATE_RFC3339));
        if (is_wp_error($items)) {
            return self::fail($cache, $items);
        }

        $events = [];
        foreach ($items as $item) {
            $e = self::normalise($item, $tz);
            if ($e) {
                $events[] = $e;
            }
        }
        usort($events, function ($a, $b) {
            return strcmp($a['start_date'], $b['start_date']);
        });

        update_option(self::CACHE_OPTION, [
            'fetched_at' => time(),
            'error'      => '',
            'events'     => $events,
        ], false);
        return true;
    }

    public static function cache() {
        $c = get_option(self::CACHE_OPTION, []);
        return is_array($c) ? $c + ['fetched_at' => 0, 'error' => '', 'events' => []] : ['fetched_at' => 0, 'error' => '', 'events' => []];
    }

    /** Keep the last good events; just record why the refresh failed. */
    private static function fail(array $cache, WP_Error $err) {
        $cache['error'] = $err->get_error_message();
        update_option(self::CACHE_OPTION, $cache, false);
        return $err;
    }

    /**
     * Convert one Google event into the plugin's public event shape
     * (id, title, start_date, end_date, all_day, location, url, categories, ...).
     * Times are site-local "Y-m-d H:i:s" strings, as before.
     */
    public static function normalise(array $g, DateTimeZone $tz) {
        if (!empty($g['status']) && $g['status'] === 'cancelled') {
            return null;
        }
        // Private/confidential events must never reach the public feed.
        if (!empty($g['visibility']) && in_array($g['visibility'], ['private', 'confidential'], true)) {
            return null;
        }
        if (empty($g['start']) || empty($g['id'])) {
            return null;
        }
        try {
            return self::build_event($g, $tz);
        } catch (Exception $e) { // one malformed item must not abort a whole sync
            return null;
        }
    }

    private static function build_event(array $g, DateTimeZone $tz) {
        $all_day = isset($g['start']['date']);
        if ($all_day) {
            // Google's all-day end date is exclusive; make it the last second of the final day.
            $start = new DateTimeImmutable($g['start']['date'] . ' 00:00:00', $tz);
            $end   = (new DateTimeImmutable((isset($g['end']['date']) ? $g['end']['date'] : $g['start']['date']) . ' 00:00:00', $tz))->modify('-1 second');
        } else {
            $start = (new DateTimeImmutable($g['start']['dateTime']))->setTimezone($tz);
            $end   = isset($g['end']['dateTime'])
                ? (new DateTimeImmutable($g['end']['dateTime']))->setTimezone($tz)
                : $start->modify('+1 hour');
        }

        $title       = isset($g['summary']) ? sanitize_text_field($g['summary']) : '(untitled)';
        $description = isset($g['description']) ? wp_strip_all_tags($g['description']) : '';
        $resolved    = WPAICal_Registry::resolve($title, $description);
        $cat         = $resolved['category'];

        return [
            'id'         => $g['id'],
            'series_id'  => isset($g['recurringEventId']) ? $g['recurringEventId'] : null,
            'title'      => $title,
            'start_date' => $start->format('Y-m-d H:i:s'),
            'end_date'   => $end->format('Y-m-d H:i:s'),
            'all_day'    => $all_day,
            'location'   => isset($g['location']) ? sanitize_text_field($g['location']) : '',
            'url'        => $resolved['url'],
            'source'     => 'gcal',
            'categories' => $cat !== '' ? [['name' => $cat, 'slug' => sanitize_title($cat)]] : [],
            'tags'       => [],
        ];
    }
}
