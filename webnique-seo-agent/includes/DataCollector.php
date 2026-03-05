<?php
/**
 * Data Collector
 *
 * Collects structured SEO data from the client WordPress site.
 * This is the ONLY intelligence on the client side — pure data extraction.
 * All analysis happens on the hub.
 *
 * Collects per-page:
 *  - Title, meta description, H1
 *  - Focus keyword (Yoast/RankMath/custom)
 *  - Word count, internal links, images + alt audit
 *  - Schema markup types present
 *  - Post status, type, categories, tags
 *  - Featured image URL
 *  - Last modified date
 *
 * @package WebNique SEO Agent
 */

namespace WNQA;

if (!defined('ABSPATH')) {
    exit;
}

class DataCollector
{
    private int $batch_size;
    private int $word_count_threshold;

    public function __construct()
    {
        $config = get_option('wnqa_config', []);
        $this->batch_size          = (int)($config['batch_size'] ?? 100);
        $this->word_count_threshold = (int)($config['word_count_threshold'] ?? 300);
    }

    /**
     * Collect data for all published posts/pages
     */
    public function collectAll(): array
    {
        $pages  = $this->collectPostType('page');
        $posts  = $this->collectPostType('post');
        $custom = $this->collectCustomTypes();

        return array_merge($pages, $posts, $custom);
    }

    /**
     * Collect a specific post type
     */
    public function collectPostType(string $post_type, int $offset = 0): array
    {
        $query = new \WP_Query([
            'post_type'      => $post_type,
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => $this->batch_size,
            'offset'         => $offset,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => false,
        ]);

        $results = [];
        foreach ($query->posts as $post) {
            $results[] = $this->collectPage($post);
        }

        return $results;
    }

    /**
     * Collect custom post types (products, portfolio, etc.)
     */
    private function collectCustomTypes(): array
    {
        $config = get_option('wnqa_config', []);
        $extra_types = $config['extra_post_types'] ?? [];

        if (empty($extra_types)) {
            return [];
        }

        $results = [];
        foreach ((array)$extra_types as $pt) {
            $results = array_merge($results, $this->collectPostType(sanitize_key($pt)));
        }
        return $results;
    }

    /**
     * Collect SEO data for a single post/page
     */
    public function collectPage(\WP_Post $post): array
    {
        $url = get_permalink($post->ID);

        // Extract content text (strip shortcodes and HTML)
        $content_raw = apply_filters('the_content', $post->post_content);
        $content_text = wp_strip_all_tags($content_raw);
        $word_count  = str_word_count($content_text);

        // Meta tags
        $title    = $this->getTitle($post);
        $meta_desc= $this->getMetaDescription($post);
        $focus_kw = $this->getFocusKeyword($post);
        $h1       = $this->getH1($post, $content_raw);

        // Internal links
        $internal_links = $this->countInternalLinks($content_raw);

        // Images
        [$img_count, $missing_alt] = $this->analyzeImages($content_raw, $post->ID);

        // Schema
        $schema_types = $this->detectSchemaTypes($post, $content_raw);

        // Taxonomy
        $categories = $this->getTaxonomyTerms($post->ID, 'category');
        $tags       = $this->getTaxonomyTerms($post->ID, 'post_tag');

        // Keyword presence analysis
        $kw_lower = strtolower(trim($focus_kw));
        $kw_in_title = $kw_lower && strpos(strtolower($title), $kw_lower) !== false;
        $kw_in_meta  = $kw_lower && strpos(strtolower($meta_desc), $kw_lower) !== false;
        $kw_in_h1    = $kw_lower && strpos(strtolower($h1), $kw_lower) !== false;

        // Featured image
        $featured_img = '';
        if (has_post_thumbnail($post->ID)) {
            $featured_img = get_the_post_thumbnail_url($post->ID, 'full') ?: '';
        }

        return [
            'post_id'              => $post->ID,
            'page_url'             => $url,
            'page_type'            => $post->post_type,
            'post_status'          => $post->post_status,
            'title'                => $title,
            'meta_description'     => $meta_desc,
            'h1'                   => $h1,
            'focus_keyword'        => $focus_kw,
            'word_count'           => $word_count,
            'internal_links_count' => $internal_links,
            'images_count'         => $img_count,
            'images_missing_alt'   => $missing_alt,
            'has_schema'           => !empty($schema_types),
            'schema_types'         => $schema_types,
            'has_h1'               => !empty($h1),
            'keyword_in_title'     => $kw_in_title ? 1 : 0,
            'keyword_in_meta'      => $kw_in_meta ? 1 : 0,
            'keyword_in_h1'        => $kw_in_h1 ? 1 : 0,
            'categories'           => $categories,
            'tags'                 => $tags,
            'featured_image_url'   => $featured_img,
            'last_modified'        => $post->post_modified,
        ];
    }

