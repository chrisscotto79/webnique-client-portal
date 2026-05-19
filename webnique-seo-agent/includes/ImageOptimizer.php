<?php
/**
 * Local image optimizer for the SEO Agent.
 *
 * @package Golden Web Marketing SEO Agent
 */

namespace WNQA;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageOptimizer
{
    public static function getDefaultSettings(): array
    {
        return [
            'enabled'               => 1,
            'warning_threshold_kb'  => 300,
            'high_threshold_kb'     => 500,
            'critical_threshold_kb' => 1024,
            'max_width'             => 1600,
            'max_height'            => 1600,
            'jpeg_quality'          => 82,
            'webp_quality'          => 82,
            'backup_originals'      => 1,
        ];
    }

    public static function getSettings(): array
    {
        $saved = get_option('wnqa_image_optimizer_settings', []);
        return array_merge(self::getDefaultSettings(), is_array($saved) ? $saved : []);
    }

    public static function saveSettings(array $input): void
    {
        $defaults = self::getDefaultSettings();
        $settings = [
            'enabled'               => !empty($input['enabled']) ? 1 : 0,
            'warning_threshold_kb'  => max(50, min(5000, (int)($input['warning_threshold_kb'] ?? $defaults['warning_threshold_kb']))),
            'high_threshold_kb'     => max(100, min(10000, (int)($input['high_threshold_kb'] ?? $defaults['high_threshold_kb']))),
            'critical_threshold_kb' => max(250, min(50000, (int)($input['critical_threshold_kb'] ?? $defaults['critical_threshold_kb']))),
            'max_width'             => max(600, min(5000, (int)($input['max_width'] ?? $defaults['max_width']))),
            'max_height'            => max(600, min(5000, (int)($input['max_height'] ?? $defaults['max_height']))),
            'jpeg_quality'          => max(40, min(100, (int)($input['jpeg_quality'] ?? $defaults['jpeg_quality']))),
            'webp_quality'          => max(40, min(100, (int)($input['webp_quality'] ?? $defaults['webp_quality']))),
            'backup_originals'      => !empty($input['backup_originals']) ? 1 : 0,
        ];

        if ($settings['high_threshold_kb'] < $settings['warning_threshold_kb']) {
            $settings['high_threshold_kb'] = $settings['warning_threshold_kb'];
        }
        if ($settings['critical_threshold_kb'] < $settings['high_threshold_kb']) {
            $settings['critical_threshold_kb'] = $settings['high_threshold_kb'];
        }

        update_option('wnqa_image_optimizer_settings', $settings);
        ImageScanner::clearStatsCache();
    }

    public static function optimize(int $attachment_id): array
    {
        $validation = self::validateAttachment($attachment_id);
        if (is_wp_error($validation)) {
            self::log($attachment_id, 'optimize', 0, 0, $validation->get_error_message());
            return ['success' => false, 'message' => $validation->get_error_message(), 'actions' => []];
        }

        $path = $validation['path'];
        $old_size = filesize($path) ?: 0;
        $backup = self::maybeBackup($attachment_id, $path);
        if (is_wp_error($backup)) {
            self::log($attachment_id, 'backup', $old_size, $old_size, $backup->get_error_message());
            return ['success' => false, 'message' => $backup->get_error_message(), 'actions' => []];
        }
        $restored_smaller_backup = self::restoreSmallerBackupIfCurrentIsLarger($attachment_id, $path);
        clearstatcache(true, $path);
        $old_size = filesize($path) ?: $old_size;

        $actions = [];
        if ($restored_smaller_backup) $actions[] = 'restored_smaller_backup';
        $resize = self::resize($attachment_id, false);
        if (!empty($resize['success']) && !empty($resize['changed'])) $actions[] = 'resized';

        $compress = self::compress($attachment_id, false);
        if (!empty($compress['success']) && !empty($compress['changed'])) $actions[] = 'compressed';

        $webp = self::generateWebp($attachment_id);
        if (!empty($webp['success']) && !empty($webp['changed'])) $actions[] = 'webp';

        clearstatcache(true, $path);
        $new_size = file_exists($path) ? (filesize($path) ?: $old_size) : $old_size;
        if ($new_size < $old_size) {
            self::storeOptimizationMeta($attachment_id, $old_size, $new_size, 'optimize');
        } else {
            self::clearOptimizationMeta($attachment_id);
        }
        self::regenerateMetadata($attachment_id, $path);
        self::clearElementorCache();
        ImageScanner::clearStatsCache();
        self::log($attachment_id, 'optimize', $old_size, $new_size, '');

        return [
            'success' => true,
            'message' => empty($actions) ? 'Image checked. No changes were needed.' : 'Image optimized on this client site.',
            'actions' => $actions,
        ];
    }

