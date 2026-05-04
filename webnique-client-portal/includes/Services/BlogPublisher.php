<?php
/**
 * Blog Publisher Service
 *
 * Orchestrates the full blog auto-publishing pipeline:
 *  1. Load post from the schedule queue
 *  2. Build AI vars from client profile + synced site data
 *  3. Call AIEngine::generate('blog_post_full') — returns H1, META, TOC, BODY
 *  4. Parse and normalize structured AI response
 *  5. Inject content into stored Elementor JSON template
 *  6. Push to client site via POST {site_url}/wp-json/wnq-agent/v1/publish-post
 *  7. Update schedule record, fire hub notification
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

        if ($scheduled['status'] === 'published') {
            return [
                'success' => true,
                'message' => 'Post is already published',
                'post_url' => $scheduled['wp_post_url'] ?? '',
            ];
        }

        if (in_array($scheduled['status'], ['generating', 'publishing'], true)) {
            return [
                'success' => true,
                'message' => 'Post is already being processed',
            ];
        }

        if (!in_array($scheduled['status'], ['pending', 'failed'], true)) {
            return ['success' => false, 'message' => 'Post cannot be processed from status: ' . ($scheduled['status'] ?? 'unknown')];
        }

        if (!empty($scheduled['generated_body'])) {
            $agent = self::getActiveAgent($scheduled['client_id'], (int)($scheduled['agent_key_id'] ?? 0));
            if (!$agent) {
                return self::fail($schedule_id, 'No active agent key found for client. Is the plugin installed and connected?');
            }

            $parsed = [
                'h1'  => self::sanitizeTitle($scheduled['generated_title'] ?: $scheduled['title']),
                'meta' => $scheduled['generated_meta'] ?? '',
                'toc'  => $scheduled['generated_toc'] ?? '',
                'body' => self::normalizeGeneratedBody($scheduled['generated_body'] ?? ''),
            ];
            BlogScheduler::updatePost($schedule_id, ['status' => 'publishing']);
            return self::publishGeneratedPost($schedule_id, $scheduled, $agent, $parsed);
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
        $category    = 'Informational';
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
            ],
            $client_id,
            [
                'max_tokens'  => AIEngine::maxTokensForBlogGeneration(),
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
            return self::fail($schedule_id, self::incompleteAIResponseMessage($ai_result['content']));
        }
        $parsed['body'] = self::normalizeGeneratedBody($parsed['body']);

        // Keep the public post title simple: use the queued title, not an
        // AI-expanded H1 that may append keywords, locations, or subtitles.
        $parsed['h1'] = self::sanitizeTitle($scheduled['title']);

        // Store generated content
        BlogScheduler::updatePost($schedule_id, [
            'generated_title' => $parsed['h1'],
            'generated_meta'  => $parsed['meta'],
            'generated_body'  => $parsed['body'],
            'generated_toc'   => $parsed['toc'],
            'tokens_used'     => $ai_result['tokens_used'] ?? 0,
            'internal_links'  => wp_json_encode([]),
            'category_type'   => 'Informational',
            'status'          => 'publishing',
        ]);

        return self::publishGeneratedPost($schedule_id, $scheduled, $agent, $parsed, $category, $focus_kw);
    }

    public static function generateContentOnly(int $schedule_id): array
    {
        $result = self::generateAndStoreContent($schedule_id, 'pending');
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => 'Content generated',
            'title'   => $result['parsed']['h1'] ?? '',
        ];
    }

    private static function generateAndStoreContent(int $schedule_id, string $final_status): array
    {
        $scheduled = BlogScheduler::getPost($schedule_id);
        if (!$scheduled) {
            return ['success' => false, 'message' => 'Schedule entry not found'];
        }

        if (!in_array($scheduled['status'], ['pending', 'failed'], true)) {
            return ['success' => false, 'message' => 'Post must be pending or failed to generate content'];
        }

        BlogScheduler::updatePost($schedule_id, ['status' => 'generating', 'error_message' => null]);

        $client_id = $scheduled['client_id'];
        $profile = SEOHub::getProfile($client_id);
        if (!$profile) {
            return self::fail($schedule_id, 'No SEO profile found for client: ' . $client_id);
        }

        $client      = Client::getByClientId($client_id) ?? [];
        $biz_name    = $client['company'] ?? $client['name'] ?? $client_id;
        $services    = implode(', ', (array)($profile['primary_services'] ?? []));
        $location    = implode(', ', (array)($profile['service_locations'] ?? []));
        $tone        = $profile['content_tone'] ?? 'professional';
        $category    = 'Informational';
        $focus_kw    = $scheduled['focus_keyword'] ?? '';

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

        $agent = self::getActiveAgent($client_id, (int)($scheduled['agent_key_id'] ?? 0));
        if (!$agent) {
            return self::fail($schedule_id, 'No active agent key found for client. Is the plugin installed and connected?');
        }

        $keyword_context = self::buildKeywordContext($client_id, $profile);
        $ai_result = AIEngine::generate(
            'blog_post_full',
            [
                'business_name'   => $biz_name,
                'services'        => $services,
                'location'        => $location,
                'title'           => $scheduled['title'],
                'category_type'   => $category,
                'focus_keyword'   => $focus_kw,
                'url_slug'        => sanitize_title($scheduled['title']),
                'tone'            => $tone,
                'keyword_context' => $keyword_context,
            ],
            $client_id,
            [
                'max_tokens'  => AIEngine::maxTokensForBlogGeneration(),
                'no_cache'    => true,
                'temperature' => 0.85,
            ]
        );

        if (!$ai_result['success']) {
            return self::fail($schedule_id, 'AI generation failed: ' . ($ai_result['error'] ?? 'unknown error'));
        }

        $parsed = self::parseAIResponse($ai_result['content']);
        if (!$parsed) {
            return self::fail($schedule_id, self::incompleteAIResponseMessage($ai_result['content']));
        }
        $parsed['body'] = self::normalizeGeneratedBody($parsed['body']);

        $parsed['h1'] = self::sanitizeTitle($scheduled['title']);
        BlogScheduler::updatePost($schedule_id, [
            'generated_title' => $parsed['h1'],
            'generated_meta'  => $parsed['meta'],
            'generated_body'  => $parsed['body'],
            'generated_toc'   => $parsed['toc'],
            'tokens_used'     => $ai_result['tokens_used'] ?? 0,
            'internal_links'  => wp_json_encode([]),
            'category_type'   => 'Informational',
            'status'          => $final_status,
            'error_message'   => null,
        ]);

        return [
            'success' => true,
            'parsed'  => $parsed,
            'agent'   => $agent,
            'category'=> $category,
            'focus_kw'=> $focus_kw,
        ];
    }

    private static function publishGeneratedPost(
        int $schedule_id,
        array $scheduled,
        array $agent,
        array $parsed,
        string $category = '',
        string $focus_kw = ''
    ): array {
        $category = 'Informational';
        $focus_kw = $focus_kw ?: ($scheduled['focus_keyword'] ?? '');

        $featured_image_url = esc_url_raw($scheduled['featured_image_url'] ?? '');
        $elementor_json = self::buildElementorJson(
            $parsed,
            (int)($scheduled['agent_key_id'] ?? 0),
            $featured_image_url
        );

        $push_result = self::pushToAgent($agent, [
            'title'             => $parsed['h1'],
            'meta_description'  => $parsed['meta'],
            'post_content'      => $parsed['body'],
            'elementor_data'    => $elementor_json,
            'categories'        => [$category],
            'status'            => 'publish',
            'focus_keyword'     => $focus_kw,
            'featured_image_url'=> $featured_image_url,
            'source_schedule_id'=> $schedule_id,
            'hide_title'        => true,
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
            'category_type'  => 'Informational',
        ]);

        // Hub notification
        $post_url  = $push_result['post_url'] ?? '';
        $post_title = $parsed['h1'];
        BlogScheduler::addNotification(
            'blog_published',
            '✅ Blog Post Published: ' . $post_title,
            'Published to ' . ($agent['site_url'] ?? $client_id) . '.',
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

    // ── Elementor JSON Builder ──────────────────────────────────────────────

    /**
     * Inject AI-generated content into the stored Elementor template.
     * Falls back to empty structure if no template is saved.
     */
    private static function buildElementorJson(array $content, int $agent_key_id = 0, string $featured_image_url = ''): string
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

        if (!empty($featured_image_url)) {
            $content['featured_image_url'] = $featured_image_url;
        }

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
     *  1b605b78 → image widget    → settings.image (featured image URL)
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
            } elseif ($id === '1b605b78' && !empty($content['featured_image_url'])) {
                $el['settings']['image'] = [
                    'url'    => esc_url_raw($content['featured_image_url']),
                    'id'     => 0,
                    'size'   => 'full',
                    'source' => 'url',
                ];
                $el['settings']['image_size'] = $el['settings']['image_size'] ?? 'large';
            } elseif ($id === '1b605b78' && !empty($el['settings']['image']['url'])) {
                $el['settings']['image']['id'] = 0;
                $el['settings']['image']['source'] = 'url';
            }

            if (!empty($el['elements'])) {
                $el['elements'] = self::walkAndInject($el['elements'], $content);
            }
        }
        return $elements;
    }

    /**
     * Clean up common AI formatting drift before storing/publishing.
     */
    private static function normalizeGeneratedBody(string $body): string
    {
        $body = trim($body);
        $body = preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $body);
        $body = preg_replace('/<a\b[^>]*>(.*?)<\/a>/is', '$1', $body);
        $body = preg_replace('/\bIn conclusion,\s*/i', '', $body);
        $body = preg_replace('/\s*(#{3})\s*([^#<\r\n]+)(?=\s|$)/', "\n<h3>$2</h3>\n", $body);
        $body = preg_replace('/\s*(#{2})\s*([^#<\r\n]+)(?=\s|$)/', "\n<h2>$2</h2>\n", $body);
        $body = preg_replace('/<p>\s*(Q:\s*)?([^<\?]{8,120}\?)\s*A:\s*/i', '<h3>$2</h3><p>', $body);
        $body = preg_replace('/\s+Q:\s*([^<\?]{8,120}\?)\s*A:\s*/i', '</p><h3>$1</h3><p>', $body);
        $body = preg_replace('/\s+\*\s*([^*\r\n]{3,180})\s+\*/', "\n<ul><li>$1</li></ul>\n", $body);
        $body = preg_replace('/<\/ul>\s*<ul>/', '', $body);
        $body = preg_replace('/\*\*([^*]+)\*\*/', '$1', $body);

        if (strpos($body, '<p') === false && strpos($body, '<h2') === false && strpos($body, '<h3') === false) {
            $lines = array_filter(array_map('trim', preg_split('/\R{2,}/', $body)));
            $html = [];
            foreach ($lines as $line) {
                if (preg_match('/^#{2,3}\s+(.+)$/', $line, $m)) {
                    $html[] = '<h2>' . esc_html($m[1]) . '</h2>';
                } elseif (substr($line, -1) === ':' && strlen($line) <= 90) {
                    $html[] = '<h3>' . esc_html(rtrim($line, ':')) . '</h3>';
                } else {
                    $html[] = '<p>' . esc_html($line) . '</p>';
                }
            }
            $body = implode("\n", $html);
        } elseif (strpos($body, '<p') === false && function_exists('wpautop')) {
            $body = wpautop($body);
        }

        return trim($body);
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

    private static function incompleteAIResponseMessage(string $raw): string
    {
        $has_h1   = strpos($raw, '===H1===') !== false;
        $has_meta = strpos($raw, '===META===') !== false;
        $has_toc  = strpos($raw, '===TOC===') !== false;
        $has_body = strpos($raw, '===BODY===') !== false;

        if ($has_h1 || $has_meta || $has_toc || !$has_body) {
            return 'AI returned an incomplete blog draft. Please click Regenerate Content and try again.';
        }

        return 'AI returned content in an unexpected format. Please click Regenerate Content and try again.';
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
