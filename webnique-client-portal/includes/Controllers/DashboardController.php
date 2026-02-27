<?php

namespace WNQ\Controllers;

use WNQ\Core\Permissions;
use WNQ\Services\FirebaseStore;
use WNQ\Models\SEOHub;

if (!defined('ABSPATH')) {
  exit;
}

final class DashboardController
{
  public static function registerRoutes(): void
  {
    // GET /wnq/v1/ping (auth required)
    register_rest_route('wnq/v1', '/ping', [
      'methods'  => 'GET',
      'callback' => [self::class, 'ping'],
      'permission_callback' => function () {
        return is_user_logged_in();
      },
    ]);

    // GET /wnq/v1/client (current user's client doc)
    register_rest_route('wnq/v1', '/client', [
      'methods'  => 'GET',
      'callback' => [self::class, 'getClient'],
      'permission_callback' => function () {
        return is_user_logged_in();
      },
    ]);

    // GET /wnq/v1/seo-audit (current user's SEO audit data)
    register_rest_route('wnq/v1', '/seo-audit', [
      'methods'  => 'GET',
      'callback' => [self::class, 'getSeoAudit'],
      'permission_callback' => function () {
        return is_user_logged_in();
      },
    ]);

    // GET /wnq/v1/seo-reports (historical reports archive)
    register_rest_route('wnq/v1', '/seo-reports', [
      'methods'  => 'GET',
      'callback' => [self::class, 'getSeoReports'],
      'permission_callback' => function () {
        return is_user_logged_in();
      },
    ]);

    // POST /wnq/v1/clients/bootstrap (admin-only)
    register_rest_route('wnq/v1', '/clients/bootstrap', [
      'methods'  => 'POST',
      'callback' => [self::class, 'bootstrapClient'],
      'permission_callback' => function () {
        return is_user_logged_in() && Permissions::currentUserCanManagePortal();
      },
    ]);
  }

  public static function ping(\WP_REST_Request $request): \WP_REST_Response
  {
    $firebase = new FirebaseStore();

    return new \WP_REST_Response([
      'ok' => true,
      'wp' => [
        'user_id' => get_current_user_id(),
        'can_manage_portal' => Permissions::currentUserCanManagePortal(),
        'client_id' => Permissions::currentUserClientId(),
      ],
      'firebase' => $firebase->ping(),
    ], 200);
  }

