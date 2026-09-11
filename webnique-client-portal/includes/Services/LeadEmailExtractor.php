<?php
/**
 * Lead Email Extractor
 *
 * Crawls a business website to find the best contact email address.
 * Tries the supplied page and up to three contact/about fallback pages.
 * Prefers non-generic addresses (owner@, firstname@) over generic ones
 * (info@, support@, admin@, etc.).
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class LeadEmailExtractor
{
    /** Email prefixes considered generic — deprioritised in scoring */
    private const GENERIC_PREFIXES = [
        'info', 'support', 'admin', 'sales', 'contact', 'hello',
        'noreply', 'no-reply', 'team', 'office', 'mail', 'email',
        'webmaster', 'help', 'service', 'enquiries', 'enquiry',
        'customerservice', 'customercare', 'billing',
    ];

    /**
     * Find the best email address on a business website.
     *
     * @param  string $base_url      Root URL of the website (scheme + domain)
     * @param  string $homepage_html Already-fetched homepage HTML to avoid refetch
     * @param  bool $homepage_attempted Caller already requested homepage, even if empty/failed
     * @return array{email: string, source: string, all_found: string[]}
     */
    public static function extractEmail(string $base_url, string $homepage_html = '', bool $homepage_attempted = false): array
    {
        $base_url = rtrim($base_url, '/');
        $parts = wp_parse_url($base_url);
        $root = !empty($parts['host']) ? ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : $base_url;

        // Use pre-fetched homepage HTML first
        if ($homepage_html) {
            $emails = self::extractEmailsFromHtml($homepage_html);
            if (!empty($emails)) {
                return ['email' => self::pickBest($emails, $base_url), 'source' => $base_url, 'all_found' => $emails];
            }
        }

        // Bounded public-page lookup; no login, form submission or mailbox probing.
        $paths = ($homepage_html || $homepage_attempted)
            ? ['/contact', '/contact-us', '/about']
            : ['', '/contact', '/contact-us', '/about'];

        foreach ($paths as $path) {
            $url = $path === '' ? $base_url : $root . $path;
            $emails = self::fetchEmailsFromUrl($url);
            if (!empty($emails)) {
                $best = self::pickBest($emails, $base_url);
                return ['email' => $best, 'source' => $url, 'all_found' => $emails];
            }
        }

        return ['email' => '', 'source' => '', 'all_found' => []];
    }

    // ── Private ─────────────────────────────────────────────────────────────

    private static function fetchEmailsFromUrl(string $url): array
    {
        $response = wp_safe_remote_get($url, [
            'timeout'             => 4,
            'user-agent'          => 'Mozilla/5.0 (compatible; GoldenWebMarketing/1.0; +https://goldenwebmarketing.com)',
            'sslverify'           => true,
            'redirection'         => 2,
            'limit_response_size' => 256000,
        ]);

        if (is_wp_error($response)) return [];

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) return [];

        $html = wp_remote_retrieve_body($response);
        return self::extractEmailsFromHtml($html);
    }

    private static function extractEmailsFromHtml(string $html): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Structured-data emails are legitimate page-source evidence, unlike arbitrary script strings.
        $schemaEmails = [];
        preg_match_all('#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $scripts);
        $walk = static function ($node) use (&$walk, &$schemaEmails): void {
            if (!is_array($node)) { return; }
            if (is_string($node['email'] ?? null)) { $schemaEmails[] = preg_replace('/^mailto:/i', '', trim($node['email'])); }
            foreach ($node as $child) { if (is_array($child)) { $walk($child); } }
        };
        foreach ($scripts[1] ?? [] as $json) { $walk(json_decode($json, true)); }
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $emails = $schemaEmails;

        // 1. Extract from mailto: links first (most reliable)
        if (preg_match_all('/mailto:([a-zA-Z0-9_.+\-]+@[a-zA-Z0-9\-]+\.[a-zA-Z0-9\-.]+)/i', $html, $m) && is_array($m[1])) {
            $emails = array_merge($emails, $m[1]);
        }

        // 2. Extract from visible text (strip tags).
        // Keep adjacent elements apart: </p><p>Call must not become .comcall.
        $text = preg_replace('/<[^>]*>/', ' ', $html);
        if (preg_match_all(
            '/(?<![a-zA-Z0-9])[a-zA-Z0-9][a-zA-Z0-9_.+\-]*@[a-zA-Z0-9][a-zA-Z0-9\-]*(?:\.[a-zA-Z0-9\-]+)*\.[a-zA-Z]{2,63}(?![a-zA-Z])/i',
            $text, $m
        ) && is_array($m[0])) {
            $emails = array_merge($emails, $m[0]);
        }

        // Normalise + deduplicate
        $emails = array_unique(array_map('strtolower', $emails));

        // Remove clearly invalid addresses
        $emails = array_values(array_filter($emails, [self::class, 'isValidEmail']));

        return $emails;
    }

    private static function isValidEmail(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        if (preg_match('/^(?:no[._-]?reply|donotreply)@/i', $email)) return false;

        // Filter image/file extensions that sometimes get caught by the regex
        if (preg_match('/\.(png|jpg|jpeg|gif|svg|webp|pdf|zip)$/i', $email)) return false;

        // Filter placeholder domains
        $domain = explode('@', $email)[1] ?? '';
        if (in_array($domain, ['example.com', 'test.com', 'domain.com', 'email.com', 'yoursite.com'], true)) {
            return false;
        }

        return true;
    }

    private static function pickBest(array $emails, string $website = ''): string
    {
        if (count($emails) === 1) return $emails[0];

        // Score: 0 = likely decision-maker, 1 = generic
        $scored = [];
        $host = preg_replace('/^www\./i', '', (string)(wp_parse_url($website)['host'] ?? ''));
        foreach ($emails as $email) {
            $prefix         = explode('@', $email)[0];
            $domain = explode('@', $email)[1] ?? '';
            $scored[$email] = ($host && strcasecmp($domain, $host) === 0 ? 0 : 10)
                + (in_array($prefix, self::GENERIC_PREFIXES, true) ? 1 : 0);
        }

        asort($scored);
        return (string)array_key_first($scored);
    }
}
