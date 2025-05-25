<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get client IP address with proxy support
 */
function wpban_get_ip($use_proxy = false) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    if ($use_proxy) {
        $proxy_headers = [
            'HTTP_CF_CONNECTING_IP',      // Cloudflare
            'HTTP_X_FORWARDED_FOR',        // Standard proxy
            'HTTP_X_REAL_IP',              // Nginx proxy
            'HTTP_CLIENT_IP',              // Some proxies
            'HTTP_X_FORWARDED',            // Some proxies
            'HTTP_X_CLUSTER_CLIENT_IP',    // Some proxies
            'HTTP_FORWARDED_FOR',          // Some proxies
            'HTTP_FORWARDED'               // RFC 7239
        ];
        
        foreach ($proxy_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    break;
                }
            }
        }
    }
    
    // Final validation
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * Match pattern with wildcard support
 */
function wpban_match_pattern($pattern, $string) {
    // Convert wildcard pattern to regex
    $pattern = str_replace(
        ['*', '?', '[', ']', '(', ')', '{', '}', '^', '$', '+', '.', '\\'],
        ['.*', '.', '\[', '\]', '\(', '\)', '\{', '\}', '\^', '\$', '\+', '\.', '\\\\'],
        $pattern
    );
    
    return preg_match('/^' . $pattern . '$/i', $string);
}

/**
 * Check if IP is in range (CIDR or range notation)
 */
function wpban_ip_in_range($ip, $range) {
    if (strpos($range, '/') !== false) {
        // CIDR notation
        list($subnet, $bits) = explode('/', $range);
        if ($bits < 0 || $bits > 32) {
            return false;
        }
        
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        
        if ($ip_long === false || $subnet_long === false) {
            return false;
        }
        
        $mask = -1 << (32 - $bits);
        return ($ip_long & $mask) == ($subnet_long & $mask);
        
    } elseif (strpos($range, '-') !== false) {
        // Range notation (192.168.1.1-192.168.1.255)
        list($start, $end) = explode('-', $range);
        $ip_long = ip2long($ip);
        $start_long = ip2long(trim($start));
        $end_long = ip2long(trim($end));
        
        if ($ip_long === false || $start_long === false || $end_long === false) {
            return false;
        }
        
        return ($ip_long >= $start_long && $ip_long <= $end_long);
    }
    
    // Check if it's a pattern
    return wpban_match_pattern($range, $ip);
}

/**
 * Get crawler list
 */
function wpban_get_crawler_list() {
    return [
        'ai' => [
            // OpenAI
            'GPTBot' => ['description' => __('OpenAI GPT Web Crawler', 'wpban')],
            'ChatGPT-User' => ['description' => __('ChatGPT Browser Tool', 'wpban')],
            'OAI-SearchBot' => ['description' => __('OpenAI Search Bot', 'wpban')],
            
            // Anthropic
            'ClaudeBot' => ['description' => __('Anthropic Claude Bot', 'wpban')],
            'Claude-Web' => ['description' => __('Claude Web Browser', 'wpban')],
            'anthropic-ai' => ['description' => __('Anthropic AI Crawler', 'wpban')],
            
            // Google
            'Google-Extended' => ['description' => __('Google Bard/Gemini Bot', 'wpban')],
            
            // Microsoft
            'Bingbot' => ['description' => __('Microsoft Bing AI', 'wpban')],
            
            // Meta
            'FacebookBot' => ['description' => __('Meta/Facebook Bot', 'wpban')],
            'Meta-ExternalAgent' => ['description' => __('Meta External Agent', 'wpban')],
            'Meta-ExternalFetcher' => ['description' => __('Meta External Fetcher', 'wpban')],
            
            // Others
            'CCBot' => ['description' => __('Common Crawl Dataset', 'wpban')],
            'PerplexityBot' => ['description' => __('Perplexity AI Search', 'wpban')],
            'YouBot' => ['description' => __('You.com Search Bot', 'wpban')],
            'Bytespider' => ['description' => __('ByteDance Spider', 'wpban')],
            'cohere-ai' => ['description' => __('Cohere AI Bot', 'wpban')],
            'Diffbot' => ['description' => __('Diffbot Crawler', 'wpban')],
            'Amazonbot' => ['description' => __('Amazon Alexa Bot', 'wpban')],
            'Applebot-Extended' => ['description' => __('Apple AI Bot', 'wpban')],
            'AI2Bot' => ['description' => __('Allen Institute AI', 'wpban')],
            'Ai2Bot-Dolma' => ['description' => __('AI2 Dolma Dataset', 'wpban')],
            'Omgilibot' => ['description' => __('Webz.io Bot', 'wpban')],
            'webzio' => ['description' => __('Webz.io Crawler', 'wpban')],
            'ImagesiftBot' => ['description' => __('Image Analysis Bot', 'wpban')],
            'PetalBot' => ['description' => __('Huawei Petal Search', 'wpban')],
        ],
        'seo' => [
            // Major Search Engines
            'Googlebot' => ['description' => __('Google Search (DO NOT BLOCK)', 'wpban')],
            'Bingbot' => ['description' => __('Bing Search (DO NOT BLOCK)', 'wpban')],
            'Slurp' => ['description' => __('Yahoo Search', 'wpban')],
            'DuckDuckBot' => ['description' => __('DuckDuckGo Search', 'wpban')],
            'Baiduspider' => ['description' => __('Baidu Search (China)', 'wpban')],
            'YandexBot' => ['description' => __('Yandex Search (Russia)', 'wpban')],
            
            // SEO Tools
            'AhrefsBot' => ['description' => __('Ahrefs SEO Tool', 'wpban')],
            'SemrushBot' => ['description' => __('Semrush SEO Tool', 'wpban')],
            'MJ12bot' => ['description' => __('Majestic SEO Tool', 'wpban')],
            'DotBot' => ['description' => __('Moz SEO Tool', 'wpban')],
            'Screaming Frog' => ['description' => __('SEO Spider Tool', 'wpban')],
            
            // Other
            'ia_archiver' => ['description' => __('Internet Archive', 'wpban')],
            'facebookexternalhit' => ['description' => __('Facebook Link Preview', 'wpban')],
            'LinkedInBot' => ['description' => __('LinkedIn Preview', 'wpban')],
            'WhatsApp' => ['description' => __('WhatsApp Link Preview', 'wpban')],
            'Twitterbot' => ['description' => __('Twitter Link Preview', 'wpban')],
        ]
    ];
}

