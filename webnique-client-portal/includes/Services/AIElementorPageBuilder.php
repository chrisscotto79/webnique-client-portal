<?php
/**
 * AI Elementor Page Builder
 *
 * Creates editable Elementor draft pages from a reusable Elementor JSON
 * template and a JSON variable payload. This service never invents layouts;
 * it preserves the provided Elementor tree and only replaces placeholders.
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class AIElementorPageBuilder
{
    /**
     * Generate a draft page from an Elementor JSON template and variables.
     *
     * @param string $template_json Raw Elementor export JSON.
     * @param array  $variables     Placeholder values keyed by variable name.
     * @param array  $options       Optional post_title and featured_image_id.
     * @return array
     */
    public static function generateDraft(string $template_json, array $variables, array $options = []): array
    {
        $decoded = self::decodeTemplate($template_json);
        if (!$decoded['success']) {
            return $decoded;
        }

        $template = $decoded['template'];
        $content = self::extractElementorContent($template);
        if (!is_array($content) || empty($content)) {
            return [
                'success' => false,
                'message' => 'The Elementor template must contain a non-empty content array.',
            ];
        }

        $variables = self::normalizeVariables($variables);
        $elementor_data = self::replacePlaceholdersRecursive($content, self::buildTokenMap($variables));

        $title = self::resolvePostTitle($variables, $options);
        $slug = self::uniquePageSlug(self::generateSlug($variables, $title));

        $post_id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => self::buildPostContentFallback($variables),
            'post_excerpt' => sanitize_textarea_field((string)($variables['meta_description'] ?? '')),
        ], true);

        if (is_wp_error($post_id)) {
            return [
                'success' => false,
                'message' => 'Page creation failed: ' . $post_id->get_error_message(),
            ];
        }

        self::saveElementorMeta((int)$post_id, $elementor_data, $template);
        self::saveRankMathMeta((int)$post_id, $variables);
        self::maybeSetFeaturedImage((int)$post_id, $variables, $options);
        self::clearElementorCache((int)$post_id);

        return [
            'success'       => true,
            'message'       => 'Draft page created.',
            'post_id'       => (int)$post_id,
            'post_title'    => $title,
            'post_slug'     => $slug,
            'elementor_url' => admin_url('post.php?post=' . (int)$post_id . '&action=elementor'),
            'edit_url'      => get_edit_post_link((int)$post_id, 'raw'),
            'preview_url'   => get_preview_post_link((int)$post_id) ?: get_permalink((int)$post_id),
        ];
    }

    private static function decodeTemplate(string $template_json): array
    {
        $template_json = trim($template_json);
        if ($template_json === '') {
            return [
                'success' => false,
                'message' => 'Paste or upload an Elementor JSON template first.',
            ];
        }

        $decoded = json_decode($template_json, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'message' => 'Invalid Elementor JSON: ' . json_last_error_msg(),
            ];
        }

        return [
            'success'  => true,
            'template' => $decoded,
        ];
    }

    private static function extractElementorContent(array $template): array
    {
        if (isset($template['content']) && is_array($template['content'])) {
            return $template['content'];
        }

        if (self::isListArray($template)) {
            return $template;
        }

        if (($template['elType'] ?? '') !== '') {
            return [$template];
        }

        return [];
    }

    public static function replacePlaceholdersRecursive($value, array $tokens)
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::replacePlaceholdersRecursive($child, $tokens);
            }
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($matches) use ($tokens) {
            $key = $matches[1];
            return array_key_exists($key, $tokens) ? $tokens[$key] : $matches[0];
        }, $value);
    }

    private static function buildTokenMap(array $variables): array
    {
        $tokens = [];
        $supported = [
            'primary_keyword',
            'service',
            'city',
            'state',
            'h1',
            'cta_title',
            'cta_text',
            'title_tag',
            'meta_description',
        ];

        foreach ($supported as $key) {
            $tokens[$key] = '';
        }

        foreach ($variables as $key => $value) {
            $clean_key = self::cleanPlaceholderKey((string)$key);
            if ($clean_key !== '') {
                $tokens[$clean_key] = self::stringifyVariable($value);
            }
        }

        return $tokens;
    }

    private static function normalizeVariables(array $variables): array
    {
        $normalized = [];
        foreach ($variables as $key => $value) {
            $clean_key = self::cleanPlaceholderKey((string)$key);
            if ($clean_key !== '') {
                $normalized[$clean_key] = $value;
            }
        }
        return $normalized;
    }

    private static function cleanPlaceholderKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key);
        return trim((string)$key, '_');
    }

    private static function stringifyVariable($value): string
    {
        if (is_array($value)) {
            $flat = [];
            foreach ($value as $child) {
                if (is_scalar($child)) {
                    $flat[] = (string)$child;
                }
            }

            if ($flat) {
                return wp_kses_post(implode(', ', $flat));
            }

            return wp_kses_post(wp_json_encode($value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return wp_kses_post((string)$value);
    }

    private static function resolvePostTitle(array $variables, array $options): string
    {
        $candidate = trim((string)($options['post_title'] ?? ''));
        if ($candidate === '') {
            $candidate = trim((string)($variables['page_title'] ?? ''));
        }
        if ($candidate === '') {
            $candidate = trim((string)($variables['h1'] ?? ''));
        }
        if ($candidate === '') {
            $candidate = trim((string)($variables['primary_keyword'] ?? ''));
        }
        if ($candidate === '') {
            $service = trim((string)($variables['service'] ?? ''));
            $city = trim((string)($variables['city'] ?? ''));
            $state = trim((string)($variables['state'] ?? ''));
            $candidate = trim($service . ' in ' . trim($city . ' ' . $state));
        }

        return sanitize_text_field($candidate ?: 'AI Elementor Draft');
    }

    private static function generateSlug(array $variables, string $fallback_title): string
    {
        $city = trim((string)($variables['city'] ?? ''));
        $service = trim((string)($variables['service'] ?? ''));
        $slug_source = trim($city . ' ' . $service);

        if ($slug_source === '') {
            $slug_source = $fallback_title;
        }

        return sanitize_title($slug_source);
    }

    private static function uniquePageSlug(string $base_slug): string
    {
        $base_slug = $base_slug ?: 'ai-elementor-draft';
        $candidate = $base_slug;
        $index = 2;

        while (get_posts([
            'name'           => $candidate,
            'post_type'      => 'page',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ])) {
            $candidate = $base_slug . '-' . $index;
            $index++;
        }

        return $candidate;
    }

    private static function buildPostContentFallback(array $variables): string
    {
        $parts = array_filter([
            wp_strip_all_tags((string)($variables['h1'] ?? '')),
            wp_strip_all_tags((string)($variables['cta_text'] ?? '')),
        ]);

        return implode("\n\n", $parts);
    }

    public static function saveElementorMeta(int $post_id, array $elementor_data, array $template = []): void
    {
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', 'wp-page');
        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($elementor_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        update_post_meta($post_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');

        if (isset($template['page_settings']) && is_array($template['page_settings'])) {
            update_post_meta($post_id, '_elementor_page_settings', $template['page_settings']);
        }
    }

    public static function saveRankMathMeta(int $post_id, array $variables): void
    {
        if (!self::isRankMathActive()) {
            return;
        }

        $focus = sanitize_text_field((string)($variables['primary_keyword'] ?? $variables['h1'] ?? ''));
        $title = sanitize_text_field((string)($variables['title_tag'] ?? $variables['h1'] ?? ''));
        $description = sanitize_textarea_field((string)($variables['meta_description'] ?? ''));

        if ($focus !== '') {
            update_post_meta($post_id, 'rank_math_focus_keyword', $focus);
        }
        if ($title !== '') {
            update_post_meta($post_id, 'rank_math_title', $title);
        }
        if ($description !== '') {
            update_post_meta($post_id, 'rank_math_description', $description);
        }
    }

    private static function isRankMathActive(): bool
    {
        return defined('RANK_MATH_VERSION') || class_exists('\RankMath') || function_exists('rank_math');
    }

    private static function maybeSetFeaturedImage(int $post_id, array $variables, array $options): void
    {
        $image_id = absint($options['featured_image_id'] ?? $variables['featured_image_id'] ?? 0);
        if ($image_id <= 0) {
            return;
        }

        if (get_post_type($image_id) === 'attachment') {
            set_post_thumbnail($post_id, $image_id);
        }
    }

    public static function clearElementorCache(int $post_id): void
    {
        delete_post_meta($post_id, '_elementor_css');
        clean_post_cache($post_id);

        try {
            if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                $post_css = new \Elementor\Core\Files\CSS\Post($post_id);
                if (method_exists($post_css, 'delete')) {
                    $post_css->delete();
                }
            }

            if (class_exists('\Elementor\Plugin')) {
                $plugin = \Elementor\Plugin::$instance;
                if (isset($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
                    $plugin->files_manager->clear_cache();
                }
            }
        } catch (\Throwable $e) {
            // Cache clearing should never block a successfully-created draft.
        }
    }

    private static function isListArray(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }
}
