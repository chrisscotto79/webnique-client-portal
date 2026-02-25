<?php
define( 'WP_CACHE', true ); 
/**
 * The base configurations of the WordPress.
 *
 * This file has the following configurations: MySQL settings, Table Prefix,
 * Secret Keys, WordPress Language, and ABSPATH. You can find more information
 * by visiting {@link http://codex.wordpress.org/Editing_wp-config.php Editing
 * wp-config.php} Codex page. You can get the MySQL settings from your web host.
 *
 * This file is used by the wp-config.php creation script during the
 * installation. You don't have to use the web site, you can just copy this file
 * to "wp-config.php" and fill in the values.
 *
 * @package WordPress
 */
// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'njhdsexxkf');
/** MySQL database username */
define('DB_USER', 'njhdsexxkf');
/** MySQL database password */
define('DB_PASSWORD', 'NtskxXB2FT');
/** MySQL hostname */
define('DB_HOST', 'localhost');
/** Database Charset to use in creating database tables. */
define('DB_CHARSET', 'utf8');
/** The Database Collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');
/**#@+
 * Authentication Unique Keys and Salts.
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 */
require('wp-salt.php');
/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each a unique
 * prefix. Only numbers, letters, and underscores please!
 */
$table_prefix  = 'wp_';
/**
 * WordPress Localized Language, defaults to English.
 *
 * Change this to localize WordPress. A corresponding MO file for the chosen
 * language must be installed to wp-content/languages. For example, install
 * de_DE.mo to wp-content/languages and set WPLANG to 'de_DE' to enable German
 * language support.
 */
define('FS_METHOD','direct');
define('WPLANG', '');
define('FS_CHMOD_DIR', (0775 & ~ umask()));
define('FS_CHMOD_FILE', (0664 & ~ umask()));
/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 */
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define( 'WP_REDIS_CONFIG', [
   'token' => "e279430effe043b8c17d3f3c751c4c0846bc70c97f0eaaea766b4079001c",
   'host' => '127.0.0.1',
   'username' => "njhdsexxkf",
   'password' => "tfTbNcw5Ry",
   'port' => 6379,
   'database' => "2021", 
   'timeout' => 2.5,
   'read_timeout' => 2.5,
   'split_alloptions' => true,
   'async_flush' => true,
   'client' => 'phpredis', 
   'compression' => 'zstd', 
   'serializer' => 'igbinary', 
   'prefetch' => true, 
   'debug' => false,
   'save_commands' => false,
   'prefix' => "njhdsexxkf:",  
   ] );
define( 'WP_REDIS_DISABLED', false );
// -----------------------------------------------------------------------------
// WebNique Client Portal (STAGING) – Firebase
// -----------------------------------------------------------------------------
/** WebNique Client Portal - Firebase Service Account */
define('WNQ_FIREBASE_PROJECT_ID', 'webnique-client-portal');
define('WNQ_FIREBASE_CLIENT_EMAIL', 'firebase-adminsdk-fbsvc@webnique-client-portal.iam.gserviceaccount.com');
define('WNQ_FIREBASE_PRIVATE_KEY', "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC1tKQievQ2xx3L\n9zevxihMqiFHTkt73j2P8Zy/c+c6FjfH/zA1lqXBMS/UIIRk+b+p0LbzVkvrsW+i\nkkJUgDm/bD+/vHsVvT0MvXeI93kmYc9PRAsoHsICLtR7oCHX3W97Y7MZfMdJhU9b\nBGZIr/N3/ITPMQxd4/7xzykApEaAS4cMQHiCZL+TlxSc5Zzx/EeR7eXV8rz8quvT\ngAj8cp8IyMxq8LyKkw1i6PNaCl5NqQr/jtudj18QeV+hyA3Rgq0jJlm5SJ0RmQlk\nLDq4beXhBgMb/dICWGgGL/1vSzVHoJRtWhv5gbXXoNkBAnTPIFPLaLF2lHTzKsmm\nQFecKLlrAgMBAAECggEABD8kNY4WwQ+m7vUTKcTW0kaI9T1WNEnoZGyAKP/7dSG/\nIf8hJaTPW5QxCvh2P6nlRIG/6jCny5VfsjaliDYK+Myoh2xPRXMhHHgdIBKwk9Nm\nC0iK04rh0Tfo+A6xBnYtZyktrrh6K99gEGoqPc6m67SiIfmCfCXvomp2CdwiWFiM\nFf1nKEVpRy/cExXV2j7rT9we8cIV+v4NAcxbnpsM4aChavU3J0Neyya/1iWYOyAI\nzvHnyzNrYGst6e7i0LwbqIgIga4b0f0n537wtZQMAeADELV/MvI2sScj1kBUMC8F\n8I2dkgW5ub2gPvyHIzh4xZh01HN7KH80K8wmtJUkSQKBgQDr1SRywUbAV4ELtgfo\n+RuxkEprDZ1BOhYlIrkqe0+1TulyFQNiwMRWNN0w31fBYLKU816LZUyS93i1WM0U\nuKcQ/qTRwhP234I5uJwzdd8AjOtvuSTyEhAX2lmjCWnlR2Ng3G+ah3+xx+vEb+Jx\nAj5IyX5RuLgIX+Gvmqrs26Yj7QKBgQDFPoyJoApLWYj7bbS1dn3v2i1mvwZb/aGl\nWAVroYFDwYfuma3uWZH6rBa8s9e10gJWy9UnwSrMyxQ4cfZyVe6K0gwG0NQD8aIq\npswbrPRcu+S2JEybAO1lDtbM7wRkzrsXcjypSOXsTjbII1phjRn61uxAuaFvFgne\nGR+q6/3XtwKBgDwemusSMHoqFICqx/txPckXUpFV6CfPqgOPhYq54skCs+pcRv+u\nyp57XYPu+80VXJEyNYDtswaoRcJdP/KvXA+uCtBaXIKL2gPi9xb7Tn0yb3aMyUlY\np3edN0qjxLYpa8EukNjhAAGPSKBMMXrDqqMdkrE8mNWxs7Pzhu1Y1VLFAoGAYh/C\n1Hh8ho2tOr+R5bBj6F2WtoWTiVH9B39peujmoKl3kTh8sZV3rMfNq+SgDEDEjx9q\nFBPh0e25SndPTgP33rGt7/oVbzzXGvbNlXsOOc/zcLVQMtBcSSj/rQW/HtNFed2H\n1gOA9nTWhewoe10xrnsbHvv6FoRVNlszSNmL97MCgYEAzLUaaNVUS53wvL7Ebt/Q\nw61cFGQA+FiohpGZnV7vexhsTGPynW4N30uXy5wg3Q/2Yjq5ob0RoMJJz+H0VXJJ\ntQmckK7T+Lnd9xyyNVbhLodlU9hzc27xpeKep19QGUHRBlIOQH359rbjp0VDhZtc\nlJTrwHfoogHNnkLTqwrL8ek=\n-----END PRIVATE KEY-----\n");

/* That's all, stop editing! Happy blogging. */
/** Absolute path to the WordPress directory. */
if ( !defined('ABSPATH') )
        define('ABSPATH', dirname(__FILE__) . '/');
/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');