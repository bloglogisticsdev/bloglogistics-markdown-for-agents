<?php
/**
 * Plugin Name:       BlogLogistics Markdown for Agents
 * Plugin URI:        https://github.com/bloglogisticsdev/bloglogistics-markdown-for-agents
 * Description:       Adds Markdown content negotiation for AI agents and serves a machine-readable homepage at /index.md using the current WordPress site's URLs and metadata.
 * Version:           1.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            BlogLogistics
 * Author URI:        https://www.bloglogistics.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:        https://github.com/bloglogisticsdev/bloglogistics-markdown-for-agents
 * Text Domain:       bloglogistics-markdown-for-agents
 */

if (!defined('ABSPATH')) {
    exit;
}

define( 'BLOGLOGISTICS_MFA_VERSION', '1.1.0' );
define( 'BLOGLOGISTICS_MFA_SLUG', 'bloglogistics-markdown-for-agents' );
define( 'BLOGLOGISTICS_MFA_FILE', __FILE__ );
define( 'BLOGLOGISTICS_MFA_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLOGLOGISTICS_MFA_REPO_URL', 'https://github.com/bloglogisticsdev/bloglogistics-markdown-for-agents/' );

$bloglogistics_mfa_puc = BLOGLOGISTICS_MFA_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

if ( file_exists( $bloglogistics_mfa_puc ) ) {
    require_once $bloglogistics_mfa_puc;
    require_once BLOGLOGISTICS_MFA_DIR . 'includes/class-bloglogistics-github-plugin-updater.php';

    BlogLogistics_GitHub_Plugin_Updater::init( [
        'repo_url'    => BLOGLOGISTICS_MFA_REPO_URL,
        'plugin_file' => BLOGLOGISTICS_MFA_FILE,
        'slug'        => BLOGLOGISTICS_MFA_SLUG,
    ] );
}

final class BL_Markdown_For_Agents {
    private const MD_PATH = '/index.md';

    public static function init(): void {
        add_action('send_headers', [__CLASS__, 'add_link_header']);
        add_action('template_redirect', [__CLASS__, 'maybe_serve_markdown'], 0);
    }

    public static function add_link_header(): void {
        if (headers_sent()) {
            return;
        }

        if (self::is_homepage_request()) {
            header(
                'Link: <' . esc_url_raw(self::markdown_url()) . '>; rel="alternate"; type="text/markdown", ' .
                '<' . esc_url_raw(self::canonical_url()) . '>; rel="canonical"; type="text/html", ' .
                '<' . esc_url_raw(self::sitemap_url()) . '>; rel="sitemap"; type="application/xml"',
                false
            );
        }
    }

    public static function maybe_serve_markdown(): void {
        if (!self::should_serve_markdown()) {
            return;
        }

        status_header(200);
        nocache_headers();

        header('Content-Type: text/markdown; charset=utf-8');
        header('Vary: Accept', false);
        header('X-Robots-Tag: index, follow');
        header(
            'Link: <' . esc_url_raw(self::canonical_url()) . '>; rel="canonical"; type="text/html", ' .
            '<' . esc_url_raw(self::markdown_url()) . '>; rel="self"; type="text/markdown"',
            false
        );

        echo self::markdown_content();
        exit;
    }

    private static function should_serve_markdown(): bool {
        $path = self::relative_request_path();
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        $is_index_md = in_array($path, [self::MD_PATH, self::MD_PATH . '/'], true);
        $homepage_markdown_requested = self::is_homepage_request() && stripos($accept, 'text/markdown') !== false;

        return $is_index_md || $homepage_markdown_requested;
    }

    private static function is_homepage_request(): bool {
        $path = self::relative_request_path();
        return $path === '/' || $path === '';
    }

    private static function current_path(): string {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($request_uri, PHP_URL_PATH);
        return is_string($path) ? $path : '/';
    }

    private static function relative_request_path(): string {
        $request_path = self::normalise_path(self::current_path());
        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        $home_path = self::normalise_path(is_string($home_path) ? $home_path : '/');

        if ($home_path !== '/' && strpos($request_path, $home_path) === 0) {
            $request_path = substr($request_path, strlen($home_path));
            $request_path = self::normalise_path($request_path);
        }

        return $request_path;
    }

