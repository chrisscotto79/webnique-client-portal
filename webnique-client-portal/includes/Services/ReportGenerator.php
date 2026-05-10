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
use WNQ\API\GoogleAnalytics;

final class ReportGenerator
{
    /**
     * Generate monthly report for a client
     */
    public static function generateMonthlyReport(string $client_id, string $month = ''): int|false
    {
        $period = self::resolveMonthlyPeriod($month);
        $period_start = $period['start'];
        $period_end = $period['end'];

        $client  = Client::getByClientId($client_id);
        $profile = SEOHub::getProfile($client_id) ?? [];

        if (!$client) return false;

        // Gather all report data
        $report_data = [
            'client'         => self::getClientSummary($client, $profile),
            'keywords'       => self::getKeywordSummary($client_id),
            'site_health'    => self::getSiteHealthSummary($client_id),
            'analytics'      => self::getAnalyticsSummary($client_id, $period_start, $period_end),
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
    public static function generateAllMonthlyReports(string $month = '', bool $send_email = true): array
    {
        $clients = Client::getByStatus('active');
        $period = self::resolveMonthlyPeriod($month);
        $results = [
            'generated' => 0,
            'emailed' => 0,
            'email_failed' => 0,
            'email_skipped' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($clients as $client) {
            $client_id = $client['client_id'];
            $profile   = SEOHub::getProfile($client_id);
            if (!$profile) {
                $results['skipped']++;
                continue;
            }

            $existing = SEOHub::getReportForPeriod($client_id, 'monthly', $period['start'], $period['end']);
            if ($existing && ($existing['status'] ?? '') === 'sent') {
                $results['email_skipped']++;
                continue;
            }

            $id = $existing ? (int)$existing['id'] : self::generateMonthlyReport($client_id, $month);
            if ($id) {
                if (!$existing) {
                    $results['generated']++;
                }
                if ($send_email) {
                    $email_result = self::sendMonthlyReportEmail($id);
                    if ($email_result === 'sent') {
                        $results['emailed']++;
                    } elseif ($email_result === 'skipped') {
                        $results['email_skipped']++;
                    } else {
                        $results['email_failed']++;
                    }
                }
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Email a generated monthly report to the client's reporting contact.
     *
     * @return string sent|failed|skipped
     */
    public static function sendMonthlyReportEmail(int $report_id): string
    {
        $report = SEOHub::getReport($report_id);
        if (!$report) {
            return 'failed';
        }

        if (($report['status'] ?? '') === 'sent') {
            return 'skipped';
        }

        $client = Client::getByClientId((string)$report['client_id']);
        if (!$client) {
            SEOHub::log('monthly_report_email_failed', [
                'client_id' => $report['client_id'],
                'entity_id' => $report_id,
                'reason' => 'Client record not found',
            ], 'failed');
            return 'failed';
        }

        $recipients = self::getReportRecipients($client);
        if (empty($recipients)) {
            SEOHub::log('monthly_report_email_skipped', [
                'client_id' => $report['client_id'],
                'entity_id' => $report_id,
                'reason' => 'No billing or client email on file',
            ], 'skipped');
            return 'skipped';
        }

        $period = $report['report_data']['period']['label'] ?? date('F Y', strtotime((string)$report['period_start']));
        $client_name = $client['company'] ?: ($client['name'] ?? 'Client');
        $subject = sprintf('%s Monthly Analytics Report - %s', $period, $client_name);
        $body = self::renderReportHTML($report_id);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($recipients, $subject, $body, $headers);
        SEOHub::log($sent ? 'monthly_report_emailed' : 'monthly_report_email_failed', [
            'client_id' => $report['client_id'],
            'entity_id' => $report_id,
            'entity_type' => 'report',
            'recipients' => $recipients,
            'period' => $period,
        ], $sent ? 'success' : 'failed');

        if ($sent) {
            SEOHub::updateReportStatus($report_id, 'sent');
            return 'sent';
        }

        return 'failed';
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
        $analytics = $data['analytics'] ?? [];
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
  .note { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 14px 16px; font-size: 14px; line-height: 1.6; }
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

  <!-- Analytics -->
  <div class="section">
    <h2>Analytics Overview</h2>
    <?php if (!empty($analytics['configured'])): ?>
      <?php
        $overview = $analytics['overview'] ?? [];
        $key_events = $analytics['key_events'] ?? [];
        $traffic_sources = $analytics['traffic_sources'] ?? [];
        $top_pages = $analytics['top_pages'] ?? [];
        $visitor_trends = $analytics['visitors_over_time'] ?? [];
      ?>
      <div class="metric-grid">
        <div class="metric-card">
          <div class="value"><?php echo number_format((int)($overview['total_users'] ?? 0)); ?></div>
          <div class="label">Visitors</div>
        </div>
        <div class="metric-card">
          <div class="value"><?php echo number_format((int)($overview['sessions'] ?? 0)); ?></div>
          <div class="label">Sessions</div>
        </div>
        <div class="metric-card">
          <div class="value"><?php echo number_format((int)($overview['page_views'] ?? 0)); ?></div>
          <div class="label">Page Views</div>
        </div>
        <div class="metric-card">
          <div class="value"><?php echo number_format((float)($overview['bounce_rate'] ?? 0), 1); ?>%</div>
          <div class="label">Bounce Rate</div>
        </div>
        <div class="metric-card success">
          <div class="value"><?php echo number_format((int)($analytics['total_key_events'] ?? 0)); ?></div>
          <div class="label">Key Events</div>
        </div>
      </div>

      <?php if (!empty($traffic_sources)): ?>
      <h3 style="font-size:16px;color:#374151;margin:6px 0 12px;">Traffic Sources</h3>
      <div class="table-wrap" style="margin-bottom:24px;">
        <table>
          <thead><tr><th>Channel</th><th>Sessions</th><th>Users</th><th>Share</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($traffic_sources, 0, 8) as $source): ?>
            <tr>
              <td><?php echo esc_html($source['channel'] ?? 'Unknown'); ?></td>
              <td><?php echo number_format((int)($source['sessions'] ?? 0)); ?></td>
              <td><?php echo number_format((int)($source['users'] ?? 0)); ?></td>
              <td><?php echo number_format((float)($source['percentage'] ?? 0), 1); ?>%</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if (!empty($visitor_trends)): ?>
      <h3 style="font-size:16px;color:#374151;margin:6px 0 12px;">Visitors Over Time</h3>
      <div class="table-wrap" style="margin-bottom:24px;">
        <table>
          <thead><tr><th>Date</th><th>Visitors</th><th>Sessions</th><th>Page Views</th></tr></thead>
          <tbody>
            <?php foreach ($visitor_trends as $day): ?>
            <tr>
              <td><?php echo esc_html($day['date'] ?? ''); ?></td>
              <td><?php echo number_format((int)($day['users'] ?? 0)); ?></td>
              <td><?php echo number_format((int)($day['sessions'] ?? 0)); ?></td>
              <td><?php echo number_format((int)($day['page_views'] ?? 0)); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if (!empty($top_pages)): ?>
      <h3 style="font-size:16px;color:#374151;margin:6px 0 12px;">Top Pages</h3>
      <div class="table-wrap" style="margin-bottom:24px;">
        <table>
          <thead><tr><th>Page</th><th>Views</th><th>Avg Time</th><th>Bounce</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($top_pages, 0, 8) as $page): ?>
            <tr>
              <td>
                <strong><?php echo esc_html($page['title'] ?? 'Untitled'); ?></strong><br>
                <span style="font-size:12px;color:#6b7280;"><?php echo esc_html($page['path'] ?? ''); ?></span>
              </td>
              <td><?php echo number_format((int)($page['views'] ?? 0)); ?></td>
              <td><?php echo esc_html(self::formatDuration((float)($page['avg_time'] ?? 0))); ?></td>
              <td><?php echo number_format((float)($page['bounce_rate'] ?? 0), 1); ?>%</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if (!empty($key_events)): ?>
      <h3 style="font-size:16px;color:#374151;margin:6px 0 12px;">Key Events</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Event</th><th>Count</th></tr></thead>
          <tbody>
            <?php foreach ($key_events as $event): ?>
            <tr>
              <td><?php echo esc_html($event['label'] ?? $event['event_name'] ?? 'Event'); ?></td>
              <td><?php echo number_format((int)($event['count'] ?? 0)); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="note">
        Analytics data was not available for this client during report generation.
        <?php if (!empty($analytics['error'])): ?>
          <?php echo esc_html($analytics['error']); ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

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

    private static function resolveMonthlyPeriod(string $month = ''): array
    {
        if (empty($month)) {
            $month = date('Y-m', strtotime('-1 month'));
        }

        $period_start = $month . '-01';
        return [
            'start' => $period_start,
            'end' => date('Y-m-t', strtotime($period_start)),
            'label' => date('F Y', strtotime($period_start)),
        ];
    }

    private static function getAnalyticsSummary(string $client_id, string $start, string $end): array
    {
        $config = AnalyticsConfig::getClientConfig($client_id);
        $credentials = AnalyticsConfig::getCredentials();

        if (!$config || empty($config['ga4_property_id'])) {
            return [
                'configured' => false,
                'error' => 'GA4 is not configured for this client.',
            ];
        }

        if (!$credentials) {
            return [
                'configured' => false,
                'error' => 'Google Analytics service account credentials are not configured.',
            ];
        }

        try {
            $analytics = new GoogleAnalytics($client_id);
            $key_events = self::getAnalyticsKeyEvents($analytics, $start, $end);

            return [
                'configured' => true,
                'ga4_property_id' => $config['ga4_property_id'],
                'overview' => $analytics->getOverviewStats($start, $end),
                'visitors_over_time' => $analytics->getVisitorTrends($start, $end),
                'traffic_sources' => $analytics->getTrafficSources($start, $end),
                'top_pages' => $analytics->getTopPages($start, $end, 10),
                'key_events' => $key_events,
                'total_key_events' => array_sum(array_map(fn($event) => (int)($event['count'] ?? 0), $key_events)),
            ];
        } catch (\Throwable $e) {
            SEOHub::log('monthly_report_analytics_failed', [
                'client_id' => $client_id,
                'error' => $e->getMessage(),
            ], 'failed');

            return [
                'configured' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function getAnalyticsKeyEvents(GoogleAnalytics $analytics, string $start, string $end): array
    {
        $events = [
            'phone_click' => 'Phone Clicks',
            'email_click' => 'Email Clicks',
            'social_click' => 'Social Clicks',
            'contact_page_visit' => 'Contact Page Visits',
            'generate_lead' => 'Form Submissions',
            'purchase' => 'Purchases',
        ];

        $summary = [];
        foreach ($events as $event_name => $label) {
            $data = $analytics->getEventData($event_name, $start, $end);
            $summary[] = [
                'event_name' => $event_name,
                'label' => $label,
                'count' => (int)($data['count'] ?? 0),
            ];
        }

        return $summary;
    }

    private static function getReportRecipients(array $client): array
    {
        $recipients = [];
        foreach (['billing_email', 'email'] as $field) {
            $email = sanitize_email((string)($client[$field] ?? ''));
            if ($email && is_email($email)) {
                $recipients[] = $email;
            }
        }

        return array_values(array_unique($recipients));
    }

    private static function formatDuration(float $seconds): string
    {
        $seconds = max(0, (int)round($seconds));
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        if ($minutes <= 0) {
            return $remaining . 's';
        }

        return $minutes . 'm ' . str_pad((string)$remaining, 2, '0', STR_PAD_LEFT) . 's';
    }

    private static function formatTrafficChange(array $overview, array $comparison): string
    {
        $current = (int)($overview['total_users'] ?? 0);
        $previous = (int)($comparison['total_users'] ?? 0);

        if ($current <= 0 && $previous <= 0) {
            return 'Analytics traffic data unavailable';
        }

        if ($previous <= 0) {
            return number_format($current) . ' visitors this period';
        }

        $change = (($current - $previous) / $previous) * 100;
        $direction = $change >= 0 ? 'up' : 'down';

        return number_format($current) . ' visitors, ' . $direction . ' ' . number_format(abs($change), 1) . '% vs previous period';
    }

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
        $analytics = $data['analytics'] ?? [];
        $overview = $analytics['overview'] ?? [];
        $comparison = $overview['comparison'] ?? [];
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
            'traffic_change'     => self::formatTrafficChange($overview, $comparison),
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
