<?php
defined('ABSPATH') || exit;

/** Top-level "AI Calendar" admin page: status dashboard, settings, program registry, sync button. */
class WPAICal_Admin {
    const CAP  = 'manage_options';
    const SLUG = 'wp-ai-calendar';

    public static function page_url() {
        return admin_url('admin.php?page=' . self::SLUG);
    }

    public static function init() {
        add_action('admin_menu', function () {
            add_menu_page('WP AI Calendar', 'AI Calendar', self::CAP, self::SLUG, [__CLASS__, 'page'], 'dashicons-calendar-alt', 58);
        });
        add_action('admin_enqueue_scripts', function ($hook) {
            if ($hook !== 'toplevel_page_' . self::SLUG) {
                return;
            }
            $main = WPAICAL_DIR . 'wp-ai-calendar.php';
            wp_enqueue_style('wpaical-admin', plugins_url('assets/admin.css', $main), [], WPAICAL_VERSION);
            wp_enqueue_script('wpaical-admin', plugins_url('assets/admin.js', $main), [], WPAICAL_VERSION, true);
        });
        add_action('admin_post_wpaical_save', [__CLASS__, 'save']);
        add_action('admin_post_wpaical_sync', [__CLASS__, 'sync_now']);
        add_action('admin_post_wpaical_quick_add', [__CLASS__, 'quick_add']);
        add_action('admin_post_wpaical_quick_edit', [__CLASS__, 'quick_edit']);
    }

