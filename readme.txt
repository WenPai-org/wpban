=== wpban Pro ===
Contributors: wpban
Tags: security, firewall, ban, geo-blocking, rate-limiting, crawler-blocking, brute-force, ip-blocking
Requires at least: 6.7.2
Tested up to: 6.7.2
Stable tag: 5.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced WordPress security plugin with geo-blocking, rate limiting, AI crawler blocking, and intelligent threat detection.

== Description ==

wpban Pro is a comprehensive security solution for WordPress that protects your site from various threats including malicious bots, brute force attacks, content scrapers, and unauthorized access attempts.

= Key Features =

**🛡️ IP Management**
* Ban IPs with wildcard support (e.g., 192.168.*.*)
* IP range blocking (CIDR and range notation)
* Whitelist trusted IPs
* Automatic reverse proxy detection

**🌍 Geographic Blocking**
* Block or allow specific countries
* Real-time IP geolocation
* Cached country lookups for performance
* Whitelist/blacklist modes

**⚡ Rate Limiting**
* Protect against DDoS and flood attacks
* Separate limits for general requests, login attempts, and API calls
* Automatic temporary bans for violators
* Customizable thresholds

**🤖 Crawler Control**
* Block 40+ AI crawlers (GPTBot, ClaudeBot, etc.)
* Control SEO crawler access
* Protect content from AI training datasets
* robots.txt integration

**📊 Advanced Logging**
* Detailed security event logs
* Filter by date, action, IP, or country
* Export logs to CSV
* Automatic log rotation

**📧 Email Notifications**
* Real-time security alerts
* Customizable alert thresholds
* Multiple event types
* Test email functionality

**🚪 Login Protection**
* Restrict wp-login.php access by IP
* Brute force detection
* Failed login tracking
* Emergency bypass URL

**🎯 Security Templates**
* Quick setup with pre-configured templates
* Basic, Strict, Content Protection, and Performance modes
* One-click application
* Customizable settings

**🔧 Additional Features**
* Browser restrictions (block WeChat/QQ)
* User agent filtering
* Referer blocking
* Host-based banning
* Import/export settings
* Database optimization tools

= Performance Optimized =

* Smart caching system
* Optimized database queries with indexes
* Minimal performance impact
* Lazy loading of features

= Emergency Access =

Never get locked out! WPBan provides an emergency bypass URL that allows you to access your site even if your IP gets banned accidentally.

== Installation ==

1. Upload the `wpban` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'WPBan Security' in your admin menu
4. Choose a security template or configure settings manually
5. Save your emergency bypass URL in a safe place

= Minimum Requirements =

* WordPress 6.7.2 or higher
* PHP 7.4 or higher
* MySQL 5.7 or higher

== Frequently Asked Questions ==

= Will this plugin slow down my website? =

No. WPBan is designed with performance in mind. It uses intelligent caching, optimized database queries, and only loads features when needed.

= What happens if I accidentally ban myself? =

Use the emergency bypass URL provided in the dashboard. This special URL allows you to access your site and disable the ban. Always save this URL in a secure location.

= Can I block entire countries? =

Yes! WPBan includes geographic blocking that allows you to block or exclusively allow specific countries. The plugin uses free IP geolocation services for this feature.

= Will blocking SEO crawlers hurt my rankings? =

Yes, blocking major search engine crawlers (Googlebot, Bingbot) will negatively impact your SEO. The plugin shows warnings for these critical crawlers. Only block SEO crawlers if you have a specific reason.

= How do I protect against AI content scraping? =

Use the "Content Protection" template which blocks major AI crawlers, or manually select AI crawlers to block in the Crawlers settings. The plugin blocks access and adds robots.txt rules.

= Can I import/export settings? =

Yes! Go to Tools > Import/Export to backup your settings or migrate them to another site.

= How long are logs kept? =

Logs are automatically cleaned after 30 days to prevent database bloat. You can export logs before they're deleted or manually clear them at any time.

= Does it work with Cloudflare? =

Yes! Enable the "Reverse Proxy" option in General settings to properly detect visitor IPs when using Cloudflare or other proxy services.

== Screenshots ==

1. Dashboard - Overview of security statistics and quick actions
2. Security Templates - One-click security configurations
3. IP Rules - Manage banned IPs, ranges, and whitelists
4. Rate Limiting - Configure request limits
5. Geographic Blocking - Block or allow countries
6. Crawler Management - Control bot access
7. Security Logs - Detailed event tracking
8. Email Notifications - Real-time alerts

== Changelog ==

= 5.0 =
* Added geographic blocking with real-time IP geolocation
* Implemented advanced rate limiting system
* Added email notifications for security events
* Improved logging with country tracking and pagination
* Added import/export functionality
* Optimized database performance with indexes
* Added emergency bypass URL feature
* Improved UI with WordPress native design
* Added security templates for quick setup
* Fixed array structure issue in templates

= 4.0 =
* Complete rewrite with improved architecture
* Added caching system
* Enhanced performance
* Better code organization

= 3.3 =
* Initial public release
* Basic IP blocking functionality
* Crawler blocking
* Simple logging

== Upgrade Notice ==

= 5.0 =
Major update with geographic blocking, rate limiting, and email notifications. Backup your settings before upgrading.

== Advanced Usage ==

= Custom Templates =

You can create custom security templates by hooking into the `wpban_templates` filter:

`
add_filter('wpban_templates', function($templates) {
    $templates['custom'] = [
        'name' => 'My Custom Template',
        'description' => 'Custom security configuration',
        'settings' => [
            'banned_ips' => ['1.2.3.4'],
            'rate_limits' => [
                'requests_per_minute' => 45
            ]
        ]
    ];
    return $templates;
});
`

= Custom Country Detection =

Integrate with premium GeoIP services:

`
add_filter('wpban_ip_country', function($country, $ip) {
    // Your custom country detection logic
    return $detected_country;
}, 10, 2);
`

= Whitelist Specific Pages =

Exclude certain pages from security checks:

`
add_filter('wpban_skip_checks', function($skip) {
    if (is_page('special-page')) {
        return true;
    }
    return $skip;
});
`

== Support ==

For support, feature requests, or bug reports, please visit our [support forum](https://wordpress.org/support/plugin/wpban/) or [GitHub repository](https://github.com/wpban/wpban).

== Privacy Policy ==

This plugin stores:
* Security logs containing IP addresses, user agents, and geographic data
* Your security configuration settings

This plugin may connect to external services:
* IP geolocation APIs (ip-api.com, ipinfo.io) for country detection
* These services only receive IP addresses for lookup

All data is stored locally in your WordPress database and is not shared with third parties.