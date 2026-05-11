<?php
/**
 * Report Generator
 *
 * Generates monthly analytics reports including GA4 and Google Search Console data.
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
use WNQ\API\GoogleSearchConsole;

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

        $analytics_config = AnalyticsConfig::getClientConfig($client_id);
        $client = self::resolveReportClient($client_id, $analytics_config);
        if (!$client) return false;

        $profile = self::resolveReportProfile($client_id, $client, $analytics_config);
        $analytics_context = $analytics_config
            ? [
                'client_id' => (string)$analytics_config['client_id'],
                'config' => $analytics_config,
                'match_reason' => 'Selected WebNique Portal Analytics client',
            ]
            : self::resolveAnalyticsContext($client_id, $client, $profile);
        $report_client_id = (string)($analytics_context['client_id'] ?? $client_id);

        // Gather all report data
        $report_data = [
            'client'         => self::getClientSummary($client, $profile),
            'analytics'      => self::getAnalyticsSummary($analytics_context, $period_start, $period_end),
            'period'         => ['start' => $period_start, 'end' => $period_end, 'label' => date('F Y', strtotime($period_start))],
            'generated_at'   => current_time('mysql'),
        ];
        $report_status = self::resolveReportStatus($report_data);

        // AI-generated executive summary
        $summary_html = self::generateAISummary($client, $profile, $report_data);

        $report_id = SEOHub::createReport($report_client_id, 'monthly', $period_start, $period_end, $report_data, $summary_html, $report_status);

        if ($report_id) {
            SEOHub::log('monthly_report_generated', [
                'client_id' => $report_client_id,
                'entity_id' => $report_id,
                'period'    => $period_start . ' to ' . $period_end,
            ]);
        }

        return $report_id;
    }

    /**
     * Generate reports for all active clients
     */
    public static function generateAllMonthlyReports(string $month = '', bool $send_email = true, bool $force_new = false): array
    {
        $clients = AnalyticsConfig::getAllClients();
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

            $existing = SEOHub::getReportForPeriod($client_id, 'monthly', $period['start'], $period['end']);
            if (!$force_new && $existing && ($existing['status'] ?? '') === 'sent') {
                $results['email_skipped']++;
                continue;
            }

            $id = (!$force_new && $existing) ? (int)$existing['id'] : self::generateMonthlyReport($client_id, $month);
            if ($id) {
                if ($force_new || !$existing) {
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

        $analytics_config = AnalyticsConfig::getClientConfig((string)$report['client_id']);
        $client = self::resolveReportClient((string)$report['client_id'], $analytics_config);
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

        $data    = is_array($report['report_data'] ?? null) ? $report['report_data'] : [];
        $period  = $data['period'] ?? [];
        $client  = $data['client'] ?? [];
        $analytics = $data['analytics'] ?? [];

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics Report - <?php echo esc_html($client['name'] ?? ''); ?> - <?php echo esc_html($period['label'] ?? ''); ?></title>
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
  .chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px; margin: 8px 0 28px; }
  .chart-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06); }
  .chart-title { color: #1e3a5f; font-size: 15px; font-weight: 800; margin-bottom: 4px; }
  .chart-subtitle { color: #64748b; font-size: 12px; margin-bottom: 14px; }
  .chart-svg { width: 100%; height: auto; display: block; overflow: visible; }
  .chart-axis-label { fill: #64748b; font-size: 11px; }
  .chart-grid-line { stroke: #e5e7eb; stroke-width: 1; }
  .chart-point { stroke: #ffffff; stroke-width: 2; }
  .bar-list { display: flex; flex-direction: column; gap: 12px; }
  .bar-row { display: flex; flex-direction: column; gap: 6px; }
  .bar-meta { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; font-size: 12px; }
  .bar-label { color: #334155; font-weight: 700; line-height: 1.35; }
  .bar-value { color: #64748b; white-space: nowrap; }
  .bar-track { width: 100%; height: 10px; background: #edf2f7; border-radius: 999px; overflow: hidden; }
  .bar-fill { display: block; height: 100%; min-width: 4px; border-radius: 999px; }
  .dual-bars { display: grid; grid-template-columns: 1fr; gap: 5px; }
  .legend { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 14px; color: #64748b; font-size: 12px; }
  .legend-item { display: inline-flex; align-items: center; gap: 6px; }
  .legend-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
  .table-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th { background: #f1f5f9; text-align: left; padding: 10px 12px; font-weight: 600; color: #374151; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
  td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #374151; }
  tr:hover td { background: #f9fafb; }
  .ai-summary { background: #f0f9ff; border-left: 4px solid #0d539e; padding: 20px 24px; border-radius: 0 8px 8px 0; font-size: 15px; line-height: 1.7; }
  .note { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 14px 16px; font-size: 14px; line-height: 1.6; }
  .report-footer { padding: 24px 40px; background: #f9fafb; text-align: center; font-size: 12px; color: #9ca3af; }
  @media print { body { background: white; } .section { page-break-inside: avoid; } }
</style>
</head>
<body>
<div class="report-wrapper">

  <div class="report-header">
    <h1>Analytics Performance Report</h1>
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
    <?php $search_console = $analytics['search_console'] ?? []; ?>
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

      <?php if (self::hasNumericChartRows($visitor_trends, ['users']) || self::hasNumericChartRows($traffic_sources, ['sessions'])): ?>
      <div class="chart-grid">
        <?php echo self::renderLineChart($visitor_trends, 'Visitors Over Time', 'users', '#2563eb'); ?>
        <?php echo self::renderBarChart($traffic_sources, 'channel', 'sessions', 'Sessions by Channel', '#0d9488'); ?>
      </div>
      <?php endif; ?>

      <?php if (self::hasNumericChartRows($top_pages, ['views']) || self::hasNumericChartRows($key_events, ['count'])): ?>
      <div class="chart-grid">
        <?php echo self::renderBarChart($top_pages, 'title', 'views', 'Top Pages by Views', '#7c3aed'); ?>
        <?php echo self::renderBarChart($key_events, 'label', 'count', 'Key Events', '#16a34a'); ?>
      </div>
      <?php endif; ?>

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
          <thead><tr><th>Page</th><th>Views</th><th>Bounce</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($top_pages, 0, 8) as $page): ?>
            <tr>
              <td>
                <strong><?php echo esc_html($page['title'] ?? 'Untitled'); ?></strong><br>
                <span style="font-size:12px;color:#6b7280;"><?php echo esc_html($page['path'] ?? ''); ?></span>
              </td>
              <td><?php echo number_format((int)($page['views'] ?? 0)); ?></td>
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
        GA4 analytics data was not available for this client during report generation.
        <?php if (!empty($analytics['error'])): ?>
          <?php echo esc_html($analytics['error']); ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

      <?php if (!empty($search_console['configured'])): ?>
      <?php
        $gsc_overview = $search_console['overview'] ?? [];
        $gsc_keywords = $search_console['top_keywords'] ?? [];
        $gsc_pages = $search_console['top_pages'] ?? [];
        $gsc_trends = $search_console['performance_over_time'] ?? [];
      ?>
      <h3 style="font-size:16px;color:#374151;margin:10px 0 12px;">Google Search Console</h3>
      <div class="metric-grid">
        <div class="metric-card">
          <div class="value"><?php echo number_format((int)($gsc_overview['clicks']['value'] ?? 0)); ?></div>
          <div class="label">Organic Clicks</div>
        </div>
        <div class="metric-card">
          <div class="value"><?php echo number_format((int)($gsc_overview['impressions']['value'] ?? 0)); ?></div>
          <div class="label">Search Impressions</div>
        </div>
        <div class="metric-card">
          <div class="value"><?php echo number_format((float)($gsc_overview['ctr']['value'] ?? 0), 1); ?>%</div>
          <div class="label">Average CTR</div>
        </div>
        <div class="metric-card">
          <div class="value"><?php echo number_format((float)($gsc_overview['position']['value'] ?? 0), 1); ?></div>
          <div class="label">Average Position</div>
        </div>
      </div>

      <?php if (self::hasNumericChartRows($gsc_trends, ['clicks']) || self::hasNumericChartRows($gsc_trends, ['impressions'])): ?>
      <div class="chart-grid">
        <?php echo self::renderLineChart($gsc_trends, 'Organic Clicks Over Time', 'clicks', '#2563eb'); ?>
        <?php echo self::renderLineChart($gsc_trends, 'Search Impressions Over Time', 'impressions', '#7c3aed'); ?>
      </div>
      <?php endif; ?>

      <?php if (self::hasNumericChartRows($gsc_keywords, ['clicks', 'impressions']) || self::hasNumericChartRows($gsc_pages, ['clicks', 'impressions'])): ?>
      <div class="chart-grid">
        <?php echo self::renderDualMetricBars($gsc_keywords, 'keyword', 'clicks', 'impressions', 'Top Queries', 'Clicks', 'Impressions'); ?>
        <?php echo self::renderDualMetricBars($gsc_pages, 'page', 'clicks', 'impressions', 'Top Search Pages', 'Clicks', 'Impressions'); ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($gsc_trends)): ?>
      <h3 style="font-size:16px;color:#374151;margin:6px 0 12px;">Search Performance Over Time</h3>
      <div class="table-wrap" style="margin-bottom:24px;">
        <table>
          <thead><tr><th>Date</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead>
          <tbody>
            <?php foreach ($gsc_trends as $day): ?>
            <tr>
              <td><?php echo esc_html($day['date'] ?? ''); ?></td>
              <td><?php echo number_format((int)($day['clicks'] ?? 0)); ?></td>
              <td><?php echo number_format((int)($day['impressions'] ?? 0)); ?></td>
              <td><?php echo number_format((float)($day['ctr'] ?? 0), 1); ?>%</td>
              <td><?php echo number_format((float)($day['position'] ?? 0), 1); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if (!empty($gsc_keywords)): ?>
      <h3 style="font-size:16px;color:#374151;margin:6px 0 12px;">Top Search Queries</h3>
      <div class="table-wrap" style="margin-bottom:24px;">
        <table>
          <thead><tr><th>Query</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead>
          <tbody>
            <?php foreach ($gsc_keywords as $keyword): ?>
            <tr>
              <td><?php echo esc_html($keyword['keyword'] ?? ''); ?></td>
              <td><?php echo number_format((int)($keyword['clicks'] ?? 0)); ?></td>
              <td><?php echo number_format((int)($keyword['impressions'] ?? 0)); ?></td>
              <td><?php echo number_format((float)($keyword['ctr'] ?? 0), 1); ?>%</td>
              <td><?php echo number_format((float)($keyword['position'] ?? 0), 1); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if (!empty($gsc_pages)): ?>
      <h3 style="font-size:16px;color:#374151;margin:6px 0 12px;">Top Search Pages</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Page</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead>
          <tbody>
            <?php foreach ($gsc_pages as $page): ?>
            <tr>
              <td><?php echo esc_html($page['page'] ?? ''); ?></td>
              <td><?php echo number_format((int)($page['clicks'] ?? 0)); ?></td>
              <td><?php echo number_format((int)($page['impressions'] ?? 0)); ?></td>
              <td><?php echo number_format((float)($page['ctr'] ?? 0), 1); ?>%</td>
              <td><?php echo number_format((float)($page['position'] ?? 0), 1); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <?php elseif (!empty($search_console['error'])): ?>
      <div class="note" style="margin-top:20px;">
        Google Search Console data was not available. <?php echo esc_html($search_console['error']); ?>
      </div>
      <?php endif; ?>
  </div>

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

    private static function renderLineChart(array $rows, string $title, string $metric_key, string $color = '#0d539e'): string
    {
        $series = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $series[] = [
                'date' => (string)($row['date'] ?? ''),
                'label' => self::formatChartDate((string)($row['date'] ?? '')),
                'value' => max(0.0, (float)($row[$metric_key] ?? 0)),
            ];
        }

        if (!self::hasNumericChartRows($series, ['value'])) {
            return '';
        }

        $all_values = array_map(fn($point) => (float)$point['value'], $series);
        $total = array_sum($all_values);
        $peak = max($all_values);
        $series = self::sampleChartSeries($series, 18);
        $max_value = max(1, (float)ceil($peak * 1.15));

        $svg_width = 760;
        $svg_height = 250;
        $left = 48;
        $right = 18;
        $top = 22;
        $bottom = 42;
        $chart_width = $svg_width - $left - $right;
        $chart_height = $svg_height - $top - $bottom;
        $baseline = $top + $chart_height;
        $count = count($series);

        $points = [];
        $point_nodes = '';
        $label_nodes = '';
        foreach ($series as $index => $point) {
            $x = $count === 1 ? $left + ($chart_width / 2) : $left + (($chart_width / max(1, $count - 1)) * $index);
            $y = $baseline - (((float)$point['value'] / $max_value) * $chart_height);
            $points[] = round($x, 1) . ',' . round($y, 1);
            $point_nodes .= sprintf(
                '<circle class="chart-point" cx="%s" cy="%s" r="4" fill="%s"><title>%s: %s</title></circle>',
                esc_attr((string)round($x, 1)),
                esc_attr((string)round($y, 1)),
                esc_attr($color),
                esc_attr((string)$point['label']),
                esc_attr(self::formatChartValue((float)$point['value']))
            );
        }

        $label_indexes = array_values(array_unique([0, (int)floor(($count - 1) / 2), max(0, $count - 1)]));
        foreach ($label_indexes as $label_index) {
            $x = $count === 1 ? $left + ($chart_width / 2) : $left + (($chart_width / max(1, $count - 1)) * $label_index);
            $anchor = $label_index === 0 ? 'start' : ($label_index === max(0, $count - 1) ? 'end' : 'middle');
            $label_nodes .= sprintf(
                '<text class="chart-axis-label" x="%s" y="%s" text-anchor="%s">%s</text>',
                esc_attr((string)round($x, 1)),
                esc_attr((string)($svg_height - 12)),
                esc_attr($anchor),
                esc_html((string)($series[$label_index]['label'] ?? ''))
            );
        }

        $grid_nodes = '';
        foreach ([1, 0.5, 0] as $scale) {
            $y = $baseline - ($scale * $chart_height);
            $grid_nodes .= sprintf(
                '<line class="chart-grid-line" x1="%s" y1="%s" x2="%s" y2="%s"></line><text class="chart-axis-label" x="0" y="%s">%s</text>',
                esc_attr((string)$left),
                esc_attr((string)round($y, 1)),
                esc_attr((string)($left + $chart_width)),
                esc_attr((string)round($y, 1)),
                esc_attr((string)round($y + 4, 1)),
                esc_html(self::formatChartValue($max_value * $scale))
            );
        }

        $first_x = $count === 1 ? $left + ($chart_width / 2) : $left;
        $last_x = $count === 1 ? $first_x : $left + $chart_width;
        $area_points = round($first_x, 1) . ',' . round($baseline, 1) . ' ' . implode(' ', $points) . ' ' . round($last_x, 1) . ',' . round($baseline, 1);

        return sprintf(
            '<div class="chart-card"><div class="chart-title">%s</div><div class="chart-subtitle">Total %s &bull; Peak %s</div><svg class="chart-svg" viewBox="0 0 %d %d" role="img" aria-label="%s">%s<polygon points="%s" fill="%s" opacity="0.12"></polygon><polyline points="%s" fill="none" stroke="%s" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></polyline>%s%s</svg></div>',
            esc_html($title),
            esc_html(self::formatChartValue((float)$total)),
            esc_html(self::formatChartValue((float)$peak)),
            $svg_width,
            $svg_height,
            esc_attr($title),
            $grid_nodes,
            esc_attr($area_points),
            esc_attr($color),
            esc_attr(implode(' ', $points)),
            esc_attr($color),
            $point_nodes,
            $label_nodes
        );
    }

    private static function renderBarChart(array $rows, string $label_key, string $value_key, string $title, string $color = '#0d539e', int $limit = 6): string
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = max(0.0, (float)($row[$value_key] ?? 0));
            if ($value <= 0) {
                continue;
            }

            $items[] = [
                'label' => self::resolveChartLabel($row, $label_key),
                'value' => $value,
            ];
        }

        if (empty($items)) {
            return '';
        }

        usort($items, fn($a, $b) => $b['value'] <=> $a['value']);
        $items = array_slice($items, 0, $limit);
        $max_value = max(1, max(array_column($items, 'value')));
        $bars = '';

        foreach ($items as $item) {
            $width = max(4, min(100, (int)round(((float)$item['value'] / $max_value) * 100)));
            $bars .= sprintf(
                '<div class="bar-row"><div class="bar-meta"><span class="bar-label">%s</span><span class="bar-value">%s</span></div><div class="bar-track"><span class="bar-fill" style="width:%d%%;background:%s;"></span></div></div>',
                esc_html(self::shortChartLabel((string)$item['label'])),
                esc_html(self::formatChartValue((float)$item['value'])),
                $width,
                esc_attr($color)
            );
        }

        return sprintf(
            '<div class="chart-card"><div class="chart-title">%s</div><div class="chart-subtitle">Top %d by volume</div><div class="bar-list">%s</div></div>',
            esc_html($title),
            count($items),
            $bars
        );
    }

    private static function renderDualMetricBars(array $rows, string $label_key, string $primary_key, string $secondary_key, string $title, string $primary_label, string $secondary_label, int $limit = 6): string
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $primary = max(0.0, (float)($row[$primary_key] ?? 0));
            $secondary = max(0.0, (float)($row[$secondary_key] ?? 0));
            if ($primary <= 0 && $secondary <= 0) {
                continue;
            }

            $items[] = [
                'label' => self::resolveChartLabel($row, $label_key),
                'primary' => $primary,
                'secondary' => $secondary,
            ];
        }

        if (empty($items)) {
            return '';
        }

        usort($items, fn($a, $b) => (($b['primary'] <=> $a['primary']) ?: ($b['secondary'] <=> $a['secondary'])));
        $items = array_slice($items, 0, $limit);
        $max_primary = max(1, max(array_column($items, 'primary')));
        $max_secondary = max(1, max(array_column($items, 'secondary')));
        $bars = '';

        foreach ($items as $item) {
            $primary_width = max(3, min(100, (int)round(((float)$item['primary'] / $max_primary) * 100)));
            $secondary_width = max(3, min(100, (int)round(((float)$item['secondary'] / $max_secondary) * 100)));
            $bars .= sprintf(
                '<div class="bar-row"><div class="bar-meta"><span class="bar-label">%s</span><span class="bar-value">%s %s &bull; %s %s</span></div><div class="dual-bars"><div class="bar-track"><span class="bar-fill" style="width:%d%%;background:#2563eb;"></span></div><div class="bar-track"><span class="bar-fill" style="width:%d%%;background:#7c3aed;"></span></div></div></div>',
                esc_html(self::shortChartLabel((string)$item['label'])),
                esc_html(self::formatChartValue((float)$item['primary'])),
                esc_html(strtolower($primary_label)),
                esc_html(self::formatChartValue((float)$item['secondary'])),
                esc_html(strtolower($secondary_label)),
                $primary_width,
                $secondary_width
            );
        }

        return sprintf(
            '<div class="chart-card"><div class="chart-title">%s</div><div class="chart-subtitle">Organic search visibility leaders</div><div class="legend"><span class="legend-item"><span class="legend-dot" style="background:#2563eb;"></span>%s</span><span class="legend-item"><span class="legend-dot" style="background:#7c3aed;"></span>%s</span></div><div class="bar-list">%s</div></div>',
            esc_html($title),
            esc_html($primary_label),
            esc_html($secondary_label),
            $bars
        );
    }

    private static function hasNumericChartRows(array $rows, array $keys): bool
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($keys as $key) {
                if ((float)($row[$key] ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function sampleChartSeries(array $series, int $max_points): array
    {
        $count = count($series);
        if ($count <= $max_points || $max_points < 2) {
            return $series;
        }

        $sampled = [];
        $seen = [];
        for ($i = 0; $i < $max_points; $i++) {
            $index = (int)round(($i * ($count - 1)) / ($max_points - 1));
            if (isset($seen[$index])) {
                continue;
            }
            $seen[$index] = true;
            $sampled[] = $series[$index];
        }

        return $sampled;
    }

    private static function resolveChartLabel(array $row, string $preferred_key): string
    {
        $label = trim((string)($row[$preferred_key] ?? ''));
        if ($label !== '') {
            return $label;
        }

        foreach (['title', 'path', 'page', 'keyword', 'channel', 'label', 'event_name'] as $fallback_key) {
            $label = trim((string)($row[$fallback_key] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return 'Unknown';
    }

    private static function shortChartLabel(string $label, int $limit = 74): string
    {
        $label = preg_replace('/\s+/', ' ', wp_strip_all_tags($label)) ?: '';
        $label = trim($label);
        if ($label === '') {
            return 'Unknown';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($label) > $limit ? rtrim(mb_substr($label, 0, $limit - 3)) . '...' : $label;
        }

        return strlen($label) > $limit ? rtrim(substr($label, 0, $limit - 3)) . '...' : $label;
    }

    private static function formatChartDate(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp ? date('M j', $timestamp) : $date;
    }

    private static function formatChartValue(float $value): string
    {
        $abs = abs($value);
        if ($abs >= 1000000) {
            return rtrim(rtrim(number_format($value / 1000000, 1), '0'), '.') . 'M';
        }

        if ($abs >= 1000) {
            return rtrim(rtrim(number_format($value / 1000, 1), '0'), '.') . 'K';
        }

        if (floor($value) === $value) {
            return number_format((int)$value);
        }

        return number_format($value, 1);
    }

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

    private static function getAnalyticsSummary(array $context, string $start, string $end): array
    {
        $client_id = (string)($context['client_id'] ?? '');
        $config = $context['config'] ?? null;
        $credentials = AnalyticsConfig::getCredentials();
        $base = [
            'analytics_client_id' => $client_id,
            'analytics_match_reason' => (string)($context['match_reason'] ?? 'unknown'),
        ];
        $search_console = $config
            ? self::getSearchConsoleSummary($client_id, $config, $start, $end)
            : [
                'configured' => false,
                'source' => self::sourceStatus('missing_config', 'Not configured', 'Google Search Console is not configured for this client.'),
                'error' => 'Google Search Console is not configured for this client.',
            ];

        if (!$config || empty($config['ga4_property_id'])) {
            $ga4_source = self::sourceStatus('missing_config', 'Not configured', 'GA4 is not configured for this client.');
            return $base + [
                'configured' => false,
                'source' => $ga4_source,
                'sources' => [
                    'ga4' => $ga4_source,
                    'gsc' => $search_console['source'] ?? self::sourceStatus('missing_config', 'Not configured', $search_console['error'] ?? ''),
                ],
                'error' => 'GA4 is not configured for this client.',
                'search_console' => $search_console,
            ];
        }

        if (!$credentials) {
            $ga4_source = self::sourceStatus('missing_credentials', 'Credentials missing', 'Google Analytics service account credentials are not configured.');
            return $base + [
                'configured' => false,
                'source' => $ga4_source,
                'sources' => [
                    'ga4' => $ga4_source,
                    'gsc' => $search_console['source'] ?? self::sourceStatus('missing_credentials', 'Credentials missing', $search_console['error'] ?? ''),
                ],
                'error' => 'Google Analytics service account credentials are not configured.',
                'search_console' => $search_console,
            ];
        }

        try {
            $analytics = new GoogleAnalytics($client_id);
            $overview = $analytics->getOverviewStats($start, $end);
            $visitor_trends = $analytics->getVisitorTrends($start, $end);
            $traffic_sources = $analytics->getTrafficSources($start, $end);
            $top_pages = $analytics->getTopPages($start, $end, 10);
            $key_events = self::getAnalyticsKeyEvents($analytics, $start, $end);
            $ga4_errors = $analytics->getErrors();
            $ga4_source = self::buildGa4SourceSummary($config, $overview, $key_events, $ga4_errors);

            return $base + [
                'configured' => in_array($ga4_source['status'], ['connected', 'partial', 'no_data'], true),
                'source' => $ga4_source,
                'sources' => [
                    'ga4' => $ga4_source,
                    'gsc' => $search_console['source'] ?? self::sourceStatus('missing_config', 'Not configured', $search_console['error'] ?? ''),
                ],
                'ga4_property_id' => $config['ga4_property_id'],
                'overview' => $overview,
                'visitors_over_time' => $visitor_trends,
                'traffic_sources' => $traffic_sources,
                'top_pages' => $top_pages,
                'key_events' => $key_events,
                'total_key_events' => array_sum(array_map(fn($event) => (int)($event['count'] ?? 0), $key_events)),
                'error' => $ga4_source['error'] ?? '',
                'search_console' => $search_console,
            ];
        } catch (\Throwable $e) {
            SEOHub::log('monthly_report_analytics_failed', [
                'client_id' => $client_id,
                'error' => $e->getMessage(),
            ], 'failed');

            $ga4_source = self::sourceStatus('failed', 'API failed', $e->getMessage(), [
                'property_id' => (string)($config['ga4_property_id'] ?? ''),
            ]);

            return $base + [
                'configured' => false,
                'source' => $ga4_source,
                'sources' => [
                    'ga4' => $ga4_source,
                    'gsc' => $search_console['source'] ?? self::sourceStatus('missing_config', 'Not configured', $search_console['error'] ?? ''),
                ],
                'error' => $e->getMessage(),
                'search_console' => $search_console,
            ];
        }
    }

    private static function getSearchConsoleSummary(string $client_id, array $config, string $start, string $end): array
    {
        if (empty($config['search_console_url'])) {
            $source = self::sourceStatus('missing_config', 'Not configured', 'Google Search Console is not configured for this client.');
            return [
                'configured' => false,
                'source' => $source,
                'error' => 'Google Search Console is not configured for this client.',
            ];
        }

        if (!AnalyticsConfig::getCredentials()) {
            $source = self::sourceStatus('missing_credentials', 'Credentials missing', 'Google service account credentials are not configured.');
            return [
                'configured' => false,
                'source' => $source,
                'error' => 'Google service account credentials are not configured.',
            ];
        }

        try {
            $gsc = new GoogleSearchConsole($client_id);
            $overview = $gsc->getOverviewStats($start, $end);
            $performance = $gsc->getPerformanceOverTime($start, $end);
            $keywords = $gsc->getKeywordRankings($start, $end, 10);
            $pages = $gsc->getTopPages($start, $end, 10);
            $source = self::buildGscSourceSummary($config, $overview, $keywords, $gsc->getErrors());

            return [
                'configured' => in_array($source['status'], ['connected', 'partial', 'no_data'], true),
                'source' => $source,
                'overview' => $overview,
                'performance_over_time' => $performance,
                'top_keywords' => $keywords,
                'top_pages' => $pages,
                'error' => $source['error'] ?? '',
            ];
        } catch (\Throwable $e) {
            SEOHub::log('monthly_report_gsc_failed', [
                'client_id' => $client_id,
                'error' => $e->getMessage(),
            ], 'failed');

            $source = self::sourceStatus('failed', 'API failed', $e->getMessage(), [
                'property' => (string)($config['search_console_url'] ?? ''),
            ]);

            return [
                'configured' => false,
                'source' => $source,
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

        $counts = array_fill_keys(array_keys($events), 0);
        foreach ($analytics->getKeyEvents(array_keys($events), $start, $end) as $event) {
            $event_name = (string)($event['event_name'] ?? '');
            if (array_key_exists($event_name, $counts)) {
                $counts[$event_name] = (int)($event['count'] ?? 0);
            }
        }

        return array_map(
            fn($event_name, $label) => [
                'event_name' => $event_name,
                'label' => $label,
                'count' => (int)($counts[$event_name] ?? 0),
            ],
            array_keys($events),
            $events
        );
    }

    private static function buildGa4SourceSummary(array $config, array $overview, array $key_events, array $errors): array
    {
        $visitors = (int)($overview['total_users'] ?? 0);
        $sessions = (int)($overview['sessions'] ?? 0);
        $page_views = (int)($overview['page_views'] ?? 0);
        $events = array_sum(array_map(fn($event) => (int)($event['count'] ?? 0), $key_events));
        $error = self::firstSourceError($errors);

        if ($error && ($visitors > 0 || $sessions > 0 || $page_views > 0 || $events > 0)) {
            $status = 'partial';
            $label = 'Partial data';
        } elseif ($error) {
            $status = 'failed';
            $label = 'API failed';
        } elseif ($visitors <= 0 && $sessions <= 0 && $page_views <= 0 && $events <= 0) {
            $status = 'no_data';
            $label = 'Connected, no traffic';
        } else {
            $status = 'connected';
            $label = 'Connected';
        }

        return self::sourceStatus($status, $label, $error, [
            'property_id' => (string)($config['ga4_property_id'] ?? ''),
            'visitors' => $visitors,
            'sessions' => $sessions,
            'page_views' => $page_views,
            'key_events' => $events,
        ]);
    }

    private static function buildGscSourceSummary(array $config, array $overview, array $keywords, array $errors): array
    {
        $clicks = (int)($overview['clicks']['value'] ?? 0);
        $impressions = (int)($overview['impressions']['value'] ?? 0);
        $keyword_count = count($keywords);
        $error = self::firstSourceError($errors);

        if ($error && ($clicks > 0 || $impressions > 0 || $keyword_count > 0)) {
            $status = 'partial';
            $label = 'Partial data';
        } elseif ($error) {
            $status = 'failed';
            $label = 'API failed';
        } elseif ($clicks <= 0 && $impressions <= 0 && $keyword_count <= 0) {
            $status = 'no_data';
            $label = 'Connected, no search data';
        } else {
            $status = 'connected';
            $label = 'Connected';
        }

        return self::sourceStatus($status, $label, $error, [
            'property' => (string)($config['search_console_url'] ?? ''),
            'clicks' => $clicks,
            'impressions' => $impressions,
            'queries' => $keyword_count,
        ]);
    }

    private static function sourceStatus(string $status, string $label, string $error = '', array $metrics = []): array
    {
        return [
            'status' => $status,
            'label' => $label,
            'error' => $error,
            'metrics' => $metrics,
        ];
    }

    private static function firstSourceError(array $errors): string
    {
        $errors = array_filter(array_map('strval', $errors));
        return $errors ? reset($errors) : '';
    }

    private static function resolveReportStatus(array $report_data): string
    {
        $analytics = $report_data['analytics'] ?? [];
        $sources = $analytics['sources'] ?? [];
        $ga4_status = $sources['ga4']['status'] ?? (!empty($analytics['configured']) ? 'connected' : 'missing_config');
        $gsc_status = $sources['gsc']['status'] ?? (!empty($analytics['search_console']['configured']) ? 'connected' : 'missing_config');
        $statuses = [$ga4_status, $gsc_status];

        if (array_intersect($statuses, ['failed'])) {
            return 'needs_attention';
        }

        if (!array_intersect($statuses, ['missing_config', 'missing_credentials', 'partial']) && !array_diff($statuses, ['connected', 'no_data'])) {
            return 'ready';
        }

        if (array_intersect($statuses, ['connected', 'partial', 'no_data'])) {
            return 'partial';
        }

        return 'needs_setup';
    }

    private static function resolveReportClient(string $client_id, ?array $analytics_config): ?array
    {
        $client = Client::getByClientId($client_id);
        if (!$client && $analytics_config) {
            $client = self::findPortalClientForAnalyticsConfig($analytics_config);
        }

        if (!$analytics_config) {
            return $client ?: null;
        }

        return [
            'client_id' => (string)$analytics_config['client_id'],
            'name' => (string)(($client['name'] ?? '') ?: ($analytics_config['client_name'] ?? $analytics_config['client_id'])),
            'company' => (string)(($client['company'] ?? '') ?: ($analytics_config['client_name'] ?? '')),
            'email' => (string)($client['email'] ?? ''),
            'billing_email' => (string)($client['billing_email'] ?? ''),
            'website' => (string)(($analytics_config['website_url'] ?? '') ?: ($client['website'] ?? '')),
            'google_analytics_property_id' => (string)($analytics_config['ga4_property_id'] ?? ''),
            'google_search_console_site_url' => (string)($analytics_config['search_console_url'] ?? ''),
        ];
    }

    private static function resolveReportProfile(string $client_id, array $client, ?array $analytics_config): array
    {
        $profile = SEOHub::getProfile($client_id);
        if ($profile) {
            return $profile;
        }

        if ($analytics_config) {
            $matched_client = self::findPortalClientForAnalyticsConfig($analytics_config);
            if ($matched_client && !empty($matched_client['client_id'])) {
                $profile = SEOHub::getProfile((string)$matched_client['client_id']);
                if ($profile) {
                    return $profile;
                }
            }
        }

        $client_profile_id = (string)($client['client_id'] ?? '');
        if ($client_profile_id !== '' && $client_profile_id !== $client_id) {
            $profile = SEOHub::getProfile($client_profile_id);
            if ($profile) {
                return $profile;
            }
        }

        return [];
    }

    private static function findPortalClientForAnalyticsConfig(array $analytics_config): ?array
    {
        $target_id = self::normalizeMatchToken((string)($analytics_config['client_id'] ?? ''));
        $target_name = self::normalizeMatchToken((string)($analytics_config['client_name'] ?? ''));
        $target_domains = array_filter(array_unique([
            self::normalizeDomain((string)($analytics_config['website_url'] ?? '')),
            self::normalizeDomain((string)($analytics_config['search_console_url'] ?? '')),
        ]));

        foreach (Client::getAll() as $client) {
            $client_tokens = array_filter(array_unique([
                self::normalizeMatchToken((string)($client['client_id'] ?? '')),
                self::normalizeMatchToken((string)($client['company'] ?? '')),
                self::normalizeMatchToken((string)($client['name'] ?? '')),
            ]));
            $client_domains = array_filter(array_unique([
                self::normalizeDomain((string)($client['website'] ?? '')),
                self::normalizeDomain((string)($client['google_search_console_site_url'] ?? '')),
            ]));

            if ($target_id !== '' && in_array($target_id, $client_tokens, true)) {
                return $client;
            }
            if ($target_name !== '' && in_array($target_name, $client_tokens, true)) {
                return $client;
            }
            if ($target_domains && array_intersect($target_domains, $client_domains)) {
                return $client;
            }
        }

        return null;
    }

    private static function resolveAnalyticsContext(string $seo_client_id, ?array $client, array $profile): array
    {
        $exact = AnalyticsConfig::getClientConfig($seo_client_id);
        if ($exact) {
            return [
                'client_id' => (string)$exact['client_id'],
                'config' => $exact,
                'match_reason' => 'Exact client ID match',
            ];
        }

        $configs = AnalyticsConfig::getAllClients();
        $target_id = self::normalizeMatchToken($seo_client_id);
        foreach ($configs as $config) {
            if ($target_id !== '' && self::normalizeMatchToken((string)($config['client_id'] ?? '')) === $target_id) {
                return [
                    'client_id' => (string)$config['client_id'],
                    'config' => $config,
                    'match_reason' => 'Matched client ID without punctuation',
                ];
            }
        }

        $target_domains = array_filter(array_unique([
            self::normalizeDomain((string)($client['website'] ?? '')),
            self::normalizeDomain((string)($client['google_search_console_site_url'] ?? '')),
            self::normalizeDomain((string)($profile['gsc_property'] ?? '')),
        ]));

        foreach ($configs as $config) {
            $config_domains = array_filter(array_unique([
                self::normalizeDomain((string)($config['website_url'] ?? '')),
                self::normalizeDomain((string)($config['search_console_url'] ?? '')),
            ]));

            if ($target_domains && array_intersect($target_domains, $config_domains)) {
                return [
                    'client_id' => (string)$config['client_id'],
                    'config' => $config,
                    'match_reason' => 'Matched website/Search Console domain',
                ];
            }
        }

        $target_names = array_filter(array_unique([
            self::normalizeMatchToken((string)($client['company'] ?? '')),
            self::normalizeMatchToken((string)($client['name'] ?? '')),
        ]));

        foreach ($configs as $config) {
            if ($target_names && in_array(self::normalizeMatchToken((string)($config['client_name'] ?? '')), $target_names, true)) {
                return [
                    'client_id' => (string)$config['client_id'],
                    'config' => $config,
                    'match_reason' => 'Matched client name',
                ];
            }
        }

        return [
            'client_id' => $seo_client_id,
            'config' => null,
            'match_reason' => 'No matching Analytics client config found',
        ];
    }

    private static function normalizeMatchToken(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?: '';
    }

    private static function normalizeDomain(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, 'sc-domain:')) {
            $value = substr($value, strlen('sc-domain:'));
        }

        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
            $value = is_string($host) ? $host : $value;
        } else {
            $value = preg_replace('/\/.*$/', '', $value) ?: $value;
        }

        $value = preg_replace('/^www\./', '', $value) ?: $value;
        return trim($value, " \t\n\r\0\x0B/");
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
            'name'      => ($client['company'] ?? '') ?: ($client['name'] ?? ''),
            'website'   => $client['website'] ?? '',
            'services'  => $profile['primary_services'] ?? [],
            'locations' => $profile['service_locations'] ?? [],
        ];
    }

    private static function generateAISummary(array $client, array $profile, array $data): string
    {
        $analytics = $data['analytics'] ?? [];
        $overview = $analytics['overview'] ?? [];
        $comparison = $overview['comparison'] ?? [];
        $gsc = $analytics['search_console'] ?? [];
        $gsc_overview = $gsc['overview'] ?? [];
        $top_queries = array_slice(array_column((array)($gsc['top_keywords'] ?? []), 'keyword'), 0, 5);
        $period  = $data['period'] ?? [];

        $vars = [
            'client_name'       => ($client['company'] ?? '') ?: ($client['name'] ?? ''),
            'period'            => $period['label'] ?? '',
            'traffic_change'    => self::formatTrafficChange($overview, $comparison),
            'visitors'          => number_format((int)($overview['total_users'] ?? 0)),
            'sessions'          => number_format((int)($overview['sessions'] ?? 0)),
            'page_views'        => number_format((int)($overview['page_views'] ?? 0)),
            'bounce_rate'       => number_format((float)($overview['bounce_rate'] ?? 0), 1) . '%',
            'key_events'        => number_format((int)($analytics['total_key_events'] ?? 0)),
            'search_clicks'     => number_format((int)($gsc_overview['clicks']['value'] ?? 0)),
            'search_impressions'=> number_format((int)($gsc_overview['impressions']['value'] ?? 0)),
            'search_ctr'        => number_format((float)($gsc_overview['ctr']['value'] ?? 0), 1) . '%',
            'search_position'   => number_format((float)($gsc_overview['position']['value'] ?? 0), 1),
            'top_queries'       => implode(', ', array_filter($top_queries)) ?: 'No query data available',
            'tone'              => $profile['content_tone'] ?? 'professional',
        ];

        $result = AIEngine::generate('report_summary', $vars, $client['client_id'] ?? '');

        if ($result['success'] && !empty($result['content'])) {
            return nl2br(esc_html($result['content']));
        }

        // Fallback non-AI summary
        return '<p>This monthly report summarizes GA4 traffic and Google Search Console performance for ' .
               esc_html($client['company'] ?? $client['name'] ?? '') . ' during ' .
               esc_html($period['label'] ?? '') . '.</p>';
    }
}
