<?php
/**
 * Analytics Cache
 * 
 * Caches API responses to improve performance
 * Uses WordPress transients for storage
 * 
 * @package WebNique Portal
 */

namespace WNQ\API;

if (!defined('ABSPATH')) {
    exit;
}

final class AnalyticsCache
{
    private static string $prefix = 'wnq_analytics_';

    /**
     * Get cached data
     */
    public static function get(string $key): mixed
    {
        $cache_key = self::$prefix . md5($key);
        return get_transient($cache_key);
    }

    /**
     * Set cached data
     */
    public static function set(string $key, mixed $data, int $expiration = 3600): bool
    {
        $cache_key = self::$prefix . md5($key);
        return set_transient($cache_key, $data, $expiration);
    }

    /**
     * Delete cached data
     */
    public static function delete(string $key): bool
    {
        $cache_key = self::$prefix . md5($key);
        return delete_transient($cache_key);
    }

    /**
     * Clear all analytics cache
     */
    public static function clearAll(): int
    {
        global $wpdb;
        
        $count = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::$prefix . '%'
            )
        );

        // Also delete timeout entries
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_' . self::$prefix . '%'
            )
        );

        return $count;
    }

    /**
     * Clear cache for specific client
     */
    public static function clearClient(string $client_id): int
    {
        global $wpdb;
        
        $pattern = self::$prefix . '%' . $client_id . '%';
        
        $count = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . $pattern
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_' . $pattern
            )
        );

        return $count;
    }

    /**
     * Get cache statistics
     */
    public static function getStats(): array
    {
        global $wpdb;

        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::$prefix . '%'
            )
        );

        $size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::$prefix . '%'
            )
        );

        return [
            'total_entries' => intval($total),
            'total_size' => intval($size),
            'size_formatted' => size_format($size),
        ];
    }
}