<?php
/**
 * AI Engine - Modular AI Provider Abstraction
 *
 * Primary: Groq (free tier, llama-3.1-8b / mixtral-8x7b)
 * Fallback: OpenAI-compatible APIs, HuggingFace Inference
 *
 * Features:
 *  - Centralized prompt templates stored as WP options
 *  - Output caching (WP transients) to conserve free-tier quotas
 *  - Rate limiting per provider
 *  - Swappable provider via settings
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class AIEngine
{
    const CACHE_GROUP   = 'wnq_ai_cache';
    const RATE_KEY      = 'wnq_ai_rate_';
    const MAX_RPM       = 30;  // requests per minute per provider (conservative)

    // ── Prompt Templates ───────────────────────────────────────────────────

    private static array $prompt_templates = [
        'blog_outline' => <<<'PROMPT'
You are an expert SEO content strategist. Create a detailed blog post outline for the following:

Business: {business_name}
Services: {services}
Location: {location}
Target Keyword: {keyword}
Content Tone: {tone}

Produce a structured outline with:
1. SEO-optimized H1 title (include keyword)
2. Meta description (150-160 chars, include keyword)
3. Introduction hook (2 sentences)
4. 5-7 H2 sections with 2-3 bullet H3 sub-points each
5. Conclusion with CTA
6. Internal link suggestions (describe what type of page to link to)
7. Recommended schema type

Return as clean, structured text. Be specific and actionable.
PROMPT,

        'blog_draft' => <<<'PROMPT'
You are an expert SEO copywriter. Write a complete, SEO-optimized blog post based on this outline:

Business: {business_name}
Target Keyword: {keyword}
Location: {location}
Tone: {tone}
Word Count Target: 800-1200 words
Outline: {outline}

Requirements:
- Include target keyword in first 100 words, H1, and naturally throughout (2-3% density)
- Use short paragraphs (2-3 sentences max)
- Include transition phrases between sections
- End with a clear CTA relevant to {services}
- Do NOT use generic filler phrases like "In conclusion" or "In today's world"

Write the full blog post now.
PROMPT,

        'meta_tags' => <<<'PROMPT'
Generate optimized SEO meta tags for the following page:

Business: {business_name}
Page Type: {page_type}
Page Topic/Service: {topic}
Target Keyword: {keyword}
Location: {location}
Current Title: {current_title}
Current Meta: {current_meta}

Return EXACTLY in this format (nothing else):
TITLE: [optimized title, 50-60 chars, include keyword]
META: [optimized meta description, 150-160 chars, include keyword, include CTA]
PROMPT,

        'schema_json' => <<<'PROMPT'
Generate a complete JSON-LD schema markup for:

Business: {business_name}
Schema Type: {schema_type}
Page URL: {page_url}
Services: {services}
Location: {location}
Phone: {phone}
Additional Info: {extra_info}

Return ONLY valid JSON-LD wrapped in <script type="application/ld+json"> tags. No explanation.
PROMPT,

        'internal_links' => <<<'PROMPT'
Analyze the following page content and suggest internal linking opportunities:

Page Title: {page_title}
Page Content Summary: {content_summary}
Available Pages on Site:
{available_pages}

For each suggestion provide:
1. Anchor text to use
2. Which page to link to
3. Where in content to place it (e.g., "after paragraph about X")

Return as a numbered list. Max 5 suggestions.
PROMPT,

        'report_summary' => <<<'PROMPT'
You are an SEO agency reporting specialist. Write a professional executive summary for a monthly SEO report:

Client: {client_name}
Period: {period}
Key Metrics:
- Traffic Change: {traffic_change}
- Top Keywords Moving Up: {improving_keywords}
- Keywords Declining: {declining_keywords}
- New Content Published: {content_published}
- Technical Issues Fixed: {issues_fixed}
- Open Issues: {open_issues}

Write a 3-4 paragraph executive summary that:
1. Highlights wins positively
2. Explains declines professionally (with context)
3. States what was done this month
4. Recommends 3 specific next-steps

Tone: {tone}. Keep it client-friendly, not technical.
PROMPT,

        'content_gap_topics' => <<<'PROMPT'
You are an SEO content strategist. Identify blog post topics to fill content gaps:

Business: {business_name}
Services: {services}
Location: {location}
Target Keyword Clusters with no content:
{gap_keywords}

Existing pages already covering:
{existing_pages}

Generate 5 specific blog post topics that:
1. Target the gap keywords naturally
2. Are relevant to the business services
3. Have local relevance where appropriate
4. Progress logically (not duplicating existing content)

Return as a numbered list: Topic | Primary Keyword | Secondary Keywords | Content Type (blog/page/faq)
PROMPT,

        'blog_post_full' => <<<'PROMPT'
You are an expert local SEO copywriter. Write a complete, publish-ready blog post of AT LEAST 2000 words.

Business: {business_name}
Services: {services}
Location: {location}
Post Title (working title): {title}
Category Type: {category_type}
Focus Keyword: {focus_keyword}
Tone: {tone}
Target Word Count: 2000-2500 words

Client Keyword Data (weave relevant terms naturally into the content):
{keyword_context}

Internal Link Candidates (use 3-5 of these naturally in the body):
{internal_links}

{external_citation_instruction}

H1 TITLE RULES (critical for SEO):
- The focus keyword MUST appear at the very beginning of the H1
- Include a power word (e.g. Proven, Essential, Ultimate, Expert, Trusted, Best, Complete, Effective)
- Include a number where natural (e.g. "5 Reasons", "7 Signs", "10 Tips", "3 Steps")
- Example pattern: "{focus_keyword}: 7 Expert Tips for [Location] Homeowners"
- Keep under 65 characters

STRICT FORMAT — return EXACTLY using these delimiters, nothing before ===H1===:

===H1===
[SEO-optimized H1 title following the rules above. No quotes.]

===META===
[Meta description, 150-160 characters, includes focus keyword near the start, ends with a subtle CTA. No quotes.]

===TOC===
<ul>
  <li><a href="#section-1">First Section Title</a></li>
  <li><a href="#section-2">Second Section Title</a></li>
  <li><a href="#section-3">Third Section Title</a></li>
  <li><a href="#section-4">Fourth Section Title</a></li>
  <li><a href="#section-5">Fifth Section Title</a></li>
  <li><a href="#section-6">Sixth Section Title</a></li>
</ul>

===BODY===
[Full HTML blog post body — minimum 2000 words. Rules:
- Do NOT include H1 (that is separate above)
- Use 6 <h2 id="section-N"> sections matching the TOC anchors above
- At least ONE <h2> must contain the focus keyword naturally
- Use <h3> subsections inside each <h2> to add depth (aim for 2-3 <h3> per <h2>)
- Wrap all paragraphs in <p> tags
- Paragraphs: 2-4 sentences max — never write a wall of text
- Focus keyword appears in the first 100 words
- Focus keyword appears 8-12 times total throughout the post (natural 0.5-1% density for 2000 words)
- Insert 3-5 internal links naturally: <a href="URL">anchor text</a>
- End with a strong <h2 id="section-6">Conclusion</h2> section that includes the focus keyword and a clear CTA
- No filler phrases like "In conclusion", "In today's world", "Look no further", or "At [Business]"
- Write with specific, useful information — not generic advice]

===END===
PROMPT,

        'blog_titles_batch' => <<<'PROMPT'
You are an SEO content strategist specializing in local business content marketing.

Business: {business_name}
Services: {services}
Location: {location}

Existing blog titles to avoid duplicating topics:
{existing_titles}

Generate {count} blog post title ideas. Mix these three content types:
- Services: Targets service-specific keywords ("Best roofing company in {location}", "Roof repair vs replacement")
- Informational: How-to guides, FAQs, educational content ("How to know if your roof needs repair")
- Seasonal: Time-sensitive local topics ("Preparing your roof for {location} winters")

Rules:
- Each title must target a distinct keyword angle
- Include natural local modifiers where it makes sense (city/region from Location)
- Use power words that drive clicks
- Keep titles under 65 characters where possible

Return ONLY a numbered list in this exact format (one per line, no extra text):
1. [Title] | [Category: Services/Informational/Seasonal] | [Focus Keyword]
2. [Title] | [Category] | [Focus Keyword]
PROMPT,
    ];

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Run an AI generation job.
     *
     * @param array $options Override default settings for this call only.
     *                       Supported keys: max_tokens, temperature.
     *                       Useful for blog posts that need higher token limits.
     */
    public static function generate(string $template_key, array $vars, string $client_id = '', array $options = []): array
    {
        $settings = self::getSettings();
        // Apply per-call overrides (e.g. max_tokens for full blog posts)
        $settings = array_merge($settings, $options);

        $provider = $settings['provider'] ?? 'groq';
        $api_key  = $settings[$provider . '_api_key'] ?? '';

        if (empty($api_key)) {
            return ['success' => false, 'error' => "No API key configured for provider: $provider", 'content' => ''];
        }

        // Build prompt
        $template = self::getTemplate($template_key);
        if (!$template) {
            return ['success' => false, 'error' => "Unknown template: $template_key", 'content' => ''];
        }
        $prompt = self::interpolate($template, $vars);

        // Check cache
        $cache_key = 'ai_' . md5($provider . $prompt);
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return ['success' => true, 'content' => $cached, 'cached' => true, 'tokens_used' => 0];
        }

        // Rate limiting
        if (!self::checkRateLimit($provider)) {
            return ['success' => false, 'error' => 'Rate limit reached. Try again in 1 minute.', 'content' => ''];
        }

        // Call provider
        $result = match ($provider) {
            'groq'       => self::callGroq($api_key, $prompt, $settings),
            'openai'     => self::callOpenAI($api_key, $prompt, $settings),
            'together'   => self::callTogetherAI($api_key, $prompt, $settings),
            'xai'        => self::callXAI($api_key, $prompt, $settings),
            default      => ['success' => false, 'error' => "Unknown provider: $provider", 'content' => ''],
        };

        // Cache successful results
        if ($result['success'] && !empty($result['content'])) {
            $cache_ttl = (int)($settings['cache_ttl'] ?? 86400); // 24h default
            set_transient($cache_key, $result['content'], $cache_ttl);
        }

        return $result;
    }

    /**
     * Get all prompt templates (merging defaults with DB overrides)
     */
    public static function getTemplate(string $key): ?string
    {
        $overrides = get_option('wnq_ai_prompt_templates', []);
        if (!empty($overrides[$key])) {
            return $overrides[$key];
        }
        return self::$prompt_templates[$key] ?? null;
    }

    /**
     * Save a prompt template override
     */
    public static function saveTemplate(string $key, string $template): void
    {
        $overrides = get_option('wnq_ai_prompt_templates', []);
        $overrides[$key] = $template;
        update_option('wnq_ai_prompt_templates', $overrides);
    }

    public static function getDefaultTemplates(): array
    {
        return self::$prompt_templates;
    }

    public static function getSettings(): array
    {
        return get_option('wnq_ai_settings', [
            'provider'         => 'openai',
            'openai_api_key'   => '',
            'openai_model'     => 'gpt-4o-mini',
            'groq_api_key'     => '',
            'groq_model'       => 'llama-3.1-8b-instant',
            'together_api_key' => '',
            'together_model'   => 'mistralai/Mixtral-8x7B-Instruct-v0.1',
            'xai_api_key'      => '',
            'xai_model'        => 'grok-3-mini-latest',
            'cache_ttl'        => 86400,
            'max_tokens'       => 2000,
            'temperature'      => 0.7,
        ]);
    }

    public static function saveSettings(array $data): void
    {
        $current = self::getSettings();
        $allowed = ['provider', 'groq_api_key', 'groq_model', 'openai_api_key', 'openai_model',
                    'together_api_key', 'together_model', 'xai_api_key', 'xai_model',
                    'psi_api_key', 'cache_ttl', 'max_tokens', 'temperature'];
        foreach ($allowed as $k) {
            if (isset($data[$k])) {
                $current[$k] = $data[$k];
            }
        }
        update_option('wnq_ai_settings', $current);
    }

    // ── Providers ──────────────────────────────────────────────────────────

    private static function callGroq(string $api_key, string $prompt, array $settings): array
    {
        $model    = $settings['groq_model'] ?? 'llama-3.1-8b-instant';
        $max_tok  = (int)($settings['max_tokens'] ?? 2000);
        $temp     = (float)($settings['temperature'] ?? 0.7);

        $response = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'max_tokens'  => $max_tok,
                'temperature' => $temp,
            ]),
        ]);

        return self::parseOpenAIResponse($response, $model);
    }

    private static function callOpenAI(string $api_key, string $prompt, array $settings): array
    {
        $model   = $settings['openai_model'] ?? 'gpt-3.5-turbo';
        $max_tok = (int)($settings['max_tokens'] ?? 2000);
        $temp    = (float)($settings['temperature'] ?? 0.7);

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'max_tokens'  => $max_tok,
                'temperature' => $temp,
            ]),
        ]);

        return self::parseOpenAIResponse($response, $model);
    }

    private static function callTogetherAI(string $api_key, string $prompt, array $settings): array
    {
        $model   = $settings['together_model'] ?? 'mistralai/Mixtral-8x7B-Instruct-v0.1';
        $max_tok = (int)($settings['max_tokens'] ?? 2000);
        $temp    = (float)($settings['temperature'] ?? 0.7);

        $response = wp_remote_post('https://api.together.xyz/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'max_tokens'  => $max_tok,
                'temperature' => $temp,
            ]),
        ]);

        return self::parseOpenAIResponse($response, $model);
    }

    private static function callXAI(string $api_key, string $prompt, array $settings): array
    {
        $model   = $settings['xai_model'] ?? 'grok-3-mini-latest';
        $max_tok = (int)($settings['max_tokens'] ?? 2000);
        $temp    = (float)($settings['temperature'] ?? 0.7);

        $response = wp_remote_post('https://api.x.ai/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'max_tokens'  => $max_tok,
                'temperature' => $temp,
            ]),
        ]);

        return self::parseOpenAIResponse($response, $model);
    }

    private static function parseOpenAIResponse($response, string $model): array
    {
        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message(), 'content' => ''];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['error']['message'] ?? "HTTP $code";
            return ['success' => false, 'error' => $msg, 'content' => ''];
        }

        $content = $body['choices'][0]['message']['content'] ?? '';
        $tokens  = $body['usage']['total_tokens'] ?? 0;

        return [
            'success'     => true,
            'content'     => trim($content),
            'tokens_used' => $tokens,
            'model'       => $model,
            'cached'      => false,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function interpolate(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            if (is_array($value)) $value = implode(', ', $value);
            $template = str_replace('{' . $key . '}', (string)$value, $template);
        }
        return $template;
    }

    private static function checkRateLimit(string $provider): bool
    {
        $key   = self::RATE_KEY . $provider;
        $count = (int)get_transient($key);
        if ($count >= self::MAX_RPM) {
            return false;
        }
        set_transient($key, $count + 1, 60);
        return true;
    }

    /**
     * Test provider connection
     */
    public static function testConnection(): array
    {
        $settings = self::getSettings();
        $provider = $settings['provider'] ?? 'groq';
        $api_key  = $settings[$provider . '_api_key'] ?? '';

        if (empty($api_key)) {
            return ['success' => false, 'error' => 'No API key set for ' . $provider];
        }

        $result = self::generate('meta_tags', [
            'business_name' => 'Test Business',
            'page_type'     => 'home',
            'topic'         => 'web design',
            'keyword'       => 'web design services',
            'location'      => 'New York',
            'current_title' => '',
            'current_meta'  => '',
        ]);

        return $result;
    }
}
