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
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class AIEngine
{
    private const BLOG_PROMPT_VERSION = '2026-05-manual-html-format-v1';
    private const SERVICE_CITY_PROMPT_VERSION = '2026-05-short-headings-no-intro-v1';

    const CACHE_GROUP   = 'wnq_ai_cache';
    const RATE_KEY      = 'wnq_ai_rate_';
    const TOKEN_RATE_KEY = 'wnq_ai_tokens_';
    const COOLDOWN_KEY  = 'wnq_ai_cooldown_';
    const MAX_RPM       = 30;  // requests per minute per provider (conservative)
    const GROQ_TPM_BUDGET = 5200; // Keep a buffer below the 6,000 TPM account limit.

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
You are an SEO agency reporting specialist. Write a professional executive summary for a monthly analytics report:

Client: {client_name}
Period: {period}
GA4 Metrics:
- Traffic: {traffic_change}
- Visitors: {visitors}
- Sessions: {sessions}
- Page Views: {page_views}
- Bounce Rate: {bounce_rate}
- Key Events: {key_events}

Google Search Console Metrics:
- Organic Clicks: {search_clicks}
- Search Impressions: {search_impressions}
- CTR: {search_ctr}
- Average Position: {search_position}
- Top Queries: {top_queries}

Write a 2-3 paragraph executive summary that:
1. Summarizes website traffic and engagement clearly
2. Summarizes organic search visibility from Google Search Console
3. Keeps the tone client-friendly and factual

Do not include recommended next steps, technical audit notes, site health, or blog activity.

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

Business Phone: {business_phone}
Business Email: {business_email}
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
Navigation Related Services: {navigation_menu_related_services}
Nearby Cities: {nearby_cities}
Navigation Nearby Areas: {nav_menu_nearby_areas}
Internal Links: {internal_links}
Geo Modifiers: {geo_modifiers}
Commercial Intent: {commercial_intent}
Page Type: {page_type}
Keyword Variants: {keyword_variants}
Tone: {tone}

Rules:
- Write one localized service page, not a blog post.
- The content must be specific to the service and city, with helpful details a real customer would care about.
- Do not publish-sounding boilerplate, fake guarantees, fake reviews, fake stats, or generic filler.
- Keep paragraphs short, 2-3 sentences max.
- Use H2s and H3s only. Do not include an H1 because the template controls the H1.
- Keep every H2 short, 3-7 words when possible, keyword-focused, and free of colons or long subtitles.
- Do not use generic headings like "Introduction", "Overview", "Conclusion", "Final Thoughts", "Get Started", or "Frequently Asked Questions".
- Do not start sentences with "At {business_name}" or "At King Sheds". Use direct wording like "{business_name} helps..." or "{service} customers in {city} can...".
- Naturally include the Primary Keyword in the first 100 words and in at least one H2.
- Naturally mention relevant service variations, related services, nearby cities/areas, county, and geo modifiers where they make sense.
- Do not create a standalone CTA, conclusion, or FAQ section. The Elementor template controls the final CTA.
- Never write labels like "CTA Title:" or "CTA Text:".
- If Business Phone or Business Email is provided, use the exact value when contact copy needs it. If either value is blank, omit it instead of writing placeholders like "[insert phone number]" or "[insert email address]".
- If internal links are provided, mention the page topics naturally, but do not add raw URL lists.
- Write 1,050-1,250 words for this first pass. If the system needs more length, it will request a continuation after this.
- Return valid HTML only inside the BODY delimiter. Do not use markdown.
- Do not include schema scripts.

Recommended structure:
- Local service overview with a keyword-focused H2
- Options, models, or details customers compare
- Permits, preparation, or installation requirements
- Local delivery or setup process
- Related services and nearby areas
- Practical selection, care, or next-step guidance

STRICT FORMAT - return exactly:

===BODY===
[HTML content using <section>, <h2>, <h3>, <p>, <ul>, and <li>. No <h1>.]
===END===
PROMPT,

        'service_city_page_expansion' => <<<'PROMPT'
You are continuing a local SEO Service + City page for {business_name}. Expand the page with useful, non-repetitive content that fits after the existing body.

Business Phone: {business_phone}
Business Email: {business_email}
Primary Keyword: {primary_keyword}
Service: {service}
Service Variations: {service_variations}
City: {city}
State: {state}
County: {county}
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

Current Word Count: {current_word_count}
Approximate Additional Words Needed: {remaining_words}

Existing Body:
{existing_body}

Rules:
- Continue the same page; do not restart the article.
- Do not repeat headings or paragraphs already present in Existing Body.
- Do not add a CTA, conclusion, or FAQ section. The Elementor template controls the final CTA.
- Do not use generic headings like "Introduction", "Overview", "Conclusion", "Final Thoughts", "Get Started", or "Frequently Asked Questions".
- Do not start sentences with "At {business_name}" or "At King Sheds".
- If Business Phone or Business Email is blank, omit it instead of writing placeholders.
- Add roughly the Approximate Additional Words Needed. If the page needs a large expansion, add 500-750 words. If it only needs a small finish, add one tight 250-400 word section.
- Use H2s and H3s only. Do not include an H1.
- Keep every H2 short, 3-7 words when possible, keyword-focused, and free of colons or long subtitles.
- Add practical sections such as sizing/selection guidance, local delivery considerations, nearby service areas, maintenance/care, and comparison details.
- Keep paragraphs short, 2-3 sentences max.
- Return valid HTML only inside the BODY delimiter. Do not use markdown.
- Do not include schema scripts.

STRICT FORMAT - return exactly:

===BODY===
[Continuation HTML using <section>, <h2>, <h3>, <p>, <ul>, and <li>. No <h1>.]
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

        'elementor_variable_payload' => <<<'PROMPT'
You are an SEO agency page strategist writing content for editable Elementor templates.

Create a JSON variable payload for the selected Elementor section templates.

Business Name: {business_name}
Website/Brand Notes: {brand_notes}
Primary Service: {service}
Primary City: {city}
State: {state}
Target Audience: {audience}
Page Goal: {page_goal}
Tone: {tone}
Theme Style: {theme_style}

Selected Section Blueprint:
{section_context}

Template Variables To Fill:
{variables}

Image URL Variables:
{image_variables}

Quality Review Feedback:
{quality_feedback}

Previous Payload To Improve:
{previous_payload}

Rules:
- Return ONLY one valid JSON object. No markdown, no code fences, no comments.
- Include every variable listed in Template Variables To Fill.
- Use the Selected Section Blueprint as the writing plan. Match each variable's copy to the section category, label, purpose, and position where it appears.
- Do not write generic page copy into every section. Each section must perform its labeled job.
- For FAQ sections, write useful service- and location-relevant questions with direct answers. Question variables must be questions and answer variables must answer them.
- For hero sections, write a clear primary headline, supporting value proposition, and focused CTA copy.
- For services sections, describe distinct relevant services and customer outcomes.
- For process sections, write sequential steps in the order a customer would experience them.
- For CTA sections, write concise action-oriented copy that supports the page goal.
- For reviews or testimonial sections, never invent customer quotes, names, ratings, or claims. Use neutral trust copy or empty strings when real review content is unavailable.
- Every substantial text field must add new information. Do not repeat or lightly reword the same sentence across multiple variables.
- Paragraph and body-copy variables should usually be 45-90 words. FAQ answers and service descriptions should usually be 30-70 words. Keep headings, labels, and CTA text concise.
- When Quality Review Feedback lists problems, correct every listed problem while preserving valid values and returning the complete JSON payload.
- Keep headings direct and conversion-focused.
- Write natural, human service-business copy.
- Do not invent phone numbers, addresses, prices, awards, reviews, licenses, or guarantees.
- For image URL variables, return an empty string unless the brand notes explicitly provide a public image URL.
- For contact_form_iframe, return an empty string unless the brand notes explicitly provide the complete iframe embed code. Never invent an iframe URL.
- For color variables, choose accessible values that match the requested theme style.
- For CTA URLs, use simple relative links such as "/contact/" or "/services/".
- Keep paragraph variables concise: 1-3 sentences each unless the variable name clearly asks for long content.
- If a variable is unclear, infer a practical value from the service, city, and page goal.
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
        if (
            $template_key === 'elementor_variable_payload'
            && !empty($vars['section_context'])
            && strpos($template, '{section_context}') === false
        ) {
            $template .= "\n\nSelected Section Blueprint:\n{section_context}\n\n"
                . "Match every generated value to its section label, category, purpose, and variables. "
                . "FAQ sections must contain relevant questions and direct answers; other sections must perform their labeled role.";
        }
        if (
            $template_key === 'elementor_variable_payload'
            && !empty($vars['quality_feedback'])
            && strpos($template, '{quality_feedback}') === false
        ) {
            $template .= "\n\nQuality Review Feedback:\n{quality_feedback}\n\n"
                . "Correct every listed issue, avoid repeated copy, and return the complete JSON payload.";
        }
        if (
            $template_key === 'elementor_variable_payload'
            && !empty($vars['previous_payload'])
            && strpos($template, '{previous_payload}') === false
        ) {
            $template .= "\n\nPrevious Payload To Improve:\n{previous_payload}";
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

        $cooldown = self::providerCooldownRemaining($provider);
        if ($cooldown > 0) {
            return self::rateLimitResult($provider, $cooldown, 'AI provider cooldown is active.');
        }

        // Reserve a conservative estimate before calling providers with TPM limits.
        $token_wait = self::reserveEstimatedTokens($provider, $prompt, (int)($settings['max_tokens'] ?? 2000));
        if ($token_wait > 0) {
            return self::rateLimitResult($provider, $token_wait, 'Local token budget reached.');
        }

        // Request-per-minute limiting.
        if (!self::checkRateLimit($provider)) {
            return self::rateLimitResult($provider, 60, 'Local request limit reached.');
        }

        // Call provider
        $result = match ($provider) {
            'groq'       => self::callGroq($api_key, $prompt, $settings),
            'openai'     => self::callOpenAI($api_key, $prompt, $settings),
            'together'   => self::callTogetherAI($api_key, $prompt, $settings),
            'xai'        => self::callXAI($api_key, $prompt, $settings),
            default      => ['success' => false, 'error' => "Unknown provider: $provider", 'content' => ''],
        };

        if (!empty($result['rate_limited'])) {
            self::setProviderCooldown($provider, (int)($result['retry_after'] ?? 60));
        }

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
        if (in_array($key, ['service_city_page', 'service_city_page_expansion'], true)) {
            self::ensureServiceCityPromptDefaults();
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

    private static function ensureServiceCityPromptDefaults(): void
    {
        if (get_option('wnq_ai_service_city_prompt_version', '') === self::SERVICE_CITY_PROMPT_VERSION) {
            return;
        }

        $overrides = get_option('wnq_ai_prompt_templates', []);
        $overrides['service_city_page'] = self::$prompt_templates['service_city_page'];
        $overrides['service_city_page_expansion'] = self::$prompt_templates['service_city_page_expansion'];
        update_option('wnq_ai_prompt_templates', $overrides);
        update_option('wnq_ai_service_city_prompt_version', self::SERVICE_CITY_PROMPT_VERSION);
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
            return 2800;
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

        return self::parseOpenAIResponse($response, $model, 'groq');
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

        return self::parseOpenAIResponse($response, $model, 'openai');
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

        return self::parseOpenAIResponse($response, $model, 'together');
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

        return self::parseOpenAIResponse($response, $model, 'xai');
    }

    private static function parseOpenAIResponse($response, string $model, string $provider): array
    {
        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message(), 'content' => ''];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['error']['message'] ?? "HTTP $code";
            if ($code === 429 || stripos($msg, 'rate limit') !== false) {
                $retry_after = self::retryAfterSeconds($response, $msg);
                return self::rateLimitResult($provider, $retry_after, $msg);
            }
            return ['success' => false, 'error' => $msg, 'content' => ''];
        }

        $content = $body['choices'][0]['message']['content'] ?? '';
        $tokens  = $body['usage']['total_tokens'] ?? 0;

        return [
            'success'     => true,
            'content'     => trim($content),
            'tokens_used' => $tokens,
            'model'       => $model,
            'provider'    => $provider,
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

    private static function reserveEstimatedTokens(string $provider, string $prompt, int $max_tokens): int
    {
        if ($provider !== 'groq') {
            return 0;
        }

        $key = self::TOKEN_RATE_KEY . $provider;
        $now = time();
        $bucket = get_transient($key);
        if (!is_array($bucket) || (int)($bucket['expires'] ?? 0) <= $now) {
            $bucket = ['used' => 0, 'expires' => $now + 60];
        }

        $estimated_prompt_tokens = max(1, (int)ceil(strlen($prompt) / 4));
        $estimated_total = $estimated_prompt_tokens + max(1, $max_tokens);
        if ((int)$bucket['used'] + $estimated_total > self::GROQ_TPM_BUDGET) {
            return max(5, (int)$bucket['expires'] - $now);
        }

        $bucket['used'] = (int)$bucket['used'] + $estimated_total;
        set_transient($key, $bucket, max(1, (int)$bucket['expires'] - $now));
        return 0;
    }

    private static function providerCooldownRemaining(string $provider): int
    {
        $expires = (int)get_transient(self::COOLDOWN_KEY . $provider);
        return $expires > time() ? $expires - time() : 0;
    }

    private static function setProviderCooldown(string $provider, int $seconds): void
    {
        $seconds = max(5, min(10 * MINUTE_IN_SECONDS, $seconds));
        set_transient(self::COOLDOWN_KEY . $provider, time() + $seconds, $seconds);
    }

    private static function rateLimitResult(string $provider, int $retry_after, string $message): array
    {
        $retry_after = max(5, min(10 * MINUTE_IN_SECONDS, $retry_after));
        return [
            'success'       => false,
            'error'         => rtrim($message) . ' Automatic retry available in about ' . $retry_after . ' seconds.',
            'content'       => '',
            'provider'      => $provider,
            'rate_limited'  => true,
            'retry_after'   => $retry_after,
        ];
    }

    private static function retryAfterSeconds($response, string $message): int
    {
        $header = wp_remote_retrieve_header($response, 'retry-after');
        if (is_numeric($header)) {
            return max(5, (int)ceil((float)$header));
        }
        if (is_string($header) && $header !== '') {
            $timestamp = strtotime($header);
            if ($timestamp !== false && $timestamp > time()) {
                return max(5, $timestamp - time());
            }
        }
        if (preg_match('/try again in\s+([0-9]+(?:\.[0-9]+)?)s/i', $message, $match)) {
            return max(5, (int)ceil((float)$match[1]));
        }
        return 60;
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
