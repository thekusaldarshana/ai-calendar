<?php
defined('ABSPATH') || exit;

/**
 * GET /wp-json/wpaical/v1/events?start_date=&end_date=&per_page=
 * Returns upcoming events from the cache; never calls Google.
 */
class WPAICal_Rest {
    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route('wpaical/v1', '/events', [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'events'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'start_date' => ['type' => 'string', 'default' => ''],
                    'end_date'   => ['type' => 'string', 'default' => ''],
                    'per_page'   => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 500],
                ],
            ]);
        });
    }

    public static function events(WP_REST_Request $request) {
        $tz    = wp_timezone();
        $start = self::parse($request->get_param('start_date'), $tz) ?: new DateTimeImmutable('today', $tz);
        $end   = self::parse($request->get_param('end_date'), $tz) ?: $start->modify('+30 days');

        $cache = WPAICal_Sync::cache();
        $out   = [];
        foreach ($cache['events'] as $e) {
            // Keep anything that overlaps the window (so "live now" events still show).
            if ($e['end_date'] >= $start->format('Y-m-d H:i:s') && $e['start_date'] <= $end->format('Y-m-d H:i:s')) {
                $out[] = $e;
            }
        }
        $out = array_slice($out, 0, (int) $request->get_param('per_page'));

        $response = rest_ensure_response([
            'events'     => $out,
            'count'      => count($out),
            'fetched_at' => $cache['fetched_at'] ? gmdate('c', $cache['fetched_at']) : null,
            'stale'      => $cache['error'] !== '',
        ]);
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Cache-Control', 'public, max-age=60');
        return $response;
    }

    private static function parse($value, DateTimeZone $tz) {
        if (!$value) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, $tz);
        } catch (Exception $e) {
            return null;
        }
    }
}
