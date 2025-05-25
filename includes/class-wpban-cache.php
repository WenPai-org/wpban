<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBan_Cache {
    private $cache_group = 'wpban';
    private $use_transients = false;
    
    public function __construct() {
        // Check if object caching is available
        if (!wp_using_ext_object_cache()) {
            $this->use_transients = true;
        }
    }
    
    public function get($key, $callback = null, $expiration = 3600) {
        $settings = get_option('wpban_settings', []);
        if (empty($settings['enable_caching'])) {
            return $callback ? $callback() : false;
        }
        
        $cache_key = $this->get_cache_key($key);
        
        if ($this->use_transients) {
            $value = get_transient($cache_key);
        } else {
            $value = wp_cache_get($key, $this->cache_group);
        }
        
        if ($value === false && $callback) {
            $value = $callback();
            $this->set($key, $value, $expiration);
        }
        
        return $value;
    }
    
    public function set($key, $value, $expiration = 3600) {
        $settings = get_option('wpban_settings', []);
        if (empty($settings['enable_caching'])) {
            return false;
        }
        
        $cache_key = $this->get_cache_key($key);
        
        if ($this->use_transients) {
            return set_transient($cache_key, $value, $expiration);
        } else {
            return wp_cache_set($key, $value, $this->cache_group, $expiration);
        }
    }
    
    public function delete($key) {
        $cache_key = $this->get_cache_key($key);
        
        if ($this->use_transients) {
            return delete_transient($cache_key);
        } else {
            return wp_cache_delete($key, $this->cache_group);
        }
    }
    
    public function clear() {
        if ($this->use_transients) {
            // Clear all WPBan transients
            global $wpdb;
            $wpdb->query(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE '_transient_wpban_%' 
                OR option_name LIKE '_transient_timeout_wpban_%'"
            );
        } else {
            // Clear object cache group
            wp_cache_delete_group($this->cache_group);
        }
    }
    
    private function get_cache_key($key) {
        return $this->use_transients ? 'wpban_' . md5($key) : $key;
    }
}