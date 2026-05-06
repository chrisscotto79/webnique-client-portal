<?php
/**
 * Service + City Draft Page Receiver
 *
 * Endpoint: POST /wp-json/wnq-agent/v1/create-service-city-page
 *
 * Creates draft child pages from the WebNique SEO OS hub. Pages are never
 * published automatically.
 *
 * @package WebNique SEO Agent
 */

namespace WNQA;

if (!defined('ABSPATH')) {
    exit;
}

final class ServiceCityReceiver
{
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void
    {
        register_rest_route('wnq-agent/v1', '/create-service-city-page', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleCreate'],
            'permission_callback' => [self::class, 'authenticate'],
        ]);
    }

    public static function authenticate(\WP_REST_Request $request)
    {
        $config = get_option('wnqa_config', []);
        $my_key = $config['api_key'] ?? '';

        if (empty($my_key)) {
            return new \WP_Error('not_configured', 'Agent plugin is not configured.', ['status' => 503]);
        }

        $sent_key = $request->get_header('X-WNQ-Api-Key') ?: ($request->get_param('api_key') ?? '');
        if (empty($sent_key) || !hash_equals($my_key, $sent_key)) {
            return new \WP_Error('invalid_api_key', 'Invalid API key.', ['status' => 403]);
        }

        return true;
    }

    public static function handleCreate(\WP_REST_Request $request)
    {
        try {
            return self::doCreate($request);
        } catch (\Throwable $e) {
            error_log('WNQ ServiceCityReceiver error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new \WP_REST_Response([
                'error'   => $e->getMessage(),
                'context' => basename($e->getFile()) . ':' . $e->getLine(),
            ], 500);
        }
    }

    private static function doCreate(\WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }

        $title = sanitize_text_field($body['title'] ?? '');
        $title_tag = sanitize_text_field($body['title_tag'] ?? $title);
        $meta_desc = sanitize_text_field($body['meta_description'] ?? '');
        $h1 = sanitize_text_field($body['h1'] ?? $title);
        $focus_kw = sanitize_text_field($body['focus_keyword'] ?? '');
        $slug = sanitize_title($body['slug'] ?? '');
        $parent_slug = sanitize_title($body['parent_service_slug'] ?? '');
        $post_content = wp_kses_post($body['post_content'] ?? '');
        $elementor_raw = isset($body['elementor_data']) ? (string)$body['elementor_data'] : '';
        $source_row_id = sanitize_text_field($body['source_row_id'] ?? '');

        if ($title === '' || $slug === '') {
            return new \WP_REST_Response(['error' => 'title and slug are required'], 400);
        }
        if ($parent_slug === '') {
            return new \WP_REST_Response(['error' => 'parent_service_slug is required for child pages'], 400);
        }

        $existing_id = self::findExistingPageBySlug($slug);
        if ($existing_id) {
            return new \WP_REST_Response([
                'status'  => 'skipped',
                'message' => 'Slug already exists on this site.',
                'page_id' => $existing_id,
                'page_url'=> get_permalink($existing_id),
            ], 200);
        }

        $parent_id = self::findParentPageId($parent_slug);
        if (!$parent_id) {
            return new \WP_REST_Response(['error' => 'Parent service page not found for slug: ' . $parent_slug], 404);
        }

        $saved_hooks = null;
        self::suspendSavePostHooks($saved_hooks);
        try {
            $page_id = wp_insert_post([
                'post_title'     => $title,
                'post_name'      => $slug,
                'post_content'   => $post_content,
                'post_excerpt'   => wp_strip_all_tags($meta_desc),
                'post_status'    => 'draft',
                'post_type'      => 'page',
                'post_parent'    => $parent_id,
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ], true);
        } finally {
            self::restoreSavePostHooks($saved_hooks);
        }

        if (is_wp_error($page_id)) {
            return new \WP_REST_Response(['error' => $page_id->get_error_message()], 500);
        }

        if ($elementor_raw !== '') {
            $elementor = json_decode($elementor_raw, true);
            if (is_array($elementor)) {
                update_post_meta($page_id, '_elementor_edit_mode', 'builder');
                update_post_meta($page_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
                update_post_meta($page_id, '_elementor_page_settings', ['hide_title' => 'yes']);
                update_post_meta($page_id, '_elementor_data', wp_slash(wp_json_encode($elementor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
            }
        }

        update_post_meta($page_id, '_wnq_service_city_source_row_id', $source_row_id);
        update_post_meta($page_id, '_wnq_service_city_slug', $slug);
        update_post_meta($page_id, '_wnq_parent_service_slug', $parent_slug);
        update_post_meta($page_id, '_wnq_h1', $h1);
        update_post_meta($page_id, '_meta_description', $meta_desc);

        if (defined('WPSEO_VERSION')) {
            update_post_meta($page_id, '_yoast_wpseo_title', $title_tag);
            update_post_meta($page_id, '_yoast_wpseo_metadesc', $meta_desc);
            if ($focus_kw !== '') {
                update_post_meta($page_id, '_yoast_wpseo_focuskw', $focus_kw);
            }
        }
        if (class_exists('RankMath')) {
            update_post_meta($page_id, 'rank_math_title', $title_tag);
            update_post_meta($page_id, 'rank_math_description', $meta_desc);
            if ($focus_kw !== '') {
                update_post_meta($page_id, 'rank_math_focus_keyword', $focus_kw);
            }
        }

        return new \WP_REST_Response([
            'status'    => 'draft_created',
            'page_id'   => (int)$page_id,
            'parent_id' => (int)$parent_id,
            'page_url'  => get_permalink($page_id),
            'edit_url'  => admin_url('post.php?post=' . (int)$page_id . '&action=edit'),
        ], 201);
    }

    private static function findExistingPageBySlug(string $slug): int
    {
        global $wpdb;
        return (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type='page' AND post_name=%s AND post_status NOT IN ('trash','auto-draft')
                 LIMIT 1",
                $slug
            )
        );
    }

    private static function findParentPageId(string $slug): int
    {
        $page = get_page_by_path($slug, OBJECT, 'page');
        if ($page && !is_wp_error($page)) {
            return (int)$page->ID;
        }

        global $wpdb;
        return (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type='page' AND post_name=%s AND post_status NOT IN ('trash','auto-draft')
                 ORDER BY post_parent ASC, ID ASC
                 LIMIT 1",
                $slug
            )
        );
    }

    private static function suspendSavePostHooks(&$snapshot): void
    {
        global $wp_filter;
        $snapshot = [];
        foreach (['save_post', 'save_post_page', 'edit_post', 'post_updated', 'wp_after_insert_post', 'wp_insert_post'] as $hook) {
            if (isset($wp_filter[$hook])) {
                $snapshot[$hook] = $wp_filter[$hook];
                unset($wp_filter[$hook]);
            }
        }
    }

    private static function restoreSavePostHooks(&$snapshot): void
    {
        global $wp_filter;
        foreach ((array)$snapshot as $hook => $hook_obj) {
            $wp_filter[$hook] = $hook_obj;
        }
        $snapshot = null;
    }
}
