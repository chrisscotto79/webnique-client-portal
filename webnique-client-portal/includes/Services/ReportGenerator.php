<?php
/**
 * Report Generator
 *
 * Generates monthly SEO performance reports including:
 *  - Traffic & keyword movement summary
 *  - Top/underperforming pages
 *  - Audit findings summary
 *  - Content published this period
 *  - AI-generated executive summary
 *  - Exportable HTML output
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\SEOHub;
use WNQ\Models\Client;
use WNQ\Models\AnalyticsConfig;

final class ReportGenerator
{
    /**
     * Generate monthly report for a client
     */
    public static function generateMonthlyReport(string $client_id, string $month = ''): int|false
    {
        if (empty($month)) {
            $month = date('Y-m', strtotime('-1 month'));
        }
        $period_start = $month . '-01';
        $period_end   = date('Y-m-t', strtotime($period_start));

        $client  = Client::getByClientId($client_id);
        $profile = SEOHub::getProfile($client_id) ?? [];

        if (!$client) return false;

        // Gather all report data
        $report_data = [
            'client'         => self::getClientSummary($client, $profile),
            'keywords'       => self::getKeywordSummary($client_id),
            'site_health'    => self::getSiteHealthSummary($client_id),
            'audit_findings' => self::getAuditFindingsSummary($client_id, $period_start, $period_end),
            'blog_posts'     => self::getBlogPublishingSummary($client_id, $period_start, $period_end),
            'automation_log' => self::getAutomationLogSummary($client_id, $period_start, $period_end),
            'recommendations'=> self::generateRecommendations($client_id),
            'period'         => ['start' => $period_start, 'end' => $period_end, 'label' => date('F Y', strtotime($period_start))],
            'generated_at'   => current_time('mysql'),
        ];

        // AI-generated executive summary
        $summary_html = self::generateAISummary($client, $profile, $report_data);

        $report_id = SEOHub::createReport($client_id, 'monthly', $period_start, $period_end, $report_data, $summary_html);

        if ($report_id) {
            SEOHub::log('monthly_report_generated', [
                'client_id' => $client_id,
                'entity_id' => $report_id,
                'period'    => $period_start . ' to ' . $period_end,
            ]);
        }

        return $report_id;
    }

    /**
     * Generate reports for all active clients
     */
    public static function generateAllMonthlyReports(string $month = ''): array
    {
        $clients = Client::getByStatus('active');
        $results = ['generated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($clients as $client) {
            $client_id = $client['client_id'];
            $profile   = SEOHub::getProfile($client_id);
            if (!$profile) {
                $results['skipped']++;
                continue;
            }

            $id = self::generateMonthlyReport($client_id, $month);
            if ($id) {
                $results['generated']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Render report as exportable HTML
     */
    public static function renderReportHTML(int $report_id): string
    {
        $report = SEOHub::getReport($report_id);
        if (!$report) return '';

        $data    = $report['report_data'];
        $period  = $data['period'] ?? [];
        $client  = $data['client'] ?? [];
        $kws     = $data['keywords'] ?? [];
        $health  = $data['site_health'] ?? [];
        $audit   = $data['audit_findings'] ?? [];
        $blog_posts = $data['blog_posts'] ?? [];
        $recs    = $data['recommendations'] ?? [];

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SEO Report - <?php echo esc_html($client['name'] ?? ''); ?> - <?php echo esc_html($period['label'] ?? ''); ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #1f2937; background: #f9fafb; }
  .report-wrapper { max-width: 900px; margin: 0 auto; background: #fff; padding: 0; }
  .report-header { background: linear-gradient(135deg, #1e3a5f 0%, #0d539e 100%); color: white; padding: 40px; }
  .report-header h1 { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
  .report-header .meta { opacity: 0.8; font-size: 14px; }
  .report-logo { font-size: 12px; opacity: 0.6; margin-top: 20px; text-transform: uppercase; letter-spacing: 2px; }
  .section { padding: 32px 40px; border-bottom: 1px solid #e5e7eb; }
  .section h2 { font-size: 20px; font-weight: 700; color: #1e3a5f; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
  .section h2::before { content: ''; display: block; width: 4px; height: 20px; background: #0d539e; border-radius: 2px; }
  .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
  .metric-card { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 10px; padding: 20px; text-align: center; }
  .metric-card .value { font-size: 32px; font-weight: 800; color: #0d539e; }
  .metric-card .label { font-size: 12px; color: #6b7280; margin-top: 4px; text-transform: uppercase; letter-spacing: 1px; }
  .metric-card.danger { background: #fef2f2; border-color: #fca5a5; }
  .metric-card.danger .value { color: #dc2626; }
  .metric-card.success { background: #f0fdf4; border-color: #86efac; }
  .metric-card.success .value { color: #16a34a; }
  .table-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th { background: #f1f5f9; text-align: left; padding: 10px 12px; font-weight: 600; color: #374151; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
  td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #374151; }
  tr:hover td { background: #f9fafb; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
  .badge-up { background: #dcfce7; color: #16a34a; }
  .badge-down { background: #fef2f2; color: #dc2626; }
  .badge-warn { background: #fef9c3; color: #92400e; }
  .badge-crit { background: #fef2f2; color: #dc2626; }
  .ai-summary { background: #f0f9ff; border-left: 4px solid #0d539e; padding: 20px 24px; border-radius: 0 8px 8px 0; font-size: 15px; line-height: 1.7; }
  .rec-list { list-style: none; }
  .rec-list li { padding: 12px 0; border-bottom: 1px solid #f1f5f9; display: flex; gap: 12px; align-items: flex-start; }
  .rec-list li::before { content: '→'; color: #0d539e; font-weight: 700; flex-shrink: 0; }
  .report-footer { padding: 24px 40px; background: #f9fafb; text-align: center; font-size: 12px; color: #9ca3af; }
  @media print { body { background: white; } .section { page-break-inside: avoid; } }
</style>
</head>
<body>
<div class="report-wrapper">

  <div class="report-header">
    <h1>SEO Performance Report</h1>
    <div class="meta">
      <?php echo esc_html($client['name'] ?? ''); ?> &bull; <?php echo esc_html($period['label'] ?? ''); ?>
    </div>
    <div class="meta" style="margin-top:8px;">
      Reporting Period: <?php echo esc_html($period['start'] ?? ''); ?> – <?php echo esc_html($period['end'] ?? ''); ?>
    </div>
    <div class="report-logo">Powered by WebNique SEO OS</div>
  </div>

  <?php if (!empty($report['summary_html'])): ?>
  <div class="section">
    <h2>Executive Summary</h2>
    <div class="ai-summary"><?php echo $report['summary_html']; ?></div>
  </div>
  <?php endif; ?>

  <!-- Site Health -->
  <div class="section">
    <h2>Site Health Overview</h2>
    <div class="metric-grid">
      <div class="metric-card">
        <div class="value"><?php echo (int)($health['total_pages'] ?? 0); ?></div>
        <div class="label">Total Pages</div>
      </div>
      <div class="metric-card <?php echo ($health['missing_h1'] ?? 0) > 0 ? 'danger' : 'success'; ?>">
        <div class="value"><?php echo (int)($health['missing_h1'] ?? 0); ?></div>
        <div class="label">Missing H1</div>
      </div>
      <div class="metric-card <?php echo ($health['thin_content'] ?? 0) > 0 ? 'danger' : 'success'; ?>">
        <div class="value"><?php echo (int)($health['thin_content'] ?? 0); ?></div>
        <div class="label">Thin Content</div>
      </div>
      <div class="metric-card <?php echo ($health['missing_alt'] ?? 0) > 0 ? 'danger' : 'success'; ?>">
        <div class="value"><?php echo (int)($health['missing_alt'] ?? 0); ?></div>
        <div class="label">Missing Alt Text</div>
      </div>
      <div class="metric-card <?php echo ($health['no_schema'] ?? 0) > 0 ? 'danger' : 'success'; ?>">
        <div class="value"><?php echo (int)($health['no_schema'] ?? 0); ?></div>
        <div class="label">No Schema</div>
      </div>
      <div class="metric-card success">
        <div class="value"><?php echo (int)($health['health_score'] ?? 0); ?>%</div>
        <div class="label">Health Score</div>
      </div>
    </div>
  </div>

  <!-- Keyword Performance -->
  <?php if (!empty($kws['top_keywords'])): ?>
  <div class="section">
    <h2>Keyword Performance</h2>
    <div class="metric-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 24px;">
      <div class="metric-card">
        <div class="value"><?php echo (int)($kws['total'] ?? 0); ?></div>
        <div class="label">Tracked Keywords</div>
      </div>
      <div class="metric-card success">
        <div class="value"><?php echo (int)($kws['improving'] ?? 0); ?></div>
        <div class="label">Improving</div>
      </div>
      <div class="metric-card danger">
        <div class="value"><?php echo (int)($kws['declining'] ?? 0); ?></div>
        <div class="label">Declining</div>
      </div>
      <div class="metric-card">
        <div class="value"><?php echo (int)($kws['content_gaps'] ?? 0); ?></div>
        <div class="label">Content Gaps</div>
      </div>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Keyword</th><th>Cluster</th><th>Position</th><th>Change</th><th>Impressions</th><th>Clicks</th></tr></thead>
        <tbody>
          <?php foreach (array_slice($kws['top_keywords'], 0, 15) as $kw): ?>
          <?php
            $delta = $kw['delta'] ?? null;
            $badge = '';
            if ($delta !== null) {
              if ($delta < 0) $badge = '<span class="badge badge-up">▲ ' . abs($delta) . '</span>';
              elseif ($delta > 0) $badge = '<span class="badge badge-down">▼ ' . $delta . '</span>';
              else $badge = '<span class="badge badge-warn">—</span>';
            }
          ?>
          <tr>
            <td><?php echo esc_html($kw['keyword']); ?></td>
            <td><?php echo esc_html($kw['cluster_name'] ?? '—'); ?></td>
            <td><?php echo $kw['current_position'] !== null ? '#' . number_format((float)$kw['current_position'], 1) : '—'; ?></td>
            <td><?php echo $badge; ?></td>
            <td><?php echo number_format((int)($kw['impressions'] ?? 0)); ?></td>
            <td><?php echo number_format((int)($kw['clicks'] ?? 0)); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Audit Findings -->
  <?php if (!empty($audit)): ?>
  <div class="section">
    <h2>Technical Audit Summary</h2>
    <div class="metric-grid" style="grid-template-columns: repeat(3, 1fr);">
      <div class="metric-card danger">
        <div class="value"><?php echo (int)($audit['critical'] ?? 0); ?></div>
        <div class="label">Critical Issues</div>
      </div>
      <div class="metric-card <?php echo ($audit['warning'] ?? 0) > 0 ? 'danger' : 'success'; ?>">
        <div class="value"><?php echo (int)($audit['warning'] ?? 0); ?></div>
        <div class="label">Warnings</div>
      </div>
      <div class="metric-card">
        <div class="value"><?php echo (int)($audit['resolved_this_period'] ?? 0); ?></div>
        <div class="label">Resolved This Period</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Blog Scheduler -->
  <?php if (!empty($blog_posts)): ?>
  <div class="section">
    <h2>Blog Scheduler Activity</h2>
    <div class="metric-grid" style="grid-template-columns: repeat(4, 1fr);">
      <div class="metric-card">
        <div class="value"><?php echo (int)($blog_posts['published'] ?? 0); ?></div>
        <div class="label">Published Blogs</div>
      </div>
      <div class="metric-card success">
        <div class="value"><?php echo (int)($blog_posts['scheduled'] ?? 0); ?></div>
        <div class="label">Scheduled Posts</div>
      </div>
      <div class="metric-card">
        <div class="value"><?php echo (int)($blog_posts['pending'] ?? 0); ?></div>
        <div class="label">Pending Drafts</div>
      </div>
      <div class="metric-card">
        <div class="value"><?php echo (int)($blog_posts['failed'] ?? 0); ?></div>
        <div class="label">Failed Posts</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recommendations -->
  <?php if (!empty($recs)): ?>
  <div class="section">
    <h2>Recommended Next Steps</h2>
    <ul class="rec-list">
      <?php foreach ($recs as $rec): ?>
      <li><?php echo esc_html($rec); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="report-footer">
    Generated by WebNique SEO Operating System &bull; <?php echo date('F j, Y'); ?> &bull; Confidential
  </div>
</div>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private static function getClientSummary(array $client, array $profile): array
    {
        return [
            'name'      => $client['company'] ?? $client['name'],
            'website'   => $client['website'] ?? '',
            'services'  => $profile['primary_services'] ?? [],
            'locations' => $profile['service_locations'] ?? [],
        ];
    }

    private static function getKeywordSummary(string $client_id): array
    {
        $kws     = SEOHub::getKeywords($client_id);
        $total   = count($kws);
        $improving = 0;
        $declining = 0;
        $gaps      = 0;

        $enriched = [];
        foreach ($kws as $kw) {
            $delta = null;
            if ($kw['current_position'] !== null && $kw['prev_position'] !== null) {
                $delta = (float)$kw['current_position'] - (float)$kw['prev_position'];
                if ($delta < 0) $improving++;
                elseif ($delta > 0) $declining++;
            }
            if ($kw['content_gap']) $gaps++;
            $enriched[] = array_merge($kw, ['delta' => $delta]);
        }

        usort($enriched, fn($a, $b) => (int)$b['impressions'] - (int)$a['impressions']);

        return [
            'total'        => $total,
            'improving'    => $improving,
            'declining'    => $declining,
            'content_gaps' => $gaps,
            'top_keywords' => $enriched,
        ];
    }

    private static function getSiteHealthSummary(string $client_id): array
    {
        $stats = SEOHub::getSiteStats($client_id);
        $stats['health_score'] = AuditEngine::getHealthScore($client_id);
        return $stats;
    }

    private static function getAuditFindingsSummary(string $client_id, string $start, string $end): array
    {
        $severity = AuditEngine::getSeveritySummary($client_id);

        global $wpdb;
        $resolved = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wnq_seo_audit_findings
             WHERE client_id=%s AND status='resolved' AND resolved_at BETWEEN %s AND %s",
            $client_id, $start . ' 00:00:00', $end . ' 23:59:59'
        ));

        return array_merge($severity, ['resolved_this_period' => $resolved]);
    }

    private static function getBlogPublishingSummary(string $client_id, string $start, string $end): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'wnq_blog_schedule';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) as cnt FROM $t
                 WHERE client_id=%s AND updated_at BETWEEN %s AND %s
                 GROUP BY status",
                $client_id,
                $start . ' 00:00:00',
                $end . ' 23:59:59'
            ),
            ARRAY_A
        ) ?: [];

        $summary = ['published' => 0, 'scheduled' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $status = (string)($r['status'] ?? '');
            if ($status === 'published') {
                $summary['published'] = (int)$r['cnt'];
            } elseif ($status === 'failed') {
                $summary['failed'] = (int)$r['cnt'];
            } elseif ($status === 'pending') {
                $summary['pending'] = (int)$r['cnt'];
            }
        }
        $summary['scheduled'] = array_sum($summary);
        return $summary;
    }

    private static function getAutomationLogSummary(string $client_id, string $start, string $end): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT action_type, COUNT(*) as count, status FROM {$wpdb->prefix}wnq_seo_automation_log
             WHERE client_id=%s AND created_at BETWEEN %s AND %s
             GROUP BY action_type, status ORDER BY count DESC LIMIT 20",
            $client_id, $start . ' 00:00:00', $end . ' 23:59:59'
        ), ARRAY_A) ?: [];
    }

    private static function generateRecommendations(string $client_id): array
    {
        $recs  = [];
        $stats = SEOHub::getSiteStats($client_id);
        $audit = AuditEngine::getSeveritySummary($client_id);
        $gaps  = SEOHub::getKeywords($client_id, ['content_gap' => 1, 'limit' => 5]);

        if ($stats['missing_h1'] > 0) {
            $recs[] = "Fix {$stats['missing_h1']} pages missing H1 tags — this is a critical ranking factor.";
        }
        if ($stats['thin_content'] > 0) {
            $recs[] = "Expand {$stats['thin_content']} thin content pages to 600+ words with target keywords.";
        }
        if ($stats['no_schema'] > 0) {
            $recs[] = "Add structured data (JSON-LD schema) to {$stats['no_schema']} pages to improve rich snippet eligibility.";
        }
        if (!empty($gaps)) {
            $kw_list = implode(', ', array_column(array_slice($gaps, 0, 3), 'keyword'));
            $recs[] = "Create new content targeting content gap keywords: $kw_list.";
        }
        if ($stats['missing_alt'] > 0) {
            $recs[] = "Add alt text to images on {$stats['missing_alt']} pages for accessibility and image SEO.";
        }
        if ($audit['critical'] > 0) {
            $recs[] = "Resolve {$audit['critical']} critical technical issues identified in the nightly audit.";
        }
        if (empty($recs)) {
            $recs[] = "Site health is strong. Focus on expanding keyword cluster coverage and publishing fresh content.";
        }

        return $recs;
    }

    private static function generateAISummary(array $client, array $profile, array $data): string
    {
        $kws   = $data['keywords'] ?? [];
        $audit = $data['audit_findings'] ?? [];
        $blog_posts = $data['blog_posts'] ?? [];
        $period  = $data['period'] ?? [];

        $improving_kws = [];
        $declining_kws = [];
        foreach (($kws['top_keywords'] ?? []) as $kw) {
            if (($kw['delta'] ?? null) !== null) {
                if ($kw['delta'] < 0) $improving_kws[] = $kw['keyword'];
                elseif ($kw['delta'] > 0) $declining_kws[] = $kw['keyword'];
            }
        }

        $vars = [
            'client_name'        => $client['company'] ?? $client['name'] ?? '',
            'period'             => $period['label'] ?? '',
            'traffic_change'     => 'Data pending GSC sync',
            'improving_keywords' => implode(', ', array_slice($improving_kws, 0, 5)) ?: 'None tracked',
            'declining_keywords' => implode(', ', array_slice($declining_kws, 0, 5)) ?: 'None tracked',
            'content_published'  => ($blog_posts['published'] ?? 0) . ' blog posts published',
            'issues_fixed'       => ($audit['resolved_this_period'] ?? 0) . ' technical issues',
            'open_issues'        => (($audit['critical'] ?? 0) + ($audit['warning'] ?? 0)) . ' open findings',
            'tone'               => $profile['content_tone'] ?? 'professional',
            'services'           => implode(', ', (array)($profile['primary_services'] ?? [])),
        ];

        $result = AIEngine::generate('report_summary', $vars, $client['client_id'] ?? '');

        if ($result['success'] && !empty($result['content'])) {
            return nl2br(esc_html($result['content']));
        }

        // Fallback non-AI summary
        return '<p>This monthly SEO report summarizes the performance, technical health, and automation activity for ' .
               esc_html($client['company'] ?? $client['name'] ?? '') . ' during ' .
               esc_html($period['label'] ?? '') . '.</p>';
    }
}
