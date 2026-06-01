<?php
final class BL_Markdown_For_Agents {
    private const MD_PATH = '/index.md';
    private const MENU_SLUG = 'bloglogistics';
    private const SETTINGS_SLUG = 'bloglogistics-markdown-for-agents';

    public static function init(): void {
        self::load_textdomain();

        add_action( 'send_headers', [ __CLASS__, 'add_link_header' ] );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_serve_markdown' ], 0 );
        add_action( 'admin_menu', [ __CLASS__, 'register_bloglogistics_menu' ], 9 );
        add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ], 20 );
        add_action( 'admin_post_bloglogistics_mfa_save_settings', [ __CLASS__, 'handle_save_settings' ] );
        add_action( 'admin_post_bloglogistics_mfa_restore_defaults', [ __CLASS__, 'handle_restore_defaults' ] );
    }


    private static function load_textdomain(): void {
        load_plugin_textdomain(
            'bloglogistics-markdown-for-agents',
            false,
            dirname( plugin_basename( BLOGLOGISTICS_MFA_FILE ) ) . '/languages/'
        );
    }

    public static function activate(): void {
        if ( ! is_array( get_option( BLOGLOGISTICS_MFA_SETTINGS_OPTION, null ) ) ) {
            add_option( BLOGLOGISTICS_MFA_SETTINGS_OPTION, self::default_settings(), '', false );
        }

        update_option( BLOGLOGISTICS_MFA_VERSION_OPTION, BLOGLOGISTICS_MFA_VERSION, false );
    }

    public static function default_settings(): array {
        return [
            'enable_markdown_homepage'     => true,
            'enable_content_negotiation'   => true,
            'add_discovery_headers'        => true,
            'include_homepage_content'     => true,
            'include_important_pages'      => true,
            'max_pages'                    => 20,
            'include_access_links'         => true,
            'include_content_signal'       => true,
        ];
    }

    private static function settings(): array {
        $saved = get_option( BLOGLOGISTICS_MFA_SETTINGS_OPTION, [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        $settings = wp_parse_args( $saved, self::default_settings() );

        $settings['enable_markdown_homepage']   = (bool) $settings['enable_markdown_homepage'];
        $settings['enable_content_negotiation'] = (bool) $settings['enable_content_negotiation'];
        $settings['add_discovery_headers']      = (bool) $settings['add_discovery_headers'];
        $settings['include_homepage_content']   = (bool) $settings['include_homepage_content'];
        $settings['include_important_pages']    = (bool) $settings['include_important_pages'];
        $settings['include_access_links']       = (bool) $settings['include_access_links'];
        $settings['include_content_signal']     = (bool) $settings['include_content_signal'];
        $settings['max_pages']                  = self::normalise_page_limit( $settings['max_pages'] );

        return $settings;
    }

    private static function normalise_page_limit( $value ): int {
        $value = absint( $value );
        if ( $value < 1 ) {
            return 1;
        }
        if ( $value > 100 ) {
            return 100;
        }
        return $value;
    }

    public static function add_link_header(): void {
        if ( headers_sent() ) {
            return;
        }

        $settings = self::settings();

        if ( ! $settings['add_discovery_headers'] || ! $settings['enable_markdown_homepage'] || ! self::is_homepage_request() ) {
            return;
        }

        header(
            'Link: <' . esc_url_raw( self::markdown_url() ) . '>; rel="alternate"; type="text/markdown", ' .
            '<' . esc_url_raw( self::canonical_url() ) . '>; rel="canonical"; type="text/html", ' .
            '<' . esc_url_raw( self::sitemap_url() ) . '>; rel="sitemap"; type="application/xml"',
            false
        );
    }

    public static function maybe_serve_markdown(): void {
        if ( ! self::should_serve_markdown() ) {
            return;
        }

        status_header( 200 );
        nocache_headers();

        header( 'Content-Type: text/markdown; charset=utf-8' );
        header( 'Vary: Accept', false );
        header( 'X-Robots-Tag: index, follow' );
        header(
            'Link: <' . esc_url_raw( self::canonical_url() ) . '>; rel="canonical"; type="text/html", ' .
            '<' . esc_url_raw( self::markdown_url() ) . '>; rel="self"; type="text/markdown"',
            false
        );

        echo self::markdown_content();
        exit;
    }

    private static function should_serve_markdown(): bool {
        $settings = self::settings();
        $path     = self::relative_request_path();
        $accept   = $_SERVER['HTTP_ACCEPT'] ?? '';

        $is_index_md = in_array( $path, [ self::MD_PATH, self::MD_PATH . '/' ], true );
        if ( $is_index_md ) {
            return $settings['enable_markdown_homepage'];
        }

        $homepage_markdown_requested = self::is_homepage_request() && stripos( $accept, 'text/markdown' ) !== false;

        return $settings['enable_content_negotiation'] && $homepage_markdown_requested;
    }

    private static function is_homepage_request(): bool {
        $path = self::relative_request_path();
        return $path === '/' || $path === '';
    }

    private static function current_path(): string {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path        = parse_url( $request_uri, PHP_URL_PATH );
        return is_string( $path ) ? $path : '/';
    }

    private static function relative_request_path(): string {
        $request_path = self::normalise_path( self::current_path() );
        $home_path    = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $home_path    = self::normalise_path( is_string( $home_path ) ? $home_path : '/' );

        if ( $home_path !== '/' && strpos( $request_path, $home_path ) === 0 ) {
            $request_path = substr( $request_path, strlen( $home_path ) );
            $request_path = self::normalise_path( $request_path );
        }

        return $request_path;
    }

    private static function normalise_path( string $path ): string {
        if ( $path === '' ) {
            return '/';
        }

        $path = '/' . ltrim( $path, '/' );

        if ( strlen( $path ) > 1 ) {
            $path = rtrim( $path, '/' );
        }

        return $path;
    }

    private static function canonical_url(): string {
        return home_url( '/' );
    }

    private static function markdown_url(): string {
        return home_url( self::MD_PATH );
    }

    private static function robots_url(): string {
        return home_url( '/robots.txt' );
    }

    private static function sitemap_url(): string {
        return home_url( '/sitemap.xml' );
    }

    private static function site_name(): string {
        $name = get_bloginfo( 'name' );
        return $name !== '' ? wp_strip_all_tags( $name ) : (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
    }

    private static function site_description(): string {
        return wp_strip_all_tags( get_bloginfo( 'description' ) );
    }

    private static function markdown_content(): string {
        $settings           = self::settings();
        $site_name          = self::site_name();
        $site_description   = self::site_description();
        $front_page_content = $settings['include_homepage_content'] ? self::front_page_markdown() : '';
        $published_pages    = $settings['include_important_pages'] ? self::published_pages_markdown( $settings['max_pages'] ) : '';

        $lines = [
            '---',
            'title: ' . self::yaml_value( $site_name ),
            'site_name: ' . self::yaml_value( $site_name ),
            'canonical_url: ' . self::canonical_url(),
            'markdown_url: ' . self::markdown_url(),
            'last_updated: ' . gmdate( 'Y-m-d' ),
            'content_type: text/markdown',
        ];

        if ( $settings['include_content_signal'] ) {
            $lines[] = 'content_signal: ' . self::content_signal_value();
        }

        $lines = array_merge( $lines, [
            '---',
            '',
            '# ' . self::markdown_escape( $site_name ),
            '',
        ] );

        if ( $site_description !== '' ) {
            $lines[] = self::markdown_escape( $site_description );
            $lines[] = '';
        }

        if ( $front_page_content !== '' ) {
            $lines[] = '## ' . __( 'Homepage Content', 'bloglogistics-markdown-for-agents' );
            $lines[] = '';
            $lines[] = $front_page_content;
            $lines[] = '';
        }

        if ( $published_pages !== '' ) {
            $lines[] = '## ' . __( 'Important Pages', 'bloglogistics-markdown-for-agents' );
            $lines[] = '';
            $lines[] = $published_pages;
            $lines[] = '';
        }

        if ( $settings['include_access_links'] ) {
            $lines[] = '## ' . __( 'Machine-readable Access', 'bloglogistics-markdown-for-agents' );
            $lines[] = '';
            $lines[] = '- ' . __( 'Homepage', 'bloglogistics-markdown-for-agents' ) . ': ' . self::canonical_url();
            $lines[] = '- ' . __( 'Markdown homepage', 'bloglogistics-markdown-for-agents' ) . ': ' . self::markdown_url();
            $lines[] = '- ' . __( 'Sitemap', 'bloglogistics-markdown-for-agents' ) . ': ' . self::sitemap_url();
            $lines[] = '- ' . __( 'Robots policy', 'bloglogistics-markdown-for-agents' ) . ': ' . self::robots_url();
            $lines[] = '';
        }

        return implode( "\n", $lines );
    }

    private static function content_signal_value(): string {
        $line = self::content_signal_line_from_robots_txt();

        if ( $line !== '' ) {
            return $line;
        }

        return 'search=yes, ai-input=yes, ai-train=no';
    }

    private static function content_signal_line_from_robots_txt(): string {
        $robots_path = trailingslashit( ABSPATH ) . 'robots.txt';

        if ( ! is_readable( $robots_path ) ) {
            return '';
        }

        $contents = file_get_contents( $robots_path );
        if ( ! is_string( $contents ) || $contents === '' ) {
            return '';
        }

        $lines      = preg_split( '/\R/', $contents );
        $in_default = false;

        foreach ( $lines as $line ) {
            $trimmed = trim( (string) $line );

            if ( preg_match( '/^User-agent\s*:\s*(.+)$/i', $trimmed, $matches ) ) {
                $in_default = trim( $matches[1] ) === '*';
                continue;
            }

            if ( $in_default && preg_match( '/^Content-Signal\s*:\s*(.+)$/i', $trimmed, $matches ) ) {
                return trim( $matches[1] );
            }

            if ( $in_default && $trimmed === '' ) {
                $in_default = false;
            }
        }

        return '';
    }

    private static function front_page_markdown(): string {
        $front_page_id = (int) get_option( 'page_on_front' );

        if ( $front_page_id <= 0 ) {
            return '';
        }

        $post = get_post( $front_page_id );

        if ( ! $post || $post->post_status !== 'publish' ) {
            return '';
        }

        $content = apply_filters( 'the_content', $post->post_content );
        $content = self::html_to_plain_markdown( $content );

        return trim( $content );
    }

    private static function published_pages_markdown( int $limit ): string {
        $front_page_id = (int) get_option( 'page_on_front' );

        $pages = get_pages( [
            'sort_column' => 'menu_order,post_title',
            'sort_order'  => 'ASC',
            'post_status' => 'publish',
            'exclude'     => $front_page_id > 0 ? [ $front_page_id ] : [],
            'number'      => $limit,
        ] );

        if ( empty( $pages ) ) {
            return '';
        }

        $lines = [];

        foreach ( $pages as $page ) {
            $title = self::markdown_escape( get_the_title( $page ) );
            $url   = get_permalink( $page );

            if ( ! $url ) {
                continue;
            }

            $excerpt = '';
            if ( ! empty( $page->post_excerpt ) ) {
                $excerpt = wp_strip_all_tags( $page->post_excerpt );
            } elseif ( ! empty( $page->post_content ) ) {
                $excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $page->post_content ) ), 30, '...' );
            }

            $lines[] = '- [' . $title . '](' . esc_url_raw( $url ) . ')' . ( $excerpt !== '' ? ' - ' . self::markdown_escape( $excerpt ) : '' );
        }

        return implode( "\n", $lines );
    }

    private static function html_to_plain_markdown( string $html ): string {
        $html = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/is', "\n# $1\n", $html );
        $html = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/is', "\n## $1\n", $html );
        $html = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/is', "\n### $1\n", $html );
        $html = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "\n$1\n", $html );
        $html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
        $html = preg_replace_callback( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function ( $matches ) {
            $label = wp_strip_all_tags( $matches[2] );
            $url   = esc_url_raw( $matches[1] );
            return '[' . $label . '](' . $url . ')';
        }, $html );

        $text = wp_strip_all_tags( $html );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
        $text = preg_replace( "/[ \t]+\n/", "\n", $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );

        return trim( $text );
    }

    private static function yaml_value( ?string $value ): string {
        $value = (string) $value;
        $value = str_replace( '"', '\\"', $value );
        return '"' . $value . '"';
    }

    private static function markdown_escape( string $value ): string {
        return trim( str_replace( [ "\r\n", "\r" ], "\n", $value ) );
    }

    public static function register_bloglogistics_menu(): void {
        if ( ! is_admin() ) {
            return;
        }

        global $menu;

        foreach ( (array) $menu as $item ) {
            if ( isset( $item[2] ) && self::MENU_SLUG === $item[2] ) {
                return;
            }
        }

        add_menu_page(
            'BlogLogistics',
            'BlogLogistics',
            'manage_options',
            self::MENU_SLUG,
            [ __CLASS__, 'render_bloglogistics_dashboard' ],
            'dashicons-rss',
            58
        );
    }

    public static function render_bloglogistics_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'bloglogistics-markdown-for-agents' ) );
        }

        echo '<div class="wrap"><h1>BlogLogistics</h1><p>' . esc_html__( 'Use the submenu items to manage BlogLogistics plugin settings.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
    }

    public static function register_settings_page(): void {
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Markdown for Agents', 'bloglogistics-markdown-for-agents' ),
            __( 'Markdown for Agents', 'bloglogistics-markdown-for-agents' ),
            'manage_options',
            self::SETTINGS_SLUG,
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'bloglogistics-markdown-for-agents' ) );
        }

        $settings = self::settings();
        $message  = isset( $_GET['bloglogistics_mfa_message'] ) ? sanitize_key( wp_unslash( $_GET['bloglogistics_mfa_message'] ) ) : '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Markdown for Agents', 'bloglogistics-markdown-for-agents' ) . '</h1>';
        echo '<p>' . esc_html__( 'This plugin provides a Markdown-friendly homepage for agents, tools, and other clients that prefer machine-readable content.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        if ( $message === 'saved' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
        } elseif ( $message === 'defaults' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Recommended defaults restored.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bloglogistics_mfa_save_settings' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_save_settings">';

        echo '<table class="form-table" role="presentation"><tbody>';
        self::render_checkbox_row( 'enable_markdown_homepage', __( 'Enable Markdown homepage', 'bloglogistics-markdown-for-agents' ), __( 'Creates a Markdown version of the homepage at /index.md.', 'bloglogistics-markdown-for-agents' ), $settings );
        self::render_checkbox_row( 'enable_content_negotiation', __( 'Support Markdown requests for the homepage', 'bloglogistics-markdown-for-agents' ), __( 'Returns Markdown when the homepage is requested by software that asks for text/markdown.', 'bloglogistics-markdown-for-agents' ), $settings );
        self::render_checkbox_row( 'add_discovery_headers', __( 'Add discovery headers', 'bloglogistics-markdown-for-agents' ), __( 'Adds headers that tell agents where to find the Markdown homepage, canonical homepage, and sitemap.', 'bloglogistics-markdown-for-agents' ), $settings );
        self::render_checkbox_row( 'include_homepage_content', __( 'Include homepage content', 'bloglogistics-markdown-for-agents' ), __( 'Adds the published homepage content to the Markdown output.', 'bloglogistics-markdown-for-agents' ), $settings );
        self::render_checkbox_row( 'include_important_pages', __( 'Include important pages', 'bloglogistics-markdown-for-agents' ), __( 'Lists published pages in the Markdown output.', 'bloglogistics-markdown-for-agents' ), $settings );

        echo '<tr><th scope="row"><label for="bloglogistics_mfa_max_pages">' . esc_html__( 'Maximum number of pages to list', 'bloglogistics-markdown-for-agents' ) . '</label></th><td>';
        echo '<input type="number" id="bloglogistics_mfa_max_pages" name="bloglogistics_mfa_settings[max_pages]" min="1" max="100" value="' . esc_attr( (string) $settings['max_pages'] ) . '" class="small-text">';
        echo '<p class="description">' . esc_html__( 'Default is 20. Allowed range is 1 to 100.', 'bloglogistics-markdown-for-agents' ) . '</p>';
        echo '</td></tr>';

        self::render_checkbox_row( 'include_access_links', __( 'Include machine-readable access links', 'bloglogistics-markdown-for-agents' ), __( 'Adds links for the homepage, Markdown homepage, sitemap, and robots policy.', 'bloglogistics-markdown-for-agents' ), $settings );
        self::render_checkbox_row( 'include_content_signal', __( 'Include website-use preferences from robots.txt', 'bloglogistics-markdown-for-agents' ), __( 'Shows the current Content-Signal preference from robots.txt when available. If the BlogLogistics robots.txt plugin manages that line, this plugin follows the value already published there instead of creating a separate setting.', 'bloglogistics-markdown-for-agents' ), $settings );
        echo '</tbody></table>';

        echo '<h2>' . esc_html__( 'Recommended defaults', 'bloglogistics-markdown-for-agents' ) . '</h2>';
        echo '<p>' . esc_html__( 'The recommended defaults keep all Markdown agent features turned on, list up to 20 pages, and follow the website-use preference already published in robots.txt.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        submit_button( __( 'Save Settings', 'bloglogistics-markdown-for-agents' ) );
        echo '</form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top: 1em;">';
        wp_nonce_field( 'bloglogistics_mfa_restore_defaults' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_restore_defaults">';
        submit_button( __( 'Restore recommended defaults', 'bloglogistics-markdown-for-agents' ), 'secondary', 'submit', false );
        echo '</form>';

        echo '</div>';
    }

    private static function render_checkbox_row( string $key, string $label, string $description, array $settings ): void {
        echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
        echo '<label><input type="checkbox" name="bloglogistics_mfa_settings[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $settings[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label>';
        echo '<p class="description">' . esc_html( $description ) . '</p>';
        echo '</td></tr>';
    }

    public static function handle_save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to save these settings.', 'bloglogistics-markdown-for-agents' ) );
        }

        check_admin_referer( 'bloglogistics_mfa_save_settings' );

        $input = isset( $_POST['bloglogistics_mfa_settings'] ) && is_array( $_POST['bloglogistics_mfa_settings'] )
            ? wp_unslash( $_POST['bloglogistics_mfa_settings'] )
            : [];

        $settings = [
            'enable_markdown_homepage'   => ! empty( $input['enable_markdown_homepage'] ),
            'enable_content_negotiation' => ! empty( $input['enable_content_negotiation'] ),
            'add_discovery_headers'      => ! empty( $input['add_discovery_headers'] ),
            'include_homepage_content'   => ! empty( $input['include_homepage_content'] ),
            'include_important_pages'    => ! empty( $input['include_important_pages'] ),
            'max_pages'                  => self::normalise_page_limit( $input['max_pages'] ?? 20 ),
            'include_access_links'       => ! empty( $input['include_access_links'] ),
            'include_content_signal'     => ! empty( $input['include_content_signal'] ),
        ];

        update_option( BLOGLOGISTICS_MFA_SETTINGS_OPTION, $settings, false );
        update_option( BLOGLOGISTICS_MFA_VERSION_OPTION, BLOGLOGISTICS_MFA_VERSION, false );

        wp_safe_redirect( add_query_arg( 'bloglogistics_mfa_message', 'saved', admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) );
        exit;
    }

    public static function handle_restore_defaults(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to restore these settings.', 'bloglogistics-markdown-for-agents' ) );
        }

        check_admin_referer( 'bloglogistics_mfa_restore_defaults' );

        update_option( BLOGLOGISTICS_MFA_SETTINGS_OPTION, self::default_settings(), false );
        update_option( BLOGLOGISTICS_MFA_VERSION_OPTION, BLOGLOGISTICS_MFA_VERSION, false );

        wp_safe_redirect( add_query_arg( 'bloglogistics_mfa_message', 'defaults', admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) );
        exit;
    }
}
