<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBan_Core {
    private $cache;
    private $logger;
    private $settings;
    private $bypass_cookie = false;
    
    public function __construct($cache, $logger) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->settings = $this->get_settings();
        
        // Check bypass first
        add_action('init', [$this, 'check_bypass'], 1);
        
        // Main security checks
        add_action('init', [$this, 'check_ban'], 10);
        add_action('init', [$this, 'check_login_restriction'], 11);
        add_action('wp_footer', [$this, 'check_browser_restrictions']);
        
        // Robots.txt modifications
        add_filter('robots_txt', [$this, 'modify_robots_txt'], 10, 2);
        
        // Performance: only load heavy checks if needed
        if ($this->should_check_crawlers()) {
            add_action('init', [$this, 'check_crawlers'], 12);
        }
    }
    
    private function get_settings() {
        return $this->cache->get('settings', function() {
            return get_option('wpban_settings', [
                'banned_ips' => [],
                'banned_ranges' => [],
                'banned_hosts' => [],
                'banned_referers' => [],
                'banned_agents' => [],
                'whitelist_ips' => [],
                'login_allowed_ips' => [],
                'blocked_crawlers' => [],
                'browser_restrictions' => [],
                'ban_message' => '<h1>Access Denied</h1><p>Your access to this site has been restricted.</p>',
                'enable_logging' => true,
                'enable_caching' => true,
                'reverse_proxy' => false
            ]);
        });
    }
    
    public function check_bypass() {
        $bypass_path = get_option('wpban_bypass_path');
        
        // Check URL bypass
        if (isset($_GET['wpban_bypass']) && $_GET['wpban_bypass'] === $bypass_path) {
            setcookie(WPBAN_BYPASS_KEY, $bypass_path, time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            $this->bypass_cookie = true;
            wp_redirect(remove_query_arg('wpban_bypass'));
            exit;
        }
        
        // Check cookie bypass
        if (isset($_COOKIE[WPBAN_BYPASS_KEY]) && $_COOKIE[WPBAN_BYPASS_KEY] === $bypass_path) {
            $this->bypass_cookie = true;
        }
    }
    
    public function check_ban() {
        if ($this->bypass_cookie) {
            return;
        }
        
        $ip = wpban_get_ip($this->settings['reverse_proxy']);
        $checks = [
            'ip' => $this->is_ip_banned($ip),
            'host' => $this->is_host_banned($ip),
            'referer' => $this->is_referer_banned(),
            'agent' => $this->is_agent_banned()
        ];
        
        foreach ($checks as $type => $banned) {
            if ($banned && !$this->is_whitelisted($ip)) {
                $this->logger->log($ip, 'banned', $type . '_ban');
                $this->show_ban_message();
            }
        }
    }
    
    private function is_ip_banned($ip) {
        // Check exact IPs and wildcards
        $banned_ips = $this->cache->get('banned_ips_compiled', function() {
            $patterns = [];
            foreach ($this->settings['banned_ips'] as $pattern) {
                $patterns[] = $this->compile_wildcard_pattern($pattern);
            }
            return $patterns;
        });
        
        foreach ($banned_ips as $pattern) {
            if (preg_match($pattern, $ip)) {
                return true;
            }
        }
        
        // Check IP ranges
        foreach ($this->settings['banned_ranges'] as $range) {
            if ($this->ip_in_range($ip, $range)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function is_host_banned($ip) {
        if (empty($this->settings['banned_hosts'])) {
            return false;
        }
        
        $hostname = $this->cache->get('hostname_' . $ip, function() use ($ip) {
            return gethostbyaddr($ip);
        }, 3600); // Cache for 1 hour
        
        foreach ($this->settings['banned_hosts'] as $pattern) {
            if (wpban_match_wildcard($pattern, $hostname)) {
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
            if (wpban_match_wildcard($pattern, $referer)) {
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
            if (wpban_match_wildcard($pattern, $agent)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function is_whitelisted($ip) {
        foreach ($this->settings['whitelist_ips'] as $pattern) {
            if (wpban_match_wildcard($pattern, $ip) || $this->ip_in_range($ip, $pattern)) {
                return true;
            }
        }
        return false;
    }
    
    public function check_login_restriction() {
        if ($this->bypass_cookie || empty($this->settings['login_allowed_ips'])) {
            return;
        }
        
        $login_files = ['wp-login.php', 'wp-register.php'];
        $current_file = basename($_SERVER['SCRIPT_FILENAME']);
        
        if (in_array($current_file, $login_files)) {
            $ip = wpban_get_ip($this->settings['reverse_proxy']);
            $allowed = false;
            
            foreach ($this->settings['login_allowed_ips'] as $pattern) {
                if (wpban_match_wildcard($pattern, $ip) || $this->ip_in_range($ip, $pattern)) {
                    $allowed = true;
                    break;
                }
            }
            
            if (!$allowed) {
                $this->logger->log($ip, 'blocked', 'login_restriction');
                wp_redirect(home_url());
                exit;
            }
        }
    }
    
    public function check_browser_restrictions() {
        if ($this->bypass_cookie || empty($this->settings['browser_restrictions'])) {
            return;
        }
        
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // WeChat/QQ browser check
        if (isset($this->settings['browser_restrictions']['wechat_qq']) && 
            $this->settings['browser_restrictions']['wechat_qq']['enabled'] &&
            (strpos($ua, 'MQQBrowser') !== false || strpos($ua, 'MicroMessenger') !== false)) {
            
            $this->logger->log(wpban_get_ip($this->settings['reverse_proxy']), 'blocked', 'browser_restriction');
            $this->show_browser_block_message($this->settings['browser_restrictions']['wechat_qq']);
        }
    }
    
    public function check_crawlers() {
        if ($this->bypass_cookie || empty($this->settings['blocked_crawlers'])) {
            return;
        }
        
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = wpban_get_ip($this->settings['reverse_proxy']);
        
        foreach ($this->settings['blocked_crawlers'] as $crawler) {
            if (stripos($ua, $crawler) !== false) {
                $this->logger->log($ip, 'blocked', 'crawler_block', $crawler);
                http_response_code(403);
                exit('Access denied for crawlers');
            }
        }
    }
    
    public function modify_robots_txt($output, $public) {
        if (!empty($this->settings['blocked_crawlers'])) {
            $output .= "\n# wpban Rules\n";
            foreach ($this->settings['blocked_crawlers'] as $crawler) {
                $output .= "User-agent: $crawler\n";
                $output .= "Disallow: /\n\n";
            }
        }
        return $output;
    }
    
    private function should_check_crawlers() {
        // Performance optimization: only check if crawlers are configured
        return !empty($this->settings['blocked_crawlers']);
    }
    
    private function compile_wildcard_pattern($pattern) {
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*', '.*', $pattern);
        return '/^' . $pattern . '$/i';
    }
    
    private function ip_in_range($ip, $range) {
        if (strpos($range, '/') !== false) {
            // CIDR notation
            list($subnet, $bits) = explode('/', $range);
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            return ($ip_long & $mask) == ($subnet_long & $mask);
        } elseif (strpos($range, '-') !== false) {
            // Range notation
            list($start, $end) = explode('-', $range);
            $ip_long = ip2long($ip);
            return ($ip_long >= ip2long(trim($start)) && $ip_long <= ip2long(trim($end)));
        }
        return false;
    }
    
    private function show_ban_message() {
        $message = $this->settings['ban_message'];
        $message = str_replace(
            ['%IP%', '%DATE%', '%SITE%'],
            [wpban_get_ip($this->settings['reverse_proxy']), date('Y-m-d H:i:s'), get_bloginfo('name')],
            $message
        );
        
        wp_die($message, 'Access Denied', ['response' => 403]);
    }
    
    private function show_browser_block_message($settings) {
        ?>
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); display: flex; justify-content: center; align-items: center; z-index: 999999;">
            <div style="background: white; padding: 30px; border-radius: 10px; max-width: 500px; text-align: center;">
                <h2><?php echo esc_html($settings['title']); ?></h2>
                <p><?php echo esc_html($settings['message']); ?></p>
                <button onclick="navigator.clipboard.writeText(window.location.href).then(() => alert('Link copied!'));" style="padding: 10px 20px; background: #2271b1; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    <?php echo esc_html($settings['button_text']); ?>
                </button>
            </div>
        </div>
        <script>document.body.style.overflow = 'hidden';</script>
        <?php
    }
}