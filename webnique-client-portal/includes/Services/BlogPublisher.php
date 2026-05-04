<?php
/**
 * Blog Publisher Service
 *
 * Orchestrates the full blog auto-publishing pipeline:
 *  1. Load post from the schedule queue
 *  2. Build AI vars from client profile + synced site data
 *  3. Select 2-4 internal links (scored by relevance + "Always Link To" priority)
 *  4. Call AIEngine::generate('blog_post_full') — returns H1, META, TOC, BODY
 *  5. Parse structured AI response
 *  6. Inject content into stored Elementor JSON template
 *  7. Push to client site via POST {site_url}/wp-json/wnq-agent/v1/publish-post
 *  8. Update schedule record, fire hub notification
 *
 * External links policy:
 *  - Informational posts: AI is prompted to include 1 authoritative citation (no competitors)
 *  - Services posts: zero external links
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\BlogScheduler;
use WNQ\Models\SEOHub;
use WNQ\Models\Client;

final class BlogPublisher
{
    /**
     * Process all posts that are due today.
     * Called by the daily cron job.
     */
    public static function processDuePosts(): void
    {
        $due = BlogScheduler::getDuePosts();
        foreach ($due as $post) {
            self::processPost((int)$post['id']);
        }
    }

    /**
     * Process a single scheduled post: generate content and publish to client site.
     */
    public static function processPost(int $schedule_id): array
    {
        $scheduled = BlogScheduler::getPost($schedule_id);
        if (!$scheduled) {
            return ['success' => false, 'message' => 'Schedule entry not found'];
        }

        if (!in_array($scheduled['status'], ['pending', 'failed'], true)) {
            return ['success' => false, 'message' => 'Post must be in pending or failed status to process'];
        }

        // Reset failed posts before retrying
        if ($scheduled['status'] === 'failed') {
            BlogScheduler::updatePost($schedule_id, ['status' => 'pending', 'error_message' => null]);
        }

        // Mark as generating
        BlogScheduler::updatePost($schedule_id, ['status' => 'generating']);

        $client_id = $scheduled['client_id'];

        // Load client profile
        $profile = SEOHub::getProfile($client_id);
        if (!$profile) {
            return self::fail($schedule_id, 'No SEO profile found for client: ' . $client_id);
        }

        $client      = Client::getByClientId($client_id) ?? [];
        $biz_name    = $client['company'] ?? $client['name'] ?? $client_id;
        $services    = implode(', ', (array)($profile['primary_services'] ?? []));
        $location    = implode(', ', (array)($profile['service_locations'] ?? []));
        $tone        = $profile['content_tone'] ?? 'professional';
        $category    = $scheduled['category_type'] ?? 'Informational';
        $focus_kw    = $scheduled['focus_keyword'] ?? '';

        // If no focus keyword set, auto-select the top tracked keyword for this client
        if (empty($focus_kw)) {
            global $wpdb;
            $top_kw = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT keyword FROM {$wpdb->prefix}wnq_seo_keywords
                     WHERE client_id = %s ORDER BY impressions DESC, clicks DESC LIMIT 1",
                    $client_id
                )
            );
            if ($top_kw) {
                $focus_kw = $top_kw;
                BlogScheduler::updatePost($schedule_id, ['focus_keyword' => $focus_kw]);
            }
        }

        // Build keyword context from tracked clusters and top keywords
        $keyword_context = self::buildKeywordContext($client_id, $profile);

        // Load agent — use the site assigned to this post, fall back to first active site
        $agent = self::getActiveAgent($client_id, (int)($scheduled['agent_key_id'] ?? 0));
        if (!$agent) {
            return self::fail($schedule_id, 'No active agent key found for client. Is the plugin installed and connected?');
        }

        // Build internal link candidates from the client sitemap first, then synced site data.
        $sitemap_candidates = self::getSitemapLinks($agent['site_url'] ?? '', $scheduled['title'], $focus_kw);
        $internal_candidates = self::selectInternalLinks($client_id, $scheduled['title'], $focus_kw);

        // Get "Always Link To" curated list
        $always_links = BlogScheduler::getAlwaysLinkTo($client_id);

        // Merge always-links at the front (they take priority)
        $all_links = array_merge($always_links, $sitemap_candidates, $internal_candidates);
        // Dedupe by URL, keep first occurrence
        $seen = [];
        $link_list = [];
        foreach ($all_links as $lnk) {
            $url = $lnk['url'] ?? '';
            if ($url && !isset($seen[$url])) {
                $seen[$url] = true;
                $link_list[] = $lnk;
            }
        }
        $link_list = array_slice($link_list, 0, 6); // cap at 6 candidates for the prompt

        // Format links for prompt.
        $links_prompt = '';
        foreach ($link_list as $i => $lnk) {
            $links_prompt .= ($i + 1) . '. Anchor: "' . ($lnk['anchor'] ?? $lnk['title'] ?? '') . '" → URL: ' . ($lnk['url'] ?? '') . "\n";
        }

        $external_links = ($category === 'Informational')
            ? 'Use one authoritative non-competitor citation if it genuinely supports the topic, such as a government, university, standards, or major industry source.'
            : 'No external links required unless they genuinely support the topic.';

        // Generate full post content via AI
        $ai_result = AIEngine::generate(
            'blog_post_full',
            [
                'business_name'              => $biz_name,
                'services'                   => $services,
                'location'                   => $location,
                'title'                      => $scheduled['title'],
                'category_type'              => $category,
                'focus_keyword'              => $focus_kw,
                'url_slug'                   => sanitize_title($scheduled['title']),
                'tone'                       => $tone,
                'keyword_context'            => $keyword_context,
                'internal_links'             => $links_prompt ?: 'No internal link candidates available.',
                'external_links'             => $external_links,
            ],
            $client_id,
            [
                'max_tokens'  => 7000,
                'no_cache'    => true,
                'temperature' => 0.85,
            ]
        );

        if (!$ai_result['success']) {
            return self::fail($schedule_id, 'AI generation failed: ' . ($ai_result['error'] ?? 'unknown error'));
        }

        // Parse structured AI response
        $parsed = self::parseAIResponse($ai_result['content']);
        if (!$parsed) {
            return self::fail($schedule_id, 'Failed to parse AI response. Raw output: ' . substr($ai_result['content'], 0, 300));
        }

        // Sanitize the H1 so SEO plugin template tokens (%page%, %sep%, %sitename%,
        // etc.) can never end up as the WordPress post title.
        $parsed['h1'] = self::sanitizeTitle($parsed['h1']);

        // Store generated content
        BlogScheduler::updatePost($schedule_id, [
            'generated_title' => $parsed['h1'],
            'generated_meta'  => $parsed['meta'],
            'generated_body'  => $parsed['body'],
            'generated_toc'   => $parsed['toc'],
            'tokens_used'     => $ai_result['tokens_used'] ?? 0,
            'internal_links'  => wp_json_encode($link_list),
            'status'          => 'publishing',
        ]);

        // Build Elementor JSON — use per-site template if one is saved for this agent key
        $elementor_json = self::buildElementorJson($parsed, (int)($scheduled['agent_key_id'] ?? 0));

        // Push to client site
        $push_result = self::pushToAgent($agent, [
            'title'            => $parsed['h1'],
            'meta_description' => $parsed['meta'],
            'post_content'     => wp_strip_all_tags($parsed['body']),
            'elementor_data'   => $elementor_json,
            'categories'       => [$category],
            'status'           => 'publish',
            'focus_keyword'    => $focus_kw,
            'hide_title'       => true,  // always hide the WordPress post title; H1 comes from Elementor
        ]);

        if (!$push_result['success']) {
            return self::fail($schedule_id, 'Failed to push to client site: ' . ($push_result['message'] ?? 'unknown error'));
        }

        // Mark published
        BlogScheduler::updatePost($schedule_id, [
            'status'        => 'published',
            'wp_post_id'    => $push_result['post_id'] ?? null,
            'wp_post_url'   => $push_result['post_url'] ?? '',
            'error_message' => null,
        ]);

        // Hub notification
        $post_url  = $push_result['post_url'] ?? '';
        $post_title = $parsed['h1'];
        BlogScheduler::addNotification(
            'blog_published',
            '✅ Blog Post Published: ' . $post_title,
            'Published to ' . ($agent['site_url'] ?? $client_id) . '. Remember to add a featured image!',
            $post_url,
            $client_id
        );

        SEOHub::log('blog_published', [
            'client_id'  => $client_id,
            'schedule_id'=> $schedule_id,
            'post_url'   => $post_url,
        ], 'success', 'cron');

        return ['success' => true, 'post_url' => $post_url, 'message' => 'Published: ' . $post_title];
    }

    // ── Internal Link Selection ─────────────────────────────────────────────

    /**
     * Score and return the top internal link candidates from synced site data.
     * Scores by: keyword match in title > focus_keyword match > word count.
     */
    private static function selectInternalLinks(string $client_id, string $post_title, string $focus_kw): array
    {
        global $wpdb;

        // Get published pages with reasonable word count
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT page_url, title, focus_keyword, word_count, page_type
                 FROM {$wpdb->prefix}wnq_seo_site_data
                 WHERE client_id = %s
                   AND post_status = 'publish'
                   AND word_count >= 200
                   AND page_type IN ('post', 'page')
                 ORDER BY word_count DESC
                 LIMIT 50",
                $client_id
            ),
            ARRAY_A
        ) ?: [];

        if (empty($rows)) {
            return [];
        }

        // Score each page for relevance to the new post
        $title_words  = self::extractKeywords($post_title . ' ' . $focus_kw);
        $scored = [];
        foreach ($rows as $row) {
            $page_words = self::extractKeywords($row['title'] . ' ' . $row['focus_keyword']);
            $overlap    = count(array_intersect($title_words, $page_words));
            $scored[]   = [
                'url'    => $row['page_url'],
                'anchor' => $row['title'],
                'score'  => $overlap * 10 + min($row['word_count'] / 100, 5),
            ];
        }

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Return top 4 candidates
        return array_slice($scored, 0, 4);
    }

    /**
     * Pull internal link candidates from https://client-site.com/page-sitemap.xml.
     */
    private static function getSitemapLinks(string $site_url, string $post_title, string $focus_kw): array
    {
        $site_url = rtrim($site_url, '/');
        if (empty($site_url)) {
            return [];
        }

        $sitemap_url = $site_url . '/page-sitemap.xml';
        $response = wp_remote_get($sitemap_url, [
            'timeout'     => 8,
            'redirection' => 3,
            'headers'     => [
                'User-Agent' => 'WebNique-SEO-OS/1.0',
            ],
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return [];
        }

        preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $body, $matches);
        $urls = array_values(array_unique(array_map('trim', $matches[1] ?? [])));
        if (empty($urls)) {
            return [];
        }

        $topic_words = self::extractKeywords($post_title . ' ' . $focus_kw);
        $scored = [];
        foreach ($urls as $url) {
            $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
            if ($path === '' || stripos($path, 'privacy') !== false || stripos($path, 'terms') !== false) {
                continue;
            }

            $anchor = self::anchorFromUrl($url);
            $page_words = self::extractKeywords($anchor . ' ' . str_replace(['-', '/'], ' ', $path));
            $overlap = count(array_intersect($topic_words, $page_words));

            $scored[] = [
                'url'    => esc_url_raw($url),
                'anchor' => $anchor,
                'score'  => $overlap * 10 + (str_contains($path, 'service') ? 3 : 0),
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, 6);
    }

    private static function anchorFromUrl(string $url): string
    {
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        $last = basename($path);
        $anchor = str_replace(['-', '_'], ' ', $last ?: $path);
        $anchor = trim(preg_replace('/\s+/', ' ', $anchor));
        return $anchor ? ucwords($anchor) : 'Related page';
    }

    /**
     * Extract meaningful keywords from text (stop-word filtered).
     */
    private static function extractKeywords(string $text): array
    {
        $stop = ['a','an','the','and','or','but','in','on','at','to','for','of','with','by','from','is','are','was','were','be','been','being','have','has','had','do','does','did','will','would','could','should','may','might','this','that','these','those','it','its','we','you','he','she','they','our','your','his','her','their'];
        $words = preg_split('/\W+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_diff($words, $stop));
    }

    // ── Elementor JSON Builder ──────────────────────────────────────────────

    /**
     * Inject AI-generated content into the stored Elementor template.
     * Falls back to empty structure if no template is saved.
     */
    private static function buildElementorJson(array $content, int $agent_key_id = 0): string
    {
        // Try per-site template first, then fall back to the global template
        $template_json = '';
        if ($agent_key_id > 0) {
            $template_json = get_option('wnq_blog_template_site_' . $agent_key_id, '');
        }
        if (empty($template_json)) {
            $template_json = get_option('wnq_blog_elementor_template', '');
        }

        if (empty($template_json)) {
            // No template stored — return minimal Elementor structure
            return wp_json_encode([
                [
                    'id'       => self::generateElId(),
                    'elType'   => 'section',
                    'settings' => [],
                    'elements' => [
                        [
                            'id'       => self::generateElId(),
                            'elType'   => 'column',
                            'settings' => ['_column_size' => 100],
                            'elements' => [
                                [
                                    'id'         => self::generateElId(),
                                    'elType'     => 'widget',
                                    'widgetType' => 'text-editor',
                                    'settings'   => ['editor' => $content['body']],
                                    'elements'   => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        }

        $template = json_decode($template_json, true);
        if (!is_array($template)) {
            return $template_json; // Return as-is if JSON invalid
        }

        // Handle Elementor export format: {"content":[...], "page_settings":{...}, ...}
        // _elementor_data only stores the content array, not the outer wrapper.
        $elements = isset($template['content']) && is_array($template['content'])
            ? $template['content']
            : $template;

        // Walk and inject content by known widget IDs
        $elements = self::walkAndInject($elements, $content);

        return wp_json_encode($elements);
    }

    /**
     * Recursively walk Elementor element tree and inject content by widget ID.
     *
     * Known injection points (from the WebNique blog template):
     *  5af58bd2 → heading widget  → settings.title  (H1)
     *  5b794435 → text-editor     → settings.editor (body HTML)
     *  4861ee91 → text-editor     → settings.editor (TOC HTML)
     *  1b605b78 → image widget    → left empty (user adds manually)
     */
    private static function walkAndInject(array $elements, array $content): array
    {
        foreach ($elements as &$el) {
            $id = $el['id'] ?? '';

            if ($id === '5af58bd2' && !empty($content['h1'])) {
                $el['settings']['title'] = esc_html($content['h1']);
            } elseif ($id === '5b794435' && !empty($content['body'])) {
                $el['settings']['editor'] = $content['body'];
            } elseif ($id === '4861ee91' && !empty($content['toc'])) {
                $el['settings']['editor'] = $content['toc'];
            }
            // 1b605b78 (image) → intentionally left as-is

            if (!empty($el['elements'])) {
                $el['elements'] = self::walkAndInject($el['elements'], $content);
            }
        }
        return $elements;
    }

    private static function generateElId(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    // ── AI Response Parser ──────────────────────────────────────────────────

    /**
     * Parse the structured blog_post_full AI response.
     * Expected delimiters: ===H1===, ===META===, ===TOC===, ===BODY===, ===END===
     */
    private static function parseAIResponse(string $raw): ?array
    {
        $sections = ['h1' => '', 'meta' => '', 'toc' => '', 'body' => ''];

        preg_match('/===H1===\s*(.*?)\s*(?====META===)/s', $raw, $m);
        $sections['h1'] = trim($m[1] ?? '');

        preg_match('/===META===\s*(.*?)\s*(?====TOC===)/s', $raw, $m);
        $sections['meta'] = trim($m[1] ?? '');

        preg_match('/===TOC===\s*(.*?)\s*(?====BODY===)/s', $raw, $m);
        $sections['toc'] = trim($m[1] ?? '');

        preg_match('/===BODY===\s*(.*?)\s*(?====END===|$)/s', $raw, $m);
        $sections['body'] = trim($m[1] ?? '');

        // Require at minimum an H1 and some body content
        if (empty($sections['h1']) || empty($sections['body'])) {
            return null;
        }

        return $sections;
    }

    // ── Agent Push ──────────────────────────────────────────────────────────

    /**
     * Push a post to the client WordPress site via the agent REST endpoint.
     */
    private static function pushToAgent(array $agent, array $post_data): array
    {
        $site_url = rtrim($agent['site_url'] ?? '', '/');
        $api_key  = $agent['api_key'] ?? '';

        if (empty($site_url) || empty($api_key)) {
            return ['success' => false, 'message' => 'Missing agent site_url or api_key'];
        }

        $url = $site_url . '/wp-json/wnq-agent/v1/publish-post';

        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => [
                'X-WNQ-Api-Key' => $api_key,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'WebNique-SEO-OS/1.0',
            ],
            'body' => wp_json_encode($post_data),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code     = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body     = json_decode($raw_body, true) ?? [];

        if ($code === 200 || $code === 201) {
            return [
                'success'  => true,
                'post_id'  => $body['post_id'] ?? null,
                'post_url' => $body['post_url'] ?? '',
            ];
        }

        // BlogReceiver returns 'error' key; WordPress REST core uses 'message'
        $error_msg = $body['message'] ?? $body['error'] ?? null;
        if ($error_msg && !empty($body['context'])) {
            $error_msg .= ' [' . $body['context'] . ']';
        }
        // Fallback: include truncated raw body so we can diagnose unexpected responses
        return ['success' => false, 'message' => $error_msg ?? "HTTP $code — " . substr($raw_body, 0, 300)];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Get an agent key for a client.
     * If $agent_key_id is provided, fetch that specific site.
     * Otherwise fall back to the most-recently-connected active site.
     */
    private static function getActiveAgent(string $client_id, int $agent_key_id = 0): ?array
    {
        global $wpdb;

        if ($agent_key_id > 0) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wnq_seo_agent_keys
                     WHERE id = %d AND client_id = %s AND status = 'active'",
                    $agent_key_id, $client_id
                ),
                ARRAY_A
            );
            if ($row) return $row;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_seo_agent_keys
                 WHERE client_id = %s AND status = 'active'
                 ORDER BY created_at DESC
                 LIMIT 1",
                $client_id
            ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Build keyword context string for the AI prompt from tracked clusters + top keywords.
     */
    private static function buildKeywordContext(string $client_id, array $profile): string
    {
        global $wpdb;

        $lines = [];

        // Keyword clusters from SEO profile
        $clusters = $profile['keyword_clusters'] ?? [];
        if (!empty($clusters) && is_array($clusters)) {
            $lines[] = 'Keyword Clusters (naturally weave in relevant terms):';
            foreach (array_slice($clusters, 0, 4) as $cluster => $keywords) {
                if (is_array($keywords) && !empty($keywords)) {
                    $lines[] = '- ' . $cluster . ': ' . implode(', ', array_slice($keywords, 0, 6));
                }
            }
        }

        // Top tracked keywords by impressions
        $top_kws = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT keyword FROM {$wpdb->prefix}wnq_seo_keywords
                 WHERE client_id = %s
                 ORDER BY impressions DESC, clicks DESC
                 LIMIT 10",
                $client_id
            )
        ) ?: [];

        if (!empty($top_kws)) {
            $lines[] = 'Top Tracked Keywords (use where natural): ' . implode(', ', $top_kws);
        }

        return !empty($lines) ? implode("\n", $lines) : 'No keyword data available.';
    }

    /**
     * Strip SEO plugin template tokens from a title before it is stored or
     * pushed as a WordPress post title.
     *
     * Yoast/RankMath append patterns like %page%, %sep%, %sitename% to their
     * internal title templates. If those strings ever make it into the post
     * title field (e.g. via AI hallucination or agent round-tripping) this
     * removes them so the saved title is always clean human-readable text.
     */
    private static function sanitizeTitle(string $title): string
    {
        // Remove %token% patterns (Yoast / RankMath template variables)
        $title = preg_replace('/%[a-z_]+%/i', '', $title);
        // Remove trailing separators that Yoast inserts between title parts
        $title = preg_replace('/\s*[\|\-\–\—]+\s*$/', '', $title);
        return trim($title);
    }

    /**
     * Mark a schedule entry as failed and record the error.
     */
    private static function fail(int $schedule_id, string $message): array
    {
        BlogScheduler::updatePost($schedule_id, [
            'status'        => 'failed',
            'error_message' => $message,
        ]);
        SEOHub::log('blog_publish_failed', ['schedule_id' => $schedule_id, 'error' => $message], 'failed', 'cron');
        return ['success' => false, 'message' => $message];
    }
}
