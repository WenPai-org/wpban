<?php
namespace WPBan_Anything;

if (!defined('ABSPATH')) {
    exit;
}

class WPBan_Anything {
    private $ai_crawlers;
    private $seo_crawlers;

    public function __construct() {
        add_action('init', [$this, 'check_ban']);
        add_action('init', [$this, 'restrict_login_access']);
        add_action('wp_footer', [$this, 'block_wechat_qq_browsers']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_ban_anything_preview', [$this, 'preview_banned_message']);
        add_action('wp_ajax_save_ban_anything_settings', [$this, 'ajax_save_settings']);
        $this->ai_crawlers = new WPBan_AI_Crawlers();
        $this->seo_crawlers = new WPBan_SEO_Crawlers();
    }

    public function check_ban() {
        $ip = ban_anything_get_ip();
        $options = $this->get_options();
        $is_banned = false;

        foreach ($options['banned_ips'] as $banned_ip) {
            if (preg_match_wildcard($banned_ip, $ip)) {
                $is_banned = true;
                break;
            }
        }
        if ($is_banned && !in_array($ip, $options['banned_exclude_ips'])) {
            $this->update_stats($ip, 'ip_ban');
            $this->print_banned_message();
        }
    }

    public function print_banned_message() {
        $options = $this->get_options();
        $message = str_replace(
            ['%SITE_NAME%', '%SITE_URL%', '%USER_IP%', '%USER_HOSTNAME%'],
            [get_option('blogname'), get_option('siteurl'), ban_anything_get_ip(), @gethostbyaddr(ban_anything_get_ip())],
            stripslashes($options['banned_message'])
        );
        wp_die($message, 'Access Denied', ['response' => 403]);
    }

    public function restrict_login_access() {
        $options = $this->get_options();
        if (strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) {
            $user_ip = ban_anything_get_ip();
            $allowed_ips = $options['login_restrictions']['allowed_ips'] ?? [];
            if (!empty($allowed_ips) && !ban_anything_ip_in_range($user_ip, $allowed_ips)) {
                $this->update_stats($user_ip, 'login_restriction');
                wp_redirect(home_url());
                exit;
            }
        }
    }

    public function block_wechat_qq_browsers() {
        $options = $this->get_options();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (strpos($ua, 'MQQBrowser') !== false || strpos($ua, 'MicroMessenger') !== false) {
            $settings = $options['wechat_qq_settings'];
            if ($settings['enabled']) {
                $this->update_stats(ban_anything_get_ip(), 'wechat_qq_block');
                ?>
                <div id="wechat-qq-mask" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; backdrop-filter: blur(10px); z-index: 9999; display: flex; justify-content: center; align-items: center;">
                    <div style="background-color: rgba(255, 255, 255, 0.8); padding: 20px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); max-width: 90%; text-align: center;">
                        <p style="font-size: 16px; line-height: 1.5em; margin-bottom: 20px;">
                            <span style="color: red; font-size: 24px; vertical-align: middle;">⚠</span>
                            <?php echo esc_html($settings['message']); ?>
                        </p>
                        <button id="wechat-qq-copy" style="width: 140px; background-color: #007aff; color: #fff; border-radius: 5px; padding: 10px 0; border: none; font-size: 16px; cursor: pointer;">
                            <?php echo esc_html($settings['copy_text']); ?>
                        </button>
                    </div>
                </div>
                <script>
                document.getElementById('wechat-qq-copy').addEventListener('click', function() {
                    navigator.clipboard.writeText(window.location.href).then(() => {
                        alert('<?php _e("Link copied to clipboard!", "wpban"); ?>');
                    });
                });
                document.body.style.overflow = 'hidden';
                </script>
                <?php
            }
        }
    }

    public function add_admin_menu() {
        $hook = add_options_page(
            __('Ban Anything', 'wpban'),
            __('Ban Anything', 'wpban'),
            'manage_options',
            'wpban',
            [$this, 'render_admin_page']
        );
        add_action("admin_print_scripts-{$hook}", function() {
            wp_enqueue_script('jquery');
        });
    }

    private function get_options() {
        return [
            'banned_ips' => get_option('banned_ips', []),
            'banned_ips_range' => get_option('banned_ips_range', []),
            'banned_hosts' => get_option('banned_hosts', []),
            'banned_referers' => get_option('banned_referers', []),
            'banned_user_agents' => get_option('banned_user_agents', []),
            'banned_exclude_ips' => get_option('banned_exclude_ips', []),
            'banned_message' => get_option('banned_message', "<div id='wp-ban-container'><p>You are banned.</p></div>"),
            'banned_options' => get_option('banned_options', ['reverse_proxy' => 0]),
            'login_restrictions' => get_option('login_restrictions', ['allowed_ips' => []]),
            'wechat_qq_settings' => get_option('wechat_qq_settings', [
                'enabled' => false,
                'message' => __('Please use your system browser to open this site.', 'wpban'),
                'copy_text' => __('Copy Link', 'wpban')
            ]),
            'banned_stats' => get_option('banned_stats', [
                'users' => [],
                'total_count' => 0,
                'last_ban_time' => null,
                'types' => ['ip_ban' => 0, 'login_restriction' => 0, 'wechat_qq_block' => 0, 'ai_crawler_block' => 0, 'seo_crawler_block' => 0]
            ]),
            'ai_crawler_settings' => $this->ai_crawlers->get_settings(),
            'seo_crawler_settings' => $this->seo_crawlers->get_settings()
        ];
    }

    private function save_options($data) {
        $allowed_tags = wp_kses_allowed_html('post');
        $allowed_tags['html'] = $allowed_tags['head'] = $allowed_tags['body'] = true;
        $allowed_tags['meta'] = ['charset' => true];

        $options = [
            'banned_options' => ['reverse_proxy' => isset($data['reverse_proxy']) ? 1 : 0],
            'banned_ips' => array_map('esc_html', array_filter(explode("\n", trim($data['banned_ips'] ?? '')))),
            'banned_ips_range' => array_map('esc_html', array_filter(explode("\n", trim($data['banned_ips_range'] ?? '')))),
            'banned_hosts' => array_map('esc_html', array_filter(explode("\n", trim($data['banned_hosts'] ?? '')))),
            'banned_referers' => array_map('esc_html', array_filter(explode("\n", trim($data['banned_referers'] ?? '')))),
            'banned_user_agents' => array_map('esc_html', array_filter(explode("\n", trim($data['banned_user_agents'] ?? '')))),
            'banned_exclude_ips' => array_map('esc_html', array_filter(explode("\n", trim($data['banned_exclude_ips'] ?? '')))),
            'banned_message' => wp_kses(trim($data['banned_message'] ?? ''), $allowed_tags),
            'login_restrictions' => [
                'allowed_ips' => array_map('esc_html', array_filter(explode("\n", trim($data['login_allowed_ips'] ?? ''))))
            ],
            'wechat_qq_settings' => [
                'enabled' => isset($data['wechat_qq_enabled']) ? 1 : 0,
                'message' => esc_html($data['wechat_qq_message'] ?? ''),
                'copy_text' => esc_html($data['wechat_qq_copy_text'] ?? '')
            ],
            'ai_crawler_settings' => $this->ai_crawlers->save_settings($data),
            'seo_crawler_settings' => $this->seo_crawlers->save_settings($data)
        ];

        foreach ($options as $key => $value) {
            if ($key !== 'ai_crawler_settings' && $key !== 'seo_crawler_settings') {
                update_option($key, $value);
            }
        }
        return $options;
    }

    private function update_stats($ip, $type) {
        $stats = get_option('banned_stats', [
            'users' => [],
            'total_count' => 0,
            'last_ban_time' => null,
            'types' => ['ip_ban' => 0, 'login_restriction' => 0, 'wechat_qq_block' => 0, 'ai_crawler_block' => 0, 'seo_crawler_block' => 0]
        ]);
        $stats['users'][$ip] = [
            'count' => ($stats['users'][$ip]['count'] ?? 0) + 1,
            'last_time' => current_time('mysql'),
            'type' => $type
        ];
        $stats['total_count']++;
        $stats['last_ban_time'] = current_time('mysql');
        $stats['types'][$type]++;
        update_option('banned_stats', $stats);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'wpban'));
        }

        $options = $this->get_options();
        ?>
        <div class="wrap">
            <h1><?php _e('WPBan Anything Settings', 'wpban'); ?></h1>

            <div class="card">
                <h2><?php _e('Your Details', 'wpban'); ?></h2>
                <p><?php _e('Your current connection details.', 'wpban'); ?></p>
                <table class="wp-list-table widefat fixed">
                    <tr><th><?php _e('IP', 'wpban'); ?></th><td><?php echo ban_anything_get_ip(); ?></td></tr>
                    <tr><th><?php _e('Host', 'wpban'); ?></th><td><?php echo @gethostbyaddr(ban_anything_get_ip()); ?></td></tr>
                    <tr><th><?php _e('User Agent', 'wpban'); ?></th><td><?php echo esc_html($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'); ?></td></tr>
                </table>
            </div>

            <div class="card">
                <h2><?php _e('Ban Settings', 'wpban'); ?></h2>
                <span id="ban-save-status" class="notice" style="display:none; margin-top: 10px;"></span>
                <form id="ban-settings-form" method="post">
                    <?php wp_nonce_field('wpban_anything_nonce', 'wpban_anything_nonce'); ?>
                    <div class="styles-sync-tabs">
                        <?php
                        $sections = [
                            'general_bans' => ['title' => __('General Bans', 'wpban'), 'desc' => __('Basic ban settings for IPs.', 'wpban')],
                            'advanced_bans' => ['title' => __('Advanced Bans', 'wpban'), 'desc' => __('Ban settings for hosts and agents.', 'wpban')],
                            'login_restrictions' => ['title' => __('Login Restrictions', 'wpban'), 'desc' => __('Restrict login page access.', 'wpban')],
                            'browser_bans' => ['title' => __('Browser Bans', 'wpban'), 'desc' => __('Ban specific browsers.', 'wpban')],
                            'ai_bans' => ['title' => __('AI Bans', 'wpban'), 'desc' => __('Block AI crawlers.', 'wpban')],
                            'seo_bans' => ['title' => __('SEO Bans', 'wpban'), 'desc' => __('Block SEO crawlers.', 'wpban')],
                            'ban_message' => ['title' => __('Ban Message', 'wpban'), 'desc' => __('Customize the ban message.', 'wpban')],
                            'options' => ['title' => __('Options', 'wpban'), 'desc' => __('Additional ban options.', 'wpban')],
                        ];
                        foreach ($sections as $key => $data): ?>
                            <button type="button" class="styles-tab <?php echo $key === 'general_bans' ? 'active' : ''; ?>" data-tab="<?php echo esc_attr($key); ?>">
                                <?php echo esc_html($data['title']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="styles-sync-content">
                        <div class="postbox styles-section" data-section="general_bans" style="">
                            <div class="postbox-header"><h2 class="hndle"><?php _e('General Bans', 'wpban'); ?></h2></div>
                            <div class="inside">
                                <p><?php _e('Banned IPs (Use * for wildcards, e.g., 192.168.1.*):', 'wpban'); ?></p>
                                <textarea name="banned_ips" rows="5" cols="50"><?php echo esc_textarea(implode("\n", $options['banned_ips'])); ?></textarea>
                                <p><?php _e('Banned IP Ranges (e.g., 192.168.1.1-192.168.1.255):', 'wpban'); ?></p>
                                <textarea name="banned_ips_range" rows="5" cols="50"><?php echo esc_textarea(implode("\n", $options['banned_ips_range'])); ?></textarea>
                                <p><?php _e('Excluded IPs (e.g., 192.168.1.100):', 'wpban'); ?></p>
                                <textarea name="banned_exclude_ips" rows="5" cols="50"><?php echo esc_textarea(implode("\n", $options['banned_exclude_ips'])); ?></textarea>
                            </div>
                        </div>
                        <div class="postbox styles-section" data-section="advanced_bans" style="display:none;">
                            <div class="postbox-header"><h2 class="hndle"><?php _e('Advanced Bans', 'wpban'); ?></h2></div>
                            <div class="inside">
                                <p><?php _e('Banned Hosts (Use * for wildcards, e.g., *.com):', 'wpban'); ?></p>
                                <textarea name="banned_hosts" rows="5" cols="50"><?php echo esc_textarea(implode("\n", $options['banned_hosts'])); ?></textarea>
                                <p><?php _e('Banned Referers (e.g., http://*.example.com):', 'wpban'); ?></p>
                                <textarea name="banned_referers" rows="5" cols="50"><?php echo esc_textarea(implode("\n", $options['banned_referers'])); ?></textarea>
                                <p><?php _e('Banned User Agents (e.g., Bot*):', 'wpban'); ?></p>
                                <textarea name="banned_user_agents" rows="5" cols="50"><?php echo esc_textarea(implode("\n", $options['banned_user_agents'])); ?></textarea>
                            </div>
                        </div>
                        <div class="postbox styles-section" data-section="login_restrictions" style="display:none;">
                            <div class="postbox-header"><h2 class="hndle"><?php _e('Login Restrictions', 'wpban'); ?></h2></div>
                            <div class="inside">
                                <p><?php _e('Allowed IPs for wp-login.php (e.g., 192.168.1.0/24):', 'wpban'); ?></p>
                                <textarea name="login_allowed_ips" rows="5" cols="50"><?php echo esc_textarea(implode("\n", $options['login_restrictions']['allowed_ips'])); ?></textarea>
                            </div>
                        </div>
                        <div class="postbox styles-section" data-section="browser_bans" style="display:none;">
                            <div class="postbox-header"><h2 class="hndle"><?php _e('Browser Bans', 'wpban'); ?></h2></div>
                            <div class="inside">
                                <label><input type="checkbox" name="wechat_qq_enabled" value="1" <?php checked($options['wechat_qq_settings']['enabled'], 1); ?>> <?php _e('Enable WeChat/QQ Ban', 'wpban'); ?></label><br>
                                <p><?php _e('Message:', 'wpban'); ?></p>
                                <textarea name="wechat_qq_message" rows="3" cols="50"><?php echo esc_textarea($options['wechat_qq_settings']['message']); ?></textarea><br>
                                <p><?php _e('Copy Button Text:', 'wpban'); ?></p>
                                <input type="text" name="wechat_qq_copy_text" value="<?php echo esc_attr($options['wechat_qq_settings']['copy_text']); ?>">
                            </div>
                        </div>
                        <div class="postbox styles-section" data-section="ai_bans" style="display:none;">
                            <div class="postbox-header">
                                <h2 class="hndle"><?php _e('AI Bans', 'wpban'); ?></h2>
                                <div class="handle-actions hide-if-no-js">
                                    <button type="button" class="select-all button button-small" data-section="ai_bans"><?php _e('Select All', 'wpban'); ?></button>
                                    <button type="button" class="select-none button button-small" data-section="ai_bans"><?php _e('Select None', 'wpban'); ?></button>
                                </div>
                            </div>
                            <div class="inside">
                                <p><?php _e('Search AI crawlers:', 'wpban'); ?></p>
                                <input type="text" id="ai-crawler-search" placeholder="<?php _e('Search...', 'wpban'); ?>" style="width: 100%; margin-bottom: 10px;">
                                <?php
                                $ai_groups = [
                                    'AI Training' => ['GPTBot', 'ChatGPT-User', 'ClaudeBot', 'anthropic-ai', 'cohere-ai', 'Bytespider'],
                                    'Social Media' => ['FacebookBot', 'Meta-ExternalAgent'],
                                    'Research & Analytics' => array_diff(array_keys($this->ai_crawlers->get_crawler_list()), ['GPTBot', 'ChatGPT-User', 'ClaudeBot', 'anthropic-ai', 'cohere-ai', 'Bytespider', 'FacebookBot', 'Meta-ExternalAgent'])
                                ];
                                foreach ($ai_groups as $group => $crawlers): ?>
                                    <details open>
                                        <summary><?php echo esc_html($group); ?> (<?php echo count($crawlers); ?> crawlers)</summary>
                                        <table class="wp-list-table widefat fixed crawler-table" data-type="ai">
                                            <thead>
                                                <tr>
                                                    <th><?php _e('Crawler', 'wpban'); ?></th>
                                                    <th><?php _e('Description', 'wpban'); ?></th>
                                                    <th><?php _e('Risk', 'wpban'); ?></th>
                                                    <th><?php _e('Recommended', 'wpban'); ?></th>
                                                    <th><?php _e('Block', 'wpban'); ?></th>
                                                    <th><?php _e('More Info', 'wpban'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($crawlers as $crawler):
                                                    $info = $this->ai_crawlers->get_crawler_list()[$crawler];
                                                    $risk = in_array($crawler, ['GPTBot', 'ClaudeBot']) ? 'High' : (in_array($crawler, ['FacebookBot']) ? 'Medium' : 'Low');
                                                    $recommended = in_array($crawler, ['GPTBot', 'ClaudeBot']) ? 'Yes' : 'No';
                                                    ?>
                                                    <tr data-group="<?php echo esc_attr($group); ?>">
                                                        <td><?php echo esc_html($crawler); ?></td>
                                                        <td><?php echo esc_html($info['description']); ?> <span class="dashicons dashicons-info" title="<?php echo esc_attr(__('Blocking may affect AI training datasets.', 'wpban')); ?>"></span></td>
                                                        <td><?php echo esc_html($risk); ?></td>
                                                        <td><?php echo esc_html($recommended); ?></td>
                                                        <td><input type="checkbox" name="ai_crawlers[]" value="<?php echo esc_attr($crawler); ?>" <?php checked(in_array($crawler, $options['ai_crawler_settings']['enabled_crawlers'])); ?> class="crawler-checkbox"></td>
                                                        <td><a href="<?php echo esc_url($info['link']); ?>" target="_blank"><?php _e('More Info', 'wpban'); ?></a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </details>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="postbox styles-section" data-section="seo_bans" style="display:none;">
                            <div class="postbox-header">
                                <h2 class="hndle"><?php _e('SEO Bans', 'wpban'); ?></h2>

                                <div class="handle-actions hide-if-no-js">
                                    <button type="button" class="select-all button button-small" data-section="seo_bans"><?php _e('Select All', 'wpban'); ?></button>
                                    <button type="button" class="select-none button button-small" data-section="seo_bans"><?php _e('Select None', 'wpban'); ?></button>
                                </div>
                            </div>
                            <div class="notice notice-warning" style="margin-bottom: 15px;">
                                <p><?php _e("Warning: Blocking major SEO crawlers (e.g., Googlebot, Bingbot) may reduce your site's visibility and traffic. Review recommendations before saving.", "wpban"); ?></p>
                            </div>
                            <div class="inside">
                                <p><?php _e('Search SEO crawlers:', 'wpban'); ?></p>
                                <input type="text" id="seo-crawler-search" placeholder="<?php _e('Search...', 'wpban'); ?>" style="width: 100%; margin-bottom: 10px;">
                                <?php
                                $seo_groups = [
                                    'Major Search Engines' => ['Googlebot', 'Bingbot', 'YandexBot', 'Baiduspider'],
                                    'SEO Analytics' => ['AhrefsBot', 'SemrushBot', 'MJ12bot'],
                                    'Other Crawlers' => array_diff(array_keys($this->seo_crawlers->get_crawler_list()), ['Googlebot', 'Bingbot', 'YandexBot', 'Baiduspider', 'AhrefsBot', 'SemrushBot', 'MJ12bot'])
                                ];
                                foreach ($seo_groups as $group => $crawlers): ?>
                                    <details open>
                                        <summary><?php echo esc_html($group); ?> (<?php echo count($crawlers); ?> crawlers)</summary>
                                        <table class="wp-list-table widefat fixed crawler-table" data-type="seo">
                                            <thead>
                                                <tr>
                                                    <th><?php _e('Crawler', 'wpban'); ?></th>
                                                    <th><?php _e('Description', 'wpban'); ?></th>
                                                    <th><?php _e('Risk', 'wpban'); ?></th>
                                                    <th><?php _e('Recommended', 'wpban'); ?></th>
                                                    <th><?php _e('Block', 'wpban'); ?></th>
                                                    <th><?php _e('More Info', 'wpban'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($crawlers as $crawler):
                                                    $info = $this->seo_crawlers->get_crawler_list()[$crawler];
                                                    $risk = in_array($crawler, ['Googlebot', 'Bingbot']) ? 'High' : (in_array($crawler, ['AhrefsBot']) ? 'Medium' : 'Low');
                                                    $recommended = in_array($crawler, ['Googlebot', 'Bingbot']) ? 'No' : 'Yes';
                                                    ?>
                                                    <tr data-group="<?php echo esc_attr($group); ?>">
                                                        <td><?php echo esc_html($crawler); ?></td>
                                                        <td><?php echo esc_html($info['description']); ?> <span class="dashicons dashicons-info" title="<?php echo esc_attr(__('Blocking may affect SEO rankings.', 'wpban')); ?>"></span></td>
                                                        <td><?php echo esc_html($risk); ?></td>
                                                        <td><?php echo esc_html($recommended); ?></td>
                                                        <td><input type="checkbox" name="seo_crawlers[]" value="<?php echo esc_attr($crawler); ?>" <?php checked(in_array($crawler, $options['seo_crawler_settings']['enabled_crawlers'])); ?> class="crawler-checkbox"></td>
                                                        <td><a href="<?php echo esc_url($info['link']); ?>" target="_blank"><?php _e('More Info', 'wpban'); ?></a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </details>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="postbox styles-section" data-section="ban_message" style="display:none;">
                            <div class="postbox-header"><h2 class="hndle"><?php _e('Ban Message', 'wpban'); ?></h2></div>
                            <div class="inside">
                                <p><?php _e('Message shown to banned users:', 'wpban'); ?></p>
                                <textarea name="banned_message" rows="10" style="width:100%;"><?php echo esc_textarea($options['banned_message']); ?></textarea>
                            </div>
                        </div>
                        <div class="postbox styles-section" data-section="options" style="display:none;">
                            <div class="postbox-header"><h2 class="hndle"><?php _e('Options', 'wpban'); ?></h2></div>
                            <div class="inside">
                                <p><label><input type="checkbox" name="reverse_proxy" value="1" <?php checked($options['banned_options']['reverse_proxy'], 1); ?>> <?php _e('Use Reverse Proxy', 'wpban'); ?></label></p>
                            </div>
                        </div>
                    </div>
                    <p class="submit"><button type="button" id="ban-save-settings" class="button button-primary"><?php _e('Save Changes', 'wpban'); ?></button></p>
                </form>
            </div>

            <div class="card">
                <h2><?php _e('Ban Statistics', 'wpban'); ?></h2>
                <p><?php _e('View detailed ban attempt statistics.', 'wpban'); ?></p>
                <table class="wp-list-table widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('Metric', 'wpban'); ?></th>
                            <th><?php _e('Value', 'wpban'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><th><?php _e('Total Ban Attempts', 'wpban'); ?></th><td><?php echo esc_html($options['banned_stats']['total_count']); ?></td></tr>
                        <tr><th><?php _e('Unique IPs Banned', 'wpban'); ?></th><td><?php echo esc_html(count($options['banned_stats']['users'])); ?></td></tr>
                        <tr><th><?php _e('Last Ban Time', 'wpban'); ?></th><td><?php echo esc_html($options['banned_stats']['last_ban_time'] ?? __('Never', 'wpban')); ?></td></tr>
                        <tr><th><?php _e('IP Bans', 'wpban'); ?></th><td><?php echo esc_html($options['banned_stats']['types']['ip_ban']); ?></td></tr>
                        <tr><th><?php _e('Login Restrictions', 'wpban'); ?></th><td><?php echo esc_html($options['banned_stats']['types']['login_restriction']); ?></td></tr>
                        <tr><th><?php _e('WeChat/QQ Bans', 'wpban'); ?></th><td><?php echo esc_html($options['banned_stats']['types']['wechat_qq_block']); ?></td></tr>
                        <tr><th><?php _e('AI Crawler Bans', 'wpban'); ?></th><td><?php echo esc_html($options['banned_stats']['types']['ai_crawler_block']); ?></td></tr>
                        <tr><th><?php _e('SEO Crawler Bans', 'wpban'); ?></th><td><?php echo esc_html($options['banned_stats']['types']['seo_crawler_block']); ?></td></tr>
                        <tr><th><?php _e('Recent Banned IPs', 'wpban'); ?></th><td>
                            <?php
                            $recent = array_slice($options['banned_stats']['users'], -5, 5, true);
                            if (empty($recent)) {
                                echo __('None', 'wpban');
                            } else {
                                foreach ($recent as $ip => $data) {
                                    echo esc_html("$ip ({$data['count']} times, last: {$data['last_time']}, type: {$data['type']})<br>");
                                }
                            }
                            ?>
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <script>
            jQuery(document).ready(function($) {
                // Tab functionality
                $('.styles-tab').on('click', function() {
                    $('.styles-tab').removeClass('active');
                    $(this).addClass('active');
                    var tab = $(this).data('tab');
                    $('.styles-section').hide();
                    $(`[data-section="${tab}"]`).show();
                });

                // Search and filter crawlers
                $('#ai-crawler-search, #seo-crawler-search').on('keyup', function() {
                    var $input = $(this);
                    var value = $input.val().toLowerCase();
                    var $tables = $input.siblings('details').find('.crawler-table');
                    $tables.find('tbody tr').each(function() {
                        var $row = $(this);
                        var text = $row.text().toLowerCase();
                        $row.toggle(text.indexOf(value) > -1);
                    });
                });

                // Select All/None
                $('.select-all').on('click', function(e) {
                    e.preventDefault();
                    var section = $(this).data('section');
                    $(`.styles-section[data-section="${section}"] .crawler-checkbox`).prop('checked', true);
                });
                $('.select-none').on('click', function(e) {
                    e.preventDefault();
                    var section = $(this).data('section');
                    $(`.styles-section[data-section="${section}"] .crawler-checkbox`).prop('checked', false);
                });

                // Save settings with confirmation
                $('#ban-save-settings').on('click', function() {
                    var $button = $(this);
                    var formData = $('#ban-settings-form').serializeArray();
                    var settings = {};
                    formData.forEach(item => {
                        if (item.name.endsWith('[]')) {
                            var key = item.name.replace('[]', '');
                            if (!settings[key]) settings[key] = [];
                            settings[key].push(item.value);
                        } else {
                            settings[item.name] = item.value;
                        }
                    });

                    // Check for critical SEO crawlers
                    var criticalCrawlers = ['Googlebot', 'Bingbot'];
                    var blockingCritical = criticalCrawlers.some(crawler => settings['seo_crawlers'] && settings['seo_crawlers'].includes(crawler));
                    if (blockingCritical && !confirm('<?php _e("You are about to block major SEO crawlers (e.g., Googlebot, Bingbot), which may reduce your site's traffic. Are you sure?", "wpban"); ?>')) {
                        return;
                    }

                    $button.prop('disabled', true);
                    $('#ban-save-status').text('<?php _e("Saving...", "wpban"); ?>').show();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'save_ban_anything_settings',
                            settings: settings,
                            _ajax_nonce: $('#wpban_anything_nonce').val()
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#ban-save-status').removeClass('notice-error').addClass('notice-success')
                                    .text('<?php _e("Settings saved successfully!", "wpban"); ?>')
                                    .delay(3000).fadeOut();
                            } else {
                                $('#ban-save-status').removeClass('notice-success').addClass('notice-error')
                                    .text(response.data || '<?php _e("Failed to save settings.", "wpban"); ?>');
                            }
                            $button.prop('disabled', false);
                        },
                        error: function(xhr, status, error) {
                            $('#ban-save-status').removeClass('notice-success').addClass('notice-error')
                                .text('<?php _e("Server error occurred:", "wpban"); ?>' + error);
                            $button.prop('disabled', false);
                        }
                    });
                });
            });
            </script>

            <style>
            .card { background: #fff; border: 1px solid #ccd0d4; max-width: unset; border-radius: 4px; margin-top: 20px; padding: 20px; }
            .styles-sync-tabs { display: flex; gap: 5px; border-bottom: 1px solid #c3c4c7; margin-bottom: 20px; flex-wrap: wrap; }
            .styles-tab { padding: 8px 16px; border: none; background: none; cursor: pointer; font-size: 14px; border-bottom: 2px solid transparent; }
            .styles-tab.active { border-bottom: 2px solid #007cba; font-weight: 600; background: #f0f0f1; }
            .styles-tab:hover:not(.active) { background: #f0f0f1; }
            .postbox { border: 0; }
            .postbox-header { padding: 10px; display: flex; justify-content: space-between; align-items: center; }
            .postbox .handle-actions { display: flex; gap: 5px; }
            .postbox .button-small { font-size: 11px; padding: 0 6px; line-height: 20px; height: 20px; }
            .notice { padding: 8px 12px; border-radius: 3px; }
            .notice-success { background-color: #dff0d8; border-left: 4px solid #46b450; }
            .notice-error { background-color: #f2dede; border-left: 4px solid #dc3232; }
            .notice-warning { background-color: #fff3cd; border-left: 4px solid #ffca2c; }
            details { margin-bottom: 15px; }
            summary { font-weight: bold; cursor: pointer; padding: 5px; }
            .dashicons-info { font-size: 16px; vertical-align: middle; color: #007cba; cursor: help; }
            </style>
        </div>
        <?php
    }

    public function ajax_save_settings() {
        if (!check_ajax_referer('wpban_anything_nonce', '_ajax_nonce', false)) {
            wp_send_json_error(__('Invalid nonce.', 'wpban'));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'wpban'));
        }

        $settings = isset($_POST['settings']) ? wp_unslash((array)$_POST['settings']) : [];
        $this->save_options($settings);
        wp_send_json_success(['message' => __('Settings saved.', 'wpban')]);
    }

    public function preview_banned_message() {
        check_ajax_referer('wpban_anything_nonce');
        $this->print_banned_message();
    }
}
