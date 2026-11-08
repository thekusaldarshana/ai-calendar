<?php
defined('ABSPATH') || exit;

/**
 * MCP server (Model Context Protocol, "Streamable HTTP" transport, JSON responses only)
 * at POST /wp-json/wpaical/mcp/ , so any agent can manage events the same way it uses
 * the Elementor MCP: a URL plus an `Authorization: Basic ...` header holding a WordPress
 * Application Password. The user behind that password needs the Editor role or higher.
 *
 * Google Calendar stays the source of truth. Write tools change Google Calendar, then
 * refresh the local cache so the public events API updates immediately.
 */
class WPAICal_MCP {
    const PROTOCOL = '2025-03-26';
    const CAP      = 'edit_others_posts'; // Default: Editor and above. Filter with "wpaical_mcp_capability".
    const MAX_BATCH = 20;

    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route('wpaical', '/mcp', [
                'methods'             => ['GET', 'POST', 'DELETE'],
                'callback'            => [__CLASS__, 'handle'],
                'permission_callback' => function () {
                    return current_user_can(apply_filters('wpaical_mcp_capability', self::CAP))
                        ? true
                        : new WP_Error('wpaical_mcp_forbidden', 'Authentication required (Editor role or higher).', ['status' => is_user_logged_in() ? 403 : 401]);
                },
            ]);
        });
    }

    // ------------------------------------------------------------------ transport

    public static function handle(WP_REST_Request $request) {
        // We don't offer a server-initiated SSE stream or sessions.
        if ($request->get_method() !== 'POST') {
            return new WP_REST_Response(null, 405, ['Allow' => 'POST']);
        }
        $msg = json_decode($request->get_body(), true);
        if (!is_array($msg)) {
            return self::rpc_error(null, -32700, 'Parse error');
        }
        // JSON-RPC batch support.
        $is_batch  = array_keys($msg) === range(0, count($msg) - 1) && $msg !== [];
        if ($is_batch && count($msg) > self::MAX_BATCH) {
            return self::rpc_error(null, -32600, 'Batch too large (max ' . self::MAX_BATCH . ' requests).');
        }
        $responses = [];
        foreach ($is_batch ? $msg : [$msg] as $one) {
            if (!is_array($one)) {
                $responses[] = self::rpc_error(null, -32600, 'Invalid Request');
                continue;
            }
            try {
                $r = self::dispatch($one);
            } catch (Throwable $e) { // bad input must become a JSON-RPC error, never a PHP fatal
                $r = self::rpc_error(array_key_exists('id', $one) ? $one['id'] : null, -32603, 'Internal error: ' . $e->getMessage());
            }
            if ($r !== null) {
                $responses[] = $r;
            }
        }
        if (!$responses) {
            return new WP_REST_Response(null, 202); // only notifications were sent
        }
        return new WP_REST_Response($is_batch ? $responses : $responses[0], 200);
    }

    private static function dispatch(array $m) {
        $id     = array_key_exists('id', $m) ? $m['id'] : null;
        $method = isset($m['method']) ? $m['method'] : '';
        $params = isset($m['params']) && is_array($m['params']) ? $m['params'] : [];

        if ($id === null) {
            return null; // notification (e.g. notifications/initialized): no reply
        }
        switch ($method) {
            case 'initialize':
                return self::result($id, [
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'ai-calendar', 'version' => WPAICAL_VERSION],
                    'instructions'    => self::instructions(),
                ]);
            case 'ping':
                return self::result($id, new stdClass());
            case 'tools/list':
                return self::result($id, ['tools' => self::tool_definitions()]);
            case 'tools/call':
                return self::call_tool($id, $params);
            default:
                return self::rpc_error($id, -32601, 'Method not found: ' . $method);
        }
    }

    private static function result($id, $result) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function rpc_error($id, $code, $message) {
        $body = ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
        return $id === null && $code === -32700 ? new WP_REST_Response($body, 400) : $body;
    }

    /**
     * Run one tool from PHP (the admin dashboard uses this so it shares the MCP's validation and
     * Google calls). Only an allow-list is exposed. Returns the tool's array, or a WP_Error.
     */
    public static function execute($name, array $args = []) {
        if (!in_array($name, ['get_event', 'create_event', 'update_event'], true)) {
            return new WP_Error('wpaical_mcp_arg', 'Tool not available here: ' . $name);
        }
        return call_user_func([__CLASS__, 'tool_' . $name], $args);
    }

    private static function call_tool($id, array $params) {
        $name = isset($params['name']) ? $params['name'] : '';
        $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
        foreach ($args as $k => $v) { // every argument is a scalar string, except the recurrence list
            if ($k === 'recurrence' ? !is_array($v) : !is_scalar($v)) {
                return self::rpc_error($id, -32602, 'Invalid argument type for "' . $k . '".');
            }
        }
        $defs = self::tool_definitions();
        $known = array_column($defs, 'name');
        if (!in_array($name, $known, true)) {
            return self::rpc_error($id, -32602, 'Unknown tool: ' . $name);
        }
        $out = call_user_func([__CLASS__, 'tool_' . $name], $args);
        if (is_wp_error($out)) {
            return self::result($id, ['isError' => true, 'content' => [['type' => 'text', 'text' => $out->get_error_message()]]]);
        }
        return self::result($id, ['content' => [['type' => 'text', 'text' => wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]]);
    }

    // ------------------------------------------------------------------ tool catalogue

    private static function tool_definitions() {
        $str  = ['type' => 'string'];
        $obj  = function (array $props, array $required = []) {
            return ['type' => 'object', 'properties' => $props ? $props : new stdClass(), 'required' => $required];
        };
        $time = ['type' => 'string', 'description' => 'Local time in the site timezone (' . wp_timezone_string() . ') as "YYYY-MM-DD HH:MM", or "YYYY-MM-DD" for an all-day event.'];
        $fields = [
            'title'       => ['type' => 'string', 'description' => 'Event title. Must match a registry title to pick up its page and category automatically.'],
            'start'       => $time,
            'end'         => $time + ['description' => 'End time. For all-day events this is the LAST day (inclusive).'],
            'description' => ['type' => 'string', 'description' => 'Free text. Do not put page:/category: lines here; use the page and category fields.'],
            'location'    => $str,
            'page'        => ['type' => 'string', 'description' => 'Program page for this event: a path like "/special-events-dc/" or a full URL. Overrides the registry.'],
            'category'    => ['type' => 'string', 'description' => (WPAICal_Registry::categories() ? 'One of: ' . implode(', ', WPAICal_Registry::categories()) . '. ' : '') . 'Overrides the registry.'],
            'image'       => ['type' => 'string', 'description' => 'Optional image URL (https) shown on this event\'s card in the upcoming-events widget. Falls back to the program page\'s featured image.'],
            'recurrence'  => ['type' => 'array', 'items' => $str, 'description' => 'iCalendar lines, e.g. ["RRULE:FREQ=WEEKLY;BYDAY=SU"] or ["RRULE:FREQ=MONTHLY;BYDAY=1SA,3SA","EXDATE;TZID=<site timezone>:20261107T090000"]. Omit for a one-off event.'],
        ];
        $ro = ['readOnlyHint' => true];
        return [
            ['name' => 'list_events', 'description' => 'List dated events (recurring series expanded into occurrences) between two dates, straight from Google Calendar. Defaults to the next 30 days.',
             'inputSchema' => $obj(['start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD']]), 'annotations' => $ro],
            ['name' => 'list_series', 'description' => 'List recurring series definitions (title, time, recurrence rules), not expanded.', 'inputSchema' => $obj([]), 'annotations' => $ro],
            ['name' => 'get_event', 'description' => 'Get one event by id (a one-off id, a series id, or a single occurrence id).', 'inputSchema' => $obj(['event_id' => $str], ['event_id']), 'annotations' => $ro],
            ['name' => 'create_event', 'description' => 'Create an event or a recurring series in Google Calendar.', 'inputSchema' => $obj($fields, ['title', 'start', 'end'])],
            ['name' => 'update_event', 'description' => 'Change fields of an event. Pass a SERIES id to change every occurrence, or an OCCURRENCE id (from list_events) to change only that date. When changing time, pass both start and end.',
             'inputSchema' => $obj(['event_id' => $str] + $fields, ['event_id'])],
            ['name' => 'delete_event', 'description' => 'Delete an event. A SERIES id deletes the whole series; an OCCURRENCE id cancels just that date. Cannot be undone from here.',
             'inputSchema' => $obj(['event_id' => $str], ['event_id']), 'annotations' => ['destructiveHint' => true]],
            ['name' => 'cancel_occurrence', 'description' => 'Cancel one date of a recurring series by series id and date (YYYY-MM-DD), without needing the occurrence id.',
             'inputSchema' => $obj(['series_id' => $str, 'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD']], ['series_id', 'date']), 'annotations' => ['destructiveHint' => true]],
            ['name' => 'list_programs', 'description' => 'List the program registry: event title -> program page and category.', 'inputSchema' => $obj([]), 'annotations' => $ro],
            ['name' => 'set_program', 'description' => 'Add or replace a registry entry so every event with this title links to a page and gets a category. Requires an administrator.',
             'inputSchema' => $obj(['title' => $str, 'page' => $str, 'category' => $str], ['title', 'page'])],
            ['name' => 'delete_program', 'description' => 'Remove a registry entry by title. Requires an administrator.', 'inputSchema' => $obj(['title' => $str], ['title']), 'annotations' => ['destructiveHint' => true]],
            ['name' => 'sync_now', 'description' => 'Refresh the public events cache from Google Calendar immediately (write tools already do this).', 'inputSchema' => $obj([])],
            ['name' => 'status', 'description' => 'Show configuration and last-sync status.', 'inputSchema' => $obj([]), 'annotations' => $ro],
        ];
    }

    // ------------------------------------------------------------------ helpers

    private static function ctx() {
        $cal = get_option('wpaical_calendar_id', '');
        if ($cal === '') {
            return new WP_Error('wpaical_mcp_cfg', 'No Google Calendar ID is configured (Settings -> AI Calendar).');
        }
        $client = WPAICal_Google_Client::from_settings();
        return is_wp_error($client) ? $client : [$client, $cal];
    }

    /** Site timezone as an IANA name (Google needs one). Empty if WordPress is set to a UTC offset. */
    private static function tzname() {
        $name = wp_timezone_string();
        return ($name !== '' && !preg_match('/^[+-]/', $name)) ? $name : '';
    }

    /** Server instructions shown to the agent: the site's own text (Settings) or a generic default. */
    private static function instructions() {
        $custom = trim((string) get_option('wpaical_instructions', ''));
        $base   = 'Manage events stored in Google Calendar (the source of truth); the site and any displays read a cached copy. Times are in the site timezone. Use list_events/list_series to look and create_event/update_event/delete_event to change. Event page links and categories come from the program registry (list_programs/set_program) or "page:"/"category:" lines in an event description.';
        return $custom !== '' ? $custom . "\n\n" . $base : $base;
    }

    private static function present(array $g) {
        $e = WPAICal_Sync::normalise($g, wp_timezone());
        if ($e === null) {
            return ['id' => $g['id'], 'status' => 'cancelled'];
        }
        $e['description'] = WPAICal_Registry::strip_directives(isset($g['description']) ? $g['description'] : '');
        if (!empty($g['recurrence'])) {
            $e['recurrence'] = $g['recurrence'];
        }
        return $e;
    }

    /** Parse "YYYY-MM-DD" or "YYYY-MM-DD HH:MM" into [date-or-datetime, is_all_day]. */
    private static function parse_time($value, $is_end = false) {
        $value = trim((string) $value);
        $tz    = wp_timezone();
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $dm) && checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1])) {
            return [$value, true];
        }
        $d      = DateTimeImmutable::createFromFormat('Y-m-d H:i', str_replace('T', ' ', substr($value, 0, 16)), $tz);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$d || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return new WP_Error('wpaical_mcp_time', 'Bad time "' . $value . '". Use "YYYY-MM-DD HH:MM" or "YYYY-MM-DD".');
        }
        return [$d->format('Y-m-d\TH:i:s'), false];
    }

    /** Build Google start/end objects from our start/end strings. */
    private static function time_fields($start, $end) {
        $s = self::parse_time($start);
        $e = self::parse_time($end, true);
        if (is_wp_error($s)) {
            return $s;
        }
        if (is_wp_error($e)) {
            return $e;
        }
        if ($s[1] !== $e[1]) {
            return new WP_Error('wpaical_mcp_time', 'start and end must both be all-day dates or both be date+time.');
        }
        if ($s[1]) { // all-day: Google's end date is exclusive
            $end_excl = (new DateTimeImmutable($e[0], wp_timezone()))->modify('+1 day')->format('Y-m-d');
            return ['start' => ['date' => $s[0]], 'end' => ['date' => $end_excl]];
        }
        if ($e[0] <= $s[0]) {
            return new WP_Error('wpaical_mcp_time', 'end must be after start.');
        }
        $tz = self::tzname();
        if ($tz === '') {
            return new WP_Error('wpaical_mcp_tz', 'Set the WordPress timezone to a city (Settings -> General), not a UTC offset.');
        }
        return ['start' => ['dateTime' => $s[0], 'timeZone' => $tz], 'end' => ['dateTime' => $e[0], 'timeZone' => $tz]];
    }

    private static function check_recurrence($lines) {
        foreach ((array) $lines as $l) {
            if (!is_string($l) || !preg_match('/^(RRULE|EXRULE|RDATE|EXDATE)[:;][^\r\n]+$/i', trim($l))) {
                return new WP_Error('wpaical_mcp_rrule', 'Each recurrence line must start with RRULE:, RDATE:, EXDATE:, got: ' . (is_string($l) ? $l : gettype($l)));
            }
        }
        return array_values(array_map('trim', (array) $lines));
    }

    private static function is_date($v) {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function refresh() {
        WPAICal_Sync::run(); // best effort; failure is recorded in the cache's error field
    }

    // ------------------------------------------------------------------ tools

    private static function tool_status($a) {
        $cache = WPAICal_Sync::cache();
        return [
            'calendar_id_set'  => get_option('wpaical_calendar_id', '') !== '',
            'credentials_set'  => !is_wp_error(WPAICal_Google_Client::from_settings()),
            'last_sync'        => $cache['fetched_at'] ? gmdate('c', $cache['fetched_at']) : null,
            'last_error'       => $cache['error'],
            'cached_events'    => count($cache['events']),
            'public_endpoint'  => rest_url('wpaical/v1/events'),
            'timezone'         => wp_timezone_string(),
        ];
    }

    private static function tool_sync_now($a) {
        $r = WPAICal_Sync::run();
        return is_wp_error($r) ? $r : ['synced' => true, 'cached_events' => count(WPAICal_Sync::cache()['events'])];
    }

    private static function tool_list_events($a) {
        $c = self::ctx();
        if (is_wp_error($c)) {
            return $c;
        }
        $tz = wp_timezone();
        foreach (['start_date', 'end_date'] as $k) {
            if (!empty($a[$k]) && !self::is_date((string) $a[$k])) {
                return new WP_Error('wpaical_mcp_arg', $k . ' must be a real date, YYYY-MM-DD.');
            }
        }
        $start = new DateTimeImmutable(!empty($a['start_date']) ? $a['start_date'] : 'today', $tz);
        $end   = !empty($a['end_date']) ? (new DateTimeImmutable($a['end_date'], $tz))->modify('+1 day') : $start->modify('+30 days');
        $items = $c[0]->list_events($c[1], $start->format(DATE_RFC3339), $end->format(DATE_RFC3339));
        if (is_wp_error($items)) {
            return $items;
        }
        $out = [];
        foreach ($items as $g) {
            $e = WPAICal_Sync::normalise($g, $tz);
            if ($e) {
                unset($e['source'], $e['tags']);
                $out[] = $e;
            }
        }
        return ['count' => count($out), 'events' => $out];
    }

    private static function tool_list_series($a) {
        $c = self::ctx();
        if (is_wp_error($c)) {
            return $c;
        }
        $items = $c[0]->list_series($c[1]);
        return is_wp_error($items) ? $items : ['count' => count($items), 'series' => array_map([__CLASS__, 'present'], $items)];
    }

    private static function tool_get_event($a) {
        $c = self::ctx();
        if (is_wp_error($c)) {
            return $c;
        }
        $id = (string) ($a['event_id'] ?? '');
        if ($id === '') {
            return new WP_Error('wpaical_mcp_arg', 'event_id is required.');
        }
        $g = $c[0]->get_event($c[1], $id);
        return is_wp_error($g) ? $g : self::present($g);
    }

    /** Compose the description Google stores: user text + our page:/category: directive lines. */
    private static function compose_description($text, $page, $category, $image = '') {
        $page     = is_string($page) ? trim(preg_replace('/[\r\n]+/', ' ', $page)) : '';
        $category = is_string($category) ? trim(preg_replace('/[\r\n]+/', ' ', $category)) : '';
        $image    = is_string($image) && preg_match('#^https?://\S+$#i', trim($image)) ? trim($image) : '';
        $lines = [];
        if ($page !== '' && $page !== null) {
            $lines[] = 'page: ' . $page;
        }
        if ($category !== '' && $category !== null) {
            $lines[] = 'category: ' . $category;
        }
        if ($image !== '') {
            $lines[] = 'image: ' . $image;
        }
        return trim($text . "\n" . implode("\n", $lines));
    }

    private static function tool_create_event($a) {
        $c = self::ctx();
        if (is_wp_error($c)) {
            return $c;
        }
        if (empty($a['title'])) {
            return new WP_Error('wpaical_mcp_arg', 'title is required.');
        }
        $times = self::time_fields($a['start'] ?? '', $a['end'] ?? '');
        if (is_wp_error($times)) {
            return $times;
        }
        $body = ['summary' => (string) $a['title']] + $times;
        if (!empty($a['recurrence'])) {
            $rec = self::check_recurrence($a['recurrence']);
            if (is_wp_error($rec)) {
                return $rec;
            }
            $body['recurrence'] = $rec;
            if (isset($body['start']['date'])) {
                // Recurring all-day events: Google wants no timeZone on dates; fine as is.
            }
        }
        if (!empty($a['location'])) {
            $body['location'] = (string) $a['location'];
        }
        $desc = self::compose_description((string) ($a['description'] ?? ''), $a['page'] ?? '', $a['category'] ?? '', $a['image'] ?? '');
        if ($desc !== '') {
            $body['description'] = $desc;
        }
        $g = $c[0]->insert_event($c[1], $body);
        if (is_wp_error($g)) {
            return $g;
        }
        self::refresh();
        return ['created' => self::present($g)];
    }

    private static function tool_update_event($a) {
        $c = self::ctx();
        if (is_wp_error($c)) {
            return $c;
        }
        $id = (string) ($a['event_id'] ?? '');
        if ($id === '') {
            return new WP_Error('wpaical_mcp_arg', 'event_id is required.');
        }
        $patch = [];
        if (isset($a['title'])) {
            $patch['summary'] = (string) $a['title'];
        }
        if (isset($a['location'])) {
            $patch['location'] = (string) $a['location'];
        }
        if (isset($a['start']) || isset($a['end'])) {
            $times = self::time_fields($a['start'] ?? '', $a['end'] ?? '');
            if (is_wp_error($times)) {
                return $times;
            }
            $patch += $times;
        }
        if (isset($a['recurrence'])) {
            $rec = self::check_recurrence($a['recurrence']);
            if (is_wp_error($rec)) {
                return $rec;
            }
            $patch['recurrence'] = $rec;
        }
        if (isset($a['description']) || isset($a['page']) || isset($a['category']) || isset($a['image'])) {
            $existing = $c[0]->get_event($c[1], $id);
            if (is_wp_error($existing)) {
                return $existing;
            }
            $old  = isset($existing['description']) ? $existing['description'] : '';
            $text = isset($a['description']) ? (string) $a['description'] : WPAICal_Registry::strip_directives($old);
            $page = array_key_exists('page', $a) ? (string) $a['page'] : WPAICal_Registry::directive($old, 'page');
            $cat  = array_key_exists('category', $a) ? (string) $a['category'] : WPAICal_Registry::directive($old, 'category');
            $img  = array_key_exists('image', $a) ? (string) $a['image'] : WPAICal_Registry::directive($old, 'image');
            $patch['description'] = self::compose_description($text, $page, $cat, $img);
        }
        if (!$patch) {
            return new WP_Error('wpaical_mcp_arg', 'Nothing to update: pass at least one field besides event_id.');
        }
        $g = $c[0]->patch_event($c[1], $id, $patch);
        if (is_wp_error($g)) {
            return $g;
        }
        self::refresh();
        return ['updated' => self::present($g)];
    }

    private static function tool_delete_event($a) {
        $c = self::ctx();
        if (is_wp_error($c)) {
            return $c;
        }
        $id = (string) ($a['event_id'] ?? '');
        if ($id === '') {
            return new WP_Error('wpaical_mcp_arg', 'event_id is required.');
        }
        $r  = $c[0]->delete_event($c[1], $id);
        if (is_wp_error($r)) {
            return $r;
        }
        self::refresh();
        return ['deleted' => $id];
    }

    private static function tool_cancel_occurrence($a) {
        $c = self::ctx();
        if (is_wp_error($c)) {
            return $c;
        }
        $date = (string) ($a['date'] ?? '');
        if (!self::is_date($date)) {
            return new WP_Error('wpaical_mcp_arg', 'date must be a real date, YYYY-MM-DD.');
        }
        $tz  = wp_timezone();
        $day = new DateTimeImmutable($date, $tz);
        $inst = $c[0]->list_instances($c[1], (string) ($a['series_id'] ?? ''), $day->format(DATE_RFC3339), $day->modify('+1 day')->format(DATE_RFC3339));
        if (is_wp_error($inst)) {
            return $inst;
        }
        // The window returns anything overlapping the day; keep only occurrences that START on that day.
        $inst = array_values(array_filter($inst, function ($g) use ($tz, $date) {
            if (!empty($g['status']) && $g['status'] === 'cancelled') {
                return false;
            }
            $s = isset($g['start']['date']) ? $g['start']['date'] : (isset($g['start']['dateTime']) ? (new DateTimeImmutable($g['start']['dateTime']))->setTimezone($tz)->format('Y-m-d') : '');
            return $s === $date;
        }));
        if (!$inst) {
            return new WP_Error('wpaical_mcp_none', 'That series has no occurrence on ' . $date . '.');
        }
        $cancelled = [];
        foreach ($inst as $g) {
            $r = $c[0]->delete_event($c[1], $g['id']);
            if (is_wp_error($r)) {
                return $r;
            }
            $cancelled[] = $g['id'];
        }
        self::refresh();
        return ['cancelled_occurrences' => $cancelled, 'date' => $date];
    }

    private static function tool_list_programs($a) {
        return ['programs' => WPAICal_Registry::rows(), 'categories' => WPAICal_Registry::categories()];
    }

    private static function tool_set_program($a) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('wpaical_mcp_forbidden', 'Changing the program registry requires an administrator.');
        }
        $title = trim((string) ($a['title'] ?? ''));
        $page  = trim((string) ($a['page'] ?? ''));
        $cat   = trim((string) ($a['category'] ?? ''));
        if ($title === '' || $page === '') {
            return new WP_Error('wpaical_mcp_arg', 'title and page are required.');
        }
        $allowed = WPAICal_Registry::categories();
        if ($cat !== '' && $allowed && !in_array($cat, $allowed, true)) {
            return new WP_Error('wpaical_mcp_arg', 'category must be one of: ' . implode(', ', $allowed));
        }
        WPAICal_Registry::set($title, $page, $cat);
        self::refresh();
        return ['saved' => ['title' => $title, 'page' => $page, 'category' => $cat]];
    }

    private static function tool_delete_program($a) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('wpaical_mcp_forbidden', 'Changing the program registry requires an administrator.');
        }
        $removed = WPAICal_Registry::remove((string) ($a['title'] ?? ''));
        self::refresh();
        return ['removed' => $removed];
    }
}
