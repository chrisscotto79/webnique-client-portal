<?php
/**
 * Lead Email Extractor
 *
 * Crawls a business website to find the best contact email address.
 * Tries the homepage, /contact, /contact-us, /about, /about-us in order.
 * Prefers non-generic addresses (owner@, firstname@) over generic ones
 * (info@, support@, admin@, etc.).
 *
 * @package WebNique Portal
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
     * @return array{email: string, source: string, all_found: string[]}
     */
    public static function extractEmail(string $base_url, string $homepage_html = ''): array
    {
        $base_url = rtrim($base_url, '/');

        // Use pre-fetched homepage HTML first
        if ($homepage_html) {
            $emails = self::extractEmailsFromHtml($homepage_html);
            if (!empty($emails)) {
                return ['email' => self::pickBest($emails), 'source' => $base_url, 'all_found' => $emails];
            }
        }

        $paths = $homepage_html
            ? ['/contact', '/contact-us', '/about', '/about-us']
            : ['', '/contact', '/contact-us', '/about', '/about-us'];

        foreach ($paths as $path) {
            $emails = self::fetchEmailsFromUrl($base_url . $path);
            if (!empty($emails)) {
                $best = self::pickBest($emails);
                return ['email' => $best, 'source' => $base_url . $path, 'all_found' => $emails];
            }
        }

        return ['email' => '', 'source' => '', 'all_found' => []];
    }

    // ── Private ─────────────────────────────────────────────────────────────

    private static function fetchEmailsFromUrl(string $url): array
    {
        $response = wp_remote_get($url, [
            'timeout'    => 8,
            'user-agent' => 'Mozilla/5.0 (compatible; WebNique/1.0; +https://webnique.com)',
            'sslverify'  => false,
            'redirection'=> 3,
        ]);

        if (is_wp_error($response)) return [];

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) return [];

        $html = wp_remote_retrieve_body($response);
        return self::extractEmailsFromHtml($html);
    }

    private static function extractEmailsFromHtml(string $html): array
    {
        $emails = [];

        // 1. Extract from mailto: links first (most reliable)
        if (preg_match_all('/mailto:([a-zA-Z0-9_.+\-]+@[a-zA-Z0-9\-]+\.[a-zA-Z0-9\-.]+)/i', $html, $m)) {
            $emails = array_merge($emails, $m[1]);
        }

        // 2. Extract from visible text (strip tags)
        $text = strip_tags($html);
        if (preg_match_all('/[a-zA-Z0-9_.+\-]+@[a-zA-Z0-9\-]+\.[a-zA-Z0-9\-.]{2,}/i', $text, $m)) {
            $emails = array_merge($emails, $m[1]);
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

        // Filter image/file extensions that sometimes get caught by the regex
        if (preg_match('/\.(png|jpg|jpeg|gif|svg|webp|pdf|zip)$/i', $email)) return false;

        // Filter placeholder domains
        $domain = explode('@', $email)[1] ?? '';
        if (in_array($domain, ['example.com', 'test.com', 'domain.com', 'email.com', 'yoursite.com'], true)) {
            return false;
        }

        return true;
    }

    private static function pickBest(array $emails): string
    {
        if (count($emails) === 1) return $emails[0];

        // Score: 0 = likely decision-maker, 1 = generic
        $scored = [];
        foreach ($emails as $email) {
            $prefix         = explode('@', $email)[0];
            $scored[$email] = in_array($prefix, self::GENERIC_PREFIXES, true) ? 1 : 0;
        }

        asort($scored);
        return (string)array_key_first($scored);
    }
}
