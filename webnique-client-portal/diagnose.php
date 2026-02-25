<?php
/**
 * Analytics System Diagnostic
 * 
 * Upload this to: wp-content/plugins/webnique-client-portal/diagnose.php
 * Then visit: https://yoursite.com/wp-content/plugins/webnique-client-portal/diagnose.php
 */

// Prevent direct execution in some cases
if (php_sapi_name() === 'cli') {
    die('This script must be run through a web browser.');
}

echo "<style>
body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
.success { color: green; }
.error { color: red; }
.warning { color: orange; }
h2 { border-bottom: 2px solid #333; padding-bottom: 10px; }
.file-check { padding: 5px; margin: 2px 0; background: white; }
</style>";

echo "<h1>🔍 WebNique Analytics - System Diagnostic</h1>";

$plugin_path = __DIR__;

echo "<h2>📍 Plugin Location</h2>";
echo "<div class='file-check'>Path: <strong>$plugin_path</strong></div>";

// Check PHP Version
echo "<h2>🐘 PHP Version</h2>";
$php_version = PHP_VERSION;
$php_ok = version_compare($php_version, '8.0.0', '>=');
echo "<div class='file-check " . ($php_ok ? 'success' : 'error') . "'>";
echo "Current: <strong>$php_version</strong> " . ($php_ok ? '✅ OK' : '❌ Need 8.0+');
echo "</div>";

// Check Core Files
echo "<h2>📁 Core Plugin Files</h2>";
$core_files = [
    'webnique-client-portal.php',
    'includes/Core/Plugin.php',
    'includes/Core/Permissions.php',
    'includes/Core/Router.php',
];

foreach ($core_files as $file) {
    $filepath = $plugin_path . '/' . $file;
    $exists = file_exists($filepath);
    echo "<div class='file-check " . ($exists ? 'success' : 'error') . "'>";
    echo "$file: " . ($exists ? '✅ EXISTS' : '❌ MISSING');
    if ($exists) {
        $size = filesize($filepath);
        echo " (" . number_format($size) . " bytes)";
        
        // Check for syntax errors
        $check = shell_exec("php -l " . escapeshellarg($filepath) . " 2>&1");
        if (strpos($check, 'No syntax errors') !== false) {
            echo " - ✅ Valid PHP";
        } else {
            echo " - ❌ SYNTAX ERROR: " . htmlspecialchars($check);
        }
    }
    echo "</div>";
}

// Check Controllers
echo "<h2>🎮 Controllers (Should only have DashboardController.php)</h2>";
$controllers_path = $plugin_path . '/includes/Controllers/';
if (is_dir($controllers_path)) {
    $controllers = scandir($controllers_path);
    foreach ($controllers as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $should_exist = ($file === 'DashboardController.php');
        $class = $should_exist ? 'success' : 'error';
        
        echo "<div class='file-check $class'>";
        echo "$file: " . ($should_exist ? '✅ CORRECT' : '❌ SHOULD BE DELETED');
        echo "</div>";
    }
} else {
    echo "<div class='file-check error'>❌ Controllers directory doesn't exist</div>";
}

// Check Analytics Files
echo "<h2>📊 Analytics Files</h2>";
$analytics_files = [
    'includes/API/GoogleAnalytics.php',
    'includes/API/GoogleSearchConsole.php',
    'includes/API/AnalyticsCache.php',
    'includes/Models/AnalyticsConfig.php',
    'admin/AnalyticsAdmin.php',
    'assets/admin/analytics-dashboard.js',
    'assets/admin/analytics-dashboard.css',
];

foreach ($analytics_files as $file) {
    $filepath = $plugin_path . '/' . $file;
    $exists = file_exists($filepath);
    echo "<div class='file-check " . ($exists ? 'success' : 'error') . "'>";
    echo "$file: " . ($exists ? '✅ EXISTS' : '❌ MISSING');
    if ($exists) {
        $size = filesize($filepath);
        echo " (" . number_format($size) . " bytes)";
        
        // Check PHP files for syntax
        if (substr($file, -4) === '.php') {
            $check = shell_exec("php -l " . escapeshellarg($filepath) . " 2>&1");
            if (strpos($check, 'No syntax errors') !== false) {
                echo " - ✅ Valid";
            } else {
                echo " - ❌ ERROR: " . htmlspecialchars(substr($check, 0, 100));
            }
        }
    }
    echo "</div>";
}

// Check Admin Files
echo "<h2>👤 Admin Files</h2>";
$admin_files = [
    'admin/ClientsAdmin.php',
    'admin/TasksAdmin.php',
    'admin/RequestsAdmin.php',
];

foreach ($admin_files as $file) {
    $filepath = $plugin_path . '/' . $file;
    $exists = file_exists($filepath);
    echo "<div class='file-check " . ($exists ? 'success' : 'warning') . "'>";
    echo "$file: " . ($exists ? '✅ EXISTS' : '⚠️ MISSING (non-critical)');
    echo "</div>";
}

// Try to check for specific errors in Plugin.php
echo "<h2>🔍 Plugin.php Content Check</h2>";
$plugin_core = $plugin_path . '/includes/Core/Plugin.php';
if (file_exists($plugin_core)) {
    $content = file_get_contents($plugin_core);
    
    // Check for common issues
    $issues = [];
    
    if (strpos($content, 'file_exists') === false) {
        $issues[] = "⚠️ No file_exists checks (might crash on missing files)";
    }
    
    if (empty($issues)) {
        echo "<div class='file-check success'>✅ No obvious issues found</div>";
    } else {
        foreach ($issues as $issue) {
            echo "<div class='file-check error'>$issue</div>";
        }
    }
    
    // Show first 50 lines
    $lines = explode("\n", $content);
    echo "<h3>First 50 lines of Plugin.php:</h3>";
    echo "<pre style='background: #f9f9f9; padding: 10px; overflow: auto; max-height: 300px;'>";
    echo htmlspecialchars(implode("\n", array_slice($lines, 0, 50)));
    echo "</pre>";
}

// Check for namespace issues
echo "<h2>📦 Namespace Checks</h2>";
$files_to_check = [
    'includes/API/GoogleAnalytics.php' => 'WNQ\\API',
    'includes/API/GoogleSearchConsole.php' => 'WNQ\\API',
    'includes/Models/AnalyticsConfig.php' => 'WNQ\\Models',
    'admin/AnalyticsAdmin.php' => 'WNQ\\Admin',
];

foreach ($files_to_check as $file => $expected_namespace) {
    $filepath = $plugin_path . '/' . $file;
    if (file_exists($filepath)) {
        $content = file_get_contents($filepath);
        if (strpos($content, "namespace $expected_namespace") !== false) {
            echo "<div class='file-check success'>✅ $file has correct namespace: $expected_namespace</div>";
        } else {
            echo "<div class='file-check error'>❌ $file missing or wrong namespace (expected: $expected_namespace)</div>";
        }
    }
}

echo "<h2>✅ Diagnostic Complete</h2>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Fix any ❌ red errors shown above</li>";
echo "<li>Delete any controllers that shouldn't exist</li>";
echo "<li>Make sure Plugin.php is the FIXED version</li>";
echo "<li>Try activating plugin again</li>";
echo "</ol>";

echo "<p><em>Generated: " . date('Y-m-d H:i:s') . "</em></p>";
?>