    // ── Meta Extraction ─────────────────────────────────────────────────────

    private function getTitle(\WP_Post $post): string
    {
        // Yoast SEO
        if (defined('WPSEO_VERSION')) {
            $title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
            if (!empty($title)) return wpseo_replace_vars($title, $post);
        }
        // RankMath
        if (class_exists('RankMath')) {
            $title = get_post_meta($post->ID, 'rank_math_title', true);
            if (!empty($title)) return $title;
        }
        // All in One SEO
        $aioseo_title = get_post_meta($post->ID, '_aioseop_title', true);
        if (!empty($aioseo_title)) return $aioseo_title;

        // Fallback to WP title
        return get_the_title($post->ID);
    }

    private function getMetaDescription(\WP_Post $post): string
    {
        // Yoast SEO
        if (defined('WPSEO_VERSION')) {
            $desc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
            if (!empty($desc)) return $desc;
        }
        // RankMath
        if (class_exists('RankMath')) {
            $desc = get_post_meta($post->ID, 'rank_math_description', true);
            if (!empty($desc)) return $desc;
        }
        // AIOSEO
        $aio_desc = get_post_meta($post->ID, '_aioseop_description', true);
        if (!empty($aio_desc)) return $aio_desc;

        // Generic meta description
        $generic = get_post_meta($post->ID, '_meta_description', true)
            ?: get_post_meta($post->ID, 'meta_description', true);
        if (!empty($generic)) return $generic;

        // Auto-excerpt fallback
        if (!empty($post->post_excerpt)) {
            return wp_strip_all_tags($post->post_excerpt);
        }

        return '';
    }

    private function getFocusKeyword(\WP_Post $post): string
    {
        // Yoast
        if (defined('WPSEO_VERSION')) {
            $kw = get_post_meta($post->ID, '_yoast_wpseo_focuskw', true);
            if (!empty($kw)) return $kw;
        }
        // RankMath
        if (class_exists('RankMath')) {
            $kw = get_post_meta($post->ID, 'rank_math_focus_keyword', true);
            if (!empty($kw)) return explode(',', $kw)[0] ?? '';
        }
        // Generic
        $generic = get_post_meta($post->ID, '_focus_keyword', true)
            ?: get_post_meta($post->ID, 'focus_keyword', true);
        return $generic ?: '';
    }

