<?php
/*
Plugin Name: WPBan-Anything
Plugin URI: https://wpban.com/
Description: Ban users by IP, IP Range, host name, user agent, referer URL, restrict login page, block WeChat/QQ browsers, AI crawlers, and SEO crawlers.
Version: 3.3
Author: WPBan
Author URI: https://wpban.com
Text Domain: wpban-anything
Requires at least: 6.7.2
*/

if (!defined('ABSPATH')) {
    exit;
}

define('WPBAN_ANYTHING_VERSION', '3.3');
define('WPBAN_ANYTHING_DIR', plugin_dir_path(__FILE__));
define('WPBAN_ANYTHING_URL', plugin_dir_url(__FILE__));

require_once WPBAN_ANYTHING_DIR . 'includes/class-wpban-anything.php';
require_once WPBAN_ANYTHING_DIR . 'includes/class-wpban-ai-crawlers.php';
require_once WPBAN_ANYTHING_DIR . 'includes/class-wpban-seo-crawlers.php';
require_once WPBAN_ANYTHING_DIR . 'includes/functions.php';

function wpban_anything_init() {
    load_plugin_textdomain('wpban-anything', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    $wpban_anything = new WPBan_Anything\WPBan_Anything();
    $wpban_ai_crawlers = new WPBan_Anything\WPBan_AI_Crawlers();
    $wpban_seo_crawlers = new WPBan_Anything\WPBan_SEO_Crawlers();
}
add_action('plugins_loaded', 'wpban_anything_init');

register_uninstall_hook(__FILE__, 'wpban_anything_uninstall');
function wpban_anything_uninstall() {
    $options = [
        'banned_ips', 'banned_hosts', 'banned_stats', 'banned_message',
        'banned_referers', 'banned_exclude_ips', 'banned_ips_range',
        'banned_user_agents', 'banned_options', 'login_restrictions',
        'wechat_qq_settings', 'ai_crawler_settings', 'seo_crawler_settings'
    ];
    if (is_multisite()) {
        foreach (get_sites(['fields' => 'ids']) as $blog_id) {
            switch_to_blog($blog_id);
            array_walk($options, 'delete_option');
            restore_current_blog();
        }
    } else {
        array_walk($options, 'delete_option');
    }
}
