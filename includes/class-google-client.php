<?php
defined('ABSPATH') || exit;

/**
 * Minimal Google Calendar client using a service account (no Composer needed).
 * Flow: sign a JWT with the account's private key -> exchange it for an access
 * token -> call the Calendar REST API with that token.
 */
class WPAICal_Google_Client {
    const SCOPE = 'https://www.googleapis.com/auth/calendar';
    const API   = 'https://www.googleapis.com/calendar/v3';

    private $account;

    /** @param array $account Decoded service-account JSON (needs client_email, private_key, token_uri). */
    public function __construct(array $account) {
        $this->account = $account;
    }

    /** Load credentials from the wp-config constant (preferred) or the saved option. */
    public static function from_settings() {
        $json = defined('WPAICAL_SERVICE_ACCOUNT_JSON') ? WPAICAL_SERVICE_ACCOUNT_JSON : get_option('wpaical_service_account', '');
        $acct = json_decode((string) $json, true);
        if (!is_array($acct) || empty($acct['client_email']) || empty($acct['private_key'])) {
            return new WP_Error('wpaical_no_credentials', 'Google service-account credentials are missing or invalid.');
        }
        return new self($acct);
    }

    private static function b64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function token() {
        $cache_key = 'wpaical_gtoken';
        $cached    = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }

        $token_uri = !empty($this->account['token_uri']) ? $this->account['token_uri'] : 'https://oauth2.googleapis.com/token';
        $now       = time();
        $header    = self::b64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims    = self::b64url(wp_json_encode([
            'iss'   => $this->account['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $token_uri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signature = '';
        $ok = openssl_sign($header . '.' . $claims, $signature, $this->account['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok) {
            return new WP_Error('wpaical_sign', 'Could not sign the Google auth request (check the private key).');
        }

        $resp = wp_remote_post($token_uri, [
            'timeout' => 15,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $header . '.' . $claims . '.' . self::b64url($signature),
            ],
        ]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($body['access_token'])) {
            $msg = isset($body['error_description']) ? $body['error_description'] : 'unknown error';
            return new WP_Error('wpaical_token', 'Google refused the credentials: ' . $msg);
        }
        // Expire our cached copy a bit before Google does.
        set_transient($cache_key, $body['access_token'], max(60, (int) $body['expires_in'] - 120));
        return $body['access_token'];
    }

    /** @return array|WP_Error Decoded JSON response. */
    public function request($method, $path, array $query = [], $body = null) {
        $token = $this->token();
        if (is_wp_error($token)) {
            return $token;
        }
        $url  = self::API . $path . ($query ? '?' . http_build_query($query) : '');
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code >= 400) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $code;
            return new WP_Error('wpaical_api', 'Google Calendar API: ' . $msg, ['status' => $code]);
        }
        return is_array($data) ? $data : [];
    }

    /**
     * List events with recurring series expanded into individual occurrences
     * (singleEvents=true), following pagination.
     *
     * @return array|WP_Error List of Google event resources.
     */
    public function list_events($calendar_id, $time_min_rfc3339, $time_max_rfc3339) {
        $items = [];
        $page  = '';
        do {
            $query = [
                'singleEvents' => 'true',
                'orderBy'      => 'startTime',
                'maxResults'   => 250,
                'timeMin'      => $time_min_rfc3339,
                'timeMax'      => $time_max_rfc3339,
            ];
            if ($page) {
                $query['pageToken'] = $page;
            }
            $data = $this->request('GET', '/calendars/' . rawurlencode($calendar_id) . '/events', $query);
            if (is_wp_error($data)) {
                return $data;
            }
            $items = array_merge($items, isset($data['items']) ? $data['items'] : []);
            $page  = isset($data['nextPageToken']) ? $data['nextPageToken'] : '';
        } while ($page);
        return $items;
    }

    /** Find an event by a private extended property (used to keep imports idempotent). */
    public function find_by_private_property($calendar_id, $key, $value) {
        $data = $this->request('GET', '/calendars/' . rawurlencode($calendar_id) . '/events', [
            'privateExtendedProperty' => $key . '=' . $value,
            'maxResults'              => 1,
        ]);
        if (is_wp_error($data)) {
            return $data;
        }
        return !empty($data['items'][0]) ? $data['items'][0] : null;
    }

    public function insert_event($calendar_id, array $event) {
        return $this->request('POST', '/calendars/' . rawurlencode($calendar_id) . '/events', [], $event);
    }

    private function event_path($calendar_id, $event_id) {
        return '/calendars/' . rawurlencode($calendar_id) . '/events/' . rawurlencode($event_id);
    }

    public function get_event($calendar_id, $event_id) {
        return $this->request('GET', $this->event_path($calendar_id, $event_id));
    }

    /** Partial update. On a recurring *instance* id this changes only that occurrence; on the series id, the whole series. */
    public function patch_event($calendar_id, $event_id, array $fields) {
        return $this->request('PATCH', $this->event_path($calendar_id, $event_id), [], $fields);
    }

    /** Delete an event. On a recurring instance id this cancels just that occurrence. */
    public function delete_event($calendar_id, $event_id) {
        return $this->request('DELETE', $this->event_path($calendar_id, $event_id));
    }

    /** Recurring series definitions (not expanded), i.e. events that carry a recurrence rule. */
    public function list_series($calendar_id) {
        $items = [];
        $page  = '';
        do {
            $query = ['singleEvents' => 'false', 'maxResults' => 250];
            if ($page) {
                $query['pageToken'] = $page;
            }
            $data = $this->request('GET', '/calendars/' . rawurlencode($calendar_id) . '/events', $query);
            if (is_wp_error($data)) {
                return $data;
            }
            foreach (isset($data['items']) ? $data['items'] : [] as $it) {
                if (!empty($it['recurrence']) && (!isset($it['status']) || $it['status'] !== 'cancelled')) {
                    $items[] = $it;
                }
            }
            $page = isset($data['nextPageToken']) ? $data['nextPageToken'] : '';
        } while ($page);
        return $items;
    }

    /** Occurrences of one recurring series inside a time window. */
    public function list_instances($calendar_id, $event_id, $time_min, $time_max) {
        $data = $this->request('GET', $this->event_path($calendar_id, $event_id) . '/instances', [
            'timeMin' => $time_min, 'timeMax' => $time_max, 'maxResults' => 250,
        ]);
        return is_wp_error($data) ? $data : (isset($data['items']) ? $data['items'] : []);
    }
}
