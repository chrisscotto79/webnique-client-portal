<?php
/**
 * Lead SEO Scorer
 *
 * Fetches a business website and scores its SEO deficiencies.
 * Each issue found adds 1 point. Higher score = worse SEO = better prospect.
 *
 * Issues checked (max score 7):
 *   missing_title          — No <title> tag or empty
 *   missing_meta_description — No meta description
 *   missing_h1             — No <h1> tag on the page
 *   thin_content           — Body text under 300 words
 *   no_blog                — No blog/news/articles link found
 *   no_schema              — No structured data (JSON-LD or microdata)
 *   no_viewport            — No mobile viewport meta tag
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class LeadSEOScorer
{
    /**
     * Fetch and score a website's SEO quality.
     *
     * @param  string $url Full URL including scheme
     * @return array{score: int, issues: string[], ok: bool}
     */
    public static function scoreWebsite(string $url): array
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['score' => 0, 'issues' => [], 'ok' => false];
        }

        $response = wp_remote_get($url, [
            'timeout'    => 12,
            'user-agent' => 'Mozilla/5.0 (compatible; WebNique/1.0; +https://webnique.com)',
            'sslverify'  => false,
            'redirection'=> 5,
        ]);

        if (is_wp_error($response)) {
            return ['score' => 0, 'issues' => ['site_unreachable'], 'ok' => false];
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return ['score' => 0, 'issues' => ['http_' . $code], 'ok' => false];
        }

        $html = wp_remote_retrieve_body($response);
        if (!$html) {
            return ['score' => 0, 'issues' => ['empty_response'], 'ok' => false];
        }

        return self::analyzeHtml($html);
    }

    // ── Private ─────────────────────────────────────────────────────────────

    private static function analyzeHtml(string $html): array
    {
        $issues = [];

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // ── Title tag ────────────────────────────────────────────────────────
        $title_nodes = $xpath->query('//title');
        if ($title_nodes->length === 0 || trim($title_nodes->item(0)->textContent) === '') {
            $issues[] = 'missing_title';
        }

        // ── Meta description ─────────────────────────────────────────────────
        $meta_desc = $xpath->query('//meta[@name="description"]/@content');
        if ($meta_desc->length === 0 || trim($meta_desc->item(0)->nodeValue ?? '') === '') {
            $issues[] = 'missing_meta_description';
        }

        // ── H1 ───────────────────────────────────────────────────────────────
        if ($xpath->query('//h1')->length === 0) {
            $issues[] = 'missing_h1';
        }

        // ── Thin content ─────────────────────────────────────────────────────
        $body_nodes = $xpath->query('//body');
        if ($body_nodes->length > 0) {
            $text       = strip_tags($body_nodes->item(0)->textContent);
            $word_count = str_word_count(preg_replace('/\s+/', ' ', trim($text)));
            if ($word_count < 300) {
                $issues[] = 'thin_content';
            }
        }

        // ── Blog / news section ──────────────────────────────────────────────
        $has_blog  = false;
        $all_links = $xpath->query('//a[@href]/@href');
        foreach ($all_links as $href) {
            if (preg_match('#/(blog|news|articles|posts|insights|updates)(/|$)#i', $href->nodeValue)) {
                $has_blog = true;
                break;
            }
        }
        if (!$has_blog) {
            $issues[] = 'no_blog';
        }

        // ── Schema markup ────────────────────────────────────────────────────
        $schema = $xpath->query('//script[@type="application/ld+json"]|//*[@itemtype]');
        if ($schema->length === 0) {
            $issues[] = 'no_schema';
        }

        // ── Mobile viewport ──────────────────────────────────────────────────
        if ($xpath->query('//meta[@name="viewport"]')->length === 0) {
            $issues[] = 'no_viewport';
        }

        return ['score' => count($issues), 'issues' => $issues, 'ok' => true];
    }

    /**
     * Human-readable label for each issue key.
     */
    public static function issueLabel(string $issue): string
    {
        return match ($issue) {
            'missing_title'            => 'No title tag',
            'missing_meta_description' => 'No meta description',
            'missing_h1'               => 'Missing H1',
            'thin_content'             => 'Thin content (<300 words)',
            'no_blog'                  => 'No blog section',
            'no_schema'                => 'No schema markup',
            'no_viewport'              => 'No mobile viewport',
            'site_unreachable'         => 'Site unreachable',
            default                    => $issue,
        };
    }
}
