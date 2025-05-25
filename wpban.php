<?php
/*
Plugin Name: WPBan
Plugin URI: https://wpban.com/
Description: Advanced WordPress security with geo-blocking, rate limiting, and intelligent threat detection.
Version: 1.5.0
Author: WenPai.org
Author URI: https://wpban.com
Text Domain: wpban
Requires at least: 6.7.2
*/

if (!defined('ABSPATH')) {
    exit;
}

define('WPBAN_VERSION', '1.5.0');
define('WPBAN_DIR', plugin_dir_path(__FILE__));
define('WPBAN_URL', plugin_dir_url(__FILE__));
define('WPBAN_BYPASS_KEY', 'wpban_bypass_' . substr(md5(AUTH_KEY), 0, 12));

// Create database tables on activation
register_activation_hook(__FILE__, 'wpban_activate');
function wpban_activate() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // Create logs table with improved indexes
    $table_logs = $wpdb->prefix . 'wpban_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        ip varchar(45) NOT NULL,
        country_code varchar(2) DEFAULT NULL,
        user_agent text,
        referer text,
        uri text,
        action varchar(50) NOT NULL,
        reason text,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ip_timestamp (ip, timestamp),
        KEY action_timestamp (action, timestamp),
        KEY country_timestamp (country_code, timestamp)
    ) $charset_collate;";

    // Create rate limit table
    $table_rate = $wpdb->prefix . 'wpban_rate_limits';
    $sql_rate = "CREATE TABLE $table_rate (
        ip varchar(45) NOT NULL,
        action varchar(50) NOT NULL,
        count int(11) DEFAULT 1,
        window_start datetime NOT NULL,
        last_attempt datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ip, action),
        KEY window_start (window_start)
    ) $charset_collate;";

    // Create geo cache table
    $table_geo = $wpdb->prefix . 'wpban_geo_cache';
    $sql_geo = "CREATE TABLE $table_geo (
        ip varchar(45) NOT NULL,
        country_code varchar(2) NOT NULL,
        country_name varchar(100),
        city varchar(100),
        cached_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ip),
        KEY cached_at (cached_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_logs);
    dbDelta($sql_rate);
    dbDelta($sql_geo);

    // Set default options
    $default_settings = [
        'rate_limits' => [
            'requests_per_minute' => 60,
            'login_per_hour' => 5,
            'api_per_minute' => 30
        ],
        'geo_blocking' => [
            'enabled' => false,
            'blocked_countries' => [],
            'allowed_countries' => []
        ],
        'email_notifications' => [
            'enabled' => false,
            'recipient' => get_option('admin_email'),
            'threshold' => 10,
            'events' => ['rate_limit', 'geo_block', 'brute_force']
        ]
    ];

    $existing = get_option('wpban_settings', []);
    update_option('wpban_settings', array_merge($default_settings, $existing));

    // Generate bypass URL if not exists
    if (!get_option('wpban_bypass_path')) {
        update_option('wpban_bypass_path', wp_generate_password(16, false));
    }

    // Schedule cron jobs
    if (!wp_next_scheduled('wpban_cleanup')) {
        wp_schedule_event(time(), 'daily', 'wpban_cleanup');
    }

    if (!wp_next_scheduled('wpban_geo_update')) {
        wp_schedule_event(time(), 'weekly', 'wpban_geo_update');
    }
}

// Load required files
require_once WPBAN_DIR . 'includes/functions.php';
require_once WPBAN_DIR . 'includes/class-wpban-security.php';
require_once WPBAN_DIR . 'includes/class-wpban-admin.php';

// Initialize plugin
add_action('plugins_loaded', 'wpban_init');
function wpban_init() {
    load_plugin_textdomain('wpban', false, dirname(plugin_basename(__FILE__)) . '/languages/');

    // Initialize security module
    $GLOBALS['wpban_security'] = new WPBan_Security();

    if (is_admin()) {
        new WPBan_Admin();
    }
}

// Cron jobs
add_action('wpban_cleanup', 'wpban_cleanup_old_data');
function wpban_cleanup_old_data() {
    global $wpdb;

    // Clean old logs (keep 30 days)
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}wpban_logs WHERE timestamp < %s",
        date('Y-m-d H:i:s', strtotime('-30 days'))
    ));

    // Clean old rate limit records
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}wpban_rate_limits WHERE window_start < %s",
        date('Y-m-d H:i:s', strtotime('-24 hours'))
    ));

    // Clean old geo cache (keep 7 days)
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}wpban_geo_cache WHERE cached_at < %s",
        date('Y-m-d H:i:s', strtotime('-7 days'))
    ));
}

// Cleanup on uninstall
register_uninstall_hook(__FILE__, 'wpban_uninstall');
function wpban_uninstall() {
    global $wpdb;

    // Remove all options
    $options = [
        'wpban_settings', 'wpban_templates', 'wpban_bypass_path',
        'wpban_cache_data', 'wpban_stats', 'wpban_geo_db_version'
    ];

    foreach ($options as $option) {
        delete_option($option);
    }

    // Remove database tables
    $tables = ['wpban_logs', 'wpban_rate_limits', 'wpban_geo_cache'];
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
    }

    // Clear scheduled events
    wp_clear_scheduled_hook('wpban_cleanup');
    wp_clear_scheduled_hook('wpban_geo_update');

    // Clear cache
    wp_cache_delete_group('wpban');
}
