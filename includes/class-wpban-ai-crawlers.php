<?php
namespace WPBan_Anything;

if (!defined('ABSPATH')) {
    exit;
}

class WPBan_AI_Crawlers {
    private $ai_crawlers;

    public function __construct() {
        $this->ai_crawlers = $this->get_default_crawlers();
        add_filter('robots_txt', [$this, 'add_ai_robots_txt'], 10, 2);
        add_action('wp_head', [$this, 'add_meta_tag']);
        add_action('init', [$this, 'monitor_crawlers']);
    }

    private function get_default_crawlers() {
        return [
            'AI2Bot' => ['description' => __('Explores sites for web content that is used to train open language models', 'wpban-anything'), 'link' => 'https://allenai.org/crawler'],
            'Ai2Bot-Dolma' => ['description' => __('Generates data sets used to train open language models', 'wpban-anything'), 'link' => 'https://allenai.org/dolma'],
            'AmazonBot' => ['description' => __('Used by Amazon\'s Alexa AI to provide AI answers.', 'wpban-anything'), 'link' => 'https://developer.amazon.com/amazonbot'],
            'Applebot-Extended' => ['description' => __('Used by Apple for generative AI features across Apple products, including Apple Intelligence, Services, and Developer Tools.', 'wpban-anything'), 'link' => 'https://support.apple.com/en-us/119829'],
            'anthropic-ai' => ['description' => __('Used by Anthropic\'s Claude.', 'wpban-anything'), 'link' => 'https://support.anthropic.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler'],
            'Bytespider' => ['description' => __('Used by TikTok for AI training.', 'wpban-anything'), 'link' => 'https://darkvisitors.com/agents/bytespider'],
            'CCBot' => ['description' => __('Compiles datasets used to train AI models.', 'wpban-anything'), 'link' => 'https://commoncrawl.org/big-picture/frequently-asked-questions/'],
            'ChatGPT-User' => ['description' => __('Used by OpenAI to power ChatGPT.', 'wpban-anything'), 'link' => 'https://platform.openai.com/docs/plugins/bot'],
            'ClaudeBot' => ['description' => __('Used by Anthropic\'s Claude.', 'wpban-anything'), 'link' => 'https://support.anthropic.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler'],
            'Claude-Web' => ['description' => __('Used by Anthropic\'s Claude.', 'wpban-anything'), 'link' => 'https://support.anthropic.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler'],
            'cohere-ai' => ['description' => __('Used by Cohere to scrape data for AI training.', 'wpban-anything'), 'link' => 'https://cohere.com/about'],
            'cohere-training-data-crawler' => ['description' => __('Used by Cohere to scrape data for AI training.', 'wpban-anything'), 'link' => 'https://cohere.com/about'],
            'Crawlspace' => ['description' => __('A web scraper that can be used to extract data for AI training.', 'wpban-anything'), 'link' => 'https://crawlspace.dev/'],
            'Diffbot' => ['description' => __('Used by Diffbot to scrape data for AI training.', 'wpban-anything'), 'link' => 'https://docs.diffbot.com/reference/crawl-introduction'],
            'FacebookBot' => ['description' => __('Used by Meta (Facebook) for their AI.', 'wpban-anything'), 'link' => 'https://developers.facebook.com/docs/sharing/bot'],
            'FriendlyCrawler' => ['description' => __('Crawls websites to build datasets for machine learning experiments.', 'wpban-anything'), 'link' => 'https://imho.alex-kunz.com/2024/01/25/an-update-on-friendly-crawler/'],
            'GPTBot' => ['description' => __('Used by OpenAI to power ChatGPT.', 'wpban-anything'), 'link' => 'https://platform.openai.com/docs/bots'],
            'Google-Extended' => ['description' => __('Used by Google to power Gemini (formerly known as Bard).', 'wpban-anything'), 'link' => 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers'],
            'ImagesiftBot' => ['description' => __('Used by Hive\'s Imagesift tool that scrapes images. This may be used for the company\'s generative AI product.', 'wpban-anything'), 'link' => 'https://imagesift.com/about'],
            'Kangaroo Bot' => ['description' => __('Used to power the Australia-focused Kangaroo LLM.', 'wpban-anything'), 'link' => 'https://kangaroollm.com.au/kangaroo-bot/'],
            'Meta-ExternalAgent' => ['description' => __('Used by Meta (Facebook) to train AI products.', 'wpban-anything'), 'link' => 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers'],
            'Meta-ExternalFetcher' => ['description' => __('Used by Meta (Facebook) to train AI products.', 'wpban-anything'), 'link' => 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers'],
            'OAI-SearchBot' => ['description' => __('Used by OpenAI for their SearchGPT product.', 'wpban-anything'), 'link' => 'https://platform.openai.com/docs/bots'],
            'Omgili' => ['description' => __('Used by Omgili to scrape data for AI training.', 'wpban-anything'), 'link' => 'https://webz.io/blog/machine-learning/common-crawl-vs-webz-io-data'],
            'Omgilibot' => ['description' => __('Used by Omgili to scrape data for AI training.', 'wpban-anything'), 'link' => 'https://webz.io/blog/machine-learning/common-crawl-vs-webz-io-data'],
            'PanguBot' => ['description' => __('Used by Huawei to download data for the Large Language Model (LLM) called PanGu.', 'wpban-anything'), 'link' => 'https://darkvisitors.com/agents/pangubot'],
            'PetalBot' => ['description' => __('Used by Huawei to scrape data for AI training.', 'wpban-anything'), 'link' => 'https://darkvisitors.com/agents/petalbot'],
            'PerplexityBot' => ['description' => __('Used by Perplexity for their AI products.', 'wpban-anything'), 'link' => 'https://docs.perplexity.ai/docs/perplexitybot'],
            'Scrapy' => ['description' => __('Blocks the Scrapy bot (used for scraping websites).', 'wpban-anything'), 'link' => 'https://scrapy.org/'],
            'SemrushBot' => ['description' => __('Blocks the Semrush bot used to pull data into the Semrush platform. Data is used for their ContentShake AI tool.', 'wpban-anything'), 'link' => 'https://www.semrush.com/bot/'],
            'SemrushBot-OCOB' => ['description' => __('Blocks the Semrush bot used to pull data into the Semrush platform.', 'wpban-anything'), 'link' => 'https://www.semrush.com/bot/'],
            'SemrushBot-FT' => ['description' => __('Blocks the Semrush bot used to pull data into the Semrush platform.', 'wpban-anything'), 'link' => 'https://www.semrush.com/bot/'],
            'SentiBot' => ['description' => __('Used for sentiment analysis and AI training.', 'wpban-anything'), 'link' => 'https://darkvisitors.com/agents/sentibot'],
            'sentibot' => ['description' => __('Used for sentiment analysis and AI training.', 'wpban-anything'), 'link' => 'https://darkvisitors.com/agents/sentibot'],
            'Timpibot' => ['description' => __('Used by Timpi; likely for their Wilson AI Product.', 'wpban-anything'), 'link' => 'https://timpi.io/wilson-ai/'],
            'TurnitinBot' => ['description' => __('Used by Turnitin to scrape data for plagiarism detection.', 'wpban-anything'), 'link' => 'https://www.turnitin.com/robot/crawlerinfo.html'],
            'YouBot' => ['description' => __('Used by You.com to train AI products.', 'wpban-anything'), 'link' => 'https://about.you.com/es/youbot/'],
            'webzio' => ['description' => __('Used by Webz.io for their social listening and intelligence platforms.', 'wpban-anything'), 'link' => 'https://webz.io/bot.html'],
            'webzio-extended' => ['description' => __('Used by Webz.io for AI training.', 'wpban-anything'), 'link' => 'https://webz.io/bot.html']
        ];
    }

    public function get_settings() {
        return get_option('ai_crawler_settings', [
            'enabled_crawlers' => array_keys($this->ai_crawlers)
        ]);
    }

    public function save_settings($data) {
        $settings = [
            'enabled_crawlers' => isset($data['ai_crawlers']) ? array_map('sanitize_text_field', $data['ai_crawlers']) : []
        ];
        update_option('ai_crawler_settings', $settings);
        return $settings;
    }

    public function add_ai_robots_txt($robots, $public) {
        $settings = $this->get_settings();
        $robots .= "\n# WPBan-Anything AI Crawler Blocks\n";

        foreach ($settings['enabled_crawlers'] as $crawler) {
            if (array_key_exists($crawler, $this->ai_crawlers)) {
                $robots .= "User-agent: $crawler\nDisallow: /\n";
            }
        }

        $robots .= "# End WPBan-Anything AI Crawler Blocks\n";
        return $robots;
    }

    public function add_meta_tag() {
        echo '<meta name="robots" content="noai, noimageai" />';
    }

    public function get_crawler_list() {
        return $this->ai_crawlers;
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
                    'type' => 'ai_crawler_block'
                ];
                $stats['total_count']++;
                $stats['last_ban_time'] = current_time('mysql');
                $stats['types']['ai_crawler_block']++;
                update_option('banned_stats', $stats);
                break;
            }
        }
    }
}
