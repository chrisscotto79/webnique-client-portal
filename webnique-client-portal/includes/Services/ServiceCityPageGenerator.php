<?php
/**
 * Service + City Draft Page Generator
 *
 * Generates one imported CSV row at a time and pushes the result to the
 * connected client site as an Elementor draft child page.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\Client;
use WNQ\Models\SEOHub;
use WNQ\Models\ServiceCityPage;

final class ServiceCityPageGenerator
{
    public const MIN_AGENT_VERSION = '1.2.3';

    public static function generateDraft(int $row_id): array
    {
        $row = ServiceCityPage::getRow($row_id);
        if (!$row) {
            return ['success' => false, 'message' => 'Imported row not found.'];
        }

        if ($row['status'] === 'draft_created') {
            return ['success' => true, 'message' => 'Draft already exists.', 'page_url' => $row['wp_page_url'] ?? ''];
        }
        if ($row['status'] === 'skipped') {
            return ['success' => true, 'message' => 'Row already skipped because the slug exists.'];
        }

        $client_id = $row['client_id'];
        $template = ServiceCityPage::getTemplate($client_id);
        if (trim($template) === '') {
            return self::fail($row_id, 'Paste and save an Elementor template for this client first.');
        }

        $validation = ServiceCityPageBlueprint::validateRow($row);
        if (!($validation['valid'] ?? false)) {
            return self::fail(
                $row_id,
                implode(' ', $validation['errors'] ?? ['Invalid Service + City row.'])
            );
        }

        if (empty($row['parent_service_slug'])) {
            return self::fail($row_id, 'parent_service_slug is required because Service + City pages are created as child pages.');
        }

        $agent = self::getActiveAgent($client_id, (int)($row['agent_key_id'] ?? 0));
        if (!$agent) {
            return self::fail($row_id, 'No active agent key found for this client site.');
        }
        if (!self::agentSupportsServiceCity($agent)) {
            $agent_version = trim((string)($agent['plugin_version'] ?? ''));
            return self::fail(
                $row_id,
                'This connected site is running Golden Web Marketing SEO Agent version ' . ($agent_version ?: 'unknown') .
                '. Service + City drafts require version ' . self::MIN_AGENT_VERSION .
                ' or newer. Update the agent plugin on that site, click Test Connection, then try Generate Draft again.'
            );
        }

        ServiceCityPage::updateRow($row_id, ['status' => 'generating', 'error_message' => null]);

        try {
            $client = Client::getByClientId($client_id) ?? [];
            $profile = SEOHub::getProfile($client_id) ?? [];
            $business_name = $client['company'] ?? $client['name'] ?? $client_id;

            $blueprint = ServiceCityPageBlueprint::compose(
                $template,
                $row,
                (string)$business_name,
                $profile,
                $client
            );

            if (($blueprint['recognized'] ?? false) === true) {
                if (($blueprint['success'] ?? false) !== true) {
                    return self::fail(
                        $row_id,
                        (string)($blueprint['message'] ?? 'The approved Service + City template could not be composed.')
                    );
                }

                $body = (string)$blueprint['body'];
                $built = $blueprint['built'];
            } else {
                $ai_vars = self::aiVarsForRow($row, $business_name, $profile, $client);
                $ai = AIEngine::generate(
                    'service_city_page',
                    $ai_vars,
                    $client_id,
                    [
                        'max_tokens'  => AIEngine::maxTokensForBlogGeneration(),
                        'no_cache'    => true,
                        'temperature' => 0.82,
                    ]
                );

                if (!$ai['success']) {
                    return self::fail($row_id, 'AI generation failed: ' . ($ai['error'] ?? 'unknown error'));
                }

                $body = self::parseGeneratedBody($ai['content'] ?? '');
                if ($body === '') {
                    return self::fail($row_id, 'AI returned an empty page body. Try generating this row again.');
                }
                $tokens = self::tokensForRow($row, $body, $business_name);
                $body = self::sanitizeGeneratedCopy($body, $tokens);
                $body = self::ensureTargetWordCount($row, $body, $business_name, $profile, $client_id, $client);

                $tokens = self::tokensForRow($row, $body, $business_name);
                $body = self::sanitizeGeneratedCopy($body, $tokens);
                $tokens = self::tokensForRow($row, $body, $business_name);
                $built = self::buildElementorFromTemplate($template, $tokens, $body);
            }

            $push = self::pushToAgent($agent, [
                'title'               => $row['page_title'] ?: $row['h1'] ?: $row['primary_keyword'],
                'title_tag'           => $row['title_tag'] ?: $row['page_title'],
                'meta_description'    => $row['meta_description'] ?? '',
                'h1'                  => $row['h1'] ?: $row['page_title'],
                'focus_keyword'       => $row['primary_keyword'] ?? '',
                'slug'                => $row['slug'],
                'parent_service_slug' => $row['parent_service_slug'],
                'post_content'        => $built['post_content'],
                'elementor_data'      => $built['elementor_data'],
                'page_settings'       => $built['page_settings'],
                'source_row_id'       => $row_id,
            ]);

            if (!$push['success']) {
                return self::fail($row_id, 'Draft push failed: ' . ($push['message'] ?? 'unknown error'));
            }

            if (($push['status'] ?? '') === 'skipped') {
                ServiceCityPage::updateRow($row_id, [
                    'status'         => 'skipped',
                    'generated_html' => $body,
                    'elementor_json' => $built['elementor_data'],
                    'error_message'  => $push['message'] ?? 'Slug already exists on the client site.',
                ]);
                return ['success' => true, 'message' => 'Skipped: slug already exists on the client site.'];
            }

            ServiceCityPage::updateRow($row_id, [
                'status'         => 'draft_created',
                'generated_html' => $body,
                'elementor_json' => $built['elementor_data'],
                'wp_page_id'     => $push['page_id'] ?? null,
                'wp_page_url'    => $push['page_url'] ?? '',
                'error_message'  => null,
            ]);

            return [
                'success'  => true,
                'message'  => 'Draft child page created.',
                'page_url' => $push['page_url'] ?? '',
            ];
        } catch (\Throwable $exception) {
            error_log(sprintf(
                'Golden Web Marketing Service + City generation failed for row %d: %s',
                $row_id,
                $exception->getMessage()
            ));
            return self::fail($row_id, 'Draft generation stopped unexpectedly. The row is ready to retry.');
        }
    }

    public static function deleteDraft(int $row_id): array
    {
        $row = ServiceCityPage::getRow($row_id);
        if (!$row) {
            return ['success' => false, 'message' => 'Imported row not found.'];
        }

        if (($row['status'] ?? '') !== 'draft_created') {
            return ['success' => false, 'message' => 'This row does not currently have a generated draft to delete.'];
        }

        $agent = self::getActiveAgent((string)$row['client_id'], (int)($row['agent_key_id'] ?? 0));
        if (!$agent) {
            return ['success' => false, 'message' => 'No active agent key found for this client site.'];
        }

        $delete = self::deleteOnAgent($agent, [
            'page_id'             => (int)($row['wp_page_id'] ?? 0),
            'source_row_id'       => $row_id,
            'slug'                => $row['slug'] ?? '',
            'parent_service_slug' => $row['parent_service_slug'] ?? '',
        ]);

        if (!$delete['success']) {
            return ['success' => false, 'message' => 'Draft delete failed: ' . ($delete['message'] ?? 'unknown error')];
        }

        ServiceCityPage::updateRow($row_id, [
            'status'         => 'imported',
            'generated_html' => null,
            'elementor_json' => null,
            'wp_page_id'     => null,
            'wp_page_url'    => '',
            'error_message'  => null,
        ]);

        return ['success' => true, 'message' => 'Draft deleted and row reset. You can generate it again.'];
    }

    public static function deleteRow(int $row_id): array
    {
        $row = ServiceCityPage::getRow($row_id);
        if (!$row) {
            return ['success' => false, 'message' => 'Imported row not found.'];
        }

        if (($row['status'] ?? '') === 'draft_created') {
            $delete = self::deleteDraft($row_id);
            if (!$delete['success']) {
                return $delete;
            }
        }

        if (!ServiceCityPage::deleteRow($row_id)) {
            return ['success' => false, 'message' => 'Imported row could not be deleted from the portal.'];
        }

        return ['success' => true, 'message' => 'Imported row deleted.'];
    }

    public static function deleteAllRows(string $client_id): array
    {
        $rows = ServiceCityPage::getAllRows($client_id);
        $deleted = 0;
        $errors = [];

        foreach ($rows as $row) {
            $result = self::deleteRow((int)$row['id']);
            if ($result['success']) {
                $deleted++;
            } else {
                $label = $row['slug'] ?? ('row #' . (int)$row['id']);
                $errors[] = $label . ': ' . ($result['message'] ?? 'delete failed');
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => $deleted . ' row(s) deleted. Some rows could not be deleted: ' . implode(' ', array_slice($errors, 0, 3)),
                'deleted' => $deleted,
            ];
        }

        return [
            'success' => true,
            'message' => $deleted . ' imported row(s) deleted.',
            'deleted' => $deleted,
        ];
    }

    private static function aiVarsForRow(array $row, string $business_name, array $profile, array $client = []): array
    {
        return [
            'business_name'       => $business_name,
            'business_phone'      => (string)($profile['phone'] ?? $client['phone'] ?? ''),
            'business_email'      => (string)($profile['email'] ?? $client['email'] ?? ''),
            'primary_keyword'     => $row['primary_keyword'] ?? '',
            'service'             => $row['service'] ?? '',
            'service_variations'  => $row['service_variations'] ?? '',
            'city'                => $row['city'] ?? '',
            'state'               => $row['state'] ?? '',
            'county'              => $row['county'] ?? '',
            'slug'                => $row['slug'] ?? '',
            'page_title'          => $row['page_title'] ?? '',
            'title_tag'           => $row['title_tag'] ?? '',
            'meta_description'    => $row['meta_description'] ?? '',
            'h1'                  => $row['h1'] ?? '',
            'cta_title'           => $row['cta_title'] ?? '',
            'cta_text'            => $row['cta_text'] ?? '',
            'related_services'    => $row['related_services'] ?? '',
            'navigation_menu_related_services' => $row['navigation_menu_related_services'] ?? '',
            'nearby_cities'       => $row['nearby_cities'] ?? '',
            'nav_menu_nearby_areas' => $row['nav_menu_nearby_areas'] ?? '',
            'internal_links'      => $row['internal_links'] ?? '',
            'geo_modifiers'       => $row['geo_modifiers'] ?? '',
            'commercial_intent'   => $row['commercial_intent'] ?? '',
            'page_type'           => $row['page_type'] ?? '',
            'parent_service_slug' => $row['parent_service_slug'] ?? '',
            'keyword_variants'    => $row['keyword_variants'] ?? '',
            'tone'                => $profile['content_tone'] ?? 'professional',
        ];
    }

    private static function ensureTargetWordCount(array $row, string $body, string $business_name, array $profile, string $client_id, array $client = []): string
    {
        $minimum_words = 1450;
        $target_words = 1500;
        $current_words = self::wordCount($body);

        if ($current_words >= $minimum_words) {
            return $body;
        }

        // Keep continuations focused instead of asking one oversized prompt to
        // finish the whole page. Two passes covers the common 750 + 750 case.
        for ($pass = 1; $pass <= 2 && $current_words < $minimum_words; $pass++) {
            $vars = self::aiVarsForRow($row, $business_name, $profile, $client);
            $vars['existing_body'] = $body;
            $vars['current_word_count'] = (string)$current_words;
            $vars['remaining_words'] = (string)min(750, max(250, $target_words - $current_words));

            $extra = AIEngine::generate(
                'service_city_page_expansion',
                $vars,
                $client_id,
                [
                    'max_tokens'  => 3200,
                    'no_cache'    => true,
                    'temperature' => 0.78,
                ]
            );

            if (!$extra['success']) {
                break;
            }

            $extra_body = self::parseGeneratedBody($extra['content'] ?? '');
            if ($extra_body === '') {
                break;
            }

            $body = trim($body . "\n" . $extra_body);
            $current_words = self::wordCount($body);
        }

        return $body;
    }

    private static function parseGeneratedBody(string $raw): string
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $raw);
        if (preg_match('/===BODY===\s*(.*?)\s*(?====END===|$)/s', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $raw = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $raw);
        return trim((string)$raw);
    }

    private static function tokensForRow(array $row, string $body, string $business_name): array
    {
        $tokens = [
            '{{business_name}}' => $business_name,
            '{{body}}' => $body,
            '{{generated_content}}' => $body,
            '{{service_city_content}}' => $body,
        ];

        foreach (ServiceCityPage::csvColumns() as $column) {
            $tokens['{{' . $column . '}}'] = (string)($row[$column] ?? '');
        }

        return $tokens;
    }

    private static function sanitizeGeneratedCopy(string $html, array $tokens): string
    {
        $html = preg_replace('/<h[23]\b[^>]*>\s*(?:Introduction|Overview|Conclusion|Summary|Final Thoughts|Get Started|Frequently Asked Questions)\s*<\/h[23]>/is', '', $html);
        $html = preg_replace('/\b(?:Introduction|Overview|Conclusion|Summary|Final Thoughts|CTA\s*Title|CTA\s*Text):\s*/i', '', (string)$html);
        $html = str_replace(['[insert phone number]', '[insert email address]'], '', (string)$html);

        $business = trim((string)($tokens['{{business_name}}'] ?? ''));
        $names = array_values(array_filter(array_unique([$business, 'King Sheds'])));
        foreach ($names as $name) {
            $quoted = preg_quote($name, '/');
            $html = preg_replace('/\b(?:Here\s+at|At)\s+' . $quoted . '\s*,?\s*/i', '', (string)$html);
        }

        $html = preg_replace_callback('/(<(?:p|li)\b[^>]*>)\s*(we|our)\b/i', static function (array $matches): string {
            return $matches[1] . ucfirst(strtolower($matches[2]));
        }, (string)$html);
        $html = preg_replace_callback('/(<(?:p|li)\b[^>]*>)\s*([a-z])/', static function (array $matches): string {
            return $matches[1] . strtoupper($matches[2]);
        }, (string)$html);
        $html = preg_replace('/[ \t]{2,}/', ' ', (string)$html);

        return trim((string)$html);
    }

    private static function buildElementorFromTemplate(string $template, array $tokens, string $body): array
    {
        $had_body_token = self::templateHasBodyToken($template);
        $has_variable_tokens = self::templateHasVariableTokens($template);
        $decoded = json_decode($template, true);

        if (is_array($decoded)) {
            $page_settings = isset($decoded['page_settings']) && is_array($decoded['page_settings'])
                ? $decoded['page_settings']
                : ['hide_title' => 'yes'];
            $elements = isset($decoded['content']) && is_array($decoded['content'])
                ? $decoded['content']
                : $decoded;

            $elements = self::replaceTokensRecursive($elements, $tokens);
            if (!$had_body_token || self::hasMultipleContentSections($elements)) {
                $elements = self::distributeFallbackContent($elements, $tokens, $body);
            }
            $elements = self::protectHeroFromBodyLeak($elements, $tokens);

            return [
                'elementor_data' => wp_json_encode($elements),
                'page_settings'  => $page_settings,
                'post_content'   => '',
            ];
        }

        $html = str_replace(array_keys($tokens), array_values($tokens), $template);
        if (!$had_body_token && !$has_variable_tokens) {
            $h1 = (string)($tokens['{{h1}}'] ?? $tokens['{{page_title}}'] ?? '');
            $html = '<h1>' . esc_html($h1) . '</h1>' . "\n" . $html . "\n" . $body;
        }

        return [
            'elementor_data' => wp_json_encode(self::minimalElementorTextEditor($html)),
            'page_settings'  => ['hide_title' => 'yes'],
            'post_content'   => $html,
        ];
    }

    private static function hasMultipleContentSections(array $elements): bool
    {
        $last_top_level_index = count($elements) - 1;
        $content_sections = 0;

        foreach ($elements as $index => $element) {
            if ($index === 0 || $index === $last_top_level_index || !is_array($element)) {
                continue;
            }
            if (self::elementHasWidget($element, 'text-editor')) {
                $content_sections++;
            }
        }

        return $content_sections > 1;
    }

    private static function protectHeroFromBodyLeak(array $elements, array $tokens): array
    {
        if (isset($elements[0]) && is_array($elements[0])) {
            $elements[0] = self::clampHeroTextEditors($elements[0], $tokens);
        }

        return $elements;
    }

    private static function clampHeroTextEditors(array $element, array $tokens): array
    {
        if (($element['widgetType'] ?? '') === 'text-editor') {
            $editor = (string)($element['settings']['editor'] ?? '');
            if (self::looksLikeFullGeneratedBody($editor)) {
                $primary_keyword = trim((string)($tokens['{{primary_keyword}}'] ?? ''));
                if ($primary_keyword === '') {
                    $primary_keyword = wp_trim_words(wp_strip_all_tags($editor), 24, '.');
                }
                $element['settings']['editor'] = '<p>' . esc_html($primary_keyword) . '</p>';
            }
        }

        if (!empty($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as &$child) {
                if (is_array($child)) {
                    $child = self::clampHeroTextEditors($child, $tokens);
                }
            }
            unset($child);
        }

        return $element;
    }

    private static function looksLikeFullGeneratedBody(string $html): bool
    {
        if (self::wordCount($html) > 100) {
            return true;
        }

        return (bool)preg_match('/<(?:h2|h3|section|ul|ol)\b/i', $html);
    }

    private static function replaceTokensRecursive($value, array $tokens)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::replaceTokensRecursive($v, $tokens);
            }
            return $value;
        }

        if (is_string($value)) {
            return str_replace(array_keys($tokens), array_values($tokens), $value);
        }

        return $value;
    }

    private static function distributeFallbackContent(array $elements, array $tokens, string $body): array
    {
        $plan = self::contentPlan($body, $tokens);
        $plan['sections'] = array_slice($plan['sections'], 0, self::countTemplateContentSections($elements));
        $section_index = 0;
        $last_top_level_index = count($elements) - 1;

        foreach ($elements as $index => &$element) {
            if (!is_array($element)) {
                continue;
            }

            if ($index === 0) {
                $heading_done = false;
                $intro_done = false;
                $element = self::fillHeroElement($element, $tokens, $plan['intro_html'], $heading_done, $intro_done);
                continue;
            }

            if ($index === $last_top_level_index && self::elementHasWidget($element, 'button')) {
                $heading_done = false;
                $body_done = false;
                $element = self::fillCtaElement($element, $tokens, $heading_done, $body_done);
                continue;
            }

            if (!self::elementHasWidget($element, 'text-editor')) {
                continue;
            }

            $section = $plan['sections'][$section_index] ?? self::fallbackContentSection($tokens, $section_index);
            $eyebrow_done = false;
            $heading_done = false;
            $body_done = false;
            $element = self::fillContentElement($element, $section, $tokens, $eyebrow_done, $heading_done, $body_done);
            $section_index++;
        }
        unset($element);

        return $elements;
    }

    private static function countTemplateContentSections(array $elements): int
    {
        $last_top_level_index = count($elements) - 1;
        $count = 0;

        foreach ($elements as $index => $element) {
            if ($index === 0 || $index === $last_top_level_index || !is_array($element)) {
                continue;
            }
            if (self::elementHasWidget($element, 'text-editor')) {
                $count++;
            }
        }

        return max(1, $count);
    }

    private static function contentPlan(string $body, array $tokens): array
    {
        $body = self::sanitizeGeneratedCopy($body, $tokens);
        $sections = self::extractContentSections($body);
        $intro_html = self::extractIntroHtml($body, $tokens);

        if ($intro_html === '') {
            $primary = trim((string)($tokens['{{primary_keyword}}'] ?? ''));
            $city = trim((string)($tokens['{{city}}'] ?? ''));
            $state = trim((string)($tokens['{{state}}'] ?? ''));
            $intro = trim($primary . ($city !== '' ? ' in ' . $city . ($state !== '' ? ', ' . $state : '') : ''));
            $intro_html = '<p>' . esc_html($intro ?: wp_trim_words(wp_strip_all_tags($body), 42, '.')) . '</p>';
        }

        if (empty($sections)) {
            $sections = self::paragraphChunksToSections($body, $tokens);
        }
        $sections = self::cleanSectionsForTemplate($sections, $tokens);

        return [
            'intro_html' => self::limitHtmlToWords($intro_html, 55),
            'sections'   => $sections,
        ];
    }

    private static function extractIntroHtml(string $body, array $tokens): string
    {
        if (preg_match_all('/<p\b[^>]*>.*?<\/p>/is', $body, $matches)) {
            foreach ($matches[0] as $paragraph) {
                $paragraph = self::sanitizeGeneratedCopy((string)$paragraph, $tokens);
                $plain = trim(wp_strip_all_tags($paragraph));
                if ($plain !== '' && self::wordCount($plain) >= 8) {
                    return trim($paragraph);
                }
            }
        }

        return '';
    }

    private static function extractContentSections(string $body): array
    {
        $sections = [];
        if (preg_match_all('/<section\b[^>]*>(.*?)<\/section>/is', $body, $matches)) {
            foreach ($matches[1] as $section_html) {
                if (!preg_match('/<h2\b[^>]*>(.*?)<\/h2>/is', $section_html, $heading)) {
                    continue;
                }
                $title = trim(wp_strip_all_tags($heading[1]));
                if (self::isNonContentSectionTitle($title)) {
                    continue;
                }
                $html = preg_replace('/<h2\b[^>]*>.*?<\/h2>/is', '', $section_html, 1);
                $html = trim((string)$html);
                if (preg_match('/<h2\b[^>]*>/i', $html)) {
                    continue;
                }
                if ($title !== '' && self::wordCount($html) > 8) {
                    $sections[] = [
                        'title' => $title,
                        'eyebrow' => $title,
                        'html'  => $html,
                    ];
                }
            }
        }

        if (!empty($sections)) {
            return $sections;
        }

        $body = preg_replace('/<\/?section\b[^>]*>/i', '', $body);
        $parts = preg_split('/(<h2\b[^>]*>.*?<\/h2>)/is', (string)$body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return [];
        }

        $current_title = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^<h2\b[^>]*>(.*?)<\/h2>$/is', $part, $m)) {
                $current_title = trim(wp_strip_all_tags($m[1]));
                continue;
            }
            if ($current_title !== '' && self::wordCount($part) > 8) {
                if (self::isNonContentSectionTitle($current_title)) {
                    $current_title = '';
                    continue;
                }
                $sections[] = [
                    'title' => $current_title,
                    'eyebrow' => $current_title,
                    'html'  => $part,
                ];
                $current_title = '';
            }
        }

        return $sections;
    }

    private static function cleanSectionsForTemplate(array $sections, array $tokens): array
    {
        $clean = [];
        foreach ($sections as $index => $section) {
            $title = trim((string)($section['title'] ?? ''));
            if ($title === '' || self::isNonContentSectionTitle($title)) {
                continue;
            }

            $html = self::cleanSectionBodyHtml((string)($section['html'] ?? ''), $tokens);
            if (self::wordCount($html) < 8) {
                continue;
            }

            $title = self::compactKeywordHeading($title, $tokens, count($clean));
            $clean[] = [
                'title'   => $title,
                'eyebrow' => self::eyebrowForSection($title, $tokens, count($clean)),
                'html'    => $html,
            ];
        }

        return $clean;
    }

    private static function isNonContentSectionTitle(string $title): bool
    {
        return (bool)preg_match('/\b(?:introduction|overview|summary|conclusion|final thoughts|faq|frequently asked|questions?|cta|call|contact|quote|pricing|get your|get started|schedule|consultation)\b/i', $title);
    }

    private static function cleanSectionBodyHtml(string $html, array $tokens): string
    {
        $html = self::sanitizeGeneratedCopy($html, $tokens);
        $html = preg_replace('/<h[23]\b[^>]*>.*?<\/h[23]>/is', '', $html);
        $html = preg_replace('/<p\b[^>]*>.*?(?:CTA\s*Title|CTA\s*Text|\[insert phone number\]|\[insert email address\]|Get Started Today|schedule a consultation|take the first step).*?<\/p>/is', '', (string)$html);
        $html = preg_replace('/\b(?:CTA\s*Title|CTA\s*Text):\s*/i', '', (string)$html);
        $html = str_replace(['[insert phone number]', '[insert email address]'], '', (string)$html);
        $html = preg_replace('/<p\b[^>]*>\s*<\/p>/i', '', (string)$html);
        $html = trim((string)$html);

        if (self::wordCount($html) > 225) {
            return self::limitHtmlToWords($html, 225);
        }

        return $html;
    }

    private static function compactKeywordHeading(string $title, array $tokens, int $index): string
    {
        $title = trim(wp_strip_all_tags($title));
        $title = preg_replace('/\s+/', ' ', (string)$title);
        $title = trim((string)preg_replace('/\s*:\s*.*$/', '', (string)$title));
        $title = trim($title, " \t\n\r\0\x0B?.!");

        $service = trim((string)($tokens['{{service}}'] ?? ''));
        $service = $service !== '' ? $service : 'Service';
        $service_single = self::singularServiceLabel($service);
        $city = trim((string)($tokens['{{city}}'] ?? ''));
        $fallback = self::fallbackHeadingForIndex($tokens, $index);
        if ($title === '' || self::isNonContentSectionTitle($title) || preg_match('/\b(?:what to know|getting started)\b/i', $title)) {
            return $fallback;
        }

        if (preg_match('/\b(?:why|choose|customers?)\b/i', $title)) {
            return 'Why Choose ' . $service;
        }
        if (preg_match('/\b(?:included|includes|features?)\b/i', $title)) {
            return $service_single . ' Features';
        }
        if (preg_match('/\b(?:options?|styles?|models?|custom|details?)\b/i', $title)) {
            return $service_single . ' Options';
        }
        if (preg_match('/\bpermits?\b/i', $title)) {
            return $service_single . ' Permits';
        }
        if (preg_match('/\b(?:prep|preparation|site|delivery|install|installation|setup|process|approach)\b/i', $title)) {
            return $service_single . ' Installation';
        }
        if (preg_match('/\b(?:related|nearby|areas?|cities|county)\b/i', $title)) {
            return 'Nearby ' . $service_single . ' Services';
        }

        $title = preg_replace('/^(?:our|your)\s+/i', '', $title);
        if ($city !== '') {
            $title = preg_replace('/\s+in\s+' . preg_quote($city, '/') . '\b.*$/i', '', (string)$title);
        }
        $title = wp_trim_words((string)$title, 7, '');
        $title = trim((string)$title, " \t\n\r\0\x0B?.!");

        if ($title === '' || self::wordCount($title) < 2) {
            return $fallback;
        }

        if ($index === 0 && $city !== '' && stripos($title, $service) === false) {
            return trim($service . ' in ' . $city);
        }

        return $title;
    }

    private static function fallbackHeadingForIndex(array $tokens, int $index): string
    {
        $service = trim((string)($tokens['{{service}}'] ?? ''));
        $service = $service !== '' ? $service : 'Service';
        $service_single = self::singularServiceLabel($service);
        $city = trim((string)($tokens['{{city}}'] ?? ''));
        $service_city = trim($service . ($city !== '' ? ' in ' . $city : ''));
        $titles = [
            $service_city !== '' ? $service_city : $service,
            $service_single . ' Options',
            $service_single . ' Permits',
            $service_single . ' Installation',
            'Why Choose ' . $service,
        ];

        return $titles[$index] ?? ($service_single . ' Details');
    }

    private static function singularServiceLabel(string $service): string
    {
        $service = trim($service) !== '' ? trim($service) : 'Service';
        $service = preg_replace('/\bSheds\b/i', 'Shed', $service);
        $service = preg_replace('/\bCarports\b/i', 'Carport', (string)$service);
        $service = preg_replace('/\bBuildings\b/i', 'Building', (string)$service);

        return trim((string)$service);
    }

    private static function eyebrowForSection(string $title, array $tokens, int $index): string
    {
        $service = trim((string)($tokens['{{service}}'] ?? ''));
        $service = $service !== '' ? $service : 'Service';
        $service_single = self::singularServiceLabel($service);
        $city = trim((string)($tokens['{{city}}'] ?? ''));
        $state = trim((string)($tokens['{{state}}'] ?? ''));
        $location = trim($city . ($state !== '' ? ', ' . $state : ''));
        $business = trim((string)($tokens['{{business_name}}'] ?? ''));
        $business = $business !== '' ? $business : 'King Sheds';
        $labels = [
            $service !== '' && $location !== '' ? $service . ' in ' . $location : 'Local Service Details',
            $service_single . ' Options',
            $service_single . ' Permits',
            'Site Prep and Delivery',
            'Why Choose ' . $business,
        ];

        return $labels[$index] ?? self::shortLabel($title, $tokens);
    }

    private static function paragraphChunksToSections(string $body, array $tokens): array
    {
        $plain = trim(wp_strip_all_tags($body));
        if ($plain === '') {
            return [self::fallbackContentSection($tokens, 0)];
        }

        $paragraphs = preg_split('/\n\s*\n|(?=<p\b)/i', $body) ?: [$body];
        $chunks = array_chunk(array_values(array_filter(array_map('trim', $paragraphs))), 2);
        $sections = [];
        foreach ($chunks as $index => $chunk) {
            $sections[] = [
                'title' => self::fallbackContentSection($tokens, $index)['title'],
                'eyebrow' => self::fallbackContentSection($tokens, $index)['eyebrow'],
                'html'  => implode('', $chunk),
            ];
        }

        return $sections;
    }

    private static function fallbackContentSection(array $tokens, int $index): array
    {
        $city = trim((string)($tokens['{{city}}'] ?? 'Your Area'));
        $state = trim((string)($tokens['{{state}}'] ?? ''));
        $location = trim($city . ($state !== '' ? ', ' . $state : ''));
        $title = self::fallbackHeadingForIndex($tokens, $index);

        return [
            'title' => $title,
            'eyebrow' => self::eyebrowForSection($title, $tokens, $index),
            'html' => self::fallbackSectionHtml($tokens, $index, $location),
        ];
    }

    private static function fallbackSectionHtml(array $tokens, int $index, string $location): string
    {
        $business = trim((string)($tokens['{{business_name}}'] ?? ''));
        $business = $business !== '' ? $business : 'King Sheds';
        $service = trim((string)($tokens['{{service}}'] ?? ''));
        $service = $service !== '' ? $service : 'service options';
        $service_single = self::singularServiceLabel($service);
        $city = trim((string)($tokens['{{city}}'] ?? 'your area'));
        $location = $location !== '' ? $location : $city;

        $sections = [
            '<p>' . esc_html($business) . ' helps customers compare ' . esc_html($service) . ' for properties in ' . esc_html($location) . ' with practical guidance on size, access, delivery, and setup.</p><p>Each page section is built around the service and city from the import row, so the draft stays focused on the customer\'s local search intent.</p>',
            '<p>Available ' . esc_html($service_single) . ' options can vary by size, layout, materials, doors, windows, and add-on features.</p><p>Customers can use this section to compare common choices before requesting pricing or confirming availability for ' . esc_html($city) . ' delivery.</p>',
            '<p>Permit and approval requirements may depend on the structure size, placement, and local rules in ' . esc_html($location) . '.</p><p>Customers should confirm city or county requirements before delivery so the site is ready and the installation timeline stays clear.</p>',
            '<p>Good site preparation starts with a level location, safe access for delivery equipment, and enough clearance around the installation area.</p><p>The team can review common delivery questions before setup so customers know what to expect on installation day.</p>',
            '<p>Customers choose ' . esc_html($service) . ' when they want practical storage, flexible options, and a simpler buying process.</p><p>' . esc_html($business) . ' keeps the page focused on useful local details, nearby service coverage, and the next steps customers need before requesting pricing.</p>',
        ];

        return $sections[$index] ?? $sections[0];
    }

    private static function fillHeroElement(array $element, array $tokens, string $intro_html, bool &$heading_done, bool &$intro_done): array
    {
        $widget = $element['widgetType'] ?? '';
        if (!$heading_done && $widget === 'heading') {
            $element['settings']['title'] = (string)($tokens['{{h1}}'] ?? $tokens['{{page_title}}'] ?? $tokens['{{primary_keyword}}'] ?? '');
            $element['settings']['header_size'] = 'h1';
            $heading_done = true;
        } elseif (!$intro_done && $widget === 'text-editor') {
            $element['settings']['editor'] = $intro_html;
            $intro_done = true;
        }

        if (!empty($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as &$child) {
                if (is_array($child)) {
                    $child = self::fillHeroElement($child, $tokens, $intro_html, $heading_done, $intro_done);
                }
            }
            unset($child);
        }

        return $element;
    }

    private static function fillContentElement(array $element, array $section, array $tokens, bool &$eyebrow_done, bool &$heading_done, bool &$body_done): array
    {
        $widget = $element['widgetType'] ?? '';

        if (!$eyebrow_done && $widget === 'icon-list') {
            if (!empty($element['settings']['icon_list'][0]) && is_array($element['settings']['icon_list'][0])) {
                $element['settings']['icon_list'][0]['text'] = self::shortLabel((string)$section['eyebrow'], $tokens);
                $eyebrow_done = true;
            }
        } elseif (!$heading_done && in_array($widget, ['heading', 'animated-headline'], true)) {
            $element = self::setSectionHeading($element, (string)$section['title']);
            $heading_done = true;
        } elseif (!$body_done && $widget === 'text-editor') {
            $element['settings']['editor'] = (string)$section['html'];
            $body_done = true;
        }

        if (!empty($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as &$child) {
                if (is_array($child)) {
                    $child = self::fillContentElement($child, $section, $tokens, $eyebrow_done, $heading_done, $body_done);
                }
            }
            unset($child);
        }

        return $element;
    }

    private static function fillCtaElement(array $element, array $tokens, bool &$heading_done, bool &$body_done): array
    {
        $widget = $element['widgetType'] ?? '';
        if (!$heading_done && $widget === 'heading') {
            $element['settings']['title'] = (string)($tokens['{{cta_title}}'] ?? $tokens['{{page_title}}'] ?? '');
            $heading_done = true;
        } elseif (!$body_done && $widget === 'text-editor') {
            $cta_text = trim((string)($tokens['{{cta_text}}'] ?? ''));
            $element['settings']['editor'] = $cta_text !== '' && str_contains($cta_text, '<')
                ? $cta_text
                : '<p>' . esc_html($cta_text) . '</p>';
            $body_done = true;
        }

        if (!empty($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as &$child) {
                if (is_array($child)) {
                    $child = self::fillCtaElement($child, $tokens, $heading_done, $body_done);
                }
            }
            unset($child);
        }

        return $element;
    }

    private static function setSectionHeading(array $element, string $title): array
    {
        if (($element['widgetType'] ?? '') === 'animated-headline') {
            [$before, $highlight] = self::splitHeadingForAnimation($title);
            $element['settings']['before_text'] = $before;
            $element['settings']['highlighted_text'] = $highlight;
            $element['settings']['tag'] = 'h2';
        } else {
            $element['settings']['title'] = $title;
            if (($element['settings']['header_size'] ?? '') !== 'h1') {
                $element['settings']['header_size'] = 'h2';
            }
        }

        return $element;
    }

    private static function splitHeadingForAnimation(string $title): array
    {
        $title = trim($title);
        $words = preg_split('/\s+/', $title) ?: [];
        if (count($words) < 4) {
            return [$title, ''];
        }

        $highlight = array_pop($words);
        return [implode(' ', $words) . ' ', $highlight];
    }

    private static function shortLabel(string $label, array $tokens): string
    {
        $label = trim(wp_strip_all_tags($label));
        if ($label === '') {
            $service = (string)($tokens['{{service}}'] ?? 'Service');
            $city = (string)($tokens['{{city}}'] ?? 'Local Area');
            return $service . ' in ' . $city;
        }

        return wp_trim_words($label, 10, '');
    }

    private static function elementHasWidget(array $element, string $widget_type): bool
    {
        if (($element['widgetType'] ?? '') === $widget_type) {
            return true;
        }
        if (empty($element['elements']) || !is_array($element['elements'])) {
            return false;
        }
        foreach ($element['elements'] as $child) {
            if (is_array($child) && self::elementHasWidget($child, $widget_type)) {
                return true;
            }
        }
        return false;
    }

    private static function appendToLastNonHeroTextEditor(array $elements, string $html): array
    {
        for ($i = count($elements) - 2; $i >= 1; $i--) {
            if (isset($elements[$i]) && is_array($elements[$i]) && self::appendToFirstTextEditor($elements[$i], $html)) {
                break;
            }
        }

        return $elements;
    }

    private static function appendToFirstTextEditor(array &$element, string $html): bool
    {
        if (($element['widgetType'] ?? '') === 'text-editor') {
            $element['settings']['editor'] = trim((string)($element['settings']['editor'] ?? '') . "\n" . $html);
            return true;
        }

        if (empty($element['elements']) || !is_array($element['elements'])) {
            return false;
        }

        foreach ($element['elements'] as &$child) {
            if (is_array($child) && self::appendToFirstTextEditor($child, $html)) {
                return true;
            }
        }
        unset($child);

        return false;
    }

    private static function limitHtmlToWords(string $html, int $limit): string
    {
        $text = trim(wp_strip_all_tags($html));
        if (self::wordCount($text) <= $limit) {
            return $html;
        }

        return '<p>' . esc_html(wp_trim_words($text, $limit, '.')) . '</p>';
    }

    private static function wordCount(string $html): int
    {
        $text = trim(wp_strip_all_tags($html));
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/', $text) ?: []);
    }

    private static function minimalElementorTextEditor(string $html): array
    {
        return [[
            'id'       => self::elementorId(),
            'elType'   => 'container',
            'settings' => ['content_width' => 'boxed'],
            'elements' => [[
                'id'         => self::elementorId(),
                'elType'     => 'widget',
                'widgetType' => 'text-editor',
                'settings'   => ['editor' => $html],
                'elements'   => [],
            ]],
        ]];
    }

    private static function templateHasBodyToken(string $template): bool
    {
        return str_contains($template, '{{body}}')
            || str_contains($template, '{{generated_content}}')
            || str_contains($template, '{{service_city_content}}');
    }

    private static function templateHasVariableTokens(string $template): bool
    {
        return (bool)preg_match('/\{\{[a-zA-Z0-9_]+\}\}/', $template);
    }

    private static function elementorId(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    private static function pushToAgent(array $agent, array $payload): array
    {
        $site_url = rtrim($agent['site_url'] ?? '', '/');
        $api_key = $agent['api_key'] ?? '';
        if ($site_url === '' || $api_key === '') {
            return ['success' => false, 'message' => 'Missing agent site URL or API key.'];
        }

        $response = wp_remote_post($site_url . '/wp-json/wnq-agent/v1/create-service-city-page', [
            'timeout' => 90,
            'headers' => [
                'X-WNQ-Api-Key' => $api_key,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'GoldenWebMarketing-SEO-OS/1.0',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $body = json_decode($raw, true) ?: [];

        if ($code >= 200 && $code < 300) {
            return array_merge(['success' => true], $body);
        }

        $message = $body['message'] ?? $body['error'] ?? ('HTTP ' . $code . ' - ' . substr($raw, 0, 250));
        $error_code = (string)($body['code'] ?? '');

        if ($code === 404 && ($error_code === 'rest_no_route' || stripos($message, 'No route was found') !== false)) {
            $message = 'The connected client site needs the updated Golden Web Marketing SEO Agent plugin. Install or update the agent on that site, then click Generate Draft again.';
        }

        return ['success' => false, 'message' => $message];
    }

    private static function getActiveAgent(string $client_id, int $agent_key_id = 0): ?array
    {
        global $wpdb;

        if ($agent_key_id > 0) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wnq_seo_agent_keys
                     WHERE id=%d AND client_id=%s AND status='active'",
                    $agent_key_id,
                    $client_id
                ),
                ARRAY_A
            );
            if ($row) {
                return $row;
            }
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_seo_agent_keys
                 WHERE client_id=%s AND status='active'
                 ORDER BY created_at DESC LIMIT 1",
                $client_id
            ),
            ARRAY_A
        ) ?: null;
    }

    private static function agentSupportsServiceCity(array $agent): bool
    {
        $version = trim((string)($agent['plugin_version'] ?? ''));
        if ($version === '') {
            return true;
        }

        return version_compare($version, self::MIN_AGENT_VERSION, '>=');
    }

    private static function fail(int $row_id, string $message): array
    {
        ServiceCityPage::updateRow($row_id, [
            'status'        => 'failed',
            'error_message' => $message,
        ]);
        return ['success' => false, 'message' => $message];
    }

    private static function deleteOnAgent(array $agent, array $payload): array
    {
        $site_url = rtrim($agent['site_url'] ?? '', '/');
        $api_key = $agent['api_key'] ?? '';
        if ($site_url === '' || $api_key === '') {
            return ['success' => false, 'message' => 'Missing agent site URL or API key.'];
        }

        $response = wp_remote_post($site_url . '/wp-json/wnq-agent/v1/delete-service-city-page', [
            'timeout' => 45,
            'headers' => [
                'X-WNQ-Api-Key' => $api_key,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'GoldenWebMarketing-SEO-OS/1.0',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $body = json_decode($raw, true) ?: [];

        if ($code >= 200 && $code < 300) {
            return array_merge(['success' => true], $body);
        }

        $message = $body['message'] ?? $body['error'] ?? ('HTTP ' . $code . ' - ' . substr($raw, 0, 250));
        if ($code === 404 && stripos($message, 'No route was found') !== false) {
            $message = 'The connected client site needs the latest Golden Web Marketing SEO Agent plugin before drafts can be deleted remotely.';
        }

        return ['success' => false, 'message' => $message];
    }
}
