<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBan_Admin {
    private $security;
    
    public function __construct() {
        $this->security = $GLOBALS['wpban_security'];
        
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // AJAX handlers
        add_action('wp_ajax_wpban_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_wpban_apply_template', [$this, 'ajax_apply_template']);
        add_action('wp_ajax_wpban_get_logs', [$this, 'ajax_get_logs']);
        add_action('wp_ajax_wpban_export_logs', [$this, 'ajax_export_logs']);
        add_action('wp_ajax_wpban_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_wpban_test_email', [$this, 'ajax_test_email']);
        add_action('wp_ajax_wpban_get_country_stats', [$this, 'ajax_get_country_stats']);
    }
    
    public function add_menu() {
        add_menu_page(
            __('WPBan Security', 'wpban'),
            __('WPBan Security', 'wpban'),
            'manage_options',
            'wpban',
            [$this, 'render_dashboard'],
            'dashicons-shield',
            30
        );
        
        add_submenu_page(
            'wpban',
            __('Settings', 'wpban'),
            __('Settings', 'wpban'),
            'manage_options',
            'wpban-settings',
            [$this, 'render_settings']
        );
        
        add_submenu_page(
            'wpban',
            __('Security Logs', 'wpban'),
            __('Logs', 'wpban'),
            'manage_options',
            'wpban-logs',
            [$this, 'render_logs']
        );
        
        add_submenu_page(
            'wpban',
            __('Tools', 'wpban'),
            __('Tools', 'wpban'),
            'manage_options',
            'wpban-tools',
            [$this, 'render_tools']
        );
    }
    
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'wpban') === false) {
            return;
        }
        
        wp_enqueue_style('wpban-admin', WPBAN_URL . 'assets/admin.css', [], WPBAN_VERSION);
        wp_enqueue_script('wpban-admin', WPBAN_URL . 'assets/admin.js', ['jquery'], WPBAN_VERSION);
        wp_localize_script('wpban-admin', 'wpban', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpban_admin'),
            'i18n' => [
                'confirm_clear' => __('Are you sure you want to clear all logs?', 'wpban'),
                'confirm_template' => __('This will replace your current settings. Continue?', 'wpban'),
                'email_sent' => __('Test email sent!', 'wpban'),
                'error' => __('An error occurred', 'wpban')
            ]
        ]);
        
        // Add Chart.js for statistics
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', [], '3.9.1');
    }
    
    public function render_dashboard() {
        $stats = $this->security->get_stats();
        $bypass_url = home_url('/?wpban_bypass=' . get_option('wpban_bypass_path'));
        ?>
        <div class="wrap wpban-wrap">
            <h1><?php _e('WPBan Security Dashboard', 'wpban'); ?></h1>
            
            <div class="wpban-dashboard">
                <!-- Stats Grid -->
                <div class="wpban-stats-grid">
                    <div class="wpban-stat-card">
                        <h3><?php _e('Total Blocks', 'wpban'); ?></h3>
                        <div class="wpban-stat-number"><?php echo number_format($stats['total_blocks']); ?></div>
                        <div class="wpban-stat-trend">
                            <?php
                            $yesterday = $stats['total_blocks'] - $stats['today_blocks'];
                            $trend = $yesterday > 0 ? round(($stats['today_blocks'] / $yesterday - 1) * 100) : 0;
                            ?>
                            <span class="<?php echo $trend > 0 ? 'up' : 'down'; ?>">
                                <?php echo $trend > 0 ? '↑' : '↓'; ?> <?php echo abs($trend); ?>%
                            </span>
                        </div>
                    </div>
                    
                    <div class="wpban-stat-card">
                        <h3><?php _e('Unique IPs', 'wpban'); ?></h3>
                        <div class="wpban-stat-number"><?php echo number_format($stats['unique_ips']); ?></div>
                    </div>
                    
                    <div class="wpban-stat-card">
                        <h3><?php _e('Today\'s Blocks', 'wpban'); ?></h3>
                        <div class="wpban-stat-number"><?php echo number_format($stats['today_blocks']); ?></div>
                    </div>
                    
                    <div class="wpban-stat-card">
                        <h3><?php _e('Active Rules', 'wpban'); ?></h3>
                        <div class="wpban-stat-number"><?php echo number_format($stats['active_rules']); ?></div>
                    </div>
                </div>
                
                <div class="wpban-grid">
                    <!-- Quick Actions -->
                    <div class="wpban-card">
                        <h2><?php _e('Quick Actions', 'wpban'); ?></h2>
                        <div class="wpban-bypass-url">
                            <label><?php _e('Emergency Bypass URL:', 'wpban'); ?></label>
                            <div class="wpban-input-group">
                                <input type="text" value="<?php echo esc_attr($bypass_url); ?>" readonly />
                                <button class="button" onclick="wpbanCopyText(this.previousElementSibling.value)">
                                    <?php _e('Copy', 'wpban'); ?>
                                </button>
                            </div>
                            <p class="description"><?php _e('Save this URL to access your site if you get locked out.', 'wpban'); ?></p>
                        </div>
                        
                        <div class="wpban-actions">
                            <a href="<?php echo admin_url('admin.php?page=wpban-settings'); ?>" class="button button-primary">
                                <?php _e('Configure Settings', 'wpban'); ?>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=wpban-logs'); ?>" class="button">
                                <?php _e('View Logs', 'wpban'); ?>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Country Stats -->
                    <div class="wpban-card">
                        <h2><?php _e('Top Blocked Countries', 'wpban'); ?></h2>
                        <canvas id="wpban-country-chart" height="200"></canvas>
                        <script>
                        jQuery(document).ready(function($) {
                            const ctx = document.getElementById('wpban-country-chart').getContext('2d');
                            const data = <?php echo json_encode($stats['top_countries']); ?>;
                            
                            new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: data.map(d => d.country_code || 'Unknown'),
                                    datasets: [{
                                        label: 'Blocks',
                                        data: data.map(d => d.count),
                                        backgroundColor: '#2271b1'
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: { display: false }
                                    }
                                }
                            });
                        });
                        </script>
                    </div>
                </div>
                
                <!-- Templates -->
                <div class="wpban-card">
                    <h2><?php _e('Security Templates', 'wpban'); ?></h2>
                    <p><?php _e('Quickly apply pre-configured security settings.', 'wpban'); ?></p>
                    <div class="wpban-templates-grid">
                        <?php foreach ($this->security->get_templates() as $id => $template): ?>
                        <div class="wpban-template-card">
                            <h3><?php echo esc_html($template['name']); ?></h3>
                            <p><?php echo esc_html($template['description']); ?></p>
                            <button class="button" onclick="wpbanApplyTemplate('<?php echo esc_attr($id); ?>')">
                                <?php _e('Apply', 'wpban'); ?>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="wpban-card">
                    <h2><?php _e('Recent Activity', 'wpban'); ?></h2>
                    <table class="wp-list-table widefat">
                        <thead>
                            <tr>
                                <th><?php _e('Time', 'wpban'); ?></th>
                                <th><?php _e('IP', 'wpban'); ?></th>
                                <th><?php _e('Country', 'wpban'); ?></th>
                                <th><?php _e('Action', 'wpban'); ?></th>
                                <th><?php _e('Details', 'wpban'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['recent_blocks'] as $log): ?>
                            <tr>
                                <td><?php echo human_time_diff(strtotime($log->timestamp)); ?> ago</td>
                                <td><code><?php echo esc_html($log->ip); ?></code></td>
                                <td><?php echo esc_html($log->country_code ?: '-'); ?></td>
                                <td><span class="wpban-badge wpban-badge-<?php echo esc_attr($log->action); ?>">
                                    <?php echo esc_html($log->action); ?></span></td>
                                <td><?php echo esc_html($log->reason); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function render_settings() {
        $settings = get_option('wpban_settings', []);
        $countries = wpban_get_country_list();
        ?>
        <div class="wrap wpban-wrap">
            <h1><?php _e('WPBan Security Settings', 'wpban'); ?></h1>
            
            <form id="wpban-settings-form" method="post">
                <?php wp_nonce_field('wpban_settings', 'wpban_nonce'); ?>
                
                <div class="wpban-tabs">
                    <nav class="nav-tab-wrapper">
                        <a href="#general" class="nav-tab nav-tab-active"><?php _e('General', 'wpban'); ?></a>
                        <a href="#ip-rules" class="nav-tab"><?php _e('IP Rules', 'wpban'); ?></a>
                        <a href="#advanced" class="nav-tab"><?php _e('Advanced', 'wpban'); ?></a>
                        <a href="#rate-limit" class="nav-tab"><?php _e('Rate Limiting', 'wpban'); ?></a>
                        <a href="#geo-block" class="nav-tab"><?php _e('Geo Blocking', 'wpban'); ?></a>
                        <a href="#crawlers" class="nav-tab"><?php _e('Crawlers', 'wpban'); ?></a>
                        <a href="#notifications" class="nav-tab"><?php _e('Notifications', 'wpban'); ?></a>
                    </nav>
                    
                    <!-- General Tab -->
                    <div id="general" class="tab-content active">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Logging', 'wpban'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="settings[enable_logging]" value="1" 
                                               <?php checked($settings['enable_logging'] ?? true); ?> />
                                        <?php _e('Log all security events', 'wpban'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Reverse Proxy', 'wpban'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="settings[reverse_proxy]" value="1" 
                                               <?php checked($settings['reverse_proxy'] ?? false); ?> />
                                        <?php _e('Server is behind a reverse proxy (Cloudflare, nginx, etc.)', 'wpban'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Ban Message', 'wpban'); ?></th>
                                <td>
                                    <?php
                                    wp_editor(
                                        $settings['ban_message'] ?? '<h1>Access Denied</h1><p>Your access has been restricted.</p>',
                                        'ban_message',
                                        [
                                            'textarea_name' => 'settings[ban_message]',
                                            'textarea_rows' => 10,
                                            'media_buttons' => false
                                        ]
                                    );
                                    ?>
                                    <p class="description"><?php _e('Variables: %IP%, %DATE%, %SITE%', 'wpban'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- IP Rules Tab -->
                    <div id="ip-rules" class="tab-content">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Banned IPs', 'wpban'); ?></th>
                                <td>
                                    <textarea name="settings[banned_ips]" rows="10" class="large-text code"><?php 
                                        echo esc_textarea(implode("\n", $settings['banned_ips'] ?? [])); 
                                    ?></textarea>
                                    <p class="description"><?php _e('One per line. Use * for wildcards (e.g., 192.168.*.*)', 'wpban'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('IP Ranges', 'wpban'); ?></th>
                                <td>
                                    <textarea name="settings[banned_ranges]" rows="5" class="large-text code"><?php 
                                        echo esc_textarea(implode("\n", $settings['banned_ranges'] ?? [])); 
                                    ?></textarea>
                                    <p class="description"><?php _e('CIDR: 192.168.0.0/24 or Range: 192.168.0.1-192.168.0.255', 'wpban'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Whitelist IPs', 'wpban'); ?></th>
                                <td>
                                    <textarea name="settings[whitelist_ips]" rows="5" class="large-text code"><?php 
                                        echo esc_textarea(implode("\n", $settings['whitelist_ips'] ?? [])); 
                                    ?></textarea>
                                    <p class="description"><?php _e('These IPs will never be blocked', 'wpban'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Login Protection', 'wpban'); ?></th>
                                <td>
                                    <textarea name="settings[login_allowed_ips]" rows="5" class="large-text code"><?php 
                                        echo esc_textarea(implode("\n", $settings['login_allowed_ips'] ?? [])); 
                                    ?></textarea>
                                    <p class="description"><?php _e('Only these IPs can access wp-login.php (leave empty to allow all)', 'wpban'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Advanced Tab -->
                    <div id="advanced" class="tab-content">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Banned Hosts', 'wpban'); ?></th>
                                <td>
                                    <textarea name="settings[banned_hosts]" rows="5" class="large-text code"><?php 
                                        echo esc_textarea(implode("\n", $settings['banned_hosts'] ?? [])); 
                                    ?></textarea>
                                    <p class="description"><?php _e('e.g., *.badhost.com', 'wpban'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Banned Referers', 'wpban'); ?></th>
                                <td>
                                    <textarea name="settings[banned_referers]" rows="5" class="large-text code"><?php 
                                        echo esc_textarea(implode("\n", $settings['banned_referers'] ?? [])); 
                                    ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Banned User Agents', 'wpban'); ?></th>
                                <td>
                                    <textarea name="settings[banned_agents]" rows="5" class="large-text code"><?php 
                                        echo esc_textarea(implode("\n", $settings['banned_agents'] ?? [])); 
                                    ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Browser Restrictions', 'wpban'); ?></th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="checkbox" name="settings[browser_restrictions][wechat_qq][enabled]" value="1"
                                                   <?php checked($settings['browser_restrictions']['wechat_qq']['enabled'] ?? false); ?> />
                                            <?php _e('Block WeChat/QQ Browsers', 'wpban'); ?>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Rate Limiting Tab -->
                    <div id="rate-limit" class="tab-content">
                        <h2><?php _e('Rate Limiting Settings', 'wpban'); ?></h2>
                        <p><?php _e('Protect against floods and brute force attacks by limiting request rates.', 'wpban'); ?></p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('General Requests', 'wpban'); ?></th>
                                <td>
                                    <input type="number" name="settings[rate_limits][requests_per_minute]" 
                                           value="<?php echo esc_attr($settings['rate_limits']['requests_per_minute'] ?? 60); ?>" 
                                           min="10" max="1000" />
                                    <span class="description"><?php _e('requests per minute', 'wpban'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Login Attempts', 'wpban'); ?></th>
                                <td>
                                    <input type="number" name="settings[rate_limits][login_per_hour]" 
                                           value="<?php echo esc_attr($settings['rate_limits']['login_per_hour'] ?? 5); ?>" 
                                           min="1" max="50" />
                                    <span class="description"><?php _e('attempts per hour', 'wpban'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('API Requests', 'wpban'); ?></th>
                                <td>
                                    <input type="number" name="settings[rate_limits][api_per_minute]" 
                                           value="<?php echo esc_attr($settings['rate_limits']['api_per_minute'] ?? 30); ?>" 
                                           min="5" max="500" />
                                    <span class="description"><?php _e('requests per minute', 'wpban'); ?></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Geo Blocking Tab -->
                    <div id="geo-block" class="tab-content">
                        <h2><?php _e('Geographic Blocking', 'wpban'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Geo Blocking', 'wpban'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="settings[geo_blocking][enabled]" value="1" 
                                               <?php checked($settings['geo_blocking']['enabled'] ?? false); ?> />
                                        <?php _e('Block or allow access based on country', 'wpban'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Block Mode', 'wpban'); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="settings[geo_blocking][mode]" value="blacklist" 
                                               <?php checked(($settings['geo_blocking']['mode'] ?? 'blacklist'), 'blacklist'); ?> />
                                        <?php _e('Block selected countries', 'wpban'); ?>
                                    </label><br>
                                    <label>
                                        <input type="radio" name="settings[geo_blocking][mode]" value="whitelist" 
                                               <?php checked(($settings['geo_blocking']['mode'] ?? 'blacklist'), 'whitelist'); ?> />
                                        <?php _e('Allow only selected countries', 'wpban'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Countries', 'wpban'); ?></th>
                                <td>
                                    <div class="wpban-country-selector">
                                        <select multiple name="settings[geo_blocking][countries][]" size="10" style="width: 100%; max-width: 400px;">
                                            <?php
                                            $selected = $settings['geo_blocking']['blocked_countries'] ?? [];
                                            foreach ($countries as $code => $name): ?>
                                                <option value="<?php echo esc_attr($code); ?>" 
                                                        <?php selected(in_array($code, $selected)); ?>>
                                                    <?php echo esc_html("$name ($code)"); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <p class="description"><?php _e('Hold Ctrl/Cmd to select multiple countries', 'wpban'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Crawlers Tab -->
                    <div id="crawlers" class="tab-content">
                        <div class="wpban-crawler-controls">
                            <button type="button" class="button" onclick="wpbanSelectCrawlers('ai', true)">
                                <?php _e('Select All AI', 'wpban'); ?>
                            </button>
                            <button type="button" class="button" onclick="wpbanSelectCrawlers('seo', true)">
                                <?php _e('Select All SEO', 'wpban'); ?>
                            </button>
                            <button type="button" class="button" onclick="wpbanSelectCrawlers('all', false)">
                                <?php _e('Deselect All', 'wpban'); ?>
                            </button>
                        </div>
                        
                        <?php
                        $crawlers = wpban_get_crawler_list();
                        $blocked = $settings['blocked_crawlers'] ?? [];
                        ?>
                        
                        <h3><?php _e('AI Crawlers', 'wpban'); ?></h3>
                        <div class="wpban-crawler-grid">
                            <?php foreach ($crawlers['ai'] as $crawler => $info): ?>
                            <label class="wpban-crawler-item" data-type="ai">
                                <input type="checkbox" name="settings[blocked_crawlers][]" 
                                       value="<?php echo esc_attr($crawler); ?>"
                                       <?php checked(in_array($crawler, $blocked)); ?> />
                                <span class="wpban-crawler-name"><?php echo esc_html($crawler); ?></span>
                                <span class="wpban-crawler-desc"><?php echo esc_html($info['description']); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        
                        <h3><?php _e('SEO Crawlers', 'wpban'); ?></h3>
                        <div class="wpban-notice wpban-notice-warning">
                            <p><?php _e('⚠️ Blocking SEO crawlers may affect your search engine rankings!', 'wpban'); ?></p>
                        </div>
                        <div class="wpban-crawler-grid">
                            <?php foreach ($crawlers['seo'] as $crawler => $info): ?>
                            <label class="wpban-crawler-item" data-type="seo">
                                <input type="checkbox" name="settings[blocked_crawlers][]" 
                                       value="<?php echo esc_attr($crawler); ?>"
                                       <?php checked(in_array($crawler, $blocked)); ?> />
                                <span class="wpban-crawler-name"><?php echo esc_html($crawler); ?></span>
                                <span class="wpban-crawler-desc"><?php echo esc_html($info['description']); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Notifications Tab -->
                    <div id="notifications" class="tab-content">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Email Notifications', 'wpban'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="settings[email_notifications][enabled]" value="1" 
                                               <?php checked($settings['email_notifications']['enabled'] ?? false); ?> />
                                        <?php _e('Send email alerts for security events', 'wpban'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Recipient Email', 'wpban'); ?></th>
                                <td>
                                    <input type="email" name="settings[email_notifications][recipient]" 
                                           value="<?php echo esc_attr($settings['email_notifications']['recipient'] ?? get_option('admin_email')); ?>" 
                                           class="regular-text" />
                                    <button type="button" class="button" onclick="wpbanTestEmail()">
                                        <?php _e('Send Test Email', 'wpban'); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Alert Threshold', 'wpban'); ?></th>
                                <td>
                                    <input type="number" name="settings[email_notifications][threshold]" 
                                           value="<?php echo esc_attr($settings['email_notifications']['threshold'] ?? 10); ?>" 
                                           min="1" max="100" />
                                    <span class="description"><?php _e('Send alert after this many blocks from same IP', 'wpban'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Alert Events', 'wpban'); ?></th>
                                <td>
                                    <?php
                                    $events = [
                                        'rate_limit' => __('Rate limit exceeded', 'wpban'),
                                        'geo_block' => __('Geographic block', 'wpban'),
                                        'brute_force' => __('Brute force attempt', 'wpban'),
                                        'crawler_block' => __('Crawler blocked', 'wpban')
                                    ];
                                    $selected_events = $settings['email_notifications']['events'] ?? ['rate_limit', 'brute_force'];
                                    
                                    foreach ($events as $event => $label): ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox" name="settings[email_notifications][events][]" 
                                                   value="<?php echo esc_attr($event); ?>"
                                                   <?php checked(in_array($event, $selected_events)); ?> />
                                            <?php echo esc_html($label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <p class="submit">
                    <button type="submit" class="button button-primary" id="wpban-save-settings">
                        <?php _e('Save Settings', 'wpban'); ?>
                    </button>
                    <span class="spinner"></span>
                </p>
            </form>
        </div>
        <?php
    }
    
    public function render_logs() {
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        
        $filters = [
            'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_GET['date_to'] ?? ''),
            'action' => sanitize_text_field($_GET['action_filter'] ?? ''),
            'ip' => sanitize_text_field($_GET['ip_filter'] ?? ''),
            'country' => sanitize_text_field($_GET['country_filter'] ?? '')
        ];
        
        $result = $this->security->get_logs($filters, $current_page, $per_page);
        ?>
        <div class="wrap wpban-wrap">
            <h1><?php _e('Security Logs', 'wpban'); ?></h1>
            
            <!-- Filters -->
            <div class="wpban-logs-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="wpban-logs" />
                    
                    <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" 
                           placeholder="<?php esc_attr_e('From date', 'wpban'); ?>" />
                    
                    <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" 
                           placeholder="<?php esc_attr_e('To date', 'wpban'); ?>" />
                    
                    <select name="action_filter">
                        <option value=""><?php _e('All Actions', 'wpban'); ?></option>
                        <option value="banned" <?php selected($filters['action'], 'banned'); ?>><?php _e('Banned', 'wpban'); ?></option>
                        <option value="blocked" <?php selected($filters['action'], 'blocked'); ?>><?php _e('Blocked', 'wpban'); ?></option>
                        <option value="failed_login" <?php selected($filters['action'], 'failed_login'); ?>><?php _e('Failed Login', 'wpban'); ?></option>
                        <option value="bypass" <?php selected($filters['action'], 'bypass'); ?>><?php _e('Bypass Used', 'wpban'); ?></option>
                    </select>
                    
                    <input type="text" name="ip_filter" value="<?php echo esc_attr($filters['ip']); ?>" 
                           placeholder="<?php esc_attr_e('IP Address', 'wpban'); ?>" />
                    
                    <input type="text" name="country_filter" value="<?php echo esc_attr($filters['country']); ?>" 
                           placeholder="<?php esc_attr_e('Country Code', 'wpban'); ?>" size="5" />
                    
                    <button type="submit" class="button"><?php _e('Filter', 'wpban'); ?></button>
                    <a href="<?php echo admin_url('admin.php?page=wpban-logs'); ?>" class="button"><?php _e('Reset', 'wpban'); ?></a>
                    
                    <button type="button" class="button" onclick="wpbanExportLogs()">
                        <?php _e('Export CSV', 'wpban'); ?>
                    </button>
                    
                    <button type="button" class="button" onclick="wpbanClearLogs()">
                        <?php _e('Clear Logs', 'wpban'); ?>
                    </button>
                </form>
            </div>
            
            <!-- Logs Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 150px;"><?php _e('Time', 'wpban'); ?></th>
                        <th style="width: 120px;"><?php _e('IP Address', 'wpban'); ?></th>
                        <th style="width: 60px;"><?php _e('Country', 'wpban'); ?></th>
                        <th style="width: 100px;"><?php _e('Action', 'wpban'); ?></th>
                        <th><?php _e('Details', 'wpban'); ?></th>
                        <th><?php _e('User Agent', 'wpban'); ?></th>
                        <th><?php _e('URI', 'wpban'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($result['logs'])): ?>
                    <tr>
                        <td colspan="7"><?php _e('No logs found.', 'wpban'); ?></td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($result['logs'] as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->timestamp); ?></td>
                            <td>
                                <code><?php echo esc_html($log->ip); ?></code>
                                <div class="row-actions">
                                    <a href="?page=wpban-logs&ip_filter=<?php echo urlencode($log->ip); ?>">
                                        <?php _e('Filter', 'wpban'); ?>
                                    </a>
                                </div>
                            </td>
                            <td><?php echo esc_html($log->country_code ?: '-'); ?></td>
                            <td>
                                <span class="wpban-badge wpban-badge-<?php echo esc_attr($log->action); ?>">
                                    <?php echo esc_html($log->action); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->reason); ?></td>
                            <td>
                                <span class="wpban-truncate" title="<?php echo esc_attr($log->user_agent); ?>">
                                    <?php echo esc_html(substr($log->user_agent, 0, 50)); ?>
                                </span>
                            </td>
                            <td>
                                <span class="wpban-truncate" title="<?php echo esc_attr($log->uri); ?>">
                                    <?php echo esc_html($log->uri); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($result['pages'] > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total' => $result['pages'],
                        'current' => $current_page
                    ]);
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function render_tools() {
        ?>
        <div class="wrap wpban-wrap">
            <h1><?php _e('WPBan Tools', 'wpban'); ?></h1>
            
            <div class="wpban-tools-grid">
                <!-- Import/Export -->
                <div class="wpban-card">
                    <h2><?php _e('Import/Export Settings', 'wpban'); ?></h2>
                    <p><?php _e('Backup your settings or migrate to another site.', 'wpban'); ?></p>
                    
                    <h3><?php _e('Export', 'wpban'); ?></h3>
                    <p>
                        <button class="button" onclick="wpbanExportSettings()">
                            <?php _e('Download Settings', 'wpban'); ?>
                        </button>
                    </p>
                    
                    <h3><?php _e('Import', 'wpban'); ?></h3>
                    <form method="post" enctype="multipart/form-data" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="wpban_import_settings" />
                        <?php wp_nonce_field('wpban_import', 'wpban_import_nonce'); ?>
                        <p>
                            <input type="file" name="import_file" accept=".json" required />
                        </p>
                        <p>
                            <button type="submit" class="button">
                                <?php _e('Import Settings', 'wpban'); ?>
                            </button>
                        </p>
                    </form>
                </div>
                
                <!-- Database Maintenance -->
                <div class="wpban-card">
                    <h2><?php _e('Database Maintenance', 'wpban'); ?></h2>
                    <p><?php _e('Optimize your WPBan database tables.', 'wpban'); ?></p>
                    
                    <?php
                    global $wpdb;
                    $logs_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpban_logs");
                    $logs_size = $wpdb->get_var("SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) 
                                                 FROM information_schema.TABLES 
                                                 WHERE table_schema = '" . DB_NAME . "' 
                                                 AND table_name = '{$wpdb->prefix}wpban_logs'");
                    ?>
                    
                    <table class="wp-list-table widefat">
                        <tr>
                            <th><?php _e('Total Log Entries', 'wpban'); ?></th>
                            <td><?php echo number_format($logs_count); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('Database Size', 'wpban'); ?></th>
                            <td><?php echo $logs_size; ?> MB</td>
                        </tr>
                    </table>
                    
                    <p>
                        <button class="button" onclick="wpbanOptimizeDatabase()">
                            <?php _e('Optimize Tables', 'wpban'); ?>
                        </button>
                        <button class="button" onclick="wpbanClearOldLogs()">
                            <?php _e('Clear Logs Older Than 30 Days', 'wpban'); ?>
                        </button>
                    </p>
                </div>
                
                <!-- System Info -->
                <div class="wpban-card">
                    <h2><?php _e('System Information', 'wpban'); ?></h2>
                    <textarea readonly class="large-text" rows="10"><?php
                        echo "WordPress Version: " . get_bloginfo('version') . "\n";
                        echo "PHP Version: " . PHP_VERSION . "\n";
                        echo "MySQL Version: " . $wpdb->db_version() . "\n";
                        echo "WPBan Version: " . WPBAN_VERSION . "\n";
                        echo "Active Theme: " . wp_get_theme()->get('Name') . "\n";
                        echo "Active Plugins:\n";
                        foreach (get_option('active_plugins') as $plugin) {
                            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
                            echo "- " . $plugin_data['Name'] . " " . $plugin_data['Version'] . "\n";
                        }
                    ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }
    
    // AJAX Handlers
    public function ajax_save_settings() {
        check_ajax_referer('wpban_settings', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        parse_str($_POST['settings'], $data);
        $settings = $data['settings'] ?? [];
        
        // Process textarea fields
        $textarea_fields = [
            'banned_ips', 'banned_ranges', 'banned_hosts', 
            'banned_referers', 'banned_agents', 'whitelist_ips', 
            'login_allowed_ips'
        ];
        
        foreach ($textarea_fields as $field) {
            if (isset($settings[$field])) {
                $settings[$field] = array_filter(array_map('trim', explode("\n", $settings[$field])));
            }
        }
        
        // Process geo blocking
        if (isset($settings['geo_blocking']['mode']) && $settings['geo_blocking']['mode'] === 'whitelist') {
            $settings['geo_blocking']['allowed_countries'] = $settings['geo_blocking']['countries'] ?? [];
            $settings['geo_blocking']['blocked_countries'] = [];
        } else {
            $settings['geo_blocking']['blocked_countries'] = $settings['geo_blocking']['countries'] ?? [];
            $settings['geo_blocking']['allowed_countries'] = [];
        }
        unset($settings['geo_blocking']['countries']);
        
        // Validate settings
        if (isset($settings['rate_limits'])) {
            foreach ($settings['rate_limits'] as $key => $value) {
                $settings['rate_limits'][$key] = max(1, intval($value));
            }
        }
        
        update_option('wpban_settings', $settings);
        $this->security->clear_cache();
        
        wp_send_json_success(['message' => __('Settings saved successfully!', 'wpban')]);
    }
    
    public function ajax_apply_template() {
        check_ajax_referer('wpban_admin', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $template_id = sanitize_key($_POST['template']);
        $templates = $this->security->get_templates();
        
        if (!isset($templates[$template_id])) {
            wp_send_json_error(__('Invalid template', 'wpban'));
        }
        
        $current = get_option('wpban_settings', []);
        $new_settings = array_merge($current, $templates[$template_id]['settings']);
        
        update_option('wpban_settings', $new_settings);
        $this->security->clear_cache();
        
        wp_send_json_success(['message' => __('Template applied successfully!', 'wpban')]);
    }
    
    public function ajax_get_logs() {
        check_ajax_referer('wpban_admin', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $page = intval($_POST['page'] ?? 1);
        $filters = [
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
            'action' => sanitize_text_field($_POST['action'] ?? ''),
            'ip' => sanitize_text_field($_POST['ip'] ?? '')
        ];
        
        $result = $this->security->get_logs($filters, $page);
        
        ob_start();
        // Render log rows
        foreach ($result['logs'] as $log) {
            // ... render table rows ...
        }
        $html = ob_get_clean();
        
        wp_send_json_success([
            'html' => $html,
            'pagination' => paginate_links([
                'total' => $result['pages'],
                'current' => $page
            ])
        ]);
    }
    
    public function ajax_export_logs() {
        check_ajax_referer('wpban_admin', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        
        $logs = $this->security->get_logs([], 1, 10000);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="wpban-logs-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Time', 'IP', 'Country', 'Action', 'Reason', 'User Agent', 'Referer', 'URI']);
        
        foreach ($logs['logs'] as $log) {
            fputcsv($output, [
                $log->timestamp,
                $log->ip,
                $log->country_code,
                $log->action,
                $log->reason,
                $log->user_agent,
                $log->referer,
                $log->uri
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    public function ajax_clear_logs() {
        check_ajax_referer('wpban_admin', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wpban_logs");
        
        wp_send_json_success(['message' => __('Logs cleared successfully!', 'wpban')]);
    }
    
    public function ajax_test_email() {
        check_ajax_referer('wpban_admin', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $settings = get_option('wpban_settings', []);
        $to = $settings['email_notifications']['recipient'] ?? get_option('admin_email');
        $subject = sprintf('[%s] WPBan Test Email', get_bloginfo('name'));
        $message = "This is a test email from WPBan Security.\n\n";
        $message .= "If you received this email, your notifications are working correctly!";
        
        $sent = wp_mail($to, $subject, $message);
        
        if ($sent) {
            wp_send_json_success(['message' => __('Test email sent successfully!', 'wpban')]);
        } else {
            wp_send_json_error(__('Failed to send test email. Please check your email settings.', 'wpban'));
        }
    }
    
    public function ajax_get_country_stats() {
        check_ajax_referer('wpban_admin', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $stats = $wpdb->get_results(
            "SELECT country_code, COUNT(*) as count 
             FROM {$wpdb->prefix}wpban_logs 
             WHERE country_code IS NOT NULL 
             GROUP BY country_code 
             ORDER BY count DESC 
             LIMIT 20"
        );
        
        wp_send_json_success($stats);
    }
}