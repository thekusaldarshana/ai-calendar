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
                </div>
                <div class="card" style="flex:1;min-width:300px;margin:0;">
                    <h2 style="margin-top:0">Upcoming events</h2>
                    <?php
                    $now = wp_date('Y-m-d H:i:s');
                    $next = array_slice(array_values(array_filter($cache['events'], function ($e) use ($now) {
                        return $e['end_date'] >= $now;
                    })), 0, 8);
                    ?>
                    <?php if (!$next) : ?>
                        <p>No upcoming events yet. Save your settings and click <em>Sync now</em>.</p>
                    <?php else : ?>
                        <table class="widefat striped"><tbody>
                        <?php foreach ($next as $e) : ?>
                            <tr>
                                <td style="white-space:nowrap"><?php echo esc_html((new DateTimeImmutable($e['start_date'], wp_timezone()))->format('D M j, g:ia')); ?></td>
                                <td><?php echo esc_html($e['title']); ?></td>
                                <td><?php echo $e['categories'] ? esc_html($e['categories'][0]['name']) : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    <?php endif; ?>
                </div>
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
