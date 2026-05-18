<?php
/**
 * Image Optimizer scanner.
 *
 * Reads WordPress Media Library image attachments and returns lightweight audit rows.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageScanner
{
    public const SUPPORTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function getStats(bool $force_refresh = false): array
    {
        $cache_key = 'wnq_image_optimizer_stats';
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $stats = [
            'total'             => 0,
            'over_warning'      => 0,
            'over_high'         => 0,
            'over_critical'     => 0,
            'missing_alt'       => 0,
            'oversized'         => 0,
            'with_webp'         => 0,
            'optimized'         => 0,
            'estimated_savings' => 0,
            'generated_at'      => current_time('mysql'),
        ];

        $settings = ImageOptimizer::getSettings();
        foreach (self::getAllImageIds() as $attachment_id) {
            $row = self::buildRow($attachment_id, $settings);
            if (!$row) {
                continue;
            }

            $stats['total']++;
            if ($row['size_kb'] > (int)$settings['warning_threshold_kb']) {
                $stats['over_warning']++;
            }
            if ($row['size_kb'] > (int)$settings['high_threshold_kb']) {
                $stats['over_high']++;
            }
            if ($row['size_kb'] > (int)$settings['critical_threshold_kb']) {
                $stats['over_critical']++;
            }
            if ($row['missing_alt']) {
                $stats['missing_alt']++;
            }
            if ($row['oversized']) {
                $stats['oversized']++;
            }
            if ($row['webp_exists']) {
                $stats['with_webp']++;
            }
            if ($row['optimized']) {
                $stats['optimized']++;
            }
            $stats['estimated_savings'] += max(0, (int)get_post_meta($attachment_id, '_seo_os_savings_bytes', true));
        }

        set_transient($cache_key, $stats, 6 * HOUR_IN_SECONDS);
        return $stats;
    }

    public static function clearStatsCache(): void
    {
        delete_transient('wnq_image_optimizer_stats');
    }

    public static function getRows(array $args = []): array
    {
        $settings = ImageOptimizer::getSettings();
        $page = max(1, (int)($args['page'] ?? 1));
        $per_page = max(5, min(50, (int)($args['per_page'] ?? 20)));
        $filter = sanitize_key((string)($args['filter'] ?? 'all'));
        $sort = sanitize_key((string)($args['sort'] ?? 'date'));
        $order = strtolower((string)($args['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $needs_full_scan = $filter !== 'all' || in_array($sort, ['file_size', 'dimensions', 'status'], true);
        if ($needs_full_scan) {
            $rows = [];
            foreach (self::getAllImageIds() as $attachment_id) {
                $row = self::buildRow($attachment_id, $settings);
                if ($row && self::rowMatchesFilter($row, $filter, $settings)) {
                    $rows[] = $row;
                }
            }
            self::sortRows($rows, $sort, $order);
            $total = count($rows);
            $rows = array_slice($rows, ($page - 1) * $per_page, $per_page);

            return [
                'rows'        => $rows,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => max(1, (int)ceil($total / $per_page)),
            ];
        }

        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => self::SUPPORTED_MIME_TYPES,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => $sort === 'file_name' ? 'title' : 'date',
            'order'          => strtoupper($order),
            'fields'         => 'ids',
        ]);

        $rows = [];
        foreach ((array)$query->posts as $attachment_id) {
            $row = self::buildRow((int)$attachment_id, $settings);
            if ($row) {
                $rows[] = $row;
            }
        }

        return [
            'rows'        => $rows,
            'total'       => (int)$query->found_posts,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => max(1, (int)$query->max_num_pages),
        ];
    }

    public static function buildRow(int $attachment_id, ?array $settings = null): ?array
    {
        $post = get_post($attachment_id);
        if (!$post || $post->post_type !== 'attachment') {
            return null;
        }

        $mime = (string)get_post_mime_type($attachment_id);
        if (!in_array($mime, self::SUPPORTED_MIME_TYPES, true)) {
            return null;
        }

        $path = get_attached_file($attachment_id);
        if (!$path || !file_exists($path)) {
            return null;
        }

        $settings = $settings ?: ImageOptimizer::getSettings();
        $metadata = wp_get_attachment_metadata($attachment_id);
        $width = (int)($metadata['width'] ?? 0);
        $height = (int)($metadata['height'] ?? 0);
        if (!$width || !$height) {
            $size = @getimagesize($path);
            $width = (int)($size[0] ?? 0);
            $height = (int)($size[1] ?? 0);
        }

        $file_size = (int)filesize($path);
        $alt = (string)get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $webp_path = (string)get_post_meta($attachment_id, '_seo_os_webp_path', true);
        $webp_exists = $mime === 'image/webp' || ($webp_path !== '' && file_exists($webp_path));
        $optimized_at = (string)get_post_meta($attachment_id, '_seo_os_optimized_at', true);
        $original_size = (int)get_post_meta($attachment_id, '_seo_os_original_file_size', true);
        $savings_percent = (float)get_post_meta($attachment_id, '_seo_os_savings_percent', true);

        $row = [
            'id'                => $attachment_id,
            'thumbnail'         => wp_get_attachment_image_url($attachment_id, 'thumbnail') ?: wp_get_attachment_url($attachment_id),
            'url'               => wp_get_attachment_url($attachment_id),
            'file_name'         => wp_basename($path),
            'mime'              => $mime,
            'file_type'         => strtoupper((string)pathinfo($path, PATHINFO_EXTENSION)),
            'file_size'         => $file_size,
            'size_kb'           => $file_size > 0 ? round($file_size / 1024, 1) : 0,
            'width'             => $width,
            'height'            => $height,
            'attached_to'       => (int)$post->post_parent,
            'attached_to_title' => $post->post_parent ? get_the_title($post->post_parent) : '',
            'alt_text'          => $alt,
            'missing_alt'       => trim($alt) === '',
            'oversized'         => $width > 2000 || $height > 2000,
            'webp_exists'       => $webp_exists,
            'optimized'         => $optimized_at !== '',
            'optimized_at'      => $optimized_at,
            'original_size'     => $original_size,
            'current_size'      => $file_size,
            'savings_percent'   => $savings_percent,
            'date_uploaded'     => $post->post_date,
        ];

        $row['priority'] = self::priorityForRow($row, $settings);
        $row['recommendation'] = self::recommendationForRow($row, $settings);

        return $row;
    }

    public static function getAllImageIds(): array
    {
        return get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => self::SUPPORTED_MIME_TYPES,
            'posts_per_page' => 2000,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
    }

    private static function rowMatchesFilter(array $row, string $filter, array $settings): bool
    {
        return match ($filter) {
            'warning'     => $row['size_kb'] > (int)$settings['warning_threshold_kb'],
            'high'        => $row['size_kb'] > (int)$settings['high_threshold_kb'],
            'critical'    => $row['size_kb'] > (int)$settings['critical_threshold_kb'],
            'missing_alt' => !empty($row['missing_alt']),
            'oversized'   => !empty($row['oversized']),
            'no_webp'     => empty($row['webp_exists']),
            'optimized'   => !empty($row['optimized']),
            default       => true,
        };
    }

    private static function sortRows(array &$rows, string $sort, string $order): void
    {
        usort($rows, function (array $a, array $b) use ($sort, $order): int {
            $left = match ($sort) {
                'file_size'  => (int)$a['file_size'],
                'dimensions' => (int)$a['width'] * (int)$a['height'],
                'file_name'  => strtolower((string)$a['file_name']),
                'status'     => (string)$a['recommendation'],
                default      => strtotime((string)$a['date_uploaded']),
            };
            $right = match ($sort) {
                'file_size'  => (int)$b['file_size'],
                'dimensions' => (int)$b['width'] * (int)$b['height'],
                'file_name'  => strtolower((string)$b['file_name']),
                'status'     => (string)$b['recommendation'],
                default      => strtotime((string)$b['date_uploaded']),
            };

            $result = $left <=> $right;
            return $order === 'asc' ? $result : -$result;
        });
    }

    private static function priorityForRow(array $row, array $settings): string
    {
        if ($row['size_kb'] > (int)$settings['critical_threshold_kb']) {
            return 'critical';
        }
        if ($row['size_kb'] > (int)$settings['high_threshold_kb']) {
            return 'high';
        }
        if ($row['size_kb'] > (int)$settings['warning_threshold_kb']) {
            return 'warning';
        }
        return 'good';
    }

    private static function recommendationForRow(array $row, array $settings): string
    {
        if ($row['size_kb'] > (int)$settings['critical_threshold_kb']) {
            return 'Critical: replace or optimize';
        }

        $needs_compress = $row['size_kb'] > (int)$settings['warning_threshold_kb'];
        $needs_resize = !empty($row['oversized']);
        $needs_webp = empty($row['webp_exists']) && $row['mime'] !== 'image/webp';

        if ($needs_compress && $needs_webp) {
            return 'Compress and generate WebP';
        }
        if ($needs_resize) {
            return 'Resize oversized image';
        }
        if ($needs_compress) {
            return 'Compress image';
        }
        if ($needs_webp) {
            return 'Generate WebP';
        }
        if (!empty($row['missing_alt'])) {
            return 'Missing alt text';
        }
        return 'Good';
    }
}
