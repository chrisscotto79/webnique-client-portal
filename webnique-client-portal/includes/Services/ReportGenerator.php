<?php
/**
 * Report Generator
 *
 * Generates monthly analytics reports including GA4 and Google Search Console data.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

use WNQ\Models\SEOHub;
use WNQ\Models\Client;
use WNQ\Models\ClientPortal;
use WNQ\Models\AnalyticsConfig;
use WNQ\API\GoogleAnalytics;
use WNQ\API\GoogleSearchConsole;

final class ReportGenerator
{
    private static string $last_error = '';

    /**
     * Generate monthly report for a client
     */
    public static function generateMonthlyReport(string $client_id, string $month = ''): int|false
    {
        return self::generateReport($client_id, 'previous_month', $month);
    }

    /**
     * Generate an analytics report for a supported period.
     */
    public static function generateReport(string $client_id, string $period_key = 'previous_month', string $month = ''): int|false
    {
        self::$last_error = '';
        $period = self::resolveReportPeriod($period_key, $month);
        $period_start = $period['start'];
        $period_end = $period['end'];

        $analytics_config = AnalyticsConfig::getClientConfig($client_id);
        $client = self::resolveReportClient($client_id, $analytics_config);
        if (!$client) {
            self::$last_error = 'No matching client was found for report client ID "' . $client_id . '".';
            SEOHub::log('monthly_report_client_missing', [
                'client_id' => $client_id,
                'period' => $period_start . ' to ' . $period_end,
            ], 'failed');
            return false;
        }

        $profile = self::resolveReportProfile($client_id, $client, $analytics_config);
        $analytics_context = $analytics_config
            ? [
                'client_id' => (string)$analytics_config['client_id'],
                'config' => $analytics_config,
                'match_reason' => 'Selected Golden Web Marketing Portal Analytics client',
            ]
            : self::resolveAnalyticsContext($client_id, $client, $profile);
        $report_client_id = (string)($analytics_context['client_id'] ?? $client_id);
        $ads_client_id = self::resolveAdsClientId($client_id, $client, $analytics_config);

        // Gather all report data
        $report_data = [
            'client'         => self::getClientSummary($client, $profile),
            'analytics'      => self::getAnalyticsSummary($analytics_context, $period_start, $period_end),
            'google_ads'     => ClientPortal::getAdsReportData($ads_client_id, $period_start, $period_end),
            'period'         => ['start' => $period_start, 'end' => $period_end, 'label' => $period['label'], 'key' => $period['key']],
            'generated_at'   => current_time('mysql'),
        ];
        $report_status = self::resolveReportStatus($report_data);

        // AI-generated executive summary
        $summary_html = self::generateAISummary($client, $profile, $report_data);

        $report_id = SEOHub::createReport($report_client_id, $period['type'], $period_start, $period_end, $report_data, $summary_html, $report_status);

        if ($report_id) {
            SEOHub::log('monthly_report_generated', [
                'client_id' => $report_client_id,
                'entity_id' => $report_id,
                'period'    => $period_start . ' to ' . $period_end,
            ]);
        } else {
            global $wpdb;
            self::$last_error = $wpdb->last_error
                ? 'The report database insert failed: ' . $wpdb->last_error
                : 'The report database insert did not return a report ID.';
            SEOHub::log('monthly_report_insert_failed', [
                'client_id' => $report_client_id,
                'period' => $period_start . ' to ' . $period_end,
                'error' => self::$last_error,
            ], 'failed');
        }

        return $report_id;
    }

    public static function getLastError(): string
    {
        return self::$last_error;
    }

    /**
     * Generate reports for all active clients
     */
    public static function generateAllMonthlyReports(string $month = '', bool $send_email = false, bool $force_new = false, string $period_key = 'previous_month'): array
    {
        $clients = AnalyticsConfig::getAllClients();
        $period = self::resolveReportPeriod($period_key, $month);
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

            $existing = SEOHub::getReportForPeriod($client_id, $period['type'], $period['start'], $period['end']);
            if (!$force_new && $existing && ($existing['status'] ?? '') === 'sent') {
                $results['email_skipped']++;
                continue;
            }

            $id = (!$force_new && $existing) ? (int)$existing['id'] : self::generateReport($client_id, $period_key, $month);
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
        $pdf_attachment = self::writeReportPdfAttachment($report_id, $report);
        $attachments = $pdf_attachment ? [$pdf_attachment] : [];

        $sent = wp_mail($recipients, $subject, $body, $headers, $attachments);
        if ($pdf_attachment && file_exists($pdf_attachment)) {
            @unlink($pdf_attachment);
        }

        SEOHub::log($sent ? 'monthly_report_emailed' : 'monthly_report_email_failed', [
            'client_id' => $report['client_id'],
            'entity_id' => $report_id,
            'entity_type' => 'report',
            'recipients' => $recipients,
            'period' => $period,
            'pdf_attached' => !empty($pdf_attachment),
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
        $google_ads = $data['google_ads'] ?? [];
        $brand_logo_url = defined('WNQ_PORTAL_URL') ? WNQ_PORTAL_URL . 'assets/images/golden-web-marketing-logo-background.png' : '';

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
	  :root {
	    --gwm-black: #090806;
	    --gwm-ink: #1b1710;
	    --gwm-green: #18380f;
	    --gwm-gold: #d6a72a;
	    --gwm-gold-dark: #9b6a10;
	    --gwm-cream: #fff7df;
	    --gwm-soft: #f7f1e2;
	    --gwm-line: #eadfca;
	    --gwm-muted: #706756;
	  }
	  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: var(--gwm-ink); background: #eee7d8; padding: 24px; }
	  .report-wrapper { max-width: 1040px; margin: 0 auto; background: #fffaf0; padding: 0; border: 1px solid var(--gwm-line); border-radius: 8px; overflow: hidden; box-shadow: 0 24px 80px rgba(26, 19, 8, 0.18); }
	  .report-header { background: linear-gradient(135deg, #070604 0%, #171207 46%, #244414 100%); color: white; padding: 34px 40px 38px; position: relative; overflow: hidden; }
	  .report-header::after { content: ''; position: absolute; inset: auto 0 0 0; height: 5px; background: linear-gradient(90deg, #f7d86a, #b77b15, #f7d86a); }
	  .report-brand { position: relative; z-index: 1; display: flex; align-items: center; gap: 18px; margin-bottom: 30px; }
	  .brand-logo { width: 84px; height: 84px; object-fit: contain; border-radius: 999px; background: #050402; border: 1px solid rgba(247,216,106,.55); box-shadow: 0 12px 28px rgba(0,0,0,.3); }
	  .brand-text span { display: block; color: #f7d86a; font-size: 12px; font-weight: 800; letter-spacing: 0; text-transform: uppercase; margin-bottom: 5px; }
	  .brand-text strong { display: block; color: #fff7df; font-size: 22px; line-height: 1.1; }
	  .report-header h1 { position: relative; z-index: 1; font-size: 44px; line-height: 1.05; font-weight: 850; letter-spacing: 0; max-width: 760px; margin-bottom: 18px; color: #fffdf6; }
	  .report-header .subtitle { position: relative; z-index: 1; color: #f7d86a; font-size: 15px; font-weight: 750; text-transform: uppercase; letter-spacing: 0; margin-bottom: 16px; }
	  .report-meta-grid { position: relative; z-index: 1; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 18px; }
	  .report-meta-card { background: rgba(255, 247, 223, .08); border: 1px solid rgba(247,216,106,.22); border-radius: 8px; padding: 14px 16px; }
	  .report-meta-card span { display:block; color:#c9b98d; font-size:11px; text-transform:uppercase; letter-spacing:0; font-weight:800; margin-bottom:5px; }
	  .report-meta-card strong { display:block; color:#fff7df; font-size:16px; line-height:1.3; }
	  .section { padding: 34px 40px; border-bottom: 1px solid var(--gwm-line); background: #fffaf0; }
	  .section:nth-of-type(even) { background: #fffdf7; }
	  .section h2 { font-size: 22px; font-weight: 850; color: var(--gwm-green); margin-bottom: 18px; display: flex; align-items: center; gap: 10px; letter-spacing: 0; }
	  .section h2::before { content: ''; display: block; width: 6px; height: 24px; background: var(--gwm-gold); border-radius: 999px; box-shadow: 0 0 0 4px rgba(214,167,42,.14); }
	  .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
	  .metric-card { background: linear-gradient(180deg, #fffdf8 0%, #fff4d6 100%); border: 1px solid #ead39c; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 10px 24px rgba(119, 82, 12, .08); }
	  .metric-card .value { font-size: 34px; font-weight: 900; color: var(--gwm-green); letter-spacing: 0; }
	  .metric-card .label { font-size: 11px; color: var(--gwm-muted); margin-top: 6px; text-transform: uppercase; letter-spacing: 0; font-weight: 800; }
	  .metric-card.danger { background: #fef2f2; border-color: #fca5a5; }
	  .metric-card.danger .value { color: #dc2626; }
	  .metric-card.success { background: #f3faeb; border-color: #b8d89f; }
	  .metric-card.success .value { color: var(--gwm-green); }
	  .chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px; margin: 8px 0 28px; }
	  .chart-card { background: #fffdf8; border: 1px solid var(--gwm-line); border-radius: 8px; padding: 18px; box-shadow: 0 10px 30px rgba(65, 46, 11, 0.08); }
	  .chart-title { color: var(--gwm-green); font-size: 15px; font-weight: 850; margin-bottom: 4px; }
	  .chart-subtitle { color: var(--gwm-muted); font-size: 12px; margin-bottom: 14px; }
	  .chart-svg { width: 100%; height: auto; display: block; overflow: visible; }
	  .chart-axis-label { fill: #74664f; font-size: 11px; }
	  .chart-grid-line { stroke: #eadfca; stroke-width: 1; }
	  .chart-point { stroke: #ffffff; stroke-width: 2; }
	  .bar-list { display: flex; flex-direction: column; gap: 12px; }
	  .bar-row { display: flex; flex-direction: column; gap: 6px; }
	  .bar-meta { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; font-size: 12px; }
	  .bar-label { color: var(--gwm-ink); font-weight: 750; line-height: 1.35; }
	  .bar-value { color: var(--gwm-muted); white-space: nowrap; }
	  .bar-track { width: 100%; height: 10px; background: #efe4cf; border-radius: 999px; overflow: hidden; }
	  .bar-fill { display: block; height: 100%; min-width: 4px; border-radius: 999px; }
	  .dual-bars { display: grid; grid-template-columns: 1fr; gap: 5px; }
	  .legend { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 14px; color: var(--gwm-muted); font-size: 12px; }
	  .legend-item { display: inline-flex; align-items: center; gap: 6px; }
	  .legend-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
	  .table-wrap { overflow-x: auto; }
	  table { width: 100%; border-collapse: collapse; font-size: 14px; }
	  th { background: #f4ead4; text-align: left; padding: 11px 12px; font-weight: 800; color: #3b321f; font-size: 12px; text-transform: uppercase; letter-spacing: 0; border-bottom: 1px solid #e3d2ae; }
	  td { padding: 11px 12px; border-bottom: 1px solid #f1e8d6; color: #3a3326; }
	  tr:hover td { background: #fff5db; }
	  .ai-summary { background: #fff4d6; border: 1px solid #ead39c; border-left: 5px solid var(--gwm-gold); padding: 22px 24px; border-radius: 8px; font-size: 15px; line-height: 1.7; color: #302817; }
	  .note { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 8px; padding: 14px 16px; font-size: 14px; line-height: 1.6; }
	  .report-footer { padding: 26px 40px; background: #090806; text-align: center; font-size: 12px; color: #c9b98d; border-top: 4px solid var(--gwm-gold); }
	  @media print {
	    body { background: white; padding: 0; }
	    .report-wrapper { box-shadow: none; border-radius: 0; }
	    .section { page-break-inside: avoid; }
	  }
	  @media (max-width: 720px) {
	    body { padding: 0; }
	    .report-wrapper { border-radius: 0; }
	    .report-header, .section, .report-footer { padding-left: 22px; padding-right: 22px; }
	    .report-header h1 { font-size: 34px; }
	    .report-meta-grid { grid-template-columns: 1fr; }
	  }
	</style>
	</head>
	<body>
	<div class="report-wrapper">

	  <div class="report-header">
	    <div class="report-brand">
	      <?php if ($brand_logo_url !== ''): ?>
	      <img class="brand-logo" src="<?php echo esc_url($brand_logo_url); ?>" alt="Golden Web Marketing">
	      <?php endif; ?>
	      <div class="brand-text">
	        <span>Golden Web Marketing</span>
	        <strong>SEO OS</strong>
	      </div>
	    </div>
	    <div class="subtitle">Client Performance Report</div>
	    <h1>Analytics Performance Report</h1>
	    <div class="report-meta-grid">
	      <div class="report-meta-card">
	        <span>Client</span>
	        <strong><?php echo esc_html($client['name'] ?? ''); ?></strong>
	      </div>
	      <div class="report-meta-card">
	        <span>Period</span>
	        <strong><?php echo esc_html($period['label'] ?? ''); ?></strong>
	      </div>
	      <div class="report-meta-card">
	        <span>Date Range</span>
	        <strong><?php echo esc_html($period['start'] ?? ''); ?> – <?php echo esc_html($period['end'] ?? ''); ?></strong>
	      </div>
	    </div>
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

  <?php if (!empty($google_ads['configured'])): ?>
  <?php
    $ads_summary = $google_ads['summary'] ?? [];
    $ads_campaigns = array_slice((array)($google_ads['campaigns'] ?? []), 0, 8);
    $ads_currency = (string)($google_ads['currency_code'] ?? 'USD');
  ?>
  <div class="section">
    <h2>Google Ads Performance</h2>
    <div class="metric-grid">
      <div class="metric-card">
        <div class="value"><?php echo esc_html(self::formatCurrency((float)($ads_summary['spend'] ?? 0), $ads_currency)); ?></div>
        <div class="label">Ad Spend</div>
      </div>
      <div class="metric-card">
        <div class="value"><?php echo number_format((int)($ads_summary['clicks'] ?? 0)); ?></div>
        <div class="label">Clicks</div>
      </div>
      <div class="metric-card">
        <div class="value"><?php echo number_format((int)($ads_summary['impressions'] ?? 0)); ?></div>
        <div class="label">Impressions</div>
      </div>
      <div class="metric-card">
        <div class="value"><?php echo number_format(((float)($ads_summary['ctr'] ?? 0)) * 100, 1); ?>%</div>
        <div class="label">Click-through Rate</div>
      </div>
      <div class="metric-card success">
        <div class="value"><?php echo number_format((float)($ads_summary['conversions'] ?? 0), 1); ?></div>
        <div class="label">Conversions</div>
      </div>
      <div class="metric-card">
        <div class="value"><?php echo esc_html(self::formatCurrency((float)($ads_summary['cost_per_conversion'] ?? 0), $ads_currency)); ?></div>
        <div class="label">Cost per Conversion</div>
      </div>
    </div>

    <?php if (!empty($ads_campaigns)): ?>
    <h3 style="font-size:16px;color:#374151;margin:6px 0 12px;">Campaign Performance</h3>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Campaign</th><th>Status</th><th>Spend</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Conversions</th></tr></thead>
        <tbody>
          <?php foreach ($ads_campaigns as $campaign): ?>
          <tr>
            <td><?php echo esc_html($campaign['name'] ?? 'Campaign'); ?></td>
            <td><?php echo esc_html(ucfirst((string)($campaign['status'] ?? 'unknown'))); ?></td>
            <td><?php echo esc_html(self::formatCurrency((float)($campaign['spend'] ?? 0), $ads_currency)); ?></td>
            <td><?php echo number_format((int)($campaign['clicks'] ?? 0)); ?></td>
            <td><?php echo number_format((int)($campaign['impressions'] ?? 0)); ?></td>
            <td><?php echo number_format(((float)($campaign['ctr'] ?? 0)) * 100, 1); ?>%</td>
            <td><?php echo number_format((float)($campaign['conversions'] ?? 0), 1); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="note">The Google Ads account was connected, with no recorded campaign activity during this reporting period.</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="report-footer">
    Generated by Golden Web Marketing SEO Operating System &bull; <?php echo date('F j, Y'); ?> &bull; Confidential
  </div>
</div>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Render report as a dependency-free PDF download.
     */
    public static function renderReportPDF(int $report_id): string
    {
        $report = SEOHub::getReport($report_id);
        if (!$report) return '';

        $data = is_array($report['report_data'] ?? null) ? $report['report_data'] : [];
        $period = $data['period'] ?? [];
        $client = $data['client'] ?? [];
        $analytics = $data['analytics'] ?? [];
        $search_console = $analytics['search_console'] ?? [];
        $google_ads = $data['google_ads'] ?? [];

        $pdf = self::pdfCreateContext();
        self::pdfStartPage($pdf);

        self::pdfRect($pdf, 0, 0, 612, 118, [9, 8, 6]);
        self::pdfRect($pdf, 0, 112, 612, 6, [214, 167, 42]);
        self::pdfText($pdf, 'Golden Web Marketing SEO OS', 48, 24, 10, [247, 216, 106], true);
        self::pdfText($pdf, 'Analytics Performance Report', 48, 48, 24, [255, 247, 223], true);
        self::pdfText($pdf, (string)($client['name'] ?? 'Client') . '  |  ' . (string)($period['label'] ?? ''), 48, 78, 11, [234, 223, 202]);
        self::pdfText($pdf, 'Reporting Period: ' . (string)($period['start'] ?? '') . ' - ' . (string)($period['end'] ?? ''), 48, 96, 10, [201, 185, 141]);
        $pdf['y'] = 142;

        if (!empty($report['summary_html'])) {
            self::pdfSectionTitle($pdf, 'Executive Summary');
            self::pdfParagraph($pdf, self::pdfHtmlToText((string)$report['summary_html']), 10.5, 516, 16);
            $pdf['y'] += 8;
        }

        self::pdfSectionTitle($pdf, 'GA4 Analytics');
        if (!empty($analytics['configured'])) {
            $overview = $analytics['overview'] ?? [];
            $key_events = $analytics['key_events'] ?? [];
            $traffic_sources = $analytics['traffic_sources'] ?? [];
            $top_pages = $analytics['top_pages'] ?? [];
            $visitor_trends = $analytics['visitors_over_time'] ?? [];

            self::pdfMetricCards($pdf, [
                ['label' => 'Visitors', 'value' => number_format((int)($overview['total_users'] ?? 0)), 'color' => [24, 56, 15]],
                ['label' => 'Sessions', 'value' => number_format((int)($overview['sessions'] ?? 0)), 'color' => [155, 106, 16]],
                ['label' => 'Page Views', 'value' => number_format((int)($overview['page_views'] ?? 0)), 'color' => [24, 56, 15]],
                ['label' => 'Bounce Rate', 'value' => number_format((float)($overview['bounce_rate'] ?? 0), 1) . '%', 'color' => [183, 123, 21]],
                ['label' => 'Key Events', 'value' => number_format((int)($analytics['total_key_events'] ?? 0)), 'color' => [22, 101, 52]],
            ]);

            self::pdfLineChart($pdf, $visitor_trends, 'Visitors Over Time', 'users', [183, 123, 21]);
            self::pdfBarChart($pdf, $traffic_sources, 'channel', 'sessions', 'Sessions by Channel', [24, 56, 15]);
            self::pdfBarChart($pdf, $top_pages, 'title', 'views', 'Top Pages by Views', [214, 167, 42]);
            self::pdfBarChart($pdf, $key_events, 'label', 'count', 'Key Events', [22, 163, 74]);
        } else {
            self::pdfNote($pdf, 'GA4 analytics data was not available for this client during report generation. ' . (string)($analytics['error'] ?? ''));
        }

        self::pdfSectionTitle($pdf, 'Google Search Console');
        if (!empty($search_console['configured'])) {
            $gsc_overview = $search_console['overview'] ?? [];
            $gsc_keywords = $search_console['top_keywords'] ?? [];
            $gsc_pages = $search_console['top_pages'] ?? [];
            $gsc_trends = $search_console['performance_over_time'] ?? [];

            self::pdfMetricCards($pdf, [
                ['label' => 'Organic Clicks', 'value' => number_format((int)($gsc_overview['clicks']['value'] ?? 0)), 'color' => [24, 56, 15]],
                ['label' => 'Impressions', 'value' => number_format((int)($gsc_overview['impressions']['value'] ?? 0)), 'color' => [214, 167, 42]],
                ['label' => 'Average CTR', 'value' => number_format((float)($gsc_overview['ctr']['value'] ?? 0), 1) . '%', 'color' => [22, 163, 74]],
                ['label' => 'Avg Position', 'value' => number_format((float)($gsc_overview['position']['value'] ?? 0), 1), 'color' => [183, 123, 21]],
            ]);

            self::pdfLineChart($pdf, $gsc_trends, 'Organic Clicks Over Time', 'clicks', [24, 56, 15]);
            self::pdfLineChart($pdf, $gsc_trends, 'Search Impressions Over Time', 'impressions', [214, 167, 42]);
            self::pdfDualBarChart($pdf, $gsc_keywords, 'keyword', 'clicks', 'impressions', 'Top Search Queries', 'Clicks', 'Impressions');
            self::pdfDualBarChart($pdf, $gsc_pages, 'page', 'clicks', 'impressions', 'Top Search Pages', 'Clicks', 'Impressions');
        } else {
            self::pdfNote($pdf, 'Google Search Console data was not available for this client during report generation. ' . (string)($search_console['error'] ?? ''));
        }

        if (!empty($google_ads['configured'])) {
            $ads_summary = $google_ads['summary'] ?? [];
            $ads_campaigns = (array)($google_ads['campaigns'] ?? []);
            $ads_currency = (string)($google_ads['currency_code'] ?? 'USD');
            self::pdfSectionTitle($pdf, 'Google Ads Performance');
            self::pdfMetricCards($pdf, [
                ['label' => 'Ad Spend', 'value' => self::formatCurrency((float)($ads_summary['spend'] ?? 0), $ads_currency), 'color' => [155, 106, 16]],
                ['label' => 'Clicks', 'value' => number_format((int)($ads_summary['clicks'] ?? 0)), 'color' => [24, 56, 15]],
                ['label' => 'Impressions', 'value' => number_format((int)($ads_summary['impressions'] ?? 0)), 'color' => [214, 167, 42]],
                ['label' => 'CTR', 'value' => number_format(((float)($ads_summary['ctr'] ?? 0)) * 100, 1) . '%', 'color' => [22, 101, 52]],
                ['label' => 'Conversions', 'value' => number_format((float)($ads_summary['conversions'] ?? 0), 1), 'color' => [24, 56, 15]],
                ['label' => 'Cost / Conversion', 'value' => self::formatCurrency((float)($ads_summary['cost_per_conversion'] ?? 0), $ads_currency), 'color' => [183, 123, 21]],
            ]);
            self::pdfBarChart($pdf, $ads_campaigns, 'name', 'clicks', 'Campaign Clicks', [24, 56, 15]);
            self::pdfBarChart($pdf, $ads_campaigns, 'name', 'conversions', 'Campaign Conversions', [214, 167, 42]);
            if (empty($ads_campaigns)) {
                self::pdfNote($pdf, 'The Google Ads account was connected, with no recorded campaign activity during this reporting period.');
            }
        }

        self::pdfNote($pdf, 'Exact table data is available in the web report view. This PDF is formatted for quick client review and easy sharing.');
        self::pdfClosePage($pdf);

        return self::pdfBuildDocument($pdf['pages']);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private static function pdfCreateContext(): array
    {
        return [
            'pages' => [],
            'stream' => '',
            'y' => 48,
            'width' => 612,
            'height' => 792,
            'margin' => 48,
            'bottom' => 54,
        ];
    }

    private static function pdfStartPage(array &$pdf): void
    {
        if (!empty($pdf['stream'])) {
            self::pdfClosePage($pdf);
        }

        $pdf['stream'] = '';
        $pdf['y'] = 48;
    }

    private static function pdfClosePage(array &$pdf): void
    {
        if (!isset($pdf['stream'])) {
            return;
        }

        self::pdfText($pdf, 'Generated by Golden Web Marketing SEO Operating System | ' . date('F j, Y') . ' | Confidential', 48, 760, 8, [112, 103, 86]);
        $pdf['pages'][] = $pdf['stream'];
        $pdf['stream'] = '';
    }

    private static function pdfEnsureSpace(array &$pdf, float $height): void
    {
        if (($pdf['y'] + $height) > ($pdf['height'] - $pdf['bottom'])) {
            self::pdfStartPage($pdf);
        }
    }

    private static function pdfSectionTitle(array &$pdf, string $title): void
    {
        self::pdfEnsureSpace($pdf, 36);
        self::pdfRect($pdf, 48, $pdf['y'] + 3, 4, 18, [214, 167, 42]);
        self::pdfText($pdf, $title, 60, $pdf['y'], 17, [24, 56, 15], true);
        $pdf['y'] += 34;
    }

    private static function pdfMetricCards(array &$pdf, array $cards): void
    {
        $columns = 3;
        $gap = 12;
        $card_width = (516 - ($gap * ($columns - 1))) / $columns;
        $card_height = 66;

        foreach (array_chunk($cards, $columns) as $row) {
            self::pdfEnsureSpace($pdf, $card_height + 14);
            $x = 48;
            foreach ($row as $card) {
                $color = $card['color'] ?? [13, 83, 158];
                self::pdfRect($pdf, $x, $pdf['y'], $card_width, $card_height, [255, 247, 223], [234, 211, 156]);
                self::pdfText($pdf, (string)($card['value'] ?? '0'), $x + 14, $pdf['y'] + 15, 20, $color, true);
                self::pdfText($pdf, strtoupper((string)($card['label'] ?? 'Metric')), $x + 14, $pdf['y'] + 45, 8, [112, 103, 86], true);
                $x += $card_width + $gap;
            }
            $pdf['y'] += $card_height + 14;
        }
    }

    private static function pdfLineChart(array &$pdf, array $rows, string $title, string $metric_key, array $color): void
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
            return;
        }

        $all_values = array_map(fn($point) => (float)$point['value'], $series);
        $peak = max($all_values);
        $total = array_sum($all_values);
        $series = self::sampleChartSeries($series, 16);
        $max_value = max(1, (float)ceil($peak * 1.15));

        $card_height = 182;
        self::pdfEnsureSpace($pdf, $card_height + 18);

        $x = 48;
        $y = $pdf['y'];
        self::pdfRect($pdf, $x, $y, 516, $card_height, [255, 255, 255], [226, 232, 240]);
        self::pdfText($pdf, $title, $x + 16, $y + 16, 12, [30, 58, 95], true);
        self::pdfText($pdf, 'Total ' . self::formatChartValue((float)$total) . ' | Peak ' . self::formatChartValue((float)$peak), $x + 16, $y + 34, 8.5, [100, 116, 139]);

        $chart_x = $x + 46;
        $chart_y = $y + 56;
        $chart_w = 444;
        $chart_h = 88;
        $baseline = $chart_y + $chart_h;

        foreach ([1, 0.5, 0] as $scale) {
            $line_y = $baseline - ($scale * $chart_h);
            self::pdfLine($pdf, $chart_x, $line_y, $chart_x + $chart_w, $line_y, [226, 232, 240], 0.5);
            self::pdfText($pdf, self::formatChartValue($max_value * $scale), $x + 14, $line_y - 6, 7, [100, 116, 139]);
        }

        $points = [];
        $count = count($series);
        foreach ($series as $index => $point) {
            $px = $count === 1 ? $chart_x + ($chart_w / 2) : $chart_x + (($chart_w / max(1, $count - 1)) * $index);
            $py = $baseline - (((float)$point['value'] / $max_value) * $chart_h);
            $points[] = [$px, $py];
        }

        for ($i = 1; $i < count($points); $i++) {
            self::pdfLine($pdf, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $color, 2.2);
        }
        foreach ($points as $point) {
            self::pdfCircle($pdf, $point[0], $point[1], 2.2, $color);
        }

        $last_index = max(0, $count - 1);
        $mid_index = (int)floor($last_index / 2);
        self::pdfText($pdf, (string)($series[0]['label'] ?? ''), $chart_x, $baseline + 13, 7.5, [100, 116, 139]);
        self::pdfText($pdf, (string)($series[$mid_index]['label'] ?? ''), $chart_x + ($chart_w / 2) - 16, $baseline + 13, 7.5, [100, 116, 139]);
        self::pdfText($pdf, (string)($series[$last_index]['label'] ?? ''), $chart_x + $chart_w - 30, $baseline + 13, 7.5, [100, 116, 139]);

        $pdf['y'] += $card_height + 18;
    }

    private static function pdfBarChart(array &$pdf, array $rows, string $label_key, string $value_key, string $title, array $color, int $limit = 6): void
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
            $items[] = ['label' => self::resolveChartLabel($row, $label_key), 'value' => $value];
        }

        if (empty($items)) {
            return;
        }

        usort($items, fn($a, $b) => $b['value'] <=> $a['value']);
        $items = array_slice($items, 0, $limit);
        $max_value = max(1, max(array_column($items, 'value')));
        $card_height = 58 + (count($items) * 31);
        self::pdfEnsureSpace($pdf, $card_height + 16);

        $x = 48;
        $y = $pdf['y'];
        self::pdfRect($pdf, $x, $y, 516, $card_height, [255, 255, 255], [226, 232, 240]);
        self::pdfText($pdf, $title, $x + 16, $y + 16, 12, [30, 58, 95], true);
        self::pdfText($pdf, 'Top ' . count($items) . ' by volume', $x + 16, $y + 34, 8.5, [100, 116, 139]);

        $bar_x = $x + 210;
        $bar_w = 280;
        $row_y = $y + 58;
        foreach ($items as $item) {
            $width = ($item['value'] / $max_value) * $bar_w;
            self::pdfText($pdf, self::shortChartLabel((string)$item['label'], 38), $x + 16, $row_y - 2, 8.2, [51, 65, 85], true);
            self::pdfText($pdf, self::formatChartValue((float)$item['value']), $bar_x + $bar_w - 40, $row_y - 2, 8, [100, 116, 139]);
            self::pdfRect($pdf, $bar_x, $row_y + 8, $bar_w, 7, [237, 242, 247]);
            self::pdfRect($pdf, $bar_x, $row_y + 8, max(4, $width), 7, $color);
            $row_y += 31;
        }

        $pdf['y'] += $card_height + 16;
    }

    private static function pdfDualBarChart(array &$pdf, array $rows, string $label_key, string $primary_key, string $secondary_key, string $title, string $primary_label, string $secondary_label, int $limit = 6): void
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
            $items[] = ['label' => self::resolveChartLabel($row, $label_key), 'primary' => $primary, 'secondary' => $secondary];
        }

        if (empty($items)) {
            return;
        }

        usort($items, fn($a, $b) => (($b['primary'] <=> $a['primary']) ?: ($b['secondary'] <=> $a['secondary'])));
        $items = array_slice($items, 0, $limit);
        $max_primary = max(1, max(array_column($items, 'primary')));
        $max_secondary = max(1, max(array_column($items, 'secondary')));
        $card_height = 72 + (count($items) * 38);
        self::pdfEnsureSpace($pdf, $card_height + 16);

        $x = 48;
        $y = $pdf['y'];
        self::pdfRect($pdf, $x, $y, 516, $card_height, [255, 255, 255], [226, 232, 240]);
        self::pdfText($pdf, $title, $x + 16, $y + 16, 12, [30, 58, 95], true);
        self::pdfText($pdf, $primary_label . ' and ' . $secondary_label, $x + 16, $y + 34, 8.5, [100, 116, 139]);
        self::pdfRect($pdf, $x + 376, $y + 21, 8, 8, [37, 99, 235]);
        self::pdfText($pdf, $primary_label, $x + 388, $y + 18, 7.5, [100, 116, 139]);
        self::pdfRect($pdf, $x + 438, $y + 21, 8, 8, [124, 58, 237]);
        self::pdfText($pdf, $secondary_label, $x + 450, $y + 18, 7.5, [100, 116, 139]);

        $bar_x = $x + 210;
        $bar_w = 280;
        $row_y = $y + 64;
        foreach ($items as $item) {
            $primary_w = ($item['primary'] / $max_primary) * $bar_w;
            $secondary_w = ($item['secondary'] / $max_secondary) * $bar_w;
            self::pdfText($pdf, self::shortChartLabel((string)$item['label'], 34), $x + 16, $row_y, 8, [51, 65, 85], true);
            self::pdfText($pdf, self::formatChartValue((float)$item['primary']) . ' / ' . self::formatChartValue((float)$item['secondary']), $bar_x + $bar_w - 54, $row_y, 7.5, [100, 116, 139]);
            self::pdfRect($pdf, $bar_x, $row_y + 10, $bar_w, 6, [237, 242, 247]);
            self::pdfRect($pdf, $bar_x, $row_y + 10, max(4, $primary_w), 6, [37, 99, 235]);
            self::pdfRect($pdf, $bar_x, $row_y + 20, $bar_w, 6, [237, 242, 247]);
            self::pdfRect($pdf, $bar_x, $row_y + 20, max(4, $secondary_w), 6, [124, 58, 237]);
            $row_y += 38;
        }

        $pdf['y'] += $card_height + 16;
    }

    private static function pdfNote(array &$pdf, string $message): void
    {
        $text = trim(self::pdfCleanText($message));
        if ($text === '') {
            return;
        }

        $lines = self::pdfWrapText($text, 10, 480);
        $height = 28 + (count($lines) * 14);
        self::pdfEnsureSpace($pdf, $height + 8);
        self::pdfRect($pdf, 48, $pdf['y'], 516, $height, [255, 251, 235], [253, 230, 138]);
        $y = $pdf['y'] + 16;
        foreach ($lines as $line) {
            self::pdfText($pdf, $line, 64, $y, 9.5, [146, 64, 14]);
            $y += 14;
        }
        $pdf['y'] += $height + 12;
    }

    private static function pdfParagraph(array &$pdf, string $text, float $font_size, float $width, float $line_height): void
    {
        $paragraphs = preg_split('/\n{2,}/', trim(self::pdfCleanText($text))) ?: [];
        foreach ($paragraphs as $paragraph) {
            $lines = self::pdfWrapText($paragraph, $font_size, $width);
            foreach ($lines as $line) {
                self::pdfEnsureSpace($pdf, $line_height + 4);
                self::pdfText($pdf, $line, 48, $pdf['y'], $font_size, [31, 41, 55]);
                $pdf['y'] += $line_height;
            }
            $pdf['y'] += 6;
        }
    }

    private static function pdfWrapText(string $text, float $font_size, float $width): array
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?: '';
        if ($text === '') {
            return [];
        }

        $chars = max(24, (int)floor($width / ($font_size * 0.52)));
        return explode("\n", wordwrap($text, $chars, "\n", true));
    }

    private static function pdfHtmlToText(string $html): string
    {
        $html = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n\n", $html);
        return wp_strip_all_tags($html);
    }

    private static function pdfText(array &$pdf, string $text, float $x, float $top_y, float $size, array $rgb, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $y = $pdf['height'] - $top_y - $size;
        $pdf['stream'] .= self::pdfColor($rgb, 'rg') . sprintf(" BT /%s %s Tf 1 0 0 1 %s %s Tm (%s) Tj ET\n",
            $font,
            self::pdfNum($size),
            self::pdfNum($x),
            self::pdfNum($y),
            self::pdfEscape(self::pdfCleanText($text))
        );
    }

    private static function pdfRect(array &$pdf, float $x, float $top_y, float $width, float $height, array $fill_rgb, ?array $stroke_rgb = null): void
    {
        $y = $pdf['height'] - $top_y - $height;
        if ($stroke_rgb) {
            $pdf['stream'] .= 'q ' . self::pdfColor($fill_rgb, 'rg') . ' ' . self::pdfColor($stroke_rgb, 'RG') . ' 1 w ' .
                self::pdfNum($x) . ' ' . self::pdfNum($y) . ' ' . self::pdfNum($width) . ' ' . self::pdfNum($height) . " re B Q\n";
            return;
        }

        $pdf['stream'] .= 'q ' . self::pdfColor($fill_rgb, 'rg') . ' ' . self::pdfNum($x) . ' ' . self::pdfNum($y) . ' ' .
            self::pdfNum($width) . ' ' . self::pdfNum($height) . " re f Q\n";
    }

    private static function pdfLine(array &$pdf, float $x1, float $top_y1, float $x2, float $top_y2, array $rgb, float $width = 1): void
    {
        $y1 = $pdf['height'] - $top_y1;
        $y2 = $pdf['height'] - $top_y2;
        $pdf['stream'] .= 'q ' . self::pdfColor($rgb, 'RG') . ' ' . self::pdfNum($width) . ' w ' .
            self::pdfNum($x1) . ' ' . self::pdfNum($y1) . ' m ' . self::pdfNum($x2) . ' ' . self::pdfNum($y2) . " l S Q\n";
    }

    private static function pdfCircle(array &$pdf, float $x, float $top_y, float $radius, array $rgb): void
    {
        $y = $pdf['height'] - $top_y;
        $k = 0.5522847498;
        $c = $radius * $k;
        $pdf['stream'] .= 'q ' . self::pdfColor($rgb, 'rg') . ' ' .
            self::pdfNum($x + $radius) . ' ' . self::pdfNum($y) . ' m ' .
            self::pdfNum($x + $radius) . ' ' . self::pdfNum($y + $c) . ' ' . self::pdfNum($x + $c) . ' ' . self::pdfNum($y + $radius) . ' ' . self::pdfNum($x) . ' ' . self::pdfNum($y + $radius) . ' c ' .
            self::pdfNum($x - $c) . ' ' . self::pdfNum($y + $radius) . ' ' . self::pdfNum($x - $radius) . ' ' . self::pdfNum($y + $c) . ' ' . self::pdfNum($x - $radius) . ' ' . self::pdfNum($y) . ' c ' .
            self::pdfNum($x - $radius) . ' ' . self::pdfNum($y - $c) . ' ' . self::pdfNum($x - $c) . ' ' . self::pdfNum($y - $radius) . ' ' . self::pdfNum($x) . ' ' . self::pdfNum($y - $radius) . ' c ' .
            self::pdfNum($x + $c) . ' ' . self::pdfNum($y - $radius) . ' ' . self::pdfNum($x + $radius) . ' ' . self::pdfNum($y - $c) . ' ' . self::pdfNum($x + $radius) . ' ' . self::pdfNum($y) . " c f Q\n";
    }

    private static function pdfBuildDocument(array $pages): string
    {
        if (empty($pages)) {
            return '';
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        $page_refs = [];
        $next_id = 5;
        foreach ($pages as $stream) {
            $page_id = $next_id++;
            $content_id = $next_id++;
            $page_refs[] = $page_id . ' 0 R';
            $objects[$page_id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
            $objects[$content_id] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $page_refs) . '] /Count ' . count($page_refs) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xC2\xA5\xC2\xB1\xC3\xAB\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref_offset = strlen($pdf);
        $max_id = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max_id + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max_id; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        $pdf .= "trailer\n<< /Size " . ($max_id + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF";
        return $pdf;
    }

    private static function pdfCleanText(string $text): string
    {
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $replacements = [
            "\xE2\x80\x93" => '-',
            "\xE2\x80\x94" => '-',
            "\xE2\x80\x98" => "'",
            "\xE2\x80\x99" => "'",
            "\xE2\x80\x9C" => '"',
            "\xE2\x80\x9D" => '"',
            "\xE2\x80\xA2" => '-',
            "\xC2\xA0" => ' ',
        ];
        $text = str_replace(array_keys($replacements), array_values($replacements), $text);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text) ?: '';
    }

    private static function pdfEscape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private static function pdfColor(array $rgb, string $operator): string
    {
        return self::pdfNum(((float)($rgb[0] ?? 0)) / 255) . ' ' .
               self::pdfNum(((float)($rgb[1] ?? 0)) / 255) . ' ' .
               self::pdfNum(((float)($rgb[2] ?? 0)) / 255) . ' ' . $operator;
    }

    private static function pdfNum(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

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

    public static function resolveReportPeriod(string $period_key = 'previous_month', string $month = ''): array
    {
        $period_key = sanitize_key($period_key);
        $now = function_exists('current_time') ? (int)current_time('timestamp') : time();

        if ($period_key === 'current_month') {
            $period_start = date('Y-m-01', $now);

            return [
                'start' => $period_start,
                'end' => date('Y-m-d', $now),
                'label' => function_exists('date_i18n') ? date_i18n('F Y', $now) : date('F Y', $now),
                'key' => 'current_month',
                'type' => 'monthly',
            ];
        }

        if ($period_key === 'last_30_days') {
            return [
                'start' => date('Y-m-d', strtotime('-30 days', $now)),
                'end' => date('Y-m-d', $now),
                'label' => 'Last 30 Days',
                'key' => 'last_30_days',
                'type' => 'last_30_days',
            ];
        }

        return self::resolveMonthlyPeriod($month);
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
            'key' => 'previous_month',
            'type' => 'monthly',
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
            $source = self::buildGscSourceSummary($config, $overview, $keywords, $gsc->getErrors(), $gsc->getActivePropertyUrl());

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

    private static function buildGscSourceSummary(array $config, array $overview, array $keywords, array $errors, string $resolved_property = ''): array
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
            'resolved_property' => $resolved_property,
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

    private static function resolveAdsClientId(string $client_id, array $client, ?array $analytics_config): string
    {
        $direct_client = Client::getByClientId($client_id);
        if ($direct_client && !empty($direct_client['client_id'])) {
            return (string)$direct_client['client_id'];
        }

        if ($analytics_config) {
            $matched_client = self::findPortalClientForAnalyticsConfig($analytics_config);
            if ($matched_client && !empty($matched_client['client_id'])) {
                return (string)$matched_client['client_id'];
            }
        }

        return (string)($client['client_id'] ?? $client_id);
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

    private static function writeReportPdfAttachment(int $report_id, array $report): string
    {
        $pdf = self::renderReportPDF($report_id);
        if ($pdf === '') {
            return '';
        }

        $uploads = wp_upload_dir();
        $base_dir = $uploads['basedir'] ?? '';
        if (!$base_dir) {
            return '';
        }

        $dir = trailingslashit($base_dir) . 'wnq-report-pdfs';
        if (!wp_mkdir_p($dir)) {
            return '';
        }

        $generated = !empty($report['generated_at']) ? strtotime((string)$report['generated_at']) : time();
        $filename = sanitize_file_name(
            'seo-report-' . ($report['client_id'] ?? 'client') . '-' . date('Y-m-d-His', $generated) . '-id-' . $report_id . '.pdf'
        );
        $path = trailingslashit($dir) . $filename;

        $written = file_put_contents($path, $pdf);
        return $written === false ? '' : $path;
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

    private static function formatCurrency(float $amount, string $currency = 'USD'): string
    {
        $currency = strtoupper(trim($currency)) ?: 'USD';
        $prefix = $currency === 'USD' ? '$' : $currency . ' ';
        return $prefix . number_format($amount, 2);
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
        $comparison_text = $change >= 0
            ? 'an increase of ' . number_format(abs($change), 1) . '%'
            : number_format(abs($change), 1) . '% lower';

        return number_format($current) . ' visitors, ' . $comparison_text . ' than the previous period';
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
        $google_ads = $data['google_ads'] ?? [];
        $ads_summary = $google_ads['summary'] ?? [];
        $ads_campaigns = array_slice(array_column((array)($google_ads['campaigns'] ?? []), 'name'), 0, 5);
        $ads_currency = (string)($google_ads['currency_code'] ?? 'USD');
        $period  = $data['period'] ?? [];

        $vars = [
            'client_name'       => ($client['company'] ?? '') ?: ($client['name'] ?? ''),
            'period'            => $period['label'] ?? '',
            'analytics_available'=> !empty($analytics['configured']) ? 'Yes' : 'No',
            'traffic_change'    => self::formatTrafficChange($overview, $comparison),
            'visitors'          => number_format((int)($overview['total_users'] ?? 0)),
            'sessions'          => number_format((int)($overview['sessions'] ?? 0)),
            'page_views'        => number_format((int)($overview['page_views'] ?? 0)),
            'bounce_rate'       => number_format((float)($overview['bounce_rate'] ?? 0), 1) . '%',
            'key_events'        => number_format((int)($analytics['total_key_events'] ?? 0)),
            'search_available'  => !empty($gsc['configured']) ? 'Yes' : 'No',
            'search_clicks'     => number_format((int)($gsc_overview['clicks']['value'] ?? 0)),
            'search_impressions'=> number_format((int)($gsc_overview['impressions']['value'] ?? 0)),
            'search_ctr'        => number_format((float)($gsc_overview['ctr']['value'] ?? 0), 1) . '%',
            'search_position'   => number_format((float)($gsc_overview['position']['value'] ?? 0), 1),
            'top_queries'       => implode(', ', array_filter($top_queries)) ?: 'No query data available',
            'ads_available'     => !empty($google_ads['configured']) ? 'Yes' : 'No',
            'ads_spend'         => self::formatCurrency((float)($ads_summary['spend'] ?? 0), $ads_currency),
            'ads_clicks'        => number_format((int)($ads_summary['clicks'] ?? 0)),
            'ads_impressions'   => number_format((int)($ads_summary['impressions'] ?? 0)),
            'ads_ctr'           => number_format(((float)($ads_summary['ctr'] ?? 0)) * 100, 1) . '%',
            'ads_conversions'   => number_format((float)($ads_summary['conversions'] ?? 0), 1),
            'ads_cost_per_conversion' => self::formatCurrency((float)($ads_summary['cost_per_conversion'] ?? 0), $ads_currency),
            'ads_campaigns'     => implode(', ', array_filter($ads_campaigns)) ?: 'No campaign activity recorded',
            'tone'              => $profile['content_tone'] ?? 'professional',
        ];

        $result = AIEngine::generate('report_summary', $vars, $client['client_id'] ?? '');

        if ($result['success'] && !empty($result['content'])) {
            return nl2br(esc_html($result['content']));
        }

        $client_name = esc_html($client['company'] ?? $client['name'] ?? 'the client');
        $period_label = esc_html($period['label'] ?? 'this reporting period');
        $paragraphs = [];
        if (!empty($analytics['configured'])) {
            $paragraphs[] = sprintf(
                '<p>During %s, %s recorded %s visitors, %s sessions, and %s page views. Compared with the prior period, the traffic snapshot was %s. Website activity can vary from month to month, and these figures provide a clear, factual view of current engagement.</p>',
                $period_label,
                $client_name,
                esc_html($vars['visitors']),
                esc_html($vars['sessions']),
                esc_html($vars['page_views']),
                esc_html($vars['traffic_change'])
            );
        }
        if (!empty($gsc['configured'])) {
            $paragraphs[] = sprintf(
                '<p>Organic search visibility produced %s clicks from %s impressions, with a %s click-through rate and an average position of %s. This gives a useful baseline for understanding how customers are finding the business in Google Search.</p>',
                esc_html($vars['search_clicks']),
                esc_html($vars['search_impressions']),
                esc_html($vars['search_ctr']),
                esc_html($vars['search_position'])
            );
        }
        if (!empty($google_ads['configured'])) {
            $paragraphs[] = sprintf(
                '<p>Google Ads generated %s clicks, %s impressions, and %s conversions during the same period, with %s in recorded ad spend. Paid campaign activity is included alongside organic performance for a more complete view of marketing visibility.</p>',
                esc_html($vars['ads_clicks']),
                esc_html($vars['ads_impressions']),
                esc_html($vars['ads_conversions']),
                esc_html($vars['ads_spend'])
            );
        }
        if (empty($paragraphs)) {
            $paragraphs[] = '<p>This report was generated successfully, but connected performance data was not available for this reporting period.</p>';
        }
        return implode('', $paragraphs);
    }
}
