<?php
/**
 * Lead Finder Engine
 *
 * Supports two discovery modes:
 *
 * Mode A — ZIP Sweep (Google Maps scraping, no API key):
 *   Phase 1 – startSearch(keyword)
 *     Stores a batch transient containing all FL ZIP codes and returns
 *     immediately. No external HTTP calls.
 *   Phase 2 – processNext(batch_id)
 *     Called once per "unit of work" by the browser JS loop.
 *     Each call does ONE of:
 *       (a) Process a pending candidate (Maps place page + homepage scrape)
 *       (b) Search the next ZIP code via Google Maps HTML scraping
 *
 * Mode B — Manual URL Queue:
 *   Phase 1 – queueManualSearch(params)
 *     Parses and validates the URL list, stores candidates in a transient,
 *     and returns immediately with a batch_id.
 *   Phase 2 – processNextManual(batch_id, filter_params)
 *     Called once per URL by the browser (looped via JS).
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\Lead;

final class LeadFinderEngine
{
    private const BATCH_TTL   = DAY_IN_SECONDS;
    private const QUEUE_TTL   = DAY_IN_SECONDS;
    private const MAX_REVIEWS = 50;

    // ── Mode A Phase 1: Start ZIP Sweep ──────────────────────────────────────

    public static function startSearch(string $keyword): array
    {
        $keyword = sanitize_text_field($keyword);
        if (!$keyword) {
            return ['ok' => false, 'error' => 'Keyword is required'];
        }

        $zips = array_values(array_unique(\WNQ\Data\FloridaZips::getAll()));
        if (empty($zips)) {
            return ['ok' => false, 'error' => 'ZIP code list is empty'];
        }

        $batch_id = wp_generate_uuid4();

        set_transient('wnq_zip_batch_' . $batch_id, [
            'keyword'    => $keyword,
            'zips'       => $zips,
            'zip_index'  => 0,
            'candidates' => [],
            'cand_index' => 0,
            'stats'      => self::emptyZipStats(),
        ], self::BATCH_TTL);

        return [
            'ok'         => true,
            'batch_id'   => $batch_id,
            'total_zips' => count($zips),
        ];
    }

    // ── Mode A Phase 2: Process one unit of work ──────────────────────────────

    public static function processNext(string $batch_id): array
    {
        $batch = get_transient('wnq_zip_batch_' . $batch_id);
        if (!$batch) {
            return ['ok' => false, 'done' => true, 'error' => 'Batch not found or expired'];
        }

        $candidates = $batch['candidates'];
        $cand_index = (int)$batch['cand_index'];

        // (a) Pending candidates → process one
        if ($cand_index < count($candidates)) {
            return self::handleCandidate($batch_id, $batch, $candidates[$cand_index]);
        }

        // (b) No candidates remaining — check if ZIPs remain
        $zips      = $batch['zips'];
        $zip_index = (int)$batch['zip_index'];
        $stats     = $batch['stats'];

        if ($zip_index >= count($zips)) {
            delete_transient('wnq_zip_batch_' . $batch_id);
            return [
                'ok'        => true,
                'done'      => true,
                'zip_index' => count($zips),
                'total_zips'=> count($zips),
                'stats'     => $stats,
                'action'    => 'complete',
            ];
        }

        return self::handleZipSearch($batch_id, $batch);
    }

    // ── Private: Process one candidate ───────────────────────────────────────

    private static function handleCandidate(string $batch_id, array $batch, array $place): array
    {
        $cand_index = (int)$batch['cand_index'];
        $zips       = $batch['zips'];
        $zip_index  = (int)$batch['zip_index'];
        $stats      = $batch['stats'];
        $keyword    = $batch['keyword'];
        $total_zips = count($zips);

        // Advance BEFORE processing — prevents infinite retry on slow/aborted calls
        $batch['cand_index'] = $cand_index + 1;
        $cands_exhausted     = ($cand_index + 1 >= count($batch['candidates']));
        $zips_exhausted      = ($zip_index >= $total_zips);
        $done                = $cands_exhausted && $zips_exhausted;

        if ($done) {
            delete_transient('wnq_zip_batch_' . $batch_id);
        } else {
            set_transient('wnq_zip_batch_' . $batch_id, $batch, self::BATCH_TTL);
        }

        try {
            $outcome = self::processCandidate($place, $keyword);
        } catch (\Throwable $e) {
            $outcome = 'skipped';
        }

        $stats = self::updateStats($stats, $outcome);

        if (!$done) {
            $q = get_transient('wnq_zip_batch_' . $batch_id);
            if ($q) {
                $q['stats'] = $stats;
                set_transient('wnq_zip_batch_' . $batch_id, $q, self::BATCH_TTL);
            }
        }

        return [
            'ok'        => true,
            'done'      => $done,
            'zip_index' => $zip_index,
            'total_zips'=> $total_zips,
            'stats'     => $stats,
            'action'    => 'candidate',
            'outcome'   => $outcome,
        ];
    }

    // ── Private: Scrape one ZIP from Google Maps ──────────────────────────────

    private static function handleZipSearch(string $batch_id, array $batch): array
    {
        $zips       = $batch['zips'];
        $zip_index  = (int)$batch['zip_index'];
        $keyword    = $batch['keyword'];
        $stats      = $batch['stats'];
        $total_zips = count($zips);
        $zip        = $zips[$zip_index];

        // Scrape Google Maps: https://www.google.com/maps/search/keyword+zip
        $data = GoogleMapsClient::search($keyword . ' ' . $zip);

        // Always advance the ZIP index (even on error) to keep the queue moving
        $batch['zip_index']  = $zip_index + 1;
        $batch['candidates'] = [];
        $batch['cand_index'] = 0;

        if (!empty($data['error']) || empty($data['results'])) {
            $stats['zips_searched'] = ($stats['zips_searched'] ?? 0) + 1;
            $batch['stats']         = $stats;
            $done = ($zip_index + 1 >= $total_zips);
            if ($done) {
                delete_transient('wnq_zip_batch_' . $batch_id);
            } else {
                set_transient('wnq_zip_batch_' . $batch_id, $batch, self::BATCH_TTL);
            }
            return [
                'ok'        => true,
                'done'      => $done,
                'zip_index' => $zip_index + 1,
                'total_zips'=> $total_zips,
                'stats'     => $stats,
                'action'    => 'zip_searched',
                'zip'       => $zip,
                'found'     => 0,
            ];
        }

        $raw = $data['results'];

        // Filter: fewer than MAX_REVIEWS
        // If review_count is 0 (not parsed from search page), include it —
        // the place page check will confirm later.
        $filtered = array_values(array_filter($raw, static function (array $p): bool {
            $rc = (int)($p['review_count'] ?? 0);
            return $rc === 0 || $rc < self::MAX_REVIEWS;
        }));

        $batch['candidates'] = $filtered;
        $batch['cand_index'] = 0;

        $stats['zips_searched'] = ($stats['zips_searched'] ?? 0) + 1;
        $batch['stats']         = $stats;

        $done = ($zip_index + 1 >= $total_zips && empty($filtered));

        if ($done) {
            delete_transient('wnq_zip_batch_' . $batch_id);
        } else {
            set_transient('wnq_zip_batch_' . $batch_id, $batch, self::BATCH_TTL);
        }

        return [
            'ok'        => true,
            'done'      => $done,
            'zip_index' => $zip_index + 1,
            'total_zips'=> $total_zips,
            'stats'     => $stats,
            'action'    => 'zip_searched',
            'zip'       => $zip,
            'found'     => count($filtered),
        ];
    }

    // ── Private: Qualify + enrich one candidate ───────────────────────────────

    /**
     * @return string  saved | duplicate | no_phone | no_website | skipped
     */
    private static function processCandidate(array $place, string $keyword): string
    {
        $place_url = $place['place_url'] ?? '';
        $name      = sanitize_text_field($place['name'] ?? '');
        if (!$place_url || !$name) return 'skipped';

        // 1. Fetch place page → phone, website, review_count, address
        $details      = GoogleMapsClient::getPlaceDetails($place_url);
        $phone        = trim($details['phone']   ?? '');
        $website      = trim($details['website'] ?? '');
        $review_count = (int)($details['review_count'] ?? $place['review_count'] ?? 0);
        $rating       = (float)($details['rating']       ?? $place['rating']       ?? 0);
        $raw_address  = trim($details['address']  ?? '');

        // 2. Filter: skip if 50+ reviews (confirm with place page data)
        if ($review_count >= self::MAX_REVIEWS) return 'skipped';

        // 3. Skip if no phone
        if (!$phone) return 'no_phone';

        // 4. Skip if no website
        if (!$website) return 'no_website';

        // 5. Parse address
        $addr = self::parseAddress($raw_address ?: ($place['address'] ?? ''));

        // 6. Dedup by name + city
        if ($addr['city'] && Lead::existsByNameAndCity($name, $addr['city'])) return 'duplicate';

        // 7. Fetch business homepage
        $html = self::fetchHtml($website);

        // 8. Extract email + phone fallback from homepage
        $email = self::extractEmail($html);
        if (!$phone) {
            $phone = self::extractPhoneFromHtml($html);
            if (!$phone) return 'no_phone';
        }

        // 9. Extract social links
        $social = self::extractSocials($html);

        Lead::insert([
            'place_id'         => '',
            'business_name'    => $name,
            'industry'         => $keyword,
            'owner_first'      => '',
            'owner_last'       => '',
            'website'          => esc_url_raw($website),
            'address'          => sanitize_text_field($addr['street']),
            'city'             => sanitize_text_field($addr['city']),
            'state'            => sanitize_text_field($addr['state']),
            'zip'              => sanitize_text_field($addr['zip']),
            'phone'            => sanitize_text_field($phone),
            'email'            => sanitize_email($email),
            'email_source'     => '',
            'rating'           => $rating,
            'review_count'     => $review_count,
            'social_facebook'  => $social['facebook']  ? esc_url_raw($social['facebook'])  : '',
            'social_instagram' => $social['instagram'] ? esc_url_raw($social['instagram']) : '',
            'social_linkedin'  => $social['linkedin']  ? esc_url_raw($social['linkedin'])  : '',
            'social_twitter'   => $social['twitter']   ? esc_url_raw($social['twitter'])   : '',
            'social_youtube'   => $social['youtube']   ? esc_url_raw($social['youtube'])   : '',
            'social_tiktok'    => $social['tiktok']    ? esc_url_raw($social['tiktok'])    : '',
            'seo_score'        => 0,
            'seo_issues'       => [],
            'status'           => 'new',
        ]);

        return 'saved';
    }

    // ── Mode B Phase 1: Queue manual URL search ───────────────────────────────

    /**
     * Parse the raw URL list (one per line) and store candidates in a transient.
     * Returns immediately with a batch_id — no web-crawling happens here.
     *
     * Supported formats (one per line):
     *   https://example.com
     *   Business Name | https://example.com
     *
     * @param array{urls: string, industry: string} $params
     * @return array{ok: bool, batch_id?: string, total?: int, error?: string}
     */
    public static function queueManualSearch(array $params): array
    {
        $raw      = $params['urls']     ?? '';
        $industry = sanitize_text_field($params['industry'] ?? '');

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $raw))
        ));

        $candidates = [];
        foreach ($lines as $line) {
            $name = '';
            $url  = $line;
            if (strpos($line, '|') !== false) {
                [$name, $url] = array_map('trim', explode('|', $line, 2));
            }
            $url = esc_url_raw($url);
            if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) continue;
            $candidates[] = [
                'url'      => $url,
                'name'     => sanitize_text_field($name),
                'industry' => $industry,
            ];
        }

        if (empty($candidates)) {
            return ['ok' => false, 'error' => 'No valid URLs found — make sure each line is a full URL (https://…)'];
        }

        $batch_id = wp_generate_uuid4();
        set_transient('wnq_lead_manual_queue_' . $batch_id, [
            'candidates' => $candidates,
            'next_index' => 0,
            'stats'      => self::emptyManualStats(count($candidates)),
        ], self::QUEUE_TTL);

        return ['ok' => true, 'batch_id' => $batch_id, 'total' => count($candidates)];
    }

    // ── Mode B Phase 2: Process one URL ──────────────────────────────────────

    /**
     * Process the next unprocessed URL from a queued batch.
     * One AJAX call per URL — no PHP timeout risk.
     *
     * @param string $batch_id   Returned by queueManualSearch()
     * @param array  $filter_params min_seo_score
     * @return array{ok: bool, done: bool, progress: int, total: int, stats: array, error?: string}
     */
    public static function processNextManual(string $batch_id, array $filter_params): array
    {
        $queue = get_transient('wnq_lead_manual_queue_' . $batch_id);
        if (!$queue) {
            return ['ok' => false, 'done' => true, 'error' => 'Batch not found or expired'];
        }

        $candidates = $queue['candidates'];
        $next       = (int)$queue['next_index'];
        $total      = count($candidates);
        $stats      = $queue['stats'];

        if ($next >= $total) {
            delete_transient('wnq_lead_manual_queue_' . $batch_id);
            return ['ok' => true, 'done' => true, 'progress' => $total, 'total' => $total, 'stats' => $stats];
        }

        // Advance transient index BEFORE processing to prevent infinite loops if
        // a site causes an abort/timeout — next call will skip to the next URL.
        $done = ($next + 1 >= $total);
        $queue['next_index'] = $next + 1;
        if ($done) {
            delete_transient('wnq_lead_manual_queue_' . $batch_id);
        } else {
            set_transient('wnq_lead_manual_queue_' . $batch_id, $queue, self::QUEUE_TTL);
        }

        try {
            $outcome = self::processSingleUrl(
                $candidates[$next]['url'],
                $candidates[$next]['name'],
                $candidates[$next]['industry'],
                $filter_params
            );
        } catch (\Throwable $e) {
            $outcome = 'low_seo';
            error_log(sprintf(
                'WNQ Lead Finder: candidate %d/%d threw %s: %s (in %s:%d)',
                $next + 1, $total,
                get_class($e), $e->getMessage(),
                $e->getFile(), $e->getLine()
            ));
        }

        $stats = self::updateStats($stats, $outcome);

        if (!$done) {
            $q = get_transient('wnq_lead_manual_queue_' . $batch_id);
            if ($q) {
                $q['stats'] = $stats;
                set_transient('wnq_lead_manual_queue_' . $batch_id, $q, self::QUEUE_TTL);
            }
        }

        return [
            'ok'       => true,
            'done'     => $done,
            'progress' => $next + 1,
            'total'    => $total,
            'stats'    => $stats,
        ];
    }

    // ── Core Processing Logic (Mode B) ────────────────────────────────────────

    /**
     * Process a single URL through the full qualification and enrichment pipeline.
     *
     * @return string One of: duplicate | no_website | franchise | low_seo | saved
     */
    private static function processSingleUrl(
        string $url,
        string $business_name,
        string $industry,
        array $filter_params
    ): string {
        $min_seo = max(0, (int)($filter_params['min_seo_score'] ?? 2));

        // Dedup: use md5(url) as place_id so the UNIQUE KEY catches repeated submissions
        $url_key = md5($url);
        if (Lead::findByPlaceId($url_key)) {
            return 'duplicate';
        }

        // Fetch homepage HTML (reused by all enrichers — one HTTP request)
        $homepage_html = self::fetchHtml($url);
        if (!$homepage_html) {
            return 'no_website';
        }

        // Extract business name from <title> if not provided
        if (empty($business_name)) {
            if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $homepage_html, $m)) {
                $business_name = sanitize_text_field(html_entity_decode(trim($m[1])));
            }
            if (empty($business_name)) {
                $business_name = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
            }
        }

        // Franchise detection (name + homepage content)
        if (LeadEnrichmentService::isFranchise($business_name, $homepage_html)) {
            return 'franchise';
        }

        // SEO scoring — always score but only reject when automated (min_seo > 0)
        // Manual entries are explicitly chosen by the user so we save them regardless.
        $seo = LeadSEOScorer::scoreWebsiteFromHtml($homepage_html);
        if ($min_seo > 0 && (!$seo['ok'] || $seo['score'] < $min_seo)) {
            return 'low_seo';
        }

        // Enrichment
        $email_data = LeadEmailExtractor::extractEmail($url, $homepage_html);
        $phone      = self::extractPhoneFromHtml($homepage_html);
        $social     = LeadEnrichmentService::extractSocialMedia($url, $homepage_html);

        $insert_id = Lead::insert([
            'place_id'         => $url_key,
            'business_name'    => sanitize_text_field($business_name),
            'industry'         => sanitize_text_field($industry),
            'owner_first'      => '',
            'owner_last'       => '',
            'website'          => esc_url_raw($url),
            'address'          => '',
            'city'             => '',
            'state'            => '',
            'zip'              => '',
            'phone'            => sanitize_text_field($phone),
            'email'            => sanitize_email($email_data['email']),
            'email_source'     => $email_data['source'] ? esc_url_raw($email_data['source']) : '',
            'rating'           => 0,
            'review_count'     => 0,
            'social_facebook'  => $social['facebook']  ? esc_url_raw($social['facebook'])  : '',
            'social_instagram' => $social['instagram'] ? esc_url_raw($social['instagram']) : '',
            'social_linkedin'  => $social['linkedin']  ? esc_url_raw($social['linkedin'])  : '',
            'social_twitter'   => $social['twitter']   ? esc_url_raw($social['twitter'])   : '',
            'social_youtube'   => $social['youtube']   ? esc_url_raw($social['youtube'])   : '',
            'social_tiktok'    => $social['tiktok']    ? esc_url_raw($social['tiktok'])    : '',
            'seo_score'        => (int)$seo['score'],
            'seo_issues'       => $seo['issues'],
            'status'           => 'new',
        ]);

        if (!$insert_id) {
            global $wpdb;
            error_log('WNQ Lead Finder: DB insert failed for "' . $business_name . '" — ' . $wpdb->last_error);
            return 'low_seo';
        }

        return 'saved';
    }

    // ── Shared Helpers ────────────────────────────────────────────────────────

    private static function fetchHtml(string $url): string
    {
        $response = wp_remote_get($url, [
            'timeout'             => 8,
            'user-agent'          => 'Mozilla/5.0 (compatible; WebNique/1.0; +https://webnique.com)',
            'sslverify'           => false,
            'redirection'         => 3,
            'limit_response_size' => 512000, // 500 KB
        ]);

        if (is_wp_error($response)) return '';
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) return '';

        return wp_remote_retrieve_body($response);
    }

    private static function extractEmail(string $html): string
    {
        if (!$html) return '';

        if (preg_match('/mailto:([a-zA-Z0-9_.+\-]+@[a-zA-Z0-9\-]+\.[a-zA-Z0-9\-.]+)/i', $html, $m)) {
            $email = strtolower($m[1]);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) &&
                !preg_match('/\.(png|jpg|jpeg|gif|svg|webp|pdf|zip)$/i', $email)) {
                return $email;
            }
        }

        $text = strip_tags($html);
        if (preg_match('/[a-zA-Z0-9_.+\-]+@[a-zA-Z0-9\-]+\.[a-zA-Z0-9\-.]{2,}/i', $text, $m)) {
            $email = strtolower($m[0]);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) &&
                !preg_match('/\.(png|jpg|jpeg|gif|svg|webp|pdf|zip)$/i', $email) &&
                !in_array(explode('@', $email)[1] ?? '', ['example.com','test.com','domain.com'], true)) {
                return $email;
            }
        }

        return '';
    }

    /** Extract US phone from homepage HTML as a fallback. */
    private static function extractPhoneFromHtml(string $html): string
    {
        if (!$html) return '';

        if (preg_match('/href="tel:([^"]+)"/i', $html, $m)) {
            $digits = preg_replace('/\D/', '', $m[1]);
            if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
            if (strlen($digits) === 10) {
                return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
            }
        }

        $text = strip_tags($html);
        if (preg_match('/\(?\b(\d{3})\)?[\s.\-](\d{3})[\s.\-](\d{4})\b/', $text, $m)) {
            return "({$m[1]}) {$m[2]}-{$m[3]}";
        }

        return '';
    }

    private static function extractSocials(string $html): array
    {
        $out = ['facebook'=>'','instagram'=>'','twitter'=>'','linkedin'=>'','youtube'=>'','tiktok'=>''];
        if (!$html) return $out;

        $patterns = [
            'facebook'  => '/https?:\/\/(?:www\.)?facebook\.com\/(?!sharer)[a-zA-Z0-9._\-\/]+/i',
            'instagram' => '/https?:\/\/(?:www\.)?instagram\.com\/[a-zA-Z0-9._\-]+/i',
            'twitter'   => '/https?:\/\/(?:www\.)?(?:twitter|x)\.com\/[a-zA-Z0-9_]+/i',
            'linkedin'  => '/https?:\/\/(?:www\.)?linkedin\.com\/(?:company|in)\/[a-zA-Z0-9_\-]+/i',
            'youtube'   => '/https?:\/\/(?:www\.)?youtube\.com\/(?:channel\/|c\/|user\/|@)[a-zA-Z0-9_\-]+/i',
            'tiktok'    => '/https?:\/\/(?:www\.)?tiktok\.com\/@[a-zA-Z0-9._\-]+/i',
        ];

        foreach ($patterns as $network => $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $out[$network] = rtrim($m[0], '/.,;)"\' ');
            }
        }

        return $out;
    }

    private static function parseAddress(string $formatted): array
    {
        $formatted = preg_replace('/,\s*USA\s*$/i', '', $formatted);
        $parts     = array_map('trim', explode(',', $formatted));

        $street    = $parts[0] ?? '';
        $city      = $parts[1] ?? '';
        $state_zip = trim($parts[2] ?? '');
        $state     = '';
        $zip       = '';

        if (preg_match('/^([A-Z]{2})\s+(\d{5}(?:-\d{4})?)/', $state_zip, $m)) {
            $state = $m[1];
            $zip   = $m[2];
        }

        return compact('street', 'city', 'state', 'zip');
    }

    private static function emptyZipStats(): array
    {
        return ['zips_searched'=>0,'saved'=>0,'duplicate'=>0,'no_phone'=>0,'no_website'=>0,'skipped'=>0];
    }

    private static function emptyManualStats(int $found = 0): array
    {
        return ['found'=>$found,'franchise'=>0,'duplicate'=>0,'no_website'=>0,'low_seo'=>0,'saved'=>0];
    }

    private static function updateStats(array $stats, string $outcome): array
    {
        if (array_key_exists($outcome, $stats)) {
            $stats[$outcome]++;
        }
        return $stats;
    }
}
