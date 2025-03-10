<?php
namespace WPBan_Anything;

if (!defined('ABSPATH')) {
    exit;
}

function ban_anything_get_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $options = get_option('banned_options', ['reverse_proxy' => 0]);
    if ($options['reverse_proxy']) {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
    }
    return sanitize_text_field($ip);
}

function preg_match_wildcard($pattern, $subject) {
    $pattern = preg_quote($pattern, '#');
    $pattern = str_replace('\*', '.*', $pattern);
    return preg_match("#^$pattern$#i", $subject);
}

function ban_anything_ip_in_range($ip, $ranges) {
    foreach ($ranges as $range) {
        if (strpos($range, '/') !== false) {
            list($subnet, $bits) = explode('/', $range);
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask = -1 << (32 - (int)$bits);
            if (($ip_long & $mask) === ($subnet_long & $mask)) {
                return true;
            }
        } elseif ($ip === $range) {
            return true;
        }
    }
    return false;
}
