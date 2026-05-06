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
    private const BLOG_PROMPT_VERSION = '2026-05-manual-html-format-v1';

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

        'blog_post_full' => <<<'PROMPT'
You are an expert local SEO copywriter writing on behalf of {business_name}. Create a unique, publish-ready blog post using this exact content brief.

Blog Title: {title}
Primary Keyword: {focus_keyword}
URL Slug: {url_slug}
Business: {business_name}
Services: {services}
Location: {location}
Category Type: Informational
Tone: {tone}
Target Word Count: 1,500-2,000+ words. If the active AI provider has a tight token limit, prioritize a complete, non-repetitive 1,200-1,500 word post over an unfinished longer draft.

Client Keyword Data (weave relevant terms naturally into the content):
{keyword_context}

Structure & Formatting:
- Use only ONE H1 tag in the H1 section below, and it must be the Blog Title exactly.
- Do not add the Primary Keyword, location, business name, subtitle, colon phrase, "with...", or any extra wording to the H1.
- Make the content easy to read, well organized, and naturally flowing.
- Start with an introduction that clearly identifies the target audience, answers the main query in the first paragraph, and explains what the reader will learn.
- Use H2s for main sections and H3s for subsections.
- Keep paragraphs short: 2-3 sentences max.
- Use light lists where helpful, but prioritize paragraph-based writing.

SEO Optimization:
- Include the Primary Keyword in the URL slug, first 100 words, at least one H2, and naturally throughout the post.
- Do not force the Primary Keyword into the H1 if it is not already part of the Blog Title.
- Keep keyword density around 1-2%; do not keyword stuff.
- Use natural keyword variations throughout.
- Write in a human, expert tone. Avoid AI-sounding phrasing.
- Optimize for featured snippets and readability.

Content Requirements:
- Write a valuable, actionable post aligned with search intent.
- Avoid fluff and repetitive content. Every section must provide real value.
- Write at a high school senior reading level.
- Make this post meaningfully different from other posts by focusing tightly on the Blog Title and Primary Keyword.

Conclusion & FAQ:
- Write a strong conclusion summarizing key points and reinforcing the topic.
- Add an FAQ section at the end with 5-10 questions.
- Each FAQ answer should be 2-4 sentences and target long-tail keyword variations.

HTML Format:
- Format the article like this structure: a Table of Contents block, then an article with short paragraphs, H2 sections, H3 subsections, conclusion, and FAQ.
- The Table of Contents must use: <div class="blog-toc"><h2>Table of Contents</h2><ul>...</ul></div>
- Section IDs must be readable slugs, for example id="what-is-ceramic-coating", not "section-1".
- The BODY section must start with <article> and end with </article>.
- Do not include schema scripts in BODY. The publishing agent adds schema separately.

STRICT FORMAT — return EXACTLY using these delimiters, nothing before ===H1===:

===H1===
{title}

===META===
[Meta description, 150-160 characters, includes the Primary Keyword near the start, ends with a subtle CTA. No quotes.]

===TOC===
<div class="blog-toc">
  <h2>Table of Contents</h2>
  <ul>
    <li><a href="#readable-section-id">Readable Section Title</a></li>
    <li><a href="#conclusion">Conclusion</a></li>
    <li><a href="#faq">FAQ</a></li>
  </ul>
</div>

===BODY===
[Full HTML blog post body. Rules:
- Start with <article> and end with </article>
- Include exactly one <h1 id="{url_slug}">{title}</h1> as the first element inside <article>
- Use <h2 id="readable-section-id"> sections matching the TOC anchors above
- At least ONE <h2> must contain the Primary Keyword naturally
- Use <h3> subsections inside sections where helpful
- Use valid HTML only. Do not use Markdown, plain-text headings, or loose labels.
- Wrap all paragraphs in <p> tags
- Paragraphs: 2-3 sentences max — never write a wall of text
- Primary Keyword appears in the first 100 words
- Do not include any <a> tags in the BODY.
- Include <h2 id="conclusion">Conclusion</h2>
- Include an FAQ section exactly like this pattern:
  <h2 id="faq">Frequently Asked Questions</h2>
  <h3>Question written as a full sentence?</h3>
  <p>Answer in 2-4 sentences.</p>
- Never write inline "Q:" or "A:" FAQ text. FAQ questions must be <h3> headings and answers must be <p> paragraphs.
- No filler phrases like "In conclusion", "In today's world", "Look no further", or "At [Business]"
- Write with specific, useful information — not generic advice
- Content should feel like it was written by an expert representing {business_name}]

===END===
PROMPT,

        'service_city_page' => <<<'PROMPT'
You are an expert local SEO copywriter writing a Service + City page for {business_name}. Create unique, useful page content for this exact page.

Primary Keyword: {primary_keyword}
Service: {service}
Service Variations: {service_variations}
City: {city}
State: {state}
County: {county}
URL Slug: {slug}
Page Title: {page_title}
Title Tag: {title_tag}
Meta Description: {meta_description}
H1: {h1}
CTA Title: {cta_title}
CTA Text: {cta_text}
Related Services: {related_services}
Nearby Cities: {nearby_cities}
Internal Links: {internal_links}
Geo Modifiers: {geo_modifiers}
Commercial Intent: {commercial_intent}
Keyword Variants: {keyword_variants}
Tone: {tone}

