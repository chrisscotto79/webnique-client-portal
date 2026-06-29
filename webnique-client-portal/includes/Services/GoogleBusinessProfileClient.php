<?php
/**
 * Google Business Profile OAuth and API client.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

use WNQ\Models\Client;

if (!defined('ABSPATH')) {
    exit;
}

final class GoogleBusinessProfileClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const ACCOUNTS_URL = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';
    private const LOCATIONS_URL = 'https://mybusinessbusinessinformation.googleapis.com/v1';
    private const POSTS_URL = 'https://mybusiness.googleapis.com/v4';
    private const SCOPE = 'https://www.googleapis.com/auth/business.manage openid email';
    private const ACCESS_TOKEN_TRANSIENT = 'wnq_gbp_access_token';
    private const STATE_PREFIX = 'wnq_gbp_oauth_state_';

    private array $errors = [];

    public static function credentialsConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    public static function connected(): bool
    {
        return self::refreshToken() !== '';
    }

    public static function redirectUri(): string
    {
        return admin_url('admin-post.php?action=wnq_gbp_oauth_callback');
    }

    public static function connectionStatus(): array
    {
        $accounts = self::syncedAccounts();
        $locations = self::syncedLocations();

        return [
            'credentials_configured' => self::credentialsConfigured(),
            'connected'              => self::connected(),
            'email'                  => sanitize_email((string)get_option('wnq_gbp_connected_email', '')),
            'connected_at'           => sanitize_text_field((string)get_option('wnq_gbp_connected_at', '')),
            'last_sync'              => sanitize_text_field((string)get_option('wnq_gbp_last_sync', '')),
            'last_error'             => sanitize_textarea_field((string)get_option('wnq_gbp_last_error', '')),
            'account_count'          => count($accounts),
            'location_count'         => count($locations),
            'credential_source'      => self::hasGbpCredentialPair() ? 'GBP settings' : 'Google Ads OAuth app',
        ];
    }

    public static function saveCredentials(string $client_id, string $client_secret): void
    {
        $old_id = self::clientId();
        $old_secret = self::clientSecret();
        $changed = false;

        if ($client_id !== '') {
            update_option('wnq_gbp_oauth_client_id', sanitize_text_field($client_id), false);
            $changed = $changed || !hash_equals($old_id, $client_id);
        }
        if ($client_secret !== '') {
            update_option('wnq_gbp_oauth_client_secret', sanitize_text_field($client_secret), false);
            $changed = $changed || !hash_equals($old_secret, $client_secret);
        }

        if ($changed && self::connected()) {
            self::clearConnection(false);
        }
    }

    public static function clearSavedCredentials(): void
    {
        self::clearConnection(true);
        delete_option('wnq_gbp_oauth_client_id');
        delete_option('wnq_gbp_oauth_client_secret');
    }

    public static function authorizationUrl(): string
    {
        if (!self::credentialsConfigured()) {
            return '';
        }

        $state = wp_generate_password(48, false, false);
        set_transient(self::STATE_PREFIX . hash('sha256', $state), get_current_user_id(), 10 * MINUTE_IN_SECONDS);

        return add_query_arg([
            'client_id'                => self::clientId(),
            'redirect_uri'             => self::redirectUri(),
            'response_type'            => 'code',
            'scope'                    => self::SCOPE,
            'access_type'              => 'offline',
            'include_granted_scopes'   => 'true',
            'prompt'                   => 'consent select_account',
            'state'                    => $state,
        ], self::AUTH_URL);
    }

    public static function consumeAuthorizationState(string $state): bool
    {
        if ($state === '') {
            return false;
        }

        $key = self::STATE_PREFIX . hash('sha256', $state);
        $user_id = (int)get_transient($key);
        delete_transient($key);

        return $user_id > 0 && $user_id === get_current_user_id();
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        if (!self::credentialsConfigured() || $code === '') {
            return $this->failure('OAuth credentials or authorization code are missing.');
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 25,
            'body' => [
                'client_id'     => self::clientId(),
                'client_secret' => self::clientSecret(),
                'code'          => $code,
                'redirect_uri'  => self::redirectUri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);
        $result = $this->decodeResponse($response, 'Google OAuth authorization failed.');
        if (!$result['success']) {
            return $result;
        }

        $refresh_token = sanitize_text_field((string)($result['data']['refresh_token'] ?? ''));
        if ($refresh_token === '') {
            return $this->failure('Google did not return an offline refresh token. Reconnect and approve the Business Profile permission.');
        }

        update_option('wnq_gbp_refresh_token', $refresh_token, false);
        update_option('wnq_gbp_connected_at', current_time('mysql'), false);
        delete_option('wnq_gbp_last_error');

        $access_token = sanitize_text_field((string)($result['data']['access_token'] ?? ''));
        if ($access_token !== '') {
            set_transient(
                self::ACCESS_TOKEN_TRANSIENT,
                $access_token,
                max(60, (int)($result['data']['expires_in'] ?? 3600) - 120)
            );
            $this->storeConnectedEmail($access_token);
        }

        return ['success' => true];
    }

    public static function disconnect(): void
    {
        self::clearConnection(false);
    }

    public function syncAccountsAndLocations(): array
    {
        $token = $this->accessToken();
        if ($token === '') {
            return $this->failure($this->lastError('Google Business Profile is not connected.'));
        }

        $accounts = [];
        $page_token = '';
        do {
            $url = add_query_arg(array_filter([
                'pageSize'  => 20,
                'pageToken' => $page_token,
            ]), self::ACCOUNTS_URL);
            $result = $this->authorizedRequest($url, $token);
            if (!$result['success']) {
                update_option('wnq_gbp_last_error', $result['error'], false);
                return $result;
            }

            foreach ((array)($result['data']['accounts'] ?? []) as $account) {
                $name = self::normalizeAccountName((string)($account['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $accounts[$name] = [
                    'name'               => $name,
                    'account_name'       => sanitize_text_field((string)($account['accountName'] ?? $name)),
                    'type'               => sanitize_key((string)($account['type'] ?? '')),
                    'role'               => sanitize_key((string)($account['role'] ?? '')),
                    'verification_state' => sanitize_key((string)($account['verificationState'] ?? '')),
                ];
            }
            $page_token = sanitize_text_field((string)($result['data']['nextPageToken'] ?? ''));
        } while ($page_token !== '');

        $locations = [];
        $location_errors = [];
        foreach ($accounts as $account) {
            $result = $this->locationsForAccount($account['name'], $token);
            if (!$result['success']) {
                $location_errors[] = $account['account_name'] . ': ' . $result['error'];
                continue;
            }
            foreach ($result['locations'] as $location) {
                $key = (string)$location['location_name'];
                if (!isset($locations[$key])) {
                    $locations[$key] = $location + [
                        'account_name'          => $account['name'],
                        'account_display_name'  => $account['account_name'],
                    ];
                }
            }
        }

        update_option('wnq_gbp_synced_accounts', array_values($accounts), false);
        update_option('wnq_gbp_synced_locations', array_values($locations), false);
        update_option('wnq_gbp_last_sync', current_time('mysql'), false);
        update_option('wnq_gbp_last_error', implode("\n", array_slice($location_errors, 0, 8)), false);
        self::autoMapClients(array_values($locations));

        if (!empty($accounts) && empty($locations) && !empty($location_errors)) {
            return $this->failure(implode("\n", array_slice($location_errors, 0, 4)), [
                'accounts'  => count($accounts),
                'locations' => 0,
            ]);
        }

        return [
            'success'        => true,
            'accounts'       => count($accounts),
            'locations'      => count($locations),
            'warnings'       => $location_errors,
        ];
    }

    public static function syncedAccounts(): array
    {
        $accounts = get_option('wnq_gbp_synced_accounts', []);
        return is_array($accounts) ? $accounts : [];
    }

    public static function syncedLocations(): array
    {
        $locations = get_option('wnq_gbp_synced_locations', []);
        return is_array($locations) ? $locations : [];
    }

    public static function clientMappings(): array
    {
        $mappings = get_option('wnq_gbp_client_mappings', []);
        return is_array($mappings) ? $mappings : [];
    }

    public static function mappingForClient(string $client_id): array
    {
        $mappings = self::clientMappings();
        return is_array($mappings[$client_id] ?? null) ? $mappings[$client_id] : [];
    }

    public static function saveClientMapping(string $client_id, string $account_name, string $location_name): bool
    {
        if ($client_id === '' || !Client::getByClientId($client_id)) {
            return false;
        }

        $mappings = self::clientMappings();
        if ($account_name === '' || $location_name === '') {
            unset($mappings[$client_id]);
            update_option('wnq_gbp_client_mappings', $mappings, false);
            return true;
        }

        foreach (self::syncedLocations() as $location) {
            if (($location['account_name'] ?? '') !== $account_name || ($location['location_name'] ?? '') !== $location_name) {
                continue;
            }
            $mappings[$client_id] = [
                'account_name'         => self::normalizeAccountName($account_name),
                'location_name'        => self::normalizeLocationName($location_name),
                'location_title'       => sanitize_text_field((string)($location['title'] ?? '')),
                'address'              => sanitize_text_field((string)($location['address'] ?? '')),
                'account_display_name' => sanitize_text_field((string)($location['account_display_name'] ?? '')),
                'mapped_at'            => current_time('mysql'),
            ];
            update_option('wnq_gbp_client_mappings', $mappings, false);
            return true;
        }

        return false;
    }

    public function publishLocalPost(array $post): array
    {
        $token = $this->accessToken();
        if ($token === '') {
            return $this->failure($this->lastError('Google Business Profile is not connected.'), ['configuration_error' => true]);
        }

        $mapping = self::mappingForClient(sanitize_text_field((string)($post['client_id'] ?? '')));
        $account_name = self::normalizeAccountName((string)($post['gbp_account_id'] ?: ($mapping['account_name'] ?? '')));
        $location_name = self::normalizeLocationName((string)($post['gbp_location_id'] ?: ($mapping['location_name'] ?? '')));
        if ($account_name === '' || $location_name === '') {
            return $this->failure('Choose a synced Google Business Profile location for this client before publishing.', ['configuration_error' => true]);
        }

        $body = $this->localPostBody($post);
        if (!$body['success']) {
            return $body;
        }

        $parent = $account_name . '/' . $location_name;
        $url = self::POSTS_URL . '/' . $parent . '/localPosts';
        $result = $this->authorizedRequest($url, $token, 'POST', $body['data']);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success'    => true,
            'post_name'  => sanitize_text_field((string)($result['data']['name'] ?? '')),
            'search_url' => esc_url_raw((string)($result['data']['searchUrl'] ?? '')),
            'state'      => sanitize_key((string)($result['data']['state'] ?? '')),
        ];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function locationsForAccount(string $account_name, string $token): array
    {
        $locations = [];
        $page_token = '';
        do {
            $url = self::LOCATIONS_URL . '/' . $account_name . '/locations';
            $url = add_query_arg(array_filter([
                'pageSize'  => 100,
                'pageToken' => $page_token,
                'readMask'  => 'name,title,storeCode,metadata,phoneNumbers,websiteUri,storefrontAddress',
                'orderBy'   => 'title',
            ]), $url);
            $result = $this->authorizedRequest($url, $token);
            if (!$result['success']) {
                return $result;
            }

            foreach ((array)($result['data']['locations'] ?? []) as $location) {
                $name = self::normalizeLocationName((string)($location['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $locations[] = [
                    'location_name' => $name,
                    'title'         => sanitize_text_field((string)($location['title'] ?? $name)),
                    'store_code'    => sanitize_text_field((string)($location['storeCode'] ?? '')),
                    'website_uri'   => esc_url_raw((string)($location['websiteUri'] ?? '')),
                    'phone'         => sanitize_text_field((string)($location['phoneNumbers']['primaryPhone'] ?? '')),
                    'address'       => self::formatAddress((array)($location['storefrontAddress'] ?? [])),
                    'maps_uri'      => esc_url_raw((string)($location['metadata']['mapsUri'] ?? '')),
                    'place_id'      => sanitize_text_field((string)($location['metadata']['placeId'] ?? '')),
                ];
            }
            $page_token = sanitize_text_field((string)($result['data']['nextPageToken'] ?? ''));
        } while ($page_token !== '');

        return ['success' => true, 'locations' => $locations];
    }

    private function localPostBody(array $post): array
    {
        $summary = trim(html_entity_decode(wp_strip_all_tags((string)($post['summary'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($summary === '') {
            return $this->failure('Post copy is required.');
        }

        $type = sanitize_key((string)($post['post_type'] ?? 'update'));
        $body = [
            'languageCode' => 'en-US',
            'summary'      => $summary,
            'topicType'    => match ($type) {
                'event' => 'EVENT',
                'offer' => 'OFFER',
                default => 'STANDARD',
            },
        ];

        $image_url = esc_url_raw((string)($post['image_url'] ?? ''));
        if ($image_url !== '') {
            $body['media'] = [[
                'mediaFormat' => 'PHOTO',
                'sourceUrl'   => $image_url,
            ]];
        }

        if ($type === 'event') {
            $event_title = sanitize_text_field((string)($post['event_title'] ?? ''));
            $event_start = $this->googleTimeInterval((string)($post['event_start'] ?? ''));
            $event_end = $this->googleTimeInterval((string)($post['event_end'] ?? ''));
            if ($event_title === '' || !$event_start || !$event_end) {
                return $this->failure('Event posts require an event title, start date/time, and end date/time.');
            }
            if ($event_end['timestamp'] <= $event_start['timestamp']) {
                return $this->failure('Event end date/time must be later than the event start date/time.');
            }
            $body['event'] = [
                'title'    => $event_title,
                'schedule' => [
                    'startDate' => $event_start['date'],
                    'startTime' => $event_start['time'],
                    'endDate'   => $event_end['date'],
                    'endTime'   => $event_end['time'],
                ],
            ];
        }

        if ($type === 'offer') {
            $offer = array_filter([
                'couponCode'      => sanitize_text_field((string)($post['offer_coupon_code'] ?? '')),
                'redeemOnlineUrl' => esc_url_raw((string)($post['offer_redeem_url'] ?? '')),
                'termsConditions' => sanitize_textarea_field((string)($post['offer_terms'] ?? '')),
            ], static fn($value) => $value !== '');
            if (!empty($offer)) {
                $body['offer'] = $offer;
            }
        }

        $cta_type = strtoupper(sanitize_key((string)($post['cta_type'] ?? '')));
        if (in_array($cta_type, ['BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'], true)) {
            $cta = ['actionType' => $cta_type];
            $cta_url = esc_url_raw((string)($post['cta_url'] ?? ''));
            if ($cta_type !== 'CALL') {
                if ($cta_url === '') {
                    return ['success' => true, 'data' => $body];
                }
                $cta['url'] = $cta_url;
            }
            $body['callToAction'] = $cta;
        }

        return ['success' => true, 'data' => $body];
    }

    private function googleTimeInterval(string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value, wp_timezone());
        } catch (\Throwable) {
            return null;
        }

        return [
            'date' => [
                'year'  => (int)$date->format('Y'),
                'month' => (int)$date->format('n'),
                'day'   => (int)$date->format('j'),
            ],
            'time' => [
                'hours'   => (int)$date->format('G'),
                'minutes' => (int)$date->format('i'),
                'seconds' => 0,
                'nanos'   => 0,
            ],
            'timestamp' => $date->getTimestamp(),
        ];
    }

    private function accessToken(): string
    {
        $cached = get_transient(self::ACCESS_TOKEN_TRANSIENT);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if (!self::credentialsConfigured() || !self::connected()) {
            $this->errors[] = 'Google Business Profile OAuth credentials are incomplete.';
            return '';
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'body' => [
                'client_id'     => self::clientId(),
                'client_secret' => self::clientSecret(),
                'refresh_token' => self::refreshToken(),
                'grant_type'    => 'refresh_token',
            ],
        ]);
        $result = $this->decodeResponse($response, 'Google OAuth token refresh failed.');
        if (!$result['success']) {
            update_option('wnq_gbp_last_error', $result['error'], false);
            return '';
        }

        $token = sanitize_text_field((string)($result['data']['access_token'] ?? ''));
        if ($token === '') {
            $this->errors[] = 'Google OAuth did not return an access token.';
            return '';
        }
        set_transient(self::ACCESS_TOKEN_TRANSIENT, $token, max(60, (int)($result['data']['expires_in'] ?? 3600) - 120));
        return $token;
    }

    private function authorizedRequest(string $url, string $token, string $method = 'GET', ?array $body = null): array
    {
        $args = [
            'timeout' => 30,
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        return $this->decodeResponse($response, 'Google Business Profile API request failed.');
    }

    private function decodeResponse($response, string $fallback): array
    {
        if (is_wp_error($response)) {
            return $this->failure($response->get_error_message());
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : [];
        if ($code < 200 || $code >= 300) {
            $message = sanitize_textarea_field((string)($body['error']['message'] ?? $fallback . ' HTTP ' . $code));
            return $this->failure($message, [
                'http_code' => $code,
                'retryable' => $code === 429,
            ]);
        }

        return ['success' => true, 'data' => $body, 'http_code' => $code];
    }

    private function storeConnectedEmail(string $token): void
    {
        $result = $this->authorizedRequest(self::USERINFO_URL, $token);
        if ($result['success'] && is_email($result['data']['email'] ?? '')) {
            update_option('wnq_gbp_connected_email', sanitize_email($result['data']['email']), false);
        }
    }

    private static function clearConnection(bool $clear_synced): void
    {
        delete_option('wnq_gbp_refresh_token');
        delete_option('wnq_gbp_connected_email');
        delete_option('wnq_gbp_connected_at');
        delete_option('wnq_gbp_last_error');
        delete_transient(self::ACCESS_TOKEN_TRANSIENT);
        if ($clear_synced) {
            delete_option('wnq_gbp_synced_accounts');
            delete_option('wnq_gbp_synced_locations');
            delete_option('wnq_gbp_client_mappings');
            delete_option('wnq_gbp_last_sync');
        }
    }

    private static function autoMapClients(array $locations): void
    {
        if (empty($locations)) {
            return;
        }

        $mappings = self::clientMappings();
        foreach (Client::getByStatus('active') as $client) {
            $client_id = sanitize_text_field((string)($client['client_id'] ?? ''));
            if ($client_id === '' || !empty($mappings[$client_id])) {
                continue;
            }

            $best = null;
            $best_score = 0;
            foreach ($locations as $location) {
                $score = self::matchScore($client, $location);
                if ($score > $best_score) {
                    $best = $location;
                    $best_score = $score;
                }
            }
            if ($best && $best_score >= 78) {
                $mappings[$client_id] = [
                    'account_name'         => sanitize_text_field((string)$best['account_name']),
                    'location_name'        => sanitize_text_field((string)$best['location_name']),
                    'location_title'       => sanitize_text_field((string)$best['title']),
                    'address'              => sanitize_text_field((string)($best['address'] ?? '')),
                    'account_display_name' => sanitize_text_field((string)($best['account_display_name'] ?? '')),
                    'mapped_at'            => current_time('mysql'),
                    'auto_matched'         => 1,
                ];
            }
        }
        update_option('wnq_gbp_client_mappings', $mappings, false);
    }

    private static function matchScore(array $client, array $location): int
    {
        $client_domain = self::domain((string)($client['website'] ?? ''));
        $location_domain = self::domain((string)($location['website_uri'] ?? ''));
        if ($client_domain !== '' && $client_domain === $location_domain) {
            return 100;
        }

        $left = self::normalizeBusinessName((string)($client['company'] ?: ($client['name'] ?? '')));
        $right = self::normalizeBusinessName((string)($location['title'] ?? ''));
        if ($left === '' || $right === '') {
            return 0;
        }
        if ($left === $right) {
            return 98;
        }
        if (str_contains($left, $right) || str_contains($right, $left)) {
            return 90;
        }
        similar_text($left, $right, $percent);
        return (int)round($percent);
    }

    private static function normalizeBusinessName(string $name): string
    {
        $name = strtolower(remove_accents($name));
        $name = preg_replace('/\b(llc|inc|co|company|the|and|corp|corporation|ltd|limited)\b/i', ' ', $name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', (string)$name);
        return trim((string)preg_replace('/\s+/', ' ', (string)$name));
    }

    private static function domain(string $url): string
    {
        $host = strtolower((string)wp_parse_url($url, PHP_URL_HOST));
        return preg_replace('/^www\./', '', $host) ?: '';
    }

    private static function formatAddress(array $address): string
    {
        $parts = [];
        foreach ((array)($address['addressLines'] ?? []) as $line) {
            if (is_scalar($line) && trim((string)$line) !== '') {
                $parts[] = trim((string)$line);
            }
        }
        $city_line = trim(implode(', ', array_filter([
            (string)($address['locality'] ?? ''),
            (string)($address['administrativeArea'] ?? ''),
            (string)($address['postalCode'] ?? ''),
        ])));
        if ($city_line !== '') {
            $parts[] = $city_line;
        }
        return sanitize_text_field(implode(', ', $parts));
    }

    private static function normalizeAccountName(string $value): string
    {
        $value = trim(sanitize_text_field($value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('#^accounts/#', '', $value);
        $value = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$value);
        return $value !== '' ? 'accounts/' . $value : '';
    }

    private static function normalizeLocationName(string $value): string
    {
        $value = trim(sanitize_text_field($value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('#^(?:accounts/[^/]+/)?locations/#', '', $value);
        $value = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$value);
        return $value !== '' ? 'locations/' . $value : '';
    }

    private static function clientId(): string
    {
        if (self::hasGbpCredentialPair()) {
            return trim((string)get_option('wnq_gbp_oauth_client_id', ''));
        }
        return trim((string)get_option('wnq_google_ads_oauth_client_id', ''));
    }

    private static function clientSecret(): string
    {
        if (self::hasGbpCredentialPair()) {
            return trim((string)get_option('wnq_gbp_oauth_client_secret', ''));
        }
        return trim((string)get_option('wnq_google_ads_oauth_client_secret', ''));
    }

    private static function hasGbpCredentialPair(): bool
    {
        return trim((string)get_option('wnq_gbp_oauth_client_id', '')) !== ''
            && trim((string)get_option('wnq_gbp_oauth_client_secret', '')) !== '';
    }

    private static function refreshToken(): string
    {
        return trim((string)get_option('wnq_gbp_refresh_token', ''));
    }

    private function lastError(string $fallback): string
    {
        return $this->errors ? (string)end($this->errors) : $fallback;
    }

    private function failure(string $message, array $extra = []): array
    {
        $message = sanitize_textarea_field($message);
        $this->errors[] = $message;
        return array_merge(['success' => false, 'error' => $message], $extra);
    }
}
