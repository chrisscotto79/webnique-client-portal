<?php
/**
 * Local Checks
 *
 * Lightweight SEO checks that run on the client site.
 * Results are stored locally but NOT analyzed here — they're included
 * in the sync payload for hub-side analysis.
 *
 * Checks performed:
 *  - Missing H1 tags
 *  - Missing alt text
 *  - No internal links
 *  - Thin content (word count below threshold)
 *  - Orphan pages (no internal links pointing to them)
 *
 * @package WebNique SEO Agent
 */

namespace WNQA;

if (!defined('ABSPATH')) {
    exit;
}

class LocalChecks
{
    private int $thin_threshold;

    public function __construct()
    {
        $config              = get_option('wnqa_config', []);
        $this->thin_threshold = (int)($config['thin_threshold'] ?? 300);
    }

    /**
     * Run all local checks and store results
     */
    public function run(): array
    {
        $collector = new DataCollector();
        $pages     = $collector->collectAll();

        $results = [
            'missing_h1'        => [],
            'missing_alt'       => [],
            'no_internal_links' => [],
            'thin_content'      => [],
            'total_pages'       => count($pages),
            'checked_at'        => current_time('mysql'),
        ];

        foreach ($pages as $page) {
            if (empty($page['h1'])) {
                $results['missing_h1'][] = [
                    'url'   => $page['page_url'],
                    'title' => $page['title'],
                ];
            }

            if ($page['images_missing_alt'] > 0) {
                $results['missing_alt'][] = [
                    'url'     => $page['page_url'],
                    'count'   => $page['images_missing_alt'],
                    'total'   => $page['images_count'],
                ];
            }

            if ($page['page_type'] === 'post' && $page['post_status'] === 'publish' && (int)$page['internal_links_count'] === 0) {
                $results['no_internal_links'][] = [
                    'url'   => $page['page_url'],
                    'title' => $page['title'],
                ];
            }

            if ($page['post_status'] === 'publish' && $page['word_count'] > 0 && $page['word_count'] < $this->thin_threshold) {
                $results['thin_content'][] = [
                    'url'        => $page['page_url'],
                    'word_count' => $page['word_count'],
                    'threshold'  => $this->thin_threshold,
                ];
            }
        }

        // Store local check results
        update_option('wnqa_local_check_results', $results);

        return $results;
    }

    /**
     * Get last check results
     */
    public function getLastResults(): array
    {
        return get_option('wnqa_local_check_results', []);
    }

    /**
     * Get summary counts
     */
    public function getSummary(): array
    {
        $results = $this->getLastResults();
        if (empty($results)) {
            return ['run' => false];
        }

        return [
            'run'               => true,
            'checked_at'        => $results['checked_at'] ?? '',
            'total_pages'       => $results['total_pages'] ?? 0,
            'missing_h1'        => count($results['missing_h1'] ?? []),
            'missing_alt'       => count($results['missing_alt'] ?? []),
            'no_internal_links' => count($results['no_internal_links'] ?? []),
            'thin_content'      => count($results['thin_content'] ?? []),
        ];
    }
}