    private static function guard($action) {
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.', 403);
        }
        check_admin_referer($action);
    }

    private static function back($notice, $ok = true) {
        wp_safe_redirect(add_query_arg(
            ['page' => 'wp-ai-calendar', 'wpaical_notice' => rawurlencode($notice), 'wpaical_ok' => $ok ? 1 : 0],
            admin_url('admin.php')
        ));
        exit;
    }

    public static function save() {
        self::guard('wpaical_save');

        update_option('wpaical_calendar_id', sanitize_text_field(wp_unslash($_POST['calendar_id'] ?? '')));
        $cats = array_values(array_filter(array_map('trim', explode(',', sanitize_text_field(wp_unslash($_POST['categories'] ?? '')))), 'strlen'));
        self::save_programs($cats);
        update_option(WPAICal_Registry::CATEGORIES_OPTION, implode(', ', $cats));
        update_option('wpaical_instructions', sanitize_textarea_field(wp_unslash($_POST['instructions'] ?? '')));

        // Only touch the credentials if a new JSON was pasted (the box is never pre-filled).
        $json = trim(wp_unslash($_POST['service_account'] ?? ''));
        if (!empty($_FILES['service_account_file']['tmp_name']) && is_uploaded_file($_FILES['service_account_file']['tmp_name'])) {
            $json = trim((string) file_get_contents($_FILES['service_account_file']['tmp_name']));
        }
        if ($json !== '') {
            $acct = json_decode($json, true);
            if (!is_array($acct) || empty($acct['client_email']) || empty($acct['private_key'])) {
                self::back('That is not a valid service-account JSON file.', false);
            }
            update_option('wpaical_service_account', $json, false);
            delete_transient('wpaical_gtoken');
        }
        self::back('Settings saved.');
    }

    /** Rebuild the program registry from the table fields. New categories are added to $cats (by reference). */
    private static function save_programs(array &$cats) {
        $titles = isset($_POST['program_title']) ? (array) wp_unslash($_POST['program_title']) : [];
        $pages  = isset($_POST['program_page']) ? (array) wp_unslash($_POST['program_page']) : [];
        $picks  = isset($_POST['program_category']) ? (array) wp_unslash($_POST['program_category']) : [];
        $news   = isset($_POST['program_category_new']) ? (array) wp_unslash($_POST['program_category_new']) : [];
        $restrict = !empty($cats); // an empty list means categories are free-form: don't start restricting
        $seen  = [];
        $lines = [];
        foreach ($titles as $i => $title) {
            $title = trim(str_replace('|', ' ', sanitize_text_field($title)));
            if ($title === '' || isset($seen[WPAICal_Registry::key($title)])) {
                continue;
            }
            $seen[WPAICal_Registry::key($title)] = true;
            $page = trim(str_replace('|', '', sanitize_text_field($pages[$i] ?? '')));
            $pick = (string) ($picks[$i] ?? '');
            $cat  = $pick === '__new__' ? sanitize_text_field($news[$i] ?? '') : sanitize_text_field($pick);
            $cat  = trim(str_replace(['|', ','], '', $cat));
            if ($cat !== '' && $restrict && !in_array(strtolower($cat), array_map('strtolower', $cats), true)) {
                $cats[] = $cat;
            }
            $lines[] = $title . ' | ' . $page . ' | ' . $cat;
        }
        update_option(WPAICal_Registry::OPTION, implode("\n", $lines));
    }

    /** Published pages as [path => title] for the page picker. */
    private static function page_choices() {
        $out = [];
        foreach (get_pages(['post_status' => 'publish', 'number' => 500, 'sort_column' => 'post_title']) as $pg) {
            $out[wp_make_link_relative(get_permalink($pg))] = $pg->post_title;
        }
        return $out;
    }

    /** "Open Google Calendar" link: opens the calendar (and offers to add it if the user hasn't yet). */
    private static function gcal_open_url() {
        $cal = (string) get_option('wpaical_calendar_id', '');
        return $cal === '' ? 'https://calendar.google.com/calendar/' : 'https://calendar.google.com/calendar/u/0/r?cid=' . rtrim(base64_encode($cal), '=');
    }

    /** Deep link to Google's own editor for one event or occurrence id. */
    private static function gcal_edit_url($event_id) {
        $cal = (string) get_option('wpaical_calendar_id', '');
        return $cal === '' ? '' : 'https://calendar.google.com/calendar/u/0/r/eventedit/' . rtrim(base64_encode($event_id . ' ' . $cal), '=');
    }

    /** All category names to offer: the configured list, the programs' categories, and those on cached events. */
    private static function category_choices() {
        $cats = WPAICal_Registry::categories();
        $extra = [];
        foreach (WPAICal_Registry::rows() as $r) {
            $extra[] = $r['category'];
        }
        foreach (WPAICal_Sync::cache()['events'] as $e) {
            if (!empty($e['categories'][0]['name'])) {
                $extra[] = $e['categories'][0]['name'];
            }
        }
        foreach ($extra as $c) {
            if ($c !== '' && !in_array(strtolower($c), array_map('strtolower', $cats), true)) {
                $cats[] = $c;
            }
        }
        return $cats;
    }

    /** Category from a select + "new" text field. Returns null when nothing was chosen. Registers new names if a list is in use. */
    private static function posted_category($pick_key, $new_key) {
        $pick = sanitize_text_field(wp_unslash($_POST[$pick_key] ?? ''));
        if ($pick === '__new__') {
            $name = trim(str_replace(['|', ','], '', sanitize_text_field(wp_unslash($_POST[$new_key] ?? ''))));
            if ($name === '') {
                return null;
            }
            $list = WPAICal_Registry::categories();
            if ($list && !in_array(strtolower($name), array_map('strtolower', $list), true)) {
                $list[] = $name;
                update_option(WPAICal_Registry::CATEGORIES_OPTION, implode(', ', $list));
            }
            return $name;
        }
        return $pick === '' ? null : $pick;
    }

    private static function valid_date($v) {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : '';
    }

    private static function valid_time($v) {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : '';
    }

    public static function quick_add() {
        self::guard('wpaical_quick_add');
        $title = trim(sanitize_text_field(wp_unslash($_POST['title'] ?? '')));
        $date  = self::valid_date(sanitize_text_field(wp_unslash($_POST['date'] ?? '')));
        $all   = !empty($_POST['all_day']);
        $st    = self::valid_time(sanitize_text_field(wp_unslash($_POST['start_time'] ?? '')));
        $en    = self::valid_time(sanitize_text_field(wp_unslash($_POST['end_time'] ?? '')));
        if ($title === '' || $date === '') {
            self::back('Quick add needs a title and a date.', false);
        }
        if (!$all && ($st === '' || $en === '')) {
            self::back('Quick add needs a start and end time (or tick All day).', false);
        }
        $args = ['title' => $title, 'start' => $all ? $date : "$date $st", 'end' => $all ? $date : "$date $en"];
        $loc  = trim(sanitize_text_field(wp_unslash($_POST['location'] ?? '')));
        if ($loc !== '') {
            $args['location'] = $loc;
        }
        $cat = self::posted_category('category', 'category_new');
        if ($cat !== null) {
            $args['category'] = $cat;
        }
        $r = WPAICal_MCP::execute('create_event', $args);
        if (is_wp_error($r)) {
            self::back($r->get_error_message(), false);
        }
        $known = WPAICal_Registry::all();
        $tip   = isset($known[WPAICal_Registry::key($title)]) ? '' : ' Tip: add "' . $title . '" under Programs to link it to a page.';
        self::back('Added "' . $title . '" on ' . $date . '.' . $tip);
    }

    public static function quick_edit() {
        self::guard('wpaical_quick_edit');
        $id = sanitize_text_field(wp_unslash($_POST['event_id'] ?? ''));
        $ev = null;
        foreach (WPAICal_Sync::cache()['events'] as $e) {
            if ($e['id'] === $id) {
                $ev = $e;
                break;
            }
        }
        if (!$ev) {
            self::back('That event is no longer in the cache. Sync and try again.', false);
        }
        $series = !empty($ev['series_id']) && ($_POST['scope'] ?? '') === 'series';
        $args   = ['event_id' => $series ? $ev['series_id'] : $id];

        $cat = self::posted_category('category', 'category_new');
        $cur = !empty($ev['categories'][0]['name']) ? $ev['categories'][0]['name'] : '';
        if ($cat !== null && strcasecmp($cat, $cur) !== 0) {
            $args['category'] = $cat;
        }

        if (empty($ev['all_day'])) { // all-day events: category only here; change dates in Google Calendar
            $date = self::valid_date(sanitize_text_field(wp_unslash($_POST['date'] ?? '')));
            $st   = self::valid_time(sanitize_text_field(wp_unslash($_POST['start_time'] ?? '')));
            $en   = self::valid_time(sanitize_text_field(wp_unslash($_POST['end_time'] ?? '')));
            if ($date === '' || $st === '' || $en === '') {
                self::back('Enter a valid date, start time and end time.', false);
            }
            $o_date = substr($ev['start_date'], 0, 10);
            $o_st   = substr($ev['start_date'], 11, 5);
            $o_en   = substr($ev['end_date'], 11, 5);
            $span   = (int) round((strtotime(substr($ev['end_date'], 0, 10)) - strtotime($o_date)) / DAY_IN_SECONDS);
            if ($series) {
                if ($st !== $o_st || $en !== $o_en) {
                    $s = WPAICal_MCP::execute('get_event', ['event_id' => $ev['series_id']]);
                    if (is_wp_error($s)) {
                        self::back($s->get_error_message(), false);
                    }
                    $args['start'] = substr($s['start_date'], 0, 10) . ' ' . $st;
                    $args['end']   = substr($s['end_date'], 0, 10) . ' ' . $en;
                }
            } elseif ($date !== $o_date || $st !== $o_st || $en !== $o_en) {
                $args['start'] = "$date $st";
                $args['end']   = gmdate('Y-m-d', strtotime($date) + $span * DAY_IN_SECONDS) . " $en";
            }
        }
        if (count($args) === 1) {
            self::back('Nothing was changed.');
        }
        $r = WPAICal_MCP::execute('update_event', $args);
        is_wp_error($r) ? self::back($r->get_error_message(), false) : self::back('Updated "' . $ev['title'] . '"' . ($series ? ' (whole series).' : '.'));
    }

    public static function sync_now() {
        self::guard('wpaical_sync');
        $r = WPAICal_Sync::run();
        is_wp_error($r) ? self::back($r->get_error_message(), false) : self::back('Synced ' . count(WPAICal_Sync::cache()['events']) . ' events.');
    }

    public static function page() {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $cache    = WPAICal_Sync::cache();
        $has_cred = defined('WPAICAL_SERVICE_ACCOUNT_JSON') || get_option('wpaical_service_account', '') !== '';
        $notice   = isset($_GET['wpaical_notice']) ? sanitize_text_field(wp_unslash($_GET['wpaical_notice'])) : '';
        $choices  = self::category_choices();
        ?>
        <div class="wrap">
            <h1>WP AI Calendar</h1>
            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo !empty($_GET['wpaical_ok']) ? 'success' : 'error'; ?>"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <div style="display:flex;flex-wrap:wrap;gap:16px;margin:16px 0;">
                <div class="card" style="flex:1;min-width:300px;margin:0;">
                    <h2 style="margin-top:0">Status</h2>
                    <p>
                        <strong>Last sync:</strong> <?php echo $cache['fetched_at'] ? esc_html(wp_date('Y-m-d H:i', $cache['fetched_at'])) : 'never'; ?><br>
                        <strong>Events cached:</strong> <?php echo (int) count($cache['events']); ?><br>
                        <strong>Credentials:</strong> <?php echo $has_cred ? 'set' : '<span style="color:#b32d2e">missing</span>'; ?> &middot;
                        <strong>Calendar ID:</strong> <?php echo get_option('wpaical_calendar_id', '') !== '' ? 'set' : '<span style="color:#b32d2e">missing</span>'; ?>
                    </p>
                    <?php if ($cache['error']) : ?>
                        <p style="color:#b32d2e"><strong>Last error:</strong> <?php echo esc_html($cache['error']); ?></p>
                    <?php endif; ?>
                    <p><strong>Public API:</strong><br><code><?php echo esc_html(rest_url('wpaical/v1/events')); ?></code></p>
                    <p><a class="button button-secondary" href="<?php echo esc_url(self::gcal_open_url()); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external" style="margin-top:4px"></span> Open Google Calendar</a></p>
                </div>
                <div class="card" style="flex:1;min-width:300px;margin:0;">
                    <h2 style="margin-top:0">Quick add</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wpaical-quick">
                        <input type="hidden" name="action" value="wpaical_quick_add">
                        <?php wp_nonce_field('wpaical_quick_add'); ?>
                        <p><input type="text" name="title" list="wpaical-titles" class="widefat" placeholder="Event title" required></p>
                        <p>
                            <input type="date" name="date" required>
                            <input type="time" name="start_time" class="wpaical-qa-time"> &ndash; <input type="time" name="end_time" class="wpaical-qa-time">
                            <label><input type="checkbox" name="all_day" id="wpaical-qa-allday"> All day</label>
                        </p>
                        <p><input type="text" name="location" class="widefat" placeholder="Location (optional)"></p>
                        <p>
                            <select name="category" class="wpaical-cat-select">
                                <option value="">Category: from program</option>
                                <?php foreach ($choices as $c) : ?>
                                    <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                                <?php endforeach; ?>
                                <option value="__new__">+ New category&hellip;</option>
                            </select>
                            <input type="text" name="category_new" class="wpaical-cat-new" placeholder="New category name" hidden>
                        </p>
                        <p><button type="submit" class="button button-primary">Add event</button>
                        <span class="description">One-off events. For repeating events use Google Calendar.</span></p>
                    </form>
                </div>
            </div>

            <?php
            $now = wp_date('Y-m-d H:i:s');
            $next = array_slice(array_values(array_filter($cache['events'], function ($e) use ($now) {
                return $e['end_date'] >= $now;
            })), 0, 20);
            ?>
            <div class="card" style="max-width:none;margin:0 0 16px;">
                <h2 style="margin-top:0">Upcoming events</h2>
                <?php if (!$next) : ?>
                    <p>No upcoming events yet. Save your settings and click <em>Sync now</em>.</p>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wpaical-qe-form">
                        <input type="hidden" name="action" value="wpaical_quick_edit">
                        <?php wp_nonce_field('wpaical_quick_edit'); ?>
                        <table class="widefat striped" id="wpaical-upcoming">
                            <thead><tr><th>When</th><th>Event</th><th>Category</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($next as $e) :
                                $cat   = $e['categories'] ? $e['categories'][0]['name'] : '';
                                $start = new DateTimeImmutable($e['start_date'], wp_timezone());
                                $gurl  = self::gcal_edit_url($e['id']);
                                ?>
                                <tr class="wpaical-ev"
                                    data-id="<?php echo esc_attr($e['id']); ?>"
                                    data-series="<?php echo esc_attr($e['series_id'] ? '1' : ''); ?>"
                                    data-title="<?php echo esc_attr($e['title']); ?>"
                                    data-date="<?php echo esc_attr(substr($e['start_date'], 0, 10)); ?>"
                                    data-start="<?php echo esc_attr(substr($e['start_date'], 11, 5)); ?>"
                                    data-end="<?php echo esc_attr(substr($e['end_date'], 11, 5)); ?>"
                                    data-allday="<?php echo $e['all_day'] ? '1' : ''; ?>"
                                    data-cat="<?php echo esc_attr($cat); ?>">
                                    <td style="white-space:nowrap"><?php echo esc_html($e['all_day'] ? $start->format('D M j') . ', all day' : $start->format('D M j, g:ia')); ?></td>
                                    <td><?php echo esc_html($e['title']); ?></td>
                                    <td><?php echo esc_html($cat); ?></td>
                                    <td style="white-space:nowrap">
                                        <button type="button" class="button-link wpaical-qe-open">Quick edit</button>
                                        <?php if ($gurl) : ?> &nbsp;|&nbsp; <a href="<?php echo esc_url($gurl); ?>" target="_blank" rel="noopener noreferrer">Edit in Google</a><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                    <template id="wpaical-qe-tpl">
                        <tr class="wpaical-qe-row"><td colspan="4">
                            <input type="hidden" name="event_id" value="">
                            <strong class="wpaical-qe-title"></strong>
                            <p class="wpaical-qe-fields">
                                <label>Date <input type="date" name="date"></label>
                                <label>Start <input type="time" name="start_time"></label>
                                <label>End <input type="time" name="end_time"></label>
                                <label>Category
                                    <select name="category" class="wpaical-cat-select">
                                        <option value="">&mdash; keep &mdash;</option>
                                        <?php foreach ($choices as $c) : ?>
                                            <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                                        <?php endforeach; ?>
                                        <option value="__new__">+ New category&hellip;</option>
                                    </select>
                                </label>
                                <input type="text" name="category_new" class="wpaical-cat-new" placeholder="New category name" hidden>
                            </p>
                            <p class="wpaical-qe-scope" hidden>
                                <label><input type="radio" name="scope" value="occurrence" checked> This date only</label>
                                &nbsp;
                                <label><input type="radio" name="scope" value="series"> All dates in the series (time and category)</label>
                            </p>
                            <p class="wpaical-qe-note description" hidden>All-day event: only the category can be changed here. Change dates in Google Calendar.</p>
                            <p><button type="submit" class="button button-primary">Update</button> <button type="button" class="button wpaical-qe-cancel">Cancel</button></p>
                        </td></tr>
                    </template>
                <?php endif; ?>
            </div>

            <div class="card" style="max-width:none;margin:0 0 16px;">
                <h2 style="margin-top:0">Connect an AI agent (MCP)</h2>
                <p>Create an Application Password for an Editor+ user (Users &rarr; Profile), then add this to your MCP client. The header value is <code>Basic</code> + base64 of <code>username:application-password</code>.</p>
                <pre style="background:#f6f7f7;padding:10px;overflow:auto;margin:0"><?php echo esc_html(wp_json_encode(['type' => 'http', 'url' => rest_url('wpaical/mcp'), 'headers' => ['Authorization' => 'Basic <base64 username:application-password>']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            </div>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wpaical_save">
                <?php wp_nonce_field('wpaical_save'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="calendar_id">Google Calendar ID</label></th>
                        <td><input id="calendar_id" name="calendar_id" type="text" class="regular-text" value="<?php echo esc_attr(get_option('wpaical_calendar_id', '')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="service_account">Service-account JSON</label></th>
                        <td>
                            <?php if (defined('WPAICAL_SERVICE_ACCOUNT_JSON')) : ?>
                                <p>Using the <code>WPAICAL_SERVICE_ACCOUNT_JSON</code> constant from wp-config.php.</p>
                            <?php else : ?>
                                <p><input type="file" name="service_account_file" accept=".json,application/json"> <span class="description">Upload the downloaded key file, or paste its contents below.</span></p>
                                <textarea id="service_account" name="service_account" rows="4" class="large-text code" placeholder="<?php echo $has_cred ? 'Saved. Paste a new JSON here only to replace it.' : 'Paste the downloaded JSON key file contents'; ?>"></textarea>
                                <p class="description">Stored in the database and never shown again. For tighter security, define <code>WPAICAL_SERVICE_ACCOUNT_JSON</code> in wp-config.php instead.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="categories">Categories</label></th>
                        <td>
                            <input id="categories" name="categories" type="text" class="regular-text" value="<?php echo esc_attr(get_option(WPAICal_Registry::CATEGORIES_OPTION, '')); ?>" placeholder="e.g. Weekly, Online, Special">
                            <p class="description">Comma-separated. Leave empty to allow any category name.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="instructions">Agent instructions</label></th>
                        <td>
                            <textarea id="instructions" name="instructions" rows="4" class="large-text"><?php echo esc_textarea(get_option('wpaical_instructions', '')); ?></textarea>
                            <p class="description">Optional. Extra context sent to AI agents that connect through the MCP endpoint (who you are, naming rules, anything they should know).</p>
                        </td>
                    </tr>
                </table>

                <h2>Programs</h2>
                <p class="description">Give each recurring event a program page and a category. The title must match the Google Calendar event title exactly. A one-off event can override this with <code>page: /some-page/</code> and/or <code>category: Special</code> on their own lines in its Google Calendar description.</p>
                <?php
                $rows   = WPAICal_Registry::rows();
                $pages  = self::page_choices();
                $cats   = WPAICal_Registry::categories();
                foreach ($rows as $r) {
                    if ($r['category'] !== '' && !in_array(strtolower($r['category']), array_map('strtolower', $cats), true)) {
                        $cats[] = $r['category'];
                    }
                }
                $counts = [];
                $titles = [];
                foreach ($cache['events'] as $ev) {
                    $k = WPAICal_Registry::key($ev['title']);
                    $counts[$k] = ($counts[$k] ?? 0) + 1;
                    $titles[$ev['title']] = true;
                }
                $row_html = function ($r) use ($cats, $counts) {
                    $n = $counts[WPAICal_Registry::key($r['title'])] ?? 0;
                    ob_start(); ?>
                    <tr>
                        <td><input type="text" class="wpaical-title" name="program_title[]" list="wpaical-titles" value="<?php echo esc_attr($r['title']); ?>" placeholder="Event title"></td>
                        <td><input type="text" name="program_page[]" list="wpaical-pages" value="<?php echo esc_attr($r['page']); ?>" placeholder="Search pages or paste a URL"></td>
                        <td>
                            <select class="wpaical-cat-select" name="program_category[]">
                                <option value=""<?php selected($r['category'], ''); ?>>&mdash; none &mdash;</option>
                                <?php foreach ($cats as $c) : ?>
                                    <option value="<?php echo esc_attr($c); ?>"<?php selected(strtolower($r['category']), strtolower($c)); ?>><?php echo esc_html($c); ?></option>
                                <?php endforeach; ?>
                                <option value="__new__">+ New category&hellip;</option>
                            </select>
                            <input type="text" class="wpaical-cat-new" name="program_category_new[]" placeholder="New category name" hidden>
                        </td>
                        <td class="wpaical-count<?php echo $n ? '' : ' zero'; ?>"><?php echo $n ? esc_html(sprintf(_n('%d event', '%d events', $n, 'wp-ai-calendar'), $n)) : esc_html__('no match', 'wp-ai-calendar'); ?></td>
                        <td><button type="button" class="button-link button-link-delete wpaical-remove">Remove</button></td>
                    </tr>
                    <?php
                    return ob_get_clean();
                };
                ?>
                <datalist id="wpaical-pages">
                    <?php foreach ($pages as $path => $title) : ?>
                        <option value="<?php echo esc_attr($path); ?>"><?php echo esc_html($title); ?></option>
                    <?php endforeach; ?>
                </datalist>
                <datalist id="wpaical-titles">
                    <?php foreach (array_keys($titles) as $title) : ?>
                        <option value="<?php echo esc_attr($title); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <table id="wpaical-programs" class="widefat striped">
                    <thead><tr>
                        <th class="col-title">Event title</th><th class="col-page">Program page</th><th class="col-cat">Category</th><th class="col-n">In calendar</th><th class="col-x"></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($rows as $r) { echo $row_html($r); } // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside the closure ?>
                    </tbody>
                </table>
                <p id="wpaical-empty" class="description" hidden>No programs yet.</p>
                <template id="wpaical-row-template"><?php echo $row_html(['title' => '', 'page' => '', 'category' => '']); // phpcs:ignore WordPress.Security.EscapeOutput ?></template>
                <p><button type="button" class="button" id="wpaical-add-row">+ Add program</button></p>
                <?php submit_button('Save settings'); ?>
            </form>

            <h2>Actions</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:12px">
                <input type="hidden" name="action" value="wpaical_sync">
                <?php wp_nonce_field('wpaical_sync'); submit_button('Sync now', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
}
