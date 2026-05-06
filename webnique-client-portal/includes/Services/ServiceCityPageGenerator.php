<?php
/**
 * Service + City Draft Page Generator
 *
 * Generates one imported CSV row at a time and pushes the result to the
 * connected client site as an Elementor draft child page.
 *
 * @package WebNique Portal
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

        if (empty($row['parent_service_slug'])) {
            return self::fail($row_id, 'parent_service_slug is required because Service + City pages are created as child pages.');
        }

        $agent = self::getActiveAgent($client_id, (int)($row['agent_key_id'] ?? 0));
        if (!$agent) {
            return self::fail($row_id, 'No active agent key found for this client site.');
        }

        ServiceCityPage::updateRow($row_id, ['status' => 'generating', 'error_message' => null]);

        $client = Client::getByClientId($client_id) ?? [];
        $profile = SEOHub::getProfile($client_id) ?? [];
        $business_name = $client['company'] ?? $client['name'] ?? $client_id;

        $ai = AIEngine::generate(
            'service_city_page',
            [
                'business_name'       => $business_name,
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
                'nearby_cities'       => $row['nearby_cities'] ?? '',
                'internal_links'      => $row['internal_links'] ?? '',
                'geo_modifiers'       => $row['geo_modifiers'] ?? '',
                'commercial_intent'   => $row['commercial_intent'] ?? '',
                'keyword_variants'    => $row['keyword_variants'] ?? '',
                'tone'                => $profile['content_tone'] ?? 'professional',
            ],
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
        $built = self::buildElementorFromTemplate($template, $tokens, $body);

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
            'source_row_id'       => $row_id,
        ]);

        if (!$push['success']) {
            return self::fail($row_id, 'Draft push failed: ' . ($push['message'] ?? 'unknown error'));
        }

        if (($push['status'] ?? '') === 'skipped') {
            ServiceCityPage::updateRow($row_id, [
                'status'        => 'skipped',
                'generated_html'=> $body,
                'elementor_json'=> $built['elementor_data'],
                'error_message' => $push['message'] ?? 'Slug already exists on the client site.',
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

        foreach (ServiceCityPage::requiredColumns() as $column) {
            $tokens['{{' . $column . '}}'] = (string)($row[$column] ?? '');
        }

        return $tokens;
    }

    private static function buildElementorFromTemplate(string $template, array $tokens, string $body): array
    {
        $had_body_token = self::templateHasBodyToken($template);
        $decoded = json_decode($template, true);

        if (is_array($decoded)) {
            $elements = isset($decoded['content']) && is_array($decoded['content'])
                ? $decoded['content']
                : $decoded;

            $elements = self::replaceTokensRecursive($elements, $tokens);
            if (!$had_body_token) {
                $heading_done = false;
                $body_done = false;
                $elements = self::injectFallbackWidgets($elements, $tokens, $body, $heading_done, $body_done);
            }

            return [
                'elementor_data' => wp_json_encode($elements),
                'post_content'   => $body,
            ];
        }

        $html = str_replace(array_keys($tokens), array_values($tokens), $template);
        if (!$had_body_token) {
            $h1 = (string)($tokens['{{h1}}'] ?? $tokens['{{page_title}}'] ?? '');
            $html = '<h1>' . esc_html($h1) . '</h1>' . "\n" . $html . "\n" . $body;
        }

        return [
            'elementor_data' => wp_json_encode(self::minimalElementorTextEditor($html)),
            'post_content'   => $html,
        ];
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

    private static function injectFallbackWidgets(array $elements, array $tokens, string $body, bool &$heading_done, bool &$body_done): array
    {
        foreach ($elements as &$el) {
            $widget = $el['widgetType'] ?? '';
            if (!$heading_done && $widget === 'heading') {
                $el['settings']['title'] = (string)($tokens['{{h1}}'] ?? $tokens['{{page_title}}'] ?? '');
                $el['settings']['header_size'] = 'h1';
                $heading_done = true;
            } elseif (!$body_done && $widget === 'text-editor') {
                $el['settings']['editor'] = $body;
                $body_done = true;
            }

            if (!empty($el['elements']) && is_array($el['elements'])) {
                $el['elements'] = self::injectFallbackWidgets($el['elements'], $tokens, $body, $heading_done, $body_done);
            }
        }

        return $elements;
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
                'User-Agent'    => 'WebNique-SEO-OS/1.0',
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

        return ['success' => false, 'message' => $body['message'] ?? $body['error'] ?? ('HTTP ' . $code . ' - ' . substr($raw, 0, 250))];
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

    private static function fail(int $row_id, string $message): array
    {
        ServiceCityPage::updateRow($row_id, [
            'status'        => 'failed',
            'error_message' => $message,
        ]);
        return ['success' => false, 'message' => $message];
    }
}
