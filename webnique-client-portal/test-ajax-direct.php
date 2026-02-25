<?php
// Test file - access at: yoursite.com/wp-content/plugins/webnique-client-portal/test-ajax-direct.php

// Load WordPress
require_once('../../../wp-load.php');

// Test AJAX registration
echo "Testing AJAX handlers...\n\n";

// Check if handlers are registered
global $wp_filter;
echo "wp_ajax_wnq_get_analytics_data registered: ";
echo isset($wp_filter['wp_ajax_wnq_get_analytics_data']) ? "YES\n" : "NO\n";

echo "wp_ajax_nopriv_wnq_get_analytics_data registered: ";
echo isset($wp_filter['wp_ajax_nopriv_wnq_get_analytics_data']) ? "YES\n" : "NO\n";

// Try calling it directly
$_POST['action'] = 'wnq_get_analytics_data';
$_POST['client_id'] = 'Sam-pa2893829';
$_POST['nonce'] = wp_create_nonce('wp_rest');
$_POST['date_range'] = 30;

echo "\nCalling handler directly...\n";
do_action('wp_ajax_wnq_get_analytics_data');