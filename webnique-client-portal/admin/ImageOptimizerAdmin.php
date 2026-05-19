<?php
/**
 * Image Optimizer admin screen.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Admin;

use WNQ\Services\ImageOptimizer;
use WNQ\Services\ImageScanner;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageOptimizerAdmin
{
    private const PAGE_SLUG = 'wnq-seo-hub-images';

    public static function register(): void
    {
        add_action('admin_post_wnq_image_optimizer_action', [self::class, 'handleManualAction']);
        add_action('admin_post_wnq_image_optimizer_refresh', [self::class, 'handleRefreshScan']);
        add_action('admin_post_wnq_image_optimizer_export', [self::class, 'handleCsvExport']);
        add_action('admin_post_wnq_image_optimizer_save_settings', [self::class, 'handleSaveSettings']);
        add_action('wp_ajax_wnq_image_optimizer_batch', [self::class, 'ajaxBatch']);
    }

    public static function renderPage(): void
    {
        self::checkCap();

        $settings = ImageOptimizer::getSettings();
        $stats = ImageScanner::getStats();
        $filter = sanitize_key(wp_unslash($_GET['image_filter'] ?? 'all'));
        $sort = sanitize_key(wp_unslash($_GET['sort'] ?? 'date'));
        $order = strtolower(sanitize_text_field(wp_unslash($_GET['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $per_page = max(5, min(50, absint($_GET['per_page'] ?? 20)));

        $result = ImageScanner::getRows([
            'page'     => $paged,
            'per_page' => $per_page,
            'filter'   => $filter,
            'sort'     => $sort,
            'order'    => $order,
        ]);

        self::renderHeader('SEO OS - Image Optimizer');
        self::renderNotices();
        ?>
<div class="wnq-image-optimizer">
  <?php if (empty($settings['enabled'])): ?>
    <div class="wnq-image-notice warning">
      Image Optimizer is disabled in settings. Scanning is available, but optimization actions are blocked until it is enabled.
    </div>
  <?php endif; ?>

  <div class="wnq-image-summary">
    <?php self::renderStatCard('Total Images', (string)number_format((int)$stats['total'])); ?>
    <?php self::renderStatCard('Large Images', (string)number_format((int)$stats['over_warning']), 'Over ' . (int)$settings['warning_threshold_kb'] . ' KB'); ?>
    <?php self::renderStatCard('Critical Images', (string)number_format((int)$stats['over_critical']), 'Over ' . (int)$settings['critical_threshold_kb'] . ' KB', 'danger'); ?>
    <?php self::renderStatCard('Missing Alt Text', (string)number_format((int)$stats['missing_alt']), '', 'warning'); ?>
    <?php self::renderStatCard('WebP Generated', (string)number_format((int)$stats['with_webp'])); ?>
    <?php self::renderStatCard('Total Savings', self::formatBytes((int)$stats['estimated_savings'])); ?>
  </div>

  <div class="wnq-image-toolbar">
    <form method="get" class="wnq-image-filter-form">
      <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
      <label>
        Filter
        <select name="image_filter">
          <?php foreach (self::filters() as $value => $label): ?>
            <option value="<?php echo esc_attr($value); ?>" <?php selected($filter, $value); ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        Sort
        <select name="sort">
          <?php foreach (self::sorts() as $value => $label): ?>
            <option value="<?php echo esc_attr($value); ?>" <?php selected($sort, $value); ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        Order
        <select name="order">
          <option value="desc" <?php selected($order, 'desc'); ?>>Descending</option>
          <option value="asc" <?php selected($order, 'asc'); ?>>Ascending</option>
        </select>
      </label>
      <label>
        Per page
        <select name="per_page">
          <?php foreach ([10, 20, 30, 50] as $option): ?>
            <option value="<?php echo (int)$option; ?>" <?php selected($per_page, $option); ?>><?php echo (int)$option; ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="wnq-btn wnq-btn-primary">Apply</button>
    </form>

    <div class="wnq-image-toolbar-actions">
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wnq_image_optimizer_refresh'); ?>
        <input type="hidden" name="action" value="wnq_image_optimizer_refresh">
        <button type="submit" class="wnq-btn">Refresh Scan</button>
      </form>
      <a class="wnq-btn" href="<?php echo esc_url(self::exportUrl($filter)); ?>">Export CSV</a>
    </div>
  </div>

  <div class="wnq-image-batch-bar">
    <label>
      Batch action
      <select id="wnq-image-batch-action">
        <option value="optimize">Optimize selected</option>
        <option value="generate_webp">Generate WebP</option>
        <option value="resize">Resize</option>
        <option value="compress">Compress</option>
        <option value="restore">Restore backup</option>
      </select>
    </label>
    <button type="button" class="wnq-btn wnq-btn-primary" id="wnq-image-run-batch">Run Batch</button>
    <span id="wnq-image-batch-progress" class="wnq-image-progress"></span>
  </div>

  <div class="wnq-image-table-scroll" role="region" aria-label="Image optimizer audit table" tabindex="0">
  <table class="widefat striped wnq-image-table">
    <thead>
      <tr>
        <th class="check-column"><input type="checkbox" id="wnq-image-select-all"></th>
        <th>Thumbnail</th>
        <th>Attachment ID</th>
        <th>File</th>
        <th>Type</th>
        <th>Size</th>
        <th>Dimensions</th>
        <th>Attached To</th>
        <th>Alt Text</th>
        <th>Oversized</th>
        <th>WebP</th>
        <th>Optimized</th>
        <th>Before / After</th>
        <th>Recommended Action</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($result['rows'])): ?>
        <tr><td colspan="15" class="wnq-image-empty">No image attachments found for this filter.</td></tr>
      <?php else: ?>
        <?php foreach ($result['rows'] as $row): ?>
          <?php self::renderImageRow($row); ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php self::renderPagination((int)$result['page'], (int)$result['total_pages'], $filter, $sort, $order, $per_page); ?>
</div>
<?php self::renderBatchScript(); ?>
        <?php
        self::renderFooter();
    }

    public static function renderSettingsSection(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = ImageOptimizer::getSettings();
        ?>
<div id="image-optimizer" class="wnq-hub-section">
  <h2>Image Optimizer Settings</h2>
  <p class="wnq-muted">Controls for Media Library scanning, safe resizing, compression, backups, and WebP generation.</p>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('wnq_image_optimizer_save_settings'); ?>
    <input type="hidden" name="action" value="wnq_image_optimizer_save_settings">
    <div class="wnq-hub-form-grid">
      <?php self::renderCheckboxField('enabled', 'Enable Image Optimizer module', $settings); ?>
      <?php self::renderNumberField('warning_threshold_kb', 'Warning size threshold (KB)', $settings, 50, 5000); ?>
      <?php self::renderNumberField('high_threshold_kb', 'High priority size threshold (KB)', $settings, 100, 10000); ?>
      <?php self::renderNumberField('critical_threshold_kb', 'Critical size threshold (KB)', $settings, 250, 50000); ?>
      <?php self::renderNumberField('max_width', 'Max image width', $settings, 600, 5000); ?>
      <?php self::renderNumberField('max_height', 'Max image height', $settings, 600, 5000); ?>
      <?php self::renderNumberField('jpeg_quality', 'JPEG quality', $settings, 40, 100); ?>
      <?php self::renderNumberField('webp_quality', 'WebP quality', $settings, 40, 100); ?>
      <?php self::renderCheckboxField('backup_originals', 'Backup originals before modifying', $settings); ?>
      <?php self::renderCheckboxField('show_missing_alt', 'Show images missing alt text', $settings); ?>
      <?php self::renderCheckboxField('show_webp_status', 'Show WebP status', $settings); ?>
    </div>
    <button type="submit" class="wnq-btn wnq-btn-primary">Save Image Optimizer Settings</button>
  </form>
</div>
        <?php
    }

    public static function handleManualAction(): void
    {
        self::checkCap();
        $attachment_id = absint($_GET['attachment_id'] ?? 0);
        check_admin_referer('wnq_image_optimizer_action_' . $attachment_id);

        $image_action = sanitize_key(wp_unslash($_GET['image_action'] ?? ''));
        $result = self::runAction($attachment_id, $image_action);
        self::redirectWithResult($result);
    }

    public static function handleRefreshScan(): void
    {
        self::checkCap();
        check_admin_referer('wnq_image_optimizer_refresh');
        ImageScanner::getStats(true);
        self::redirectWithResult(['success' => true, 'message' => 'Image scan refreshed.']);
    }

    public static function handleSaveSettings(): void
    {
        self::checkCap();
        check_admin_referer('wnq_image_optimizer_save_settings');
        ImageOptimizer::saveSettings(wp_unslash($_POST));
        wp_safe_redirect(add_query_arg([
            'page'                  => 'wnq-seo-hub-settings',
            'image_optimizer_saved' => 1,
        ], admin_url('admin.php')) . '#image-optimizer');
        exit;
    }

    public static function handleCsvExport(): void
    {
        self::checkCap();
        check_admin_referer('wnq_image_optimizer_export');

        $filter = sanitize_key(wp_unslash($_GET['image_filter'] ?? 'all'));
        $export_rows = [];
        $page = 1;
        do {
            $result = ImageScanner::getRows([
                'page'     => $page,
                'per_page' => 50,
                'filter'   => $filter,
                'sort'     => 'file_name',
                'order'    => 'asc',
            ]);
            $export_rows = array_merge($export_rows, (array)$result['rows']);
            $page++;
        } while ($page <= (int)$result['total_pages'] && $page <= 40);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=seo-os-image-audit-' . gmdate('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        if (!$output) {
            wp_die('Could not open CSV output stream.');
        }
        fputcsv($output, [
            'Attachment ID',
            'File name',
            'URL',
            'File type',
            'File size',
            'Width',
            'Height',
            'Alt text',
            'Attached to',
            'WebP exists',
            'Optimized',
            'Original size',
            'Current size',
            'Savings percent',
            'Recommended action',
        ]);

        foreach ($export_rows as $row) {
            fputcsv($output, [
                (int)$row['id'],
                $row['file_name'],
                $row['url'],
                $row['file_type'],
                (int)$row['file_size'],
                (int)$row['width'],
                (int)$row['height'],
                $row['alt_text'],
                $row['attached_to_title'],
                !empty($row['webp_exists']) ? 'yes' : 'no',
                !empty($row['optimized']) ? 'yes' : 'no',
                (int)$row['original_size'],
                (int)$row['current_size'],
                (float)$row['savings_percent'],
                $row['recommendation'],
            ]);
        }
        fclose($output);
        exit;
    }

    public static function ajaxBatch(): void
    {
        self::checkCap();
        check_ajax_referer('wnq_image_optimizer_batch', 'nonce');

        $image_action = sanitize_key(wp_unslash($_POST['image_action'] ?? ''));
        $ids = array_slice(array_map('absint', (array)wp_unslash($_POST['attachment_ids'] ?? [])), 0, 5);
        $items = [];

        foreach ($ids as $attachment_id) {
            if ($attachment_id <= 0) {
                continue;
            }
            $result = self::runAction($attachment_id, $image_action);
            $items[] = [
                'id'      => $attachment_id,
                'success' => !empty($result['success']),
                'message' => $result['message'] ?? '',
            ];
        }

        wp_send_json_success(['items' => $items]);
    }

    private static function runAction(int $attachment_id, string $image_action): array
    {
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return ['success' => false, 'message' => 'Invalid attachment ID.'];
        }

        $settings = ImageOptimizer::getSettings();
        if (empty($settings['enabled']) && $image_action !== 'restore') {
            return ['success' => false, 'message' => 'Image Optimizer is disabled in settings.'];
        }

        return match ($image_action) {
            'generate_webp' => ImageOptimizer::generateWebp($attachment_id),
            'resize'        => ImageOptimizer::resize($attachment_id),
            'compress'      => ImageOptimizer::compress($attachment_id),
            'optimize'      => ImageOptimizer::optimize($attachment_id),
            'restore'       => ImageOptimizer::restoreBackup($attachment_id),
            default         => ['success' => false, 'message' => 'Unknown image optimizer action.'],
        };
    }

    private static function renderImageRow(array $row): void
    {
        $priority = sanitize_html_class((string)$row['priority']);
        ?>
<tr>
  <th class="check-column">
    <input type="checkbox" class="wnq-image-select" value="<?php echo (int)$row['id']; ?>">
  </th>
  <td>
    <?php if (!empty($row['thumbnail'])): ?>
      <img src="<?php echo esc_url($row['thumbnail']); ?>" alt="" class="wnq-image-thumb">
    <?php endif; ?>
  </td>
  <td><code><?php echo (int)$row['id']; ?></code></td>
  <td>
    <strong><?php echo esc_html($row['file_name']); ?></strong>
    <?php if (!empty($row['url'])): ?>
      <br><a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener">Open file</a>
    <?php endif; ?>
  </td>
  <td><?php echo esc_html($row['file_type']); ?></td>
  <td><strong><?php echo esc_html(self::formatBytes((int)$row['file_size'])); ?></strong></td>
  <td><?php echo (int)$row['width']; ?> x <?php echo (int)$row['height']; ?></td>
  <td>
    <?php if (!empty($row['attached_to'])): ?>
      <a href="<?php echo esc_url(get_edit_post_link((int)$row['attached_to'])); ?>"><?php echo esc_html($row['attached_to_title']); ?></a>
    <?php else: ?>
      <span class="wnq-muted">Unattached</span>
    <?php endif; ?>
  </td>
  <td><?php echo !empty($row['missing_alt']) ? '<span class="wnq-image-pill danger">Missing</span>' : '<span class="wnq-image-pill good">OK</span>'; ?></td>
  <td><?php echo !empty($row['oversized']) ? '<span class="wnq-image-pill warning">Oversized</span>' : '<span class="wnq-image-pill good">OK</span>'; ?></td>
  <td><?php echo !empty($row['webp_exists']) ? '<span class="wnq-image-pill good">Exists</span>' : '<span class="wnq-image-pill warning">No WebP</span>'; ?></td>
  <td>
    <?php if (!empty($row['optimized'])): ?>
      <span class="wnq-image-pill good">Optimized</span>
      <br><small><?php echo esc_html((string)$row['optimized_at']); ?></small>
      <?php if ((float)$row['savings_percent'] > 0): ?>
        <br><small><?php echo esc_html((string)$row['savings_percent']); ?>% saved</small>
      <?php endif; ?>
    <?php else: ?>
      <span class="wnq-image-pill neutral">Not yet</span>
    <?php endif; ?>
  </td>
  <td><?php self::renderSizeChange($row); ?></td>
  <td><span class="wnq-image-recommend <?php echo esc_attr($priority); ?>"><?php echo esc_html($row['recommendation']); ?></span></td>
  <td class="wnq-image-row-actions">
    <a class="wnq-mini-btn" href="<?php echo esc_url(self::actionUrl((int)$row['id'], 'generate_webp')); ?>">WebP</a>
    <a class="wnq-mini-btn primary" href="<?php echo esc_url(self::actionUrl((int)$row['id'], 'optimize')); ?>">Optimize</a>
    <?php if ((string)get_post_meta((int)$row['id'], '_seo_os_backup_path', true) !== ''): ?>
      <a class="wnq-mini-btn" href="<?php echo esc_url(self::actionUrl((int)$row['id'], 'restore')); ?>" onclick="return confirm('Restore this image from its SEO OS backup?');">Restore</a>
    <?php endif; ?>
    <?php $edit_link = get_edit_post_link((int)$row['id']); ?>
    <?php if ($edit_link): ?>
      <a class="wnq-mini-btn" href="<?php echo esc_url($edit_link); ?>">View</a>
    <?php endif; ?>
  </td>
</tr>
        <?php
    }

    private static function renderSizeChange(array $row): void
    {
        $before = (int)($row['original_size'] ?? 0);
        $after = (int)($row['current_size'] ?? $row['file_size'] ?? 0);
        $savings_percent = (float)($row['savings_percent'] ?? 0);

        if ($before <= 0 && empty($row['optimized'])) {
            echo '<span class="wnq-muted">Not optimized yet</span>';
            return;
        }

        echo '<span class="wnq-image-size-change">';
        if ($before > 0) {
            echo '<strong>Before:</strong> ' . esc_html(self::formatBytes($before)) . '<br>';
        } else {
            echo '<strong>Before:</strong> <span class="wnq-muted">Not recorded</span><br>';
        }
        echo '<strong>After:</strong> ' . esc_html(self::formatBytes($after));
        if ($savings_percent > 0) {
            echo '<br><small>' . esc_html((string)$savings_percent) . '% saved</small>';
        } elseif ($before > 0 && $after > $before) {
            $increase = round((($after - $before) / $before) * 100, 2);
            echo '<br><small class="wnq-image-size-increase">' . esc_html((string)$increase) . '% larger</small>';
        }
        echo '</span>';
    }

    private static function renderPagination(int $page, int $total_pages, string $filter, string $sort, string $order, int $per_page): void
    {
        if ($total_pages <= 1) {
            return;
        }

        $links = paginate_links([
            'base'      => add_query_arg([
                'page'         => self::PAGE_SLUG,
                'image_filter' => $filter,
                'sort'         => $sort,
                'order'        => $order,
                'per_page'     => $per_page,
                'paged'        => '%#%',
            ], admin_url('admin.php')),
            'format'    => '',
            'current'   => $page,
            'total'     => $total_pages,
            'type'      => 'array',
            'prev_text' => 'Previous',
            'next_text' => 'Next',
        ]);

        if (empty($links)) {
            return;
        }

        echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(implode('', $links)) . '</div></div>';
    }

    private static function renderBatchScript(): void
    {
        $nonce = wp_create_nonce('wnq_image_optimizer_batch');
        ?>
<script>
(function() {
  const selectAll = document.getElementById('wnq-image-select-all');
  const runButton = document.getElementById('wnq-image-run-batch');
  const progress = document.getElementById('wnq-image-batch-progress');

  if (selectAll) {
    selectAll.addEventListener('change', function() {
      document.querySelectorAll('.wnq-image-select').forEach(function(box) {
        box.checked = selectAll.checked;
      });
    });
  }

  if (!runButton) {
    return;
  }

  runButton.addEventListener('click', function() {
    const ids = Array.from(document.querySelectorAll('.wnq-image-select:checked')).map(function(box) {
      return box.value;
    });
    const imageAction = document.getElementById('wnq-image-batch-action').value;
    if (!ids.length) {
      progress.textContent = 'Select at least one image.';
      return;
    }
    if (!window.confirm('Run this action on ' + ids.length + ' selected image(s)?')) {
      return;
    }

    runButton.disabled = true;
    progress.textContent = 'Starting...';
    const chunks = [];
    for (let i = 0; i < ids.length; i += 5) {
      chunks.push(ids.slice(i, i + 5));
    }
    let completed = 0;
    let failures = 0;

    const runChunk = function(index) {
      if (index >= chunks.length) {
        progress.textContent = 'Done. ' + completed + ' processed' + (failures ? ', ' + failures + ' failed.' : '.');
        runButton.disabled = false;
        setTimeout(function() { window.location.reload(); }, 900);
        return;
      }

      const form = new window.FormData();
      form.append('action', 'wnq_image_optimizer_batch');
      form.append('nonce', '<?php echo esc_js($nonce); ?>');
      form.append('image_action', imageAction);
      chunks[index].forEach(function(id) {
        form.append('attachment_ids[]', id);
      });

      window.fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        body: form
      }).then(function(response) {
        return response.json();
      }).then(function(json) {
        const items = json && json.data && json.data.items ? json.data.items : [];
        items.forEach(function(item) {
          completed++;
          if (!item.success) {
            failures++;
          }
        });
        progress.textContent = 'Processed ' + completed + ' of ' + ids.length + '...';
        runChunk(index + 1);
      }).catch(function() {
        failures += chunks[index].length;
        completed += chunks[index].length;
        progress.textContent = 'Processed ' + completed + ' of ' + ids.length + '...';
        runChunk(index + 1);
      });
    };

    runChunk(0);
  });
})();
</script>
        <?php
    }

    private static function renderHeader(string $title): void
    {
        $current = sanitize_key(wp_unslash($_GET['page'] ?? self::PAGE_SLUG));
        $nav_items = [
            'wnq-seo-hub'              => 'Dashboard',
            'wnq-seo-hub-clients'      => 'Clients',
            'wnq-seo-hub-keywords'     => 'Keywords',
            'wnq-seo-hub-content'      => 'Service City Pages',
            'wnq-seo-hub-images'       => 'Image Optimizer',
            'wnq-seo-hub-reports'      => 'Reports',
            'wnq-seo-hub-blog'         => 'Blog Scheduler',
            'wnq-seo-hub-ai-elementor' => 'AI Elementor Builder',
            'wnq-seo-hub-api'          => 'API',
            'wnq-seo-hub-settings'     => 'Settings',
        ];

        echo '<div class="wrap wnq-hub-wrap">';
        echo '<div class="wnq-hub-masthead">';
        echo '<div class="wnq-hub-logo">Golden Web Marketing<span>SEO OS</span></div>';
        echo '<nav class="wnq-hub-nav">';
        foreach ($nav_items as $slug => $label) {
            $class = $current === $slug ? 'active' : '';
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav></div>';
        echo '<h1 class="wnq-hub-page-title">' . esc_html($title) . '</h1>';
        echo '<style>
            .wnq-hub-wrap{max-width:100%;overflow:hidden;box-sizing:border-box}
            .wnq-hub-masthead{max-width:100%;overflow-x:auto}
            .wnq-hub-nav{flex-wrap:wrap}
            .wnq-image-optimizer{max-width:100%;overflow:hidden}
            .wnq-image-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:14px;margin:18px 0 22px}
            .wnq-image-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
            .wnq-image-card .value{display:block;font-size:30px;line-height:1;font-weight:800;color:#1f5aa6}
            .wnq-image-card .label{display:block;margin-top:7px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.05em;font-size:12px}
            .wnq-image-card .hint{display:block;margin-top:4px;color:#6b7280;font-size:12px}
            .wnq-image-card.danger .value{color:#dc2626}.wnq-image-card.warning .value{color:#d97706}
            .wnq-image-toolbar,.wnq-image-batch-bar{display:flex;justify-content:space-between;gap:14px;align-items:center;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:14px}
            .wnq-image-filter-form,.wnq-image-toolbar-actions{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
            .wnq-image-filter-form label,.wnq-image-batch-bar label{font-weight:700;color:#374151}
            .wnq-image-filter-form select,.wnq-image-batch-bar select{display:block;min-width:145px;margin-top:4px}
            .wnq-image-table-scroll{width:100%;max-width:100%;overflow-x:auto;background:#fff;border:1px solid #d1d5db;border-radius:10px}
            .wnq-image-table{min-width:1450px;border:0;table-layout:fixed}
            .wnq-image-table th,.wnq-image-table td{vertical-align:middle;word-break:break-word}
            .wnq-image-table th:nth-child(1),.wnq-image-table td:nth-child(1){width:36px}
            .wnq-image-table th:nth-child(2),.wnq-image-table td:nth-child(2){width:76px}
            .wnq-image-table th:nth-child(3),.wnq-image-table td:nth-child(3){width:82px}
            .wnq-image-table th:nth-child(4),.wnq-image-table td:nth-child(4){width:210px}
            .wnq-image-table th:nth-child(6),.wnq-image-table td:nth-child(6){width:88px}
            .wnq-image-table th:nth-child(7),.wnq-image-table td:nth-child(7){width:112px}
            .wnq-image-table th:nth-child(13),.wnq-image-table td:nth-child(13){width:150px}
            .wnq-image-table th:nth-child(14),.wnq-image-table td:nth-child(14){width:150px}
            .wnq-image-table th:nth-child(15),.wnq-image-table td:nth-child(15){width:150px}
            .wnq-image-size-change{display:block;line-height:1.5}
            .wnq-image-size-increase{color:#991b1b;font-weight:800}
            .wnq-image-thumb{width:58px;height:58px;object-fit:cover;border-radius:8px;background:#f3f4f6;border:1px solid #e5e7eb}
            .wnq-image-pill{display:inline-block;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:800}
            .wnq-image-pill.good{background:#dcfce7;color:#166534}.wnq-image-pill.warning{background:#fef3c7;color:#92400e}.wnq-image-pill.danger{background:#fee2e2;color:#991b1b}.wnq-image-pill.neutral{background:#f3f4f6;color:#4b5563}
            .wnq-image-recommend{display:inline-block;border-radius:8px;padding:6px 8px;background:#f3f4f6;color:#374151;font-weight:700}
            .wnq-image-recommend.warning{background:#fef3c7;color:#92400e}.wnq-image-recommend.high{background:#ffedd5;color:#9a3412}.wnq-image-recommend.critical{background:#fee2e2;color:#991b1b}.wnq-image-recommend.good{background:#dcfce7;color:#166534}
            .wnq-image-row-actions{display:flex;gap:6px;flex-wrap:wrap;min-width:175px}
            .wnq-mini-btn{display:inline-flex;align-items:center;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;text-decoration:none;background:#fff;color:#1f2937;font-weight:700;font-size:12px}
            .wnq-mini-btn.primary{background:#255da8;border-color:#255da8;color:#fff}
            .wnq-image-progress{font-weight:700;color:#1f5aa6}
            .wnq-image-empty{text-align:center;padding:32px;color:#6b7280}
            .wnq-image-notice{border-radius:8px;padding:12px 14px;margin:12px 0;font-weight:700}.wnq-image-notice.success{background:#dcfce7;color:#166534;border:1px solid #86efac}.wnq-image-notice.error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}.wnq-image-notice.warning{background:#fef3c7;color:#92400e;border:1px solid #fcd34d}
            @media(max-width:1300px){.wnq-image-summary{grid-template-columns:repeat(3,minmax(0,1fr))}.wnq-image-toolbar{align-items:flex-start;flex-direction:column}}
            @media(max-width:782px){.wnq-image-summary{grid-template-columns:1fr}.wnq-image-toolbar,.wnq-image-batch-bar{align-items:flex-start;flex-direction:column}}
        </style>';
    }

    private static function renderFooter(): void
    {
        echo '</div>';
    }

    private static function renderNotices(): void
    {
        $message = sanitize_text_field(wp_unslash($_GET['wnq_message'] ?? ''));
        if ($message === '') {
            return;
        }

        $type = sanitize_key(wp_unslash($_GET['wnq_notice'] ?? 'success'));
        $class = $type === 'error' ? 'error' : 'success';
        echo '<div class="wnq-image-notice ' . esc_attr($class) . '">' . esc_html($message) . '</div>';
    }

    private static function renderStatCard(string $label, string $value, string $hint = '', string $class = ''): void
    {
        echo '<div class="wnq-image-card ' . esc_attr($class) . '">';
        echo '<span class="value">' . esc_html($value) . '</span>';
        echo '<span class="label">' . esc_html($label) . '</span>';
        if ($hint !== '') {
            echo '<span class="hint">' . esc_html($hint) . '</span>';
        }
        echo '</div>';
    }

    private static function renderNumberField(string $key, string $label, array $settings, int $min, int $max): void
    {
        echo '<div class="wnq-hub-form-group">';
        echo '<label>' . esc_html($label) . '</label>';
        echo '<input type="number" name="' . esc_attr($key) . '" value="' . esc_attr((string)($settings[$key] ?? '')) . '" min="' . (int)$min . '" max="' . (int)$max . '">';
        echo '</div>';
    }

    private static function renderCheckboxField(string $key, string $label, array $settings): void
    {
        echo '<div class="wnq-hub-form-group">';
        echo '<label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked(!empty($settings[$key]), true, false) . '> ' . esc_html($label) . '</label>';
        echo '</div>';
    }

    private static function actionUrl(int $attachment_id, string $image_action): string
    {
        return wp_nonce_url(add_query_arg([
            'action'        => 'wnq_image_optimizer_action',
            'attachment_id' => $attachment_id,
            'image_action'  => $image_action,
        ], admin_url('admin-post.php')), 'wnq_image_optimizer_action_' . $attachment_id);
    }

    private static function exportUrl(string $filter): string
    {
        return wp_nonce_url(add_query_arg([
            'action'       => 'wnq_image_optimizer_export',
            'image_filter' => $filter,
        ], admin_url('admin-post.php')), 'wnq_image_optimizer_export');
    }

    private static function redirectWithResult(array $result): void
    {
        wp_safe_redirect(add_query_arg([
            'page'        => self::PAGE_SLUG,
            'wnq_notice'  => !empty($result['success']) ? 'success' : 'error',
            'wnq_message' => (string)($result['message'] ?? 'Action complete.'),
        ], admin_url('admin.php')));
        exit;
    }

    private static function filters(): array
    {
        return [
            'all'         => 'All images',
            'warning'     => 'Over warning threshold',
            'high'        => 'Over high priority threshold',
            'critical'    => 'Over 1 MB / critical',
            'missing_alt' => 'Missing alt text',
            'oversized'   => 'Oversized dimensions',
            'no_webp'     => 'No WebP version',
            'optimized'   => 'Already optimized',
        ];
    }

    private static function sorts(): array
    {
        return [
            'date'       => 'Date uploaded',
            'file_size'  => 'File size',
            'dimensions' => 'Dimensions',
            'file_name'  => 'File name',
            'status'     => 'Optimization status',
        ];
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    private static function checkCap(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access the Image Optimizer.');
        }
    }
}
