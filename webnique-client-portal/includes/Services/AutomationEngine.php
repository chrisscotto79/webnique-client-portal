<?php
/**
 * Automation Engine
 *
 * Orchestrates SEO automation workflows:
 *  - Content gap analysis → content job creation
 *  - Meta tag optimization suggestions
 *  - Internal linking recommendations
 *  - Schema generation
 *  - Nightly audit triggers
 *  - Monthly report generation
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\SEOHub;
use WNQ\Models\Client;

final class AutomationEngine
{
    // ── Phase 1 Automation Workflows ───────────────────────────────────────

    /**
     * Run all pending automation for a specific client
     */
    public static function runClientAutomation(string $client_id): array
    {
        $results = [];

        $results['content_gaps'] = self::analyzeContentGaps($client_id);
        $results['meta_jobs']    = self::queueMetaTagJobs($client_id);
        $results['schema_jobs']  = self::queueSchemaJobs($client_id);

        SEOHub::log('run_client_automation', [
            'client_id' => $client_id,
            'results'   => $results,
        ], 'success', 'manual');

        return $results;
    }

    /**
     * Process AI content job queue (called by cron or manually)
     */
    public static function processContentQueue(int $batch_size = 5): array
    {
        $jobs    = SEOHub::getPendingJobs($batch_size);
        $results = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];

        foreach ($jobs as $job) {
            $results['processed']++;

            // Mark as running
            SEOHub::updateContentJob($job['id'], ['status' => 'running']);

            $result = self::executeContentJob($job);

            if ($result['success']) {
                SEOHub::updateContentJob($job['id'], [
                    'status'         => 'completed',
                    'output_content' => $result['content'],
                    'tokens_used'    => $result['tokens_used'] ?? 0,
                    'ai_model'       => $result['model'] ?? '',
                ]);
                $results['succeeded']++;
                SEOHub::log('content_job_completed', ['client_id' => $job['client_id'], 'entity_id' => $job['id'], 'job_type' => $job['job_type']]);
            } else {
                SEOHub::updateContentJob($job['id'], [
                    'status'        => 'failed',
                    'error_message' => $result['error'] ?? 'Unknown error',
                ]);
                $results['failed']++;
                SEOHub::log('content_job_failed', ['client_id' => $job['client_id'], 'entity_id' => $job['id'], 'error' => $result['error'] ?? ''], 'failed');
            }
        }

        return $results;
    }

    /**
     * Analyze content gaps for a client and queue blog outline jobs
     */
    public static function analyzeContentGaps(string $client_id): array
    {
        $gap_count = SEOHub::markContentGaps($client_id);
        if ($gap_count === 0) {
            return ['gaps_found' => 0, 'jobs_created' => 0];
        }

        $profile  = SEOHub::getProfile($client_id) ?? [];
        $gap_kws  = SEOHub::getKeywords($client_id, ['content_gap' => 1, 'limit' => 10]);

        if (empty($gap_kws)) {
            return ['gaps_found' => $gap_count, 'jobs_created' => 0];
        }

        $client = Client::getByClientId($client_id) ?? [];
        $jobs_created = 0;

        foreach ($gap_kws as $kw) {
            // Check for existing pending/completed job for this keyword
            $existing_jobs = SEOHub::getContentJobs($client_id, ['job_type' => 'blog_outline', 'status' => 'pending']);
            $already_queued = false;
            foreach ($existing_jobs as $ej) {
                if ($ej['target_keyword'] === $kw['keyword']) {
                    $already_queued = true;
                    break;
                }
            }
            if ($already_queued) continue;

            SEOHub::createContentJob([
                'client_id'      => $client_id,
                'job_type'       => 'blog_outline',
                'target_keyword' => $kw['keyword'],
                'prompt_key'     => 'blog_outline',
                'input_data'     => [
                    'business_name' => $client['company'] ?? $client['name'] ?? '',
                    'services'      => implode(', ', (array)($profile['primary_services'] ?? [])),
                    'location'      => implode(', ', (array)($profile['service_locations'] ?? [])),
                    'keyword'       => $kw['keyword'],
                    'tone'          => $profile['content_tone'] ?? 'professional',
                    'cluster'       => $kw['cluster_name'] ?? '',
                ],
            ]);
            $jobs_created++;
        }

        SEOHub::log('content_gap_analysis', [
            'client_id'     => $client_id,
            'gaps_found'    => $gap_count,
            'jobs_created'  => $jobs_created,
        ]);

        return ['gaps_found' => $gap_count, 'jobs_created' => $jobs_created];
    }

    /**
     * Queue meta tag optimization jobs for pages missing/weak meta
     */
    public static function queueMetaTagJobs(string $client_id, int $limit = 5): array
    {
        $profile = SEOHub::getProfile($client_id) ?? [];
        $client  = Client::getByClientId($client_id) ?? [];

        // Find pages with short/missing meta descriptions
        global $wpdb;
        $pages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wnq_seo_site_data
                 WHERE client_id=%s
                 AND (meta_description='' OR meta_description IS NULL OR CHAR_LENGTH(meta_description) < 100)
                 AND post_status='publish'
                 ORDER BY word_count DESC LIMIT %d",
                $client_id, $limit
            ),
            ARRAY_A
        ) ?: [];

        $jobs_created = 0;
        foreach ($pages as $page) {
            SEOHub::createContentJob([
                'client_id'      => $client_id,
                'job_type'       => 'meta_tags',
                'target_keyword' => $page['focus_keyword'] ?? '',
                'target_url'     => $page['page_url'],
                'prompt_key'     => 'meta_tags',
                'input_data'     => [
                    'business_name' => $client['company'] ?? $client['name'] ?? '',
                    'page_type'     => $page['page_type'],
                    'topic'         => $page['title'] ?? '',
                    'keyword'       => $page['focus_keyword'] ?? $page['title'] ?? '',
                    'location'      => implode(', ', (array)($profile['service_locations'] ?? [])),
                    'current_title' => $page['title'] ?? '',
                    'current_meta'  => $page['meta_description'] ?? '',
                ],
            ]);
            $jobs_created++;
        }

        return ['pages_analyzed' => count($pages), 'jobs_created' => $jobs_created];
    }

    /**
     * Queue schema generation jobs for pages lacking structured data
     */
    public static function queueSchemaJobs(string $client_id, int $limit = 5): array
    {
        $profile = SEOHub::getProfile($client_id) ?? [];
        $client  = Client::getByClientId($client_id) ?? [];

        $pages = SEOHub::getSiteData($client_id, ['has_issue' => 'no_schema', 'limit' => $limit]);
        $jobs_created = 0;

        foreach ($pages as $page) {
            $schema_type = self::inferSchemaType($page);
            SEOHub::createContentJob([
                'client_id'      => $client_id,
                'job_type'       => 'schema',
                'target_url'     => $page['page_url'],
                'prompt_key'     => 'schema_json',
                'input_data'     => [
                    'business_name' => $client['company'] ?? $client['name'] ?? '',
                    'schema_type'   => $schema_type,
                    'page_url'      => $page['page_url'],
                    'services'      => implode(', ', (array)($profile['primary_services'] ?? [])),
                    'location'      => implode(', ', (array)($profile['service_locations'] ?? [])),
                    'phone'         => $client['phone'] ?? '',
                    'extra_info'    => $page['title'] ?? '',
                ],
            ]);
            $jobs_created++;
        }

        return ['pages_analyzed' => count($pages), 'jobs_created' => $jobs_created];
    }

    /**
     * Queue internal link suggestion jobs
     */
    public static function queueInternalLinkJobs(string $client_id, int $limit = 5): array
    {
        $pages_needing_links = SEOHub::getSiteData($client_id, ['has_issue' => 'no_internal', 'limit' => $limit]);
        $all_pages = SEOHub::getSiteData($client_id, ['limit' => 50]);

        $available = array_map(fn($p) => ($p['title'] ?? '') . ' - ' . $p['page_url'], $all_pages);
        $client  = Client::getByClientId($client_id) ?? [];
        $jobs_created = 0;

        foreach ($pages_needing_links as $page) {
            SEOHub::createContentJob([
                'client_id'   => $client_id,
                'job_type'    => 'internal_links',
                'target_url'  => $page['page_url'],
                'prompt_key'  => 'internal_links',
                'input_data'  => [
                    'page_title'      => $page['title'] ?? '',
                    'content_summary' => substr($page['meta_description'] ?? '', 0, 300),
                    'available_pages' => implode("\n", array_slice($available, 0, 20)),
                ],
            ]);
            $jobs_created++;
        }

        return ['pages_analyzed' => count($pages_needing_links), 'jobs_created' => $jobs_created];
    }

    /**
     * Execute a specific content job via AI
     */
    public static function executeContentJob(array $job): array
    {
        $input = is_string($job['input_data']) ? (json_decode($job['input_data'], true) ?? []) : ($job['input_data'] ?? []);
        $template_key = $job['prompt_key'] ?: $job['job_type'];

        return AIEngine::generate($template_key, $input, $job['client_id']);
    }

    /**
     * Run automation across all active clients (called by nightly cron)
     */
    public static function runNightlyAutomation(): array
    {
        $clients = Client::getByStatus('active');
        $summary = ['clients_processed' => 0, 'total_gaps' => 0, 'total_jobs' => 0];

        foreach ($clients as $client) {
            $client_id = $client['client_id'];
            if (empty($client_id)) continue;

            $profile = SEOHub::getProfile($client_id);
            if (!$profile) continue; // Skip clients not set up in SEO OS

            try {
                $gaps = self::analyzeContentGaps($client_id);
                $summary['total_gaps'] += $gaps['gaps_found'] ?? 0;
                $summary['total_jobs'] += $gaps['jobs_created'] ?? 0;
                $summary['clients_processed']++;
            } catch (\Throwable $e) {
                SEOHub::log('nightly_automation_error', ['client_id' => $client_id, 'error' => $e->getMessage()], 'failed');
            }
        }

        // Process a batch of queued AI jobs
        $queue_results = self::processContentQueue(10);
        $summary['queue_results'] = $queue_results;

        SEOHub::log('nightly_automation_complete', $summary, 'success');
        return $summary;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function inferSchemaType(array $page): string
    {
        $type = strtolower($page['page_type'] ?? 'page');
        $title = strtolower($page['title'] ?? '');

        if ($type === 'product') return 'Product';
        if (str_contains($title, 'contact') || str_contains($title, 'about')) return 'LocalBusiness';
        if (str_contains($title, 'faq') || str_contains($title, 'question')) return 'FAQPage';
        if ($type === 'post') return 'BlogPosting';
        if (str_contains($title, 'service') || str_contains($title, 'pricing')) return 'Service';
        return 'WebPage';
    }

    /**
     * Get automation statistics for dashboard
     */
    public static function getStats(string $client_id = ''): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_seo_content_jobs';

        if ($client_id) {
            $where = $wpdb->prepare("WHERE client_id=%s", $client_id);
        } else {
            $where = "WHERE 1=1";
        }

        return [
            'pending'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t $where AND status='pending'"),
            'running'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t $where AND status='running'"),
            'completed' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t $where AND status='completed'"),
            'approved'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t $where AND approved=1"),
            'failed'    => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t $where AND status='failed'"),
            'awaiting_approval' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $t $where AND status='completed' AND approved=0"),
        ];
    }
}
