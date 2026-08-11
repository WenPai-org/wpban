<?php
namespace WPBan_Anything;

if (!defined('ABSPATH')) {
    exit;
}

class WPBan_SEO_Crawlers {
    private $seo_crawlers;

    public function __construct() {
        $this->seo_crawlers = $this->get_default_crawlers();
        add_filter('robots_txt', [$this, 'add_seo_robots_txt'], 10, 2);
        add_action('init', [$this, 'monitor_crawlers']);
    }

    private function get_default_crawlers() {
    return [
        'Googlebot' => ['description' => __('Google\'s primary crawler for indexing web pages.', 'wpban'), 'link' => 'https://developers.google.com/search/docs/crawling-indexing/googlebot'],
        'Bingbot' => ['description' => __('Microsoft Bing\'s crawler for indexing web content.', 'wpban'), 'link' => 'https://www.bing.com/webmasters/help/which-crawlers-does-bing-use-8c184ec0'],
        'Baiduspider' => ['description' => __('Baidu\'s crawler for indexing content, primarily for the Chinese market.', 'wpban'), 'link' => 'https://www.baidu.com/search/spider.html'],
        'YandexBot' => ['description' => __('Yandex\'s crawler for indexing web pages, used in Russia and beyond.', 'wpban'), 'link' => 'https://yandex.com/support/webmaster/robot-workings/yandex-robot.html'],
        'DuckDuckBot' => ['description' => __('DuckDuckGo\'s crawler for indexing privacy-focused search results.', 'wpban'), 'link' => 'https://duckduckgo.com/duckduckbot'],
        'Yahoo! Slurp' => ['description' => __('Yahoo\'s legacy crawler for indexing web content.', 'wpban'), 'link' => 'https://help.yahoo.com/kb/SLN22600.html'],
        'Sogou Spider' => ['description' => __('Sogou\'s crawler for indexing content, popular in China.', 'wpban'), 'link' => 'http://www.sogou.com/docs/service/spider.htm'],
        'Exabot' => ['description' => __('Exalead\'s crawler for indexing web pages, used by Dassault Systèmes.', 'wpban'), 'link' => 'https://www.exalead.com/software/exabot/'],
        'AhrefsBot' => ['description' => __('Ahrefs\' crawler for SEO analysis and backlink indexing.', 'wpban'), 'link' => 'https://ahrefs.com/robot'],
        'MJ12bot' => ['description' => __('Majestic\'s crawler for SEO and backlink analysis.', 'wpban'), 'link' => 'https://majestic.com/support/mj12bot'],
        'MauiBot' => ['description' => __('MauiBot is a web crawler used for data collection.', 'wpban'), 'link' => 'https://www.mauibot.com'],
        'MegaIndex.ru' => ['description' => __('MegaIndex.ru is a Russian SEO tool crawler.', 'wpban'), 'link' => 'https://www.megaindex.com'],
        'bytedance' => ['description' => __('Bytedance\'s crawler for data collection.', 'wpban'), 'link' => 'https://www.bytedance.com'],
        'SemrushBot' => ['description' => __('SemrushBot is a crawler used by Semrush for SEO analysis.', 'wpban'), 'link' => 'https://www.semrush.com/bot/'],
        'Windows NT 5' => ['description' => __('User agent for older Windows operating systems (e.g., Windows XP).', 'wpban'), 'link' => 'https://en.wikipedia.org/wiki/Windows_NT'],
        'BLEXBot' => ['description' => __('BLEXBot is a web crawler used by WebMeUp for backlink analysis.', 'wpban'), 'link' => 'https://webmeup.com/crawler.html'],
        'DotBot' => ['description' => __('DotBot is a web crawler used by Moz for SEO data collection.', 'wpban'), 'link' => 'https://moz.com/help/guides/moz-procedures/what-is-dotbot'],
        'CocCocBot' => ['description' => __('CocCocBot is a Vietnamese search engine crawler.', 'wpban'), 'link' => 'https://help.coccoc.com/searchengine'],
        'ImagesiftBot' => ['description' => __('ImagesiftBot is a crawler used for image analysis.', 'wpban'), 'link' => 'https://imagesift.com'],
        'Apache-HttpClient/4.5.2 (Java/1.8.0_151)' => ['description' => __('A common user agent for Java-based HTTP clients.', 'wpban'), 'link' => 'https://hc.apache.org/httpcomponents-client-ga/'],
        'Windows NT 6' => ['description' => __('User agent for Windows Vista, 7, 8, and 10.', 'wpban'), 'link' => 'https://en.wikipedia.org/wiki/Windows_NT'],
        'Macintosh' => ['description' => __('User agent for macOS devices.', 'wpban'), 'link' => 'https://en.wikipedia.org/wiki/Macintosh'],
        'python' => ['description' => __('User agent for Python-based web requests.', 'wpban'), 'link' => 'https://www.python.org'],
        'Fedora' => ['description' => __('User agent for Fedora Linux systems.', 'wpban'), 'link' => 'https://getfedora.org'],
        'X11' => ['description' => __('User agent for X11-based systems (e.g., Linux).', 'wpban'), 'link' => 'https://en.wikipedia.org/wiki/X_Window_System'],
        'WOW64' => ['description' => __('User agent for 64-bit Windows on Windows (WOW64) systems.', 'wpban'), 'link' => 'https://en.wikipedia.org/wiki/WoW64'],
    ];
}

    public function get_settings() {
        return get_option('seo_crawler_settings', [
            'enabled_crawlers' => []
        ]);
    }

    public function save_settings($data) {
        $settings = [
            'enabled_crawlers' => isset($data['seo_crawlers']) ? array_map('sanitize_text_field', $data['seo_crawlers']) : []
        ];
        update_option('seo_crawler_settings', $settings);
        return $settings;
    }

    public function add_seo_robots_txt($robots, $public) {
        $settings = $this->get_settings();
        $robots .= "\n# WPBan-Anything SEO Crawler Blocks\n";

        foreach ($settings['enabled_crawlers'] as $crawler) {
            if (array_key_exists($crawler, $this->seo_crawlers)) {
                $robots .= "User-agent: $crawler\nDisallow: /\n";
            }
        }

        $robots .= "# End WPBan-Anything SEO Crawler Blocks\n";
        return $robots;
    }

    public function get_crawler_list() {
        return $this->seo_crawlers;
    }

    public function monitor_crawlers() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $settings = $this->get_settings();
        foreach ($settings['enabled_crawlers'] as $crawler) {
            if (stripos($ua, $crawler) !== false) {
                $stats = get_option('banned_stats', [
                    'users' => [],
                    'total_count' => 0,
                    'last_ban_time' => null,
                    'types' => ['ip_ban' => 0, 'login_restriction' => 0, 'wechat_qq_block' => 0, 'ai_crawler_block' => 0, 'seo_crawler_block' => 0]
                ]);
                $ip = ban_anything_get_ip();
                $stats['users'][$ip] = [
                    'count' => ($stats['users'][$ip]['count'] ?? 0) + 1,
                    'last_time' => current_time('mysql'),
                    'type' => 'seo_crawler_block'
                ];
                $stats['total_count']++;
                $stats['last_ban_time'] = current_time('mysql');
                $stats['types']['seo_crawler_block']++;
                update_option('banned_stats', $stats);
                break;
            }
        }
    }
}