    public static function resize(int $attachment_id, bool $backup_first = true): array
    {
        $validation = self::validateAttachment($attachment_id);
        if (is_wp_error($validation)) return ['success' => false, 'message' => $validation->get_error_message(), 'changed' => false];

        $path = $validation['path'];
        $settings = self::getSettings();
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            self::log($attachment_id, 'resize', 0, 0, $editor->get_error_message());
            return ['success' => false, 'message' => $editor->get_error_message(), 'changed' => false];
        }

        $size = $editor->get_size();
        if ((int)$size['width'] <= (int)$settings['max_width'] && (int)$size['height'] <= (int)$settings['max_height']) {
            return ['success' => true, 'message' => 'Image is already within max dimensions.', 'changed' => false];
        }

        if ($backup_first) {
            $backup = self::maybeBackup($attachment_id, $path);
            if (is_wp_error($backup)) return ['success' => false, 'message' => $backup->get_error_message(), 'changed' => false];
        }

        $old_size = filesize($path) ?: 0;
        $resize = $editor->resize((int)$settings['max_width'], (int)$settings['max_height'], false);
        if (is_wp_error($resize)) {
            self::log($attachment_id, 'resize', $old_size, $old_size, $resize->get_error_message());
            return ['success' => false, 'message' => $resize->get_error_message(), 'changed' => false];
        }

        $save = self::saveEditorOverOriginal($editor, $path, $validation['mime']);
        if (is_wp_error($save)) {
            self::log($attachment_id, 'resize', $old_size, $old_size, $save->get_error_message());
            if (self::isNoSavingsError($save)) {
                return ['success' => true, 'message' => $save->get_error_message(), 'changed' => false];
            }
            return ['success' => false, 'message' => $save->get_error_message(), 'changed' => false];
        }

        clearstatcache(true, $path);
        $new_size = filesize($path) ?: $old_size;
        self::storeOptimizationMeta($attachment_id, $old_size, $new_size, 'resize');
        self::regenerateMetadata($attachment_id, $path);
        self::clearElementorCache();
        ImageScanner::clearStatsCache();
        self::log($attachment_id, 'resize', $old_size, $new_size, '');

