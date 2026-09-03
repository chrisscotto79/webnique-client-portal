<?php
/**
 * Encrypted storage for shared Google Ads API credentials.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class GoogleAdsCredentials
{
    private const OPTION_KEY = 'wnq_ppc_google_ads_credentials_v1';

    private const LEGACY_OPTIONS = [
        'developer_token'    => 'wnq_google_ads_developer_token',
        'manager_customer_id'=> 'wnq_google_ads_manager_customer_id',
        'oauth_client_id'    => 'wnq_google_ads_oauth_client_id',
        'oauth_client_secret'=> 'wnq_google_ads_oauth_client_secret',
        'refresh_token'      => 'wnq_google_ads_refresh_token',
        'access_level'       => 'wnq_google_ads_access_level',
    ];

    public static function get(): array
    {
        $stored = get_option(self::OPTION_KEY, '');
        if (is_string($stored) && $stored !== '') {
            $decrypted = self::decrypt($stored);
            $decoded = $decrypted !== '' ? json_decode($decrypted, true) : null;
            if (is_array($decoded)) {
                return self::normalize($decoded);
            }
        }

        return self::legacyValues();
    }

    public static function save(array $values, bool $preserve_blanks = true): bool
    {
        $current = self::get();
        foreach (array_keys(self::LEGACY_OPTIONS) as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = trim((string)$values[$key]);
            if ($preserve_blanks && $value === '') {
                continue;
            }
            $current[$key] = $value;
        }

        $current = self::normalize($current);
        $encrypted = self::encrypt(wp_json_encode($current));
        if ($encrypted === '') {
            return false;
        }

        $saved = update_option(self::OPTION_KEY, $encrypted, false);
        if (!$saved && get_option(self::OPTION_KEY, '') !== $encrypted) {
            return false;
        }

        self::deleteLegacySecrets();
        return true;
    }

    public static function migrateLegacy(): bool
    {
        if ((string)get_option(self::OPTION_KEY, '') !== '') {
            return true;
        }

        $legacy = self::legacyValues();
        $has_values = false;
        foreach (['developer_token', 'manager_customer_id', 'oauth_client_id', 'oauth_client_secret', 'refresh_token'] as $key) {
            $has_values = $has_values || $legacy[$key] !== '';
        }

        return !$has_values || self::save($legacy, false);
    }

    public static function status(): array
    {
        $credentials = self::get();
        return [
            'configured'                => self::isConfigured($credentials),
            'has_developer_token'        => $credentials['developer_token'] !== '',
            'has_manager_customer_id'    => $credentials['manager_customer_id'] !== '',
            'has_oauth_client_id'        => $credentials['oauth_client_id'] !== '',
            'has_oauth_client_secret'    => $credentials['oauth_client_secret'] !== '',
            'has_refresh_token'          => $credentials['refresh_token'] !== '',
            'manager_customer_id'        => $credentials['manager_customer_id'],
            'access_level'               => $credentials['access_level'],
        ];
    }

    public static function isConfigured(?array $credentials = null): bool
    {
        $credentials = $credentials ?? self::get();
        foreach (['developer_token', 'manager_customer_id', 'oauth_client_id', 'oauth_client_secret', 'refresh_token'] as $key) {
            if (trim((string)($credentials[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    private static function normalize(array $values): array
    {
        $access_level = sanitize_key((string)($values['access_level'] ?? 'test'));
        if (!in_array($access_level, ['test', 'explorer', 'basic', 'standard'], true)) {
            $access_level = 'test';
        }

        return [
            'developer_token'     => sanitize_text_field((string)($values['developer_token'] ?? '')),
            'manager_customer_id' => preg_replace('/\D+/', '', (string)($values['manager_customer_id'] ?? '')) ?: '',
            'oauth_client_id'     => sanitize_text_field((string)($values['oauth_client_id'] ?? '')),
            'oauth_client_secret' => sanitize_text_field((string)($values['oauth_client_secret'] ?? '')),
            'refresh_token'       => sanitize_text_field((string)($values['refresh_token'] ?? '')),
            'access_level'        => $access_level,
        ];
    }

    private static function legacyValues(): array
    {
        $values = [];
        foreach (self::LEGACY_OPTIONS as $key => $option) {
            $values[$key] = (string)get_option($option, $key === 'access_level' ? 'test' : '');
        }
        return self::normalize($values);
    }

    private static function deleteLegacySecrets(): void
    {
        foreach (self::LEGACY_OPTIONS as $option) {
            delete_option($option);
        }
    }

    private static function encrypt(string $plaintext): string
    {
        if ($plaintext === '' || !function_exists('openssl_encrypt')) {
            return '';
        }
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', self::encryptionKey(), OPENSSL_RAW_DATA, $iv);
        if (!is_string($ciphertext)) {
            return '';
        }
        return base64_encode($iv . $ciphertext);
    }

    private static function decrypt(string $payload): string
    {
        if (!function_exists('openssl_decrypt')) {
            return '';
        }
        $decoded = base64_decode($payload, true);
        if (!is_string($decoded) || strlen($decoded) <= 16) {
            return '';
        }
        $plaintext = openssl_decrypt(substr($decoded, 16), 'aes-256-cbc', self::encryptionKey(), OPENSSL_RAW_DATA, substr($decoded, 0, 16));
        return is_string($plaintext) ? $plaintext : '';
    }

    private static function encryptionKey(): string
    {
        return hash('sha256', wp_salt('auth') . '|wnq-ppc-google-ads', true);
    }
}
