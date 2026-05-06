<?php
/**
 * SEO OS Bootstrap
 *
 * Initializes the SEO Operating System within the WebNique Portal plugin.
 * Loaded from the main plugin file via plugins_loaded hook.
 *
 * Registers:
 *  - SEOHub model table creation
 *  - SEOHubAdmin menu pages and AJAX handlers
 *  - SEOAgentController REST routes
 *  - CronScheduler jobs
 *  - All form POST handlers
 *
 * @package WebNique Portal
 */

namespace WNQ\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class SEOOSBootstrap
{
    public static function init(): void
    {
        // Load all SEO OS classes (including Client model needed by every page)
        self::loadClasses();

        // Set initial AI provider settings (first-run only, won't overwrite saved settings)
        self::maybeInitAISettings();

        // Register admin UI
        if (is_admin()) {
            \WNQ\Admin\SEOHubAdmin::register();
            \WNQ\Admin\BlogSchedulerAdmin::register();
            \WNQ\Admin\SpiderAdmin::register();
            \WNQ\Admin\LeadFinderAdmin::register();
        }

        // Create blog tables if not yet created (schema migration for existing installs)
        self::maybeCreateBlogTables();

        // Create lead finder table if not yet created
        \WNQ\Models\Lead::createTable();

        // Register REST API routes for SEO Agent
        add_action('rest_api_init', function () {
            \WNQ\Controllers\SEOAgentController::registerRoutes();
        });

        // Register cron
        CronScheduler::register();

        // Register POST handlers
        self::registerFormHandlers();
    }

    private static function loadClasses(): void
    {
        $base = WNQ_PORTAL_PATH;

        $files = [
            // Core models — Client must load BEFORE SEOHubAdmin which uses Client::getAll()
            'includes/Models/Client.php',
            'includes/Models/Task.php',
            'includes/Models/SEOHub.php',
            'includes/Models/BlogScheduler.php',
            // Services
            'includes/Services/AIEngine.php',
            'includes/Services/AuditEngine.php',
            'includes/Services/ReportGenerator.php',
            'includes/Services/BlogPublisher.php',
            'includes/Services/SEOHealthFixer.php',
            // Spider & Analysis Services
            'includes/Services/CrawlEngine.php',
            'includes/Services/PageSpeedEngine.php',
            'includes/Services/ContentAnalyzer.php',
            'includes/Services/CompetitorTracker.php',
            'includes/Services/LocalSEOEngine.php',
            'includes/Services/ServiceCoverageEngine.php',
            // Lead Finder
            'includes/Models/Lead.php',
            'includes/Data/FloridaZips.php',
            'includes/Services/GoogleMapsClient.php',
            'includes/Services/LeadSEOScorer.php',
            'includes/Services/LeadEmailExtractor.php',
            'includes/Services/LeadEnrichmentService.php',
            'includes/Services/LeadFinderEngine.php',
            // Controllers & Core
            'includes/Controllers/SEOAgentController.php',
            'includes/Core/CronScheduler.php',
            // Admin UI (must come last — depends on everything above)
            'admin/SEOHubAdmin.php',
            'admin/BlogSchedulerAdmin.php',
            'admin/SpiderAdmin.php',
            'admin/LeadFinderAdmin.php',
        ];

        foreach ($files as $f) {
            $path = $base . $f;
            if (file_exists($path) && !class_exists(self::classFromFile($f))) {
                require_once $path;
            }
        }
    }

