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
 *   fix_open_graph   bool    Writes og:title / og:description / og:image to
 *                            Yoast, RankMath, and generic _wnq_og_* meta
 *   og_title         string  OG title override (defaults to post title)
 *   og_description   string  OG description override
 *   og_image         string  OG image URL override (defaults to featured image)
 *   fix_image_lazy_load bool Add loading="lazy" to content <img> tags
 *   internal_links   array   [{anchor, url}, …] to insert into post_content
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

        // ── H1 fix: promote first heading to H1 and hide the WP post title ──────
        // Hub sends promote_first_h2 = true when a page has no H1 tag.
        // Strategy:
        //   1. Find the FIRST heading widget in Elementor (any level — default/h2/h3/etc.)
        //      and change its heading_size to 'h1'.
        //   2. Also set _elementor_page_settings[hide_title] = 'yes' so the theme
        //      stops rendering the WordPress post title above the Elementor content.
        //   3. Fall back to raw <h2> → <h1> replacement for non-Elementor pages.
        if (!empty($body['promote_first_h2'])) {
            $promoted = false;

            // ── Elementor path ──────────────────────────────────────────────
            $elementor_raw = get_post_meta($post_id, '_elementor_data', true);
            if (!empty($elementor_raw)) {
                $elementor_data = json_decode($elementor_raw, true);
                if (is_array($elementor_data)) {
                    self::promoteFirstHeadingInElementor($elementor_data, $promoted);
                    if ($promoted) {
                        update_post_meta(
                            $post_id,
                            '_elementor_data',
                            wp_slash(wp_json_encode($elementor_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        );
                        delete_post_meta($post_id, '_elementor_css'); // bust CSS cache
                        $applied[] = 'h1_promoted_elementor';
                    }
                }

                // Always hide the post title on Elementor pages — whether we promoted
                // a heading or not — so "Home" / page name stops appearing.
                $page_settings = get_post_meta($post_id, '_elementor_page_settings', true);
                if (!is_array($page_settings)) {
                    $page_settings = [];
                }
                if (($page_settings['hide_title'] ?? '') !== 'yes') {
                    $page_settings['hide_title'] = 'yes';
                    update_post_meta($post_id, '_elementor_page_settings', $page_settings);
                    $applied[] = 'elementor_title_hidden';
                }
            }

            // ── Classic / Gutenberg fallback ────────────────────────────────
            if (!$promoted && !empty($post->post_content)) {
                $content = $post->post_content;
                // Replace only the FIRST <h2 …>…</h2>
                $new_content = preg_replace('/<h2(\s[^>]*)?>/', '<h1$1>', $content, 1, $h2_count);
                if ($h2_count > 0) {
                    $new_content = preg_replace('/<\/h2>/', '</h1>', $new_content, 1);
                    global $wpdb;
                    $wpdb->update($wpdb->posts, ['post_content' => $new_content], ['ID' => $post_id]);
                    clean_post_cache($post_id);
                    $promoted = true;
                    $applied[] = 'h1_promoted_content';
                }
            }
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

        // ── Open Graph fix ───────────────────────────────────────────────────────
        // Writes og:title / og:description / og:image to Yoast, RankMath, or generic meta.
        if (!empty($body['fix_open_graph'])) {
            $og_title = isset($body['og_title']) ? sanitize_text_field($body['og_title']) : get_the_title($post_id);
            $og_desc  = isset($body['og_description']) ? sanitize_text_field($body['og_description']) : '';
            $og_image = isset($body['og_image']) ? esc_url_raw($body['og_image']) : '';

            // Use featured image as OG image if hub didn't supply one
            if (empty($og_image) && has_post_thumbnail($post_id)) {
                $og_image = get_the_post_thumbnail_url($post_id, 'large') ?: '';
            }

            if (defined('WPSEO_VERSION')) {
                if (!empty($og_title)) update_post_meta($post_id, '_yoast_wpseo_opengraph-title', $og_title);
                if (!empty($og_desc))  update_post_meta($post_id, '_yoast_wpseo_opengraph-description', $og_desc);
                if (!empty($og_image)) update_post_meta($post_id, '_yoast_wpseo_opengraph-image', $og_image);
            }
            if (class_exists('RankMath')) {
                if (!empty($og_title)) update_post_meta($post_id, 'rank_math_facebook_title', $og_title);
                if (!empty($og_desc))  update_post_meta($post_id, 'rank_math_facebook_description', $og_desc);
                if (!empty($og_image)) update_post_meta($post_id, 'rank_math_facebook_image', $og_image);
            }
            // Generic fallback — output by wp_head hook in webnique-seo-agent.php
            if (!empty($og_title)) update_post_meta($post_id, '_wnq_og_title', $og_title);
            if (!empty($og_desc))  update_post_meta($post_id, '_wnq_og_description', $og_desc);
            if (!empty($og_image)) update_post_meta($post_id, '_wnq_og_image', $og_image);

            $applied[] = 'open_graph';
        }

        // ── Image lazy load fix ──────────────────────────────────────────────────
        // Classic/Gutenberg: patch img tags in post_content directly.
        // Elementor: set a flag; a wp_head JS snippet adds loading="lazy" at render time.
        if (!empty($body['fix_image_lazy_load'])) {
            $lazy_fixed    = 0;
            $elementor_raw = get_post_meta($post_id, '_elementor_data', true);

            if (empty($elementor_raw) && !empty($post->post_content)) {
                // Classic / Gutenberg — patch <img> tags directly
                $new_content = preg_replace_callback(
                    '/<img(?![^>]*\bloading\b)([^>]*)>/i',
                    function ($m) use (&$lazy_fixed) {
                        $lazy_fixed++;
                        return '<img' . $m[1] . ' loading="lazy">';
                    },
                    $post->post_content
                );
                if ($lazy_fixed > 0) {
                    global $wpdb;
                    $wpdb->update($wpdb->posts, ['post_content' => $new_content], ['ID' => $post_id]);
                    clean_post_cache($post_id);
                }
            } elseif (!empty($elementor_raw)) {
                // Elementor page — store flag; JS snippet in wp_head adds loading="lazy" at runtime
                update_post_meta($post_id, '_wnq_lazy_load', 1);
                $lazy_fixed = 1;
            }

            if ($lazy_fixed > 0) {
                $applied[] = 'image_lazy_load';
            }
        }

        // ── Internal links insertion ──────────────────────────────────────────────
        // Hub sends internal_links: [{anchor, url}, …]. We wrap the first unlinked
        // occurrence of each anchor phrase in an <a> tag.
        if (!empty($body['internal_links']) && is_array($body['internal_links']) && !empty($post->post_content)) {
            $content     = $post->post_content;
            $links_added = 0;

            foreach ($body['internal_links'] as $link) {
                $anchor = sanitize_text_field($link['anchor'] ?? '');
                $url    = esc_url_raw($link['url'] ?? '');
                if (empty($anchor) || empty($url)) continue;

                $escaped     = preg_quote($anchor, '/');
                $new_content = preg_replace(
                    '/(?<!["\'>])(' . $escaped . ')(?![^<]*>)(?![^<]*<\/a>)/ui',
                    '<a href="' . esc_url($url) . '">' . $anchor . '</a>',
                    $content, 1, $replaced
                );
                if ($replaced > 0) {
                    $content = $new_content;
                    $links_added++;
                }
            }

            if ($links_added > 0) {
                global $wpdb;
                $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $post_id]);
                clean_post_cache($post_id);
                $applied[] = 'internal_links(' . $links_added . ')';
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

    // ── Elementor Heading Promoter ────────────────────────────────────────────

    /**
     * Recursively walk Elementor JSON elements and promote the FIRST heading
     * widget to H1, regardless of its current heading_size value.
     *
     * Elementor heading widgets use heading_size values like:
     *   'default' (renders as H2), 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'
     *
     * We skip any widget already set to 'h1'. The first non-H1 heading found
     * is promoted. $changed is set to true once done so recursion stops early.
     */
    private static function promoteFirstHeadingInElementor(array &$elements, bool &$changed): void
    {
        if ($changed) return;

        foreach ($elements as &$element) {
            if ($changed) break;

            // Heading widget — promote to H1 if not already H1
            if (
                isset($element['elType']) &&
                $element['elType'] === 'widget' &&
                ($element['widgetType'] ?? '') === 'heading'
            ) {
                $current_size = $element['settings']['heading_size'] ?? 'default';
                if ($current_size !== 'h1') {
                    $element['settings']['heading_size'] = 'h1';
                    $changed = true;
                    break;
                }
            }

            // Recurse into child elements (sections, columns, containers, divs)
            if (!empty($element['elements']) && is_array($element['elements'])) {
                self::promoteFirstHeadingInElementor($element['elements'], $changed);
            }
        }
        unset($element);
    }
}