    private static function normalise_path(string $path): string {
        if ($path === '') {
            return '/';
        }

        $path = '/' . ltrim($path, '/');

        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    private static function canonical_url(): string {
        return home_url('/');
    }

    private static function markdown_url(): string {
        return home_url(self::MD_PATH);
    }

    private static function robots_url(): string {
        return home_url('/robots.txt');
    }

    private static function sitemap_url(): string {
        return home_url('/sitemap.xml');
    }

    private static function site_name(): string {
        $name = get_bloginfo('name');
        return $name !== '' ? wp_strip_all_tags($name) : wp_parse_url(home_url('/'), PHP_URL_HOST);
    }

    private static function site_description(): string {
        return wp_strip_all_tags(get_bloginfo('description'));
    }

    private static function markdown_content(): string {
        $site_name = self::site_name();
        $site_description = self::site_description();
        $front_page_content = self::front_page_markdown();
        $published_pages = self::published_pages_markdown();

        $lines = [
            '---',
            'title: ' . self::yaml_value($site_name),
            'site_name: ' . self::yaml_value($site_name),
            'canonical_url: ' . self::canonical_url(),
            'markdown_url: ' . self::markdown_url(),
            'last_updated: ' . gmdate('Y-m-d'),
            'content_type: text/markdown',
            'content_signal: search=yes, ai-input=yes, ai-train=no',
            '---',
            '',
            '# ' . self::markdown_escape($site_name),
            '',
        ];

        if ($site_description !== '') {
            $lines[] = self::markdown_escape($site_description);
            $lines[] = '';
        }

        if ($front_page_content !== '') {
            $lines[] = '## Homepage Content';
            $lines[] = '';
            $lines[] = $front_page_content;
            $lines[] = '';
        }

        if ($published_pages !== '') {
            $lines[] = '## Important Pages';
            $lines[] = '';
            $lines[] = $published_pages;
            $lines[] = '';
        }

        $lines[] = '## Machine-readable Access';
        $lines[] = '';
        $lines[] = '- Homepage: ' . self::canonical_url();
        $lines[] = '- Markdown homepage: ' . self::markdown_url();
        $lines[] = '- Sitemap: ' . self::sitemap_url();
        $lines[] = '- Robots policy: ' . self::robots_url();
        $lines[] = '';

        return implode("\n", $lines);
    }

    private static function front_page_markdown(): string {
        $front_page_id = (int) get_option('page_on_front');

        if ($front_page_id <= 0) {
            return '';
        }

        $post = get_post($front_page_id);

        if (!$post || $post->post_status !== 'publish') {
            return '';
        }

        $content = apply_filters('the_content', $post->post_content);
        $content = self::html_to_plain_markdown($content);

        return trim($content);
    }

    private static function published_pages_markdown(): string {
        $front_page_id = (int) get_option('page_on_front');

        $pages = get_pages([
            'sort_column' => 'menu_order,post_title',
            'sort_order'  => 'ASC',
            'post_status' => 'publish',
            'exclude'     => $front_page_id > 0 ? [$front_page_id] : [],
            'number'      => 20,
        ]);

        if (empty($pages)) {
            return '';
        }

        $lines = [];

        foreach ($pages as $page) {
            $title = self::markdown_escape(get_the_title($page));
            $url = get_permalink($page);

            if (!$url) {
                continue;
            }

            $excerpt = '';
            if (!empty($page->post_excerpt)) {
                $excerpt = wp_strip_all_tags($page->post_excerpt);
            } elseif (!empty($page->post_content)) {
                $excerpt = wp_trim_words(wp_strip_all_tags(strip_shortcodes($page->post_content)), 30, '...');
            }

            $lines[] = '- [' . $title . '](' . esc_url_raw($url) . ')' . ($excerpt !== '' ? ' - ' . self::markdown_escape($excerpt) : '');
        }

        return implode("\n", $lines);
    }

    private static function html_to_plain_markdown(string $html): string {
        $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "\n# $1\n", $html);
        $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "\n## $1\n", $html);
        $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "\n### $1\n", $html);
        $html = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "\n$1\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace_callback('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function ($matches) {
            $label = wp_strip_all_tags($matches[2]);
            $url = esc_url_raw($matches[1]);
            return '[' . $label . '](' . $url . ')';
        }, $html);

        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private static function yaml_value(?string $value): string {
        $value = (string) $value;
        $value = str_replace('"', '\"', $value);
        return '"' . $value . '"';
    }

    private static function markdown_escape(string $value): string {
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }
}

BL_Markdown_For_Agents::init();