  public static function getClient(\WP_REST_Request $request): \WP_REST_Response
  {
    $clientId = Permissions::currentUserClientId();
    $clientId = $clientId ? sanitize_text_field($clientId) : '';

    if ($clientId === '') {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => 'No wnq_client_id is set for this WP user.',
      ], 400);
    }

    $firebase = new FirebaseStore();
    $res = $firebase->getClientDocFields($clientId);

    if (!$res['ok']) {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => $res['error'] ?? 'Unknown error',
        'status' => $res['status'] ?? 500,
        'details' => $res['body'] ?? ($res['details'] ?? null),
      ], $res['status'] ?? 500);
    }

    return new \WP_REST_Response([
      'ok' => true,
      'client_id' => $clientId,
      'exists' => (bool) ($res['exists'] ?? false),
      'client' => $res['client'] ?? null,
    ], 200);
  }

  public static function bootstrapClient(\WP_REST_Request $request): \WP_REST_Response
  {
    $firebase = new FirebaseStore();

    $body = $request->get_json_params();
    $requestedClientId = is_array($body) && isset($body['client_id']) ? (string) $body['client_id'] : '';

    $clientId = trim($requestedClientId) !== '' ? sanitize_text_field($requestedClientId) : (Permissions::currentUserClientId() ?? '');
    $clientId = $clientId ? sanitize_text_field($clientId) : '';

    if ($clientId === '') {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => 'Missing client_id (and current user has no wnq_client_id).',
      ], 400);
    }

    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $payload = [
      'client_id' => $clientId,
      'status' => 'active',
      'name' => $clientId,
      'created_at' => $now,
      'created_by_wp_user_id' => get_current_user_id(),
    ];

    $res = $firebase->createClientDocIfMissing($clientId, $payload);

    if (!$res['ok']) {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => $res['error'] ?? 'Unknown error',
        'status' => $res['status'] ?? 500,
        'details' => $res['body'] ?? ($res['details'] ?? null),
      ], $res['status'] ?? 500);
    }

    return new \WP_REST_Response([
      'ok' => true,
      'client_id' => $clientId,
      'created' => (bool) ($res['created'] ?? false),
      'doc' => $res['doc'] ?? null,
    ], 200);
  }

  public static function getSeoAudit(\WP_REST_Request $request): \WP_REST_Response
  {
    $clientId = Permissions::currentUserClientId();
    if (!$clientId && !Permissions::currentUserCanManagePortal()) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'No client linked.'], 403);
    }

    // Admins can pass a client_id param
    $paramId = sanitize_text_field($request->get_param('client_id') ?? '');
    if ($paramId && Permissions::currentUserCanManagePortal()) {
      $clientId = $paramId;
    }

    if (!$clientId) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'No client ID.'], 400);
    }

    // Gather data
    $siteStats    = SEOHub::getSiteStats($clientId);
    $auditSummary = SEOHub::getAuditSummary($clientId);
    $findings     = SEOHub::getAuditFindings($clientId, ['status' => 'open', 'limit' => 50]);
    $keywords     = SEOHub::getKeywords($clientId, ['limit' => 20]);

    // Calculate category scores (0-100)
    $totalPages = max(1, (int)($siteStats['total_pages'] ?? 1));

    $onPageScore = 100;
    if ($siteStats['missing_h1'] > 0)    $onPageScore -= min(30, round(($siteStats['missing_h1'] / $totalPages) * 100));
    if ($siteStats['no_schema'] > 0)     $onPageScore -= min(20, round(($siteStats['no_schema'] / $totalPages) * 50));
    if ($siteStats['missing_alt'] > 0)   $onPageScore -= min(15, round(($siteStats['missing_alt'] / $totalPages) * 40));
    if ($siteStats['thin_content'] > 0)  $onPageScore -= min(20, round(($siteStats['thin_content'] / $totalPages) * 50));
    $onPageScore = max(0, $onPageScore);

    $linksScore = 100;
    if ($siteStats['no_internal_links'] > 0) $linksScore -= min(40, round(($siteStats['no_internal_links'] / $totalPages) * 80));
    $linksScore = max(0, $linksScore);

    // Performance and Usability: derive from audit findings
    $perfPenalty = 0;
    $usabilityPenalty = 0;
    foreach ($findings as $f) {
      if (in_array($f['finding_type'], ['inline_styles', 'render_blocking'])) $perfPenalty += 10;
      if (in_array($f['finding_type'], ['missing_viewport', 'iframes'])) $usabilityPenalty += 10;
    }
    $perfScore      = max(0, 100 - $perfPenalty);
    $usabilityScore = max(0, 100 - $usabilityPenalty);
    $socialScore    = 50; // Static placeholder; updated when social data is available

    $overallScore = (int)round(($onPageScore + $linksScore + $perfScore + $usabilityScore + $socialScore) / 5);

    // Build recommendations from open findings
    $priorityMap = ['critical' => 'High', 'warning' => 'Medium', 'info' => 'Low'];
    $categoryMap = [
      'missing_h1'       => ['cat' => 'On-Page SEO', 'label' => 'Add H1 Tags to all pages'],
      'no_schema'        => ['cat' => 'On-Page SEO', 'label' => 'Add Schema Structured Data'],
      'thin_content'     => ['cat' => 'On-Page SEO', 'label' => 'Increase content length (thin pages)'],
      'missing_alt'      => ['cat' => 'On-Page SEO', 'label' => 'Add Alt Attributes to all images'],
      'missing_meta'     => ['cat' => 'On-Page SEO', 'label' => 'Add/improve Meta Descriptions'],
      'kw_not_in_title'  => ['cat' => 'On-Page SEO', 'label' => 'Use main keyword in Title Tags'],
      'no_internal_links'=> ['cat' => 'Links',       'label' => 'Add internal links to orphaned posts'],
      'orphan_page'      => ['cat' => 'Links',       'label' => 'Link to orphaned pages internally'],
      'declining_rank'   => ['cat' => 'On-Page SEO', 'label' => 'Improve pages with declining rankings'],
    ];

    $recommendations = [];
    $seen = [];
    foreach ($findings as $f) {
      $type = $f['finding_type'];
      if (isset($seen[$type])) continue;
      $seen[$type] = true;
      $info = $categoryMap[$type] ?? ['cat' => 'Other', 'label' => ucwords(str_replace('_', ' ', $type))];
      $recommendations[] = [
        'label'    => $info['label'],
        'category' => $info['cat'],
        'priority' => $priorityMap[$f['severity']] ?? 'Low',
        'count'    => (int)($f['count'] ?? 1),
      ];
    }

    // Sort by priority
    $order = ['High' => 0, 'Medium' => 1, 'Low' => 2];
    usort($recommendations, fn($a, $b) => ($order[$a['priority']] ?? 2) - ($order[$b['priority']] ?? 2));

    return new \WP_REST_Response([
      'ok'           => true,
      'client_id'    => $clientId,
      'overall_score'=> $overallScore,
      'generated_at' => current_time('mysql'),
      'site_stats'   => $siteStats,
      'category_scores' => [
        'on_page_seo' => $onPageScore,
        'links'       => $linksScore,
        'performance' => $perfScore,
        'usability'   => $usabilityScore,
        'social'      => $socialScore,
      ],
      'audit_summary'   => $auditSummary,
      'recommendations' => $recommendations,
      'keywords'        => array_map(fn($k) => [
        'keyword'          => $k['keyword'],
        'current_position' => $k['current_position'],
        'impressions'      => (int)$k['impressions'],
        'clicks'           => (int)$k['clicks'],
        'cluster'          => $k['cluster_name'],
      ], $keywords),
    ], 200);
  }

  public static function getSeoReports(\WP_REST_Request $request): \WP_REST_Response
  {
    $clientId = Permissions::currentUserClientId();
    if (!$clientId && !Permissions::currentUserCanManagePortal()) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'No client linked.'], 403);
    }

    $paramId = sanitize_text_field($request->get_param('client_id') ?? '');
    if ($paramId && Permissions::currentUserCanManagePortal()) {
      $clientId = $paramId;
    }

    if (!$clientId) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'No client ID.'], 400);
    }

    $reports = SEOHub::getReports($clientId, 12);

    $formatted = array_map(function($r) {
      $data = is_string($r['report_data']) ? json_decode($r['report_data'], true) : $r['report_data'];
      return [
        'id'           => (int)$r['id'],
        'title'        => $r['title'],
        'period_start' => $r['period_start'],
        'period_end'   => $r['period_end'],
        'status'       => $r['status'],
        'generated_at' => $r['generated_at'],
        'health_score' => $data['site_health']['score'] ?? null,
        'summary_html' => $r['summary_html'] ?? '',
      ];
    }, $reports);

    return new \WP_REST_Response([
      'ok'      => true,
      'reports' => $formatted,
      'count'   => count($formatted),
    ], 200);
  }
}
