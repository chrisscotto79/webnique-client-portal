<?php
/**
 * Plugin Name: WebNique SEO Agent
 * Description: Lightweight data relay and execution endpoint for the WebNique SEO Operating System hub. Collects site data and syncs with web-nique.com.
 * Version: 1.1.0
 * Author: WebNique
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: webnique-seo-agent
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WNQA_VERSION', '1.1.0');
define('WNQA_PATH', plugin_dir_path(__FILE__));
define('WNQA_URL', plugin_dir_url(__FILE__));
define('WNQA_SLUG', 'webnique-seo-agent');

require_once WNQA_PATH . 'includes/DataCollector.php';
require_once WNQA_PATH . 'includes/APISync.php';
require_once WNQA_PATH . 'includes/LocalChecks.php';
require_once WNQA_PATH . 'includes/BlogReceiver.php';
require_once WNQA_PATH . 'includes/SEOFixer.php';
require_once WNQA_PATH . 'admin/AgentSettings.php';

register_activation_hook(__FILE__,   'wnqa_activate');
register_deactivation_hook(__FILE__, 'wnqa_deactivate');

function wnqa_activate(): void {
    // Schedule sync cron
    if (!wp_next_scheduled('wnqa_sync_to_hub')) {
        wp_schedule_event(time() + 300, 'twicedaily', 'wnqa_sync_to_hub');
    }
    if (!wp_next_scheduled('wnqa_local_checks')) {
        wp_schedule_event(time() + 60, 'daily', 'wnqa_local_checks');
    }
    flush_rewrite_rules();
}

function wnqa_deactivate(): void {
    $ts = wp_next_scheduled('wnqa_sync_to_hub');
    if ($ts) wp_unschedule_event($ts, 'wnqa_sync_to_hub');
    $ts = wp_next_scheduled('wnqa_local_checks');
    if ($ts) wp_unschedule_event($ts, 'wnqa_local_checks');
    flush_rewrite_rules();
}

add_action('plugins_loaded', function () {
    // Admin settings page
    if (is_admin()) {
        \WNQA\Admin\AgentSettings::register();
    }

    // Register blog receiver REST endpoint (hub → agent publishing)
    \WNQA\BlogReceiver::register();

    // Register SEO fixer REST endpoint (hub → agent auto-fix SEO meta)
    \WNQA\SEOFixer::register();

    // Output JSON-LD schema on single posts/pages (plugin-agnostic fallback)
    add_action('wp_head', function () {
        if (!is_singular()) return;
        $post_id = get_the_ID();
        if (!$post_id) return;
        $schema = get_post_meta($post_id, '_wnq_schema_json', true);
        if (empty($schema)) return;
        // Skip if Yoast or RankMath are active — they output their own schema
        if (defined('WPSEO_VERSION') || class_exists('RankMath')) return;
        echo '<script type="application/ld+json">' . $schema . '</script>' . "\n";
    });

    // Output Open Graph meta tags (plugin-agnostic fallback)
    add_action('wp_head', function () {
        if (!is_singular()) return;
        $post_id = get_the_ID();
        if (!$post_id) return;
        // Skip if Yoast or RankMath are active — they manage their own OG tags
        if (defined('WPSEO_VERSION') || class_exists('RankMath')) return;
        $og_title = get_post_meta($post_id, '_wnq_og_title', true);
        $og_desc  = get_post_meta($post_id, '_wnq_og_description', true);
        $og_image = get_post_meta($post_id, '_wnq_og_image', true);
        if (empty($og_title) && empty($og_desc) && empty($og_image)) return;
        if (!empty($og_title)) echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
        if (!empty($og_desc))  echo '<meta property="og:description" content="' . esc_attr($og_desc) . '">' . "\n";
        if (!empty($og_image)) echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";
        echo '<meta property="og:type" content="' . (is_single() ? 'article' : 'website') . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url(get_permalink($post_id)) . '">' . "\n";
    });

    // Add loading="lazy" to images on Elementor pages flagged by SEOFixer
    add_action('wp_head', function () {
        if (!is_singular()) return;
        $post_id = get_the_ID();
        if (!$post_id || !get_post_meta($post_id, '_wnq_lazy_load', true)) return;
        echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("img:not([loading])").forEach(function(i){i.setAttribute("loading","lazy")});});</script>' . "\n";
    });

    // Append font-display:swap to Google Fonts stylesheet URLs for better CLS score
    add_filter('style_loader_src', function ($src) {
        if (strpos($src, 'fonts.googleapis.com') !== false && strpos($src, 'display=') === false) {
            $src = add_query_arg('display', 'swap', $src);
        }
        return $src;
    }, 10, 1);

    // Cron handlers
    add_action('wnqa_sync_to_hub', function () {
        $sync = new \WNQA\APISync();
        $sync->syncSiteData();
    });

    add_action('wnqa_local_checks', function () {
        $checks = new \WNQA\LocalChecks();
        $checks->run();
    });

    // Admin bar notice if not configured
    add_action('admin_bar_menu', function (\WP_Admin_Bar $bar) {
        if (!current_user_can('manage_options')) return;
        $config = get_option('wnqa_config', []);
        if (empty($config['api_key']) || empty($config['hub_url'])) {
            $bar->add_node([
                'id'     => 'wnqa-setup',
                'title'  => '⚠ SEO Agent: Setup Required',
                'href'   => admin_url('options-general.php?page=' . WNQA_SLUG),
                'meta'   => ['class' => 'wnqa-setup-notice'],
            ]);
        }
    }, 100);
});

// Manual sync trigger from admin
add_action('admin_post_wnqa_manual_sync', function () {
    check_admin_referer('wnqa_manual_sync');
    if (!current_user_can('manage_options')) wp_die('Access denied');

    $sync = new \WNQA\APISync();
    $result = $sync->syncSiteData();

    $status = $result['success'] ? 'success' : 'error';
    $msg    = urlencode($result['message'] ?? '');
    wp_redirect(admin_url('options-general.php?page=' . WNQA_SLUG . '&sync=' . $status . '&msg=' . $msg));
    exit;
});

// Test connection
add_action('admin_post_wnqa_test_connection', function () {
    check_admin_referer('wnqa_test_connection');
    if (!current_user_can('manage_options')) wp_die('Access denied');

    $sync   = new \WNQA\APISync();
    $result = $sync->ping();

    $status = $result['success'] ? 'success' : 'error';
    $msg    = urlencode($result['message'] ?? '');
    wp_redirect(admin_url('options-general.php?page=' . WNQA_SLUG . '&test=' . $status . '&msg=' . $msg));
    exit;
});
