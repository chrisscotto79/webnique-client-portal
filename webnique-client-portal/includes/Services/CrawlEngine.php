<?php
/**
 * CrawlEngine — SEO Spider
 *
 * Crawls client websites in AJAX batches, storing per-page analysis.
 * Detects: broken links, redirect chains, duplicate content, missing tags,
 * robots/noindex, canonical issues, thin content, missing alt text.
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class CrawlEngine
{
    const BATCH_SIZE    = 8;
    const MAX_DEPTH     = 5;
    const CRAWL_TIMEOUT = 15; // seconds per request
    const MAX_URLS      = 2000;

    // ── Table Creation ─────────────────────────────────────────────────────

    public static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sessions_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_crawl_sessions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            start_url varchar(2083) NOT NULL,
            base_domain varchar(255) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'running',
            urls_found int NOT NULL DEFAULT 0,
            urls_crawled int NOT NULL DEFAULT 0,
            urls_queued int NOT NULL DEFAULT 0,
            issues_found int NOT NULL DEFAULT 0,
            options longtext DEFAULT NULL,
            started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_client_status (client_id, status)
        ) $charset;";

        $pages_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_crawl_pages (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id bigint(20) NOT NULL,
            client_id varchar(100) NOT NULL,
            url varchar(2083) NOT NULL,
            depth tinyint NOT NULL DEFAULT 0,
            status_code smallint NOT NULL DEFAULT 0,
            final_url varchar(2083) DEFAULT NULL,
            redirect_count tinyint NOT NULL DEFAULT 0,
            redirect_chain longtext DEFAULT NULL,
            page_title varchar(1000) DEFAULT NULL,
            meta_description varchar(1000) DEFAULT NULL,
            h1 varchar(500) DEFAULT NULL,
            canonical_url varchar(2083) DEFAULT NULL,
            robots_meta varchar(200) DEFAULT NULL,
            x_robots_tag varchar(200) DEFAULT NULL,
            content_type varchar(100) DEFAULT NULL,
            word_count int NOT NULL DEFAULT 0,
            content_hash varchar(32) DEFAULT NULL,
            internal_links smallint NOT NULL DEFAULT 0,
            external_links smallint NOT NULL DEFAULT 0,
            images_count smallint NOT NULL DEFAULT 0,
            images_missing_alt smallint NOT NULL DEFAULT 0,
            has_schema tinyint NOT NULL DEFAULT 0,
            is_indexable tinyint NOT NULL DEFAULT 1,
            page_size_kb smallint NOT NULL DEFAULT 0,
            issues longtext DEFAULT NULL,
            crawled_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_client (client_id),
            KEY idx_status_code (status_code),
            KEY idx_content_hash (content_hash)
        ) $charset;";

        $queue_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wnq_crawl_queue (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id bigint(20) NOT NULL,
            url varchar(2083) NOT NULL,
            depth tinyint NOT NULL DEFAULT 0,
            source_url varchar(2083) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            KEY idx_session_status (session_id, status)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sessions_sql);
        dbDelta($pages_sql);
        dbDelta($queue_sql);
    }

    // ── Session Management ─────────────────────────────────────────────────

    public static function startCrawl(string $client_id, string $start_url, array $options = []): int
    {
        global $wpdb;

        $start_url   = trailingslashit(esc_url_raw($start_url));
        $base_domain = parse_url($start_url, PHP_URL_HOST) ?? '';

        $session_id = $wpdb->insert(
            "{$wpdb->prefix}wnq_crawl_sessions",
            ['client_id' => $client_id, 'start_url' => $start_url, 'base_domain' => $base_domain,
             'status' => 'running', 'options' => wp_json_encode($options)],
            ['%s', '%s', '%s', '%s', '%s']
        ) ? (int)$wpdb->insert_id : 0;

        if ($session_id) {
            $wpdb->insert(
                "{$wpdb->prefix}wnq_crawl_queue",
                ['session_id' => $session_id, 'url' => $start_url, 'depth' => 0],
                ['%d', '%s', '%d']
            );
            $wpdb->update("{$wpdb->prefix}wnq_crawl_sessions", ['urls_queued' => 1], ['id' => $session_id]);
        }

        return $session_id;
    }

    public static function getSession(int $session_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_crawl_sessions WHERE id=%d", $session_id
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function getSessions(string $client_id, int $limit = 10): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_crawl_sessions WHERE client_id=%s ORDER BY id DESC LIMIT %d",
            $client_id, $limit
        ), ARRAY_A) ?: [];
    }

    public static function deleteSession(int $session_id): void
    {
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}wnq_crawl_sessions", ['id' => $session_id]);
        $wpdb->delete("{$wpdb->prefix}wnq_crawl_pages",    ['session_id' => $session_id]);
        $wpdb->delete("{$wpdb->prefix}wnq_crawl_queue",    ['session_id' => $session_id]);
    }

    // ── Batch Crawler ──────────────────────────────────────────────────────

    public static function crawlBatch(int $session_id, int $batch_size = self::BATCH_SIZE): array
    {
        global $wpdb;

        $session = self::getSession($session_id);
        if (!$session || $session['status'] !== 'running') {
            return ['done' => true, 'crawled' => 0, 'queued' => 0, 'issues' => 0];
        }

        // Fetch already-crawled URLs to skip duplicates
        $crawled_urls = $wpdb->get_col($wpdb->prepare(
            "SELECT url FROM {$wpdb->prefix}wnq_crawl_pages WHERE session_id=%d", $session_id
        ));
        $crawled_set = array_flip($crawled_urls);

        // Fetch batch from queue
        $queue_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wnq_crawl_queue WHERE session_id=%d AND status='pending' LIMIT %d",
            $session_id, $batch_size
        ), ARRAY_A);

        if (empty($queue_items)) {
            $wpdb->update("{$wpdb->prefix}wnq_crawl_sessions",
                ['status' => 'completed', 'completed_at' => current_time('mysql')],
                ['id' => $session_id]);
            return ['done' => true, 'crawled' => (int)$session['urls_crawled'],
                    'queued' => 0, 'issues' => (int)$session['issues_found']];
        }

        $options     = json_decode($session['options'] ?? '{}', true) ?: [];
        $base_domain = $session['base_domain'];
        $max_depth   = (int)($options['max_depth'] ?? self::MAX_DEPTH);
        $crawled     = 0;
        $new_issues  = 0;

        foreach ($queue_items as $item) {
            // Mark as processing
            $wpdb->update("{$wpdb->prefix}wnq_crawl_queue",
                ['status' => 'processing'], ['id' => $item['id']]);

            $url = $item['url'];

            // Skip if already crawled in this session
            if (isset($crawled_set[$url])) {
                $wpdb->update("{$wpdb->prefix}wnq_crawl_queue", ['status' => 'done'], ['id' => $item['id']]);
                continue;
            }
            $crawled_set[$url] = true;

            // Crawl the page
            $page_data = self::crawlPage($url, (int)$item['depth'], $session_id, $session['client_id']);
            $wpdb->insert("{$wpdb->prefix}wnq_crawl_pages", $page_data, null);

            if (!empty($page_data['issues'])) {
                $issues_arr = json_decode($page_data['issues'], true);
                $new_issues += count($issues_arr ?: []);
            }

            // Queue discovered internal links (if not already crawled/queued and within depth)
            if (!empty($page_data['_discovered_links']) && (int)$item['depth'] < $max_depth) {
                $total_queued = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}wnq_crawl_queue WHERE session_id=%d", $session_id
                ));

                foreach ($page_data['_discovered_links'] as $link) {
                    $link_host = parse_url($link, PHP_URL_HOST) ?? '';
                    if ($link_host !== $base_domain) continue;
                    if (isset($crawled_set[$link])) continue;
                    if ($total_queued >= self::MAX_URLS) break;

                    // Check if already in queue
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}wnq_crawl_queue WHERE session_id=%d AND url=%s",
                        $session_id, $link
                    ));
                    if (!$exists) {
                        $wpdb->insert("{$wpdb->prefix}wnq_crawl_queue", [
                            'session_id' => $session_id, 'url' => $link,
                            'depth' => (int)$item['depth'] + 1, 'source_url' => $url,
                        ]);
                        $total_queued++;
                    }
                }
            }
            unset($page_data['_discovered_links']);

            $wpdb->update("{$wpdb->prefix}wnq_crawl_queue", ['status' => 'done'], ['id' => $item['id']]);
            $crawled++;
        }

        // Update session counters
        $queued_remaining = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wnq_crawl_queue WHERE session_id=%d AND status='pending'",
            $session_id
        ));
        $total_crawled = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wnq_crawl_pages WHERE session_id=%d", $session_id
        ));
        $total_issues = (int)$session['issues_found'] + $new_issues;

        $wpdb->update("{$wpdb->prefix}wnq_crawl_sessions", [
            'urls_crawled' => $total_crawled,
            'urls_queued'  => $queued_remaining,
            'issues_found' => $total_issues,
        ], ['id' => $session_id]);

        return [
            'done'    => false,
            'crawled' => $total_crawled,
            'queued'  => $queued_remaining,
            'issues'  => $total_issues,
            'batch'   => $crawled,
        ];
    }

    // ── Single Page Crawl ──────────────────────────────────────────────────

    private static function crawlPage(string $url, int $depth, int $session_id, string $client_id): array
    {
        $redirect_chain = [];
        $response = wp_remote_get($url, [
            'timeout'     => self::CRAWL_TIMEOUT,
            'redirection' => 10,
            'user-agent'  => 'WebNique-SEO-Spider/1.0 (+https://web-nique.com/bot)',
            'headers'     => ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
        ]);

        $data = [
            'session_id'       => $session_id,
            'client_id'        => $client_id,
            'url'              => $url,
            'depth'            => $depth,
            'status_code'      => 0,
            'final_url'        => $url,
            'redirect_count'   => 0,
            'redirect_chain'   => null,
            'page_title'       => null,
            'meta_description' => null,
            'h1'               => null,
            'canonical_url'    => null,
            'robots_meta'      => null,
            'x_robots_tag'     => null,
            'content_type'     => null,
            'word_count'       => 0,
            'content_hash'     => null,
            'internal_links'   => 0,
            'external_links'   => 0,
            'images_count'     => 0,
            'images_missing_alt' => 0,
            'has_schema'       => 0,
            'is_indexable'     => 1,
            'page_size_kb'     => 0,
            'issues'           => null,
            '_discovered_links' => [],
        ];

        if (is_wp_error($response)) {
            $data['status_code'] = 0;
            $data['issues']      = wp_json_encode(['connection_error']);
            return $data;
        }

        $status_code  = (int)wp_remote_retrieve_response_code($response);
        $body         = wp_remote_retrieve_body($response);
        $headers      = wp_remote_retrieve_headers($response);
        $content_type = $headers['content-type'] ?? '';
        $x_robots     = $headers['x-robots-tag'] ?? null;

        $data['status_code']  = $status_code;
        $data['content_type'] = substr($content_type, 0, 100);
        $data['page_size_kb'] = (int)ceil(strlen($body) / 1024);
        if ($x_robots) $data['x_robots_tag'] = substr($x_robots, 0, 200);

        // Track redirects
        if (isset($response['http_response'])) {
            // wp_remote_get follows redirects automatically; infer from final URL
            $final_url = $headers['x-final-location'] ?? $url;
            if ($final_url !== $url) {
                $data['final_url']       = $final_url;
                $data['redirect_count']  = 1;
                $data['redirect_chain']  = wp_json_encode([$url, $final_url]);
            }
        }

        $issues = [];

        // Non-200: broken link, server error, redirect
        if ($status_code === 404) $issues[] = 'broken_link_404';
        elseif ($status_code >= 400 && $status_code < 500) $issues[] = 'client_error_' . $status_code;
        elseif ($status_code >= 500) $issues[] = 'server_error_' . $status_code;
        elseif (in_array($status_code, [301, 302, 307, 308])) $issues[] = 'redirect_' . $status_code;

        // Non-HTML: skip parsing
        if (!str_contains($content_type, 'html')) {
            $data['issues'] = !empty($issues) ? wp_json_encode($issues) : null;
            return $data;
        }

        // Parse HTML
        if (!empty($body)) {
            $parsed = self::parseHTML($body, $url, $headers);
            $data   = array_merge($data, $parsed['data']);

            // Check indexability
            $robots_lc = strtolower($parsed['data']['robots_meta'] ?? '');
            $xrobots_lc = strtolower($x_robots ?? '');
            if (str_contains($robots_lc, 'noindex') || str_contains($xrobots_lc, 'noindex')) {
                $data['is_indexable'] = 0;
                $issues[] = 'noindex';
            }
            if (str_contains($robots_lc, 'nofollow') || str_contains($xrobots_lc, 'nofollow')) {
                $issues[] = 'nofollow_page';
            }

            // SEO issue checks
            if (empty($data['page_title']))       $issues[] = 'missing_title';
            elseif (strlen($data['page_title']) < 20)  $issues[] = 'title_too_short';
            elseif (strlen($data['page_title']) > 60)  $issues[] = 'title_too_long';

            if (empty($data['meta_description']))  $issues[] = 'missing_meta_description';
            elseif (strlen($data['meta_description']) < 70)  $issues[] = 'meta_desc_too_short';
            elseif (strlen($data['meta_description']) > 160) $issues[] = 'meta_desc_too_long';

            if (empty($data['h1']))                $issues[] = 'missing_h1';
            if ($data['word_count'] > 0 && $data['word_count'] < 300) $issues[] = 'thin_content';
            if ($data['images_missing_alt'] > 0)   $issues[] = 'missing_alt_text';
            if (!$data['has_schema'])              $issues[] = 'no_schema';
            if ($data['internal_links'] === 0)     $issues[] = 'no_internal_links';
            if (!empty($data['canonical_url']) && $data['canonical_url'] !== $url) $issues[] = 'canonical_points_elsewhere';

            $data['_discovered_links'] = $parsed['links'];
        }

        $data['issues'] = !empty($issues) ? wp_json_encode(array_values(array_unique($issues))) : null;
        return $data;
    }

    // ── HTML Parser ────────────────────────────────────────────────────────

    private static function parseHTML(string $html, string $page_url, $headers): array
    {
        $result = [
            'data'  => [],
            'links' => [],
        ];

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $base  = self::baseUrl($page_url);
        $host  = parse_url($page_url, PHP_URL_HOST);

        // Title
        $title_node = $xpath->query('//title')->item(0);
        $result['data']['page_title'] = $title_node ? trim($title_node->textContent) : null;

        // Meta description
        foreach ($xpath->query('//meta[@name="description"]') as $node) {
            $result['data']['meta_description'] = substr(trim($node->getAttribute('content')), 0, 1000);
            break;
        }

        // Robots meta
        foreach ($xpath->query('//meta[@name="robots"]') as $node) {
            $result['data']['robots_meta'] = substr($node->getAttribute('content'), 0, 200);
            break;
        }

        // H1
        $h1_node = $xpath->query('//h1')->item(0);
        $result['data']['h1'] = $h1_node ? substr(trim($h1_node->textContent), 0, 500) : null;

        // Canonical
        foreach ($xpath->query('//link[@rel="canonical"]') as $node) {
            $result['data']['canonical_url'] = esc_url_raw($node->getAttribute('href'));
            break;
        }

        // Schema
        $schema_nodes = $xpath->query('//script[@type="application/ld+json"]');
        $result['data']['has_schema'] = $schema_nodes->length > 0 ? 1 : 0;

        // Images
        $imgs = $xpath->query('//img');
        $result['data']['images_count']       = $imgs->length;
        $result['data']['images_missing_alt'] = 0;
        foreach ($imgs as $img) {
            if (trim($img->getAttribute('alt')) === '') {
                $result['data']['images_missing_alt']++;
            }
        }

        // Body text for word count + hash
        $body_node = $xpath->query('//body')->item(0);
        $body_text = '';
        if ($body_node) {
            $body_text = trim(preg_replace('/\s+/', ' ',
                strip_tags($doc->saveHTML($body_node))
            ));
        }
        $result['data']['word_count']    = str_word_count($body_text);
        $result['data']['content_hash']  = md5($body_text);

        // Links
        $internal = 0; $external = 0;
        $discovered = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if (empty($href) || str_starts_with($href, '#') ||
                str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') ||
                str_starts_with($href, 'javascript:')) continue;

            $abs = self::toAbsoluteUrl($href, $base);
            if (!$abs) continue;

            $link_host = parse_url($abs, PHP_URL_HOST) ?? '';

            // Strip fragments
            $abs = strtok($abs, '#');

            // Skip non-HTML resources
            $ext = strtolower(pathinfo(parse_url($abs, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'zip', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'css', 'js', 'xml'])) {
                continue;
            }

            if ($link_host === $host) {
                $internal++;
                $discovered[] = $abs;
            } else {
                $external++;
            }
        }

        $result['data']['internal_links'] = $internal;
        $result['data']['external_links'] = $external;
        $result['links']                  = array_values(array_unique($discovered));

        return $result;
    }

    // ── URL Helpers ────────────────────────────────────────────────────────

    private static function baseUrl(string $url): string
    {
        $parts = parse_url($url);
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') .
               (isset($parts['path']) ? dirname($parts['path']) . '/' : '/');
    }

    private static function toAbsoluteUrl(string $href, string $base): ?string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            $base_parts = parse_url($base);
            return ($base_parts['scheme'] ?? 'https') . '://' . ($base_parts['host'] ?? '') . $href;
        }
        return rtrim($base, '/') . '/' . $href;
    }

    // ── Robots.txt ─────────────────────────────────────────────────────────

    public static function getRobotsTxt(string $site_url): array
    {
        $robots_url = trailingslashit($site_url) . 'robots.txt';
        $response   = wp_remote_get($robots_url, ['timeout' => 10]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return ['found' => false, 'content' => '', 'disallowed' => [], 'sitemaps' => []];
        }

        $content    = wp_remote_retrieve_body($response);
        $disallowed = [];
        $sitemaps   = [];
        $in_agent   = false;

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (stripos($line, 'user-agent: *') === 0) { $in_agent = true; continue; }
            if (stripos($line, 'user-agent:') === 0)   { $in_agent = false; continue; }
            if ($in_agent && stripos($line, 'disallow:') === 0) {
                $path = trim(substr($line, 9));
                if ($path) $disallowed[] = $path;
            }
            if (stripos($line, 'sitemap:') === 0) {
                $sitemaps[] = trim(substr($line, 8));
            }
        }

        return ['found' => true, 'content' => $content, 'disallowed' => $disallowed, 'sitemaps' => $sitemaps];
    }

    // ── Result Queries ─────────────────────────────────────────────────────

    public static function getPages(int $session_id, array $filters = []): array
    {
        global $wpdb;
        $where = ['p.session_id=%d'];
        $vals  = [$session_id];

        if (!empty($filters['status_code'])) {
            $where[] = 'p.status_code=%d';
            $vals[]  = (int)$filters['status_code'];
        }
        if (!empty($filters['issue'])) {
            $where[] = "p.issues LIKE %s";
            $vals[]  = '%' . $wpdb->esc_like($filters['issue']) . '%';
        }
        if (isset($filters['is_indexable'])) {
            $where[] = 'p.is_indexable=%d';
            $vals[]  = (int)$filters['is_indexable'];
        }

        $limit = (int)($filters['limit'] ?? 500);
        $sql   = "SELECT p.* FROM {$wpdb->prefix}wnq_crawl_pages p WHERE " .
                 implode(' AND ', $where) . " ORDER BY p.id ASC LIMIT %d";
        $vals[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, ...$vals), ARRAY_A) ?: [];
    }

    public static function getBrokenLinks(int $session_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT url, status_code, depth, issues FROM {$wpdb->prefix}wnq_crawl_pages
             WHERE session_id=%d AND (status_code>=400 OR status_code=0)
             ORDER BY status_code ASC, url ASC LIMIT 500",
            $session_id
        ), ARRAY_A) ?: [];
    }

    public static function getRedirectPages(int $session_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT url, status_code, final_url, redirect_count, redirect_chain
             FROM {$wpdb->prefix}wnq_crawl_pages
             WHERE session_id=%d AND redirect_count > 0
             ORDER BY redirect_count DESC LIMIT 300",
            $session_id
        ), ARRAY_A) ?: [];
    }

    public static function getDuplicateContent(int $session_id): array
    {
        global $wpdb;
        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT content_hash, COUNT(*) as cnt
             FROM {$wpdb->prefix}wnq_crawl_pages
             WHERE session_id=%d AND content_hash IS NOT NULL AND status_code=200
             GROUP BY content_hash HAVING cnt > 1
             ORDER BY cnt DESC LIMIT 100",
            $session_id
        ), ARRAY_A) ?: [];

        $result = [];
        foreach ($groups as $g) {
            $pages = $wpdb->get_results($wpdb->prepare(
                "SELECT url, page_title, word_count FROM {$wpdb->prefix}wnq_crawl_pages
                 WHERE session_id=%d AND content_hash=%s",
                $session_id, $g['content_hash']
            ), ARRAY_A) ?: [];
            $result[] = ['hash' => $g['content_hash'], 'count' => (int)$g['cnt'], 'pages' => $pages];
        }
        return $result;
    }

    public static function getDuplicateTitles(int $session_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT page_title, GROUP_CONCAT(url SEPARATOR '|||') as urls, COUNT(*) as cnt
             FROM {$wpdb->prefix}wnq_crawl_pages
             WHERE session_id=%d AND page_title IS NOT NULL AND page_title != '' AND status_code=200
             GROUP BY page_title HAVING cnt > 1
             ORDER BY cnt DESC LIMIT 100",
            $session_id
        ), ARRAY_A) ?: [];
    }

    public static function getIssuesSummary(int $session_id): array
    {
        global $wpdb;
        $pages  = $wpdb->get_col($wpdb->prepare(
            "SELECT issues FROM {$wpdb->prefix}wnq_crawl_pages WHERE session_id=%d AND issues IS NOT NULL",
            $session_id
        ));
        $counts = [];
        foreach ($pages as $raw) {
            $arr = json_decode($raw, true) ?: [];
            foreach ($arr as $issue) {
                $counts[$issue] = ($counts[$issue] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }

    public static function getArchitecture(int $session_id): array
    {
        global $wpdb;
        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT url, depth, status_code, page_title, is_indexable
             FROM {$wpdb->prefix}wnq_crawl_pages WHERE session_id=%d ORDER BY depth ASC, url ASC LIMIT 500",
            $session_id
        ), ARRAY_A) ?: [];

        // Group by depth level
        $by_depth = [];
        foreach ($pages as $p) {
            $by_depth[(int)$p['depth']][] = $p;
        }
        return $by_depth;
    }

    // ── XML Sitemap Generator ──────────────────────────────────────────────

    public static function generateSitemap(int $session_id): string
    {
        global $wpdb;
        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT url, crawled_at FROM {$wpdb->prefix}wnq_crawl_pages
             WHERE session_id=%d AND status_code=200 AND is_indexable=1
             ORDER BY url ASC LIMIT 2000",
            $session_id
        ), ARRAY_A) ?: [];

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($pages as $p) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . esc_url($p['url']) . "</loc>\n";
            $xml .= '    <lastmod>' . date('Y-m-d', strtotime($p['crawled_at'])) . "</lastmod>\n";
            $xml .= "    <changefreq>monthly</changefreq>\n";
            $xml .= "    <priority>0.5</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';
        return $xml;
    }

    // ── Stats ──────────────────────────────────────────────────────────────

    public static function getSessionStats(int $session_id): array
    {
        global $wpdb;
        $p = "{$wpdb->prefix}wnq_crawl_pages";
        return [
            'total'         => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $p WHERE session_id=%d", $session_id)),
            'ok'            => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $p WHERE session_id=%d AND status_code=200", $session_id)),
            'broken'        => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $p WHERE session_id=%d AND (status_code>=400 OR status_code=0)", $session_id)),
            'redirects'     => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $p WHERE session_id=%d AND redirect_count>0", $session_id)),
            'noindex'       => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $p WHERE session_id=%d AND is_indexable=0", $session_id)),
            'missing_title' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $p WHERE session_id=%d AND (page_title IS NULL OR page_title='')", $session_id)),
            'missing_h1'    => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $p WHERE session_id=%d AND (h1 IS NULL OR h1='')", $session_id)),
            'thin_content'  => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $p WHERE session_id=%d AND word_count>0 AND word_count<300", $session_id)),
            'no_schema'     => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $p WHERE session_id=%d AND has_schema=0 AND status_code=200", $session_id)),
            'missing_meta'  => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $p WHERE session_id=%d AND (meta_description IS NULL OR meta_description='')", $session_id)),
        ];
    }
}
