<?php
/**
 * SEO Hub Admin - Master Control Dashboard
 *
 * Tabs:
 *   dashboard | clients | keywords | content | audits | reports | api | settings
 *
 * @package WebNique Portal
 */

namespace WNQ\Admin;

use WNQ\Models\SEOHub;
use WNQ\Models\Client;
use WNQ\Services\AutomationEngine;
use WNQ\Services\AuditEngine;
use WNQ\Services\AIEngine;
use WNQ\Core\CronScheduler;

if (!defined('ABSPATH')) {
    exit;
}

final class SEOHubAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenuPages'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_wnq_seohub_action', [self::class, 'handleAjax']);
    }

    public static function addMenuPages(): void
    {
        $cap = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';

        add_menu_page(
            'SEO Operating System',
            'SEO OS',
            $cap,
            'wnq-seo-hub',
            [self::class, 'renderDashboard'],
            'dashicons-chart-line',
            57
        );

        $pages = [
            ['Dashboard',         'wnq-seo-hub',             [self::class, 'renderDashboard']],
            ['Clients',           'wnq-seo-hub-clients',     [self::class, 'renderClients']],
            ['Keywords',          'wnq-seo-hub-keywords',    [self::class, 'renderKeywords']],
            ['Content Automation','wnq-seo-hub-content',     [self::class, 'renderContent']],
            ['Technical Audits',  'wnq-seo-hub-audits',      [self::class, 'renderAudits']],
            ['Reports',           'wnq-seo-hub-reports',     [self::class, 'renderReports']],
            ['Blog Scheduler',    'wnq-seo-hub-blog',        ['WNQ\\Admin\\BlogSchedulerAdmin', 'renderPage']],
            ['API Management',    'wnq-seo-hub-api',         [self::class, 'renderAPI']],
            ['AI Settings',       'wnq-seo-hub-settings',    [self::class, 'renderSettings']],
        ];

        foreach ($pages as [$title, $slug, $callback]) {
            add_submenu_page('wnq-seo-hub', $title, $title, $cap, $slug, $callback);
        }
    }

    public static function enqueueAssets(string $hook): void
    {
        if (strpos($hook, 'wnq-seo-hub') === false) return;

        wp_enqueue_style('wnq-seohub', WNQ_PORTAL_URL . 'assets/admin/seohub.css', [], WNQ_PORTAL_VERSION);
        wp_enqueue_script('wnq-seohub', WNQ_PORTAL_URL . 'assets/admin/seohub.js', ['jquery'], WNQ_PORTAL_VERSION, true);
        wp_localize_script('wnq-seohub', 'WNQ_SEOHUB', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wnq_seohub_nonce'),
        ]);
    }

    // ── Dashboard ──────────────────────────────────────────────────────────

    public static function renderDashboard(): void
    {
        self::checkCap();
        $clients  = Client::getByStatus('active');
        $seo_clients = [];
        foreach ($clients as $c) {
            $profile = SEOHub::getProfile($c['client_id']);
            if ($profile) {
                $stats  = SEOHub::getSiteStats($c['client_id']);
                $health = AuditEngine::getHealthScore($c['client_id']);
                $audit  = AuditEngine::getSeveritySummary($c['client_id']);
                $seo_clients[] = array_merge($c, ['stats' => $stats, 'health' => $health, 'audit' => $audit, 'profile' => $profile]);
            }
        }
        $total_clients = count($seo_clients);
        $total_pages   = array_sum(array_column(array_column($seo_clients, 'stats'), 'total_pages'));
        $total_critical = array_sum(array_column(array_column($seo_clients, 'audit'), 'critical'));
        $queue_stats   = AutomationEngine::getStats();
        $cron_status   = CronScheduler::getCronStatus();

        self::renderHeader('SEO Operating System — Dashboard');
        ?>
<div class="wnq-hub-dashboard">

  <div class="wnq-hub-stats-bar">
    <div class="wnq-hub-stat">
      <span class="value"><?php echo $total_clients; ?></span>
      <span class="label">Active Clients in SEO OS</span>
    </div>
    <div class="wnq-hub-stat">
      <span class="value"><?php echo number_format($total_pages); ?></span>
      <span class="label">Total Pages Tracked</span>
    </div>
    <div class="wnq-hub-stat <?php echo $total_critical > 0 ? 'danger' : ''; ?>">
      <span class="value"><?php echo $total_critical; ?></span>
      <span class="label">Critical Issues Open</span>
    </div>
    <div class="wnq-hub-stat">
      <span class="value"><?php echo $queue_stats['pending']; ?></span>
      <span class="label">AI Jobs Pending</span>
    </div>
    <div class="wnq-hub-stat success">
      <span class="value"><?php echo $queue_stats['awaiting_approval']; ?></span>
      <span class="label">Awaiting Your Approval</span>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="wnq-hub-section">
    <h2>Quick Actions</h2>
    <div class="wnq-hub-actions">
      <button class="wnq-btn" onclick="wnqHubAjax('install_tables')" style="background:#1e3a5f;color:#fff;border-color:#1e3a5f;">
        🗄️ Install / Repair Tables
      </button>
      <button class="wnq-btn wnq-btn-primary" onclick="wnqHubAjax('run_nightly_audit')">
        🔍 Run Nightly Audit Now
      </button>
      <button class="wnq-btn wnq-btn-primary" onclick="wnqHubAjax('run_automation')">
        ⚡ Run Automation Now
      </button>
      <button class="wnq-btn wnq-btn-primary" onclick="wnqHubAjax('process_queue')">
        🤖 Process AI Queue (5 jobs)
      </button>
      <a href="<?php echo admin_url('admin.php?page=wnq-seo-hub-reports'); ?>" class="wnq-btn">
        📊 Generate Monthly Reports
      </a>
    </div>
    <div id="wnq-action-result" style="margin-top:12px;"></div>
  </div>

  <!-- Client Grid -->
  <div class="wnq-hub-section">
    <h2>Client SEO Health Overview</h2>
    <?php if (empty($seo_clients)): ?>
    <div class="wnq-hub-empty">
      <p>No clients are configured in the SEO OS yet.</p>
      <a href="<?php echo admin_url('admin.php?page=wnq-seo-hub-clients'); ?>" class="wnq-btn wnq-btn-primary">
        Configure Clients →
      </a>
    </div>
    <?php else: ?>
    <div class="wnq-hub-client-grid">
      <?php foreach ($seo_clients as $c): ?>
      <?php
        $health = $c['health'];
        $health_class = $health >= 80 ? 'good' : ($health >= 60 ? 'ok' : 'poor');
      ?>
      <div class="wnq-hub-client-card">
        <div class="wnq-hub-client-header">
          <strong><?php echo esc_html($c['company'] ?: $c['name']); ?></strong>
          <span class="wnq-health-badge <?php echo $health_class; ?>"><?php echo $health; ?>%</span>
        </div>
        <div class="wnq-hub-client-meta">
          <span><?php echo (int)$c['stats']['total_pages']; ?> pages</span>
          <?php if ($c['audit']['critical']): ?>
          <span class="critical"><?php echo $c['audit']['critical']; ?> critical</span>
          <?php endif; ?>
          <?php if ($c['audit']['warning']): ?>
          <span class="warning"><?php echo $c['audit']['warning']; ?> warnings</span>
          <?php endif; ?>
        </div>
        <div class="wnq-hub-client-links">
          <a href="<?php echo admin_url('admin.php?page=wnq-seo-hub-clients&client_id=' . urlencode($c['client_id'])); ?>">Profile</a>
          <a href="<?php echo admin_url('admin.php?page=wnq-seo-hub-audits&client_id=' . urlencode($c['client_id'])); ?>">Audit</a>
          <a href="<?php echo admin_url('admin.php?page=wnq-seo-hub-keywords&client_id=' . urlencode($c['client_id'])); ?>">Keywords</a>
          <a href="<?php echo admin_url('admin.php?page=wnq-seo-hub-reports&client_id=' . urlencode($c['client_id'])); ?>">Reports</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Cron Status -->
  <div class="wnq-hub-section">
    <h2>Automation Scheduler Status</h2>
    <table class="wnq-hub-table">
      <thead><tr><th>Job</th><th>Status</th><th>Next Run</th></tr></thead>
      <tbody>
        <?php foreach ($cron_status as $hook => $info): ?>
        <tr>
          <td><?php echo esc_html($info['label']); ?></td>
          <td><?php echo $info['scheduled'] ? '<span class="wnq-badge-green">Scheduled</span>' : '<span class="wnq-badge-red">Not Scheduled</span>'; ?></td>
          <td><?php echo esc_html($info['next_human']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>
        <?php
        self::renderFooter();
    }

    // ── Clients ────────────────────────────────────────────────────────────

    public static function renderClients(): void
    {
        self::checkCap();
        $client_id = sanitize_text_field($_GET['client_id'] ?? '');
        $clients   = Client::getAll();

        self::renderHeader('SEO OS — Clients');

        // Single client profile view
        if ($client_id) {
            $client  = Client::getByClientId($client_id);
            $profile = SEOHub::getProfile($client_id) ?? [];
            $stats   = SEOHub::getSiteStats($client_id);
            $health  = AuditEngine::getHealthScore($client_id);
            $log     = SEOHub::getLog(['client_id' => $client_id, 'limit' => 20]);

            if (!$client) {
                echo '<div class="wnq-hub-notice error"><p>Client not found.</p></div>';
            } else {
                self::renderClientProfile($client, $profile, $stats, $health, $log);
            }
        } else {
            // Client list with SEO setup status
            echo '<div class="wnq-hub-section">';
            echo '<div class="wnq-hub-section-header"><h2>Client Profiles</h2></div>';
            echo '<table class="wnq-hub-table">';
            echo '<thead><tr><th>Client</th><th>Website</th><th>SEO Profile</th><th>Last Sync</th><th>Health</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            foreach ($clients as $c) {
                $profile = SEOHub::getProfile($c['client_id']);
                $health  = $profile ? AuditEngine::getHealthScore($c['client_id']) : null;
                $stats   = $profile ? SEOHub::getSiteStats($c['client_id']) : null;
                $health_class = $health !== null ? ($health >= 80 ? 'good' : ($health >= 60 ? 'ok' : 'poor')) : 'na';
                echo '<tr>';
                echo '<td><strong>' . esc_html($c['company'] ?: $c['name']) . '</strong><br><small>' . esc_html($c['email']) . '</small></td>';
                echo '<td>' . ($c['website'] ? '<a href="' . esc_url($c['website']) . '" target="_blank">' . esc_html($c['website']) . '</a>' : '—') . '</td>';
                echo '<td>' . ($profile ? '<span class="wnq-badge-green">Configured</span>' : '<span class="wnq-badge-red">Not Set Up</span>') . '</td>';
                echo '<td>' . ($stats['last_synced'] ?? '—') . '</td>';
                echo '<td>' . ($health !== null ? '<span class="wnq-health-badge ' . $health_class . '">' . $health . '%</span>' : '—') . '</td>';
                echo '<td><a href="' . admin_url('admin.php?page=wnq-seo-hub-clients&client_id=' . urlencode($c['client_id'])) . '" class="wnq-btn wnq-btn-sm">Manage →</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        self::renderFooter();
    }

    private static function renderClientProfile(array $client, array $profile, array $stats, int $health, array $log): void
    {
        $client_id = $client['client_id'];
        ?>
<div class="wnq-hub-client-profile">
  <div class="wnq-hub-profile-header">
    <div>
      <h2><?php echo esc_html($client['company'] ?: $client['name']); ?></h2>
      <p><?php echo esc_html($client['website'] ?? ''); ?></p>
    </div>
    <div class="wnq-health-score-big <?php echo $health >= 80 ? 'good' : ($health >= 60 ? 'ok' : 'poor'); ?>">
      <?php echo $health; ?>%<span>Health</span>
    </div>
  </div>

  <div class="wnq-hub-tabs">
    <a href="#profile-config" class="wnq-tab active">Targeting Config</a>
    <a href="#profile-stats" class="wnq-tab">Site Stats</a>
    <a href="#profile-log" class="wnq-tab">Activity Log</a>
  </div>

  <!-- Targeting Config Form -->
  <div id="profile-config" class="wnq-tab-panel active">
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
      <?php wp_nonce_field('wnq_seo_profile_' . $client_id); ?>
      <input type="hidden" name="action" value="wnq_save_seo_profile">
      <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">

      <div class="wnq-hub-form-grid">

        <div class="wnq-hub-form-group">
          <label>Primary Services (one per line)</label>
          <textarea name="primary_services" rows="4" placeholder="Web Design&#10;SEO&#10;Google Ads"><?php echo esc_textarea(implode("\n", (array)($profile['primary_services'] ?? []))); ?></textarea>
        </div>

        <div class="wnq-hub-form-group">
          <label>Service Locations (one per line)</label>
          <textarea name="service_locations" rows="4" placeholder="New York, NY&#10;Brooklyn, NY"><?php echo esc_textarea(implode("\n", (array)($profile['service_locations'] ?? []))); ?></textarea>
        </div>

        <div class="wnq-hub-form-group wnq-span-2">
          <label>Keyword Clusters (JSON format: {"Cluster Name": ["keyword 1", "keyword 2"]})</label>
          <textarea name="keyword_clusters" rows="6" class="wnq-code"><?php
            echo esc_textarea(!empty($profile['keyword_clusters'])
                ? (is_array($profile['keyword_clusters']) ? wp_json_encode($profile['keyword_clusters'], JSON_PRETTY_PRINT) : $profile['keyword_clusters'])
                : '{"Services": [], "Local": []}');
          ?></textarea>
        </div>

        <div class="wnq-hub-form-group">
          <label>Brand Notes</label>
          <textarea name="brand_notes" rows="3" placeholder="Family-owned, founded 2010, focus on premium service..."><?php echo esc_textarea($profile['brand_notes'] ?? ''); ?></textarea>
        </div>

        <div class="wnq-hub-form-group">
          <label>Content Tone</label>
          <select name="content_tone">
            <?php foreach (['professional', 'friendly', 'authoritative', 'conversational', 'technical'] as $t): ?>
            <option value="<?php echo $t; ?>" <?php selected($profile['content_tone'] ?? 'professional', $t); ?>><?php echo ucfirst($t); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="wnq-hub-form-group">
          <label>Google Search Console Property URL</label>
          <input type="url" name="gsc_property" value="<?php echo esc_attr($profile['gsc_property'] ?? ''); ?>" placeholder="https://example.com/">
        </div>

        <div class="wnq-hub-form-group">
          <label>Google Analytics Property ID</label>
          <input type="text" name="ga_property" value="<?php echo esc_attr($profile['ga_property'] ?? ''); ?>" placeholder="G-XXXXXXXXXX">
        </div>

        <div class="wnq-hub-form-group">
          <label>
            <input type="checkbox" name="auto_approve" value="1" <?php checked(!empty($profile['auto_approve'])); ?>>
            Auto-approve AI content (without manual review)
          </label>
          <p class="description">⚠️ Only enable if you trust the AI output. Recommended: OFF.</p>
        </div>

      </div>

      <button type="submit" class="wnq-btn wnq-btn-primary wnq-btn-lg">💾 Save Client Profile</button>
    </form>
  </div>

  <!-- Site Stats -->
  <div id="profile-stats" class="wnq-tab-panel" style="display:none;">
    <div class="wnq-hub-stats-bar">
      <div class="wnq-hub-stat"><span class="value"><?php echo $stats['total_pages']; ?></span><span class="label">Pages Indexed</span></div>
      <div class="wnq-hub-stat danger"><span class="value"><?php echo $stats['missing_h1']; ?></span><span class="label">Missing H1</span></div>
      <div class="wnq-hub-stat warning"><span class="value"><?php echo $stats['thin_content']; ?></span><span class="label">Thin Content</span></div>
      <div class="wnq-hub-stat warning"><span class="value"><?php echo $stats['missing_alt']; ?></span><span class="label">Missing Alt</span></div>
      <div class="wnq-hub-stat warning"><span class="value"><?php echo $stats['no_schema']; ?></span><span class="label">No Schema</span></div>
      <div class="wnq-hub-stat"><span class="value"><?php echo $stats['no_internal_links']; ?></span><span class="label">No Int. Links</span></div>
    </div>
    <p class="wnq-muted">Last synced: <?php echo esc_html($stats['last_synced'] ?? 'Never'); ?></p>

    <div style="margin-top:20px;">
      <button class="wnq-btn" onclick="wnqHubAjax('run_client_audit', '<?php echo esc_js($client_id); ?>')">
        🔍 Run Audit for This Client
      </button>
      <button class="wnq-btn wnq-btn-primary" onclick="wnqHubAjax('run_client_automation', '<?php echo esc_js($client_id); ?>')">
        ⚡ Run Automation for This Client
      </button>
    </div>
  </div>

  <!-- Activity Log -->
  <div id="profile-log" class="wnq-tab-panel" style="display:none;">
    <table class="wnq-hub-table">
      <thead><tr><th>Action</th><th>Status</th><th>Triggered By</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($log as $entry): ?>
        <tr>
          <td><?php echo esc_html($entry['action_type']); ?></td>
          <td><span class="wnq-badge-<?php echo $entry['status'] === 'success' ? 'green' : 'red'; ?>"><?php echo esc_html($entry['status']); ?></span></td>
          <td><?php echo esc_html($entry['triggered_by']); ?></td>
          <td><?php echo esc_html($entry['created_at']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
        <?php
    }

    // ── Keywords ───────────────────────────────────────────────────────────

    public static function renderKeywords(): void
    {
        self::checkCap();
        $client_id = sanitize_text_field($_GET['client_id'] ?? '');
        $clients   = Client::getAll();

        self::renderHeader('SEO OS — Keywords');

        // Client selector
        echo '<div class="wnq-hub-section">';
        echo '<div class="wnq-client-selector">';
        echo '<select onchange="location.href=\'?page=wnq-seo-hub-keywords&client_id=\'+this.value"><option value="">— Select Client —</option>';
        foreach ($clients as $c) {
            $selected = $client_id === $c['client_id'] ? 'selected' : '';
            echo '<option value="' . esc_attr($c['client_id']) . '" ' . $selected . '>' . esc_html($c['company'] ?: $c['name']) . '</option>';
        }
        echo '</select></div>';

        if ($client_id) {
            $keywords = SEOHub::getKeywords($client_id);
            $clusters = SEOHub::getKeywordClusters($client_id);
            $gaps     = SEOHub::getKeywords($client_id, ['content_gap' => 1]);
            $profile  = SEOHub::getProfile($client_id) ?? [];

            echo '<div class="wnq-hub-stats-bar">';
            echo '<div class="wnq-hub-stat"><span class="value">' . count($keywords) . '</span><span class="label">Tracked Keywords</span></div>';
            echo '<div class="wnq-hub-stat danger"><span class="value">' . count($gaps) . '</span><span class="label">Content Gaps</span></div>';
            echo '<div class="wnq-hub-stat"><span class="value">' . count($clusters) . '</span><span class="label">Clusters</span></div>';
            echo '</div>';

            // Add Keyword Form
            echo '<details style="margin:16px 0;"><summary class="wnq-btn">+ Add Keyword</summary>';
            echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-top:8px;">';
            wp_nonce_field('wnq_add_keyword');
            echo '<input type="hidden" name="action" value="wnq_add_seo_keyword">';
            echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '">';
            echo '<div class="wnq-hub-form-grid" style="grid-template-columns: repeat(3, 1fr);">';
            echo '<div class="wnq-hub-form-group"><label>Keyword</label><input type="text" name="keyword" required placeholder="plumber brooklyn ny"></div>';
            echo '<div class="wnq-hub-form-group"><label>Cluster Name</label><input type="text" name="cluster_name" placeholder="Plumbing Services"></div>';
            echo '<div class="wnq-hub-form-group"><label>Service Category</label><input type="text" name="service_category" placeholder="Plumbing"></div>';
            echo '<div class="wnq-hub-form-group"><label>Location</label><input type="text" name="location" placeholder="Brooklyn, NY"></div>';
            echo '<div class="wnq-hub-form-group"><label>Target URL</label><input type="url" name="target_url" placeholder="https://..."></div>';
            echo '</div>';
            echo '<button type="submit" class="wnq-btn wnq-btn-primary">Add Keyword</button>';
            echo '</form></details>';

            // Keyword clusters summary
            if (!empty($clusters)) {
                echo '<h3 style="margin-bottom:12px;">Keyword Clusters</h3>';
                echo '<div class="wnq-hub-cluster-grid">';
                foreach ($clusters as $cluster) {
                    echo '<div class="wnq-hub-cluster-card">';
                    echo '<strong>' . esc_html($cluster['cluster_name']) . '</strong>';
                    echo '<div class="wnq-hub-cluster-stats">';
                    echo '<span>' . (int)$cluster['keyword_count'] . ' kws</span>';
                    echo '<span>' . number_format((int)$cluster['total_impressions']) . ' impr.</span>';
                    echo '<span>' . number_format((int)$cluster['total_clicks']) . ' clicks</span>';
                    if ($cluster['avg_pos']) echo '<span>Avg pos: ' . number_format((float)$cluster['avg_pos'], 1) . '</span>';
                    echo '</div></div>';
                }
                echo '</div>';
            }

            // Full keyword table
            echo '<h3 style="margin:20px 0 12px;">All Keywords</h3>';
            echo '<div class="wnq-hub-table-wrap">';
            echo '<table class="wnq-hub-table"><thead><tr><th>Keyword</th><th>Cluster</th><th>Position</th><th>Change</th><th>Impressions</th><th>Clicks</th><th>Gap?</th><th>Target URL</th></tr></thead><tbody>';
            foreach ($keywords as $kw) {
                $delta = ($kw['current_position'] !== null && $kw['prev_position'] !== null)
                    ? ((float)$kw['current_position'] - (float)$kw['prev_position'])
                    : null;
                $delta_html = '—';
                if ($delta !== null) {
                    if ($delta < 0) $delta_html = '<span style="color:#16a34a">▲ ' . abs($delta) . '</span>';
                    elseif ($delta > 0) $delta_html = '<span style="color:#dc2626">▼ ' . $delta . '</span>';
                    else $delta_html = '—';
                }
                echo '<tr>';
                echo '<td><strong>' . esc_html($kw['keyword']) . '</strong></td>';
                echo '<td>' . esc_html($kw['cluster_name'] ?: '—') . '</td>';
                echo '<td>' . ($kw['current_position'] !== null ? '#' . number_format((float)$kw['current_position'], 1) : '—') . '</td>';
                echo '<td>' . $delta_html . '</td>';
                echo '<td>' . number_format((int)$kw['impressions']) . '</td>';
                echo '<td>' . number_format((int)$kw['clicks']) . '</td>';
                echo '<td>' . ($kw['content_gap'] ? '<span class="wnq-badge-red">Gap</span>' : '<span class="wnq-badge-green">Covered</span>') . '</td>';
                echo '<td>' . ($kw['target_url'] ? '<a href="' . esc_url($kw['target_url']) . '" target="_blank">↗</a>' : '—') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '</div>';
        self::renderFooter();
    }

    // ── Content Automation ─────────────────────────────────────────────────

    public static function renderContent(): void
    {
        self::checkCap();
        $client_id = sanitize_text_field($_GET['client_id'] ?? '');
        $clients   = Client::getAll();

        self::renderHeader('SEO OS — Content Automation');

        echo '<div class="wnq-hub-section">';
        echo '<div class="wnq-client-selector">';
        echo '<select onchange="location.href=\'?page=wnq-seo-hub-content&client_id=\'+this.value"><option value="">— Select Client —</option>';
        foreach ($clients as $c) {
            $selected = $client_id === $c['client_id'] ? 'selected' : '';
            echo '<option value="' . esc_attr($c['client_id']) . '" ' . $selected . '>' . esc_html($c['company'] ?: $c['name']) . '</option>';
        }
        echo '</select>';
        if ($client_id) {
            echo ' &nbsp;<button class="wnq-btn wnq-btn-primary" onclick="wnqHubAjax(\'analyze_content_gaps\', \'' . esc_js($client_id) . '\')">⚡ Analyze Gaps & Queue Jobs</button>';
        }
        echo '</div>';

        if ($client_id) {
            $stats   = AutomationEngine::getStats($client_id);
            $jobs    = SEOHub::getContentJobs($client_id, ['limit' => 50]);
            $profile = SEOHub::getProfile($client_id) ?? [];

            // Stats
            echo '<div class="wnq-hub-stats-bar">';
            foreach ([
                'pending' => 'Pending', 'running' => 'Running', 'completed' => 'Completed',
                'awaiting_approval' => 'Needs Approval', 'approved' => 'Approved', 'failed' => 'Failed'
            ] as $k => $label) {
                $class = $k === 'awaiting_approval' ? 'success' : '';
                echo '<div class="wnq-hub-stat ' . $class . '"><span class="value">' . $stats[$k] . '</span><span class="label">' . $label . '</span></div>';
            }
            echo '</div>';

            // Manual job creation
            echo '<details style="margin-bottom:20px;"><summary class="wnq-btn">+ Create AI Job Manually</summary>';
            echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-top:8px;">';
            wp_nonce_field('wnq_create_ai_job');
            echo '<input type="hidden" name="action" value="wnq_create_ai_job">';
            echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '">';
            echo '<div class="wnq-hub-form-grid" style="grid-template-columns: repeat(3, 1fr);">';
            echo '<div class="wnq-hub-form-group"><label>Job Type</label><select name="job_type">';
            foreach (['blog_outline' => 'Blog Outline', 'blog_draft' => 'Blog Draft', 'meta_tags' => 'Meta Tags', 'schema' => 'Schema JSON-LD', 'internal_links' => 'Internal Links', 'content_gap_topics' => 'Content Gap Topics'] as $v => $l) {
                echo '<option value="' . $v . '">' . $l . '</option>';
            }
            echo '</select></div>';
            echo '<div class="wnq-hub-form-group"><label>Target Keyword</label><input type="text" name="target_keyword" placeholder="e.g. plumber brooklyn ny"></div>';
            echo '<div class="wnq-hub-form-group"><label>Target URL (optional)</label><input type="url" name="target_url"></div>';
            echo '</div>';
            echo '<button type="submit" class="wnq-btn wnq-btn-primary">Create Job</button>';
            echo '</form></details>';

            // Jobs table
            echo '<div class="wnq-hub-table-wrap"><table class="wnq-hub-table">';
            echo '<thead><tr><th>Type</th><th>Keyword</th><th>Status</th><th>Created</th><th>Output</th><th>Actions</th></tr></thead><tbody>';
            foreach ($jobs as $job) {
                $has_output = !empty($job['output_content']);
                echo '<tr>';
                echo '<td><strong>' . esc_html(str_replace('_', ' ', $job['job_type'])) . '</strong></td>';
                echo '<td>' . esc_html($job['target_keyword'] ?: '—') . '</td>';
                $status_colors = ['pending' => '#6b7280', 'running' => '#d97706', 'completed' => '#16a34a', 'failed' => '#dc2626', 'approved' => '#0d539e'];
                $sc = $status_colors[$job['status']] ?? '#6b7280';
                echo '<td><span style="color:' . $sc . ';font-weight:600;">' . esc_html($job['status']) . '</span>' . ($job['approved'] ? ' ✓' : '') . '</td>';
                echo '<td>' . esc_html(substr($job['created_at'], 0, 10)) . '</td>';
                echo '<td>' . ($has_output ? '<span style="color:#16a34a">✓ Ready</span>' : '—') . '</td>';
                echo '<td>';
                if ($has_output && !$job['approved']) {
                    echo '<button class="wnq-btn wnq-btn-sm" onclick="wnqViewJob(' . $job['id'] . ', this)">View & Approve</button> ';
                }
                if ($job['status'] === 'pending') {
                    echo '<button class="wnq-btn wnq-btn-sm wnq-btn-primary" onclick="wnqHubAjax(\'run_single_job\', \'' . esc_js($client_id) . '\', ' . $job['id'] . ')">Run Now</button> ';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '</div>';
        self::renderFooter();
    }

    // ── Technical Audits ───────────────────────────────────────────────────

    public static function renderAudits(): void
    {
        self::checkCap();
        $client_id = sanitize_text_field($_GET['client_id'] ?? '');
        $clients   = Client::getAll();

        self::renderHeader('SEO OS — Technical Audits');

        echo '<div class="wnq-hub-section">';
        echo '<div class="wnq-client-selector">';
        echo '<select onchange="location.href=\'?page=wnq-seo-hub-audits&client_id=\'+this.value"><option value="">— Select Client —</option>';
        foreach ($clients as $c) {
            $selected = $client_id === $c['client_id'] ? 'selected' : '';
            echo '<option value="' . esc_attr($c['client_id']) . '" ' . $selected . '>' . esc_html($c['company'] ?: $c['name']) . '</option>';
        }
        echo '</select>';
        if ($client_id) {
            echo ' &nbsp;<button class="wnq-btn wnq-btn-primary" onclick="wnqHubAjax(\'run_client_audit\', \'' . esc_js($client_id) . '\')">🔍 Run Audit Now</button>';
            echo ' &nbsp;<button id="wnq-fix-btn" class="wnq-btn" style="background:#059669;color:#fff;border-color:#059669;" onclick="wnqAutoFixSEO(\'' . esc_js($client_id) . '\')">🔧 Auto-Fix SEO Issues</button>';
        }
        echo '</div>';
        echo '<div id="wnq-action-result" style="margin-top:12px;"></div>';

        // Progress bar — hidden until auto-fix starts
        echo '<div id="wnq-fix-progress" style="display:none;margin-top:16px;padding:16px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;">';
        echo '  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">';
        echo '    <strong style="color:#166534;">🔧 Auto-Fixing SEO Issues…</strong>';
        echo '    <span id="wnq-fix-pct" style="font-weight:700;color:#166534;font-size:18px;">0%</span>';
        echo '  </div>';
        echo '  <div style="background:#dcfce7;border-radius:6px;height:14px;overflow:hidden;">';
        echo '    <div id="wnq-fix-bar" style="background:linear-gradient(90deg,#059669,#34d399);height:100%;width:0%;transition:width 0.5s ease;border-radius:6px;"></div>';
        echo '  </div>';
        echo '  <div id="wnq-fix-status" style="margin-top:10px;font-size:13px;color:#15803d;"></div>';
        echo '  <div id="wnq-fix-counts" style="margin-top:6px;font-size:12px;color:#6b7280;"></div>';
        echo '</div>';

        if ($client_id) {
            $findings = SEOHub::getAuditFindings($client_id, ['status' => 'open']);
            $health   = AuditEngine::getHealthScore($client_id);
            $severity = AuditEngine::getSeveritySummary($client_id);
            $stats    = SEOHub::getSiteStats($client_id);

            echo '<div class="wnq-hub-stats-bar">';
            echo '<div class="wnq-hub-stat ' . ($health < 60 ? 'danger' : ($health < 80 ? 'warning' : 'success')) . '"><span class="value">' . $health . '%</span><span class="label">Health Score</span></div>';
            echo '<div class="wnq-hub-stat danger"><span class="value">' . ($severity['critical'] ?? 0) . '</span><span class="label">Critical</span></div>';
            echo '<div class="wnq-hub-stat warning"><span class="value">' . ($severity['warning'] ?? 0) . '</span><span class="label">Warnings</span></div>';
            echo '<div class="wnq-hub-stat"><span class="value">' . ($severity['info'] ?? 0) . '</span><span class="label">Info</span></div>';
            echo '<div class="wnq-hub-stat"><span class="value">' . count($findings) . '</span><span class="label">Open Findings</span></div>';
            echo '</div>';

            // Issue summary
            $type_labels = [
                'missing_h1'       => ['Missing H1', 'critical'],
                'no_schema'        => ['No Schema Markup', 'warning'],
                'thin_content'     => ['Thin Content (<300 words)', 'warning'],
                'missing_alt'      => ['Missing Image Alt Text', 'warning'],
                'kw_not_in_title'  => ['Keyword Not In Title', 'warning'],
                'no_internal_links'=> ['No Internal Links', 'info'],
                'missing_meta'     => ['Missing/Short Meta Description', 'warning'],
                'declining_rank'   => ['Declining Rankings', 'warning'],
            ];

            echo '<div class="wnq-hub-audit-grid">';
            foreach ($type_labels as $type => [$label, $sev]) {
                $count = (int)($stats[$type] ?? count(array_filter($findings, fn($f) => $f['finding_type'] === $type)));
                $class = $count > 0 ? $sev : 'resolved';
                echo '<div class="wnq-hub-audit-card ' . $class . '">';
                echo '<div class="wnq-hub-audit-count">' . $count . '</div>';
                echo '<div class="wnq-hub-audit-label">' . esc_html($label) . '</div>';
                echo '<div class="wnq-hub-audit-sev">' . $sev . '</div>';
                echo '</div>';
            }
            echo '</div>';

            // Findings table
            if (!empty($findings)) {
                echo '<h3 style="margin:20px 0 12px;">Open Findings (' . count($findings) . ')</h3>';
                echo '<div class="wnq-hub-table-wrap"><table class="wnq-hub-table">';
                echo '<thead><tr><th>Finding</th><th>Severity</th><th>URL</th><th>Details</th><th>Action</th></tr></thead><tbody>';
                foreach ($findings as $f) {
                    $data = json_decode($f['finding_data'] ?? '[]', true) ?? [];
                    echo '<tr>';
                    echo '<td>' . esc_html($type_labels[$f['finding_type']][0] ?? $f['finding_type']) . '</td>';
                    $sev_colors = ['critical' => '#dc2626', 'warning' => '#d97706', 'info' => '#6b7280'];
                    echo '<td><span style="color:' . ($sev_colors[$f['severity']] ?? '#6b7280') . ';font-weight:600;">' . esc_html($f['severity']) . '</span></td>';
                    echo '<td><a href="' . esc_url($f['page_url'] ?? '') . '" target="_blank">' . esc_html(substr($f['page_url'] ?? '', 0, 50)) . '</a></td>';
                    echo '<td style="font-size:12px;">';
                    foreach ($data as $k => $v) {
                        echo esc_html(str_replace('_', ' ', $k)) . ': <strong>' . esc_html($v) . '</strong><br>';
                    }
                    echo '</td>';
                    echo '<td><button class="wnq-btn wnq-btn-sm" onclick="wnqHubAjax(\'resolve_finding\', \'\', ' . $f['id'] . ')">✓ Resolve</button></td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
        }

        echo '</div>';
        self::renderFooter();
    }

    // ── Reports ────────────────────────────────────────────────────────────

    public static function renderReports(): void
    {
        self::checkCap();
        $client_id = sanitize_text_field($_GET['client_id'] ?? '');
        $clients   = Client::getAll();

        self::renderHeader('SEO OS — Reports');

        echo '<div class="wnq-hub-section">';
        echo '<div class="wnq-client-selector">';
        echo '<select onchange="location.href=\'?page=wnq-seo-hub-reports&client_id=\'+this.value"><option value="">— Select Client —</option>';
        foreach ($clients as $c) {
            $selected = $client_id === $c['client_id'] ? 'selected' : '';
            echo '<option value="' . esc_attr($c['client_id']) . '" ' . $selected . '>' . esc_html($c['company'] ?: $c['name']) . '</option>';
        }
        echo '</select>';
        if ($client_id) {
            echo ' &nbsp;<button class="wnq-btn wnq-btn-primary" onclick="wnqHubAjax(\'generate_report\', \'' . esc_js($client_id) . '\')">📊 Generate This Month\'s Report</button>';
            echo ' &nbsp;<button class="wnq-btn" onclick="wnqHubAjax(\'generate_all_reports\', \'\')">📊 Generate All Client Reports</button>';
        }
        echo '</div>';

        if ($client_id) {
            $reports = SEOHub::getReports($client_id);

            echo '<table class="wnq-hub-table"><thead><tr><th>Report</th><th>Period</th><th>Status</th><th>Generated</th><th>Actions</th></tr></thead><tbody>';
            if (empty($reports)) {
                echo '<tr><td colspan="5" style="text-align:center;padding:40px;color:#6b7280;">No reports generated yet.</td></tr>';
            }
            foreach ($reports as $r) {
                echo '<tr>';
                echo '<td><strong>' . esc_html($r['title']) . '</strong></td>';
                echo '<td>' . esc_html($r['period_start']) . ' – ' . esc_html($r['period_end']) . '</td>';
                echo '<td><span class="wnq-badge-green">' . esc_html($r['status']) . '</span></td>';
                echo '<td>' . esc_html(substr($r['generated_at'], 0, 10)) . '</td>';
                echo '<td>';
                echo '<a class="wnq-btn wnq-btn-sm" href="' . admin_url('admin-post.php?action=wnq_export_report&report_id=' . $r['id'] . '&_wpnonce=' . wp_create_nonce('wnq_export_report')) . '" target="_blank">Export HTML</a>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
        self::renderFooter();
    }

    // ── API Management ─────────────────────────────────────────────────────

    public static function renderAPI(): void
    {
        self::checkCap();
        $client_id = sanitize_text_field($_GET['client_id'] ?? '');
        $clients   = Client::getAll();
        $all_keys  = SEOHub::getAllAgentKeys();

        self::renderHeader('SEO OS — API Management');

        // ── Hub URL callout (this is what client plugins must use) ──
        $hub_url = site_url();
        echo '<div style="background:#1e3a5f;color:white;padding:20px 24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">';
        echo '<div style="flex:1;">';
        echo '<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;opacity:.7;margin-bottom:4px;">Your Hub URL — enter this in every client plugin</div>';
        echo '<code id="wnq-hub-url-value" style="font-size:18px;font-weight:700;background:rgba(255,255,255,.1);padding:8px 16px;border-radius:6px;letter-spacing:.5px;">' . esc_html($hub_url) . '</code>';
        echo '</div>';
        echo '<button type="button" onclick="navigator.clipboard.writeText(\'' . esc_js($hub_url) . '\').then(()=>{this.innerHTML=\'✓ Copied!\';setTimeout(()=>this.innerHTML=\'📋 Copy URL\',2000)})" style="background:white;color:#1e3a5f;border:none;padding:10px 18px;border-radius:6px;font-weight:700;cursor:pointer;font-size:13px;">📋 Copy URL</button>';
        echo '</div>';

        echo '<div class="wnq-hub-section">';
        echo '<h2>Agent API Keys</h2>';
        echo '<p class="wnq-muted">Each client WordPress site needs an API key to communicate with this hub. Install the <strong>WebNique SEO Agent</strong> plugin on the client site and enter the Hub URL above plus the key generated here.</p>';

        // Notices (generated/revoked)
        if (!empty($_GET['generated'])) {
            echo '<div class="wnq-hub-notice success" style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:10px 14px;border-radius:6px;margin-bottom:16px;">✅ API key generated successfully. Copy it from the table below and enter it in the client plugin.</div>';
        }
        if (!empty($_GET['revoked'])) {
            echo '<div class="wnq-hub-notice" style="background:#fef9c3;color:#92400e;border:1px solid #fde68a;padding:10px 14px;border-radius:6px;margin-bottom:16px;">⚠ Key revoked. The client plugin using this key will no longer be able to sync.</div>';
        }

        // Generate key form
        echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="background:#f0f9ff;padding:20px;border-radius:8px;margin:16px 0;border:1px solid #bae6fd;">';
        wp_nonce_field('wnq_generate_agent_key');
        echo '<input type="hidden" name="action" value="wnq_generate_agent_key">';
        echo '<h3 style="margin-bottom:12px;">Generate New API Key</h3>';
        echo '<div class="wnq-hub-form-grid" style="grid-template-columns: repeat(3, 1fr);">';
        echo '<div class="wnq-hub-form-group"><label>Client</label><select name="client_id" required><option value="">— Select Client —</option>';
        foreach ($clients as $c) {
            echo '<option value="' . esc_attr($c['client_id']) . '">' . esc_html($c['company'] ?: $c['name']) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="wnq-hub-form-group"><label>Client Site URL</label><input type="url" name="site_url" required placeholder="https://client-site.com"></div>';
        echo '<div class="wnq-hub-form-group"><label>Site Name (optional)</label><input type="text" name="site_name" placeholder="Client Main Site"></div>';
        echo '</div>';
        echo '<button type="submit" class="wnq-btn wnq-btn-primary">🔑 Generate API Key</button>';
        echo '</form>';

        // Keys table
        echo '<table class="wnq-hub-table"><thead><tr><th>Client</th><th>Site URL</th><th>API Key</th><th>Last Ping</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        if (empty($all_keys)) {
            echo '<tr><td colspan="6" style="text-align:center;padding:40px;color:#6b7280;">No API keys generated yet.</td></tr>';
        }
        foreach ($all_keys as $key) {
            $client = Client::getByClientId($key['client_id']);
            $name   = $client ? ($client['company'] ?: $client['name']) : $key['client_id'];
            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td><a href="' . esc_url($key['site_url']) . '" target="_blank">' . esc_html($key['site_url']) . '</a></td>';
            echo '<td><code style="font-size:11px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">' . esc_html($key['api_key']) . '</code></td>';
            echo '<td>' . esc_html($key['last_ping'] ?: 'Never') . '</td>';
            echo '<td>' . ($key['status'] === 'active' ? '<span class="wnq-badge-green">Active</span>' : '<span class="wnq-badge-red">Revoked</span>') . '</td>';
            echo '<td>';
            if ($key['status'] === 'active') {
                echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="display:inline;">';
                wp_nonce_field('wnq_revoke_key_' . $key['id'], '_wpnonce', false);
                echo '<input type="hidden" name="action" value="wnq_revoke_agent_key">';
                echo '<input type="hidden" name="key_id" value="' . $key['id'] . '">';
                echo '<button type="submit" class="wnq-btn wnq-btn-sm wnq-btn-danger" onclick="return confirm(\'Revoke this key? The client plugin will stop working.\')">Revoke</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';

        // Installation instructions
        $hub_url = site_url();
        echo '<div class="wnq-hub-section">';
        echo '<h2>Plugin Installation Instructions</h2>';
        echo '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:16px;margin-bottom:20px;">';
        echo '<strong>⚡ Quick Reference:</strong><br>';
        echo 'Hub URL to enter in every client plugin: <code style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-size:13px;">' . esc_html($hub_url) . '</code>';
        echo '</div>';
        echo '<ol style="line-height:2.2;">';
        echo '<li>Zip the <code>webnique-seo-agent</code> folder from this repo into <code>webnique-seo-agent.zip</code></li>';
        echo '<li>On the <strong>client\'s</strong> WordPress site go to <em>Plugins → Add New → Upload Plugin</em></li>';
        echo '<li>Upload and activate <code>webnique-seo-agent.zip</code></li>';
        echo '<li>Go to <em>Settings → WebNique SEO Agent</em> on the client site</li>';
        echo '<li>Set <strong>Hub URL</strong> to: <code>' . esc_html($hub_url) . '</code></li>';
        echo '<li>Set <strong>API Key</strong> to the key generated in the table above for that client</li>';
        echo '<li>Click <strong>Save Settings</strong> then <strong>Test Connection</strong></li>';
        echo '<li>Click <strong>Sync to Hub Now</strong> to push the first data batch</li>';
        echo '<li>All future syncs happen automatically (twice daily by default)</li>';
        echo '</ol>';
        echo '</div>';

        self::renderFooter();
    }

    // ── AI Settings ────────────────────────────────────────────────────────

    public static function renderSettings(): void
    {
        self::checkCap();
        $ai_settings = AIEngine::getSettings();
        $cron_status = CronScheduler::getCronStatus();

        self::renderHeader('SEO OS — AI & Automation Settings');

        // Notices
        if (!empty($_GET['saved'])) {
            echo '<div style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:12px 20px;font-weight:600;">✅ Settings saved successfully.</div>';
        }
        if (!empty($_GET['reset'])) {
            echo '<div style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:12px 20px;font-weight:600;">✅ Prompt templates reset to defaults.</div>';
        }

        // Cloudways/environment notice (shown on non-production domains)
        $current_host = parse_url(site_url(), PHP_URL_HOST) ?? '';
        if (str_contains($current_host, 'cloudwaysapps.com') || str_contains($current_host, 'staging') || str_contains($current_host, 'localhost')) {
            echo '<div style="background:#fffbeb;border-left:4px solid #d97706;padding:14px 20px;margin:0;">';
            echo '<strong>🔧 Testing Environment Detected:</strong> Hub is running at <code>' . esc_html(site_url()) . '</code>. ';
            echo 'When you move to production (web-nique.com), regenerate API keys and update the Hub URL in each client plugin.<br>';
            echo '<small style="color:#92400e;">The <code>_load_textdomain_just_in_time</code> notice you may see in debug mode is from the <strong>Cloudways Breeze caching plugin</strong> — it is unrelated to the SEO OS and can be safely ignored.</small>';
            echo '</div>';
        }

        echo '<div class="wnq-hub-section">';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
        wp_nonce_field('wnq_save_ai_settings');
        echo '<input type="hidden" name="action" value="wnq_save_ai_settings">';

        echo '<h2>AI Provider Configuration</h2>';
        echo '<p class="wnq-muted">The AI engine is modular — switch providers without rebuilding anything. <strong>Groq</strong> offers a generous free tier with fast Llama/Mixtral models.</p>';

        echo '<div class="wnq-hub-form-grid">';

        echo '<div class="wnq-hub-form-group">';
        echo '<label>AI Provider</label>';
        echo '<select name="provider">';
        foreach ([
            'xai'     => 'xAI / Grok (Recommended)',
            'groq'    => 'Groq (Free Tier)',
            'openai'  => 'OpenAI (GPT-3.5/4)',
            'together'=> 'Together AI (Free Tier)',
        ] as $v => $l) {
            echo '<option value="' . $v . '" ' . selected($ai_settings['provider'] ?? 'groq', $v, false) . '>' . $l . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="wnq-hub-form-group">';
        echo '<label>xAI API Key</label>';
        echo '<input type="password" name="xai_api_key" value="' . esc_attr($ai_settings['xai_api_key'] ?? '') . '" placeholder="xai-...">';
        echo '<p class="description">Get your key at <a href="https://console.x.ai" target="_blank">console.x.ai</a></p>';
        echo '</div>';

        echo '<div class="wnq-hub-form-group">';
        echo '<label>xAI Model</label>';
        echo '<select name="xai_model">';
        foreach ([
            'grok-3-mini-latest' => 'Grok 3 Mini (fast, cost-efficient)',
            'grok-3-latest'      => 'Grok 3 (most capable)',
            'grok-4-latest'      => 'Grok 4 (latest)',
        ] as $v => $l) {
            echo '<option value="' . $v . '" ' . selected($ai_settings['xai_model'] ?? 'grok-3-mini-latest', $v, false) . '>' . $l . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="wnq-hub-form-group">';
        echo '<label>Groq API Key</label>';
        echo '<input type="password" name="groq_api_key" value="' . esc_attr($ai_settings['groq_api_key'] ?? '') . '" placeholder="gsk_...">';
        echo '<p class="description">Free tier: 14,400 req/day · <a href="https://console.groq.com" target="_blank">console.groq.com</a></p>';
        echo '</div>';

        echo '<div class="wnq-hub-form-group">';
        echo '<label>Groq Model</label>';
        echo '<select name="groq_model">';
        foreach ([
            'llama-3.1-8b-instant'    => 'Llama 3.1 8B Instant (fastest)',
            'llama-3.3-70b-versatile' => 'Llama 3.3 70B Versatile (best quality)',
            'mixtral-8x7b-32768'      => 'Mixtral 8x7B (good balance)',
            'gemma2-9b-it'            => 'Gemma 2 9B IT',
        ] as $v => $l) {
            echo '<option value="' . $v . '" ' . selected($ai_settings['groq_model'] ?? 'llama-3.1-8b-instant', $v, false) . '>' . $l . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="wnq-hub-form-group">';
        echo '<label>OpenAI API Key</label>';
        echo '<input type="password" name="openai_api_key" value="' . esc_attr($ai_settings['openai_api_key'] ?? '') . '" placeholder="sk-...">';
        echo '</div>';

        echo '<div class="wnq-hub-form-group">';
        echo '<label>OpenAI Model</label>';
        echo '<input type="text" name="openai_model" value="' . esc_attr($ai_settings['openai_model'] ?? 'gpt-3.5-turbo') . '">';
        echo '</div>';

        echo '<div class="wnq-hub-form-group">';
        echo '<label>Together AI API Key</label>';
        echo '<input type="password" name="together_api_key" value="' . esc_attr($ai_settings['together_api_key'] ?? '') . '">';
        echo '<p class="description">Get a free key at <a href="https://api.together.xyz" target="_blank">api.together.xyz</a></p>';
        echo '</div>';

        echo '<div class="wnq-hub-form-group">';
        echo '<label>Max Output Tokens</label>';
        echo '<input type="number" name="max_tokens" value="' . esc_attr($ai_settings['max_tokens'] ?? 2000) . '" min="500" max="8000">';
        echo '</div>';

        echo '<div class="wnq-hub-form-group">';
        echo '<label>Temperature (creativity 0-1)</label>';
        echo '<input type="number" name="temperature" value="' . esc_attr($ai_settings['temperature'] ?? 0.7) . '" min="0" max="1" step="0.1">';
        echo '</div>';

        echo '<div class="wnq-hub-form-group">';
        echo '<label>Output Cache TTL (seconds)</label>';
        echo '<input type="number" name="cache_ttl" value="' . esc_attr($ai_settings['cache_ttl'] ?? 86400) . '">';
        echo '<p class="description">86400 = 24 hours. Identical prompts return cached output.</p>';
        echo '</div>';

        echo '</div>';

        echo '<div style="display:flex;gap:12px;margin-top:20px;">';
        echo '<button type="submit" class="wnq-btn wnq-btn-primary wnq-btn-lg">💾 Save AI Settings</button>';
        echo '<button type="button" class="wnq-btn wnq-btn-lg" onclick="wnqHubAjax(\'test_ai_connection\')">🧪 Test Connection</button>';
        echo '</div>';
        echo '<div id="wnq-action-result" style="margin-top:12px;"></div>';

        echo '</form>';
        echo '</div>';

        // Prompt Templates
        echo '<div class="wnq-hub-section">';
        echo '<h2>Prompt Templates</h2>';
        echo '<p class="wnq-muted">Customize the AI prompts used for each job type. Use {variable} placeholders.</p>';

        $defaults = AIEngine::getDefaultTemplates();
        $overrides = get_option('wnq_ai_prompt_templates', []);

        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
        wp_nonce_field('wnq_save_prompt_templates');
        echo '<input type="hidden" name="action" value="wnq_save_prompt_templates">';

        foreach ($defaults as $key => $default_prompt) {
            $current = $overrides[$key] ?? $default_prompt;
            echo '<div class="wnq-hub-form-group">';
            echo '<label style="font-weight:700;">' . esc_html(str_replace('_', ' ', ucfirst($key))) . '</label>';
            echo '<textarea name="templates[' . esc_attr($key) . ']" rows="8" class="wnq-code">' . esc_textarea($current) . '</textarea>';
            echo '</div>';
        }

        echo '<button type="submit" class="wnq-btn wnq-btn-primary">💾 Save Templates</button>';
        echo ' &nbsp;<button type="button" class="wnq-btn" onclick="if(confirm(\'Reset all templates to defaults?\')) { document.getElementById(\'reset-templates\').submit(); }">↩ Reset to Defaults</button>';
        echo '</form>';
        echo '<form id="reset-templates" method="post" action="' . admin_url('admin-post.php') . '" style="display:none;">';
        wp_nonce_field('wnq_reset_prompt_templates');
        echo '<input type="hidden" name="action" value="wnq_reset_prompt_templates">';
        echo '</form>';

        echo '</div>';

        // Cron Status
        echo '<div class="wnq-hub-section">';
        echo '<h2>Automation Scheduler</h2>';
        echo '<table class="wnq-hub-table"><thead><tr><th>Job</th><th>Scheduled?</th><th>Next Run</th></tr></thead><tbody>';
        foreach ($cron_status as $hook => $info) {
            echo '<tr>';
            echo '<td>' . esc_html($info['label']) . '</td>';
            echo '<td>' . ($info['scheduled'] ? '<span class="wnq-badge-green">Yes</span>' : '<span class="wnq-badge-red">No</span>') . '</td>';
            echo '<td>' . esc_html($info['next_human']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';

        self::renderFooter();
    }

    // ── AJAX Handler ───────────────────────────────────────────────────────

    public static function handleAjax(): void
    {
        check_ajax_referer('wnq_seohub_nonce', 'nonce');
        self::checkCap();

        $action    = sanitize_text_field($_POST['hub_action'] ?? '');
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $entity_id = (int)($_POST['entity_id'] ?? 0);

        switch ($action) {
            case 'run_nightly_audit':
                $result = AuditEngine::runNightlyAudit();
                wp_send_json_success(['message' => "Audit complete. Clients audited: {$result['clients_audited']}, Findings: {$result['total_findings']}", 'data' => $result]);
                break;

            case 'run_client_audit':
                if (!$client_id) wp_send_json_error(['message' => 'No client selected']);
                $result = AuditEngine::auditClient($client_id);
                wp_send_json_success(['message' => "Audit complete. {$result['total']} findings.", 'data' => $result]);
                break;

            case 'run_automation':
                $result = AutomationEngine::runNightlyAutomation();
                wp_send_json_success(['message' => "Automation complete. Processed: {$result['clients_processed']} clients, {$result['total_jobs']} jobs queued.", 'data' => $result]);
                break;

            case 'run_client_automation':
                if (!$client_id) wp_send_json_error(['message' => 'No client selected']);
                $result = AutomationEngine::runClientAutomation($client_id);
                wp_send_json_success(['message' => "Done. Content gaps: {$result['content_gaps']['gaps_found']}, Jobs created: {$result['content_gaps']['jobs_created']}", 'data' => $result]);
                break;

            case 'analyze_content_gaps':
                if (!$client_id) wp_send_json_error(['message' => 'No client selected']);
                $result = AutomationEngine::analyzeContentGaps($client_id);
                wp_send_json_success(['message' => "Gaps found: {$result['gaps_found']}, Jobs queued: {$result['jobs_created']}", 'data' => $result]);
                break;

            case 'process_queue':
                $result = AutomationEngine::processContentQueue(5);
                wp_send_json_success(['message' => "Processed: {$result['processed']}, Succeeded: {$result['succeeded']}, Failed: {$result['failed']}", 'data' => $result]);
                break;

            case 'run_single_job':
                if (!$entity_id) wp_send_json_error(['message' => 'No job ID']);
                global $wpdb;
                $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wnq_seo_content_jobs WHERE id=%d", $entity_id), ARRAY_A);
                if (!$job) wp_send_json_error(['message' => 'Job not found']);
                SEOHub::updateContentJob($entity_id, ['status' => 'running']);
                $result = AutomationEngine::executeContentJob($job);
                if ($result['success']) {
                    SEOHub::updateContentJob($entity_id, ['status' => 'completed', 'output_content' => $result['content'], 'tokens_used' => $result['tokens_used'] ?? 0]);
                    wp_send_json_success(['message' => 'Job completed successfully', 'content' => $result['content']]);
                } else {
                    SEOHub::updateContentJob($entity_id, ['status' => 'failed', 'error_message' => $result['error']]);
                    wp_send_json_error(['message' => $result['error']]);
                }
                break;

            case 'approve_job':
                if (!$entity_id) wp_send_json_error(['message' => 'No job ID']);
                $user = wp_get_current_user();
                SEOHub::updateContentJob($entity_id, ['approved' => 1, 'approved_by' => $user->user_login, 'status' => 'completed']);
                wp_send_json_success(['message' => 'Content approved']);
                break;

            case 'resolve_finding':
                if (!$entity_id) wp_send_json_error(['message' => 'No finding ID']);
                SEOHub::resolveAuditFinding($entity_id);
                wp_send_json_success(['message' => 'Finding resolved']);
                break;

            case 'fix_seo_issues':
                // Legacy single-shot call — kept for backwards compat
                if (!$client_id) wp_send_json_error(['message' => 'No client selected']);
                $result = \WNQ\Services\SEOHealthFixer::runBatch($client_id);
                $msg = "Batch complete — Fixed: {$result['fixed']}, Failed: {$result['failed']}, Remaining: {$result['remaining']}";
                if (!empty($result['error'])) $msg .= ' — ' . $result['error'];
                wp_send_json_success(['message' => $msg, 'data' => $result]);
                break;

            case 'fix_seo_batch':
                // Pulse endpoint — called repeatedly by the JS progress bar
                if (!$client_id) wp_send_json_error(['message' => 'No client selected']);
                $result = \WNQ\Services\SEOHealthFixer::runBatch($client_id);
                wp_send_json_success($result);
                break;

            case 'fix_seo_count':
                // Returns total fixable page count for initialising the progress bar
                if (!$client_id) wp_send_json_error(['message' => 'No client selected']);
                $count = \WNQ\Services\SEOHealthFixer::countFixablePages($client_id);
                wp_send_json_success(['total' => $count]);
                break;

            case 'generate_report':
                if (!$client_id) wp_send_json_error(['message' => 'No client selected']);
                $id = \WNQ\Services\ReportGenerator::generateMonthlyReport($client_id);
                if ($id) {
                    wp_send_json_success(['message' => 'Report generated (ID #' . $id . ')', 'report_id' => $id]);
                } else {
                    wp_send_json_error(['message' => 'Report generation failed']);
                }
                break;

            case 'generate_all_reports':
                $result = \WNQ\Services\ReportGenerator::generateAllMonthlyReports();
                wp_send_json_success(['message' => "Reports: generated={$result['generated']}, skipped={$result['skipped']}, failed={$result['failed']}", 'data' => $result]);
                break;

            case 'install_tables':
                SEOHub::createTables();
                wp_send_json_success(['message' => 'All SEO OS database tables created / verified successfully.']);
                break;

            case 'test_ai_connection':
                $result = AIEngine::testConnection();
                if ($result['success']) {
                    wp_send_json_success(['message' => 'AI connection successful! Provider is working.', 'content' => substr($result['content'], 0, 200)]);
                } else {
                    wp_send_json_error(['message' => 'Connection failed: ' . ($result['error'] ?? 'Unknown error')]);
                }
                break;

            default:
                wp_send_json_error(['message' => 'Unknown action: ' . $action]);
        }
    }

    // ── Shared Helpers ─────────────────────────────────────────────────────

    private static function checkCap(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied.');
        }
    }

    private static function renderHeader(string $title): void
    {
        echo '<div class="wrap wnq-hub-wrap">';
        echo '<div class="wnq-hub-masthead">';
        echo '<div class="wnq-hub-logo">🔭 WebNique<span>SEO OS</span></div>';
        echo '<nav class="wnq-hub-nav">';
        $nav_items = [
            'wnq-seo-hub'          => 'Dashboard',
            'wnq-seo-hub-clients'  => 'Clients',
            'wnq-seo-hub-keywords' => 'Keywords',
            'wnq-seo-hub-content'  => 'Content',
            'wnq-seo-hub-audits'   => 'Audits',
            'wnq-seo-hub-reports'  => 'Reports',
            'wnq-seo-hub-blog'     => 'Blog Scheduler',
            'wnq-seo-hub-api'      => 'API',
            'wnq-seo-hub-settings' => 'Settings',
        ];
        $current = $_GET['page'] ?? 'wnq-seo-hub';
        foreach ($nav_items as $slug => $label) {
            $active = $current === $slug ? 'active' : '';
            echo '<a href="' . admin_url('admin.php?page=' . $slug) . '" class="' . $active . '">' . $label . '</a>';
        }
        echo '</nav></div>';
        echo '<h1 class="wnq-hub-page-title">' . esc_html($title) . '</h1>';
    }

    private static function renderFooter(): void
    {
        echo '</div>'; // .wnq-hub-wrap
    }
}