/**
 * Get country list
 */
function wpban_get_country_list() {
    return [
        'AF' => __('Afghanistan', 'wpban'),
        'AL' => __('Albania', 'wpban'),
        'DZ' => __('Algeria', 'wpban'),
        'AR' => __('Argentina', 'wpban'),
        'AM' => __('Armenia', 'wpban'),
        'AU' => __('Australia', 'wpban'),
        'AT' => __('Austria', 'wpban'),
        'AZ' => __('Azerbaijan', 'wpban'),
        'BD' => __('Bangladesh', 'wpban'),
        'BY' => __('Belarus', 'wpban'),
        'BE' => __('Belgium', 'wpban'),
        'BR' => __('Brazil', 'wpban'),
        'BG' => __('Bulgaria', 'wpban'),
        'CA' => __('Canada', 'wpban'),
        'CL' => __('Chile', 'wpban'),
        'CN' => __('China', 'wpban'),
        'CO' => __('Colombia', 'wpban'),
        'HR' => __('Croatia', 'wpban'),
        'CZ' => __('Czech Republic', 'wpban'),
        'DK' => __('Denmark', 'wpban'),
        'EG' => __('Egypt', 'wpban'),
        'EE' => __('Estonia', 'wpban'),
        'FI' => __('Finland', 'wpban'),
        'FR' => __('France', 'wpban'),
        'GE' => __('Georgia', 'wpban'),
        'DE' => __('Germany', 'wpban'),
        'GR' => __('Greece', 'wpban'),
        'HK' => __('Hong Kong', 'wpban'),
        'HU' => __('Hungary', 'wpban'),
        'IS' => __('Iceland', 'wpban'),
        'IN' => __('India', 'wpban'),
        'ID' => __('Indonesia', 'wpban'),
        'IR' => __('Iran', 'wpban'),
        'IQ' => __('Iraq', 'wpban'),
        'IE' => __('Ireland', 'wpban'),
        'IL' => __('Israel', 'wpban'),
        'IT' => __('Italy', 'wpban'),
        'JP' => __('Japan', 'wpban'),
        'KZ' => __('Kazakhstan', 'wpban'),
        'KE' => __('Kenya', 'wpban'),
        'KR' => __('South Korea', 'wpban'),
        'LV' => __('Latvia', 'wpban'),
        'LT' => __('Lithuania', 'wpban'),
        'MY' => __('Malaysia', 'wpban'),
        'MX' => __('Mexico', 'wpban'),
        'MA' => __('Morocco', 'wpban'),
        'NL' => __('Netherlands', 'wpban'),
        'NZ' => __('New Zealand', 'wpban'),
        'NG' => __('Nigeria', 'wpban'),
        'NO' => __('Norway', 'wpban'),
        'PK' => __('Pakistan', 'wpban'),
        'PE' => __('Peru', 'wpban'),
        'PH' => __('Philippines', 'wpban'),
        'PL' => __('Poland', 'wpban'),
        'PT' => __('Portugal', 'wpban'),
        'RO' => __('Romania', 'wpban'),
        'RU' => __('Russia', 'wpban'),
        'SA' => __('Saudi Arabia', 'wpban'),
        'RS' => __('Serbia', 'wpban'),
        'SG' => __('Singapore', 'wpban'),
        'SK' => __('Slovakia', 'wpban'),
        'SI' => __('Slovenia', 'wpban'),
        'ZA' => __('South Africa', 'wpban'),
        'ES' => __('Spain', 'wpban'),
        'SE' => __('Sweden', 'wpban'),
        'CH' => __('Switzerland', 'wpban'),
        'TW' => __('Taiwan', 'wpban'),
        'TH' => __('Thailand', 'wpban'),
        'TR' => __('Turkey', 'wpban'),
        'UA' => __('Ukraine', 'wpban'),
        'AE' => __('United Arab Emirates', 'wpban'),
        'GB' => __('United Kingdom', 'wpban'),
        'US' => __('United States', 'wpban'),
        'UZ' => __('Uzbekistan', 'wpban'),
        'VE' => __('Venezuela', 'wpban'),
        'VN' => __('Vietnam', 'wpban'),
    ];
}

