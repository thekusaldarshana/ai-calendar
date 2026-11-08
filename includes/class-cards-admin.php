<?php
defined('ABSPATH') || exit;

/**
 * AI Calendar -> Program cards: the visual editor for the [wpaical_upcoming] widget.
 *
 *  1. Pick a program (a title prefix such as "Meditate with a Monk").
 *  2. See its upcoming dates; change a date's picture or link (this date only, or the whole series).
 *     Those edits are written to Google Calendar, the source of truth.
 *  3. Add cards that have no date yet ("Dates to be announced"); these live in WordPress only.
 *  4. Live preview of exactly what visitors will see, and the shortcode to paste.
 */
class WPAICal_Cards_Admin {
    const SLUG = 'wp-ai-calendar-cards';
    const CAP  = 'manage_options';

    public static function init() {
        add_action('admin_menu', function () {
            add_submenu_page(WPAICal_Admin::SLUG, 'Program cards', 'Program cards', self::CAP, self::SLUG, [__CLASS__, 'page']);
        });
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_wpaical_card_event', [__CLASS__, 'save_event']);
        add_action('admin_post_wpaical_card_save', [__CLASS__, 'save_card']);
        add_action('admin_post_wpaical_card_delete', [__CLASS__, 'delete_card']);
    }

    private static function is_this_page() {
        return isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === self::SLUG; // phpcs:ignore WordPress.Security.NonceVerification -- read-only page check
    }

    public static function assets() {
        if (!self::is_this_page()) {
            return;
        }
        wp_enqueue_media();
        $main = WPAICAL_DIR . 'wp-ai-calendar.php';
        wp_enqueue_style('wpaical-admin', plugins_url('assets/admin.css', $main), [], WPAICAL_VERSION);
        wp_enqueue_script('wpaical-cards-admin', plugins_url('assets/cards-admin.js', $main), [], WPAICAL_VERSION, true);
    }

    private static function guard($action) {
        if (!current_user_can(self::CAP)) {
            wp_die('Not allowed.', 403);
        }
        check_admin_referer($action);
    }

    private static function url($program = '') {
        $args = ['page' => self::SLUG];
        if ($program !== '') {
            $args['program'] = rawurlencode($program);
        }
        return add_query_arg($args, admin_url('admin.php'));
    }

    private static function back($program, $notice, $ok = true) {
        wp_safe_redirect(add_query_arg(['wpaical_notice' => rawurlencode($notice), 'wpaical_ok' => $ok ? 1 : 0], self::url($program)));
        exit;
    }

    /** Names offered in the program picker: the part of event titles before " - ", registry titles, and card programs. */
    private static function programs() {
        $names = [];
        $add = function ($t) use (&$names) {
            $t = trim((string) $t);
            if ($t === '') {
                return;
            }
            if (preg_match('/^(.+?)\s+[\x{2013}\x{2014}-]\s+.+$/u', $t, $m)) {
                $t = $m[1];
            }
            $names[WPAICal_Registry::key($t)] = $t;
        };
        foreach (WPAICal_Sync::cache()['events'] as $e) {
            $add($e['title']);
        }
        foreach (WPAICal_Registry::rows() as $r) {
            $add($r['title']);
        }
        foreach (WPAICal_Widget::cards() as $c) {
            $add(isset($c['program']) ? $c['program'] : '');
        }
        natcasesort($names);
        return array_values($names);
    }

    /** A link must be an http(s) URL or a site path. Returns [clean, error]. */
    private static function clean_link($v) {
        $v = trim((string) $v);
        if ($v === '') {
            return ['', ''];
        }
        if (preg_match('#^https?://#i', $v)) {
            $u = esc_url_raw($v);
            return $u === '' ? ['', 'That link is not a valid URL.'] : [$u, ''];
        }
        if ($v[0] === '/') {
            return [preg_replace('/[^A-Za-z0-9\-._~\/?#=&%+]/', '', $v), ''];
        }
        return ['', 'Links must start with https:// or with / (a page on this site).'];
    }

    private static function clean_image($v) {
        $v = trim((string) $v);
        if ($v === '') {
            return ['', ''];
        }
        $u = preg_match('#^https?://#i', $v) ? esc_url_raw($v) : '';
        return $u === '' ? ['', 'The image must be a full https:// URL (use "Choose image").'] : [$u, ''];
    }

