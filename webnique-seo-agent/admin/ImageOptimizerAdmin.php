<?php
/**
 * SEO Agent Image Optimizer admin page.
 *
 * @package Golden Web Marketing SEO Agent
 */

namespace WNQA\Admin;

use WNQA\ImageOptimizer;
use WNQA\ImageScanner;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageOptimizerAdmin
{
    const PAGE_SLUG = 'webnique-seo-agent-image-optimizer';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addPage']);
        add_action('admin_post_wnqa_image_optimizer_action', [self::class, 'handleManualAction']);
        add_action('admin_post_wnqa_image_optimizer_refresh', [self::class, 'handleRefreshScan']);
        add_action('admin_post_wnqa_image_optimizer_export', [self::class, 'handleCsvExport']);
        add_action('admin_post_wnqa_image_optimizer_save_settings', [self::class, 'handleSaveSettings']);
        add_action('wp_ajax_wnqa_image_optimizer_batch', [self::class, 'ajaxBatch']);
    }

    public static function addPage(): void
    {
        add_options_page(
            'SEO Agent Image Optimizer',
            'SEO Agent Image Optimizer',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
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
        $rows = ImageScanner::getRows([
            'page'     => $paged,
            'per_page' => $per_page,
            'filter'   => $filter,
            'sort'     => $sort,
            'order'    => $order,
        ]);

        ?>
<div class="wrap wnqa-image-wrap">
  <h1>Golden Web Marketing SEO Agent - Image Optimizer</h1>
  <p class="description">Optimize this client site's local Media Library. Actions run on this WordPress install, not on the hub.</p>
  <?php self::renderNotices(); ?>

  <?php if (empty($settings['enabled'])): ?>
    <div class="wnqa-image-notice warning">Image Optimizer is disabled. Scanning still works, but optimization actions are blocked.</div>
  <?php endif; ?>

  <div class="wnqa-image-stats">
    <?php self::stat('Total Images', number_format((int)$stats['total'])); ?>
    <?php self::stat('Large Images', number_format((int)$stats['over_warning']), 'Over ' . (int)$settings['warning_threshold_kb'] . ' KB'); ?>
    <?php self::stat('Critical Images', number_format((int)$stats['over_critical']), 'Over ' . (int)$settings['critical_threshold_kb'] . ' KB', 'danger'); ?>
    <?php self::stat('Missing Alt Text', number_format((int)$stats['missing_alt']), '', 'warning'); ?>
    <?php self::stat('WebP Generated', number_format((int)$stats['with_webp'])); ?>
    <?php self::stat('Total Savings', self::formatBytes((int)$stats['estimated_savings'])); ?>
  </div>

  <div class="wnqa-image-panel">
    <form method="get" class="wnqa-image-filters">
      <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
      <label>Filter
        <select name="image_filter"><?php self::options(self::filters(), $filter); ?></select>
      </label>
      <label>Sort
        <select name="sort"><?php self::options(self::sorts(), $sort); ?></select>
      </label>
      <label>Order
        <select name="order">
          <option value="desc" <?php selected($order, 'desc'); ?>>Descending</option>
          <option value="asc" <?php selected($order, 'asc'); ?>>Ascending</option>
        </select>
      </label>
      <label>Per page
        <select name="per_page"><?php self::options([10 => '10', 20 => '20', 30 => '30', 50 => '50'], (string)$per_page); ?></select>
      </label>
      <button type="submit" class="button button-primary">Apply</button>
      <a class="button" href="<?php echo esc_url(self::exportUrl($filter)); ?>">Export CSV</a>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('wnqa_image_optimizer_refresh'); ?>
      <input type="hidden" name="action" value="wnqa_image_optimizer_refresh">
      <button type="submit" class="button">Refresh Scan</button>
    </form>
  </div>

  <div class="wnqa-image-panel wnqa-image-batch">
    <label>Batch action
      <select id="wnqa-image-batch-action">
        <option value="optimize">Optimize selected</option>
        <option value="generate_webp">Generate WebP</option>
        <option value="resize">Resize</option>
        <option value="compress">Compress</option>
        <option value="restore">Restore backup</option>
      </select>
    </label>
    <button type="button" class="button button-primary" id="wnqa-image-run-batch">Run Batch</button>
    <span id="wnqa-image-progress"></span>
  </div>

  <div class="wnqa-image-table-scroll" role="region" aria-label="Local image audit table" tabindex="0">
    <table class="widefat striped wnqa-image-table">
      <thead>
        <tr>
          <th class="check-column"><input type="checkbox" id="wnqa-image-select-all"></th>
          <th>Preview</th>
          <th>ID</th>
          <th>File</th>
          <th>Type</th>
          <th>Size</th>
          <th>Dimensions</th>
          <th>Attached To</th>
          <th>Alt</th>
          <th>WebP</th>
          <th>Optimized</th>
          <th>Before / After</th>
          <th>Recommended</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows['rows'])): ?>
          <tr><td colspan="14" class="wnqa-image-empty">No images found for this filter.</td></tr>
        <?php else: ?>
          <?php foreach ($rows['rows'] as $row): ?>
            <?php self::row($row); ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php self::pagination((int)$rows['page'], (int)$rows['total_pages'], $filter, $sort, $order, $per_page); ?>
  <?php self::settingsPanel($settings); ?>
