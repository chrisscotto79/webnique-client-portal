<?php
/**
 * Lead Finder Engine (ZIP Edition)
 *
 * Mass lead generator that iterates through all Florida ZIP codes,
 * queries Google Places for each, and saves qualifying businesses.
 *
 * Architecture — two AJAX phases:
 *   Phase 1 – startSearch(keyword)
 *     Stores a batch transient containing all FL ZIP codes and returns
 *     immediately. No HTTP calls to external services.
 *
 *   Phase 2 – processNext(batch_id)
 *     Called once per "unit of work" by the browser JS loop.
 *     Each call does ONE of:
 *       (a) Process a pending candidate (Place Details + homepage scrape)
 *       (b) Search the next ZIP code via Google Places Text Search
 *
 * Qualifying criteria:
 *   – Fewer than 50 Google reviews (small, under-the-radar businesses)
 *   – Must have a phone number (skipped if none in Place Details)
 *
 * Enrichment (homepage source only, no deep crawling):
 *   – Email: mailto: link → visible text regex for @
 *   – Socials: regex for FB / IG / X(Twitter) / LI / YT / TT URLs
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
    private const BATCH_TTL  = DAY_IN_SECONDS;
    private const MAX_REVIEWS = 50; // businesses with fewer reviews are targeted

    // ── Phase 1: Start ───────────────────────────────────────────────────────

    /**
     * Initialise a new ZIP-sweep batch.
     * Returns immediately — no external HTTP calls made here.
     *
     * @param  string $keyword e.g. "roofing contractor"
     * @return array{ok: bool, batch_id?: string, total_zips?: int, error?: string}
     */
    public static function startSearch(string $keyword): array
    {
        $keyword = sanitize_text_field($keyword);
        if (!$keyword) {
            return ['ok' => false, 'error' => 'Keyword is required'];
        }

        $zips = \WNQ\Data\FloridaZips::getAll();
        // Remove duplicates that may exist in the data file
        $zips = array_values(array_unique($zips));

        if (empty($zips)) {
            return ['ok' => false, 'error' => 'ZIP code list is empty'];
        }

        $batch_id = wp_generate_uuid4();

        set_transient('wnq_zip_batch_' . $batch_id, [
            'keyword'    => $keyword,
            'zips'       => $zips,
            'zip_index'  => 0,       // index of next ZIP to search
            'page_token' => '',      // Places pagination token for current ZIP
            'candidates' => [],      // places fetched from current ZIP, not yet processed
            'cand_index' => 0,       // next candidate to process
            'stats'      => self::emptyStats(),
        ], self::BATCH_TTL);

        return [
            'ok'         => true,
            'batch_id'   => $batch_id,
            'total_zips' => count($zips),
        ];
    }

    // ── Phase 2: Process one unit of work ────────────────────────────────────

    /**
     * Process the next available unit of work in the batch.
     * One call either (a) processes a pending candidate or (b) fetches the next ZIP.
     *
     * @param  string $batch_id Returned by startSearch()
     * @return array{ok: bool, done: bool, zip_index: int, total_zips: int, stats: array, action?: string}
     */
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

        // (b) No candidates → check if any ZIPs remain
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

        // (b) Fetch next ZIP
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

        // *** Advance cand_index BEFORE processing ***
        // If the browser AbortController fires and the JS moves on, the next
        // AJAX call picks up the NEXT candidate instead of retrying this one.
        $batch['cand_index'] = $cand_index + 1;
        $cands_exhausted     = ($cand_index + 1 >= count($batch['candidates']));
        $zips_exhausted      = ($zip_index >= $total_zips);
        $done                = $cands_exhausted && $zips_exhausted;

        if ($done) {
            delete_transient('wnq_zip_batch_' . $batch_id);
        } else {
            set_transient('wnq_zip_batch_' . $batch_id, $batch, self::BATCH_TTL);
        }

        // Process — errors counted as skipped, never block the queue
        try {
            $outcome = self::processCandidate($place, $keyword);
        } catch (\Throwable $e) {
            $outcome = 'skipped';
        }

        $stats = self::updateStats($stats, $outcome);

        // Write stats back best-effort (transient may be gone if done)
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

    // ── Private: Fetch one ZIP (or next page) from Places API ────────────────

    private static function handleZipSearch(string $batch_id, array $batch): array
    {
        $zips       = $batch['zips'];
        $zip_index  = (int)$batch['zip_index'];
        $page_token = $batch['page_token'] ?? '';
        $keyword    = $batch['keyword'];
        $stats      = $batch['stats'];
        $total_zips = count($zips);
        $zip        = $zips[$zip_index];

        // Query Places API (15s timeout is in PlacesAPIClient)
        $data = PlacesAPIClient::textSearch($keyword . ' ' . $zip, $page_token);

        if (!empty($data['error'])) {
            // Advance ZIP index on error so we don't retry forever
            $batch['zip_index']  = $zip_index + 1;
            $batch['page_token'] = '';
            $batch['candidates'] = [];
            $batch['cand_index'] = 0;
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
                'action'    => 'zip_error',
                'zip'       => $zip,
            ];
        }

        $raw = $data['results'] ?? [];

        // Filter: fewer than MAX_REVIEWS reviews, not permanently closed
        $filtered = array_values(array_filter($raw, static function (array $p): bool {
            if (($p['business_status'] ?? '') === 'PERMANENTLY_CLOSED') return false;
            return (int)($p['user_ratings_total'] ?? 0) < self::MAX_REVIEWS;
        }));

        $next_page_token = $data['next_page_token'] ?? '';

        // Advance ZIP or stay on same ZIP for next page
        if ($next_page_token) {
            $batch['page_token'] = $next_page_token;
            // zip_index stays the same — we'll fetch next page on the next "empty" call
        } else {
            $batch['zip_index']  = $zip_index + 1;
            $batch['page_token'] = '';
        }

        $batch['candidates'] = $filtered;
        $batch['cand_index'] = 0;

        $stats['zips_searched'] = ($stats['zips_searched'] ?? 0) + 1;
        $batch['stats']         = $stats;

        $new_zip_index = $next_page_token ? $zip_index : ($zip_index + 1);
        $done          = ($new_zip_index >= $total_zips && !$next_page_token && empty($filtered));

        if ($done) {
            delete_transient('wnq_zip_batch_' . $batch_id);
        } else {
            set_transient('wnq_zip_batch_' . $batch_id, $batch, self::BATCH_TTL);
        }

        return [
            'ok'        => true,
            'done'      => $done,
            'zip_index' => $new_zip_index,
            'total_zips'=> $total_zips,
            'stats'     => $stats,
            'action'    => 'zip_searched',
            'zip'       => $zip,
            'found'     => count($filtered),
        ];
    }

    // ── Private: Qualify + enrich a single Places result ────────────────────

    /**
     * @return string  saved | duplicate | no_phone | no_website | skipped
     */
    private static function processCandidate(array $place, string $keyword): string
    {
        $place_id = $place['place_id'] ?? '';
        if (!$place_id) return 'skipped';

        // 1. Dedup by place_id (fast DB check)
        if (Lead::findByPlaceId($place_id)) return 'duplicate';

        // 2. Place Details → phone + website (8s timeout in PlacesAPIClient)
        $details = PlacesAPIClient::getDetails($place_id);
        $phone   = trim($details['formatted_phone_number'] ?? '');
        $website = trim($details['website'] ?? '');

        // 3. Skip if no phone (user requirement)
        if (!$phone) return 'no_phone';

        // 4. Skip if no website
        if (!$website) return 'no_website';

        // 5. Parse address (needed for name+city dedup)
        $addr          = self::parseAddress($place['formatted_address'] ?? '');
        $business_name = sanitize_text_field($place['name'] ?? '');
        $lead_city     = $addr['city'] ?: '';

        // 6. Dedup by name + city
        if ($lead_city && Lead::existsByNameAndCity($business_name, $lead_city)) return 'duplicate';

        // 7. Fetch homepage source (5s timeout, 256 KB cap)
        $html = self::fetchHtml($website);

        // 8. Extract email + social media from homepage source only
        $email  = self::extractEmail($html);
        $social = self::extractSocials($html);

        Lead::insert([
            'place_id'         => $place_id,
            'business_name'    => $business_name,
            'industry'         => $keyword,
            'owner_first'      => '',
            'owner_last'       => '',
            'website'          => esc_url_raw($website),
            'address'          => sanitize_text_field($addr['street']),
            'city'             => sanitize_text_field($lead_city),
            'state'            => sanitize_text_field($addr['state']),
            'zip'              => sanitize_text_field($addr['zip']),
            'phone'            => sanitize_text_field($phone),
            'email'            => sanitize_email($email),
            'email_source'     => '',
            'rating'           => (float)($place['rating'] ?? 0),
            'review_count'     => (int)($place['user_ratings_total'] ?? 0),
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

    // ── Private: Helpers ─────────────────────────────────────────────────────

    private static function fetchHtml(string $url): string
    {
        $response = wp_remote_get($url, [
            'timeout'             => 5,
            'user-agent'          => 'Mozilla/5.0 (compatible; WebNique/1.0; +https://webnique.com)',
            'sslverify'           => false,
            'redirection'         => 3,
            'limit_response_size' => 262144, // 256 KB — enough for head + visible text
        ]);

        if (is_wp_error($response)) return '';
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) return '';

        return wp_remote_retrieve_body($response);
    }

    /**
     * Extract the first valid email address from homepage HTML.
     * Prefers mailto: links, falls back to regex on visible text.
     */
    private static function extractEmail(string $html): string
    {
        if (!$html) return '';

        // 1. mailto: links (most reliable signal)
        if (preg_match('/mailto:([a-zA-Z0-9_.+\-]+@[a-zA-Z0-9\-]+\.[a-zA-Z0-9\-.]+)/i', $html, $m)) {
            $email = strtolower($m[1]);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) &&
                !preg_match('/\.(png|jpg|jpeg|gif|svg|webp|pdf|zip)$/i', $email)) {
                return $email;
            }
        }

        // 2. Visible text (strip HTML tags first)
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

    /**
     * Extract social media URLs from homepage HTML source.
     * Returns first match per platform.
     */
    private static function extractSocials(string $html): array
    {
        $out = [
            'facebook'  => '',
            'instagram' => '',
            'twitter'   => '',
            'linkedin'  => '',
            'youtube'   => '',
            'tiktok'    => '',
        ];

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

    /**
     * Parse a Google Places formatted_address into components.
     * Example input: "123 Main St, Orlando, FL 32801, USA"
     */
    private static function parseAddress(string $formatted): array
    {
        // Strip trailing country
        $formatted = preg_replace('/,\s*USA\s*$/', '', $formatted);
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

    private static function emptyStats(): array
    {
        return [
            'zips_searched' => 0,
            'saved'         => 0,
            'duplicate'     => 0,
            'no_phone'      => 0,
            'no_website'    => 0,
            'skipped'       => 0,
        ];
    }

    private static function updateStats(array $stats, string $outcome): array
    {
        if (array_key_exists($outcome, $stats)) {
            $stats[$outcome]++;
        }
        return $stats;
    }
}
