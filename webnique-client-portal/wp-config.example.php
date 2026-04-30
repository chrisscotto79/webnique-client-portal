<?php
/**
 * Example WordPress configuration for local/staging setup.
 *
 * Copy this to wp-config.php outside of version control and provide secrets
 * through environment variables or host-managed configuration.
 */

define('DB_NAME', getenv('WP_DB_NAME') ?: '');
define('DB_USER', getenv('WP_DB_USER') ?: '');
define('DB_PASSWORD', getenv('WP_DB_PASSWORD') ?: '');
define('DB_HOST', getenv('WP_DB_HOST') ?: 'localhost');

define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

$table_prefix = getenv('WP_TABLE_PREFIX') ?: 'wp_';

define('WP_DEBUG', filter_var(getenv('WP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN));
define('WP_DEBUG_LOG', filter_var(getenv('WP_DEBUG_LOG') ?: false, FILTER_VALIDATE_BOOLEAN));
define('WP_DEBUG_DISPLAY', false);

if (getenv('WP_REDIS_HOST')) {
    define('WP_REDIS_CONFIG', [
        'token' => getenv('WP_REDIS_TOKEN') ?: '',
        'host' => getenv('WP_REDIS_HOST'),
        'username' => getenv('WP_REDIS_USERNAME') ?: '',
        'password' => getenv('WP_REDIS_PASSWORD') ?: '',
        'port' => (int) (getenv('WP_REDIS_PORT') ?: 6379),
        'database' => getenv('WP_REDIS_DATABASE') ?: '0',
    ]);
    define('WP_REDIS_DISABLED', false);
}

define('WNQ_FIREBASE_PROJECT_ID', getenv('WNQ_FIREBASE_PROJECT_ID') ?: '');
define('WNQ_FIREBASE_CLIENT_EMAIL', getenv('WNQ_FIREBASE_CLIENT_EMAIL') ?: '');
define('WNQ_FIREBASE_PRIVATE_KEY', str_replace('\\n', "\n", getenv('WNQ_FIREBASE_PRIVATE_KEY') ?: ''));

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