    /**
     * Map file path → expected class name for the class_exists guard.
     * Prevents double-loading if the main portal already required the file.
     */
    private static function classFromFile(string $file): string
    {
        $map = [
            'includes/Models/Client.php'              => 'WNQ\\Models\\Client',
            'includes/Models/Task.php'                => 'WNQ\\Models\\Task',
            'includes/Models/SEOHub.php'              => 'WNQ\\Models\\SEOHub',
            'includes/Models/BlogScheduler.php'       => 'WNQ\\Models\\BlogScheduler',
            'includes/Services/AIEngine.php'          => 'WNQ\\Services\\AIEngine',
            'includes/Services/AuditEngine.php'       => 'WNQ\\Services\\AuditEngine',
            'includes/Services/ReportGenerator.php'   => 'WNQ\\Services\\ReportGenerator',
            'includes/Services/BlogPublisher.php'     => 'WNQ\\Services\\BlogPublisher',
            'includes/Services/SEOHealthFixer.php'   => 'WNQ\\Services\\SEOHealthFixer',
            'includes/Controllers/SEOAgentController.php' => 'WNQ\\Controllers\\SEOAgentController',
            'includes/Core/CronScheduler.php'             => 'WNQ\\Core\\CronScheduler',
            'includes/Services/CrawlEngine.php'           => 'WNQ\\Services\\CrawlEngine',
            'includes/Services/PageSpeedEngine.php'       => 'WNQ\\Services\\PageSpeedEngine',
            'includes/Services/ContentAnalyzer.php'       => 'WNQ\\Services\\ContentAnalyzer',
            'includes/Services/CompetitorTracker.php'     => 'WNQ\\Services\\CompetitorTracker',
            'includes/Services/LocalSEOEngine.php'            => 'WNQ\\Services\\LocalSEOEngine',
            'includes/Services/ServiceCoverageEngine.php'     => 'WNQ\\Services\\ServiceCoverageEngine',
            // Lead Finder
            'includes/Models/Lead.php'                        => 'WNQ\\Models\\Lead',
            'includes/Data/FloridaZips.php'                   => 'WNQ\\Data\\FloridaZips',
            'includes/Services/GoogleMapsClient.php'          => 'WNQ\\Services\\GoogleMapsClient',
            'includes/Services/LeadSEOScorer.php'             => 'WNQ\\Services\\LeadSEOScorer',
            'includes/Services/LeadEmailExtractor.php'        => 'WNQ\\Services\\LeadEmailExtractor',
            'includes/Services/LeadEnrichmentService.php'     => 'WNQ\\Services\\LeadEnrichmentService',
            'includes/Services/LeadFinderEngine.php'          => 'WNQ\\Services\\LeadFinderEngine',
            'admin/SEOHubAdmin.php'                           => 'WNQ\\Admin\\SEOHubAdmin',
            'admin/BlogSchedulerAdmin.php'                    => 'WNQ\\Admin\\BlogSchedulerAdmin',
            'admin/SpiderAdmin.php'                           => 'WNQ\\Admin\\SpiderAdmin',
            'admin/LeadFinderAdmin.php'                       => 'WNQ\\Admin\\LeadFinderAdmin',
        ];
        return $map[$file] ?? '';
    }

    /**
     * Set default AI provider settings on first run.
     * Won't overwrite if admin has already saved settings.
     */
    private static function maybeInitAISettings(): void
    {
        if (get_option('wnq_ai_settings') !== false) {
            return; // Already configured — respect existing settings
        }

        update_option('wnq_ai_settings', [
            'provider'         => 'openai',
            'openai_api_key'   => '',
            'openai_model'     => 'gpt-4o-mini',
            'groq_api_key'     => '',
            'groq_model'       => 'llama-3.1-8b-instant',
            'together_api_key' => '',
            'together_model'   => 'mistralai/Mixtral-8x7B-Instruct-v0.1',
            'cache_ttl'        => 86400,
            'max_tokens'       => 2000,
            'temperature'      => 0.7,
        ]);
    }