    private static function find_event($id) {
        foreach (WPAICal_Sync::cache()['events'] as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ handlers

    /** Change one date's (or a whole series') link and picture. Written to Google Calendar. */
    public static function save_event() {
        self::guard('wpaical_card_event');
        $program = sanitize_text_field(wp_unslash($_POST['program'] ?? ''));
        $ev = self::find_event(sanitize_text_field(wp_unslash($_POST['event_id'] ?? '')));
        if (!$ev) {
            self::back($program, 'That event is no longer in the cache. Sync and try again.', false);
        }
        $series = !empty($ev['series_id']) && (sanitize_text_field(wp_unslash($_POST['scope'] ?? '')) === 'series');
        $args   = ['event_id' => $series ? $ev['series_id'] : $ev['id']];

        list($link, $err) = self::clean_link(wp_unslash($_POST['link'] ?? ''));
        if ($err) {
            self::back($program, $err, false);
        }
        list($img, $err) = self::clean_image(wp_unslash($_POST['image'] ?? ''));
        if ($err) {
            self::back($program, $err, false);
        }
        // Only send what changed: an unchanged link may come from the program registry and must not be pinned.
        if ($link !== trim(sanitize_text_field(wp_unslash($_POST['orig_link'] ?? '')))) {
            $args['page'] = $link;
        }
        if ($img !== trim(sanitize_text_field(wp_unslash($_POST['orig_image'] ?? '')))) {
            $args['image'] = $img;
        }
        if (count($args) === 1) {
            self::back($program, 'Nothing was changed.');
        }
        $r = WPAICal_MCP::execute('update_event', $args);
        if (is_wp_error($r)) {
            self::back($program, $r->get_error_message(), false);
        }
        self::back($program, 'Updated "' . $ev['title'] . '"' . ($series ? ' (all dates in the series).' : ' (this date only).'));
    }

    public static function save_card() {
        self::guard('wpaical_card_save');
        $program = trim(sanitize_text_field(wp_unslash($_POST['program'] ?? '')));
        $title   = trim(sanitize_text_field(wp_unslash($_POST['title'] ?? '')));
        if ($title === '') {
            self::back($program, 'A card needs a title.', false);
        }
        list($link, $err) = self::clean_link(wp_unslash($_POST['link'] ?? ''));
        if ($err) {
            self::back($program, $err, false);
        }
        list($img, $err) = self::clean_image(wp_unslash($_POST['image'] ?? ''));
        if ($err) {
            self::back($program, $err, false);
        }
        $id   = sanitize_key(wp_unslash($_POST['card_id'] ?? ''));
        $card = [
            'id'       => $id !== '' ? $id : 'c' . substr(md5(wp_generate_password(20, false) . microtime()), 0, 10),
            'program'  => $program,
            'title'    => $title,
            'kicker'   => trim(sanitize_text_field(wp_unslash($_POST['kicker'] ?? ''))),
            'location' => trim(sanitize_text_field(wp_unslash($_POST['location'] ?? ''))),
            'note'     => trim(sanitize_text_field(wp_unslash($_POST['note'] ?? ''))),
            'image'    => $img,
            'link'     => $link,
        ];
        $cards = WPAICal_Widget::cards();
        $found = false;
        foreach ($cards as $i => $c) {
            if (isset($c['id']) && $c['id'] === $card['id']) {
                $cards[$i] = $card;
                $found = true;
            }
        }
        if (!$found) {
            $cards[] = $card;
        }
        update_option(WPAICal_Widget::CARDS_OPTION, $cards, false);
        self::back($program, ($found ? 'Updated' : 'Added') . ' card "' . $title . '".');
    }

    public static function delete_card() {
        self::guard('wpaical_card_delete');
        $program = trim(sanitize_text_field(wp_unslash($_POST['program'] ?? '')));
        $id      = sanitize_key(wp_unslash($_POST['card_id'] ?? ''));
        $cards   = array_values(array_filter(WPAICal_Widget::cards(), function ($c) use ($id) {
            return !isset($c['id']) || $c['id'] !== $id;
        }));
        update_option(WPAICal_Widget::CARDS_OPTION, $cards, false);
        self::back($program, 'Card deleted.');
    }

    // ------------------------------------------------------------------ page

    /** Image field with a preview and the media-library picker. */
    private static function image_field($name, $value) {
        ?>
        <span class="wpaical-imgfield">
            <img class="wpaical-imgprev" src="<?php echo esc_url($value); ?>" alt=""<?php echo $value === '' ? ' hidden' : ''; ?>>
            <input type="text" name="<?php echo esc_attr($name); ?>" class="wpaical-imgurl regular-text" value="<?php echo esc_attr($value); ?>" placeholder="https://…">
            <button type="button" class="button wpaical-pick">Choose image</button>
            <button type="button" class="button-link wpaical-clear">Clear</button>
        </span>
        <?php
    }

    public static function page() {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $program = trim(sanitize_text_field(wp_unslash($_GET['program'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification
        $notice  = isset($_GET['wpaical_notice']) ? sanitize_text_field(wp_unslash($_GET['wpaical_notice'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $ok      = !empty($_GET['wpaical_ok']); // phpcs:ignore WordPress.Security.NonceVerification
        $tz      = wp_timezone();
        $now     = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
        $needles = WPAICal_Widget::needles($program);

        $events = [];
        if ($program !== '') {
            foreach (WPAICal_Sync::cache()['events'] as $e) {
                if ($e['end_date'] >= $now && WPAICal_Widget::matches($e['title'], $needles, 'prefix')) {
                    $events[] = $e;
                }
                if (count($events) >= 50) {
                    break;
                }
            }
        }
        $cards = array_values(array_filter(WPAICal_Widget::cards(), function ($c) use ($needles, $program) {
            return $program !== '' && WPAICal_Widget::matches(isset($c['program']) ? $c['program'] : '', $needles, 'prefix');
        }));
        $pages = [];
        foreach (get_pages(['post_status' => 'publish', 'number' => 500, 'sort_column' => 'post_title']) as $pg) {
            $pages[wp_make_link_relative(get_permalink($pg))] = $pg->post_title;
        }
        $shortcode = '[wpaical_upcoming program="' . $program . '" limit="10"]';
        ?>
        <div class="wrap wpaical-cards">
            <h1>Program cards</h1>
            <p class="description">The cards that <code>[wpaical_upcoming]</code> shows on a page. Dates come from Google Calendar; here you set each date's picture and link, and add cards for programs that have no date yet.</p>
            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo $ok ? 'success' : 'error'; ?>"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="wpaical-pick-program">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <label for="wpaical-program"><strong>Program</strong></label>
                <input type="text" id="wpaical-program" name="program" list="wpaical-programs-list" value="<?php echo esc_attr($program); ?>" placeholder="e.g. Meditate with a Monk" class="regular-text">
                <button type="submit" class="button button-primary">Show</button>
                <datalist id="wpaical-programs-list">
                    <?php foreach (self::programs() as $name) : ?><option value="<?php echo esc_attr($name); ?>"></option><?php endforeach; ?>
                </datalist>
            </form>
            <p>
                <?php foreach (array_slice(self::programs(), 0, 12) as $name) : ?>
                    <a class="button button-small" href="<?php echo esc_url(self::url($name)); ?>"><?php echo esc_html($name); ?></a>
                <?php endforeach; ?>
            </p>

            <?php if ($program === '') : ?>
                <p>Choose a program above. A program is the start of an event title, so <em>Meditate with a Monk</em> covers "Meditate with a Monk &ndash; Vienna Community Center" and so on.</p>
            <?php else : ?>

            <div class="card" style="max-width:none;margin:16px 0">
                <h2 style="margin-top:0">Shortcode for this program</h2>
                <p><input type="text" readonly class="large-text code wpaical-sc" value="<?php echo esc_attr($shortcode); ?>"> </p>
                <p class="description">Paste it into the program's page (Elementor: a Shortcode widget). Add <code>heading="Upcoming Events"</code>, <code>button="Register"</code>, <code>limit="6"</code> as you like.</p>
            </div>

            <h2>Upcoming dates <span class="count">(<?php echo (int) count($events); ?>)</span></h2>
            <?php if (!$events) : ?>
                <p>No upcoming events match "<?php echo esc_html($program); ?>". Add them in Google Calendar (or Quick add on the dashboard), then come back.</p>
            <?php else : ?>
                <table class="widefat striped wpaical-ev-table">
                    <thead><tr><th style="width:84px">Picture</th><th>When</th><th>Event</th><th>Link</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($events as $e) :
                        $start = new DateTimeImmutable($e['start_date'], $tz);
                        $eff   = WPAICal_Widget::image_for($e);
                        $own   = isset($e['image']) ? $e['image'] : '';
                        ?>
                        <tr class="wpaical-evrow">
                            <td><?php if ($eff !== '') : ?><img src="<?php echo esc_url($eff); ?>" alt="" class="wpaical-thumb"><?php else : ?><span class="description">none</span><?php endif; ?></td>
                            <td style="white-space:nowrap"><?php echo esc_html($e['all_day'] ? $start->format('D M j') . ', all day' : $start->format('D M j, g:ia')); ?></td>
                            <td><strong><?php echo esc_html($e['title']); ?></strong><?php echo $e['location'] !== '' ? '<br><span class="description">' . esc_html($e['location']) . '</span>' : ''; ?></td>
                            <td><?php echo !empty($e['url']) ? '<a href="' . esc_url($e['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html(wp_parse_url($e['url'], PHP_URL_HOST) . (wp_parse_url($e['url'], PHP_URL_PATH) ?: '')) . '</a>' : '<span class="description">none</span>'; ?></td>
                            <td><button type="button" class="button-link wpaical-edit-ev">Edit</button></td>
                        </tr>
                        <tr class="wpaical-evedit" hidden><td colspan="5">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="wpaical_card_event">
                                <?php wp_nonce_field('wpaical_card_event'); ?>
                                <input type="hidden" name="program" value="<?php echo esc_attr($program); ?>">
                                <input type="hidden" name="event_id" value="<?php echo esc_attr($e['id']); ?>">
                                <input type="hidden" name="orig_link" value="<?php echo esc_attr(isset($e['url']) ? $e['url'] : ''); ?>">
                                <input type="hidden" name="orig_image" value="<?php echo esc_attr($own); ?>">
                                <p><strong><?php echo esc_html($e['title']); ?></strong> &mdash; <?php echo esc_html($start->format('D M j, g:ia')); ?></p>
                                <p><label>Picture<br><?php self::image_field('image', $own); ?></label>
                                    <?php if ($own === '' && $eff !== '') : ?><br><span class="description">Currently using the program page's featured image.</span><?php endif; ?></p>
                                <p><label>Link (the card's button)<br>
                                    <input type="text" name="link" list="wpaical-page-list" class="large-text" value="<?php echo esc_attr(isset($e['url']) ? $e['url'] : ''); ?>" placeholder="/page-on-this-site/ or https://… (e.g. a ticketing page)"></label></p>
                                <?php if (!empty($e['series_id'])) : ?>
                                    <p>
                                        <label><input type="radio" name="scope" value="occurrence" checked> This date only</label> &nbsp;
                                        <label><input type="radio" name="scope" value="series"> All dates in this series</label>
                                    </p>
                                <?php endif; ?>
                                <p><button type="submit" class="button button-primary">Save to calendar</button> <button type="button" class="button wpaical-cancel">Cancel</button>
                                    <span class="description">Empty fields go back to the default (program page link, program page picture).</span></p>
                            </form>
                        </td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top:28px">Cards without a date <span class="count">(<?php echo (int) count($cards); ?>)</span></h2>
            <p class="description">For a place or session whose dates are not set yet. These are not added to the calendar; they show as "Coming soon" in the widget until you add real dates.</p>
            <?php if ($cards) : ?>
                <table class="widefat striped">
                    <thead><tr><th style="width:84px">Picture</th><th>Card</th><th>Note</th><th>Link</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($cards as $c) : ?>
                        <tr class="wpaical-evrow">
                            <td><?php if (!empty($c['image'])) : ?><img src="<?php echo esc_url($c['image']); ?>" alt="" class="wpaical-thumb"><?php else : ?><span class="description">none</span><?php endif; ?></td>
                            <td><strong><?php echo esc_html($c['title']); ?></strong><?php echo !empty($c['location']) ? '<br><span class="description">' . esc_html($c['location']) . '</span>' : ''; ?></td>
                            <td><?php echo esc_html(!empty($c['note']) ? $c['note'] : 'Dates to be announced'); ?></td>
                            <td><?php echo !empty($c['link']) ? esc_html($c['link']) : '<span class="description">none</span>'; ?></td>
                            <td style="white-space:nowrap">
                                <button type="button" class="button-link wpaical-edit-ev">Edit</button> &nbsp;|&nbsp;
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('Delete this card?');">
                                    <input type="hidden" name="action" value="wpaical_card_delete">
                                    <?php wp_nonce_field('wpaical_card_delete'); ?>
                                    <input type="hidden" name="program" value="<?php echo esc_attr($program); ?>">
                                    <input type="hidden" name="card_id" value="<?php echo esc_attr($c['id']); ?>">
                                    <button type="submit" class="button-link button-link-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <tr class="wpaical-evedit" hidden><td colspan="5"><?php self::card_form($program, $c, $pages); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <details class="wpaical-addcard" <?php echo $cards ? '' : 'open'; ?>>
                <summary class="button">+ Add card without a date</summary>
                <div class="card" style="max-width:none;margin-top:10px"><?php self::card_form($program, [], $pages); ?></div>
            </details>

            <h2 style="margin-top:28px">Preview</h2>
            <p class="description">Exactly what visitors will see (with your Widget CSS applied).</p>
            <div class="wpaical-preview"><?php echo WPAICal_Widget::render(['program' => $program, 'limit' => 50, 'button' => 'Details']); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside render() ?></div>

            <datalist id="wpaical-page-list">
                <?php foreach ($pages as $path => $title) : ?><option value="<?php echo esc_attr($path); ?>"><?php echo esc_html($title); ?></option><?php endforeach; ?>
            </datalist>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function card_form($program, array $c, array $pages) {
        $v = function ($k) use ($c) {
            return isset($c[$k]) ? $c[$k] : '';
        };
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wpaical-cardform">
            <input type="hidden" name="action" value="wpaical_card_save">
            <?php wp_nonce_field('wpaical_card_save'); ?>
            <input type="hidden" name="card_id" value="<?php echo esc_attr($v('id')); ?>">
            <input type="hidden" name="program" value="<?php echo esc_attr($program); ?>">
            <table class="form-table" role="presentation">
                <tr><th><label>Title</label></th><td><input type="text" name="title" class="regular-text" required value="<?php echo esc_attr($v('title')); ?>" placeholder="e.g. National Mall"> <span class="description">Shown as the card heading.</span></td></tr>
                <tr><th><label>Label</label></th><td><input type="text" name="kicker" class="regular-text" value="<?php echo esc_attr($v('kicker')); ?>" placeholder="<?php echo esc_attr($program); ?>"> <span class="description">Small text above the title (defaults to the program).</span></td></tr>
                <tr><th><label>Location</label></th><td><input type="text" name="location" class="regular-text" value="<?php echo esc_attr($v('location')); ?>" placeholder="e.g. Washington, DC"></td></tr>
                <tr><th><label>Message</label></th><td><input type="text" name="note" class="regular-text" value="<?php echo esc_attr($v('note')); ?>" placeholder="Dates to be announced"></td></tr>
                <tr><th><label>Picture</label></th><td><?php self::image_field('image', $v('image')); ?></td></tr>
                <tr><th><label>Link</label></th><td><input type="text" name="link" list="wpaical-page-list" class="large-text" value="<?php echo esc_attr($v('link')); ?>" placeholder="/page-on-this-site/ or https://… (leave empty for no button)"></td></tr>
            </table>
            <p><button type="submit" class="button button-primary"><?php echo $v('id') !== '' ? 'Save card' : 'Add card'; ?></button>
                <?php if ($v('id') !== '') : ?><button type="button" class="button wpaical-cancel">Cancel</button><?php endif; ?></p>
        </form>
        <?php
    }
}
