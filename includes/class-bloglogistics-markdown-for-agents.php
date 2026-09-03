<?php
/**
 * Core plugin functionality for BlogLogistics Markdown for Agents.
 *
 * @package BlogLogistics_Markdown_For_Agents
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BL_Markdown_For_Agents {
    private const MENU_SLUG = 'bloglogistics';
    private const SETTINGS_SLUG = 'bloglogistics-markdown-for-agents';
    private const MARKDOWN_URL_META = 'bloglogistics_markdown_url';
    private const DISABLED_META = 'bloglogistics_markdown_disabled';
    private const LLMS_DETECTED_OPTION = 'bloglogistics_mfa_llms_detected';
    private const LAST_SCAN_OPTION = 'bloglogistics_mfa_last_scan';

    public static function init(): void {
        self::load_textdomain();

        add_action( 'init', [ __CLASS__, 'register_meta' ] );
        add_action( 'wp_head', [ __CLASS__, 'output_discovery_links' ], 20 );

        add_action( 'admin_init', [ __CLASS__, 'maybe_upgrade' ] );
        add_action( 'admin_menu', [ __CLASS__, 'register_bloglogistics_menu' ], 9 );
        add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ], 20 );
        add_action( 'admin_post_bloglogistics_mfa_scan', [ __CLASS__, 'handle_scan' ] );
        add_action( 'admin_post_bloglogistics_mfa_save_exclusions', [ __CLASS__, 'handle_save_exclusions' ] );

        add_action( 'add_meta_boxes', [ __CLASS__, 'register_editor_meta_box' ] );
        add_action( 'save_post', [ __CLASS__, 'save_editor_options' ], 10, 2 );
    }

    private static function load_textdomain(): void {
        load_plugin_textdomain(
            'bloglogistics-markdown-for-agents',
            false,
            dirname( plugin_basename( BLOGLOGISTICS_MFA_FILE ) ) . '/languages/'
        );
    }

    /**
     * Activation deliberately does not scan the filesystem.
     *
     * Users remain in control of when the plugin checks for curated files.
     */
    public static function activate(): void {
        if ( false === get_option( self::LLMS_DETECTED_OPTION, false ) ) {
            add_option( self::LLMS_DETECTED_OPTION, '0', '', true );
        }

        if ( false === get_option( self::LAST_SCAN_OPTION, false ) ) {
            add_option( self::LAST_SCAN_OPTION, 0, '', false );
        }

        delete_option( BLOGLOGISTICS_MFA_SETTINGS_OPTION );
        update_option( BLOGLOGISTICS_MFA_VERSION_OPTION, BLOGLOGISTICS_MFA_VERSION, false );
    }

    /**
     * One-time migration for sites updating from the dynamic Markdown version.
     * Runs in wp-admin only.
     */
    public static function maybe_upgrade(): void {
        $stored_version = (string) get_option( BLOGLOGISTICS_MFA_VERSION_OPTION, '' );

        if ( BLOGLOGISTICS_MFA_VERSION === $stored_version ) {
            return;
        }

        delete_option( BLOGLOGISTICS_MFA_SETTINGS_OPTION );

        if ( false === get_option( self::LLMS_DETECTED_OPTION, false ) ) {
            add_option( self::LLMS_DETECTED_OPTION, '0', '', true );
        }

        if ( false === get_option( self::LAST_SCAN_OPTION, false ) ) {
            add_option( self::LAST_SCAN_OPTION, 0, '', false );
        }

        update_option( BLOGLOGISTICS_MFA_VERSION_OPTION, BLOGLOGISTICS_MFA_VERSION, false );
    }

    /**
     * Register the two user-visible post meta fields.
     */
    public static function register_meta(): void {
        foreach ( [ 'post', 'page' ] as $post_type ) {
            add_post_type_support( $post_type, 'custom-fields' );

            register_post_meta(
                $post_type,
                self::MARKDOWN_URL_META,
                [
                    'type'              => 'string',
                    'single'            => true,
                    'show_in_rest'      => true,
                    'sanitize_callback' => 'esc_url_raw',
                    'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
                        return current_user_can( 'edit_post', (int) $post_id );
                    },
                    'default'           => '',
                ]
            );

            register_post_meta(
                $post_type,
                self::DISABLED_META,
                [
                    'type'              => 'boolean',
                    'single'            => true,
                    'show_in_rest'      => true,
                    'sanitize_callback' => static function ( $value ): bool {
                        return (bool) $value;
                    },
                    'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
                        return current_user_can( 'edit_post', (int) $post_id );
                    },
                    'default'           => false,
                ]
            );
        }
    }

    /**
     * Output discovery markup for the current singular post/page.
     *
     * No filesystem checks, directory scans, HTTP probes, or Markdown
     * generation occur on public page loads. WordPress normally primes post
     * metadata in the main query, so these values are served from its metadata
     * cache on standard requests.
     */
    public static function output_discovery_links(): void {
        static $done = false;

        if ( $done || ! is_singular( [ 'post', 'page' ] ) ) {
            return;
        }

        $done    = true;
        $post_id = get_queried_object_id();

        if ( ! $post_id || self::is_discovery_disabled( $post_id ) ) {
            return;
        }

        $markdown_url = (string) get_post_meta( $post_id, self::MARKDOWN_URL_META, true );

        if ( '' === $markdown_url ) {
            return;
        }

        printf(
            '<link rel="alternate" type="text/markdown" href="%s">' . "\n",
            esc_url( $markdown_url )
        );

        if ( '1' === get_option( self::LLMS_DETECTED_OPTION, '0' ) ) {
            printf(
                '<link rel="describedby" href="%s">' . "\n",
                esc_url( home_url( '/llms.txt' ) )
            );
        }
    }

    private static function is_discovery_disabled( int $post_id ): bool {
        return (bool) get_post_meta( $post_id, self::DISABLED_META, true );
    }

    /**
     * Return the filesystem directory corresponding to the site's public root.
     * Used only during an explicit administrator scan.
     */
    private static function public_root(): string {
        if ( ! function_exists( 'get_home_path' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $root = get_home_path();

        if ( ! is_string( $root ) || '' === $root ) {
            $root = ABSPATH;
        }

        return trailingslashit( wp_normalize_path( $root ) );
    }

    private static function home_path(): string {
        $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

        if ( ! is_string( $home_path ) || '' === $home_path ) {
            return '/';
        }

        $home_path = '/' . trim( rawurldecode( $home_path ), '/' );

        return '/' === $home_path ? '/' : trailingslashit( $home_path );
    }

    /**
     * Convert a permalink into a path relative to the site's public root.
     */
    private static function relative_path_from_permalink( string $permalink ): ?string {
        $path = wp_parse_url( $permalink, PHP_URL_PATH );

        if ( ! is_string( $path ) ) {
            return null;
        }

        $path      = '/' . ltrim( rawurldecode( $path ), '/' );
        $home_path = self::home_path();

        if ( '/' !== $home_path ) {
            if ( 0 !== strpos( trailingslashit( $path ), $home_path ) ) {
                return null;
            }

            $path = substr( $path, strlen( rtrim( $home_path, '/' ) ) );
        }

        $relative = trim( $path, '/' );

        if ( false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) ) {
            return null;
        }

        return $relative;
    }

    /**
     * Confirm a file is readable and resides inside the public site root.
     * Used only during an explicit administrator scan.
     */
    private static function is_safe_readable_file( string $file, string $public_root ): bool {
        if ( ! is_file( $file ) || ! is_readable( $file ) ) {
            return false;
        }

        $real_file = realpath( $file );
        $real_root = realpath( $public_root );

        if ( false === $real_file || false === $real_root ) {
            return false;
        }

        $real_file = wp_normalize_path( $real_file );
        $real_root = trailingslashit( wp_normalize_path( $real_root ) );

        return 0 === strpos( $real_file, $real_root );
    }

    /**
     * Scan for user-created Markdown companions and cache their URLs as post
     * meta. This function is called only by an explicit administrator action.
     *
     * @return array{checked:int,found:int,removed:int,llms:bool}
     */
    private static function scan_markdown_files(): array {
        $public_root  = self::public_root();
        $front_page   = ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_on_front' ) : 0;
        $checked      = 0;
        $found        = 0;
        $removed      = 0;

        $post_ids = get_posts(
            [
                'post_type'              => [ 'post', 'page' ],
                'post_status'            => 'publish',
                'numberposts'            => -1,
                'fields'                 => 'ids',
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'suppress_filters'       => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );

        foreach ( $post_ids as $post_id ) {
            $checked++;

            if ( $front_page && $front_page === (int) $post_id ) {
                $relative_markdown = 'index.md';
                $markdown_url      = home_url( '/index.md' );
            } else {
                $permalink = get_permalink( $post_id );

                if ( ! is_string( $permalink ) || '' === $permalink ) {
                    continue;
                }

                $relative = self::relative_path_from_permalink( $permalink );

                if ( null === $relative || '' === $relative ) {
                    continue;
                }

                $relative_markdown = trailingslashit( $relative ) . 'index.md';
                $markdown_url      = trailingslashit( $permalink ) . 'index.md';
            }

            $markdown_file = wp_normalize_path( $public_root . ltrim( $relative_markdown, '/' ) );

            if ( self::is_safe_readable_file( $markdown_file, $public_root ) ) {
                update_post_meta( $post_id, self::MARKDOWN_URL_META, esc_url_raw( $markdown_url ) );
                $found++;
            } elseif ( metadata_exists( 'post', $post_id, self::MARKDOWN_URL_META ) ) {
                delete_post_meta( $post_id, self::MARKDOWN_URL_META );
                $removed++;
            }
        }

        $llms_file = wp_normalize_path( $public_root . 'llms.txt' );
        $has_llms  = self::is_safe_readable_file( $llms_file, $public_root );

        update_option( self::LLMS_DETECTED_OPTION, $has_llms ? '1' : '0', true );
        update_option( self::LAST_SCAN_OPTION, time(), false );

        return [
            'checked' => $checked,
            'found'   => $found,
            'removed' => $removed,
            'llms'    => $has_llms,
        ];
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

        $last_scan = (int) get_option( self::LAST_SCAN_OPTION, 0 );
        $has_llms  = '1' === get_option( self::LLMS_DETECTED_OPTION, '0' );
        $message   = isset( $_GET['bloglogistics_mfa_message'] ) ? sanitize_key( wp_unslash( $_GET['bloglogistics_mfa_message'] ) ) : '';

        $detected_ids = get_posts(
            [
                'post_type'              => [ 'post', 'page' ],
                'post_status'            => 'publish',
                'numberposts'            => -1,
                'fields'                 => 'ids',
                'meta_key'               => self::MARKDOWN_URL_META,
                'meta_compare'           => 'EXISTS',
                'orderby'                => 'title',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            ]
        );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Markdown for Agents', 'bloglogistics-markdown-for-agents' ) . '</h1>';

        echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Editorial control stays with you.', 'bloglogistics-markdown-for-agents' ) . '</strong> ';
        echo esc_html__( 'This plugin does not create, rewrite, or curate llms.txt or any Markdown file. You create and maintain those files yourself. The plugin only discovers files during a manual admin scan and advertises the files you choose to expose.', 'bloglogistics-markdown-for-agents' );
        echo '</p></div>';

        if ( 'scanned' === $message ) {
            $checked = isset( $_GET['checked'] ) ? absint( $_GET['checked'] ) : 0;
            $found   = isset( $_GET['found'] ) ? absint( $_GET['found'] ) : 0;
            $removed = isset( $_GET['removed'] ) ? absint( $_GET['removed'] ) : 0;

            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                esc_html__( 'Scan complete. Checked %1$d published posts/pages, detected %2$d Markdown companions, and removed %3$d stale Markdown URL fields.', 'bloglogistics-markdown-for-agents' ),
                $checked,
                $found,
                $removed
            );
            echo '</p></div>';
        } elseif ( 'exclusions_saved' === $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Per-page discovery choices saved.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'How it works', 'bloglogistics-markdown-for-agents' ) . '</h2>';
        echo '<ol>';
        echo '<li>' . esc_html__( 'You create a carefully curated /llms.txt file and any Markdown companion files you want to publish.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'For a normal page such as /about-us/, place its companion at /about-us/index.md. The homepage companion is /index.md.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'Run the scan below after files are added, removed, or moved. The scan stores each detected Markdown URL in the WordPress custom field bloglogistics_markdown_url.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'On public page loads, the plugin performs no filesystem checks and generates no Markdown. It only reads the already-stored metadata and outputs discovery markup once for eligible pages.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '</ol>';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__( 'llms.txt detected at last scan', 'bloglogistics-markdown-for-agents' ) . '</th><td><strong>' . ( $has_llms ? esc_html__( 'Yes', 'bloglogistics-markdown-for-agents' ) : esc_html__( 'No', 'bloglogistics-markdown-for-agents' ) ) . '</strong></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Markdown companions detected', 'bloglogistics-markdown-for-agents' ) . '</th><td><strong>' . esc_html( number_format_i18n( count( $detected_ids ) ) ) . '</strong></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Last scan', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . ( $last_scan ? esc_html( wp_date( 'Y-m-d H:i:s T', $last_scan ) ) : esc_html__( 'Not yet scanned', 'bloglogistics-markdown-for-agents' ) ) . '</td></tr>';
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bloglogistics_mfa_scan' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_scan">';
        submit_button( __( 'Scan for Markdown Files', 'bloglogistics-markdown-for-agents' ), 'primary', 'submit', false );
        echo '</form>';

        echo '<h2 style="margin-top:2em;">' . esc_html__( 'Detected Markdown Companions', 'bloglogistics-markdown-for-agents' ) . '</h2>';

        if ( empty( $detected_ids ) ) {
            echo '<p>' . esc_html__( 'No Markdown companions are currently recorded. Upload your curated files, then run the scan.', 'bloglogistics-markdown-for-agents' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<p>' . esc_html__( 'Use the checkbox below to suppress discovery for a specific post or page without deleting its Markdown file. The same option is available in the WordPress editor.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bloglogistics_mfa_save_exclusions' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_save_exclusions">';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Title', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Type', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Markdown URL', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Do not advertise', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $detected_ids as $post_id ) {
            $markdown_url = (string) get_post_meta( $post_id, self::MARKDOWN_URL_META, true );
            $disabled     = self::is_discovery_disabled( (int) $post_id );
            $edit_link    = get_edit_post_link( $post_id );

            echo '<tr>';
            echo '<td>';
            if ( $edit_link ) {
                echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a>';
            } else {
                echo esc_html( get_the_title( $post_id ) );
            }
            echo '</td>';
            echo '<td>' . esc_html( get_post_type( $post_id ) ) . '</td>';
            echo '<td><code>' . esc_html( $markdown_url ) . '</code></td>';
            echo '<td><label><input type="checkbox" name="disabled_ids[]" value="' . esc_attr( (string) $post_id ) . '" ' . checked( $disabled, true, false ) . '> ' . esc_html__( 'Do not advertise Markdown or llms.txt from this page', 'bloglogistics-markdown-for-agents' ) . '</label></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        submit_button( __( 'Save Per-page Choices', 'bloglogistics-markdown-for-agents' ) );
        echo '</form>';
        echo '</div>';
    }

    public static function handle_scan(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to scan Markdown files.', 'bloglogistics-markdown-for-agents' ) );
        }

        check_admin_referer( 'bloglogistics_mfa_scan' );

        $result = self::scan_markdown_files();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                      => self::SETTINGS_SLUG,
                    'bloglogistics_mfa_message' => 'scanned',
                    'checked'                   => $result['checked'],
                    'found'                     => $result['found'],
                    'removed'                   => $result['removed'],
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function handle_save_exclusions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to change Markdown discovery settings.', 'bloglogistics-markdown-for-agents' ) );
        }

        check_admin_referer( 'bloglogistics_mfa_save_exclusions' );

        $disabled_ids = isset( $_POST['disabled_ids'] ) && is_array( $_POST['disabled_ids'] )
            ? array_map( 'absint', wp_unslash( $_POST['disabled_ids'] ) )
            : [];

        $detected_ids = get_posts(
            [
                'post_type'              => [ 'post', 'page' ],
                'post_status'            => 'publish',
                'numberposts'            => -1,
                'fields'                 => 'ids',
                'meta_key'               => self::MARKDOWN_URL_META,
                'meta_compare'           => 'EXISTS',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );

        foreach ( $detected_ids as $post_id ) {
            if ( in_array( (int) $post_id, $disabled_ids, true ) ) {
                update_post_meta( $post_id, self::DISABLED_META, '1' );
            } else {
                delete_post_meta( $post_id, self::DISABLED_META );
            }
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                      => self::SETTINGS_SLUG,
                    'bloglogistics_mfa_message' => 'exclusions_saved',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function register_editor_meta_box(): void {
        foreach ( [ 'post', 'page' ] as $post_type ) {
            add_meta_box(
                'bloglogistics-mfa-discovery',
                __( 'Markdown for Agents', 'bloglogistics-markdown-for-agents' ),
                [ __CLASS__, 'render_editor_meta_box' ],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public static function render_editor_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'bloglogistics_mfa_editor_options', 'bloglogistics_mfa_editor_nonce' );

        $markdown_url = (string) get_post_meta( $post->ID, self::MARKDOWN_URL_META, true );
        $disabled     = self::is_discovery_disabled( $post->ID );

        if ( '' !== $markdown_url ) {
            echo '<p><strong>' . esc_html__( 'Markdown companion detected:', 'bloglogistics-markdown-for-agents' ) . '</strong></p>';
            echo '<p style="word-break:break-word;"><a href="' . esc_url( $markdown_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $markdown_url ) . '</a></p>';
        } else {
            echo '<p><strong>' . esc_html__( 'No Markdown companion is currently recorded for this content.', 'bloglogistics-markdown-for-agents' ) . '</strong></p>';
            echo '<p>' . esc_html__( 'Create the curated index.md file yourself, then run BlogLogistics > Markdown for Agents > Scan for Markdown Files.', 'bloglogistics-markdown-for-agents' ) . '</p>';
        }

        echo '<p><label><input type="checkbox" name="bloglogistics_mfa_disable_discovery" value="1" ' . checked( $disabled, true, false ) . '> ' . esc_html__( 'Do not advertise Markdown or llms.txt from this page', 'bloglogistics-markdown-for-agents' ) . '</label></p>';
        echo '<p class="description">' . esc_html__( 'This preference is independent of whether the Markdown file exists and remains in effect until you change it.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        if ( '' !== $markdown_url ) {
            echo '<p class="description">' . esc_html__( 'Stored custom field:', 'bloglogistics-markdown-for-agents' ) . ' <code>' . esc_html( self::MARKDOWN_URL_META ) . '</code></p>';
        }
    }

    public static function save_editor_options( int $post_id, WP_Post $post ): void {
        if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['bloglogistics_mfa_editor_nonce'] ) ) {
            return;
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['bloglogistics_mfa_editor_nonce'] ) );

        if ( ! wp_verify_nonce( $nonce, 'bloglogistics_mfa_editor_options' ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( ! empty( $_POST['bloglogistics_mfa_disable_discovery'] ) ) {
            update_post_meta( $post_id, self::DISABLED_META, '1' );
        } else {
            delete_post_meta( $post_id, self::DISABLED_META );
        }
    }
}
