<?php
/**
 * AI Elementor Page Builder
 *
 * Creates editable Elementor draft pages from a reusable Elementor JSON
 * template and a JSON variable payload. This service never invents layouts;
 * it preserves the provided Elementor tree and only replaces placeholders.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class AIElementorPageBuilder
{
    public const MIN_REMOTE_AGENT_VERSION = '1.1.8';

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
        $built = self::buildPagePayload($template_json, $variables, $options);
        if (empty($built['success'])) {
            return $built;
        }

        $title = $built['title'];
        $slug = self::uniquePageSlug($built['slug']);

        $post_id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $built['post_content'],
            'post_excerpt' => $built['meta_description'],
        ], true);

        if (is_wp_error($post_id)) {
            return [
                'success' => false,
                'message' => 'Page creation failed: ' . $post_id->get_error_message(),
            ];
        }

        self::saveElementorMeta((int)$post_id, $built['elementor_data'], $built['template']);
        self::saveRankMathMeta((int)$post_id, $built['variables']);
        self::maybeSetFeaturedImage((int)$post_id, $built['variables'], $options);
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
            'pages_url'     => admin_url('edit.php?post_type=page'),
        ];
    }

    public static function generateRemoteDraft(int $agent_key_id, string $template_json, array $variables, array $options = []): array
    {
        $agent = self::getAgent($agent_key_id);
        if (!$agent) {
            return [
                'success' => false,
                'message' => 'Select an active connected client WordPress site first.',
            ];
        }

        $built = self::buildPagePayload($template_json, $variables, $options);
        if (empty($built['success'])) {
            return $built;
        }

        return self::pushToAgent($agent, [
            'title'             => $built['title'],
            'slug'              => $built['slug'],
            'post_content'      => $built['post_content'],
            'elementor_data'    => wp_json_encode($built['elementor_data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'page_settings'     => $built['page_settings'],
            'title_tag'         => $built['title_tag'],
            'meta_description'  => $built['meta_description'],
            'h1'                => $built['h1'],
            'focus_keyword'     => $built['focus_keyword'],
            'featured_image_id' => absint($options['featured_image_id'] ?? $built['variables']['featured_image_id'] ?? 0),
        ]);
    }

    private static function buildPagePayload(string $template_json, array $variables, array $options = []): array
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

        $variables = self::applyVariableAliases(self::normalizeVariables($variables));
        $elementor_data = self::replacePlaceholdersRecursive($content, self::buildTokenMap($variables));
        $elementor_data = self::regenerateElementorIds($elementor_data);
        $title = self::resolvePostTitle($variables, $options);

        return [
            'success'          => true,
            'template'         => $template,
            'variables'        => $variables,
            'elementor_data'   => $elementor_data,
            'page_settings'    => isset($template['page_settings']) && is_array($template['page_settings']) ? $template['page_settings'] : [],
            'title'            => $title,
            'slug'             => self::generateSlug($variables, $title),
            'post_content'     => self::buildPostContentFallback($variables),
            'title_tag'        => sanitize_text_field((string)($variables['title_tag'] ?? $title)),
            'meta_description' => sanitize_textarea_field((string)($variables['meta_description'] ?? '')),
            'h1'               => sanitize_text_field((string)($variables['h1'] ?? $title)),
            'focus_keyword'    => sanitize_text_field((string)($variables['primary_keyword'] ?? $variables['h1'] ?? '')),
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

    private static function applyVariableAliases(array $variables): array
    {
        $aliases = [
            'section_title' => ['template_title'],
            'hero_subheadline' => ['hero_description', 'hero_subtitle', 'cta_text'],
            'hero_background_image_url' => ['hero_slide_1_url', 'hero_background_placeholder_url'],
            'primary_cta_text' => ['cta_button_1_text', 'cta_button_text'],
            'primary_cta_url' => ['cta_button_1_url'],
            'secondary_cta_text' => ['cta_button_2_text'],
            'secondary_cta_url' => ['cta_button_2_url'],
            'content_image_url' => ['hero_slide_2_url', 'hero_slide_3_url', 'hero_background_image_url', 'hero_background_placeholder_url'],
            'content_image_alt' => ['hero_background_image_alt', 'h1', 'primary_keyword'],
        ];
        $uploaded_image_fields = array_map([self::class, 'cleanPlaceholderKey'], (array)($variables['uploaded_image_fields'] ?? []));
        $image_targets = ['hero_background_image_url', 'content_image_url'];

        foreach ($aliases as $target => $sources) {
            foreach ($sources as $source) {
                if (!self::hasUsableVariable($variables[$source] ?? null)) {
                    continue;
                }

                $target_is_uploaded_image = in_array($target, $uploaded_image_fields, true) || self::isUploadedImageVariable($variables[$target] ?? null);
                $target_needs_value = !self::hasUsableVariable($variables[$target] ?? null);
                $target_is_image_url = in_array($target, $image_targets, true);
                if (!$target_is_uploaded_image && ($target_needs_value || (!$target_is_image_url && self::isLegacyAlias($source)))) {
                    $variables[$target] = $variables[$source];
                }

                break;
            }
        }

        if (!self::hasUsableVariable($variables['hero_background_image_alt'] ?? null)) {
            $variables['hero_background_image_alt'] = $variables['h1'] ?? $variables['primary_keyword'] ?? '';
        }

        if (!self::hasUsableVariable($variables['hero_overlay_image_alt'] ?? null)) {
            $variables['hero_overlay_image_alt'] = $variables['hero_background_image_alt'] ?? '';
        }

        if (!self::hasUsableVariable($variables['hero_background_video_alt'] ?? null)) {
            $variables['hero_background_video_alt'] = $variables['hero_background_image_alt'] ?? '';
        }

        if (!self::hasUsableVariable($variables['h1'] ?? null) && self::hasUsableVariable($variables['primary_keyword'] ?? null)) {
            $variables['h1'] = $variables['primary_keyword'];
        }

        return $variables;
    }

    private static function hasUsableVariable($value): bool
    {
        if (is_array($value)) {
            return !empty($value);
        }

        return trim((string)$value) !== '';
    }

    private static function isUploadedImageVariable($value): bool
    {
        return is_string($value) && preg_match('#^data:image/(?:jpeg|jpg|png|gif|webp);base64,#i', $value) === 1;
    }

    private static function isLegacyAlias(string $source): bool
    {
        return in_array($source, [
            'hero_description',
            'hero_subtitle',
            'hero_background_placeholder_url',
            'cta_button_1_text',
            'cta_button_1_url',
            'cta_button_2_text',
            'cta_button_2_url',
            'cta_button_text',
            'hero_slide_2_url',
            'hero_slide_3_url',
        ], true);
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

    private static function regenerateElementorIds($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['elType']) && isset($value['id']) && is_string($value['id'])) {
            $value['id'] = self::newElementorId();
        }

        if (isset($value['settings']) && is_array($value['settings'])) {
            $value['settings'] = self::regenerateRepeaterIds($value['settings']);
        }

        if (isset($value['elements']) && is_array($value['elements'])) {
            foreach ($value['elements'] as $index => $child) {
                $value['elements'][$index] = self::regenerateElementorIds($child);
            }
        }

        if (self::isListArray($value)) {
            foreach ($value as $index => $child) {
                $value[$index] = self::regenerateElementorIds($child);
            }
        }

        return $value;
    }

    private static function regenerateRepeaterIds($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['_id']) && is_string($value['_id'])) {
            $value['_id'] = self::newElementorId();
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::regenerateRepeaterIds($child);
            }
        }

        return $value;
    }

    private static function newElementorId(): string
    {
        try {
            return substr(bin2hex(random_bytes(4)), 0, 8);
        } catch (\Throwable $e) {
            return substr(md5(uniqid('', true)), 0, 8);
        }
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

    private static function getAgent(int $agent_key_id): ?array
    {
        if ($agent_key_id <= 0) {
            return null;
        }

        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_seo_agent_keys
                 WHERE id=%d AND status='active'
                 LIMIT 1",
                $agent_key_id
            ),
            ARRAY_A
        ) ?: null;
    }

    private static function pushToAgent(array $agent, array $payload): array
    {
        $site_url = rtrim((string)($agent['site_url'] ?? ''), '/');
        $api_key = (string)($agent['api_key'] ?? '');

        if ($site_url === '' || $api_key === '') {
            return [
                'success' => false,
                'message' => 'The selected client site is missing its site URL or agent API key.',
            ];
        }

        $agent_version = trim((string)($agent['plugin_version'] ?? ''));
        if ($agent_version !== '' && version_compare($agent_version, self::MIN_REMOTE_AGENT_VERSION, '<')) {
            return [
                'success' => false,
                'message' => 'The selected client site needs Golden Web Marketing SEO Agent ' . self::MIN_REMOTE_AGENT_VERSION . ' or newer. Update the agent plugin on that site, then try again.',
            ];
        }

        $response = wp_remote_post($site_url . '/wp-json/wnq-agent/v1/create-elementor-page', [
            'timeout' => 90,
            'headers' => [
                'X-WNQ-Api-Key' => $api_key,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'GoldenWebMarketing-SEO-OS/1.0',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Client site request failed: ' . $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = [];
        }

        if ($code >= 200 && $code < 300) {
            return array_merge([
                'success' => true,
                'message' => 'Draft created on the selected client site.',
                'site_url' => $site_url,
                'agent_key_id' => (int)($agent['id'] ?? 0),
            ], $body);
        }

        $message = $body['message'] ?? $body['error'] ?? ('HTTP ' . $code . ' - ' . substr($raw, 0, 250));
        $error_code = (string)($body['code'] ?? '');

        if ($code === 404 && ($error_code === 'rest_no_route' || stripos($message, 'No route was found') !== false)) {
            $message = 'The selected client site needs Golden Web Marketing SEO Agent ' . self::MIN_REMOTE_AGENT_VERSION . ' or newer. Update the agent plugin on that site, then try again.';
        }

        return [
            'success' => false,
            'message' => 'Client draft creation failed: ' . $message,
        ];
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
