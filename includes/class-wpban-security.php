<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBan_Security {
    private $settings;
    private $bypass_active = false;
    private $cache_group = 'wpban';
    private $ip_address;
    
    public function __construct() {
        $this->load_settings();
        $this->ip_address = wpban_get_ip($this->settings['reverse_proxy'] ?? false);
        
        // Core hooks
        add_action('init', [$this, 'check_bypass'], 1);
        add_action('init', [$this, 'check_rate_limit'], 5);
        add_action('init', [$this, 'check_geo_block'], 6);
        add_action('init', [$this, 'check_ban'], 10);
        add_action('init', [$this, 'check_login_restriction'], 11);
        add_action('init', [$this, 'check_crawlers'], 12);
        add_action('wp_footer', [$this, 'check_browser_restrictions']);
        add_filter('robots_txt', [$this, 'modify_robots_txt'], 10, 2);
        
        // Login protection
        add_action('wp_login_failed', [$this, 'handle_login_failed']);
        add_filter('authenticate', [$this, 'check_login_attempt'], 30, 3);
    }
    
    private function load_settings() {
        $this->settings = wp_cache_get('settings', $this->cache_group);
        if ($this->settings === false) {
            $this->settings = get_option('wpban_settings', []);
            wp_cache_set('settings', $this->settings, $this->cache_group, 3600);
        }
    }
    
    public function check_bypass() {
        $bypass_path = get_option('wpban_bypass_path');
        
        // URL bypass
        if (isset($_GET['wpban_bypass']) && $_GET['wpban_bypass'] === $bypass_path) {
            setcookie(WPBAN_BYPASS_KEY, $bypass_path, time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            $this->bypass_active = true;
            $this->log('bypass', 'bypass_activated');
            wp_redirect(remove_query_arg('wpban_bypass'));
            exit;
        }
        
        // Cookie bypass
        if (isset($_COOKIE[WPBAN_BYPASS_KEY]) && $_COOKIE[WPBAN_BYPASS_KEY] === $bypass_path) {
            $this->bypass_active = true;
        }
    }
    
    public function check_rate_limit() {
        if ($this->bypass_active || empty($this->settings['rate_limits'])) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'wpban_rate_limits';
        $limits = $this->settings['rate_limits'];
        
        // Determine action type
        $action = 'general';
        if (strpos($_SERVER['REQUEST_URI'], '/wp-login.php') !== false) {
            $action = 'login';
        } elseif (strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false) {
            $action = 'api';
        }
        
        // Get limit settings
        $window = 60; // seconds
        $max_requests = $limits['requests_per_minute'] ?? 60;
        
        if ($action === 'login') {
            $window = 3600;
            $max_requests = $limits['login_per_hour'] ?? 5;
        } elseif ($action === 'api') {
            $max_requests = $limits['api_per_minute'] ?? 30;
        }
        
        $window_start = date('Y-m-d H:i:s', time() - $window);
        
        // Check current count
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT count, window_start FROM $table WHERE ip = %s AND action = %s AND window_start > %s",
            $this->ip_address, $action, $window_start
        ));
        
        if ($current) {
            if ($current->count >= $max_requests) {
                $this->log('blocked', 'rate_limit', $action);
                $this->send_notification('rate_limit', [
                    'action' => $action,
                    'count' => $current->count
                ]);
                wp_die(__('Too many requests. Please try again later.', 'wpban'), 429);
            }
            
            // Increment counter
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET count = count + 1, last_attempt = %s WHERE ip = %s AND action = %s",
                current_time('mysql'), $this->ip_address, $action
            ));
        } else {
            // Create new record
            $wpdb->insert($table, [
                'ip' => $this->ip_address,
                'action' => $action,
                'count' => 1,
                'window_start' => current_time('mysql'),
                'last_attempt' => current_time('mysql')
            ]);
        }
    }
    
    public function check_geo_block() {
        if ($this->bypass_active || empty($this->settings['geo_blocking']['enabled'])) {
            return;
        }
        
        $country = $this->get_country_code($this->ip_address);
        $blocked_countries = $this->settings['geo_blocking']['blocked_countries'] ?? [];
        $allowed_countries = $this->settings['geo_blocking']['allowed_countries'] ?? [];
        
        // If allowed list is set, only allow those countries
        if (!empty($allowed_countries) && !in_array($country, $allowed_countries)) {
            $this->log('blocked', 'geo_block', $country);
            $this->send_notification('geo_block', ['country' => $country]);
            wp_die(__('Access denied from your location.', 'wpban'), 403);
        }
        
        // Check blocked countries
        if (in_array($country, $blocked_countries)) {
            $this->log('blocked', 'geo_block', $country);
            $this->send_notification('geo_block', ['country' => $country]);
            wp_die(__('Access denied from your location.', 'wpban'), 403);
        }
    }
    
    public function check_ban() {
        if ($this->bypass_active) {
            return;
        }
        
        $checks = [
            'ip' => $this->is_ip_banned(),
            'host' => $this->is_host_banned(),
            'referer' => $this->is_referer_banned(),
            'agent' => $this->is_agent_banned()
        ];
        
        foreach ($checks as $type => $banned) {
            if ($banned && !$this->is_whitelisted()) {
                $this->log('banned', $type . '_ban');
                $this->show_ban_message();
            }
        }
    }
    
    public function check_login_restriction() {
        if ($this->bypass_active || empty($this->settings['login_allowed_ips'])) {
            return;
        }
        
        $login_files = ['wp-login.php', 'wp-register.php', 'xmlrpc.php'];
        $current_file = basename($_SERVER['SCRIPT_FILENAME']);
        
        if (in_array($current_file, $login_files)) {
            $allowed = false;
            foreach ($this->settings['login_allowed_ips'] as $pattern) {
                if (wpban_match_pattern($pattern, $this->ip_address)) {
                    $allowed = true;
                    break;
                }
            }
            
            if (!$allowed) {
                $this->log('blocked', 'login_restriction');
                wp_redirect(home_url());
                exit;
            }
        }
    }
    
    public function check_crawlers() {
        if ($this->bypass_active || empty($this->settings['blocked_crawlers'])) {
            return;
        }
        
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        foreach ($this->settings['blocked_crawlers'] as $crawler) {
            if (stripos($ua, $crawler) !== false) {
                $this->log('blocked', 'crawler_block', $crawler);
                http_response_code(403);
                exit('Access denied');
            }
        }
    }
    
    public function check_browser_restrictions() {
        if ($this->bypass_active || empty($this->settings['browser_restrictions'])) {
            return;
        }
        
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (isset($this->settings['browser_restrictions']['wechat_qq']) && 
            $this->settings['browser_restrictions']['wechat_qq']['enabled'] &&
            (strpos($ua, 'MQQBrowser') !== false || strpos($ua, 'MicroMessenger') !== false)) {
            
            $this->log('blocked', 'browser_restriction', 'WeChat/QQ');
            $this->show_browser_block_message($this->settings['browser_restrictions']['wechat_qq']);
        }
    }
    
    public function handle_login_failed($username) {
        $this->log('failed_login', 'login_attempt', $username);
        
        // Check for brute force
        global $wpdb;
        $table = $wpdb->prefix . 'wpban_logs';
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE ip = %s AND action = 'failed_login' AND timestamp > %s",
            $this->ip_address,
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        ));
        
        if ($count >= 10) {
            $this->send_notification('brute_force', [
                'attempts' => $count,
                'username' => $username
            ]);
        }
    }
    
    public function check_login_attempt($user, $username, $password) {
        if (empty($username) || empty($password)) {
            return $user;
        }
        
        // Additional login security checks can be added here
        return $user;
    }
    
    public function modify_robots_txt($output, $public) {
        if (!empty($this->settings['blocked_crawlers'])) {
            $output .= "\n# WPBan Security Rules\n";
            foreach ($this->settings['blocked_crawlers'] as $crawler) {
                $output .= "User-agent: $crawler\n";
                $output .= "Disallow: /\n\n";
            }
        }
        return $output;
    }
    
    // Helper methods
    private function is_ip_banned() {
        foreach ($this->settings['banned_ips'] ?? [] as $pattern) {
            if (wpban_match_pattern($pattern, $this->ip_address)) {
                return true;
            }
        }
        
        foreach ($this->settings['banned_ranges'] ?? [] as $range) {
            if (wpban_ip_in_range($this->ip_address, $range)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function is_host_banned() {
        if (empty($this->settings['banned_hosts'])) {
            return false;
        }
        
        $hostname = $this->get_cached_data('hostname_' . $this->ip_address, function() {
            return gethostbyaddr($this->ip_address);
        }, 3600);
        
        foreach ($this->settings['banned_hosts'] as $pattern) {
            if (wpban_match_pattern($pattern, $hostname)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function is_referer_banned() {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (empty($referer) || empty($this->settings['banned_referers'])) {
            return false;
        }
        
        foreach ($this->settings['banned_referers'] as $pattern) {
            if (wpban_match_pattern($pattern, $referer)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function is_agent_banned() {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (empty($agent) || empty($this->settings['banned_agents'])) {
            return false;
        }
        
        foreach ($this->settings['banned_agents'] as $pattern) {
            if (wpban_match_pattern($pattern, $agent)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function is_whitelisted() {
        foreach ($this->settings['whitelist_ips'] ?? [] as $pattern) {
            if (wpban_match_pattern($pattern, $this->ip_address) || 
                wpban_ip_in_range($this->ip_address, $pattern)) {
                return true;
            }
        }
        return false;
    }
    
    private function get_country_code($ip) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpban_geo_cache';
        
        // Check cache first
        $cached = $wpdb->get_var($wpdb->prepare(
            "SELECT country_code FROM $table WHERE ip = %s",
            $ip
        ));
        
        if ($cached) {
            return $cached;
        }
        
        // Use IP geolocation service
        $country = $this->lookup_country($ip);
        
        // Cache result
        if ($country) {
            $wpdb->replace($table, [
                'ip' => $ip,
                'country_code' => $country['code'],
                'country_name' => $country['name'],
                'city' => $country['city'] ?? null,
                'cached_at' => current_time('mysql')
            ]);
        }
        
        return $country['code'] ?? 'XX';
    }
    
    private function lookup_country($ip) {
        // Try multiple services
        $services = [
            'ipapi' => "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city",
            'ipinfo' => "https://ipinfo.io/{$ip}/json"
        ];
        
        foreach ($services as $name => $url) {
            $response = wp_remote_get($url, ['timeout' => 2]);
            
            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                
                if ($name === 'ipapi' && $data['status'] === 'success') {
                    return [
                        'code' => $data['countryCode'],
                        'name' => $data['country'],
                        'city' => $data['city']
                    ];
                } elseif ($name === 'ipinfo' && isset($data['country'])) {
                    return [
                        'code' => $data['country'],
                        'name' => $data['country'],
                        'city' => $data['city'] ?? null
                    ];
                }
            }
        }
        
        return null;
    }
    
    private function log($action, $reason, $details = '') {
        if (empty($this->settings['enable_logging'])) {
            return;
        }
        
        global $wpdb;
        $country = $action === 'geo_block' ? $details : $this->get_country_code($this->ip_address);
        
        $wpdb->insert($wpdb->prefix . 'wpban_logs', [
            'ip' => $this->ip_address,
            'country_code' => $country,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'referer' => substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
            'uri' => substr($_SERVER['REQUEST_URI'] ?? '', 0, 500),
            'action' => $action,
            'reason' => $reason . ($details ? ' - ' . $details : ''),
            'timestamp' => current_time('mysql')
        ]);
    }
    
    private function send_notification($type, $data = []) {
        if (empty($this->settings['email_notifications']['enabled']) ||
            !in_array($type, $this->settings['email_notifications']['events'] ?? [])) {
            return;
        }
        
        // Check threshold
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wpban_logs 
             WHERE ip = %s AND timestamp > %s",
            $this->ip_address,
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        ));
        
        if ($count < ($this->settings['email_notifications']['threshold'] ?? 10)) {
            return;
        }
        
        // Send email
        $to = $this->settings['email_notifications']['recipient'];
        $subject = sprintf(__('[%s] Security Alert: %s', 'wpban'), get_bloginfo('name'), $type);
        $message = $this->format_notification_email($type, $data);
        
        wp_mail($to, $subject, $message);
    }
    
    private function format_notification_email($type, $data) {
        $site = get_bloginfo('name');
        $time = current_time('mysql');
        
        $message = "Security alert on {$site}\n\n";
        $message .= "Event: {$type}\n";
        $message .= "Time: {$time}\n";
        $message .= "IP Address: {$this->ip_address}\n";
        $message .= "Country: " . $this->get_country_code($this->ip_address) . "\n";
        
        if (!empty($data)) {
            $message .= "\nDetails:\n";
            foreach ($data as $key => $value) {
                $message .= "- {$key}: {$value}\n";
            }
        }
        
        $message .= "\nView logs: " . admin_url('admin.php?page=wpban-logs');
        
        return $message;
    }
    
    private function show_ban_message() {
        $message = $this->settings['ban_message'] ?? '<h1>Access Denied</h1>';
        $message = str_replace(
            ['%IP%', '%DATE%', '%SITE%'],
            [$this->ip_address, current_time('mysql'), get_bloginfo('name')],
            $message
        );
        
        wp_die($message, 'Access Denied', ['response' => 403]);
    }
    
    private function show_browser_block_message($settings) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($settings['title']); ?></title>
            <style>
                body {
                    margin: 0;
                    padding: 0;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: rgba(0,0,0,0.9);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                }
                .block-container {
                    background: white;
                    padding: 40px;
                    border-radius: 10px;
                    max-width: 500px;
                    text-align: center;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                }
                h1 {
                    margin: 0 0 20px 0;
                    color: #333;
                }
                p {
                    color: #666;
                    line-height: 1.6;
                    margin-bottom: 30px;
                }
                button {
                    background: #2271b1;
                    color: white;
                    border: none;
                    padding: 12px 30px;
                    border-radius: 5px;
                    font-size: 16px;
                    cursor: pointer;
                    transition: background 0.3s;
                }
                button:hover {
                    background: #135e96;
                }
            </style>
        </head>
        <body>
            <div class="block-container">
                <h1><?php echo esc_html($settings['title']); ?></h1>
                <p><?php echo esc_html($settings['message']); ?></p>
                <button onclick="copyLink()">
                    <?php echo esc_html($settings['button_text']); ?>
                </button>
            </div>
            <script>
                function copyLink() {
                    navigator.clipboard.writeText(window.location.href).then(() => {
                        alert('<?php _e('Link copied to clipboard!', 'wpban'); ?>');
                    });
                }
            </script>
        </body>
        </html>
        <?php
        exit;
    }
    
    private function get_cached_data($key, $callback, $expiration = 3600) {
        $value = wp_cache_get($key, $this->cache_group);
        
        if ($value === false) {
            $value = $callback();
            wp_cache_set($key, $value, $this->cache_group, $expiration);
        }
        
        return $value;
    }
    
    // Public methods for admin
    public function get_logs($filters = [], $page = 1, $per_page = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpban_logs';
        
        $where = [];
        $where_values = [];
        
        if (!empty($filters['date_from'])) {
            $where[] = "timestamp >= %s";
            $where_values[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "timestamp <= %s";
            $where_values[] = $filters['date_to'] . ' 23:59:59';
        }
        
        if (!empty($filters['action'])) {
            $where[] = "action = %s";
            $where_values[] = $filters['action'];
        }
        
        if (!empty($filters['ip'])) {
            $where[] = "ip LIKE %s";
            $where_values[] = '%' . $wpdb->esc_like($filters['ip']) . '%';
        }
        
        if (!empty($filters['country'])) {
            $where[] = "country_code = %s";
            $where_values[] = $filters['country'];
        }
        
        $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM $table $where_clause";
        $total = $wpdb->get_var($wpdb->prepare($count_query, $where_values));
        
        // Get logs with pagination
        $offset = ($page - 1) * $per_page;
        $query = "SELECT * FROM $table $where_clause ORDER BY timestamp DESC LIMIT %d OFFSET %d";
        $where_values[] = $per_page;
        $where_values[] = $offset;
        
        $logs = $wpdb->get_results($wpdb->prepare($query, $where_values));
        
        return [
            'logs' => $logs,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page
        ];
    }
    
    public function get_stats() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        
        $stats = [
            'total_blocks' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpban_logs"),
            'unique_ips' => $wpdb->get_var("SELECT COUNT(DISTINCT ip) FROM {$prefix}wpban_logs"),
            'today_blocks' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}wpban_logs WHERE DATE(timestamp) = %s",
                current_time('Y-m-d')
            )),
            'active_rules' => $this->count_active_rules(),
            'top_countries' => $wpdb->get_results(
                "SELECT country_code, COUNT(*) as count 
                 FROM {$prefix}wpban_logs 
                 WHERE country_code IS NOT NULL 
                 GROUP BY country_code 
                 ORDER BY count DESC 
                 LIMIT 10"
            ),
            'recent_blocks' => $wpdb->get_results(
                "SELECT * FROM {$prefix}wpban_logs 
                 ORDER BY timestamp DESC 
                 LIMIT 10"
            )
        ];
        
        return $stats;
    }
    
    private function count_active_rules() {
        $count = 0;
        $rule_types = [
            'banned_ips', 'banned_ranges', 'banned_hosts', 
            'banned_referers', 'banned_agents', 'blocked_crawlers'
        ];
        
        foreach ($rule_types as $type) {
            $count += count($this->settings[$type] ?? []);
        }
        
        return $count;
    }
    
    public function clear_cache() {
        wp_cache_delete_group($this->cache_group);
    }
    
    public function get_templates() {
        return [
            'basic' => [
                'name' => __('Basic Protection', 'wpban'),
                'description' => __('Essential security for most WordPress sites', 'wpban'),
                'settings' => [
                    'banned_agents' => ['*bot*', '*crawler*', '*spider*'],
                    'enable_logging' => true,
                    'rate_limits' => [
                        'requests_per_minute' => 60,
                        'login_per_hour' => 5
                    ]
                ]
            ],
            'strict' => [
                'name' => __('Strict Security', 'wpban'),
                'description' => __('Maximum protection with geo-blocking and rate limiting', 'wpban'),
                'settings' => [
                    'banned_agents' => ['*bot*', '*crawler*', '*spider*', '*scraper*', 'curl*', 'wget*'],
                    'blocked_crawlers' => ['GPTBot', 'ChatGPT-User', 'ClaudeBot', 'CCBot'],
                    'rate_limits' => [
                        'requests_per_minute' => 30,
                        'login_per_hour' => 3,
                        'api_per_minute' => 10
                    ],
                    'geo_blocking' => [
                        'enabled' => true,
                        'blocked_countries' => []
                    ]
                ]
            ],
            'content' => [
                'name' => __('Content Protection', 'wpban'),
                'description' => __('Protect content from AI and scraping', 'wpban'),
                'settings' => [
                    'blocked_crawlers' => array_keys(wpban_get_crawler_list()['ai']),
                    'banned_agents' => ['*GPT*', '*Claude*', '*AI*Bot*'],
                    'rate_limits' => [
                        'requests_per_minute' => 30
                    ]
                ]
            ],
            'performance' => [
                'name' => __('Performance Mode', 'wpban'),
                'description' => __('Balanced security with minimal performance impact', 'wpban'),
                'settings' => [
                    'enable_logging' => false,
                    'rate_limits' => [
                        'requests_per_minute' => 120,
                        'login_per_hour' => 10
                    ]
                ]
            ]
        ];
    }
}