<?php
defined('ABSPATH') || exit;

/**
 * Program registry: maps an event title (as typed in Google Calendar) to the
 * WordPress program page it links to and its display category.
 *
 * Stored as one line per program: "Title | /page-path-or-full-url | Category".
 * Google Calendar's own UI has no field for these, so we keep them here.
 */
class WPAICal_Registry {
    const OPTION = 'wpaical_programs';

    const CATEGORIES_OPTION = 'wpaical_categories';

    /**
     * Allowed category names, from Settings (comma separated). An empty list means
     * categories are free-form and not validated.
     * @return string[]
     */
    public static function categories() {
        $raw = (string) get_option(self::CATEGORIES_OPTION, '');
        return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }

    /** @return array<string,array{url:string,category:string}> keyed by lower-cased title. */
    public static function all() {
        static $memo = []; // parsed once per distinct option value (resolve() runs once per event)
        $raw = (string) get_option(self::OPTION, '');
        if (isset($memo[$raw])) {
            return $memo[$raw];
        }
        $map = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2 || $parts[0] === '') {
                continue;
            }
            $map[self::key($parts[0])] = [
                'url'      => self::absolute_url($parts[1]),
                'category' => isset($parts[2]) ? $parts[2] : '',
            ];
        }
        $memo[$raw] = $map;
        return $map;
    }

    /** Raw rows for display/editing: [['title'=>..,'page'=>..,'category'=>..], ...]. */
    public static function rows() {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) get_option(self::OPTION, '')) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) >= 2 && $parts[0] !== '') {
                $rows[] = ['title' => $parts[0], 'page' => $parts[1], 'category' => isset($parts[2]) ? $parts[2] : ''];
            }
        }
        return $rows;
    }

    /** Add or replace one program (matched by title, case-insensitive). */
    public static function set($title, $page, $category) {
        $rows  = self::rows();
        $found = false;
        foreach ($rows as &$r) {
            if (self::key($r['title']) === self::key($title)) {
                $r = ['title' => $title, 'page' => $page, 'category' => $category];
                $found = true;
            }
        }
        unset($r);
        if (!$found) {
            $rows[] = ['title' => $title, 'page' => $page, 'category' => $category];
        }
        self::save_rows($rows);
    }

    /** @return bool Whether a program was removed. */
    public static function remove($title) {
        $rows = self::rows();
        $kept = array_values(array_filter($rows, function ($r) use ($title) {
            return self::key($r['title']) !== self::key($title);
        }));
        self::save_rows($kept);
        return count($kept) !== count($rows);
    }

    private static function save_rows(array $rows) {
        $lines = array_map(function ($r) {
            return str_replace(['|', "\n", "\r"], ' ', $r['title']) . ' | ' . str_replace(['|', "\n", "\r"], '', $r['page']) . ' | ' . str_replace(['|', "\n", "\r"], '', $r['category']);
        }, $rows);
        update_option(self::OPTION, implode("\n", $lines));
    }

    public static function key($title) {
        $t = trim(html_entity_decode((string) $title, ENT_QUOTES));
        return function_exists('mb_strtolower') ? mb_strtolower($t) : strtolower($t);
    }

    private static function absolute_url($value) {
        if ($value === '') {
            return '';
        }
        return preg_match('#^https?://#i', $value) ? esc_url_raw($value) : home_url('/' . ltrim($value, '/'));
    }

    /** Value of a "page:" or "category:" directive line in a description, or ''. */
    public static function directive($description, $name) {
        return preg_match('/^\s*' . preg_quote($name, '/') . '\s*:\s*(.+?)\s*$/im', (string) $description, $m) ? $m[1] : '';
    }

    /** Description text without our page:/category:/image: directive lines. */
    public static function strip_directives($description) {
        return trim(preg_replace('/^\s*(page|category|image)\s*:.*$/im', '', (string) $description));
    }

    /**
     * Resolve link + category for one normalised event.
     * Priority: "page:" / "category:" lines in the event description, then the registry.
     */
    public static function resolve($title, $description) {
        $result = ['url' => '', 'category' => '', 'image' => ''];

        $registry = self::all();
        $k = self::key($title);
        if (isset($registry[$k])) {
            $result = $registry[$k];
        }

        if (preg_match('/^\s*page\s*:\s*(\S+)\s*$/im', (string) $description, $m)) {
            $result['url'] = self::absolute_url($m[1]);
        }
        if (preg_match('/^\s*category\s*:\s*(.+?)\s*$/im', (string) $description, $m)) {
            $result['category'] = $m[1];
        }
        if (preg_match('/^\s*image\s*:\s*(https?:\/\/\S+)\s*$/im', (string) $description, $m)) {
            $result['image'] = esc_url_raw($m[1]);
        }
        if (!isset($result['image'])) {
            $result['image'] = '';
        }
        return $result;
    }
}