        return ['success' => true, 'message' => 'Image resized on this client site.', 'changed' => true];
    }

    public static function compress(int $attachment_id, bool $backup_first = true): array
    {
        $validation = self::validateAttachment($attachment_id);
        if (is_wp_error($validation)) return ['success' => false, 'message' => $validation->get_error_message(), 'changed' => false];

        $path = $validation['path'];
        $mime = $validation['mime'];
        if ($mime === 'image/webp') return ['success' => true, 'message' => 'Native WebP image skipped for compression in v1.', 'changed' => false];

        if ($backup_first) {
            $backup = self::maybeBackup($attachment_id, $path);
            if (is_wp_error($backup)) return ['success' => false, 'message' => $backup->get_error_message(), 'changed' => false];
        }

        $old_size = filesize($path) ?: 0;
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            self::log($attachment_id, 'compress', $old_size, $old_size, $editor->get_error_message());
            return ['success' => false, 'message' => $editor->get_error_message(), 'changed' => false];
        }

        if ($mime === 'image/jpeg') {
            $editor->set_quality((int)self::getSettings()['jpeg_quality']);
        }

        $save = self::saveEditorOverOriginal($editor, $path, $mime);
        if (is_wp_error($save)) {
            self::log($attachment_id, 'compress', $old_size, $old_size, $save->get_error_message());
            if (self::isNoSavingsError($save)) {
                return ['success' => true, 'message' => $save->get_error_message(), 'changed' => false];
            }
            return ['success' => false, 'message' => $save->get_error_message(), 'changed' => false];
        }

        clearstatcache(true, $path);
        $new_size = filesize($path) ?: $old_size;
        self::storeOptimizationMeta($attachment_id, $old_size, $new_size, 'compress');
        self::regenerateMetadata($attachment_id, $path);
        self::clearElementorCache();
        ImageScanner::clearStatsCache();
        self::log($attachment_id, 'compress', $old_size, $new_size, '');

        return ['success' => true, 'message' => 'Image compressed on this client site.', 'changed' => $new_size < $old_size];
    }

    public static function generateWebp(int $attachment_id): array
    {
        $validation = self::validateAttachment($attachment_id);
        if (is_wp_error($validation)) return ['success' => false, 'message' => $validation->get_error_message(), 'changed' => false];

        $path = $validation['path'];
        if ($validation['mime'] === 'image/webp') return ['success' => true, 'message' => 'Image is already WebP.', 'changed' => false];

        // Safest v1 naming keeps the original extension and appends .webp, avoiding attachment URL rewrites.
        $webp_path = $path . '.webp';
        if (file_exists($webp_path)) {
            self::storeWebpMeta($attachment_id, $webp_path);
            return ['success' => true, 'message' => 'WebP already exists.', 'changed' => false];
        }

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            self::log($attachment_id, 'generate_webp', 0, 0, $editor->get_error_message());
            return ['success' => false, 'message' => $editor->get_error_message(), 'changed' => false];
        }

        $editor->set_quality((int)self::getSettings()['webp_quality']);
        $saved = $editor->save($webp_path, 'image/webp');
        if (is_wp_error($saved) || empty($saved['path']) || !file_exists($saved['path'])) {
            $message = is_wp_error($saved) ? $saved->get_error_message() : 'This server image editor does not support WebP output.';
            self::log($attachment_id, 'generate_webp', 0, 0, $message);
            return ['success' => false, 'message' => $message, 'changed' => false];
        }

        self::storeWebpMeta($attachment_id, $saved['path']);
        ImageScanner::clearStatsCache();
        self::log($attachment_id, 'generate_webp', filesize($path) ?: 0, filesize($saved['path']) ?: 0, '');

        // TODO: Future serving options: picture tags, server rewrite rules, CDN integration, and content filtering.
        return ['success' => true, 'message' => 'WebP generated on this client site.', 'changed' => true];
    }

    public static function restoreBackup(int $attachment_id): array
    {
        $validation = self::validateAttachment($attachment_id);
        if (is_wp_error($validation)) return ['success' => false, 'message' => $validation->get_error_message()];

        $backup_path = (string)get_post_meta($attachment_id, '_wnqa_backup_path', true);
        if ($backup_path === '' || !file_exists($backup_path)) return ['success' => false, 'message' => 'No backup is available for this attachment.'];

        $uploads = wp_get_upload_dir();
        if (!self::isPathInside($backup_path, trailingslashit($uploads['basedir']) . 'seo-agent-image-backups')) {
            return ['success' => false, 'message' => 'Backup path is not inside the SEO Agent backups directory.'];
        }

        $old_size = filesize($validation['path']) ?: 0;
        if (!copy($backup_path, $validation['path'])) {
            self::log($attachment_id, 'restore', $old_size, $old_size, 'Could not copy backup over original.');
            return ['success' => false, 'message' => 'Could not restore backup.'];
        }

        clearstatcache(true, $validation['path']);
        $new_size = filesize($validation['path']) ?: $old_size;
        foreach (['_wnqa_optimized_at', '_wnqa_optimization_method', '_wnqa_current_file_size', '_wnqa_savings_bytes', '_wnqa_savings_percent'] as $key) {
            delete_post_meta($attachment_id, $key);
        }
        self::regenerateMetadata($attachment_id, $validation['path']);
        self::clearElementorCache();
        ImageScanner::clearStatsCache();
        self::log($attachment_id, 'restore', $old_size, $new_size, '');

        return ['success' => true, 'message' => 'Backup restored on this client site.'];
    }

    public static function validateAttachment(int $attachment_id)
    {
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return new \WP_Error('invalid_attachment', 'Invalid attachment ID.');
        }

        $mime = (string)get_post_mime_type($attachment_id);
        if (!in_array($mime, ImageScanner::SUPPORTED_MIME_TYPES, true)) {
            return new \WP_Error('unsupported_mime', 'Only JPG, PNG, and WebP images are supported in v1.');
        }

        $path = get_attached_file($attachment_id);
        if (!$path || !file_exists($path)) {
            return new \WP_Error('missing_file', 'The attachment file could not be found.');
        }

        $uploads = wp_get_upload_dir();
        if (!self::isPathInside($path, $uploads['basedir'])) {
            return new \WP_Error('unsafe_path', 'The image file is outside the WordPress uploads directory.');
        }

        return ['path' => $path, 'mime' => $mime];
    }

    private static function maybeBackup(int $attachment_id, string $path)
    {
        if (empty(self::getSettings()['backup_originals'])) return true;

        $existing = (string)get_post_meta($attachment_id, '_wnqa_backup_path', true);
        if ($existing !== '' && file_exists($existing)) return true;

        $uploads = wp_get_upload_dir();
        $backup_dir = trailingslashit($uploads['basedir']) . 'seo-agent-image-backups/' . date('Y/m');
        if (!wp_mkdir_p($backup_dir)) return new \WP_Error('backup_dir_failed', 'Could not create the SEO Agent image backup directory.');
        if (!self::isPathInside($backup_dir, trailingslashit($uploads['basedir']) . 'seo-agent-image-backups')) {
            return new \WP_Error('backup_dir_unsafe', 'Backup directory validation failed.');
        }

        $backup_path = trailingslashit($backup_dir) . $attachment_id . '-' . wp_basename($path);
        if (!copy($path, $backup_path)) return new \WP_Error('backup_failed', 'Could not backup the original image.');

        $metadata = wp_get_attachment_metadata($attachment_id);
        update_post_meta($attachment_id, '_wnqa_backup_path', $backup_path);
        update_post_meta($attachment_id, '_wnqa_backup_url', str_replace($uploads['basedir'], $uploads['baseurl'], $backup_path));
        update_post_meta($attachment_id, '_wnqa_backup_created_at', current_time('mysql'));
        update_post_meta($attachment_id, '_wnqa_original_file_size', filesize($path) ?: 0);
        update_post_meta($attachment_id, '_wnqa_original_dimensions', [
            'width' => (int)($metadata['width'] ?? 0),
            'height' => (int)($metadata['height'] ?? 0),
        ]);

        return true;
    }

    private static function saveEditorOverOriginal($editor, string $path, string $mime)
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $tmp_path = $path . '.wnqa-tmp.' . $extension;
        $old_size = file_exists($path) ? (filesize($path) ?: 0) : 0;
        $saved = $editor->save($tmp_path, $mime);
        if (is_wp_error($saved)) return $saved;
        if (empty($saved['path']) || !file_exists($saved['path'])) return new \WP_Error('save_failed', 'The optimized image file was not saved.');
        $new_size = filesize($saved['path']) ?: 0;
        if ($old_size > 0 && $new_size >= $old_size) {
            @unlink($saved['path']);
            return new \WP_Error('no_size_savings', 'The processed image would be larger, so the original was kept.');
        }
        if (!rename($saved['path'], $path)) {
            @unlink($saved['path']);
            return new \WP_Error('replace_failed', 'Could not replace the original image file.');
        }
        return true;
    }

    private static function regenerateMetadata(int $attachment_id, string $path): void
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $path);
        if (!is_wp_error($metadata) && is_array($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
    }

    private static function storeOptimizationMeta(int $attachment_id, int $old_size, int $new_size, string $method): void
    {
        $saved = max(0, $old_size - $new_size);
        $percent = $old_size > 0 ? round(($saved / $old_size) * 100, 2) : 0;
        update_post_meta($attachment_id, '_wnqa_current_file_size', $new_size);
        update_post_meta($attachment_id, '_wnqa_savings_bytes', $saved);
        update_post_meta($attachment_id, '_wnqa_savings_percent', $percent);
        update_post_meta($attachment_id, '_wnqa_optimized_at', current_time('mysql'));
        update_post_meta($attachment_id, '_wnqa_optimization_method', $method);
    }

    private static function clearOptimizationMeta(int $attachment_id): void
    {
        foreach (['_wnqa_optimized_at', '_wnqa_optimization_method', '_wnqa_current_file_size', '_wnqa_savings_bytes', '_wnqa_savings_percent'] as $key) {
            delete_post_meta($attachment_id, $key);
        }
    }

    private static function restoreSmallerBackupIfCurrentIsLarger(int $attachment_id, string $path): bool
    {
        $original_size = (int)get_post_meta($attachment_id, '_wnqa_original_file_size', true);
        $backup_path = (string)get_post_meta($attachment_id, '_wnqa_backup_path', true);
        $current_size = file_exists($path) ? (filesize($path) ?: 0) : 0;

        if ($original_size <= 0 || $current_size <= $original_size || $backup_path === '' || !file_exists($backup_path)) {
            return false;
        }

        $uploads = wp_get_upload_dir();
        if (!self::isPathInside($backup_path, trailingslashit($uploads['basedir']) . 'seo-agent-image-backups')) {
            return false;
        }

        if (copy($backup_path, $path)) {
            self::clearOptimizationMeta($attachment_id);
            self::log($attachment_id, 'restore_smaller_backup', $current_size, $original_size, 'Restored smaller original because optimized output was larger.');
            return true;
        }

        return false;
    }

    private static function isNoSavingsError(\WP_Error $error): bool
    {
        return $error->get_error_code() === 'no_size_savings';
    }

    private static function storeWebpMeta(int $attachment_id, string $webp_path): void
    {
        $uploads = wp_get_upload_dir();
        update_post_meta($attachment_id, '_wnqa_webp_path', $webp_path);
        update_post_meta($attachment_id, '_wnqa_webp_url', str_replace($uploads['basedir'], $uploads['baseurl'], $webp_path));
        update_post_meta($attachment_id, '_wnqa_webp_size', file_exists($webp_path) ? (int)filesize($webp_path) : 0);
        update_post_meta($attachment_id, '_wnqa_webp_generated_at', current_time('mysql'));
    }

    public static function log(int $attachment_id, string $action, int $old_size, int $new_size, string $error = ''): void
    {
        $logs = get_post_meta($attachment_id, '_wnqa_image_optimizer_log', true);
        $logs = is_array($logs) ? $logs : [];
        array_unshift($logs, [
            'attachment_id' => $attachment_id,
            'action'        => sanitize_key($action),
            'old_size'      => $old_size,
            'new_size'      => $new_size,
            'savings'       => max(0, $old_size - $new_size),
            'date'          => current_time('mysql'),
            'user_id'       => get_current_user_id(),
            'error'         => sanitize_text_field($error),
        ]);
        update_post_meta($attachment_id, '_wnqa_image_optimizer_log', array_slice($logs, 0, 20));
    }

    private static function clearElementorCache(): void
    {
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
    }

    private static function isPathInside(string $path, string $base): bool
    {
        $real_path = realpath($path);
        $real_base = realpath($base);
        if (!$real_path || !$real_base) return false;
        $real_base = trailingslashit($real_base);
        return strpos($real_path, $real_base) === 0;
    }
}
