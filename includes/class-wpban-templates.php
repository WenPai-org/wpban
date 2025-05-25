<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBan_Templates {
    private $templates;
    
    public function __construct() {
        $this->init_templates();
    }
    
    private function init_templates() {
        $this->templates = [
            'basic' => [
                'name' => __('Basic Protection', 'wpban'),
                'description' => __('Essential security for most WordPress sites', 'wpban'),
                'settings' => [
                    'banned_agents' => ['*bot*', '*crawler*', '*spider*'],
                    'blocked_crawlers' => [],
                    'enable_logging' => true,
                    'enable_caching' => true,
                    'ban_message' => '<h1>Access Denied</h1><p>Your access to this site has been restricted.</p>'
                ]
            ],
            'strict' => [
                'name' => __('Strict Security', 'wpban'),
                'description' => __('Maximum protection with login restrictions', 'wpban'),
                'settings' => [
                    'banned_agents' => ['*bot*', '*crawler*', '*spider*', '*scraper*', 'curl*', 'wget*'],
                    'blocked_crawlers' => ['GPTBot', 'ChatGPT-User', 'ClaudeBot', 'CCBot', 'PerplexityBot', 'Bytespider'],
                    'browser_restrictions' => [
                        'wechat_qq' => [
                            'enabled' => true,
                            'title' => __('Browser Not Supported', 'wpban'),
                            'message' => __('Please use Chrome, Firefox, or Safari to access this site.', 'wpban'),
                            'button_text' => __('Copy Link', 'wpban')
                        ]
                    ],
                    'enable_logging' => true,
                    'enable_caching' => true
                ]
            ],
            'content' => [
                'name' => __('Content Protection', 'wpban'),
                'description' => __('Protect original content from AI crawlers', 'wpban'),
                'settings' => [
                    'blocked_crawlers' => [
                        'GPTBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-Web', 'anthropic-ai',
                        'CCBot', 'PerplexityBot', 'Bytespider', 'FacebookBot', 'Meta-ExternalAgent',
                        'cohere-ai', 'AI2Bot', 'Ai2Bot-Dolma', 'Google-Extended', 'ImagesiftBot'
                    ],
                    'banned_agents' => ['*GPT*', '*Claude*', '*AI*Bot*'],
                    'enable_logging' => true,
                    'enable_caching' => true
                ]
            ],
            'membership' => [
                'name' => __('Membership Site', 'wpban'),
                'description' => __('Ideal for private or membership websites', 'wpban'),
                'settings' => [
                    'banned_agents' => ['*bot*', '*crawler*', '*spider*', '*scraper*'],
                    'blocked_crawlers' => array_merge(
                        wpban_get_crawler_list()['ai'],
                        wpban_get_crawler_list()['seo']
                    ),
                    'banned_referers' => ['*google.*', '*bing.*', '*yahoo.*', '*baidu.*'],
                    'enable_logging' => true,
                    'enable_caching' => true
                ]
            ],
            'development' => [
                'name' => __('Development Mode', 'wpban'),
                'description' => __('Minimal restrictions for development sites', 'wpban'),
                'settings' => [
                    'enable_logging' => true,
                    'enable_caching' => false,
                    'ban_message' => '<h1>Site Under Development</h1><p>This site is currently under development.</p>'
                ]
            ]
        ];
    }
    
    public function get_all_templates() {
        return $this->templates;
    }
    
    public function get_template($id) {
        return isset($this->templates[$id]) ? $this->templates[$id] : null;
    }
    
    public function apply_template($template_id) {
        $template = $this->get_template($template_id);
        if (!$template) {
            return false;
        }
        
        // Get current settings
        $current_settings = get_option('wpban_settings', []);
        
        // Merge template settings with defaults
        $new_settings = array_merge(
            [
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
            ],
            $template['settings']
        );
        
        // Preserve some user settings
        $preserve_fields = ['whitelist_ips', 'reverse_proxy'];
        foreach ($preserve_fields as $field) {
            if (isset($current_settings[$field])) {
                $new_settings[$field] = $current_settings[$field];
            }
        }
        
        return $new_settings;
    }
}