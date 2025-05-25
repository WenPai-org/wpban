<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBan_Logger {
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wpban_logs';
    }
    
    public function log($ip, $action, $reason, $details = '') {
        $settings = get_option('wpban_settings', []);
        if (empty($settings['enable_logging'])) {
            return;
        }
        
        global $wpdb;
        
        $data = [
            'ip' => $ip,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'referer' => substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
            'uri' => substr($_SERVER['REQUEST_URI'] ?? '', 0, 500),
            'action' => $action,
            'reason' => $reason . ($details ? ' - ' . $details : ''),
            'timestamp' => current_time('mysql')
        ];
        
        $wpdb->insert($this->table_name, $data);
        
        // Clean old logs (keep last 30 days)
        $this->clean_old_logs();
    }
    
    public function get_logs($filters = []) {
        global $wpdb;
        
        $where = [];
        $where_values = [];
        
        if (!empty($filters['date'])) {
            $where[] = "DATE(timestamp) = %s";
            $where_values[] = $filters['date'];
        }
        
        if (!empty($filters['action'])) {
            $where[] = "action = %s";
            $where_values[] = $filters['action'];
        }
        
        if (!empty($filters['ip'])) {
            $where[] = "ip = %s";
            $where_values[] = $filters['ip'];
        }
        
        $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = isset($filters['limit']) ? intval($filters['limit']) : 100;
        
        $query = "SELECT * FROM {$this->table_name} {$where_clause} ORDER BY timestamp DESC LIMIT %d";
        $where_values[] = $limit;
        
        return $wpdb->get_results($wpdb->prepare($query, $where_values));
    }
    
    public function get_stats() {
        global $wpdb;
        
        $settings = get_option('wpban_settings', []);
        
        // Total blocks
        $total_blocks = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        // Unique IPs
        $unique_ips = $wpdb->get_var("SELECT COUNT(DISTINCT ip) FROM {$this->table_name}");
        
        // Today's blocks
        $today = current_time('Y-m-d');
        $today_blocks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE DATE(timestamp) = %s",
            $today
        ));
        
        // Count active rules
        $active_rules = 0;
        $rule_types = ['banned_ips', 'banned_ranges', 'banned_hosts', 'banned_referers', 'banned_agents', 'blocked_crawlers'];
        foreach ($rule_types as $type) {
            if (!empty($settings[$type])) {
                $active_rules += count($settings[$type]);
            }
        }
        
        return [
            'total_blocks' => $total_blocks,
            'unique_ips' => $unique_ips,
            'today_blocks' => $today_blocks,
            'active_rules' => $active_rules
        ];
    }
    
    public function clear_logs() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
    
    private function clean_old_logs() {
        global $wpdb;
        
        // Run cleanup only 1% of the time to avoid performance impact
        if (mt_rand(1, 100) > 1) {
            return;
        }
        
        $days_to_keep = 30;
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE timestamp < %s",
            $cutoff_date
        ));
    }
}