/**
 * Sanitize and validate IP address
 */
function wpban_sanitize_ip($ip) {
    // Remove any whitespace
    $ip = trim($ip);
    
    // Check if it's a valid IP
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    
    // Check if it's a pattern with wildcards
    if (strpos($ip, '*') !== false) {
        // Replace wildcards with 0 for validation
        $test_ip = str_replace('*', '0', $ip);
        if (filter_var($test_ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    
    return false;
}

/**
 * Format bytes to human readable
 */
function wpban_format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Get user's country flag emoji
 */
function wpban_country_flag($country_code) {
    if (strlen($country_code) !== 2) {
        return '';
    }
    
    $country_code = strtoupper($country_code);
    $flag = '';
    
    // Convert country code to flag emoji
    for ($i = 0; $i < strlen($country_code); $i++) {
        $flag .= mb_chr(ord($country_code[$i]) - ord('A') + 0x1F1E6, 'UTF-8');
    }
    
    return $flag;
}

/**
 * Log security event (helper function)
 */
function wpban_log($action, $reason, $details = '') {
    if (isset($GLOBALS['wpban_security'])) {
        $GLOBALS['wpban_security']->log($action, $reason, $details);
    }
}

/**
 * Check if current user is whitelisted
 */
function wpban_is_current_user_whitelisted() {
    $settings = get_option('wpban_settings', []);
    $ip = wpban_get_ip($settings['reverse_proxy'] ?? false);
    
    foreach ($settings['whitelist_ips'] ?? [] as $pattern) {
        if (wpban_match_pattern($pattern, $ip) || wpban_ip_in_range($ip, $pattern)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get attack type label
 */
function wpban_get_attack_type_label($type) {
    $labels = [
        'ip_ban' => __('IP Ban', 'wpban'),
        'rate_limit' => __('Rate Limit', 'wpban'),
        'geo_block' => __('Geo Block', 'wpban'),
        'crawler_block' => __('Crawler Block', 'wpban'),
        'failed_login' => __('Failed Login', 'wpban'),
        'brute_force' => __('Brute Force', 'wpban'),
        'banned' => __('Banned', 'wpban'),
        'blocked' => __('Blocked', 'wpban'),
    ];
    
    return $labels[$type] ?? $type;
}

/**
 * Export settings
 */
function wpban_export_settings() {
    $settings = get_option('wpban_settings', []);
    $bypass_path = get_option('wpban_bypass_path', '');
    
    $export = [
        'version' => WPBAN_VERSION,
        'timestamp' => current_time('timestamp'),
        'site_url' => get_site_url(),
        'settings' => $settings,
        'bypass_path' => $bypass_path
    ];
    
    return json_encode($export, JSON_PRETTY_PRINT);
}

/**
 * Import settings
 */
function wpban_import_settings($json) {
    $data = json_decode($json, true);
    
    if (!$data || !isset($data['settings'])) {
        return false;
    }
    
    // Validate version compatibility
    if (version_compare($data['version'], '4.0', '<')) {
        return false;
    }
    
    // Import settings
    update_option('wpban_settings', $data['settings']);
    
    // Optionally import bypass path
    if (!empty($data['bypass_path'])) {
        update_option('wpban_bypass_path', $data['bypass_path']);
    }
    
    // Clear cache
    if (isset($GLOBALS['wpban_security'])) {
        $GLOBALS['wpban_security']->clear_cache();
    }
    
    return true;
}