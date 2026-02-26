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
            // Register get_job ajax handler
            add_action('wp_ajax_wnq_seohub_get_job', [self::class, 'ajaxGetJob']);
        }

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
            // Services
            'includes/Services/AIEngine.php',
            'includes/Services/AutomationEngine.php',
            'includes/Services/AuditEngine.php',
            'includes/Services/ReportGenerator.php',
            // Controllers & Core
            'includes/Controllers/SEOAgentController.php',
            'includes/Core/CronScheduler.php',
            // Admin UI (must come last — depends on everything above)
            'admin/SEOHubAdmin.php',
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
            'includes/Services/AIEngine.php'          => 'WNQ\\Services\\AIEngine',
            'includes/Services/AutomationEngine.php'  => 'WNQ\\Services\\AutomationEngine',
            'includes/Services/AuditEngine.php'       => 'WNQ\\Services\\AuditEngine',
            'includes/Services/ReportGenerator.php'   => 'WNQ\\Services\\ReportGenerator',
            'includes/Controllers/SEOAgentController.php' => 'WNQ\\Controllers\\SEOAgentController',
            'includes/Core/CronScheduler.php'         => 'WNQ\\Core\\CronScheduler',
            'admin/SEOHubAdmin.php'                   => 'WNQ\\Admin\\SEOHubAdmin',
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

        // Create AI job manually
        add_action('admin_post_wnq_create_ai_job', [self::class, 'handleCreateAIJob']);

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
    }

    // ── AJAX: Get Job Content ───────────────────────────────────────────────

    public static function ajaxGetJob(): void
    {
        check_ajax_referer('wnq_seohub_nonce', 'nonce');
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
        }

        $job_id = (int)($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => 'Invalid job ID']);
        }

        global $wpdb;
        $job = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}wnq_seo_content_jobs WHERE id=%d", $job_id),
            ARRAY_A
        );

        if (!$job) {
            wp_send_json_error(['message' => 'Job not found']);
        }

        wp_send_json_success($job);
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
            'auto_approve'      => !empty($_POST['auto_approve']) ? 1 : 0,
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

    public static function handleCreateAIJob(): void
    {
        check_admin_referer('wnq_create_ai_job');
        self::requireCap();

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $job_type  = sanitize_text_field($_POST['job_type'] ?? '');

        if (empty($client_id) || empty($job_type)) {
            wp_die('Missing required fields');
        }

        $client  = \WNQ\Models\Client::getByClientId($client_id) ?? [];
        $profile = \WNQ\Models\SEOHub::getProfile($client_id) ?? [];

        \WNQ\Models\SEOHub::createContentJob([
            'client_id'      => $client_id,
            'job_type'       => $job_type,
            'target_keyword' => sanitize_text_field($_POST['target_keyword'] ?? ''),
            'target_url'     => esc_url_raw($_POST['target_url'] ?? ''),
            'prompt_key'     => $job_type,
            'input_data'     => [
                'business_name' => $client['company'] ?? $client['name'] ?? '',
                'services'      => implode(', ', (array)($profile['primary_services'] ?? [])),
                'location'      => implode(', ', (array)($profile['service_locations'] ?? [])),
                'keyword'       => sanitize_text_field($_POST['target_keyword'] ?? ''),
                'tone'          => $profile['content_tone'] ?? 'professional',
            ],
        ]);

        wp_redirect(admin_url('admin.php?page=wnq-seo-hub-content&client_id=' . urlencode($client_id) . '&created=1'));
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
                    'together_api_key', 'together_model', 'max_tokens', 'temperature', 'cache_ttl'];

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

    // ── Table Creation (called on activation) ──────────────────────────────

    public static function createTables(): void
    {
        if (file_exists(WNQ_PORTAL_PATH . 'includes/Models/SEOHub.php')) {
            require_once WNQ_PORTAL_PATH . 'includes/Models/SEOHub.php';
            if (class_exists('WNQ\\Models\\SEOHub')) {
                \WNQ\Models\SEOHub::createTables();
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