</div>
<?php self::styles(); ?>
<?php self::batchScript(); ?>
        <?php
    }

    public static function handleManualAction(): void
    {
        self::checkCap();
        $attachment_id = absint($_GET['attachment_id'] ?? 0);
        check_admin_referer('wnqa_image_optimizer_action_' . $attachment_id);
        $result = self::runAction($attachment_id, sanitize_key(wp_unslash($_GET['image_action'] ?? '')));
        self::redirect($result);
    }

    public static function handleRefreshScan(): void
    {
        self::checkCap();
        check_admin_referer('wnqa_image_optimizer_refresh');
        ImageScanner::getStats(true);
        self::redirect(['success' => true, 'message' => 'Image scan refreshed.']);
    }

    public static function handleSaveSettings(): void
    {
        self::checkCap();
        check_admin_referer('wnqa_image_optimizer_save_settings');
        ImageOptimizer::saveSettings(wp_unslash($_POST));
        self::redirect(['success' => true, 'message' => 'Image Optimizer settings saved.']);
    }

    public static function handleCsvExport(): void
    {
        self::checkCap();
        check_admin_referer('wnqa_image_optimizer_export');
        $filter = sanitize_key(wp_unslash($_GET['image_filter'] ?? 'all'));
        $export_rows = [];
        $page = 1;
        do {
            $rows = ImageScanner::getRows(['page' => $page, 'per_page' => 50, 'filter' => $filter, 'sort' => 'file_name', 'order' => 'asc']);
            $export_rows = array_merge($export_rows, (array)$rows['rows']);
            $page++;
        } while ($page <= (int)$rows['total_pages'] && $page <= 40);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=seo-agent-image-audit-' . gmdate('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        if (!$output) wp_die('Could not open CSV output stream.');
        fputcsv($output, ['Attachment ID', 'File name', 'URL', 'File type', 'File size', 'Width', 'Height', 'Alt text', 'Attached to', 'WebP exists', 'Optimized', 'Original size', 'Current size', 'Savings percent', 'Recommended action']);
        foreach ($export_rows as $row) {
            fputcsv($output, [(int)$row['id'], $row['file_name'], $row['url'], $row['file_type'], (int)$row['file_size'], (int)$row['width'], (int)$row['height'], $row['alt_text'], $row['attached_to_title'], !empty($row['webp_exists']) ? 'yes' : 'no', !empty($row['optimized']) ? 'yes' : 'no', (int)$row['original_size'], (int)$row['current_size'], (float)$row['savings_percent'], $row['recommendation']]);
        }
        fclose($output);
        exit;
    }

    public static function ajaxBatch(): void
    {
        self::checkCap();
        check_ajax_referer('wnqa_image_optimizer_batch', 'nonce');
        $image_action = sanitize_key(wp_unslash($_POST['image_action'] ?? ''));
        $ids = array_slice(array_map('absint', (array)wp_unslash($_POST['attachment_ids'] ?? [])), 0, 5);
        $items = [];
        foreach ($ids as $attachment_id) {
            if ($attachment_id <= 0) continue;
            $result = self::runAction($attachment_id, $image_action);
            $items[] = ['id' => $attachment_id, 'success' => !empty($result['success']), 'message' => $result['message'] ?? ''];
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
            return ['success' => false, 'message' => 'Image Optimizer is disabled.'];
        }
        switch ($image_action) {
            case 'generate_webp': return ImageOptimizer::generateWebp($attachment_id);
            case 'resize': return ImageOptimizer::resize($attachment_id);
            case 'compress': return ImageOptimizer::compress($attachment_id);
            case 'optimize': return ImageOptimizer::optimize($attachment_id);
            case 'restore': return ImageOptimizer::restoreBackup($attachment_id);
            default: return ['success' => false, 'message' => 'Unknown image optimizer action.'];
        }
    }

    private static function row(array $row): void
    {
        $priority = sanitize_html_class((string)$row['priority']);
        ?>
<tr>
  <th class="check-column"><input type="checkbox" class="wnqa-image-select" value="<?php echo (int)$row['id']; ?>"></th>
  <td><?php if (!empty($row['thumbnail'])): ?><img class="wnqa-image-thumb" src="<?php echo esc_url($row['thumbnail']); ?>" alt=""><?php endif; ?></td>
  <td><code><?php echo (int)$row['id']; ?></code></td>
  <td><strong><?php echo esc_html($row['file_name']); ?></strong><br><a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener">Open file</a></td>
  <td><?php echo esc_html($row['file_type']); ?></td>
  <td><strong><?php echo esc_html(self::formatBytes((int)$row['file_size'])); ?></strong></td>
  <td><?php echo (int)$row['width']; ?> x <?php echo (int)$row['height']; ?></td>
  <td><?php echo !empty($row['attached_to']) ? '<a href="' . esc_url(get_edit_post_link((int)$row['attached_to'])) . '">' . esc_html($row['attached_to_title']) . '</a>' : '<span class="description">Unattached</span>'; ?></td>
  <td><?php echo !empty($row['missing_alt']) ? '<span class="wnqa-pill danger">Missing</span>' : '<span class="wnqa-pill good">OK</span>'; ?></td>
  <td><?php echo !empty($row['webp_exists']) ? '<span class="wnqa-pill good">Exists</span>' : '<span class="wnqa-pill warning">No WebP</span>'; ?></td>
  <td><?php echo !empty($row['optimized']) ? '<span class="wnqa-pill good">Optimized</span><br><small>' . esc_html((string)$row['optimized_at']) . '</small>' : '<span class="wnqa-pill neutral">Not yet</span>'; ?></td>
  <td><?php self::sizeChange($row); ?></td>
  <td><span class="wnqa-recommend <?php echo esc_attr($priority); ?>"><?php echo esc_html($row['recommendation']); ?></span></td>
  <td class="wnqa-row-actions">
    <a class="button button-small" href="<?php echo esc_url(self::actionUrl((int)$row['id'], 'generate_webp')); ?>">WebP</a>
    <a class="button button-small button-primary" href="<?php echo esc_url(self::actionUrl((int)$row['id'], 'optimize')); ?>">Optimize</a>
    <?php if ((string)get_post_meta((int)$row['id'], '_wnqa_backup_path', true) !== ''): ?>
      <a class="button button-small" href="<?php echo esc_url(self::actionUrl((int)$row['id'], 'restore')); ?>" onclick="return confirm('Restore this image from backup?');">Restore</a>
    <?php endif; ?>
  </td>
</tr>
        <?php
    }

    private static function sizeChange(array $row): void
    {
        $before = (int)($row['original_size'] ?? 0);
        $after = (int)($row['current_size'] ?? $row['file_size'] ?? 0);
        $savings_percent = (float)($row['savings_percent'] ?? 0);

        if ($before <= 0 && empty($row['optimized'])) {
            echo '<span class="description">Not optimized yet</span>';
            return;
        }

        echo '<span class="wnqa-size-change">';
        if ($before > 0) {
            echo '<strong>Before:</strong> ' . esc_html(self::formatBytes($before)) . '<br>';
        } else {
            echo '<strong>Before:</strong> <span class="description">Not recorded</span><br>';
        }
        echo '<strong>After:</strong> ' . esc_html(self::formatBytes($after));
        if ($savings_percent > 0) {
            echo '<br><small>' . esc_html((string)$savings_percent) . '% saved</small>';
        }
        echo '</span>';
    }

    private static function settingsPanel(array $settings): void
    {
        ?>
<div class="wnqa-image-card">
  <h2>Settings</h2>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wnqa-settings-grid">
    <?php wp_nonce_field('wnqa_image_optimizer_save_settings'); ?>
    <input type="hidden" name="action" value="wnqa_image_optimizer_save_settings">
    <?php self::checkbox('enabled', 'Enable Image Optimizer', $settings); ?>
    <?php self::number('warning_threshold_kb', 'Warning KB', $settings, 50, 5000); ?>
    <?php self::number('high_threshold_kb', 'High Priority KB', $settings, 100, 10000); ?>
    <?php self::number('critical_threshold_kb', 'Critical KB', $settings, 250, 50000); ?>
    <?php self::number('max_width', 'Max Width', $settings, 600, 5000); ?>
    <?php self::number('max_height', 'Max Height', $settings, 600, 5000); ?>
    <?php self::number('jpeg_quality', 'JPEG Quality', $settings, 40, 100); ?>
    <?php self::number('webp_quality', 'WebP Quality', $settings, 40, 100); ?>
    <?php self::checkbox('backup_originals', 'Backup originals', $settings); ?>
    <p><button type="submit" class="button button-primary">Save Settings</button></p>
  </form>
</div>
        <?php
    }

    private static function styles(): void
    {
        ?>
<style>
.wnqa-image-wrap{max-width:100%;overflow:hidden;box-sizing:border-box}
.wnqa-image-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin:16px 0}
.wnqa-stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px}.wnqa-stat .val{display:block;font-size:28px;font-weight:800;color:#1f5aa6}.wnqa-stat .lbl{display:block;margin-top:6px;text-transform:uppercase;font-size:11px;font-weight:800;color:#50575e;letter-spacing:.05em}.wnqa-stat.danger .val{color:#dc2626}.wnqa-stat.warning .val{color:#d97706}
.wnqa-image-panel,.wnqa-image-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;margin:14px 0}.wnqa-image-panel{display:flex;justify-content:space-between;gap:12px;align-items:end;flex-wrap:wrap}
.wnqa-image-filters{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.wnqa-image-filters label,.wnqa-image-batch label{font-weight:700}.wnqa-image-filters select,.wnqa-image-batch select{display:block;min-width:130px;margin-top:4px}
.wnqa-image-table-scroll{width:100%;max-width:100%;overflow-x:auto;background:#fff;border:1px solid #dcdcde;border-radius:8px}.wnqa-image-table{min-width:1300px;border:0;table-layout:fixed}.wnqa-image-table th,.wnqa-image-table td{vertical-align:middle;word-break:break-word}
.wnqa-image-table th:nth-child(1),.wnqa-image-table td:nth-child(1){width:36px}.wnqa-image-table th:nth-child(2),.wnqa-image-table td:nth-child(2){width:74px}.wnqa-image-table th:nth-child(3),.wnqa-image-table td:nth-child(3){width:60px}.wnqa-image-table th:nth-child(4),.wnqa-image-table td:nth-child(4){width:220px}.wnqa-image-table th:nth-child(13),.wnqa-image-table td:nth-child(13){width:145px}.wnqa-image-table th:nth-child(14),.wnqa-image-table td:nth-child(14){width:155px}
.wnqa-size-change{display:block;line-height:1.5}
.wnqa-image-thumb{width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid #dcdcde;background:#f6f7f7}.wnqa-pill{display:inline-block;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:800}.wnqa-pill.good{background:#dcfce7;color:#166534}.wnqa-pill.warning{background:#fef3c7;color:#92400e}.wnqa-pill.danger{background:#fee2e2;color:#991b1b}.wnqa-pill.neutral{background:#f3f4f6;color:#4b5563}
.wnqa-recommend{display:inline-block;border-radius:8px;padding:6px 8px;background:#f3f4f6;font-weight:700}.wnqa-recommend.warning{background:#fef3c7;color:#92400e}.wnqa-recommend.high{background:#ffedd5;color:#9a3412}.wnqa-recommend.critical{background:#fee2e2;color:#991b1b}.wnqa-recommend.good{background:#dcfce7;color:#166534}.wnqa-row-actions{display:flex;gap:6px;flex-wrap:wrap}
.wnqa-image-notice{border-radius:6px;padding:10px 12px;margin:12px 0;font-weight:700}.wnqa-image-notice.success{background:#dcfce7;color:#166534;border:1px solid #86efac}.wnqa-image-notice.error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}.wnqa-image-notice.warning{background:#fef3c7;color:#92400e;border:1px solid #fcd34d}
.wnqa-settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.wnqa-settings-grid label{font-weight:700}.wnqa-settings-grid input[type=number]{width:100%;margin-top:4px}.wnqa-image-empty{text-align:center;padding:28px;color:#646970}
@media(max-width:1200px){.wnqa-image-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.wnqa-settings-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:782px){.wnqa-image-stats,.wnqa-settings-grid{grid-template-columns:1fr}.wnqa-image-panel{align-items:flex-start;flex-direction:column}}
</style>
        <?php
    }

    private static function batchScript(): void
    {
        $nonce = wp_create_nonce('wnqa_image_optimizer_batch');
        ?>
<script>
(function(){
  var all=document.getElementById('wnqa-image-select-all'),btn=document.getElementById('wnqa-image-run-batch'),progress=document.getElementById('wnqa-image-progress');
  if(all){all.addEventListener('change',function(){document.querySelectorAll('.wnqa-image-select').forEach(function(box){box.checked=all.checked;});});}
  if(!btn){return;}
  btn.addEventListener('click',function(){
    var ids=Array.from(document.querySelectorAll('.wnqa-image-select:checked')).map(function(box){return box.value;});
    var imageAction=document.getElementById('wnqa-image-batch-action').value;
    if(!ids.length){progress.textContent='Select at least one image.';return;}
    if(!window.confirm('Run this action on '+ids.length+' image(s)?')){return;}
    btn.disabled=true;progress.textContent='Starting...';
    var chunks=[];for(var i=0;i<ids.length;i+=5){chunks.push(ids.slice(i,i+5));}
    var done=0,failed=0;
    var run=function(index){
      if(index>=chunks.length){progress.textContent='Done. '+done+' processed'+(failed?', '+failed+' failed.':'.');btn.disabled=false;setTimeout(function(){window.location.reload();},900);return;}
      var form=new window.FormData();form.append('action','wnqa_image_optimizer_batch');form.append('nonce','<?php echo esc_js($nonce); ?>');form.append('image_action',imageAction);
      chunks[index].forEach(function(id){form.append('attachment_ids[]',id);});
      window.fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:form}).then(function(r){return r.json();}).then(function(json){
        var items=json&&json.data&&json.data.items?json.data.items:[];items.forEach(function(item){done++;if(!item.success){failed++;}});progress.textContent='Processed '+done+' of '+ids.length+'...';run(index+1);
      }).catch(function(){failed+=chunks[index].length;done+=chunks[index].length;run(index+1);});
    };
    run(0);
  });
})();
</script>
        <?php
    }

    private static function pagination(int $page, int $total_pages, string $filter, string $sort, string $order, int $per_page): void
    {
        if ($total_pages <= 1) return;
        $links = paginate_links([
            'base'      => add_query_arg(['page' => self::PAGE_SLUG, 'image_filter' => $filter, 'sort' => $sort, 'order' => $order, 'per_page' => $per_page, 'paged' => '%#%'], admin_url('options-general.php')),
            'format'    => '',
            'current'   => $page,
            'total'     => $total_pages,
            'type'      => 'array',
            'prev_text' => 'Previous',
            'next_text' => 'Next',
        ]);
        if ($links) echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(implode('', $links)) . '</div></div>';
    }

    private static function stat(string $label, string $value, string $hint = '', string $class = ''): void
    {
        echo '<div class="wnqa-stat ' . esc_attr($class) . '"><span class="val">' . esc_html($value) . '</span><span class="lbl">' . esc_html($label) . '</span>' . ($hint !== '' ? '<span class="description">' . esc_html($hint) . '</span>' : '') . '</div>';
    }

    private static function checkbox(string $key, string $label, array $settings): void
    {
        echo '<label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked(!empty($settings[$key]), true, false) . '> ' . esc_html($label) . '</label>';
    }

    private static function number(string $key, string $label, array $settings, int $min, int $max): void
    {
        echo '<label>' . esc_html($label) . '<input type="number" name="' . esc_attr($key) . '" value="' . esc_attr((string)($settings[$key] ?? '')) . '" min="' . (int)$min . '" max="' . (int)$max . '"></label>';
    }

    private static function renderNotices(): void
    {
        $message = sanitize_text_field(wp_unslash($_GET['wnqa_message'] ?? ''));
        if ($message === '') return;
        $class = sanitize_key(wp_unslash($_GET['wnqa_notice'] ?? 'success')) === 'error' ? 'error' : 'success';
        echo '<div class="wnqa-image-notice ' . esc_attr($class) . '">' . esc_html($message) . '</div>';
    }

    private static function actionUrl(int $attachment_id, string $image_action): string
    {
        return wp_nonce_url(add_query_arg(['action' => 'wnqa_image_optimizer_action', 'attachment_id' => $attachment_id, 'image_action' => $image_action], admin_url('admin-post.php')), 'wnqa_image_optimizer_action_' . $attachment_id);
    }

    private static function exportUrl(string $filter): string
    {
        return wp_nonce_url(add_query_arg(['action' => 'wnqa_image_optimizer_export', 'image_filter' => $filter], admin_url('admin-post.php')), 'wnqa_image_optimizer_export');
    }

    private static function redirect(array $result): void
    {
        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'wnqa_notice' => !empty($result['success']) ? 'success' : 'error', 'wnqa_message' => (string)($result['message'] ?? 'Action complete.')], admin_url('options-general.php')));
        exit;
    }

    private static function options(array $options, string $selected): void
    {
        foreach ($options as $value => $label) {
            echo '<option value="' . esc_attr((string)$value) . '" ' . selected((string)$selected, (string)$value, false) . '>' . esc_html($label) . '</option>';
        }
    }

    private static function filters(): array
    {
        return ['all' => 'All images', 'warning' => 'Over warning threshold', 'high' => 'Over high threshold', 'critical' => 'Critical', 'missing_alt' => 'Missing alt text', 'oversized' => 'Oversized', 'no_webp' => 'No WebP', 'optimized' => 'Optimized'];
    }

    private static function sorts(): array
    {
        return ['date' => 'Date uploaded', 'file_size' => 'File size', 'dimensions' => 'Dimensions', 'file_name' => 'File name', 'status' => 'Status'];
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    private static function checkCap(): void
    {
        if (!current_user_can('manage_options')) wp_die('Access denied');
    }
}