    private static function registerFormHandlers(): void
    {
        // Save client SEO profile
        add_action('admin_post_wnq_save_seo_profile', [self::class, 'handleSaveProfile']);

        // Add keyword
        add_action('admin_post_wnq_add_seo_keyword', [self::class, 'handleAddKeyword']);

        // Generate/export report
        add_action('admin_post_wnq_export_report', [self::class, 'handleExportReport']);

        // Generate agent API key
        add_action('admin_post_wnq_generate_agent_key', [self::class, 'handleGenerateAgentKey']);

        // Revoke agent key
        add_action('admin_post_wnq_revoke_agent_key', [self::class, 'handleRevokeAgentKey']);

        // Save AI settings
        add_action('admin_post_wnq_save_ai_settings', [self::class, 'handleSaveAISettings']);

        // Save prompt templates
        add_action('admin_post_wnq_save_prompt_templates', [self::class, 'handleSavePromptTemplates']);

        // Reset prompt templates
        add_action('admin_post_wnq_reset_prompt_templates', [self::class, 'handleResetPromptTemplates']);

        // Competitors
        add_action('admin_post_wnq_save_competitors',      [self::class, 'handleSaveCompetitors']);

        // Local SEO
        add_action('admin_post_wnq_save_local_location',   [self::class, 'handleSaveLocalLocation']);
        add_action('admin_post_wnq_delete_local_location', [self::class, 'handleDeleteLocalLocation']);

        // Spider sitemap export
        add_action('admin_post_wnq_spider_sitemap',        [self::class, 'handleSpiderSitemap']);

        // Blog Scheduler handlers
        add_action('admin_post_wnq_blog_add_post',         [self::class, 'handleBlogAddPost']);
        add_action('admin_post_wnq_blog_import_titles',    [self::class, 'handleBlogImportTitles']);
        add_action('admin_post_wnq_blog_update_post',      [self::class, 'handleBlogUpdatePost']);
        add_action('admin_post_wnq_blog_bulk_delete',      [self::class, 'handleBlogBulkDelete']);
        add_action('admin_post_wnq_blog_delete_all',       [self::class, 'handleBlogDeleteAll']);
        add_action('admin_post_wnq_blog_delete_post',      [self::class, 'handleBlogDeletePost']);
        add_action('admin_post_wnq_blog_save_featured',    [self::class, 'handleBlogSaveFeaturedImage']);
        add_action('admin_post_wnq_blog_save_template',    [self::class, 'handleBlogSaveTemplate']);
        add_action('admin_post_wnq_blog_mark_all_read',    [self::class, 'handleBlogMarkAllRead']);
        add_action('wp_ajax_wnq_blog_add_batch',           [self::class, 'ajaxBlogAddBatch']);
    }

    // ── Form Handlers ───────────────────────────────────────────────────────

