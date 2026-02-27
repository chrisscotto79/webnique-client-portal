<?php
/**
 * SEO Fixer
 *
 * REST endpoint called by the WebNique SEO OS hub to apply AI-generated
 * and programmatic SEO fixes to existing posts/pages.
 *
 * Endpoint: POST /wp-json/wnq-agent/v1/fix-seo
 * Auth:     X-WNQ-Api-Key (same key stored in wnqa_config)
 *
 * Accepted body fields (all optional except post_id):
 *   post_id          int     WordPress post/page ID (required)
 *   seo_title        string  New SEO title — written to Yoast / RankMath
 *   meta_description string  New meta description — Yoast / RankMath / generic
 *   focus_keyword    string  Focus keyword — Yoast / RankMath
 *   schema_json      string  JSON-LD string (no <script> wrapper) → _wnq_schema_json
 *   h1_title         string  Un-hides WordPress title (removes Elementor hide_title)
 *                            and updates post_title if changed
 *   fix_missing_alt  bool    Updates featured image + attached image alt texts
 *                            using the post title as alt text
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

        $applied = [];

        // ── Meta description ─────────────────────────────────────────────────
        $meta_desc = isset($body['meta_description']) ? sanitize_text_field($body['meta_description']) : '';
        if (!empty($meta_desc)) {
            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
            }
            if (class_exists('RankMath')) {
                update_post_meta($post_id, 'rank_math_description', $meta_desc);
            }
            update_post_meta($post_id, '_meta_description', $meta_desc);
            $applied[] = 'meta_description';
        }

        // ── SEO title ────────────────────────────────────────────────────────
        $seo_title = isset($body['seo_title']) ? sanitize_text_field($body['seo_title']) : '';
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
        $focus_kw = isset($body['focus_keyword']) ? sanitize_text_field($body['focus_keyword']) : '';
        if (!empty($focus_kw)) {
            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_kw);
            }
            if (class_exists('RankMath')) {
                update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);
            }
            $applied[] = 'focus_keyword';
        }

        // ── Schema JSON-LD ───────────────────────────────────────────────────
        $schema_json = isset($body['schema_json']) ? $body['schema_json'] : '';
        if (!empty($schema_json) && is_string($schema_json)) {
            // Strip <script> wrapper if hub accidentally included it
            $schema_json = preg_replace('/<script[^>]*>(.*?)<\/script>/si', '$1', $schema_json);
            $schema_json = trim($schema_json);
            // Validate it's parseable JSON
            if (json_decode($schema_json) !== null) {
                update_post_meta($post_id, '_wnq_schema_json', $schema_json);
                $applied[] = 'schema_json';
            }
        }

        // ── H1 / title visibility fix ─────────────────────────────────────────
        // Sends the correct post_title and removes Elementor's hide_title setting
        // so the WordPress page title (which themes output as <h1>) becomes visible.
        $h1_title = isset($body['h1_title']) ? sanitize_text_field($body['h1_title']) : '';
        if (!empty($h1_title)) {
            // Update post_title if it differs (avoid unnecessary DB write)
            if ($post->post_title !== $h1_title) {
                wp_update_post(['ID' => $post_id, 'post_title' => $h1_title]);
            }

            // Remove Elementor's hide_title setting so the title renders as H1
            $page_settings = get_post_meta($post_id, '_elementor_page_settings', true);
            if (is_array($page_settings) && isset($page_settings['hide_title']) && $page_settings['hide_title'] === 'yes') {
                unset($page_settings['hide_title']);
                update_post_meta($post_id, '_elementor_page_settings', $page_settings);
            } elseif (is_string($page_settings) && !empty($page_settings)) {
                $decoded = json_decode($page_settings, true);
                if (is_array($decoded) && isset($decoded['hide_title'])) {
                    unset($decoded['hide_title']);
                    update_post_meta($post_id, '_elementor_page_settings', $decoded);
                }
            }

            $applied[] = 'h1_title';
        }

        // ── Missing alt text fix ──────────────────────────────────────────────
        // Uses the post title as the alt text for all images that currently lack one.
        if (!empty($body['fix_missing_alt'])) {
            $alt_base = sanitize_text_field($post->post_title);
            $images_fixed = 0;

            // Featured image
            $thumb_id = get_post_thumbnail_id($post_id);
            if ($thumb_id && !get_post_meta($thumb_id, '_wp_attachment_image_alt', true)) {
                update_post_meta($thumb_id, '_wp_attachment_image_alt', $alt_base);
                $images_fixed++;
            }

            // Attached images (gallery / media library images parented to this post)
            $attachments = get_posts([
                'post_type'      => 'attachment',
                'post_parent'    => $post_id,
                'post_mime_type' => 'image',
                'posts_per_page' => 50,
                'fields'         => 'ids',
                'post_status'    => 'inherit',
            ]);

            foreach ($attachments as $att_id) {
                if (!get_post_meta($att_id, '_wp_attachment_image_alt', true)) {
                    update_post_meta($att_id, '_wp_attachment_image_alt', $alt_base);
                    $images_fixed++;
                }
            }

            // Also scan post_content for <img> tags and try to match srcs to attachments
            if (!empty($post->post_content)) {
                preg_match_all('/class="[^"]*wp-image-(\d+)[^"]*"/', $post->post_content, $matches);
                foreach (array_unique($matches[1]) as $img_id) {
                    $img_id = (int)$img_id;
                    if ($img_id && !get_post_meta($img_id, '_wp_attachment_image_alt', true)) {
                        update_post_meta($img_id, '_wp_attachment_image_alt', $alt_base);
                        $images_fixed++;
                    }
                }
            }

            if ($images_fixed > 0) {
                $applied[] = 'image_alt_texts(' . $images_fixed . ')';
            }
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
