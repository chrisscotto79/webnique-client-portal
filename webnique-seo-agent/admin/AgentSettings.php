<?php
/**
 * Agent Settings Page
 *
 * WordPress admin settings for the WebNique SEO Agent plugin.
 * Located at: Settings → WebNique SEO Agent
 *
 * @package WebNique SEO Agent
 */

namespace WNQA\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WNQA\LocalChecks;
use WNQA\APISync;

final class AgentSettings
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addSettingsPage']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function addSettingsPage(): void
    {
        add_options_page(
            'WebNique SEO Agent',
            'WebNique SEO Agent',
            'manage_options',
            WNQA_SLUG,
            [self::class, 'renderPage']
        );
    }

    public static function registerSettings(): void
    {
        register_setting(WNQA_SLUG, 'wnqa_config', [
            'sanitize_callback' => [self::class, 'sanitizeConfig'],
        ]);
    }

    public static function sanitizeConfig($data): array
    {
        // WordPress may pass null/false if the option isn't in $_POST (first run,
        // nonce failure, etc.). Guard here so PHP 8 doesn't throw a TypeError.
        if (!is_array($data)) {
            $data = [];
        }

        // extra_post_types is stored as array but submitted as a comma string —
        // handle both so round-trips through the sanitize callback don't crash.
        $extra_raw = $data['extra_post_types'] ?? '';
        if (is_array($extra_raw)) {
            $extra_types = array_map('sanitize_key', array_filter($extra_raw));
        } else {
            $extra_types = array_map('sanitize_key', array_filter(explode(',', (string)$extra_raw)));
        }

        return [
            'hub_url'          => esc_url_raw(trim($data['hub_url'] ?? '')),
            'api_key'          => sanitize_text_field(trim($data['api_key'] ?? '')),
            'sync_frequency'   => sanitize_text_field($data['sync_frequency'] ?? 'twicedaily'),
            'batch_size'       => max(10, min(500, (int)($data['batch_size'] ?? 100))),
            'thin_threshold'   => max(100, min(1000, (int)($data['thin_threshold'] ?? 300))),
            'extra_post_types' => $extra_types,
            'debug_mode'       => !empty($data['debug_mode']) ? 1 : 0,
        ];
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_' . WNQA_SLUG) return;
        // Inline minimal styles
        echo '<style>
        .wnqa-wrap { max-width: 800px; }
        .wnqa-card { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 16px 0; }
        .wnqa-card h2 { margin: 0 0 16px; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        .wnqa-status-bar { display: flex; gap: 16px; flex-wrap: wrap; margin: 16px 0; }
        .wnqa-stat { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 12px 16px; text-align: center; min-width: 100px; }
        .wnqa-stat .val { font-size: 28px; font-weight: 800; color: #0d539e; }
        .wnqa-stat .lbl { font-size: 11px; color: #6b7280; text-transform: uppercase; }
        .wnqa-stat.warn .val { color: #d97706; }
        .wnqa-stat.danger .val { color: #dc2626; }
        .wnqa-form-row { display: grid; grid-template-columns: 200px 1fr; gap: 12px; margin-bottom: 12px; align-items: start; }
        .wnqa-form-row label { font-weight: 600; padding-top: 8px; }
        .wnqa-form-row input[type=text], .wnqa-form-row input[type=url], .wnqa-form-row input[type=password],
        .wnqa-form-row input[type=number], .wnqa-form-row select { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .wnqa-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 10px 14px; border-radius: 6px; margin: 12px 0; }
        .wnqa-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; padding: 10px 14px; border-radius: 6px; margin: 12px 0; }
        .wnqa-log { background: #1f2937; color: #d1fae5; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 12px; max-height: 200px; overflow-y: auto; }
        .wnqa-log .ts { color: #6b7280; }
        </style>';
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) wp_die('Access denied');

        $config  = get_option('wnqa_config', []);
        $checks  = (new LocalChecks())->getSummary();
        $last_sync = get_option('wnqa_last_sync', 'Never');
        $last_ping = get_option('wnqa_last_ping', 'Never');
        $sync_count= get_option('wnqa_last_sync_count', 0);
        $sync_log  = get_option('wnqa_sync_log', []);

        // Notices
        $sync_status = sanitize_text_field($_GET['sync'] ?? '');
        $test_status = sanitize_text_field($_GET['test'] ?? '');
        $msg         = sanitize_text_field(urldecode($_GET['msg'] ?? ''));

        ?>
<div class="wrap wnqa-wrap">
  <h1>🔭 WebNique SEO Agent</h1>
  <p style="color:#6b7280;">Version <?php echo WNQA_VERSION; ?> &bull; Data relay to WebNique SEO OS Hub</p>

  <?php if ($sync_status === 'success'): ?>
  <div class="wnqa-success">✅ Sync successful! <?php echo esc_html($msg); ?></div>
  <?php elseif ($sync_status === 'error'): ?>
  <div class="wnqa-error">❌ Sync failed: <?php echo esc_html($msg); ?></div>
  <?php endif; ?>

  <?php if ($test_status === 'success'): ?>
  <div class="wnqa-success">✅ Connection test passed! Hub is reachable.</div>
  <?php elseif ($test_status === 'error'): ?>
  <div class="wnqa-error">❌ Connection test failed: <?php echo esc_html($msg); ?></div>
  <?php endif; ?>

  <!-- Status Overview -->
  <div class="wnqa-card">
    <h2>📊 Status</h2>
    <div class="wnqa-status-bar">
      <div class="wnqa-stat"><div class="val"><?php echo esc_html($sync_count); ?></div><div class="lbl">Pages Synced</div></div>
      <div class="wnqa-stat <?php echo $checks['missing_h1'] ?? 0 ? 'danger' : ''; ?>"><div class="val"><?php echo $checks['missing_h1'] ?? '—'; ?></div><div class="lbl">Missing H1</div></div>
      <div class="wnqa-stat <?php echo $checks['missing_alt'] ?? 0 ? 'warn' : ''; ?>"><div class="val"><?php echo $checks['missing_alt'] ?? '—'; ?></div><div class="lbl">Missing Alt</div></div>
      <div class="wnqa-stat <?php echo $checks['thin_content'] ?? 0 ? 'warn' : ''; ?>"><div class="val"><?php echo $checks['thin_content'] ?? '—'; ?></div><div class="lbl">Thin Content</div></div>
      <div class="wnqa-stat"><div class="val"><?php echo $checks['no_internal_links'] ?? '—'; ?></div><div class="lbl">No Int. Links</div></div>
    </div>
    <p style="font-size:12px;color:#6b7280;">
      Last sync: <strong><?php echo esc_html($last_sync); ?></strong> &bull;
      Last ping: <strong><?php echo esc_html($last_ping); ?></strong>
    </p>

    <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
      <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <?php wp_nonce_field('wnqa_manual_sync'); ?>
        <input type="hidden" name="action" value="wnqa_manual_sync">
        <button type="submit" class="button button-primary">⚡ Sync to Hub Now</button>
      </form>
      <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <?php wp_nonce_field('wnqa_test_connection'); ?>
        <input type="hidden" name="action" value="wnqa_test_connection">
        <button type="submit" class="button">🔌 Test Connection</button>
      </form>
    </div>
  </div>

  <!-- Settings Form -->
  <div class="wnqa-card">
    <h2>⚙️ Configuration</h2>
    <form method="post" action="options.php">
      <?php settings_fields(WNQA_SLUG); ?>

      <div class="wnqa-form-row">
        <label>Hub URL</label>
        <div>
          <input type="url" name="wnqa_config[hub_url]" value="<?php echo esc_attr($config['hub_url'] ?? ''); ?>" placeholder="https://web-nique.com" required>
          <p class="description">URL of your WebNique SEO OS hub (your web-nique.com site)</p>
        </div>
      </div>

      <div class="wnqa-form-row">
        <label>API Key</label>
        <div>
          <input type="password" name="wnqa_config[api_key]" value="<?php echo esc_attr($config['api_key'] ?? ''); ?>" placeholder="wnq_..." required>
          <p class="description">Generate this key in your hub under <em>SEO OS → API Management</em></p>
        </div>
      </div>

      <div class="wnqa-form-row">
        <label>Sync Frequency</label>
        <div>
          <select name="wnqa_config[sync_frequency]">
            <?php foreach (['hourly' => 'Hourly', 'twicedaily' => 'Twice Daily (Recommended)', 'daily' => 'Daily'] as $v => $l): ?>
            <option value="<?php echo $v; ?>" <?php selected($config['sync_frequency'] ?? 'twicedaily', $v); ?>><?php echo $l; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="wnqa-form-row">
        <label>Pages per Batch</label>
        <div>
          <input type="number" name="wnqa_config[batch_size]" value="<?php echo esc_attr($config['batch_size'] ?? 100); ?>" min="10" max="500">
          <p class="description">Pages collected per sync cycle (lower if timing out)</p>
        </div>
      </div>

      <div class="wnqa-form-row">
        <label>Thin Content Threshold</label>
        <div>
          <input type="number" name="wnqa_config[thin_threshold]" value="<?php echo esc_attr($config['thin_threshold'] ?? 300); ?>" min="100" max="1000">
          <p class="description">Pages with fewer words than this are flagged as thin content</p>
        </div>
      </div>

      <div class="wnqa-form-row">
        <label>Extra Post Types</label>
        <div>
          <input type="text" name="wnqa_config[extra_post_types]" value="<?php echo esc_attr(implode(',', (array)($config['extra_post_types'] ?? []))); ?>" placeholder="product,portfolio">
          <p class="description">Comma-separated custom post types to include in sync</p>
        </div>
      </div>

      <div class="wnqa-form-row">
        <label>Debug Mode</label>
        <div>
          <label>
            <input type="checkbox" name="wnqa_config[debug_mode]" value="1" <?php checked(!empty($config['debug_mode'])); ?>>
            Log detailed sync information
          </label>
        </div>
      </div>

      <?php submit_button('Save Settings'); ?>
    </form>
  </div>

  <!-- Sync Log -->
  <?php if (!empty($sync_log)): ?>
  <div class="wnqa-card">
    <h2>📋 Sync Log (last 50)</h2>
    <div class="wnqa-log">
      <?php foreach (array_reverse($sync_log) as $entry): ?>
      <div><span class="ts">[<?php echo esc_html($entry['time']); ?>]</span> [<?php echo esc_html($entry['action']); ?>] <?php echo esc_html($entry['message']); ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Local Check Results -->
  <?php if (!empty($checks['run'])): ?>
  <div class="wnqa-card">
    <h2>🔍 Last Local Check Results</h2>
    <p style="font-size:12px;color:#6b7280;">Checked <?php echo esc_html($checks['total_pages'] ?? 0); ?> pages at <?php echo esc_html($checks['checked_at'] ?? ''); ?></p>
    <table class="widefat striped" style="margin-top:8px;">
      <thead><tr><th>Check</th><th>Count</th><th>Status</th></tr></thead>
      <tbody>
        <tr><td>Missing H1 Tags</td><td><?php echo $checks['missing_h1']; ?></td><td><?php echo $checks['missing_h1'] > 0 ? '❌ Needs Fix' : '✅ Good'; ?></td></tr>
        <tr><td>Missing Alt Text</td><td><?php echo $checks['missing_alt']; ?></td><td><?php echo $checks['missing_alt'] > 0 ? '⚠️ Warning' : '✅ Good'; ?></td></tr>
        <tr><td>No Internal Links (posts)</td><td><?php echo $checks['no_internal_links']; ?></td><td><?php echo $checks['no_internal_links'] > 0 ? '⚠️ Warning' : '✅ Good'; ?></td></tr>
        <tr><td>Thin Content (<?php echo esc_html($config['thin_threshold'] ?? 300); ?> words)</td><td><?php echo $checks['thin_content']; ?></td><td><?php echo $checks['thin_content'] > 0 ? '⚠️ Warning' : '✅ Good'; ?></td></tr>
      </tbody>
    </table>
    <p style="margin-top:12px;font-size:12px;color:#6b7280;">These findings are also synced to your hub for centralized analysis.</p>
  </div>
  <?php endif; ?>

  <!-- Info Box -->
  <div class="wnqa-card" style="background:#f0f9ff;border-color:#bae6fd;">
    <h2>ℹ️ About This Plugin</h2>
    <p>This plugin is a <strong>lightweight data relay</strong>. It:</p>
    <ul style="list-style:disc;margin-left:20px;line-height:2;">
      <li>Collects SEO data from your WordPress content</li>
      <li>Sends it securely to your WebNique SEO OS hub</li>
      <li>Receives and acknowledges automation instructions</li>
      <li>Runs lightweight local checks (H1, alt text, thin content)</li>
    </ul>
    <p style="margin-top:8px;">All SEO analysis, AI content generation, keyword tracking, and reporting happens on your hub. This plugin does NOT slow down your site.</p>
  </div>
</div>
        <?php
    }
}