    private function getH1(\WP_Post $post, string $content_html): string
    {
        // 1. Classic / Gutenberg: look for <h1> in rendered content
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $content_html, $matches)) {
            return wp_strip_all_tags($matches[1]);
        }

        // 2. Elementor: scan _elementor_data for a heading widget with heading_size=h1.
        //    apply_filters('the_content') doesn't fully render Elementor in a sync
        //    context, so <h1> tags won't appear in $content_html for Elementor pages.
        $elementor_raw = get_post_meta($post->ID, '_elementor_data', true);
        if (!empty($elementor_raw)) {
            $elements = json_decode($elementor_raw, true);
            if (is_array($elements)) {
                $h1_text = $this->findElementorH1($elements);
                if ($h1_text !== null) {
                    return $h1_text;
                }
            }
        }

        return '';
    }

    /**
     * Recursively search Elementor elements for a heading widget set to h1.
     * Returns the heading text, or null if none found.
     */
    private function findElementorH1(array $elements): ?string
    {
        foreach ($elements as $element) {
            if (
                isset($element['elType']) &&
                $element['elType'] === 'widget' &&
                ($element['widgetType'] ?? '') === 'heading' &&
                ($element['settings']['heading_size'] ?? 'default') === 'h1'
            ) {
                return wp_strip_all_tags($element['settings']['title'] ?? '');
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $result = $this->findElementorH1($element['elements']);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        return null;
    }

    // ── Content Analysis ────────────────────────────────────────────────────

    private function countInternalLinks(string $content_html): int
    {
        $site_host = parse_url(home_url(), PHP_URL_HOST);
        $count = 0;
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content_html, $matches)) {
            foreach ($matches[1] as $href) {
                $href_host = parse_url($href, PHP_URL_HOST);
                // Count internal links and relative links
                if (!$href_host || $href_host === $site_host) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function analyzeImages(string $content_html, int $post_id): array
    {
        $total   = 0;
        $missing = 0;

        // Count images in content
        if (preg_match_all('/<img[^>]+>/i', $content_html, $matches)) {
            foreach ($matches[0] as $img_tag) {
                $total++;
                if (!preg_match('/alt=["\'][^"\']+["\']/', $img_tag) ||
                    preg_match('/alt=["\']["\']/', $img_tag)) {
                    $missing++;
                }
            }
        }

        // Also check featured image alt
        if (has_post_thumbnail($post_id)) {
            $thumb_id = get_post_thumbnail_id($post_id);
            $alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
            $total++;
            if (empty($alt)) {
                $missing++;
            }
        }

        return [$total, $missing];
    }

    private function detectSchemaTypes(\WP_Post $post, string $content_html): array
    {
        $types = [];

        // Check page content for JSON-LD
        if (preg_match_all('/"@type"\s*:\s*"([^"]+)"/i', $content_html, $matches)) {
            $types = array_unique($matches[1]);
        }

        // Check post meta for schema (various SEO plugins + our own _wnq_schema_json)
        $meta_schema = get_post_meta($post->ID, '_schema_type', true)
            ?: get_post_meta($post->ID, 'rank_math_schema_JsonLd', true)
            ?: get_post_meta($post->ID, '_yoast_wpseo_schema_page_type', true);

        if (!empty($meta_schema) && is_string($meta_schema)) {
            $types[] = $meta_schema;
        }

        // Also check _wnq_schema_json — written by SEOFixer when auto-fix adds schema
        $wnq_schema = get_post_meta($post->ID, '_wnq_schema_json', true);
        if (!empty($wnq_schema)) {
            if (preg_match_all('/"@type"\s*:\s*"([^"]+)"/i', $wnq_schema, $sm)) {
                foreach ($sm[1] as $st) {
                    $types[] = $st;
                }
            }
        }

        // Check head output for schema (via output buffering)
        // This is lightweight — just pattern-match existing content
        if (empty($types)) {
            // Infer from post type/category as a hint for the hub
            if ($post->post_type === 'product') $types[] = 'Product';
            if (in_array($post->post_type, ['post', 'page']) && strpos(strtolower($post->post_title), 'faq') !== false) $types[] = 'FAQPage';
        }

        return array_values(array_unique($types));
    }

    private function getTaxonomyTerms(int $post_id, string $taxonomy): array
    {
        $terms = get_the_terms($post_id, $taxonomy);
        if (is_wp_error($terms) || empty($terms)) return [];
        return array_map(fn($t) => $t->name, $terms);
    }
}