    public static function handleSaveProfile(): void
    {
        check_admin_referer('wnq_seo_profile_' . ($_POST['client_id'] ?? ''));
        self::requireCap();

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        if (empty($client_id)) {
            wp_die('Invalid client ID');
        }

        $services   = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['primary_services'] ?? ''))));
        $locations  = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['service_locations'] ?? ''))));

        // Parse keyword clusters JSON
        $kw_clusters_raw = stripslashes($_POST['keyword_clusters'] ?? '{}');
        $kw_clusters     = json_decode($kw_clusters_raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $kw_clusters = [];
        }

        \WNQ\Models\SEOHub::upsertProfile($client_id, [
            'primary_services'  => array_values($services),
            'service_locations' => array_values($locations),
            'keyword_clusters'  => $kw_clusters,
            'brand_notes'       => sanitize_textarea_field($_POST['brand_notes'] ?? ''),
            'content_tone'      => sanitize_text_field($_POST['content_tone'] ?? 'professional'),
            'gsc_property'      => esc_url_raw($_POST['gsc_property'] ?? ''),
            'ga_property'       => sanitize_text_field($_POST['ga_property'] ?? ''),
        ]);

        // Also queue keyword imports from clusters if defined
        foreach ($kw_clusters as $cluster_name => $keywords) {
            foreach ((array)$keywords as $kw) {
                if (!empty(trim($kw))) {
                    \WNQ\Models\SEOHub::upsertKeyword($client_id, [
                        'keyword'      => strtolower(trim($kw)),
                        'cluster_name' => $cluster_name,
                    ]);
                }
            }
        }

        \WNQ\Models\SEOHub::log('profile_saved', ['client_id' => $client_id], 'success', 'manual');

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-clients&client_id=' . urlencode($client_id) . '&saved=1'));
        exit;
    }

    public static function handleAddKeyword(): void
    {
        check_admin_referer('wnq_add_keyword');
        self::requireCap();

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $keyword   = sanitize_text_field($_POST['keyword'] ?? '');

        if (empty($client_id) || empty($keyword)) {
            wp_die('Missing required fields');
        }

        \WNQ\Models\SEOHub::upsertKeyword($client_id, [
            'keyword'          => $keyword,
            'cluster_name'     => sanitize_text_field($_POST['cluster_name'] ?? ''),
            'service_category' => sanitize_text_field($_POST['service_category'] ?? ''),
            'location'         => sanitize_text_field($_POST['location'] ?? ''),
            'target_url'       => esc_url_raw($_POST['target_url'] ?? ''),
        ]);

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-keywords&client_id=' . urlencode($client_id) . '&added=1'));
        exit;
    }

    public static function handleExportReport(): void
    {
        check_admin_referer('wnq_export_report');
        self::requireCap();

        $report_id = (int)($_GET['report_id'] ?? 0);
        if (!$report_id) wp_die('Invalid report ID');

        $html = \WNQ\Services\ReportGenerator::renderReportHTML($report_id);
        if (empty($html)) wp_die('Report not found');

        $report = \WNQ\Models\SEOHub::getReport($report_id);
        $filename = sanitize_file_name('seo-report-' . ($report['client_id'] ?? 'client') . '-' . date('Y-m') . '.html');

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $html;
        exit;
    }

    public static function handleGenerateAgentKey(): void
    {
        check_admin_referer('wnq_generate_agent_key');
        self::requireCap();

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $site_url  = esc_url_raw($_POST['site_url'] ?? '');
        $site_name = sanitize_text_field($_POST['site_name'] ?? '');

        if (empty($client_id) || empty($site_url)) {
            wp_redirect(admin_url('admin.php?page=wnq-seo-hub-api&error=missing_fields'));
            exit;
        }

        $key = \WNQ\Models\SEOHub::generateAgentKey($client_id, $site_url, $site_name);

        // Also create a basic profile for this client if not exists
        $profile = \WNQ\Models\SEOHub::getProfile($client_id);
        if (!$profile) {
            \WNQ\Models\SEOHub::upsertProfile($client_id, [
                'primary_services'  => [],
                'service_locations' => [],
                'keyword_clusters'  => [],
            ]);
        }

        if ($key) {
            \WNQ\Models\SEOHub::log('agent_key_generated', ['client_id' => $client_id, 'site_url' => $site_url], 'success', 'manual');
            wp_redirect(admin_url('admin.php?page=wnq-seo-hub-api&generated=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=wnq-seo-hub-api&error=generation_failed'));
        }
        exit;
    }

    public static function handleRevokeAgentKey(): void
    {
        $key_id = (int)($_POST['key_id'] ?? 0);
        check_admin_referer('wnq_revoke_key_' . $key_id);
        self::requireCap();

        if ($key_id) {
            \WNQ\Models\SEOHub::revokeAgentKey($key_id);
        }

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-api&revoked=1'));
        exit;
    }

    public static function handleSaveAISettings(): void
    {
        check_admin_referer('wnq_save_ai_settings');
        self::requireCap();

        $allowed = ['provider', 'groq_api_key', 'groq_model', 'openai_api_key', 'openai_model',
                    'together_api_key', 'together_model', 'xai_api_key', 'xai_model',
                    'psi_api_key', 'max_tokens', 'temperature', 'cache_ttl'];

        $data = [];
        foreach ($allowed as $k) {
            if (isset($_POST[$k])) {
                $data[$k] = sanitize_text_field($_POST[$k]);
            }
        }

        \WNQ\Services\AIEngine::saveSettings($data);

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-settings&saved=1'));
        exit;
    }

    public static function handleSavePromptTemplates(): void
    {
        check_admin_referer('wnq_save_prompt_templates');
        self::requireCap();

        $templates = $_POST['templates'] ?? [];
        $overrides = [];
        foreach ($templates as $key => $tpl) {
            $overrides[sanitize_key($key)] = sanitize_textarea_field(stripslashes($tpl));
        }
        update_option('wnq_ai_prompt_templates', $overrides);

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-settings&saved=1#prompts'));
        exit;
    }

    public static function handleResetPromptTemplates(): void
    {
        check_admin_referer('wnq_reset_prompt_templates');
        self::requireCap();

        delete_option('wnq_ai_prompt_templates');

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-settings&reset=1'));
        exit;
    }

    // ── Blog Scheduler Form Handlers ────────────────────────────────────────

    public static function handleBlogAddPost(): void
    {
        check_admin_referer('wnq_blog_add_post');
        self::requireCap();

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $title     = sanitize_text_field($_POST['title'] ?? '');
        if (empty($client_id) || empty($title)) wp_die('Missing required fields');

        \WNQ\Models\BlogScheduler::addPost($client_id, [
            'title'          => $title,
            'category_type'  => 'Informational',
            'focus_keyword'  => sanitize_text_field($_POST['focus_keyword'] ?? ''),
            'featured_image_url' => esc_url_raw($_POST['featured_image_url'] ?? ''),
            'scheduled_date' => sanitize_text_field($_POST['scheduled_date'] ?? ''),
            'agent_key_id'   => (int)($_POST['agent_key_id'] ?? 0),
        ]);

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-blog&tab=queue&client_id=' . urlencode($client_id) . '&notice=added'));
        exit;
    }

    public static function handleBlogImportTitles(): void
    {
        check_admin_referer('wnq_blog_import_titles');
        self::requireCap();

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $raw_titles = sanitize_textarea_field(stripslashes($_POST['bulk_titles'] ?? ''));
        if (empty($client_id) || empty($raw_titles)) {
            wp_die('Missing required fields');
        }

        $titles = array_filter(array_map('trim', explode(',', $raw_titles)));
        $start_date_raw = sanitize_text_field($_POST['scheduled_date'] ?? '');
        $start_date = null;
        if (!empty($start_date_raw)) {
            $parsed_date = \DateTimeImmutable::createFromFormat('!Y-m-d', $start_date_raw);
            if ($parsed_date && $parsed_date->format('Y-m-d') === $start_date_raw) {
                $start_date = $parsed_date;
            }
        }

        $seen = [];
        $added = 0;
        foreach ($titles as $title) {
            $title = sanitize_text_field($title);
            $key = strtolower($title);
            if (empty($title) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $scheduled_date = $start_date
                ? $start_date->modify('+' . ($added * 2) . ' days')->format('Y-m-d')
                : '';
            \WNQ\Models\BlogScheduler::addPost($client_id, [
                'title'              => $title,
                'category_type'      => 'Informational',
                'focus_keyword'      => sanitize_text_field($_POST['focus_keyword'] ?? ''),
                'featured_image_url' => '',
                'scheduled_date'     => $scheduled_date,
                'agent_key_id'       => (int)($_POST['agent_key_id'] ?? 0),
            ]);
            $added++;
        }

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-blog&tab=queue&client_id=' . urlencode($client_id) . '&notice=bulk_added&added=' . $added));
        exit;
    }

    public static function handleBlogSaveFeaturedImage(): void
    {
        $post_id   = (int)($_POST['post_id'] ?? 0);
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        check_admin_referer('wnq_blog_featured_' . $post_id);
        self::requireCap();

        if ($post_id) {
            \WNQ\Models\BlogScheduler::updatePost($post_id, [
                'featured_image_url' => esc_url_raw($_POST['featured_image_url'] ?? ''),
            ]);
        }

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-blog&tab=queue&client_id=' . urlencode($client_id) . '&notice=featured_saved'));
        exit;
    }

    public static function handleBlogUpdatePost(): void
    {
        $post_id   = (int)($_POST['post_id'] ?? 0);
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        check_admin_referer('wnq_blog_edit_' . $post_id);
        self::requireCap();

        $post = $post_id ? \WNQ\Models\BlogScheduler::getPost($post_id) : null;
        if (!$post || $post['client_id'] !== $client_id) {
            wp_die('Invalid post');
        }

        $new_title = sanitize_text_field($_POST['title'] ?? '');
        if ($new_title === '') {
            wp_die('Title is required');
        }

        $updates = [
            'title'              => $new_title,
            'category_type'      => 'Informational',
            'focus_keyword'      => sanitize_text_field($_POST['focus_keyword'] ?? ''),
            'featured_image_url' => esc_url_raw($_POST['featured_image_url'] ?? ''),
            'scheduled_date'     => !empty($_POST['scheduled_date']) ? sanitize_text_field($_POST['scheduled_date']) : null,
            'agent_key_id'       => !empty($_POST['agent_key_id']) ? (int)$_POST['agent_key_id'] : null,
        ];

        $content_changed = $new_title !== ($post['title'] ?? '')
            || $updates['focus_keyword'] !== ($post['focus_keyword'] ?? '');
        if ($content_changed && in_array($post['status'], ['pending', 'failed'], true)) {
            $updates['generated_title'] = null;
            $updates['generated_meta']  = null;
            $updates['generated_body']  = null;
            $updates['generated_toc']   = null;
            $updates['status']          = 'pending';
            $updates['error_message']   = null;
        }

        \WNQ\Models\BlogScheduler::updatePost($post_id, $updates);

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-blog&tab=queue&client_id=' . urlencode($client_id) . '&notice=updated'));
        exit;
    }

    public static function handleBlogBulkDelete(): void
    {
        check_admin_referer('wnq_blog_bulk_delete');
        self::requireCap();

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $ids_raw = sanitize_text_field($_POST['post_ids'] ?? '');
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
        $deleted = \WNQ\Models\BlogScheduler::deletePosts($ids, $client_id);

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-blog&tab=queue&client_id=' . urlencode($client_id) . '&notice=bulk_deleted&deleted=' . $deleted));
        exit;
    }

    public static function handleBlogDeleteAll(): void
    {
        check_admin_referer('wnq_blog_delete_all');
        self::requireCap();

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $deleted = \WNQ\Models\BlogScheduler::deletePostsByClient($client_id);

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-blog&tab=queue&client_id=' . urlencode($client_id) . '&notice=bulk_deleted&deleted=' . $deleted));
        exit;
    }

    public static function handleBlogDeletePost(): void
    {
        $post_id   = (int)($_POST['post_id'] ?? 0);
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        check_admin_referer('wnq_blog_delete_' . $post_id);
        self::requireCap();

        if ($post_id) \WNQ\Models\BlogScheduler::deletePost($post_id);

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-blog&tab=queue&client_id=' . urlencode($client_id) . '&notice=deleted'));
        exit;
    }

    public static function handleBlogSaveTemplate(): void
    {
        check_admin_referer('wnq_blog_save_template');
        self::requireCap();

        $json         = stripslashes($_POST['elementor_template'] ?? '');
        $agent_key_id = (int)($_POST['agent_key_id'] ?? 0);
        $client_id    = sanitize_text_field($_POST['client_id'] ?? '');

        // Basic validation: must be a valid JSON array or empty
        if (!empty($json)) {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                wp_redirect(admin_url('admin.php?page=wnq-seo-hub-blog&tab=settings&client_id=' . urlencode($client_id) . '&error=invalid_json'));
                exit;
            }
        }

        // Per-site template (agent_key_id set) or global fallback
        if ($agent_key_id > 0) {
            update_option('wnq_blog_template_site_' . $agent_key_id, $json);
        } else {
            update_option('wnq_blog_elementor_template', $json);
        }

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-blog&tab=settings&client_id=' . urlencode($client_id) . '&settings_saved=1'));
        exit;
    }

    public static function handleBlogMarkAllRead(): void
    {
        check_admin_referer('wnq_blog_mark_all_read');
        self::requireCap();
        \WNQ\Models\BlogScheduler::markAllRead();
        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-blog&tab=queue'));
        exit;
    }

    public static function ajaxBlogAddBatch(): void
    {
        check_ajax_referer('wnq_seohub_nonce', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $client_id    = sanitize_text_field($_POST['client_id'] ?? '');
        $agent_key_id = (int)($_POST['agent_key_id'] ?? 0);
        $posts_raw    = stripslashes($_POST['posts'] ?? '[]');
        $posts        = json_decode($posts_raw, true);

        if (empty($client_id) || !is_array($posts)) {
            wp_send_json_error(['message' => 'Invalid data']);
        }

        $added = 0;
        foreach ($posts as $p) {
            $title = sanitize_text_field($p['title'] ?? '');
            if (empty($title)) continue;
            \WNQ\Models\BlogScheduler::addPost($client_id, [
                'title'          => $title,
                'category_type'  => 'Informational',
                'focus_keyword'  => sanitize_text_field($p['keyword'] ?? ''),
                'scheduled_date' => sanitize_text_field($p['date'] ?? ''),
                'agent_key_id'   => $agent_key_id,
            ]);
            $added++;
        }

        wp_send_json_success(['added' => $added]);
    }

    // ── Competitor Handlers ──────────────────────────────────────────────────

    public static function handleSaveCompetitors(): void
    {
        check_admin_referer('wnq_save_competitors');
        self::requireCap();
        $client_id   = sanitize_text_field($_POST['client_id'] ?? '');
        $competitors = $_POST['competitors'] ?? [];
        if (!$client_id) wp_die('Missing client_id');
        \WNQ\Services\CompetitorTracker::saveCompetitors($client_id, $competitors);
        wp_redirect(admin_url('admin.php?page=wnq-seo-spider&tab=competitors&client_id=' . urlencode($client_id) . '&saved=1'));
        exit;
    }

    // ── Local SEO Handlers ───────────────────────────────────────────────────

    public static function handleSaveLocalLocation(): void
    {
        check_admin_referer('wnq_save_local_location');
        self::requireCap();
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        if (!$client_id) wp_die('Missing client_id');
        \WNQ\Services\LocalSEOEngine::saveLocation($client_id, $_POST);
        wp_redirect(admin_url('admin.php?page=wnq-seo-spider&tab=local&client_id=' . urlencode($client_id) . '&saved=1'));
        exit;
    }

    public static function handleDeleteLocalLocation(): void
    {
        $location_id = (int)($_POST['location_id'] ?? 0);
        check_admin_referer('wnq_delete_local_' . $location_id);
        self::requireCap();
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        if ($location_id) \WNQ\Services\LocalSEOEngine::deleteLocation($location_id, $client_id);
        wp_redirect(admin_url('admin.php?page=wnq-seo-spider&tab=local&client_id=' . urlencode($client_id) . '&deleted=1'));
        exit;
    }

    // ── Spider Sitemap Export ────────────────────────────────────────────────

    public static function handleSpiderSitemap(): void
    {
        check_admin_referer('wnq_spider_sitemap');
        self::requireCap();
        $session_id = (int)($_GET['session_id'] ?? 0);
        if (!$session_id) wp_die('Invalid session ID');
        $xml = \WNQ\Services\CrawlEngine::generateSitemap($session_id);
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="sitemap-session-' . $session_id . '.xml"');
        echo $xml;
        exit;
    }

    // ── Schema Migration (creates blog tables for existing installs) ─────────

    private static function maybeCreateBlogTables(): void
    {
        $current = get_option('wnq_blog_schema_ver', '0');

        if ($current === '3') {
            return; // already up to date
        }

        if (class_exists('WNQ\\Models\\BlogScheduler')) {
            \WNQ\Models\BlogScheduler::createTables();

            global $wpdb;

            // v1 → v2: add agent_key_id to existing installations
            if (in_array($current, ['1'], true)) {
                $col = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->prefix}wnq_blog_schedule LIKE 'agent_key_id'");
                if (!$col) {
                    $wpdb->query("ALTER TABLE {$wpdb->prefix}wnq_blog_schedule ADD COLUMN agent_key_id bigint(20) DEFAULT NULL AFTER client_id");
                    $wpdb->query("ALTER TABLE {$wpdb->prefix}wnq_blog_schedule ADD INDEX idx_agent_key_id (agent_key_id)");
                }
            }

            // v2 → v3: store featured image URL selected in the hub queue.
            if (in_array($current, ['1', '2'], true)) {
                $col = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->prefix}wnq_blog_schedule LIKE 'featured_image_url'");
                if (!$col) {
                    $wpdb->query("ALTER TABLE {$wpdb->prefix}wnq_blog_schedule ADD COLUMN featured_image_url varchar(1000) DEFAULT NULL AFTER focus_keyword");
                }
            }

            update_option('wnq_blog_schema_ver', '3');
        }
    }

    // ── Table Creation (called on activation) ──────────────────────────────

    public static function createTables(): void
    {
        if (file_exists(WNQ_PORTAL_PATH . 'includes/Models/SEOHub.php')) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEOHub.php';
            if (class_exists('WNQ\\Models\\SEOHub')) {
                \WNQ\Models\SEOHub::createTables();
            }
        }
        if (file_exists(WNQ_PORTAL_PATH . 'includes/Models/BlogScheduler.php')) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/BlogScheduler.php';
            if (class_exists('WNQ\\Models\\BlogScheduler')) {
                \WNQ\Models\BlogScheduler::createTables();
            }
        }

        // Spider & Analysis tables
        $service_tables = [
            'includes/Services/CrawlEngine.php'       => 'WNQ\\Services\\CrawlEngine',
            'includes/Services/PageSpeedEngine.php'   => 'WNQ\\Services\\PageSpeedEngine',
            'includes/Services/CompetitorTracker.php' => 'WNQ\\Services\\CompetitorTracker',
            'includes/Services/LocalSEOEngine.php'    => 'WNQ\\Services\\LocalSEOEngine',
        ];
        foreach ($service_tables as $file => $class) {
            $path = WNQ_PORTAL_PATH . $file;
            if (file_exists($path)) {
                require_once $path;
                if (class_exists($class)) {
                    $class::createTables();
                }
            }
        }
    }

    private static function requireCap(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied.');
        }
    }
}
