<?php
/**
 * Blog Receiver
 *
 * Registers a REST endpoint on the client WordPress site that the
 * WebNique SEO OS hub calls to publish AI-generated blog posts.
 *
 * Endpoint: POST /wp-json/wnq-agent/v1/publish-post
 *
 * Auth: X-WNQ-Api-Key header must match the API key stored in wnqa_config.
 *
 * Payload:
 * {
 *   "title":            "SEO H1 title",
 *   "meta_description": "150-160 char meta",
 *   "post_content":     "Plain-text fallback body",
 *   "elementor_data":   "[{...}]",
 *   "categories":       ["Services"],
 *   "status":           "publish",
 *   "focus_keyword":    "keyword phrase"
 * }
 *
 * @package WebNique SEO Agent
 */

namespace WNQA;

if (!defined('ABSPATH')) {
    exit;
}

final class BlogReceiver
{
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void
    {
        register_rest_route('wnq-agent/v1', '/publish-post', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handlePublish'],
            'permission_callback' => [self::class, 'authenticate'],
        ]);
    }

    // ── Authentication ──────────────────────────────────────────────────────

    public static function authenticate(\WP_REST_Request $request)
    {
        $config  = get_option('wnqa_config', []);
        $my_key  = $config['api_key'] ?? '';

        if (empty($my_key)) {
            return new \WP_Error('not_configured', 'Agent plugin is not configured.', ['status' => 503]);
        }

        $sent_key = $request->get_header('X-WNQ-Api-Key')
            ?? $request->get_param('api_key')
            ?? '';

        if (empty($sent_key) || !hash_equals($my_key, $sent_key)) {
            return new \WP_Error('invalid_api_key', 'Invalid API key.', ['status' => 403]);
        }

        return true;
    }

    // ── Handler ─────────────────────────────────────────────────────────────

    public static function handlePublish(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params() ?? [];

        $title         = sanitize_text_field($body['title'] ?? '');
        $post_content  = wp_kses_post($body['post_content'] ?? '');
        $meta_desc     = sanitize_text_field($body['meta_description'] ?? '');
        $elementor_raw = $body['elementor_data'] ?? '';
        $categories    = array_map('sanitize_text_field', (array)($body['categories'] ?? []));
        $status        = in_array($body['status'] ?? 'publish', ['publish', 'draft'], true)
                         ? $body['status']
                         : 'publish';
        $focus_kw      = sanitize_text_field($body['focus_keyword'] ?? '');

        if (empty($title)) {
            return new \WP_REST_Response(['error' => 'title is required'], 400);
        }

        // Resolve category term IDs (create if they don't exist — never hardcode IDs)
        $cat_ids = [];
        foreach ($categories as $cat_name) {
            if (empty($cat_name)) continue;
            $term = get_term_by('name', $cat_name, 'category');
            if ($term) {
                $cat_ids[] = (int)$term->term_id;
            } else {
                $new_term = wp_create_term($cat_name, 'category');
                if (!is_wp_error($new_term)) {
                    $cat_ids[] = (int)$new_term['term_id'];
                }
            }
        }

        // Create the WordPress post
        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $post_content,
            'post_status'  => $status,
            'post_type'    => 'post',
            'post_category'=> $cat_ids,
        ], true);

        if (is_wp_error($post_id)) {
            return new \WP_REST_Response(['error' => $post_id->get_error_message()], 500);
        }

        // Set Elementor data
        if (!empty($elementor_raw)) {
            // Validate it's parseable JSON
            $elementor_arr = json_decode($elementor_raw, true);
            if (is_array($elementor_arr)) {
                update_post_meta($post_id, '_elementor_data', $elementor_raw);
                update_post_meta($post_id, '_elementor_edit_mode', 'builder');
                update_post_meta($post_id, '_elementor_template_type', 'wp-post');
                update_post_meta($post_id, '_elementor_version', '3.0.0');
                // Ensure page is hidden H1 (Elementor heading widget is the H1)
                update_post_meta($post_id, '_elementor_page_settings', ['hide_title' => 'yes']);
            }
        }

        // Set SEO meta (Yoast, RankMath, or generic)
        if (!empty($meta_desc)) {
            // Yoast SEO
            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
                if (!empty($focus_kw)) {
                    update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_kw);
                }
            }
            // RankMath
            if (class_exists('RankMath')) {
                update_post_meta($post_id, 'rank_math_description', $meta_desc);
                if (!empty($focus_kw)) {
                    update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);
                }
            }
            // Generic fallback
            update_post_meta($post_id, '_meta_description', $meta_desc);
        }

        $post_url = get_permalink($post_id);

        return new \WP_REST_Response([
            'status'   => 'published',
            'post_id'  => $post_id,
            'post_url' => $post_url,
        ], 201);
    }
}
