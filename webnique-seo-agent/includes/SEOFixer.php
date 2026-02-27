<?php
/**
 * SEO Fixer
 *
 * Registers a REST endpoint on the client WordPress site that the
 * WebNique SEO OS hub calls to automatically apply AI-generated SEO fixes
 * to existing posts/pages.
 *
 * Endpoint: POST /wp-json/wnq-agent/v1/fix-seo
 *
 * Auth: X-WNQ-Api-Key header must match the API key stored in wnqa_config.
 *
 * Accepted body fields (all optional — only non-empty values are applied):
 *   post_id          int     WordPress post/page ID to update (required)
 *   seo_title        string  New SEO title (Yoast / RankMath)
 *   meta_description string  New meta description (Yoast / RankMath / generic)
 *   focus_keyword    string  Focus keyword (Yoast / RankMath)
 *
 * @package WebNique SEO Agent
 */

namespace WNQA;

if (!defined('ABSPATH')) {
    exit;
}

final class SEOFixer
{
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void
    {
        register_rest_route('wnq-agent/v1', '/fix-seo', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleFix'],
            'permission_callback' => [self::class, 'authenticate'],
        ]);
    }

    // ── Authentication ───────────────────────────────────────────────────────

    public static function authenticate(\WP_REST_Request $request)
    {
        $config  = get_option('wnqa_config', []);
        $my_key  = $config['api_key'] ?? '';

        if (empty($my_key)) {
            return new \WP_Error('not_configured', 'Agent plugin is not configured.', ['status' => 503]);
        }

        $sent_key = $request->get_header('X-WNQ-Api-Key');
        if (empty($sent_key)) {
            $sent_key = $request->get_param('api_key') ?? '';
        }

        if (empty($sent_key) || !hash_equals($my_key, $sent_key)) {
            return new \WP_Error('invalid_api_key', 'Invalid API key.', ['status' => 403]);
        }

        return true;
    }

    // ── Handler ──────────────────────────────────────────────────────────────

    public static function handleFix(\WP_REST_Request $request)
    {
        $body    = $request->get_json_params();
        $post_id = (int)($body['post_id'] ?? 0);

        if (!$post_id) {
            return new \WP_REST_Response(['error' => 'post_id is required'], 400);
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status === 'trash') {
            return new \WP_REST_Response(['error' => 'Post not found: ' . $post_id], 404);
        }

        $meta_desc = isset($body['meta_description']) ? sanitize_text_field($body['meta_description']) : '';
        $seo_title = isset($body['seo_title'])        ? sanitize_text_field($body['seo_title'])        : '';
        $focus_kw  = isset($body['focus_keyword'])    ? sanitize_text_field($body['focus_keyword'])    : '';

        $applied = [];

        // ── Meta description ────────────────────────────────────────────────
        if (!empty($meta_desc)) {
            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
            }
            if (class_exists('RankMath')) {
                update_post_meta($post_id, 'rank_math_description', $meta_desc);
            }
            // Generic fallback — also read by our own JSON-LD output
            update_post_meta($post_id, '_meta_description', $meta_desc);
            $applied[] = 'meta_description';
        }

        // ── SEO title ────────────────────────────────────────────────────────
        if (!empty($seo_title)) {
            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
            }
            if (class_exists('RankMath')) {
                update_post_meta($post_id, 'rank_math_title', $seo_title);
            }
            $applied[] = 'seo_title';
        }

        // ── Focus keyword ─────────────────────────────────────────────────────
        if (!empty($focus_kw)) {
            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_kw);
            }
            if (class_exists('RankMath')) {
                update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);
            }
            $applied[] = 'focus_keyword';
        }

        if (empty($applied)) {
            return new \WP_REST_Response(['error' => 'No valid fix fields provided'], 400);
        }

        error_log('WNQ SEOFixer: fixed post #' . $post_id . ' — ' . implode(', ', $applied));

        return new \WP_REST_Response([
            'status'  => 'fixed',
            'post_id' => $post_id,
            'applied' => $applied,
        ], 200);
    }
}
