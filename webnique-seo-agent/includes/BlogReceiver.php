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

        $sent_key = $request->get_header('X-WNQ-Api-Key');
        if (empty($sent_key)) {
            $sent_key = $request->get_param('api_key') ?? '';
        }

        if (empty($sent_key) || !hash_equals($my_key, $sent_key)) {
            return new \WP_Error('invalid_api_key', 'Invalid API key.', ['status' => 403]);
        }

        return true;
    }

    // ── Handler ─────────────────────────────────────────────────────────────

    public static function handlePublish(\WP_REST_Request $request)
    {
        try {
            return self::doPublish($request);
        } catch (\Throwable $e) {
            error_log('WNQ BlogReceiver error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new \WP_REST_Response([
                'error'   => $e->getMessage(),
                'context' => basename($e->getFile()) . ':' . $e->getLine(),
            ], 500);
        }
    }

    private static function doPublish(\WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }

        $title         = sanitize_text_field($body['title'] ?? '');
        $post_content  = wp_kses_post($body['post_content'] ?? '');
        $meta_desc     = sanitize_text_field($body['meta_description'] ?? '');
        $elementor_raw = isset($body['elementor_data']) ? (string)$body['elementor_data'] : '';
        $categories    = array_map('sanitize_text_field', (array)($body['categories'] ?? []));
        $status        = ($body['status'] ?? 'publish') === 'draft' ? 'draft' : 'publish';
        $focus_kw      = sanitize_text_field($body['focus_keyword'] ?? '');
        $featured_image_url = esc_url_raw($body['featured_image_url'] ?? '');

        if (empty($title)) {
            return new \WP_REST_Response(['error' => 'title is required'], 400);
        }

        // SEO-friendly slug from focus keyword; fall back to title
        $post_slug = sanitize_title(!empty($focus_kw) ? $focus_kw : $title);

        // Resolve category term IDs (create if they don't exist — never hardcode IDs)
        $cat_ids = [];
        foreach ($categories as $cat_name) {
            if (empty($cat_name)) continue;
            $term = get_term_by('name', $cat_name, 'category');
            if ($term && !is_wp_error($term)) {
                $cat_ids[] = (int)$term->term_id;
            } else {
                // wp_insert_term() is always available (wp-includes); wp_create_term() is admin-only
                $new_term = wp_insert_term($cat_name, 'category');
                if (!is_wp_error($new_term) && !empty($new_term['term_id'])) {
                    $cat_ids[] = (int)$new_term['term_id'];
                }
            }
        }

        // Suspend save_post hooks for the draft insertion.
        // Some plugins (e.g. Elementor) hook into save_post and call wp_die() when
        // they encounter unexpected conditions during REST API requests, which sends
        // an error response and kills the process before our try-catch can respond.
        // We set all Elementor meta directly, so save_post processing is not needed.
        self::suspendSavePostHooks($saved_hooks);

        $post_id = wp_insert_post([
            'post_title'     => $title,
            'post_name'      => $post_slug,
            'post_content'   => $post_content,
            'post_excerpt'   => wp_strip_all_tags($meta_desc),
            'post_status'    => 'draft',
            'post_type'      => 'post',
            'post_category'  => $cat_ids,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true);

        self::restoreSavePostHooks($saved_hooks);

        if (is_wp_error($post_id)) {
            return new \WP_REST_Response(['error' => $post_id->get_error_message()], 500);
        }

        // Set Elementor data — directly via update_post_meta (no save_post fired)
        if (!empty($elementor_raw)) {
            $elementor_arr = json_decode($elementor_raw, true);
            if (is_array($elementor_arr)) {
                update_post_meta($post_id, '_elementor_data', wp_slash($elementor_raw));
                update_post_meta($post_id, '_elementor_edit_mode', 'builder');
                // Store as a PHP array — WordPress serializes it and Elementor reads it correctly.
                // hide_title removes the theme's default post title from the page.
                update_post_meta($post_id, '_elementor_page_settings', ['hide_title' => 'yes']);
            }
        }

        // Set SEO meta
        if (!empty($meta_desc)) {
            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
                if (!empty($focus_kw)) {
                    update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_kw);
                }
                // Yoast SEO title — put focus keyword first
                if (!empty($focus_kw)) {
                    update_post_meta($post_id, '_yoast_wpseo_title', $title . ' %%page%% %%sep%% %%sitename%%');
                }
            }
            if (class_exists('RankMath')) {
                update_post_meta($post_id, 'rank_math_description', $meta_desc);
                if (!empty($focus_kw)) {
                    update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);
                }
                // RankMath SEO title — explicit so keyword is at start
                update_post_meta($post_id, 'rank_math_title', $title . ' %page% %sep% %sitename%');
                // Article schema for RankMath
                update_post_meta($post_id, 'rank_math_rich_snippet', 'article');
                update_post_meta($post_id, 'rank_math_snippet_article_type', 'BlogPosting');
            }
            update_post_meta($post_id, '_meta_description', $meta_desc);
        }

        // Inject BlogPosting JSON-LD schema (plugin-agnostic fallback)
        // Stored in meta and output via wp_head if the theme/plugin doesn't cover it.
        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'BlogPosting',
            'headline'      => $title,
            'description'   => $meta_desc,
            'keywords'      => $focus_kw,
            'datePublished' => gmdate('c'),
            'dateModified'  => gmdate('c'),
            'author'        => ['@type' => 'Organization', 'name' => get_bloginfo('name')],
            'publisher'     => ['@type' => 'Organization', 'name' => get_bloginfo('name'),
                                'logo'  => ['@type' => 'ImageObject', 'url' => get_site_icon_url()]],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => get_home_url() . '/' . $post_slug . '/'],
        ];
        update_post_meta($post_id, '_wnq_schema_json', wp_json_encode($schema));

        $attachment_id = 0;
        if (!empty($featured_image_url)) {
            $attachment_id = self::sideloadFeaturedImage($featured_image_url, $post_id, $title);
            if ($attachment_id) {
                set_post_thumbnail($post_id, $attachment_id);
                $local_image_url = wp_get_attachment_image_url($attachment_id, 'full') ?: $featured_image_url;
                update_post_meta($post_id, '_wnq_og_image', $local_image_url);
                update_post_meta($post_id, '_yoast_wpseo_opengraph-image', $local_image_url);
                update_post_meta($post_id, 'rank_math_facebook_image', $local_image_url);
            }
        }

        // Publish — suspend hooks again to prevent plugin crashes on status transition
        if ($status === 'publish') {
            self::suspendSavePostHooks($saved_hooks2);
            wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
            self::restoreSavePostHooks($saved_hooks2);
        }

        $post_url = get_permalink($post_id);

        return new \WP_REST_Response([
            'status'             => 'published',
            'post_id'            => $post_id,
            'post_url'           => $post_url,
            'featured_image_set' => !empty($attachment_id),
        ], 201);
    }

    private static function sideloadFeaturedImage(string $image_url, int $post_id, string $title): int
    {
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachment_id = media_sideload_image($image_url, $post_id, $title, 'id');
        if (is_wp_error($attachment_id)) {
            error_log('WNQ featured image sideload failed: ' . $attachment_id->get_error_message());
            return 0;
        }

        update_post_meta((int)$attachment_id, '_wp_attachment_image_alt', $title);
        return (int)$attachment_id;
    }

    /**
     * Snapshot and unset hooks that 3rd-party plugins (e.g. Elementor) attach to.
     * These plugins can call wp_die() inside save_post during REST API requests,
     * which terminates the process before our try-catch can return a proper response.
     * We set all Elementor meta directly, so none of these hooks are needed.
     *
     * Uses unset() rather than remove_all_actions() to avoid mutating the WP_Hook
     * object — the original object is simply moved out of $wp_filter and restored.
     *
     * @param mixed $snapshot Populated by reference with the suspended hooks.
     */
    private static function suspendSavePostHooks(&$snapshot)
    {
        global $wp_filter;
        $snapshot = [];
        $hooks = ['save_post', 'save_post_post', 'transition_post_status'];
        foreach ($hooks as $hook) {
            if (isset($wp_filter[$hook])) {
                $snapshot[$hook] = $wp_filter[$hook];
                unset($wp_filter[$hook]);
            }
        }
    }

    /**
     * Restore hooks that were suspended by suspendSavePostHooks().
     *
     * @param mixed $snapshot The snapshot populated by suspendSavePostHooks().
     */
    private static function restoreSavePostHooks(&$snapshot)
    {
        global $wp_filter;
        foreach ((array)$snapshot as $hook => $hook_obj) {
            $wp_filter[$hook] = $hook_obj;
        }
        $snapshot = null;
    }
}