Rules:
- Write one localized service page, not a blog post.
- The content must be specific to the service and city, with helpful details a real customer would care about.
- Do not publish-sounding boilerplate, fake guarantees, fake reviews, fake stats, or generic filler.
- Keep paragraphs short, 2-3 sentences max.
- Use H2s and H3s only. Do not include an H1 because the template controls the H1.
- Naturally include the Primary Keyword in the first 100 words and in at least one H2.
- Naturally mention relevant service variations, nearby cities, county, and geo modifiers where they make sense.
- Use the CTA Title and CTA Text once near the end.
- If internal links are provided, mention the page topics naturally, but do not add raw URL lists.
- Write 700-1,100 words.
- Return valid HTML only inside the BODY delimiter. Do not use markdown.
- Do not include schema scripts.

Recommended structure:
- Intro section that answers what the page is about immediately
- Why customers in {city} need this service
- What is included
- Local process or approach
- Related services / nearby areas
- CTA
- FAQ with 4-6 questions

STRICT FORMAT - return exactly:

===BODY===
[HTML content using <section>, <h2>, <h3>, <p>, <ul>, and <li>. No <h1>.]
===END===
PROMPT,

        'blog_titles_batch' => <<<'PROMPT'
You are an SEO content strategist specializing in local business content marketing. Generate simple, clear blog titles.

Business: {business_name}
Services: {services}
Location: {location}

Existing blog titles to avoid duplicating topics:
{existing_titles}

Generate {count} blog post title ideas.

Rules:
- Keep titles simple, direct, and easy to understand.
- Do not make titles long, clever, or complicated.
- Do not use colons, subtitles, "with...", "for your...", or stacked keyword phrases.
- Each title must target one distinct keyword angle.
- Every title must be informational, helpful, and blog-style.
- Use natural local modifiers only where they make sense.
- Keep titles under 8 words when possible and always under 55 characters.
- Avoid duplicate topics from the existing blog titles.

Return ONLY a numbered list in this exact format (one per line, no extra text):
1. [Title] | Informational | [Focus Keyword]
2. [Title] | Informational | [Focus Keyword]
PROMPT,

        // ── Backlink / Outreach Templates ──────────────────────────────────

        'backlink_outreach_email' => <<<'PROMPT'
Write a short, casual outreach email asking about guest posting on {target_domain}.

From: {business_name} ({website})

Rules:
- First line must be: Subject: Guest Post Inquiry
- Then a blank line, then the email body
- 3-4 sentences max
- Casual and friendly, not salesy
- Just ask about the process and pricing for a guest post or article
- Sign off with the name from {business_name}

Write it now:
PROMPT,

        'backlink_opportunities' => <<<'PROMPT'
You are a link-building specialist. Find real, specific websites where {business_name} ({services}, based in {location}) can get backlinks through guest posts or outreach.

Return ONLY a valid JSON array of 8 real websites. Every item must have these exact fields:
- "type": one of [guest_post, resource_page, directory, sponsor, press, partnership]
- "site_name": the real name of the website (e.g. "Search Engine Journal")
- "domain": the real domain only, no https, no trailing slash (e.g. "searchenginejournal.com")
- "contact_email": the most likely editorial or contact email for link outreach at this site (e.g. "contribute@searchenginejournal.com"). Use common patterns like contribute@, guest@, editor@, editorial@, hello@, or info@ if unsure.
- "pitch": one sentence describing what to pitch to this specific site, relevant to {services}

Mix industry blogs relevant to {services} with local {location} blogs and business publications. Only include websites that genuinely exist and accept contributions or outreach. Return JSON only, no markdown, no explanation.
PROMPT,

    ];

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Run an AI generation job.
     *
     * @param array $options Override default settings for this call only.
     *                       Supported keys: max_tokens, temperature, no_cache.
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

        // Check cache unless this call needs fresh AI output.
        $cache_key = 'ai_' . md5($provider . $prompt);
        $no_cache = !empty($settings['no_cache']);
        if (!$no_cache) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return ['success' => true, 'content' => $cached, 'cached' => true, 'tokens_used' => 0];
            }
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
        if (!$no_cache && $result['success'] && !empty($result['content'])) {
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
        if (in_array($key, ['blog_post_full', 'blog_titles_batch'], true)) {
            self::ensureBlogPromptDefaults();
        }

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

    private static function ensureBlogPromptDefaults(): void
    {
        if (get_option('wnq_ai_blog_prompt_version', '') === self::BLOG_PROMPT_VERSION) {
            return;
        }

        $overrides = get_option('wnq_ai_prompt_templates', []);
        $overrides['blog_post_full'] = self::$prompt_templates['blog_post_full'];
        $overrides['blog_titles_batch'] = self::$prompt_templates['blog_titles_batch'];
        update_option('wnq_ai_prompt_templates', $overrides);
        update_option('wnq_ai_blog_prompt_version', self::BLOG_PROMPT_VERSION);
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

    public static function maxTokensForBlogGeneration(): int
    {
        $settings = self::getSettings();
        $provider = $settings['provider'] ?? 'openai';

        if ($provider === 'groq') {
            return 4200;
        }

        return 7000;
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
