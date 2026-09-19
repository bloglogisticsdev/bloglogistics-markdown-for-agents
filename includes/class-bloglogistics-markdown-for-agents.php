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
    private const HTACCESS_STATUS_OPTION = 'bloglogistics_mfa_htaccess_status';
    private const HTACCESS_NOTICE_OPTION = 'bloglogistics_mfa_htaccess_notice_pending';
    private const HEALTH_OPTION = 'bloglogistics_mfa_health';
    private const VALIDATION_MAX_BYTES = 2097152;
    private const LIVE_VERIFY_LIMIT = 75;
    private const BACKUP_RETAIN_COUNT = 3;
    private const PER_PAGE_USER_META = 'bloglogistics_mfa_items_per_page';

    public static function init(): void {
        self::load_textdomain();

        add_action( 'init', [ __CLASS__, 'register_meta' ] );
        add_action( 'wp_head', [ __CLASS__, 'output_discovery_links' ], 20 );

        add_action( 'admin_init', [ __CLASS__, 'maybe_upgrade' ] );
        add_action( 'admin_menu', [ __CLASS__, 'register_bloglogistics_menu' ], 9 );
        add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ], 20 );
        add_action( 'admin_post_bloglogistics_mfa_scan', [ __CLASS__, 'handle_scan' ] );
        add_action( 'admin_post_bloglogistics_mfa_save_exclusions', [ __CLASS__, 'handle_save_exclusions' ] );
        add_action( 'admin_post_bloglogistics_mfa_htaccess', [ __CLASS__, 'handle_htaccess' ] );
        add_action( 'admin_post_bloglogistics_mfa_live_verify', [ __CLASS__, 'handle_live_verify' ] );
        add_action( 'admin_post_bloglogistics_mfa_verify_item', [ __CLASS__, 'handle_verify_item' ] );
        add_action( 'admin_post_bloglogistics_mfa_cleanup_backups', [ __CLASS__, 'handle_cleanup_backups' ] );
        add_action( 'admin_post_bloglogistics_mfa_save_text_file', [ __CLASS__, 'handle_save_text_file' ] );
        add_action( 'admin_notices', [ __CLASS__, 'render_htaccess_admin_notice' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_bloglogistics_mfa_browser_verify_targets', [ __CLASS__, 'ajax_browser_verify_targets' ] );
        add_action( 'wp_ajax_bloglogistics_mfa_store_browser_results', [ __CLASS__, 'ajax_store_browser_results' ] );

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
     * Activation deliberately does not scan for curated Markdown files.
     *
     * It does, however, install the Apache-compatible .htaccess rules required
     * for /slug/ WordPress permalinks to coexist with physical /slug/index.md
     * files and for .md companions to be served as UTF-8 text/markdown.
     */
    public static function activate(): void {
        if ( false === get_option( self::LLMS_DETECTED_OPTION, false ) ) {
            add_option( self::LLMS_DETECTED_OPTION, '0', '', true );
        }

        if ( false === get_option( self::LAST_SCAN_OPTION, false ) ) {
            add_option( self::LAST_SCAN_OPTION, 0, '', false );
        }

        if ( false === get_option( self::HEALTH_OPTION, false ) ) {
            add_option( self::HEALTH_OPTION, [], '', false );
        }

        delete_option( BLOGLOGISTICS_MFA_SETTINGS_OPTION );
        self::ensure_htaccess_rule( true );
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

        if ( false === get_option( self::HEALTH_OPTION, false ) ) {
            add_option( self::HEALTH_OPTION, [], '', false );
        }

        self::ensure_htaccess_rule( true );

        // Live verification changed materially in 2.4.3. Browser-based public
        // verification replaces origin-server self-requests as the primary
        // verifier. Preserve old results for context, but require a fresh pass.
        if ( '' !== $stored_version && version_compare( $stored_version, '2.4.3', '<' ) ) {
            self::mark_saved_live_results_outdated();
        }

        update_option( BLOGLOGISTICS_MFA_VERSION_OPTION, BLOGLOGISTICS_MFA_VERSION, false );
    }

    /**
     * Mark previously saved live results for re-verification after a verifier
     * behaviour change without discarding the administrator's history.
     */
    private static function mark_saved_live_results_outdated(): void {
        $health = get_option( self::HEALTH_OPTION, [] );

        if ( ! is_array( $health ) || ! isset( $health['live']['items'] ) || ! is_array( $health['live']['items'] ) ) {
            return;
        }

        $outdated_count = 0;
        $marked_at      = time();

        foreach ( $health['live']['items'] as $post_id => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $item['outdated']    = true;
            $item['outdated_at'] = $marked_at;
            $health['live']['items'][ $post_id ] = $item;
            $outdated_count++;
        }

        $health['live']['outdated_count'] = $outdated_count;
        update_option( self::HEALTH_OPTION, $health, false );
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

        if ( $done ) {
            return;
        }

        $post_id = self::current_discovery_post_id();

        if ( ! $post_id ) {
            return;
        }

        $done = true;

        if ( self::is_discovery_disabled( $post_id ) ) {
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

    /**
     * Resolve the WordPress content object whose discovery links belong in the
     * current front-end response. The configured Posts page is an archive
     * request (`is_home()`), not a singular page, even though it is backed by a
     * real Page object. Supporting it here keeps a /notes/ style Posts page in
     * sync with the rest of the plugin's Page health model.
     */
    private static function current_discovery_post_id(): int {
        if ( is_singular( [ 'post', 'page' ] ) ) {
            return absint( get_queried_object_id() );
        }

        if ( is_home() ) {
            $posts_page_id = absint( get_option( 'page_for_posts', 0 ) );

            if ( $posts_page_id && 'publish' === get_post_status( $posts_page_id ) ) {
                return $posts_page_id;
            }
        }

        return 0;
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
     * Validate a text file without changing it.
     *
     * Validation runs only during an administrator-initiated scan. UTF-8 BOMs
     * are reported as warnings, while invalid UTF-8, replacement characters,
     * common mojibake patterns, unreadable content, and oversized files are
     * treated as validation failures.
     *
     * @return array{checked:bool,valid:bool,valid_utf8:bool,bom:bool,mojibake:bool,size:int,issues:array<int,string>}
     */
    private static function validate_text_file( string $file, string $public_root ): array {
        $result = [
            'checked'    => false,
            'valid'      => false,
            'valid_utf8' => false,
            'bom'        => false,
            'mojibake'   => false,
            'size'       => 0,
            'issues'     => [],
        ];

        if ( ! self::is_safe_readable_file( $file, $public_root ) ) {
            $result['issues'][] = 'unreadable';
            return $result;
        }

        $size = @filesize( $file );

        if ( false === $size ) {
            $result['issues'][] = 'size_unknown';
            return $result;
        }

        $result['size'] = (int) $size;

        if ( $size > self::VALIDATION_MAX_BYTES ) {
            $result['issues'][] = 'too_large';
            return $result;
        }

        $contents = @file_get_contents( $file );

        if ( ! is_string( $contents ) ) {
            $result['issues'][] = 'read_failed';
            return $result;
        }

        $result['checked'] = true;
        $result['bom']     = str_starts_with( $contents, "\xEF\xBB\xBF" );

        if ( function_exists( 'mb_check_encoding' ) ) {
            $result['valid_utf8'] = mb_check_encoding( $contents, 'UTF-8' );
        } else {
            $result['valid_utf8'] = 1 === preg_match( '//u', $contents );
        }

        if ( ! $result['valid_utf8'] ) {
            $result['issues'][] = 'invalid_utf8';
        }

        if ( false !== strpos( $contents, "\xEF\xBF\xBD" ) ) {
            $result['issues'][] = 'replacement_character';
        }

        $mojibake_patterns = [
            'â€',
            'ï»¿',
            'Â ',
            'Ã©',
            'Ã¨',
            'Ãª',
            'Ã¡',
            'Ã¢',
            'Ã£',
            'Ã¤',
            'Ã­',
            'Ã³',
            'Ã´',
            'Ã¶',
            'Ãº',
            'Ã¼',
            'Ã±',
            'Ã§',
        ];

        foreach ( $mojibake_patterns as $pattern ) {
            if ( false !== strpos( $contents, $pattern ) ) {
                $result['mojibake'] = true;
                $result['issues'][] = 'possible_mojibake';
                break;
            }
        }

        if ( $result['bom'] ) {
            $result['issues'][] = 'utf8_bom';
        }

        $hard_failures = array_diff( $result['issues'], [ 'utf8_bom' ] );
        $result['valid'] = $result['valid_utf8'] && empty( $hard_failures );

        return $result;
    }

    /**
     * Return a local filesystem path for a same-site URL or path.
     */
    private static function local_file_from_url( string $url, string $public_root ): ?string {
        $site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

        if ( '' !== $url_host && '' !== $site_host && $url_host !== $site_host ) {
            return null;
        }

        $path = wp_parse_url( $url, PHP_URL_PATH );

        if ( ! is_string( $path ) || '' === $path ) {
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

        $relative = ltrim( $path, '/' );

        if ( '' === $relative || false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) ) {
            return null;
        }

        return wp_normalize_path( $public_root . $relative );
    }

    /**
     * Resolve one existing user-managed text file that this plugin is allowed
     * to edit. Arbitrary paths are never accepted from a request.
     *
     * @return array<string,mixed>|WP_Error
     */
    private static function editable_text_target( string $type, int $post_id = 0 ) {
        $public_root = self::public_root();

        if ( 'llms' === $type ) {
            $file = wp_normalize_path( $public_root . 'llms.txt' );

            if ( ! self::is_safe_readable_file( $file, $public_root ) ) {
                return new WP_Error( 'missing_file', __( 'llms.txt was not found or is not readable.', 'bloglogistics-markdown-for-agents' ) );
            }

            return [
                'type'        => 'llms',
                'post_id'     => 0,
                'file'        => $file,
                'relative'    => 'llms.txt',
                'public_url'  => home_url( '/llms.txt' ),
                'label'       => 'llms.txt',
                'return_tab'  => 'llms',
                'return_view' => 'all',
            ];
        }

        if ( 'markdown' !== $type || $post_id < 1 ) {
            return new WP_Error( 'invalid_target', __( 'The requested Markdown file is not a valid editable target.', 'bloglogistics-markdown-for-agents' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) || 'publish' !== $post->post_status ) {
            return new WP_Error( 'invalid_post', __( 'The requested WordPress content is not an editable published Page or Post.', 'bloglogistics-markdown-for-agents' ) );
        }

        $markdown_url = (string) get_post_meta( $post_id, self::MARKDOWN_URL_META, true );
        if ( '' === $markdown_url ) {
            return new WP_Error( 'missing_mapping', __( 'No scanned Markdown file is mapped to this content item.', 'bloglogistics-markdown-for-agents' ) );
        }

        $file = self::local_file_from_url( $markdown_url, $public_root );
        if ( null === $file || 'md' !== strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) || ! self::is_safe_readable_file( $file, $public_root ) ) {
            return new WP_Error( 'unsafe_target', __( 'The mapped Markdown file could not be resolved safely inside the public site root.', 'bloglogistics-markdown-for-agents' ) );
        }

        $real_file = realpath( $file );
        $real_root = realpath( $public_root );
        if ( false === $real_file || false === $real_root ) {
            return new WP_Error( 'unsafe_target', __( 'The Markdown file path could not be verified.', 'bloglogistics-markdown-for-agents' ) );
        }

        $relative = ltrim( substr( wp_normalize_path( $real_file ), strlen( trailingslashit( wp_normalize_path( $real_root ) ) ) ), '/' );

        return [
            'type'        => 'markdown',
            'post_id'     => $post_id,
            'file'        => wp_normalize_path( $real_file ),
            'relative'    => $relative,
            'public_url'  => $markdown_url,
            'label'       => get_the_title( $post_id ),
            'return_tab'  => 'page' === $post->post_type ? 'pages' : 'posts',
            'return_view' => 'all',
        ];
    }

    /**
     * Create a timestamped private-ish backup beneath wp-content before the
     * plain text editor replaces a Markdown or llms.txt file.
     *
     * @return string|WP_Error Backup path on success.
     */
    private static function backup_text_file( string $file, string $relative ) {
        $backup_dir = trailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/bloglogistics-markdown-backups' ) );

        if ( ! is_dir( $backup_dir ) && ! wp_mkdir_p( $backup_dir ) ) {
            return new WP_Error( 'backup_dir', __( 'The Markdown backup directory could not be created.', 'bloglogistics-markdown-for-agents' ) );
        }

        if ( ! is_writable( $backup_dir ) ) {
            return new WP_Error( 'backup_dir', __( 'The Markdown backup directory is not writable.', 'bloglogistics-markdown-for-agents' ) );
        }

        $index_file = $backup_dir . 'index.php';
        if ( ! file_exists( $index_file ) ) {
            @file_put_contents( $index_file, "<?php\n// Silence is golden.\n", LOCK_EX );
        }

        $deny_file = $backup_dir . '.htaccess';
        if ( ! file_exists( $deny_file ) ) {
            $deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
            @file_put_contents( $deny_file, $deny, LOCK_EX );
        }

        $safe_name = sanitize_file_name( str_replace( '/', '-', trim( $relative, '/' ) ) );
        if ( '' === $safe_name ) {
            $safe_name = 'markdown';
        }

        $timestamp = wp_date( 'Ymd-His' );
        $hash      = substr( hash( 'sha256', wp_normalize_path( $file ) ), 0, 12 );
        $backup    = $backup_dir . $safe_name . '-' . $timestamp . '-' . $hash . '.bak';

        if ( ! @copy( $file, $backup ) ) {
            return new WP_Error( 'backup_failed', __( 'A backup could not be created, so the file was not changed.', 'bloglogistics-markdown-for-agents' ) );
        }

        return $backup;
    }

    /**
     * Atomically replace one existing text file after making a backup.
     *
     * @return true|WP_Error
     */
    private static function write_text_file_safely( array $target, string $contents, string $original_hash ) {
        $file = (string) $target['file'];

        if ( ! is_writable( $file ) || ! is_writable( dirname( $file ) ) ) {
            return new WP_Error( 'not_writable', __( 'The file or its directory is not writable. No changes were made.', 'bloglogistics-markdown-for-agents' ) );
        }

        $current = @file_get_contents( $file );
        if ( ! is_string( $current ) ) {
            return new WP_Error( 'read_failed', __( 'The current file could not be read. No changes were made.', 'bloglogistics-markdown-for-agents' ) );
        }

        if ( '' === $original_hash || ! hash_equals( hash( 'sha256', $current ), $original_hash ) ) {
            return new WP_Error( 'changed_on_disk', __( 'The file changed after the editor was opened. Your save was stopped to avoid overwriting newer changes. Reload the editor and try again.', 'bloglogistics-markdown-for-agents' ) );
        }

        // Store normalized UTF-8 text with LF line endings and no UTF-8 BOM.
        $contents = preg_replace( "/\r\n?|\n/", "\n", $contents );
        $contents = is_string( $contents ) ? $contents : '';
        if ( str_starts_with( $contents, "\xEF\xBB\xBF" ) ) {
            $contents = substr( $contents, 3 );
        }

        $valid_utf8 = function_exists( 'mb_check_encoding' ) ? mb_check_encoding( $contents, 'UTF-8' ) : ( 1 === preg_match( '//u', $contents ) );
        if ( ! $valid_utf8 ) {
            return new WP_Error( 'invalid_utf8', __( 'The submitted text is not valid UTF-8. No changes were made.', 'bloglogistics-markdown-for-agents' ) );
        }

        if ( strlen( $contents ) > self::VALIDATION_MAX_BYTES ) {
            return new WP_Error( 'too_large', __( 'The submitted file is larger than the plugin editing limit. No changes were made.', 'bloglogistics-markdown-for-agents' ) );
        }

        $backup = self::backup_text_file( $file, (string) $target['relative'] );
        if ( is_wp_error( $backup ) ) {
            return $backup;
        }

        $permissions = @fileperms( $file );
        $temp        = @tempnam( dirname( $file ), '.bl-mfa-edit-' );
        if ( false === $temp ) {
            return new WP_Error( 'temp_failed', __( 'A temporary file could not be created. The original file was not changed.', 'bloglogistics-markdown-for-agents' ) );
        }

        $written = @file_put_contents( $temp, $contents, LOCK_EX );
        if ( false === $written || $written !== strlen( $contents ) ) {
            @unlink( $temp );
            return new WP_Error( 'write_failed', __( 'The temporary file could not be written completely. The original file was not changed.', 'bloglogistics-markdown-for-agents' ) );
        }

        if ( is_int( $permissions ) ) {
            @chmod( $temp, $permissions & 0777 );
        }

        if ( ! @rename( $temp, $file ) ) {
            @unlink( $temp );
            return new WP_Error( 'replace_failed', __( 'The edited file could not replace the original atomically. The original file was not changed.', 'bloglogistics-markdown-for-agents' ) );
        }

        clearstatcache( true, $file );
        return true;
    }

    /**
     * Validate the user-managed llms.txt file and its local Markdown links.
     * External links are left alone. No external HTTP requests are made.
     *
     * @return array<string,mixed>
     */
    private static function validate_llms_file( string $public_root ): array {
        $llms_file = wp_normalize_path( $public_root . 'llms.txt' );
        $result    = [
            'exists'               => false,
            'valid'                => false,
            'encoding'             => [],
            'local_links_checked'  => 0,
            'broken_local_links'   => [],
            'duplicate_links'      => [],
            'local_links'          => [],
            'errors'               => [],
            'warnings'             => [],
        ];

        if ( ! self::is_safe_readable_file( $llms_file, $public_root ) ) {
            $result['errors'][] = [ 'code' => 'missing' ];
            return $result;
        }

        $result['exists']   = true;
        $result['encoding'] = self::validate_text_file( $llms_file, $public_root );

        foreach ( $result['encoding']['issues'] as $issue ) {
            if ( 'utf8_bom' === $issue ) {
                $result['warnings'][] = [ 'code' => 'utf8_bom' ];
            } else {
                $result['errors'][] = [ 'code' => 'encoding', 'value' => $issue ];
            }
        }

        if ( empty( $result['encoding']['checked'] ) || empty( $result['encoding']['valid_utf8'] ) ) {
            return $result;
        }

        $contents = @file_get_contents( $llms_file );

        if ( ! is_string( $contents ) ) {
            $result['errors'][] = [ 'code' => 'read_failed' ];
            return $result;
        }

        $contents_without_bom = str_starts_with( $contents, "\xEF\xBB\xBF" ) ? substr( $contents, 3 ) : $contents;

        if ( '' === trim( $contents_without_bom ) ) {
            $result['errors'][] = [ 'code' => 'empty' ];
            return $result;
        }

        $first_nonempty = '';
        foreach ( preg_split( '/\R/u', $contents_without_bom ) ?: [] as $line ) {
            if ( '' !== trim( $line ) ) {
                $first_nonempty = trim( $line );
                break;
            }
        }

        if ( '' === $first_nonempty || 1 !== preg_match( '/^#\s+\S/u', $first_nonempty ) ) {
            $result['warnings'][] = [ 'code' => 'missing_h1' ];
        }

        $matches = [];
        preg_match_all(
            '~\[[^\]\r\n]*\]\(\s*<?([^\s)>]+)>?(?:\s+["\'][^"\']*["\'])?\s*\)~u',
            $contents_without_bom,
            $matches
        );

        $seen = [];

        foreach ( $matches[1] ?? [] as $raw_url ) {
            $link = html_entity_decode( trim( (string) $raw_url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            if ( '' === $link ) {
                continue;
            }

            $first_seen = ! isset( $seen[ $link ] );

            if ( ! $first_seen ) {
                if ( ! in_array( $link, $result['duplicate_links'], true ) ) {
                    $result['duplicate_links'][] = $link;
                }
            } else {
                $seen[ $link ] = true;
            }

            $path = wp_parse_url( $link, PHP_URL_PATH );

            if ( ! is_string( $path ) || ! str_ends_with( strtolower( $path ), '.md' ) ) {
                continue;
            }

            $site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
            $link_host = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );

            if ( '' !== $link_host && '' !== $site_host && $link_host !== $site_host ) {
                continue;
            }

            $result['local_links_checked']++;
            $target = self::local_file_from_url( $link, $public_root );

            $target_exists = null !== $target && self::is_safe_readable_file( $target, $public_root );

            if ( $first_seen ) {
                $result['local_links'][] = [
                    'url'    => $link,
                    'exists' => $target_exists,
                ];
            }

            if ( ! $target_exists ) {
                $result['broken_local_links'][] = $link;
                $result['errors'][] = [ 'code' => 'broken_local_markdown', 'value' => $link ];
            }
        }

        foreach ( $result['duplicate_links'] as $duplicate ) {
            $result['warnings'][] = [ 'code' => 'duplicate_link', 'value' => $duplicate ];
        }

        $result['valid'] = empty( $result['errors'] );

        return $result;
    }

    /**
     * Convert an encoding issue code into an administrator-facing label.
     */
    private static function encoding_issue_label( string $issue ): string {
        return match ( $issue ) {
            'unreadable'             => __( 'File is not readable.', 'bloglogistics-markdown-for-agents' ),
            'size_unknown'           => __( 'File size could not be determined.', 'bloglogistics-markdown-for-agents' ),
            'too_large'              => __( 'File is larger than the 2 MB validation limit.', 'bloglogistics-markdown-for-agents' ),
            'read_failed'            => __( 'File contents could not be read.', 'bloglogistics-markdown-for-agents' ),
            'invalid_utf8'           => __( 'File is not valid UTF-8.', 'bloglogistics-markdown-for-agents' ),
            'replacement_character'  => __( 'Unicode replacement characters were detected.', 'bloglogistics-markdown-for-agents' ),
            'possible_mojibake'      => __( 'Possible mojibake or double-encoded punctuation was detected.', 'bloglogistics-markdown-for-agents' ),
            'utf8_bom'               => __( 'UTF-8 BOM detected. The file is readable, but BOM-free UTF-8 is preferred.', 'bloglogistics-markdown-for-agents' ),
            default                  => __( 'Unknown encoding validation issue.', 'bloglogistics-markdown-for-agents' ),
        };
    }

    /**
     * Convert a stored llms.txt validation issue into administrator-facing text.
     *
     * @param array<string,mixed> $issue Validation issue.
     */
    private static function llms_issue_label( array $issue ): string {
        $code  = isset( $issue['code'] ) ? (string) $issue['code'] : '';
        $value = isset( $issue['value'] ) ? (string) $issue['value'] : '';

        return match ( $code ) {
            'missing'               => __( 'llms.txt was not found or is not readable.', 'bloglogistics-markdown-for-agents' ),
            'read_failed'           => __( 'llms.txt could not be read for validation.', 'bloglogistics-markdown-for-agents' ),
            'empty'                 => __( 'llms.txt is empty.', 'bloglogistics-markdown-for-agents' ),
            'missing_h1'            => __( 'The first non-empty line is not an H1 heading. This is recommended for llms.txt.', 'bloglogistics-markdown-for-agents' ),
            'utf8_bom'              => __( 'llms.txt contains a UTF-8 BOM. It is readable, but BOM-free UTF-8 is preferred.', 'bloglogistics-markdown-for-agents' ),
            'encoding'              => sprintf(
                /* translators: %s: encoding validation message. */
                __( 'Encoding: %s', 'bloglogistics-markdown-for-agents' ),
                self::encoding_issue_label( $value )
            ),
            'broken_local_markdown' => sprintf(
                /* translators: %s: Markdown URL. */
                __( 'Referenced local Markdown file was not found: %s', 'bloglogistics-markdown-for-agents' ),
                $value
            ),
            'duplicate_link'        => sprintf(
                /* translators: %s: duplicated URL. */
                __( 'Duplicate link in llms.txt: %s', 'bloglogistics-markdown-for-agents' ),
                $value
            ),
            default                 => __( 'Unknown llms.txt validation issue.', 'bloglogistics-markdown-for-agents' ),
        };
    }

    /**
     * Confirm that a URL points back to this WordPress site's public host.
     */
    private static function is_same_site_url( string $url ): bool {
        $site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $scheme    = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

        return '' !== $site_host
            && $site_host === $url_host
            && in_array( $scheme, [ 'http', 'https' ], true );
    }

    /**
     * Classify the live Content-Type returned for a Markdown companion.
     * text/markdown is preferred. text/plain and legacy Markdown MIME types
     * remain usable but are surfaced as warnings rather than hard failures.
     */
    private static function markdown_mime_state( string $content_type ): string {
        $base_type = strtolower( trim( explode( ';', $content_type, 2 )[0] ?? '' ) );

        if ( '' === $base_type ) {
            return 'missing';
        }

        if ( 'text/markdown' === $base_type ) {
            return 'preferred';
        }

        if ( in_array( $base_type, [ 'text/plain', 'text/x-markdown', 'application/markdown' ], true ) ) {
            return 'warning';
        }

        return 'bad';
    }

    private static function markdown_mime_label( string $state, string $content_type ): string {
        $content_type = '' !== trim( $content_type ) ? trim( $content_type ) : __( 'No Content-Type header', 'bloglogistics-markdown-for-agents' );

        return match ( $state ) {
            'preferred' => sprintf(
                /* translators: %s: Content-Type response header. */
                __( 'Preferred (%s)', 'bloglogistics-markdown-for-agents' ),
                $content_type
            ),
            'warning'   => sprintf(
                /* translators: %s: Content-Type response header. */
                __( 'Usable, but text/markdown is preferred (%s)', 'bloglogistics-markdown-for-agents' ),
                $content_type
            ),
            'missing'   => __( 'Content-Type was not reported to the verifier', 'bloglogistics-markdown-for-agents' ),
            default     => sprintf(
                /* translators: %s: Content-Type response header. */
                __( 'Incorrect Markdown MIME type (%s)', 'bloglogistics-markdown-for-agents' ),
                $content_type
            ),
        };
    }

    private static function live_issue_label( string $issue ): string {
        return match ( $issue ) {
            'missing_stored_url'            => __( 'A stored page or Markdown URL is missing.', 'bloglogistics-markdown-for-agents' ),
            'html_request_error'            => __( 'The WordPress page request failed.', 'bloglogistics-markdown-for-agents' ),
            'markdown_request_error'        => __( 'The Markdown request failed.', 'bloglogistics-markdown-for-agents' ),
            'html_status'                   => __( 'The WordPress page did not return HTTP 200.', 'bloglogistics-markdown-for-agents' ),
            'markdown_status'               => __( 'The Markdown file did not return HTTP 200.', 'bloglogistics-markdown-for-agents' ),
            'markdown_mime'                 => __( 'The Markdown file returned an incorrect Content-Type.', 'bloglogistics-markdown-for-agents' ),
            'markdown_mime_unconfirmed'     => __( 'The Markdown file returned HTTP 200, but the verifier did not receive a Content-Type header.', 'bloglogistics-markdown-for-agents' ),
            'stale_cached_mime'             => __( 'Cached Markdown headers appear stale; a fresh recheck returned the preferred text/markdown Content-Type.', 'bloglogistics-markdown-for-agents' ),
            'missing_markdown_discovery'    => __( 'The expected rel="alternate" Markdown discovery link was not found.', 'bloglogistics-markdown-for-agents' ),
            'unexpected_markdown_discovery' => __( 'A Markdown discovery link is still present even though discovery is disabled.', 'bloglogistics-markdown-for-agents' ),
            'missing_llms_discovery'        => __( 'The expected llms.txt rel="describedby" link was not found.', 'bloglogistics-markdown-for-agents' ),
            'unexpected_llms_discovery'     => __( 'An llms.txt discovery link is present when it is not expected.', 'bloglogistics-markdown-for-agents' ),
            'stale_cached_discovery'        => __( 'Cached HTML appears stale; current uncached HTML contains the expected discovery links.', 'bloglogistics-markdown-for-agents' ),
            'discovery_recheck_failed'      => __( 'Discovery could not be confirmed with a cache-busting recheck.', 'bloglogistics-markdown-for-agents' ),
            'redirected'                    => __( 'A live endpoint redirected before returning its final response.', 'bloglogistics-markdown-for-agents' ),
            default                         => __( 'Unknown live verification issue.', 'bloglogistics-markdown-for-agents' ),
        };
    }

    /**
     * Extract link element attributes from an HTML response without requiring
     * DOM or XML extensions.
     *
     * @return array<int,array<string,string>>
     */
    private static function extract_html_link_elements( string $html ): array {
        $elements = [];
        $matches  = [];

        if ( 1 > preg_match_all( '~<link\b[^>]*>~i', $html, $matches ) ) {
            return $elements;
        }

        foreach ( $matches[0] as $tag ) {
            $attributes = [];
            $attr_match = [];

            preg_match_all(
                '~([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~',
                $tag,
                $attr_match,
                PREG_SET_ORDER
            );

            foreach ( $attr_match as $attribute ) {
                $name  = strtolower( (string) $attribute[1] );
                $value = '';

                if ( isset( $attribute[2] ) && '' !== $attribute[2] ) {
                    $value = $attribute[2];
                } elseif ( isset( $attribute[3] ) && '' !== $attribute[3] ) {
                    $value = $attribute[3];
                } elseif ( isset( $attribute[4] ) ) {
                    $value = $attribute[4];
                }

                $attributes[ $name ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            }

            if ( $attributes ) {
                $elements[] = $attributes;
            }
        }

        return $elements;
    }

    /**
     * Check the live HTML for the discovery markup expected from this plugin.
     *
     * @return array{passed:bool,markdown_found:bool,llms_found:bool,issues:array<int,string>}
     */
    private static function verify_discovery_markup( string $html, string $markdown_url, bool $expect_markdown, bool $expect_llms ): array {
        $markdown_found = false;
        $llms_found     = false;
        $llms_url       = home_url( '/llms.txt' );

        foreach ( self::extract_html_link_elements( $html ) as $link ) {
            $rel_tokens = isset( $link['rel'] )
                ? preg_split( '/\s+/', strtolower( trim( $link['rel'] ) ) )
                : [];
            $rel_tokens = is_array( $rel_tokens ) ? array_filter( $rel_tokens ) : [];
            $href       = isset( $link['href'] ) ? trim( $link['href'] ) : '';
            $type       = isset( $link['type'] ) ? strtolower( trim( explode( ';', $link['type'], 2 )[0] ) ) : '';

            if (
                in_array( 'alternate', $rel_tokens, true )
                && 'text/markdown' === $type
                && '' !== $href
                && untrailingslashit( $href ) === untrailingslashit( $markdown_url )
            ) {
                $markdown_found = true;
            }

            if (
                in_array( 'describedby', $rel_tokens, true )
                && '' !== $href
                && untrailingslashit( $href ) === untrailingslashit( $llms_url )
            ) {
                $llms_found = true;
            }
        }

        $issues = [];

        if ( $expect_markdown && ! $markdown_found ) {
            $issues[] = 'missing_markdown_discovery';
        } elseif ( ! $expect_markdown && $markdown_found ) {
            $issues[] = 'unexpected_markdown_discovery';
        }

        if ( $expect_llms && ! $llms_found ) {
            $issues[] = 'missing_llms_discovery';
        } elseif ( ! $expect_llms && $llms_found ) {
            $issues[] = 'unexpected_llms_discovery';
        }

        return [
            'passed'         => empty( $issues ),
            'markdown_found' => $markdown_found,
            'llms_found'     => $llms_found,
            'issues'         => $issues,
        ];
    }

    /**
     * Request a same-site URL while recording whether the original endpoint
     * redirected. Redirects are then followed by WordPress's safe HTTP client.
     *
     * @return array<string,mixed>
     */
    private static function live_request( string $url, int $response_limit, bool $bypass_cache = false ): array {
        $result = [
            'url'            => $url,
            'requested_url'  => $url,
            'cache_bypass'   => $bypass_cache,
            'initial_status' => 0,
            'status'         => 0,
            'redirected'     => false,
            'content_type'   => '',
            'body'           => '',
            'error'          => '',
        ];

        if ( ! self::is_same_site_url( $url ) ) {
            $result['error'] = __( 'The stored URL is not a same-site HTTP or HTTPS URL, so it was not requested.', 'bloglogistics-markdown-for-agents' );
            return $result;
        }

        $test_url = $url;
        $headers  = [];

        if ( $bypass_cache ) {
            $token    = rawurlencode( (string) time() . '-' . (string) wp_rand( 100000, 999999 ) );
            $test_url = add_query_arg( 'bloglogistics_mfa_live', $token, $url );
            $headers  = [
                'Cache-Control' => 'no-cache, no-store, max-age=0',
                'Pragma'        => 'no-cache',
            ];
        }

        $result['requested_url'] = $test_url;
        $common = [
            'timeout'             => 12,
            'reject_unsafe_urls'  => true,
            'limit_response_size' => $response_limit,
            'headers'             => $headers,
            'user-agent'          => 'Mozilla/5.0 (compatible; BlogLogistics-MFA-Live-Verification/' . BLOGLOGISTICS_MFA_VERSION . '; +' . home_url( '/' ) . ')',
        ];

        $initial = wp_safe_remote_get( $test_url, array_merge( $common, [ 'redirection' => 0 ] ) );

        if ( is_wp_error( $initial ) ) {
            $result['error'] = $initial->get_error_message();
            return $result;
        }

        $initial_status           = (int) wp_remote_retrieve_response_code( $initial );
        $result['initial_status'] = $initial_status;
        $result['redirected']     = $initial_status >= 300 && $initial_status < 400;
        $response                 = $initial;

        if ( $result['redirected'] ) {
            $response = wp_safe_remote_get( $test_url, array_merge( $common, [ 'redirection' => 5 ] ) );

            if ( is_wp_error( $response ) ) {
                $result['error'] = $response->get_error_message();
                return $result;
            }
        }

        $result['status']       = (int) wp_remote_retrieve_response_code( $response );
        $result['content_type'] = (string) wp_remote_retrieve_header( $response, 'content-type' );
        $body                   = wp_remote_retrieve_body( $response );
        $result['body']         = is_string( $body ) ? $body : '';

        return $result;
    }

    /**
     * Verify one detected WordPress/Markdown pair, including reachability,
     * redirects, Markdown MIME type, and front-end discovery markup.
     *
     * @return array<string,mixed>
     */
    private static function verify_live_item( int $post_id, bool $has_llms ): array {
        $markdown_url = (string) get_post_meta( $post_id, self::MARKDOWN_URL_META, true );
        $html_url     = get_permalink( $post_id );
        $disabled     = self::is_discovery_disabled( $post_id );
        $checked_at   = time();

        $result = [
            'post_id'                 => $post_id,
            'checked_at'              => $checked_at,
            'html_url'                => is_string( $html_url ) ? $html_url : '',
            'markdown_url'            => $markdown_url,
            'disabled'                => $disabled,
            'html_initial_status'     => 0,
            'html_status'             => 0,
            'html_redirected'         => false,
            'markdown_initial_status' => 0,
            'markdown_status'         => 0,
            'markdown_redirected'     => false,
            'content_type'            => '',
            'mime_state'              => 'missing',
            'markdown_fresh_content_type' => '',
            'markdown_fresh_mime_state'   => '',
            'markdown_mime_cache_state'   => 'not_checked',
            'markdown_recheck_status'     => 0,
            'expect_markdown'         => ! $disabled,
            'expect_llms'             => ! $disabled && $has_llms,
            'discovery'               => [],
            'discovery_fresh'         => [],
            'discovery_cache_state'   => 'not_checked',
            'discovery_recheck_status'=> 0,
            'delivery_issues'         => [],
            'delivery_warnings'       => [],
            'discovery_issues'        => [],
            'discovery_warnings'      => [],
            'issues'                  => [],
            'warnings'                => [],
            'delivery_passed'         => false,
            'discovery_passed'        => false,
            'passed'                  => false,
            'outdated'                => false,
        ];

        if ( ! is_string( $html_url ) || '' === $html_url || '' === $markdown_url ) {
            $result['delivery_issues'][] = 'missing_stored_url';
            $result['issues']            = $result['delivery_issues'];
            return $result;
        }

        // First test the canonical public URLs exactly as an agent would receive them.
        $html = self::live_request( $html_url, 1048576, false );
        $md   = self::live_request( $markdown_url, 131072, false );

        $result['html_initial_status']     = (int) $html['initial_status'];
        $result['html_status']             = (int) $html['status'];
        $result['html_redirected']         = ! empty( $html['redirected'] );
        $result['markdown_initial_status'] = (int) $md['initial_status'];
        $result['markdown_status']         = (int) $md['status'];
        $result['markdown_redirected']     = ! empty( $md['redirected'] );
        $result['content_type']            = (string) $md['content_type'];
        $result['mime_state']              = self::markdown_mime_state( (string) $md['content_type'] );

        if ( '' !== (string) $html['error'] ) {
            $result['delivery_issues'][] = 'html_request_error';
            $result['html_error']        = (string) $html['error'];
        } elseif ( 200 !== (int) $html['status'] ) {
            $result['delivery_issues'][] = 'html_status';
        }

        if ( '' !== (string) $md['error'] ) {
            $result['delivery_issues'][] = 'markdown_request_error';
            $result['markdown_error']    = (string) $md['error'];
        } elseif ( 200 !== (int) $md['status'] ) {
            $result['delivery_issues'][] = 'markdown_status';
        }

        if ( 200 === (int) $md['status'] ) {
            if ( in_array( $result['mime_state'], [ 'missing', 'bad' ], true ) ) {
                // Static files can retain old CDN/page-cache response headers even
                // after the Apache MIME rule has been corrected. Recheck once with
                // a unique query string before turning a good HTTP 200 into a red
                // delivery failure.
                $fresh_md = self::live_request( $markdown_url, 131072, true );
                $result['markdown_recheck_status'] = (int) $fresh_md['status'];

                if ( '' === (string) $fresh_md['error'] && 200 === (int) $fresh_md['status'] ) {
                    $result['markdown_fresh_content_type'] = (string) $fresh_md['content_type'];
                    $result['markdown_fresh_mime_state']   = self::markdown_mime_state( (string) $fresh_md['content_type'] );

                    if ( 'preferred' === $result['markdown_fresh_mime_state'] ) {
                        $result['markdown_mime_cache_state'] = 'stale_cache';
                        $result['delivery_warnings'][]       = 'stale_cached_mime';
                    } elseif ( 'warning' === $result['markdown_fresh_mime_state'] ) {
                        $result['markdown_mime_cache_state'] = 'fresh_warning';
                        $result['delivery_warnings'][]       = 'markdown_mime';
                    } elseif ( 'missing' === $result['markdown_fresh_mime_state'] ) {
                        $result['markdown_mime_cache_state'] = 'unconfirmed';
                        $result['delivery_warnings'][]       = 'markdown_mime_unconfirmed';
                    } else {
                        $result['markdown_mime_cache_state'] = 'bad';
                        $result['delivery_issues'][]         = 'markdown_mime';
                    }
                } elseif ( 'missing' === $result['mime_state'] ) {
                    $result['markdown_mime_cache_state'] = 'recheck_failed';
                    $result['delivery_warnings'][]       = 'markdown_mime_unconfirmed';
                } else {
                    $result['markdown_mime_cache_state'] = 'recheck_failed';
                    $result['delivery_issues'][]         = 'markdown_mime';
                }
            } elseif ( 'warning' === $result['mime_state'] ) {
                $result['delivery_warnings'][] = 'markdown_mime';
            }
        }

        if ( $result['html_redirected'] || $result['markdown_redirected'] ) {
            $result['delivery_warnings'][] = 'redirected';
        }

        if ( 200 === (int) $html['status'] ) {
            $result['discovery'] = self::verify_discovery_markup(
                (string) $html['body'],
                $markdown_url,
                (bool) $result['expect_markdown'],
                (bool) $result['expect_llms']
            );

            if ( ! empty( $result['discovery']['passed'] ) ) {
                $result['discovery_cache_state'] = 'canonical';
            } else {
                // If canonical HTML is missing expected markup, retry once with a
                // unique query string and no-cache request headers. This separates
                // genuinely missing discovery markup from stale page/CDN HTML.
                $fresh_html = self::live_request( $html_url, 1048576, true );
                $result['discovery_recheck_status'] = (int) $fresh_html['status'];

                if ( '' === (string) $fresh_html['error'] && 200 === (int) $fresh_html['status'] ) {
                    $result['discovery_fresh'] = self::verify_discovery_markup(
                        (string) $fresh_html['body'],
                        $markdown_url,
                        (bool) $result['expect_markdown'],
                        (bool) $result['expect_llms']
                    );

                    if ( ! empty( $result['discovery_fresh']['passed'] ) ) {
                        $result['discovery_cache_state'] = 'stale_cache';
                        $result['discovery_warnings'][]  = 'stale_cached_discovery';
                    } else {
                        $result['discovery_cache_state'] = 'missing';
                        // Discovery markup is important, but it is not the same as
                        // endpoint delivery. A missing discovery tag on otherwise
                        // reachable content is therefore a Review, not a red file
                        // delivery failure.
                        $result['discovery_warnings'] = array_values( array_map( 'strval', (array) $result['discovery_fresh']['issues'] ) );
                    }
                } else {
                    $result['discovery_cache_state'] = 'recheck_failed';
                    $result['discovery_warnings'][]  = 'discovery_recheck_failed';
                    if ( '' !== (string) $fresh_html['error'] ) {
                        $result['discovery_recheck_error'] = (string) $fresh_html['error'];
                    }
                }
            }
        }

        $result['delivery_issues']    = array_values( array_unique( $result['delivery_issues'] ) );
        $result['delivery_warnings']  = array_values( array_unique( $result['delivery_warnings'] ) );
        $result['discovery_issues']   = array_values( array_unique( $result['discovery_issues'] ) );
        $result['discovery_warnings'] = array_values( array_unique( $result['discovery_warnings'] ) );
        $result['issues']             = array_values( array_unique( array_merge( $result['delivery_issues'], $result['discovery_issues'] ) ) );
        $result['warnings']           = array_values( array_unique( array_merge( $result['delivery_warnings'], $result['discovery_warnings'] ) ) );
        $result['delivery_passed']    = empty( $result['delivery_issues'] );
        $result['discovery_passed']   = empty( $result['discovery_issues'] );
        $result['passed']             = $result['delivery_passed'] && $result['discovery_passed'];

        return $result;
    }

    /**
     * Run live public verification for all detected companions or a selected
     * subset. Results are stored only in the administrator health snapshot.
     *
     * @param array<int,int> $only_ids Optional selected post IDs.
     * @return array<string,mixed>
     */
    private static function verify_live_endpoints( array $only_ids = [] ): array {
        $only_ids = array_values( array_unique( array_filter( array_map( 'absint', $only_ids ) ) ) );
        $post_ids = get_posts(
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

        if ( $only_ids ) {
            $post_ids = array_values(
                array_filter(
                    $post_ids,
                    static fn ( $post_id ): bool => in_array( (int) $post_id, $only_ids, true )
                )
            );
        }

        $total_candidates = count( $post_ids );
        $truncated        = $total_candidates > self::LIVE_VERIFY_LIMIT;
        $post_ids         = array_slice( $post_ids, 0, self::LIVE_VERIFY_LIMIT );
        $has_llms         = '1' === get_option( self::LLMS_DETECTED_OPTION, '0' );
        $health           = get_option( self::HEALTH_OPTION, [] );
        $health           = is_array( $health ) ? $health : [];
        $previous_live    = isset( $health['live'] ) && is_array( $health['live'] ) ? $health['live'] : [];
        $health_items      = isset( $health['items'] ) && is_array( $health['items'] ) ? $health['items'] : [];
        $live_items       = $only_ids && isset( $previous_live['items'] ) && is_array( $previous_live['items'] )
            ? $previous_live['items']
            : [];

        $checked            = 0;
        $passed             = 0;
        $reviewed           = 0;
        $failed             = 0;
        $mime_warnings      = 0;
        $mime_failures      = 0;
        $discovery_failures = 0;
        $redirected         = 0;
        $request_errors     = 0;

        foreach ( $post_ids as $post_id ) {
            $item = self::verify_live_item( (int) $post_id, $has_llms );
            $scan_item = isset( $health_items[ (int) $post_id ] ) && is_array( $health_items[ (int) $post_id ] ) ? $health_items[ (int) $post_id ] : [];
            $item['scan_signature'] = isset( $scan_item['scan_signature'] ) ? (string) $scan_item['scan_signature'] : '';
            $item['outdated']       = false;
            unset( $item['outdated_at'] );
            $live_items[ (int) $post_id ] = $item;
            $checked++;

            if ( ! empty( $item['issues'] ) ) {
                $failed++;
            } elseif ( ! empty( $item['warnings'] ) ) {
                $reviewed++;
            } else {
                $passed++;
            }

            $item_delivery_issues   = isset( $item['delivery_issues'] ) && is_array( $item['delivery_issues'] ) ? array_map( 'strval', $item['delivery_issues'] ) : [];
            $item_delivery_warnings = isset( $item['delivery_warnings'] ) && is_array( $item['delivery_warnings'] ) ? array_map( 'strval', $item['delivery_warnings'] ) : [];

            if ( in_array( 'markdown_mime', $item_delivery_issues, true ) ) {
                $mime_failures++;
            } elseif (
                in_array( 'markdown_mime', $item_delivery_warnings, true )
                || in_array( 'markdown_mime_unconfirmed', $item_delivery_warnings, true )
                || in_array( 'stale_cached_mime', $item_delivery_warnings, true )
            ) {
                $mime_warnings++;
            }

            if ( ! empty( $item['discovery_issues'] ) || ! empty( $item['discovery_warnings'] ) ) {
                $discovery_failures++;
            }

            if ( ! empty( $item['html_redirected'] ) || ! empty( $item['markdown_redirected'] ) ) {
                $redirected++;
            }

            $issues = isset( $item['issues'] ) && is_array( $item['issues'] ) ? $item['issues'] : [];
            if ( in_array( 'html_request_error', $issues, true ) || in_array( 'markdown_request_error', $issues, true ) ) {
                $request_errors++;
            }
        }

        $outdated_count = 0;
        foreach ( $live_items as $saved_live_item ) {
            if ( is_array( $saved_live_item ) && ! empty( $saved_live_item['outdated'] ) ) {
                $outdated_count++;
            }
        }

        $live = [
            'verified_at'        => time(),
            'scope'              => $only_ids ? 'selected' : 'all',
            'candidate_count'    => $total_candidates,
            'checked'            => $checked,
            'passed'             => $passed,
            'reviewed'           => $reviewed,
            'failed'             => $failed,
            'mime_warnings'      => $mime_warnings,
            'mime_failures'      => $mime_failures,
            'discovery_failures' => $discovery_failures,
            'redirected'         => $redirected,
            'request_errors'     => $request_errors,
            'outdated_count'      => $outdated_count,
            'truncated'           => $truncated,
            'items'              => $live_items,
        ];

        $health['live'] = $live;
        update_option( self::HEALTH_OPTION, $health, false );

        return $live;
    }

    /**
     * Return administrator-facing server diagnostics without changing files.
     *
     * @return array<string,mixed>
     */
    private static function server_diagnostics(): array {
        $software      = isset( $_SERVER['SERVER_SOFTWARE'] ) ? trim( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';
        $software_low  = strtolower( $software );
        $server_type   = 'unknown';
        $public_root   = self::public_root();
        $htaccess_file = wp_normalize_path( $public_root . '.htaccess' );
        $mod_rewrite   = 'unknown';
        $mod_mime      = 'unknown';

        if ( false !== strpos( $software_low, 'litespeed' ) ) {
            $server_type = 'litespeed';
        } elseif ( false !== strpos( $software_low, 'apache' ) ) {
            $server_type = 'apache';
        } elseif ( false !== strpos( $software_low, 'nginx' ) ) {
            $server_type = 'nginx';
        } elseif ( false !== strpos( $software_low, 'microsoft-iis' ) || false !== strpos( $software_low, 'iis' ) ) {
            $server_type = 'iis';
        }

        if ( function_exists( 'apache_get_modules' ) ) {
            $modules = apache_get_modules();
            if ( is_array( $modules ) ) {
                $mod_rewrite = in_array( 'mod_rewrite', $modules, true ) ? 'enabled' : 'not_detected';
                $mod_mime    = in_array( 'mod_mime', $modules, true ) ? 'enabled' : 'not_detected';
            }
        }

        return [
            'software'              => $software,
            'server_type'           => $server_type,
            'supports_htaccess'     => self::server_supports_htaccess( $htaccess_file ),
            'mod_rewrite'           => $mod_rewrite,
            'mod_mime'              => $mod_mime,
            'htaccess_exists'       => file_exists( $htaccess_file ),
            'htaccess_readable'     => is_readable( $htaccess_file ),
            'htaccess_writable'     => file_exists( $htaccess_file ) ? is_writable( $htaccess_file ) : is_writable( dirname( $htaccess_file ) ),
            'public_root_writable'  => is_writable( $public_root ),
        ];
    }

    /**
     * Return timestamped .htaccess backups created by this plugin.
     *
     * @return array<int,array{name:string,path:string,mtime:int,size:int}>
     */
    private static function htaccess_backups(): array {
        $public_root = self::public_root();
        $candidates  = glob( $public_root . '.htaccess.bloglogistics-mfa-backup-*' );
        $backups     = [];

        if ( ! is_array( $candidates ) ) {
            return $backups;
        }

        foreach ( $candidates as $candidate ) {
            $candidate = wp_normalize_path( (string) $candidate );
            $name      = basename( $candidate );

            if ( 1 !== preg_match( '/^\.htaccess\.bloglogistics-mfa-backup-\d{8}-\d{6}(?:-\d+)?$/', $name ) ) {
                continue;
            }

            if ( ! is_file( $candidate ) ) {
                continue;
            }

            $backups[] = [
                'name'  => $name,
                'path'  => $candidate,
                'mtime' => (int) @filemtime( $candidate ),
                'size'  => (int) @filesize( $candidate ),
            ];
        }

        usort(
            $backups,
            static function ( array $a, array $b ): int {
                if ( $a['mtime'] === $b['mtime'] ) {
                    return strcmp( $b['name'], $a['name'] );
                }

                return $b['mtime'] <=> $a['mtime'];
            }
        );

        return $backups;
    }

    /**
     * Delete only plugin-created backups older than the newest retained set.
     *
     * @return array{deleted:int,failed:int,retained:int}
     */
    private static function cleanup_old_htaccess_backups(): array {
        $backups = self::htaccess_backups();
        $old     = array_slice( $backups, self::BACKUP_RETAIN_COUNT );
        $deleted = 0;
        $failed  = 0;

        foreach ( $old as $backup ) {
            if ( @unlink( $backup['path'] ) ) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        return [
            'deleted'  => $deleted,
            'failed'   => $failed,
            'retained' => min( count( $backups ), self::BACKUP_RETAIN_COUNT ),
        ];
    }

    /**
     * Return the exact .htaccess block managed by this plugin.
     */
    private static function htaccess_rule_block(): string {
        return implode(
            "\n",
            [
                '# ======================================================================',
                '# Allow WordPress pages to coexist with Markdown companion directories',
                '# Added by the plugin: BlogLogistics Markdown for Agents',
                '# ======================================================================',
                '<IfModule mod_rewrite.c>',
                '    RewriteEngine On',
                '',
                '    RewriteCond %{REQUEST_FILENAME} -d',
                '    RewriteCond %{REQUEST_FILENAME}/index.md -f',
                '    RewriteRule ^.+/?$ index.php [L]',
                '</IfModule>',
                '# ======================================================================',
            ]
        );
    }

    /**
     * Return the exact Markdown MIME block managed by this plugin.
     */
    private static function htaccess_mime_rule_block(): string {
        return implode(
            "\n",
            [
                '# ======================================================================',
                '# Serve Markdown companion files with the correct MIME type',
                '# Added by the plugin: BlogLogistics Markdown for Agents',
                '# ======================================================================',
                '<IfModule mod_mime.c>',
                '    AddType text/markdown .md',
                '    AddCharset UTF-8 .md',
                '</IfModule>',
                '# ======================================================================',
            ]
        );
    }

    /**
     * Determine whether the current server is expected to honour .htaccess.
     */
    private static function server_supports_htaccess( string $htaccess_file ): bool {
        $software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';

        if ( false !== strpos( $software, 'litespeed' ) || false !== strpos( $software, 'apache' ) ) {
            return true;
        }

        if ( function_exists( 'apache_get_modules' ) ) {
            $modules = apache_get_modules();

            if ( is_array( $modules ) && in_array( 'mod_rewrite', $modules, true ) ) {
                return true;
            }
        }

        // If the server identity is unavailable but WordPress already has a
        // root .htaccess file, allow a cautious attempt and rely on the live
        // verification step to confirm whether the rule is actually honoured.
        return '' === $software && file_exists( $htaccess_file );
    }

    private static function normalise_newlines( string $contents ): string {
        return str_replace( [ "\r\n", "\r" ], "\n", $contents );
    }

    private static function detect_eol( string $contents ): string {
        return false !== strpos( $contents, "\r\n" ) ? "\r\n" : "\n";
    }

    /**
     * Check whether the compatibility directives already exist anywhere in
     * .htaccess. Comments, blank lines, and the plugin's banner text are not
     * part of the test. This deliberately respects an equivalent rule that a
     * site owner or another tool has already installed.
     */
    private static function htaccess_rule_exists( string $contents ): bool {
        $normalised = self::normalise_newlines( $contents );
        $lines      = explode( "\n", $normalised );
        $directives = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );

            if ( '' === $line || str_starts_with( $line, '#' ) ) {
                continue;
            }

            $directives[] = $line;
        }

        $count = count( $directives );

        for ( $i = 0; $i <= $count - 3; $i++ ) {
            $directory_condition = (bool) preg_match(
                '~^RewriteCond\s+%\{REQUEST_FILENAME\}\s+-d(?:\s+\[[^\]]+\])?$~i',
                $directives[ $i ]
            );

            if ( ! $directory_condition ) {
                continue;
            }

            $markdown_condition = (bool) preg_match(
                '~^RewriteCond\s+%\{REQUEST_FILENAME\}/index\.md\s+-f(?:\s+\[[^\]]+\])?$~i',
                $directives[ $i + 1 ]
            );

            $wordpress_rule = (bool) preg_match(
                '~^RewriteRule\s+\^\.\+/\?\$\s+/?index\.php\s+\[[^\]]*\bL\b[^\]]*\]$~i',
                $directives[ $i + 2 ]
            );

            if ( $markdown_condition && $wordpress_rule ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the required Markdown MIME directives already exist.
     *
     * Comments, blank lines, placement, and surrounding IfModule wrappers are
     * deliberately ignored. A manually installed equivalent is respected and
     * will never be duplicated.
     */
    private static function htaccess_mime_rule_exists( string $contents ): bool {
        $normalised  = self::normalise_newlines( $contents );
        $has_type    = false;
        $has_charset = false;

        foreach ( explode( "\n", $normalised ) as $line ) {
            $line = trim( (string) preg_replace( '~\s+#.*$~', '', $line ) );

            if ( '' === $line || str_starts_with( $line, '#' ) ) {
                continue;
            }

            $parts = preg_split( '~\s+~', $line );

            if ( ! is_array( $parts ) || count( $parts ) < 3 ) {
                continue;
            }

            $directive = strtolower( (string) $parts[0] );
            $value     = strtolower( (string) $parts[1] );
            $targets   = array_map( 'strtolower', array_slice( $parts, 2 ) );

            if ( 'addtype' === $directive && 'text/markdown' === $value && in_array( '.md', $targets, true ) ) {
                $has_type = true;
            }

            if ( 'addcharset' === $directive && 'utf-8' === $value && in_array( '.md', $targets, true ) ) {
                $has_charset = true;
            }
        }

        return $has_type && $has_charset;
    }

    /**
     * Insert only the missing managed .htaccess blocks without altering the
     * surrounding content more than necessary.
     */
    private static function build_htaccess_contents( string $contents ): string {
        $blocks = [];

        if ( ! self::htaccess_rule_exists( $contents ) ) {
            $blocks[] = self::htaccess_rule_block();
        }

        if ( ! self::htaccess_mime_rule_exists( $contents ) ) {
            $blocks[] = self::htaccess_mime_rule_block();
        }

        if ( ! $blocks ) {
            return $contents;
        }

        $eol           = self::detect_eol( $contents );
        $normalised    = self::normalise_newlines( $contents );
        $managed_block = implode( "\n\n", $blocks );
        $normalised    = ltrim( $normalised, "\n" );
        $wp_pos        = strpos( $normalised, '# BEGIN WordPress' );

        if ( false !== $wp_pos ) {
            $before = rtrim( substr( $normalised, 0, $wp_pos ), "\n" );
            $after  = ltrim( substr( $normalised, $wp_pos ), "\n" );

            $new_contents = '' !== $before
                ? $before . "\n\n" . $managed_block . "\n\n" . $after
                : $managed_block . "\n\n" . $after;
        } else {
            $new_contents = '' !== trim( $normalised )
                ? $managed_block . "\n\n" . ltrim( $normalised, "\n" )
                : $managed_block . "\n";
        }

        if ( "\n" !== $eol ) {
            $new_contents = str_replace( "\n", $eol, $new_contents );
        }

        return $new_contents;
    }

    /**
     * Create a timestamped backup beside the live .htaccess file.
     *
     * @return array{success:bool,path:string,message:string}
     */
    private static function backup_htaccess( string $htaccess_file, string $contents ): array {
        $timestamp = wp_date( 'Ymd-His' );
        $base_path = $htaccess_file . '.bloglogistics-mfa-backup-' . $timestamp;
        $backup    = $base_path;
        $suffix    = 2;

        while ( file_exists( $backup ) ) {
            $backup = $base_path . '-' . $suffix;
            $suffix++;
        }

        $written = @file_put_contents( $backup, $contents, LOCK_EX );

        if ( false === $written || strlen( $contents ) !== $written ) {
            return [
                'success' => false,
                'path'    => '',
                'message' => __( 'The existing .htaccess file could not be backed up, so no changes were made.', 'bloglogistics-markdown-for-agents' ),
            ];
        }

        $backup_contents = @file_get_contents( $backup );

        if ( ! is_string( $backup_contents ) || $backup_contents !== $contents ) {
            @unlink( $backup );

            return [
                'success' => false,
                'path'    => '',
                'message' => __( 'The .htaccess backup could not be verified, so no changes were made.', 'bloglogistics-markdown-for-agents' ),
            ];
        }

        if ( file_exists( $htaccess_file ) ) {
            $mode = @fileperms( $htaccess_file );

            if ( false !== $mode ) {
                @chmod( $backup, $mode & 0777 );
            }
        }

        return [
            'success' => true,
            'path'    => $backup,
            'message' => '',
        ];
    }

    /**
     * Find a published WordPress page/post whose Markdown companion creates a
     * real directory. The homepage is intentionally excluded because /index.md
     * does not create the permalink/directory collision this rule solves.
     *
     * @return array{html_url:string,markdown_url:string}|null
     */
    private static function verification_target(): ?array {
        $public_root = self::public_root();
        $front_page  = ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_on_front' ) : 0;
        $post_ids    = get_posts(
            [
                'post_type'              => [ 'post', 'page' ],
                'post_status'            => 'publish',
                'numberposts'            => -1,
                'fields'                 => 'ids',
                'meta_key'               => self::MARKDOWN_URL_META,
                'meta_compare'           => 'EXISTS',
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            ]
        );

        foreach ( $post_ids as $post_id ) {
            if ( $front_page && $front_page === (int) $post_id ) {
                continue;
            }

            $html_url     = get_permalink( $post_id );
            $markdown_url = (string) get_post_meta( $post_id, self::MARKDOWN_URL_META, true );

            if ( ! is_string( $html_url ) || '' === $html_url || '' === $markdown_url ) {
                continue;
            }

            $relative = self::relative_path_from_permalink( $html_url );

            if ( null === $relative || '' === $relative ) {
                continue;
            }

            $directory     = wp_normalize_path( $public_root . trailingslashit( $relative ) );
            $markdown_file = $directory . 'index.md';

            if ( is_dir( $directory ) && self::is_safe_readable_file( $markdown_file, $public_root ) ) {
                return [
                    'html_url'     => $html_url,
                    'markdown_url' => $markdown_url,
                ];
            }
        }

        return null;
    }

    /**
     * Perform an end-to-end public request check. A unique query parameter is
     * used so a stale page cache is less likely to hide the current result.
     *
     * @return array{available:bool,passed:bool,html_url:string,markdown_url:string,html_status:int,markdown_status:int,markdown_content_type:string,mime_live_verified:bool,message:string}
     */
    private static function verify_htaccess_live(): array {
        $target = self::verification_target();

        if ( null === $target ) {
            return [
                'available'             => false,
                'passed'                => false,
                'html_url'              => '',
                'markdown_url'          => '',
                'html_status'           => 0,
                'markdown_status'       => 0,
                'markdown_content_type' => '',
                'mime_live_verified'    => false,
                'message'               => __( 'The .htaccess rules are present, but no non-homepage Markdown file is currently recorded for an end-to-end live test. Run the Markdown scan after uploading Markdown files.', 'bloglogistics-markdown-for-agents' ),
            ];
        }

        $token        = rawurlencode( (string) time() . '-' . (string) wp_rand( 100000, 999999 ) );
        $html_test    = add_query_arg( 'bloglogistics_mfa_verify', $token, $target['html_url'] );
        $md_test      = add_query_arg( 'bloglogistics_mfa_verify', $token, $target['markdown_url'] );
        $request_args = [
            'timeout'             => 12,
            'redirection'         => 5,
            'reject_unsafe_urls'  => true,
            'limit_response_size' => 262144,
            'headers'             => [
                'Cache-Control' => 'no-cache',
            ],
            'user-agent'          => 'Mozilla/5.0 (compatible; BlogLogistics-MFA-Verification/' . BLOGLOGISTICS_MFA_VERSION . '; +' . home_url( '/' ) . ')',
        ];

        $html_response = wp_remote_get( $html_test, $request_args );

        if ( is_wp_error( $html_response ) ) {
            return [
                'available'             => true,
                'passed'                => false,
                'html_url'              => $target['html_url'],
                'markdown_url'          => $target['markdown_url'],
                'html_status'           => 0,
                'markdown_status'       => 0,
                'markdown_content_type' => '',
                'mime_live_verified'    => false,
                'message'               => sprintf(
                    /* translators: %s: HTTP request error. */
                    __( 'The .htaccess rules are present, but the public HTML verification request failed: %s', 'bloglogistics-markdown-for-agents' ),
                    $html_response->get_error_message()
                ),
            ];
        }

        $html_status = (int) wp_remote_retrieve_response_code( $html_response );
        $md_response = wp_remote_get( $md_test, $request_args );

        if ( is_wp_error( $md_response ) ) {
            return [
                'available'             => true,
                'passed'                => false,
                'html_url'              => $target['html_url'],
                'markdown_url'          => $target['markdown_url'],
                'html_status'           => $html_status,
                'markdown_status'       => 0,
                'markdown_content_type' => '',
                'mime_live_verified'    => false,
                'message'               => sprintf(
                    /* translators: %s: HTTP request error. */
                    __( 'The HTML page responded, but the Markdown verification request failed: %s', 'bloglogistics-markdown-for-agents' ),
                    $md_response->get_error_message()
                ),
            ];
        }

        $markdown_status       = (int) wp_remote_retrieve_response_code( $md_response );
        $markdown_content_type = (string) wp_remote_retrieve_header( $md_response, 'content-type' );
        $mime_live_verified    = 'preferred' === self::markdown_mime_state( $markdown_content_type );
        $http_passed           = 200 === $html_status && 200 === $markdown_status;
        $passed                = $http_passed && $mime_live_verified;

        if ( $passed ) {
            $message = __( 'Live verification passed: the WordPress page and Markdown file returned HTTP 200, and the Markdown file returned the preferred text/markdown Content-Type.', 'bloglogistics-markdown-for-agents' );
        } elseif ( ! $http_passed ) {
            $message = sprintf(
                /* translators: 1: HTML status code, 2: Markdown status code. */
                __( 'Live verification did not pass. The WordPress page returned HTTP %1$d and the Markdown file returned HTTP %2$d.', 'bloglogistics-markdown-for-agents' ),
                $html_status,
                $markdown_status
            );
        } else {
            $content_type_label = '' !== trim( $markdown_content_type )
                ? trim( $markdown_content_type )
                : __( 'No Content-Type header', 'bloglogistics-markdown-for-agents' );

            $message = sprintf(
                /* translators: %s: Content-Type response header or no-header label. */
                __( 'Both endpoints returned HTTP 200, but the Markdown MIME rule is not live yet. The Markdown file returned: %s.', 'bloglogistics-markdown-for-agents' ),
                $content_type_label
            );
        }

        return [
            'available'             => true,
            'passed'                => $passed,
            'html_url'              => $target['html_url'],
            'markdown_url'          => $target['markdown_url'],
            'html_status'           => $html_status,
            'markdown_status'       => $markdown_status,
            'markdown_content_type' => $markdown_content_type,
            'mime_live_verified'    => $mime_live_verified,
            'message'               => $message,
        ];
    }

    /**
     * Install or repair the .htaccess compatibility and Markdown MIME rules,
     * backing up the existing file before every write and verifying the result
     * afterwards.
     *
     * @param bool $verify_live Whether to perform a public end-to-end check.
     * @return array<string,mixed>
     */
    private static function ensure_htaccess_rule( bool $verify_live = true ): array {
        $public_root   = self::public_root();
        $htaccess_file = wp_normalize_path( $public_root . '.htaccess' );
        $status        = [
            'state'                 => 'unknown',
            'message'               => '',
            'htaccess_file'         => $htaccess_file,
            'backup_file'           => '',
            'file_verified'         => false,
            'rewrite_rule_verified' => false,
            'mime_rule_verified'    => false,
            'live_available'        => false,
            'live_verified'         => false,
            'mime_live_verified'    => false,
            'html_url'              => '',
            'markdown_url'          => '',
            'html_status'           => 0,
            'markdown_status'       => 0,
            'markdown_content_type' => '',
            'verified_at'           => time(),
        ];

        if ( ! self::server_supports_htaccess( $htaccess_file ) ) {
            $status['state']   = 'unsupported';
            $status['message'] = __( 'This server does not appear to use Apache-compatible .htaccess rules. No .htaccess file was changed.', 'bloglogistics-markdown-for-agents' );
            update_option( self::HTACCESS_STATUS_OPTION, $status, false );
            update_option( self::HTACCESS_NOTICE_OPTION, '1', false );
            return $status;
        }

        $exists   = file_exists( $htaccess_file );
        $contents = '';

        if ( $exists ) {
            if ( ! is_readable( $htaccess_file ) ) {
                $status['state']   = 'failed';
                $status['message'] = __( 'The website .htaccess file is not readable, so the required Markdown rules could not be checked or installed.', 'bloglogistics-markdown-for-agents' );
                update_option( self::HTACCESS_STATUS_OPTION, $status, false );
                update_option( self::HTACCESS_NOTICE_OPTION, '1', false );
                return $status;
            }

            $read = @file_get_contents( $htaccess_file );

            if ( ! is_string( $read ) ) {
                $status['state']   = 'failed';
                $status['message'] = __( 'The website .htaccess file could not be read, so no changes were made.', 'bloglogistics-markdown-for-agents' );
                update_option( self::HTACCESS_STATUS_OPTION, $status, false );
                update_option( self::HTACCESS_NOTICE_OPTION, '1', false );
                return $status;
            }

            $contents = $read;
        }

        $rewrite_present = self::htaccess_rule_exists( $contents );
        $mime_present    = self::htaccess_mime_rule_exists( $contents );
        $needs_write     = ! $rewrite_present || ! $mime_present;

        if ( $needs_write ) {
            if ( $exists && ! is_writable( $htaccess_file ) ) {
                $status['state']   = 'failed';
                $status['message'] = __( 'The website .htaccess file is not writable. No changes were made.', 'bloglogistics-markdown-for-agents' );
                update_option( self::HTACCESS_STATUS_OPTION, $status, false );
                update_option( self::HTACCESS_NOTICE_OPTION, '1', false );
                return $status;
            }

            if ( ! $exists && ! is_writable( dirname( $htaccess_file ) ) ) {
                $status['state']   = 'failed';
                $status['message'] = __( 'The website root is not writable, so the required Markdown .htaccess rules could not be created.', 'bloglogistics-markdown-for-agents' );
                update_option( self::HTACCESS_STATUS_OPTION, $status, false );
                update_option( self::HTACCESS_NOTICE_OPTION, '1', false );
                return $status;
            }

            if ( $exists ) {
                $backup = self::backup_htaccess( $htaccess_file, $contents );

                if ( ! $backup['success'] ) {
                    $status['state']   = 'failed';
                    $status['message'] = $backup['message'];
                    update_option( self::HTACCESS_STATUS_OPTION, $status, false );
                    update_option( self::HTACCESS_NOTICE_OPTION, '1', false );
                    return $status;
                }

                $status['backup_file'] = $backup['path'];
            }

            $new_contents = self::build_htaccess_contents( $contents );
            $written      = @file_put_contents( $htaccess_file, $new_contents, LOCK_EX );

            if ( false === $written || strlen( $new_contents ) !== $written ) {
                if ( $exists && '' !== $status['backup_file'] ) {
                    @file_put_contents( $htaccess_file, $contents, LOCK_EX );
                } elseif ( ! $exists ) {
                    @unlink( $htaccess_file );
                }

                $status['state']   = 'failed';
                $status['message'] = __( 'The required Markdown rules could not be written to .htaccess. The original file was restored when possible.', 'bloglogistics-markdown-for-agents' );
                update_option( self::HTACCESS_STATUS_OPTION, $status, false );
                update_option( self::HTACCESS_NOTICE_OPTION, '1', false );
                return $status;
            }
        }

        clearstatcache( true, $htaccess_file );
        $saved_contents          = @file_get_contents( $htaccess_file );
        $rewrite_rule_verified   = is_string( $saved_contents ) && self::htaccess_rule_exists( $saved_contents );
        $mime_rule_verified      = is_string( $saved_contents ) && self::htaccess_mime_rule_exists( $saved_contents );
        $file_verified           = $rewrite_rule_verified && $mime_rule_verified;

        if ( ! $file_verified ) {
            if ( $needs_write && $exists && '' !== $status['backup_file'] ) {
                @file_put_contents( $htaccess_file, $contents, LOCK_EX );
            } elseif ( $needs_write && ! $exists ) {
                @unlink( $htaccess_file );
            }

            $status['state']   = 'failed';
            $status['message'] = __( 'The .htaccess write could not be verified. The original file was restored when possible.', 'bloglogistics-markdown-for-agents' );
            update_option( self::HTACCESS_STATUS_OPTION, $status, false );
            update_option( self::HTACCESS_NOTICE_OPTION, '1', false );
            return $status;
        }

        $status['file_verified']         = true;
        $status['rewrite_rule_verified'] = true;
        $status['mime_rule_verified']    = true;
        $status['state']                 = $needs_write ? 'installed' : 'already_present';

        if ( ! $rewrite_present && ! $mime_present ) {
            $status['message'] = __( 'The Markdown permalink compatibility and MIME rules were added to .htaccess and confirmed in the saved file.', 'bloglogistics-markdown-for-agents' );
        } elseif ( ! $rewrite_present ) {
            $status['message'] = __( 'The Markdown permalink compatibility rule was added to .htaccess and confirmed in the saved file. An equivalent Markdown MIME rule was already present and was not duplicated.', 'bloglogistics-markdown-for-agents' );
        } elseif ( ! $mime_present ) {
            $status['message'] = __( 'The Markdown MIME rule was added to .htaccess and confirmed in the saved file. An equivalent permalink compatibility rule was already present and was not duplicated.', 'bloglogistics-markdown-for-agents' );
        } else {
            $status['message'] = __( 'Equivalent Markdown permalink compatibility and MIME rules are already present in .htaccess. No changes were made.', 'bloglogistics-markdown-for-agents' );
        }

        if ( $verify_live ) {
            $live = self::verify_htaccess_live();

            $status['live_available']        = $live['available'];
            $status['live_verified']         = $live['passed'];
            $status['mime_live_verified']    = $live['mime_live_verified'];
            $status['html_url']              = $live['html_url'];
            $status['markdown_url']          = $live['markdown_url'];
            $status['html_status']           = $live['html_status'];
            $status['markdown_status']       = $live['markdown_status'];
            $status['markdown_content_type'] = $live['markdown_content_type'];
            $status['message']              .= ' ' . $live['message'];

            if ( $live['available'] && ! $live['passed'] ) {
                $status['state'] = 'verification_failed';
            }
        }

        update_option( self::HTACCESS_STATUS_OPTION, $status, false );
        update_option( self::HTACCESS_NOTICE_OPTION, '1', false );

        return $status;
    }

    /**
     * Show one post-update/activation notice describing the .htaccess result.
     */
    public static function render_htaccess_admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) || '1' !== get_option( self::HTACCESS_NOTICE_OPTION, '0' ) ) {
            return;
        }

        $status = get_option( self::HTACCESS_STATUS_OPTION, [] );

        if ( ! is_array( $status ) || empty( $status['message'] ) ) {
            delete_option( self::HTACCESS_NOTICE_OPTION );
            return;
        }

        $state = isset( $status['state'] ) ? (string) $status['state'] : 'unknown';
        $class = in_array( $state, [ 'installed', 'already_present' ], true )
            ? 'notice notice-success is-dismissible'
            : ( 'verification_failed' === $state ? 'notice notice-warning is-dismissible' : 'notice notice-error is-dismissible' );

        echo '<div class="' . esc_attr( $class ) . '"><p><strong>' . esc_html__( 'BlogLogistics Markdown for Agents:', 'bloglogistics-markdown-for-agents' ) . '</strong> ' . esc_html( (string) $status['message'] );

        if ( ! empty( $status['backup_file'] ) ) {
            echo ' ';
            printf(
                /* translators: %s: backup filename. */
                esc_html__( 'Backup created: %s.', 'bloglogistics-markdown-for-agents' ),
                esc_html( basename( (string) $status['backup_file'] ) )
            );
        }

        echo '</p></div>';
        delete_option( self::HTACCESS_NOTICE_OPTION );
    }

    /**
     * Scan for user-created Markdown companions, refresh stored discovery URLs,
     * and build the administrator-only health snapshot.
     *
     * The scan is incremental by default. It still checks file existence,
     * timestamps, and sizes so additions/removals are detected, but it reuses
     * the previous encoding result when the relevant page/file signature has
     * not changed. A full or selected forced validation can bypass that cache.
     *
     * @param bool           $force_all Force content validation for every companion.
     * @param array<int,int> $force_ids Force validation for selected post IDs.
     * @return array<string,mixed>
     */
    private static function scan_markdown_files( bool $force_all = false, array $force_ids = [] ): array {
        $public_root       = self::public_root();
        $front_page        = ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_on_front' ) : 0;
        $previous_health   = get_option( self::HEALTH_OPTION, [] );
        $previous_health   = is_array( $previous_health ) ? $previous_health : [];
        $previous_items    = isset( $previous_health['items'] ) && is_array( $previous_health['items'] ) ? $previous_health['items'] : [];
        $previous_llms     = isset( $previous_health['llms'] ) && is_array( $previous_health['llms'] ) ? $previous_health['llms'] : [];
        $force_ids         = array_values( array_unique( array_filter( array_map( 'absint', $force_ids ) ) ) );
        $force_lookup      = array_fill_keys( $force_ids, true );
        $checked           = 0;
        $found             = 0;
        $removed           = 0;
        $missing           = 0;
        $stale             = 0;
        $encoding_issues   = 0;
        $encoding_warnings = 0;
        $excluded          = 0;
        $validated         = 0;
        $reused            = 0;
        $changed           = 0;
        $items             = [];

        $post_ids = get_posts(
            [
                'post_type'              => [ 'post', 'page' ],
                'post_status'            => 'publish',
                'numberposts'            => -1,
                'fields'                 => 'ids',
                'orderby'                => 'title',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'suppress_filters'       => false,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            ]
        );

        foreach ( $post_ids as $post_id ) {
            $post_id  = (int) $post_id;
            $checked++;
            $disabled = self::is_discovery_disabled( $post_id );

            if ( $disabled ) {
                $excluded++;
            }

            $permalink = get_permalink( $post_id );

            if ( ! is_string( $permalink ) || '' === $permalink ) {
                continue;
            }

            if ( $front_page && $front_page === $post_id ) {
                $relative_markdown = 'index.md';
                $markdown_url      = home_url( '/index.md' );
            } else {
                $relative = self::relative_path_from_permalink( $permalink );

                if ( null === $relative || '' === $relative ) {
                    continue;
                }

                $relative_markdown = trailingslashit( $relative ) . 'index.md';
                $markdown_url      = trailingslashit( $permalink ) . 'index.md';
            }

            $markdown_file = wp_normalize_path( $public_root . ltrim( $relative_markdown, '/' ) );
            $exists        = self::is_safe_readable_file( $markdown_file, $public_root );
            $md_modified   = $exists ? (int) @filemtime( $markdown_file ) : 0;
            $md_size       = $exists ? (int) @filesize( $markdown_file ) : 0;
            $post_modified = (int) get_post_modified_time( 'U', true, $post_id );
            $is_stale      = $exists && $post_modified > 0 && $md_modified > 0 && $post_modified > ( $md_modified + 60 );
            $signature     = hash(
                'sha256',
                wp_json_encode(
                    [
                        'relative'      => $relative_markdown,
                        'permalink'     => $permalink,
                        'post_modified' => $post_modified,
                        'exists'        => $exists,
                        'md_modified'   => $md_modified,
                        'md_size'       => $md_size,
                        'disabled'      => $disabled,
                    ]
                ) ?: ''
            );
            $previous      = isset( $previous_items[ $post_id ] ) && is_array( $previous_items[ $post_id ] ) ? $previous_items[ $post_id ] : [];
            $previous_sig  = isset( $previous['scan_signature'] ) ? (string) $previous['scan_signature'] : '';
            $force_item    = $force_all || isset( $force_lookup[ $post_id ] );
            $unchanged     = ! $force_item && '' !== $previous_sig && hash_equals( $previous_sig, $signature );
            $encoding      = [];

            if ( $force_item || ! $unchanged ) {
                $changed++;
            }

            if ( $exists ) {
                $expected_markdown_url = esc_url_raw( $markdown_url );
                $stored_markdown_url   = (string) get_post_meta( $post_id, self::MARKDOWN_URL_META, true );

                if ( $stored_markdown_url !== $expected_markdown_url ) {
                    update_post_meta( $post_id, self::MARKDOWN_URL_META, $expected_markdown_url );
                    if ( $unchanged && ! $force_item ) {
                        $changed++;
                    }
                }
                $found++;

                if ( $unchanged && isset( $previous['encoding'] ) && is_array( $previous['encoding'] ) ) {
                    $encoding = $previous['encoding'];
                    $reused++;
                } else {
                    $encoding = self::validate_text_file( $markdown_file, $public_root );
                    $validated++;
                }

                if ( $is_stale ) {
                    $stale++;
                }

                if ( empty( $encoding['valid'] ) ) {
                    $encoding_issues++;
                } elseif ( ! empty( $encoding['bom'] ) ) {
                    $encoding_warnings++;
                }
            } else {
                if ( metadata_exists( 'post', $post_id, self::MARKDOWN_URL_META ) ) {
                    delete_post_meta( $post_id, self::MARKDOWN_URL_META );
                    $removed++;
                }

                if ( ! $disabled ) {
                    $missing++;
                }
            }

            $items[ $post_id ] = [
                'exists'             => $exists,
                'disabled'           => $disabled,
                'permalink'          => $permalink,
                'markdown_url'       => $exists ? $expected_markdown_url : '',
                'markdown_relative'  => $relative_markdown,
                'post_modified'      => $post_modified,
                'markdown_modified'  => $md_modified,
                'markdown_size'      => $md_size,
                'stale'              => $is_stale,
                'encoding'           => $encoding,
                'scan_signature'     => $signature,
                'validation_reused'  => $exists && $unchanged,
            ];
        }

        $llms_health = self::validate_llms_file( $public_root );
        $llms_file   = wp_normalize_path( $public_root . 'llms.txt' );
        $llms_exists = self::is_safe_readable_file( $llms_file, $public_root );
        $llms_sig    = hash(
            'sha256',
            wp_json_encode(
                [
                    'exists' => $llms_exists,
                    'mtime'  => $llms_exists ? (int) @filemtime( $llms_file ) : 0,
                    'size'   => $llms_exists ? (int) @filesize( $llms_file ) : 0,
                    'valid'  => ! empty( $llms_health['valid'] ),
                ]
            ) ?: ''
        );
        $llms_health['scan_signature'] = $llms_sig;
        $previous_llms_sig = isset( $previous_llms['scan_signature'] ) ? (string) $previous_llms['scan_signature'] : '';

        if ( '' === $previous_llms_sig || ! hash_equals( $previous_llms_sig, $llms_sig ) ) {
            $changed++;
        }

        $has_llms   = ! empty( $llms_health['exists'] );
        $scanned_at = time();
        $health     = [
            'scanned_at'          => $scanned_at,
            'published'           => $checked,
            'found'               => $found,
            'missing'             => $missing,
            'stale'               => $stale,
            'encoding_issues'     => $encoding_issues,
            'encoding_warnings'   => $encoding_warnings,
            'excluded'            => $excluded,
            'validated'           => $validated,
            'reused'              => $reused,
            'changed'             => $changed,
            'llms'                => $llms_health,
            'items'               => $items,
        ];

        if ( isset( $previous_health['live'] ) && is_array( $previous_health['live'] ) ) {
            $previous_live       = $previous_health['live'];
            $previous_live_items = isset( $previous_live['items'] ) && is_array( $previous_live['items'] ) ? $previous_live['items'] : [];
            $preserved_live      = [];
            $outdated_count      = 0;
            $llms_expectation_changed = ( ! empty( $previous_llms['exists'] ) ) !== $llms_exists;

            foreach ( $items as $post_id => $current_item ) {
                $post_id = (int) $post_id;
                if ( ! isset( $previous_live_items[ $post_id ] ) || ! is_array( $previous_live_items[ $post_id ] ) ) {
                    continue;
                }

                $live_item          = $previous_live_items[ $post_id ];
                $verified_signature = isset( $live_item['scan_signature'] ) ? (string) $live_item['scan_signature'] : '';
                if ( '' === $verified_signature && isset( $previous_items[ $post_id ]['scan_signature'] ) ) {
                    $verified_signature = (string) $previous_items[ $post_id ]['scan_signature'];
                }
                $current_signature = isset( $current_item['scan_signature'] ) ? (string) $current_item['scan_signature'] : '';
                $signature_changed = '' === $verified_signature || '' === $current_signature || ! hash_equals( $verified_signature, $current_signature );

                if ( $llms_expectation_changed || $signature_changed || ! empty( $live_item['outdated'] ) ) {
                    $live_item['outdated']    = true;
                    $live_item['outdated_at'] = $scanned_at;
                    $outdated_count++;
                } else {
                    $live_item['outdated'] = false;
                    unset( $live_item['outdated_at'] );
                }

                $preserved_live[ $post_id ] = $live_item;
            }

            $previous_live['items']          = $preserved_live;
            $previous_live['outdated_count'] = $outdated_count;
            $health['live']                  = $previous_live;
        }

        update_option( self::LLMS_DETECTED_OPTION, $has_llms ? '1' : '0', true );
        update_option( self::LAST_SCAN_OPTION, $scanned_at, false );
        update_option( self::HEALTH_OPTION, $health, false );

        return [
            'checked'         => $checked,
            'found'           => $found,
            'removed'         => $removed,
            'missing'         => $missing,
            'stale'           => $stale,
            'encoding_issues' => $encoding_issues,
            'llms'            => $has_llms,
            'validated'       => $validated,
            'reused'          => $reused,
            'changed'         => $changed,
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

    /**
     * Load the administrator browser verifier only on this plugin's settings page.
     * Public visitors never receive this script.
     */
    public static function enqueue_admin_assets( string $hook_suffix ): void {
        unset( $hook_suffix );

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        if ( self::SETTINGS_SLUG !== $page ) {
            return;
        }

        $handle = 'bloglogistics-mfa-admin-live-verifier';
        wp_enqueue_script(
            $handle,
            plugins_url( 'assets/admin-live-verifier.js', BLOGLOGISTICS_MFA_FILE ),
            [],
            BLOGLOGISTICS_MFA_VERSION,
            true
        );

        wp_localize_script(
            $handle,
            'BlogLogisticsMFALiveVerifier',
            [
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'bloglogistics_mfa_browser_verify' ),
                'autoStart' => isset( $_GET['browser_verify'] ) && '1' === (string) wp_unslash( $_GET['browser_verify'] ),
                'labels'    => [
                    'starting'  => __( 'Browser verification is starting...', 'bloglogistics-markdown-for-agents' ),
                    'progress'  => __( 'Browser verification: %1$d of %2$d checked.', 'bloglogistics-markdown-for-agents' ),
                    'saving'    => __( 'Browser verification finished. Saving results...', 'bloglogistics-markdown-for-agents' ),
                    'error'     => __( 'Browser verification could not be completed. Reload the page and try again.', 'bloglogistics-markdown-for-agents' ),
                    'noTargets' => __( 'No Markdown files are available for live verification.', 'bloglogistics-markdown-for-agents' ),
                ],
            ]
        );
    }

    private static function settings_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

        return in_array( $tab, [ 'overview', 'pages', 'posts', 'llms', 'server' ], true ) ? $tab : 'overview';
    }

    private static function settings_view(): string {
        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all';

        return in_array( $view, [ 'all', 'attention', 'missing', 'stale', 'encoding', 'live', 'discovery', 'disabled' ], true ) ? $view : 'all';
    }

    /**
     * Current administrator list size for Pages/Posts. A small fixed set keeps
     * the UI predictable and prevents accidental enormous admin tables.
     */
    private static function settings_per_page(): int {
        $allowed   = [ 20, 50, 100 ];
        $requested = isset( $_GET['mfa_per_page'] ) ? absint( wp_unslash( $_GET['mfa_per_page'] ) ) : 0;

        if ( in_array( $requested, $allowed, true ) ) {
            if ( get_current_user_id() ) {
                update_user_meta( get_current_user_id(), self::PER_PAGE_USER_META, $requested );
            }
            return $requested;
        }

        $stored = get_current_user_id() ? absint( get_user_meta( get_current_user_id(), self::PER_PAGE_USER_META, true ) ) : 0;

        return in_array( $stored, $allowed, true ) ? $stored : 20;
    }

    private static function settings_paged(): int {
        return max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
    }

    private static function settings_url( string $tab = 'overview', string $view = 'all', array $extra = [] ): string {
        $args = array_merge(
            [
                'page' => self::SETTINGS_SLUG,
                'tab'  => $tab,
            ],
            $extra
        );

        if ( in_array( $tab, [ 'pages', 'posts' ], true ) ) {
            $args['view'] = $view;
            if ( ! array_key_exists( 'mfa_per_page', $args ) ) {
                $args['mfa_per_page'] = self::settings_per_page();
            }
        }

        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    /**
     * Preserve the administrator's current tab/filter/pagination after an action.
     *
     * @return array{tab:string,view:string,paged:int,per_page:int}
     */
    private static function return_context( string $default_tab = 'overview', string $default_view = 'all' ): array {
        $tab_source      = isset( $_POST['return_tab'] ) ? wp_unslash( $_POST['return_tab'] ) : ( isset( $_GET['return_tab'] ) ? wp_unslash( $_GET['return_tab'] ) : $default_tab );
        $view_source     = isset( $_POST['return_view'] ) ? wp_unslash( $_POST['return_view'] ) : ( isset( $_GET['return_view'] ) ? wp_unslash( $_GET['return_view'] ) : $default_view );
        $paged_source    = isset( $_POST['return_paged'] ) ? wp_unslash( $_POST['return_paged'] ) : ( isset( $_GET['return_paged'] ) ? wp_unslash( $_GET['return_paged'] ) : 1 );
        $per_page_source = isset( $_POST['return_per_page'] ) ? wp_unslash( $_POST['return_per_page'] ) : ( isset( $_GET['return_per_page'] ) ? wp_unslash( $_GET['return_per_page'] ) : self::settings_per_page() );
        $tab             = sanitize_key( (string) $tab_source );
        $view            = sanitize_key( (string) $view_source );
        $paged           = max( 1, absint( $paged_source ) );
        $per_page        = absint( $per_page_source );

        if ( ! in_array( $tab, [ 'overview', 'pages', 'posts', 'llms', 'server' ], true ) ) {
            $tab = $default_tab;
        }

        if ( ! in_array( $view, [ 'all', 'attention', 'missing', 'stale', 'encoding', 'live', 'discovery', 'disabled' ], true ) ) {
            $view = $default_view;
        }

        if ( ! in_array( $per_page, [ 20, 50, 100 ], true ) ) {
            $per_page = 20;
        }

        return [
            'tab'      => $tab,
            'view'     => $view,
            'paged'    => $paged,
            'per_page' => $per_page,
        ];
    }

    /**
     * Query arguments needed to return to the same Pages/Posts list position.
     *
     * @param array{tab:string,view:string,paged?:int,per_page?:int} $context Context.
     * @return array<string,int|string>
     */
    private static function context_query_args( array $context ): array {
        $tab  = isset( $context['tab'] ) ? sanitize_key( (string) $context['tab'] ) : 'overview';
        $view = isset( $context['view'] ) ? sanitize_key( (string) $context['view'] ) : 'all';
        $args = [
            'tab'  => $tab,
            'view' => $view,
        ];

        if ( in_array( $tab, [ 'pages', 'posts' ], true ) ) {
            $per_page = isset( $context['per_page'] ) ? absint( $context['per_page'] ) : 20;
            $args['paged']        = max( 1, isset( $context['paged'] ) ? absint( $context['paged'] ) : 1 );
            $args['mfa_per_page'] = in_array( $per_page, [ 20, 50, 100 ], true ) ? $per_page : 20;
        }

        return $args;
    }

    /**
     * Per-administrator transient used to hand a verification request from a
     * normal WordPress form action to the browser-side verifier.
     */
    private static function browser_verify_transient_key(): string {
        return 'bloglogistics_mfa_browser_verify_' . get_current_user_id();
    }

    /**
     * Queue a browser-based verification run. An empty ID list means all
     * detected companions, subject to the normal per-run limit.
     *
     * @param array<int,int> $ids Selected post IDs, or an empty array for all.
     * @param array{tab:string,view:string,paged?:int,per_page?:int} $context Return location.
     */
    private static function schedule_browser_verification( array $ids, array $context ): void {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

        set_transient(
            self::browser_verify_transient_key(),
            [
                'requested_ids' => $ids,
                'scope'         => $ids ? 'selected' : 'all',
                'context'       => [
                    'tab'      => isset( $context['tab'] ) ? sanitize_key( (string) $context['tab'] ) : 'overview',
                    'view'     => isset( $context['view'] ) ? sanitize_key( (string) $context['view'] ) : 'all',
                    'paged'    => max( 1, isset( $context['paged'] ) ? absint( $context['paged'] ) : 1 ),
                    'per_page' => isset( $context['per_page'] ) && in_array( absint( $context['per_page'] ), [ 20, 50, 100 ], true ) ? absint( $context['per_page'] ) : 20,
                ],
                'created_at'    => time(),
            ],
            10 * MINUTE_IN_SECONDS
        );
    }

    /**
     * Return published IDs for one supported post type, sorted by title.
     *
     * @return array<int,int>
     */
    private static function published_ids_by_type( string $post_type ): array {
        static $cache = [];

        if ( isset( $cache[ $post_type ] ) ) {
            return $cache[ $post_type ];
        }

        $ids = get_posts(
            [
                'post_type'              => $post_type,
                'post_status'            => 'publish',
                'numberposts'            => -1,
                'fields'                 => 'ids',
                'orderby'                => 'title',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'suppress_filters'       => false,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
            ]
        );

        $cache[ $post_type ] = array_map( 'absint', $ids );

        return $cache[ $post_type ];
    }

    /**
     * Summarise one content item's saved health state for display/filtering.
     * No filesystem or HTTP work is performed here.
     *
     * @param array<string,mixed> $item       Saved scan item.
     * @param array<string,mixed> $live_item  Saved live verification item.
     * @return array<string,mixed>
     */
    private static function content_item_state( int $post_id, array $item, array $live_item ): array {
        $scanned  = ! empty( $item );
        $exists   = $scanned && ! empty( $item['exists'] );
        $disabled = self::is_discovery_disabled( $post_id );
        $stale    = $exists && ! empty( $item['stale'] );
        $encoding = isset( $item['encoding'] ) && is_array( $item['encoding'] ) ? $item['encoding'] : [];
        $enc_bad  = $exists && ! empty( $encoding ) && empty( $encoding['valid'] );
        $enc_bom  = $exists && ! $enc_bad && ! empty( $encoding['bom'] );
        $live     = ! empty( $live_item );
        $outdated = $live && ! empty( $live_item['outdated'] );

        $discovery_codes = [
            'missing_markdown_discovery',
            'unexpected_markdown_discovery',
            'missing_llms_discovery',
            'unexpected_llms_discovery',
        ];
        $all_issues   = $live && isset( $live_item['issues'] ) && is_array( $live_item['issues'] ) ? array_values( array_map( 'strval', $live_item['issues'] ) ) : [];
        $all_warnings = $live && isset( $live_item['warnings'] ) && is_array( $live_item['warnings'] ) ? array_values( array_map( 'strval', $live_item['warnings'] ) ) : [];

        $delivery_issues = isset( $live_item['delivery_issues'] ) && is_array( $live_item['delivery_issues'] )
            ? array_values( array_map( 'strval', $live_item['delivery_issues'] ) )
            : array_values( array_diff( $all_issues, $discovery_codes ) );
        $discovery_issues = isset( $live_item['discovery_issues'] ) && is_array( $live_item['discovery_issues'] )
            ? array_values( array_map( 'strval', $live_item['discovery_issues'] ) )
            : array_values( array_intersect( $all_issues, $discovery_codes ) );
        $delivery_warnings = isset( $live_item['delivery_warnings'] ) && is_array( $live_item['delivery_warnings'] )
            ? array_values( array_map( 'strval', $live_item['delivery_warnings'] ) )
            : array_values( array_diff( $all_warnings, [ 'stale_cached_discovery', 'discovery_recheck_failed' ] ) );
        $discovery_warnings = isset( $live_item['discovery_warnings'] ) && is_array( $live_item['discovery_warnings'] )
            ? array_values( array_map( 'strval', $live_item['discovery_warnings'] ) )
            : array_values( array_intersect( $all_warnings, [ 'stale_cached_discovery', 'discovery_recheck_failed' ] ) );

        // Normalize saved 2.4.0/2.4.1 results so a reachable file with a
        // missing Content-Type is a Review rather than a red delivery failure.
        if (
            in_array( 'markdown_mime', $delivery_issues, true )
            && '' === trim( (string) ( $live_item['content_type'] ?? '' ) )
            && 200 === (int) ( $live_item['markdown_status'] ?? 0 )
        ) {
            $delivery_issues   = array_values( array_diff( $delivery_issues, [ 'markdown_mime' ] ) );
            $delivery_warnings[] = 'markdown_mime_unconfirmed';
        }

        // Discovery mismatches describe advertising state, not file delivery.
        // Older saved results used the red issue bucket; normalize them to
        // Review so the UI remains consistent with the 2.4.2 verifier.
        if ( $discovery_issues ) {
            $discovery_warnings = array_values( array_unique( array_merge( $discovery_warnings, $discovery_issues ) ) );
            $discovery_issues   = [];
        }

        $delivery_warnings  = array_values( array_unique( $delivery_warnings ) );
        $discovery_warnings = array_values( array_unique( $discovery_warnings ) );

        // Outdated saved verification is not a health finding. It means only
        // that a fresh check is required. Likewise, browser/network request
        // exceptions are inconclusive rather than evidence of a broken URL.
        $request_inconclusive_codes = [ 'html_request_error', 'markdown_request_error' ];
        $request_inconclusive       = $live && (bool) array_intersect( $request_inconclusive_codes, $delivery_warnings );
        $severity_delivery_warnings = array_values( array_diff( $delivery_warnings, $request_inconclusive_codes, [ 'redirected' ] ) );

        $live_bad         = $live && ! $outdated && ! empty( $delivery_issues );
        $live_review      = $live && ! $outdated && ! $live_bad && ! $request_inconclusive && ! empty( $severity_delivery_warnings );
        $discovery_bad    = false;
        $discovery_review = $live && ! $outdated && ! empty( $discovery_warnings );
        $missing          = $scanned && ! $exists && ! $disabled;
        $attention        = $missing || $stale || $enc_bad || $enc_bom || $live_bad || $live_review || $discovery_bad || $discovery_review;

        return [
            'scanned'            => $scanned,
            'exists'             => $exists,
            'disabled'           => $disabled,
            'missing'            => $missing,
            'stale'              => $stale,
            'encoding'           => $encoding,
            'encoding_bad'       => $enc_bad,
            'encoding_bom'       => $enc_bom,
            'live'               => $live,
            'live_outdated'      => $outdated,
            'live_inconclusive'  => $request_inconclusive,
            'live_bad'           => $live_bad,
            'live_review'        => $live_review,
            'delivery_issues'    => $delivery_issues,
            'delivery_warnings'  => $delivery_warnings,
            'severity_delivery_warnings' => $severity_delivery_warnings,
            'discovery_bad'      => $discovery_bad,
            'discovery_review'   => $discovery_review,
            'discovery_issues'   => $discovery_issues,
            'discovery_warnings' => $discovery_warnings,
            'attention'          => $attention,
            'item'               => $item,
            'live_item'          => $live_item,
            'markdown_url'       => $exists && ! empty( $item['markdown_url'] ) ? (string) $item['markdown_url'] : (string) get_post_meta( $post_id, self::MARKDOWN_URL_META, true ),
        ];
    }

    /**
     * Does a content state belong in the requested Pages/Posts filter?
     *
     * @param array<string,mixed> $state Content state.
     */
    private static function state_matches_view( array $state, string $view ): bool {
        return match ( $view ) {
            'attention' => ! empty( $state['attention'] ),
            'missing'   => ! empty( $state['missing'] ),
            'stale'     => ! empty( $state['stale'] ),
            'encoding'  => ! empty( $state['encoding_bad'] ) || ! empty( $state['encoding_bom'] ),
            'live'      => ! empty( $state['live_bad'] ) || ! empty( $state['live_review'] ),
            'discovery' => ! empty( $state['discovery_bad'] ) || ! empty( $state['discovery_review'] ),
            'disabled'  => ! empty( $state['disabled'] ),
            default     => true,
        };
    }

    /**
     * Count each filter state for a Pages/Posts tab.
     *
     * @param array<int,int>       $ids        Published IDs.
     * @param array<string,mixed>  $health     Saved health snapshot.
     * @param array<int,mixed>     $live_items Saved live verification items.
     * @return array<string,int>
     */
    private static function content_filter_counts( array $ids, array $health, array $live_items ): array {
        $counts = [
            'all'                => count( $ids ),
            'attention'          => 0,
            'problems'           => 0,
            'reviews'            => 0,
            'missing'            => 0,
            'stale'              => 0,
            'encoding'           => 0,
            'live'               => 0,
            'live_problems'      => 0,
            'live_reviews'       => 0,
            'discovery'          => 0,
            'discovery_problems' => 0,
            'discovery_reviews'  => 0,
            'disabled'           => 0,
            'found'              => 0,
        ];
        $items = isset( $health['items'] ) && is_array( $health['items'] ) ? $health['items'] : [];

        foreach ( $ids as $post_id ) {
            $item      = isset( $items[ $post_id ] ) && is_array( $items[ $post_id ] ) ? $items[ $post_id ] : [];
            $live_item = isset( $live_items[ $post_id ] ) && is_array( $live_items[ $post_id ] ) ? $live_items[ $post_id ] : [];
            $state     = self::content_item_state( (int) $post_id, $item, $live_item );

            foreach ( [ 'attention', 'missing', 'stale', 'disabled' ] as $key ) {
                if ( ! empty( $state[ $key ] ) ) {
                    $counts[ $key ]++;
                }
            }

            if ( ! empty( $state['encoding_bad'] ) || ! empty( $state['encoding_bom'] ) ) {
                $counts['encoding']++;
            }

            if ( ! empty( $state['live_bad'] ) || ! empty( $state['live_review'] ) ) {
                $counts['live']++;
            }
            if ( ! empty( $state['live_bad'] ) ) {
                $counts['live_problems']++;
            } elseif ( ! empty( $state['live_review'] ) ) {
                $counts['live_reviews']++;
            }

            if ( ! empty( $state['discovery_bad'] ) || ! empty( $state['discovery_review'] ) ) {
                $counts['discovery']++;
            }
            if ( ! empty( $state['discovery_bad'] ) ) {
                $counts['discovery_problems']++;
            } elseif ( ! empty( $state['discovery_review'] ) ) {
                $counts['discovery_reviews']++;
            }

            $has_problem = ! empty( $state['missing'] ) || ! empty( $state['encoding_bad'] ) || ! empty( $state['live_bad'] ) || ! empty( $state['discovery_bad'] );
            $has_review  = ! empty( $state['stale'] ) || ! empty( $state['encoding_bom'] ) || ! empty( $state['live_review'] ) || ! empty( $state['discovery_review'] );

            if ( $has_problem ) {
                $counts['problems']++;
            } elseif ( $has_review ) {
                $counts['reviews']++;
            }

            if ( ! empty( $state['exists'] ) ) {
                $counts['found']++;
            }
        }

        return $counts;
    }

    private static function status_markup( string $status, string $label, string $detail = '' ): string {
        $status = in_array( $status, [ 'healthy', 'problem', 'review', 'na' ], true ) ? $status : 'na';
        $html   = '<span class="bl-mfa-status bl-mfa-status-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';

        if ( '' !== $detail ) {
            $html .= '<span class="bl-mfa-health-detail">' . esc_html( $detail ) . '</span>';
        }

        return $html;
    }

    /**
     * Human-readable detail for the public HTML/Markdown delivery check.
     *
     * @param array<string,mixed> $live_item Saved live verification item.
     */
    private static function live_delivery_detail( array $live_item ): string {
        $detail = sprintf(
            __( 'HTML %1$d; Markdown %2$d', 'bloglogistics-markdown-for-agents' ),
            (int) ( $live_item['html_status'] ?? 0 ),
            (int) ( $live_item['markdown_status'] ?? 0 )
        );
        $cache_state = (string) ( $live_item['markdown_mime_cache_state'] ?? '' );

        if ( 'stale_cache' === $cache_state ) {
            $fresh_type = trim( (string) ( $live_item['markdown_fresh_content_type'] ?? '' ) );
            $detail .= '; ' . sprintf(
                /* translators: %s: fresh Content-Type header. */
                __( 'Cached MIME headers appear stale; fresh recheck: %s', 'bloglogistics-markdown-for-agents' ),
                '' !== $fresh_type ? $fresh_type : __( 'text/markdown confirmed', 'bloglogistics-markdown-for-agents' )
            );
        } elseif ( 'unconfirmed' === $cache_state || 'recheck_failed' === $cache_state ) {
            $detail .= '; ' . __( 'Content-Type was not returned to the server-side verifier; the Markdown file is reachable', 'bloglogistics-markdown-for-agents' );
        } else {
            $mime_label = self::markdown_mime_label( (string) ( $live_item['mime_state'] ?? 'missing' ), (string) ( $live_item['content_type'] ?? '' ) );
            if ( '' !== $mime_label ) {
                $detail .= '; ' . $mime_label;
            }
        }

        return $detail;
    }

    /**
     * Human-readable detail for HTML head discovery checks.
     *
     * @param array<string,mixed> $live_item Saved live verification item.
     */
    private static function discovery_detail( array $live_item ): string {
        $disabled        = ! empty( $live_item['disabled'] );
        $expect_markdown = array_key_exists( 'expect_markdown', $live_item ) ? (bool) $live_item['expect_markdown'] : ! $disabled;
        $expect_llms     = array_key_exists( 'expect_llms', $live_item ) ? (bool) $live_item['expect_llms'] : ( ! $disabled && '1' === get_option( self::LLMS_DETECTED_OPTION, '0' ) );
        $canonical       = isset( $live_item['discovery'] ) && is_array( $live_item['discovery'] ) ? $live_item['discovery'] : [];
        $fresh           = isset( $live_item['discovery_fresh'] ) && is_array( $live_item['discovery_fresh'] ) ? $live_item['discovery_fresh'] : [];
        $cache_state     = isset( $live_item['discovery_cache_state'] ) ? (string) $live_item['discovery_cache_state'] : '';
        $effective       = 'stale_cache' === $cache_state && $fresh ? $fresh : $canonical;
        $parts           = [];

        if ( $disabled ) {
            if ( 'stale_cache' === $cache_state ) {
                return __( 'Discovery is disabled; current HTML no longer advertises the links, but cached HTML appears stale', 'bloglogistics-markdown-for-agents' );
            }
            if ( ! empty( $canonical['markdown_found'] ) || ! empty( $canonical['llms_found'] ) || ! empty( $fresh['markdown_found'] ) || ! empty( $fresh['llms_found'] ) ) {
                return __( 'Discovery is disabled, but discovery links are still present in live HTML', 'bloglogistics-markdown-for-agents' );
            }
            return __( 'Discovery is disabled and no plugin discovery links were found', 'bloglogistics-markdown-for-agents' );
        }

        if ( $expect_markdown ) {
            if ( 'stale_cache' === $cache_state && ! empty( $fresh['markdown_found'] ) && empty( $canonical['markdown_found'] ) ) {
                $parts[] = __( 'Markdown discovery found in current HTML; cached HTML was stale', 'bloglogistics-markdown-for-agents' );
            } else {
                $parts[] = ! empty( $effective['markdown_found'] )
                    ? __( 'Markdown discovery found', 'bloglogistics-markdown-for-agents' )
                    : __( 'Markdown discovery missing', 'bloglogistics-markdown-for-agents' );
            }
        }

        if ( $expect_llms ) {
            if ( 'stale_cache' === $cache_state && ! empty( $fresh['llms_found'] ) && empty( $canonical['llms_found'] ) ) {
                $parts[] = __( 'llms.txt discovery found in current HTML; cached HTML was stale', 'bloglogistics-markdown-for-agents' );
            } else {
                $parts[] = ! empty( $effective['llms_found'] )
                    ? __( 'llms.txt discovery found', 'bloglogistics-markdown-for-agents' )
                    : __( 'llms.txt discovery missing', 'bloglogistics-markdown-for-agents' );
            }
        } elseif ( ! $disabled ) {
            $parts[] = __( 'llms.txt discovery is not expected because llms.txt was not detected', 'bloglogistics-markdown-for-agents' );
        }

        if ( 'recheck_failed' === $cache_state ) {
            $parts[] = __( 'Cache-busting recheck could not be completed', 'bloglogistics-markdown-for-agents' );
        }

        return implode( '; ', $parts );
    }

    /**
     * Render one dashboard card. One-link cards are clickable as a whole;
     * multi-destination cards expose explicit Pages/Posts links.
     *
     * @param array<string,string> $links Link label => URL.
     */
    private static function render_dashboard_card( string $title, int|string $value, string $status, array $links = [], string $detail = '' ): void {
        $status = in_array( $status, [ 'healthy', 'problem', 'review', 'na' ], true ) ? $status : 'na';
        $inner  = '<span class="bl-mfa-card-label">' . esc_html( $title ) . '</span>';
        $display_value = is_int( $value ) ? number_format_i18n( $value ) : $value;
        $inner .= '<strong>' . esc_html( $display_value ) . '</strong>';

        if ( '' !== $detail ) {
            $inner .= '<span class="bl-mfa-card-detail">' . esc_html( $detail ) . '</span>';
        }

        if ( 1 === count( $links ) ) {
            $url = reset( $links );
            echo '<a class="bl-mfa-health-card bl-mfa-card-' . esc_attr( $status ) . '" href="' . esc_url( (string) $url ) . '">' . $inner . '</a>';
            return;
        }

        echo '<div class="bl-mfa-health-card bl-mfa-card-' . esc_attr( $status ) . '">' . $inner;
        if ( $links ) {
            echo '<span class="bl-mfa-card-links">';
            $parts = [];
            foreach ( $links as $label => $url ) {
                $parts[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
            }
            echo implode( '<span aria-hidden="true"> · </span>', $parts );
            echo '</span>';
        }
        echo '</div>';
    }

    private static function render_settings_tabs( string $active_tab, int $page_count, int $post_count ): void {
        $tabs = [
            'overview' => __( 'Overview', 'bloglogistics-markdown-for-agents' ),
            'pages'    => sprintf( __( 'Pages (%d)', 'bloglogistics-markdown-for-agents' ), $page_count ),
            'posts'    => sprintf( __( 'Posts (%d)', 'bloglogistics-markdown-for-agents' ), $post_count ),
            'llms'     => __( 'llms.txt', 'bloglogistics-markdown-for-agents' ),
            'server'   => __( 'Server & .htaccess', 'bloglogistics-markdown-for-agents' ),
        ];

        echo '<nav class="nav-tab-wrapper bl-mfa-tabs" aria-label="' . esc_attr__( 'Markdown for Agents sections', 'bloglogistics-markdown-for-agents' ) . '">';
        foreach ( $tabs as $tab => $label ) {
            $class = 'nav-tab' . ( $active_tab === $tab ? ' nav-tab-active' : '' );
            echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::settings_url( $tab ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    private static function render_scan_actions( string $tab, string $view ): void {
        echo '<div class="bl-mfa-actions bl-mfa-primary-actions">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bloglogistics_mfa_scan' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_scan">';
        echo '<input type="hidden" name="return_tab" value="' . esc_attr( $tab ) . '">';
        echo '<input type="hidden" name="return_view" value="' . esc_attr( $view ) . '">';
        if ( in_array( $tab, [ 'pages', 'posts' ], true ) ) {
            echo '<input type="hidden" name="return_paged" value="' . esc_attr( (string) self::settings_paged() ) . '">';
            echo '<input type="hidden" name="return_per_page" value="' . esc_attr( (string) self::settings_per_page() ) . '">';
        }
        submit_button( __( 'Scan Changes and Refresh Health', 'bloglogistics-markdown-for-agents' ), 'primary', 'submit', false );
        echo ' <button type="submit" class="button" name="force_full_scan" value="1">' . esc_html__( 'Force Full Rescan', 'bloglogistics-markdown-for-agents' ) . '</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bloglogistics_mfa_live_verify' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_live_verify">';
        echo '<input type="hidden" name="return_tab" value="' . esc_attr( $tab ) . '">';
        echo '<input type="hidden" name="return_view" value="' . esc_attr( $view ) . '">';
        if ( in_array( $tab, [ 'pages', 'posts' ], true ) ) {
            echo '<input type="hidden" name="return_paged" value="' . esc_attr( (string) self::settings_paged() ) . '">';
            echo '<input type="hidden" name="return_per_page" value="' . esc_attr( (string) self::settings_per_page() ) . '">';
        }
        submit_button( __( 'Run Live Endpoint Verification', 'bloglogistics-markdown-for-agents' ), 'secondary', 'submit', false );
        echo '</form>';
        echo '</div>';
    }

    /**
     * Render the Overview tab.
     *
     * @param array<string,mixed> $health         Saved health snapshot.
     * @param array<string,mixed> $htaccess_status Saved .htaccess state.
     * @param array<string,mixed> $server         Server diagnostics.
     */
    private static function render_overview_tab( array $health, array $htaccess_status, array $server, int $last_scan ): void {
        $health_ready = ! empty( $health['scanned_at'] ) && isset( $health['items'] ) && is_array( $health['items'] );
        $live_health  = isset( $health['live'] ) && is_array( $health['live'] ) ? $health['live'] : [];
        $live_items   = isset( $live_health['items'] ) && is_array( $live_health['items'] ) ? $live_health['items'] : [];
        $llms_health  = isset( $health['llms'] ) && is_array( $health['llms'] ) ? $health['llms'] : [];
        $page_ids     = self::published_ids_by_type( 'page' );
        $post_ids     = self::published_ids_by_type( 'post' );
        $page_counts  = self::content_filter_counts( $page_ids, $health, $live_items );
        $post_counts  = self::content_filter_counts( $post_ids, $health, $live_items );
        $missing      = $page_counts['missing'] + $post_counts['missing'];
        $stale        = $page_counts['stale'] + $post_counts['stale'];
        $encoding     = $page_counts['encoding'] + $post_counts['encoding'];
        $live_issues       = $page_counts['live'] + $post_counts['live'];
        $live_problems     = $page_counts['live_problems'] + $post_counts['live_problems'];
        $live_reviews      = $page_counts['live_reviews'] + $post_counts['live_reviews'];
        $discovery_issues  = $page_counts['discovery'] + $post_counts['discovery'];
        $discovery_problems = $page_counts['discovery_problems'] + $post_counts['discovery_problems'];
        $discovery_reviews = $page_counts['discovery_reviews'] + $post_counts['discovery_reviews'];
        $disabled     = $page_counts['disabled'] + $post_counts['disabled'];
        $found        = $page_counts['found'] + $post_counts['found'];
        $encoding_bad = isset( $health['encoding_issues'] ) ? (int) $health['encoding_issues'] : 0;
        $encoding_warn = isset( $health['encoding_warnings'] ) ? (int) $health['encoding_warnings'] : 0;

        echo '<h2>' . esc_html__( 'Markdown Health Dashboard', 'bloglogistics-markdown-for-agents' ) . '</h2>';
        echo '<div class="bl-mfa-status-key"><strong>' . esc_html__( 'Status key:', 'bloglogistics-markdown-for-agents' ) . '</strong> ';
        echo self::status_markup( 'healthy', __( 'Healthy', 'bloglogistics-markdown-for-agents' ) ) . ' ';
        echo self::status_markup( 'problem', __( 'Problem', 'bloglogistics-markdown-for-agents' ) ) . ' ';
        echo self::status_markup( 'review', __( 'Review', 'bloglogistics-markdown-for-agents' ) ) . ' ';
        echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ) );
        echo '</div>';

        if ( ! $health_ready ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Run the Markdown scan to populate health information. The scan checks Markdown file presence, freshness, UTF-8 encoding, common mojibake patterns, and llms.txt references without changing your Markdown content.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
        }

        echo '<div class="bl-mfa-health-grid">';
        self::render_dashboard_card(
            __( 'Pages', 'bloglogistics-markdown-for-agents' ),
            count( $page_ids ),
            ! $health_ready ? 'na' : ( $page_counts['problems'] ? 'problem' : ( $page_counts['reviews'] ? 'review' : 'healthy' ) ),
            [ __( 'Open Pages', 'bloglogistics-markdown-for-agents' ) => self::settings_url( 'pages', $page_counts['attention'] ? 'attention' : 'all' ) ],
            ! $health_ready ? __( 'Not scanned yet', 'bloglogistics-markdown-for-agents' ) : ( $page_counts['attention'] ? sprintf( _n( '%d needs attention', '%d need attention', $page_counts['attention'], 'bloglogistics-markdown-for-agents' ), $page_counts['attention'] ) : __( 'No saved issues', 'bloglogistics-markdown-for-agents' ) )
        );
        self::render_dashboard_card(
            __( 'Posts', 'bloglogistics-markdown-for-agents' ),
            count( $post_ids ),
            ! $health_ready ? 'na' : ( $post_counts['problems'] ? 'problem' : ( $post_counts['reviews'] ? 'review' : 'healthy' ) ),
            [ __( 'Open Posts', 'bloglogistics-markdown-for-agents' ) => self::settings_url( 'posts', $post_counts['attention'] ? 'attention' : 'all' ) ],
            ! $health_ready ? __( 'Not scanned yet', 'bloglogistics-markdown-for-agents' ) : ( $post_counts['attention'] ? sprintf( _n( '%d needs attention', '%d need attention', $post_counts['attention'], 'bloglogistics-markdown-for-agents' ), $post_counts['attention'] ) : __( 'No saved issues', 'bloglogistics-markdown-for-agents' ) )
        );
        self::render_dashboard_card(
            __( 'Markdown files found', 'bloglogistics-markdown-for-agents' ),
            $found,
            $health_ready ? 'healthy' : 'na',
            [
                sprintf( __( 'Pages %d', 'bloglogistics-markdown-for-agents' ), $page_counts['found'] ) => self::settings_url( 'pages', 'all' ),
                sprintf( __( 'Posts %d', 'bloglogistics-markdown-for-agents' ), $post_counts['found'] ) => self::settings_url( 'posts', 'all' ),
            ]
        );
        self::render_dashboard_card(
            __( 'Missing Markdown files', 'bloglogistics-markdown-for-agents' ),
            $missing,
            ! $health_ready ? 'na' : ( $missing ? 'problem' : 'healthy' ),
            [
                sprintf( __( 'Pages %d', 'bloglogistics-markdown-for-agents' ), $page_counts['missing'] ) => self::settings_url( 'pages', 'missing' ),
                sprintf( __( 'Posts %d', 'bloglogistics-markdown-for-agents' ), $post_counts['missing'] ) => self::settings_url( 'posts', 'missing' ),
            ]
        );
        self::render_dashboard_card(
            __( 'Possibly stale', 'bloglogistics-markdown-for-agents' ),
            $stale,
            ! $health_ready ? 'na' : ( $stale ? 'review' : 'healthy' ),
            [
                sprintf( __( 'Pages %d', 'bloglogistics-markdown-for-agents' ), $page_counts['stale'] ) => self::settings_url( 'pages', 'stale' ),
                sprintf( __( 'Posts %d', 'bloglogistics-markdown-for-agents' ), $post_counts['stale'] ) => self::settings_url( 'posts', 'stale' ),
            ]
        );
        self::render_dashboard_card(
            __( 'Encoding findings', 'bloglogistics-markdown-for-agents' ),
            $encoding,
            ! $health_ready ? 'na' : ( $encoding_bad ? 'problem' : ( $encoding_warn ? 'review' : 'healthy' ) ),
            [
                sprintf( __( 'Pages %d', 'bloglogistics-markdown-for-agents' ), $page_counts['encoding'] ) => self::settings_url( 'pages', 'encoding' ),
                sprintf( __( 'Posts %d', 'bloglogistics-markdown-for-agents' ), $post_counts['encoding'] ) => self::settings_url( 'posts', 'encoding' ),
            ]
        );
        $saved_live_checked  = isset( $live_health['checked'] ) ? (int) $live_health['checked'] : 0;
        $saved_live_outdated = isset( $live_health['outdated_count'] ) ? (int) $live_health['outdated_count'] : 0;
        $all_live_outdated   = $live_health && $saved_live_checked > 0 && $saved_live_outdated >= $saved_live_checked;

        self::render_dashboard_card(
            __( 'Live delivery', 'bloglogistics-markdown-for-agents' ),
            $live_issues,
            ! $live_health || $all_live_outdated ? 'na' : ( $live_problems ? 'problem' : ( $live_reviews ? 'review' : 'healthy' ) ),
            [
                sprintf( __( 'Pages %d', 'bloglogistics-markdown-for-agents' ), $page_counts['live'] ) => self::settings_url( 'pages', 'live' ),
                sprintf( __( 'Posts %d', 'bloglogistics-markdown-for-agents' ), $post_counts['live'] ) => self::settings_url( 'posts', 'live' ),
            ],
            ! $live_health ? __( 'Not live-verified yet', 'bloglogistics-markdown-for-agents' ) : ( $all_live_outdated ? __( 'Re-verification required', 'bloglogistics-markdown-for-agents' ) : ( ! empty( $live_health['outdated_count'] ) ? sprintf( _n( '%d saved result needs re-verification', '%d saved results need re-verification', (int) $live_health['outdated_count'], 'bloglogistics-markdown-for-agents' ), (int) $live_health['outdated_count'] ) : __( 'HTML, Markdown, and MIME delivery', 'bloglogistics-markdown-for-agents' ) ) )
        );
        self::render_dashboard_card(
            __( 'Discovery checks', 'bloglogistics-markdown-for-agents' ),
            $discovery_issues,
            ! $live_health || $all_live_outdated ? 'na' : ( $discovery_problems ? 'problem' : ( $discovery_reviews ? 'review' : 'healthy' ) ),
            [
                sprintf( __( 'Pages %d', 'bloglogistics-markdown-for-agents' ), $page_counts['discovery'] ) => self::settings_url( 'pages', 'discovery' ),
                sprintf( __( 'Posts %d', 'bloglogistics-markdown-for-agents' ), $post_counts['discovery'] ) => self::settings_url( 'posts', 'discovery' ),
            ],
            ! $live_health ? __( 'Not live-verified yet', 'bloglogistics-markdown-for-agents' ) : ( $all_live_outdated ? __( 'Re-verification required', 'bloglogistics-markdown-for-agents' ) : __( 'Checks discovery links in the live HTML head', 'bloglogistics-markdown-for-agents' ) )
        );
        self::render_dashboard_card(
            __( 'Discovery disabled', 'bloglogistics-markdown-for-agents' ),
            $disabled,
            'na',
            [
                sprintf( __( 'Pages %d', 'bloglogistics-markdown-for-agents' ), $page_counts['disabled'] ) => self::settings_url( 'pages', 'disabled' ),
                sprintf( __( 'Posts %d', 'bloglogistics-markdown-for-agents' ), $post_counts['disabled'] ) => self::settings_url( 'posts', 'disabled' ),
            ],
            __( 'Intentional exclusions are not errors', 'bloglogistics-markdown-for-agents' )
        );

        $llms_status = 'na';
        $llms_detail = __( 'Not scanned yet', 'bloglogistics-markdown-for-agents' );
        if ( $health_ready ) {
            $llms_errors   = isset( $llms_health['errors'] ) && is_array( $llms_health['errors'] ) ? $llms_health['errors'] : [];
            $llms_warnings = isset( $llms_health['warnings'] ) && is_array( $llms_health['warnings'] ) ? $llms_health['warnings'] : [];
            $llms_status   = ! empty( $llms_health['valid'] ) ? ( $llms_warnings ? 'review' : 'healthy' ) : 'problem';
            $llms_detail   = ! empty( $llms_health['valid'] ) ? ( $llms_warnings ? __( 'Valid with warnings', 'bloglogistics-markdown-for-agents' ) : __( 'Healthy', 'bloglogistics-markdown-for-agents' ) ) : sprintf( _n( '%d problem', '%d problems', count( $llms_errors ), 'bloglogistics-markdown-for-agents' ), count( $llms_errors ) );
        }
        self::render_dashboard_card(
            __( 'llms.txt', 'bloglogistics-markdown-for-agents' ),
            isset( $llms_health['local_links_checked'] ) ? (int) $llms_health['local_links_checked'] : 0,
            $llms_status,
            [ __( 'Open llms.txt', 'bloglogistics-markdown-for-agents' ) => self::settings_url( 'llms' ) ],
            $llms_detail
        );

        $rewrite_ok = ! empty( $htaccess_status['rewrite_rule_verified'] );
        $mime_ok    = ! empty( $htaccess_status['mime_rule_verified'] );
        $server_status = ! empty( $server['supports_htaccess'] ) ? ( $rewrite_ok && $mime_ok ? 'healthy' : 'review' ) : 'na';
        self::render_dashboard_card(
            __( 'Server rules', 'bloglogistics-markdown-for-agents' ),
            ( (int) $rewrite_ok + (int) $mime_ok ) . '/2',
            $server_status,
            [ __( 'Open server tools', 'bloglogistics-markdown-for-agents' ) => self::settings_url( 'server' ) ],
            ! empty( $server['supports_htaccess'] ) ? __( '2 managed rule groups expected', 'bloglogistics-markdown-for-agents' ) : __( 'Server-level configuration may be required', 'bloglogistics-markdown-for-agents' )
        );
        echo '</div>';

        echo '<div class="bl-mfa-overview-meta">';
        echo '<span><strong>' . esc_html__( 'Last scan:', 'bloglogistics-markdown-for-agents' ) . '</strong> ' . ( $last_scan ? esc_html( wp_date( 'Y-m-d H:i:s T', $last_scan ) ) : esc_html__( 'Not yet scanned', 'bloglogistics-markdown-for-agents' ) ) . '</span>';
        echo '<span><strong>' . esc_html__( 'Last live verification:', 'bloglogistics-markdown-for-agents' ) . '</strong> ' . ( ! empty( $live_health['verified_at'] ) ? esc_html( wp_date( 'Y-m-d H:i:s T', (int) $live_health['verified_at'] ) ) : esc_html__( 'Not yet run', 'bloglogistics-markdown-for-agents' ) ) . '</span>';
        echo '</div>';

        self::render_scan_actions( 'overview', 'all' );
        echo '<p class="description">' . esc_html__( 'The incremental scan performs local administrator-only checks. Live verification runs public requests from your administrator browser, then securely stores the observed status, headers, and discovery results. Neither operation runs during normal public page loads.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        echo '<h2 style="margin-top:2em;">' . esc_html__( 'How it works', 'bloglogistics-markdown-for-agents' ) . '</h2>';
        echo '<ol class="bl-mfa-how-it-works">';
        echo '<li>' . esc_html__( 'Create and maintain your curated /llms.txt and Markdown files.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'For a normal page such as /about-us/, place its Markdown file at /about-us/index.md. The homepage Markdown file is /index.md.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'Run the incremental scan after files or WordPress content change. Use Force Full Rescan when every Markdown file should be re-read.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'Use the Pages and Posts tabs to review health, filter problems, change discovery settings, or verify selected content.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'Use the llms.txt and Server & .htaccess tabs for focused validation and technical diagnostics.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '</ol>';
    }

    /**
     * Render WordPress-style pagination and the per-page selector used by the
     * Pages and Posts lists.
     */
    private static function render_content_pagination( string $post_type, string $tab, string $view, int $paged, int $per_page, int $total_items, bool $show_per_page = true ): void {
        $total_pages = max( 1, (int) ceil( $total_items / max( 1, $per_page ) ) );
        $paged       = min( max( 1, $paged ), $total_pages );
        $type_label  = 'page' === $post_type
            ? sprintf( _n( '%s page', '%s pages', $total_items, 'bloglogistics-markdown-for-agents' ), number_format_i18n( $total_items ) )
            : sprintf( _n( '%s post', '%s posts', $total_items, 'bloglogistics-markdown-for-agents' ), number_format_i18n( $total_items ) );

        echo '<div class="tablenav bl-mfa-pagination-nav">';

        if ( $show_per_page ) {
            echo '<div class="alignleft actions bl-mfa-per-page">';
            echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
            echo '<input type="hidden" name="page" value="' . esc_attr( self::SETTINGS_SLUG ) . '">';
            echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
            echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '">';
            echo '<input type="hidden" name="paged" value="1">';
            echo '<label for="bl-mfa-per-page-' . esc_attr( $tab ) . '">' . esc_html__( 'Items per page', 'bloglogistics-markdown-for-agents' ) . '</label> ';
            echo '<select id="bl-mfa-per-page-' . esc_attr( $tab ) . '" name="mfa_per_page">';
            foreach ( [ 20, 50, 100 ] as $option ) {
                echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( $per_page, $option, false ) . '>' . esc_html( (string) $option ) . '</option>';
            }
            echo '</select> ';
            echo '<button type="submit" class="button">' . esc_html__( 'Apply', 'bloglogistics-markdown-for-agents' ) . '</button>';
            echo '</form>';
            echo '</div>';
        }

        echo '<div class="tablenav-pages">';
        echo '<span class="displaying-num">' . esc_html( $type_label ) . '</span>';

        if ( $total_pages > 1 ) {
            $first_url = self::settings_url( $tab, $view, [ 'paged' => 1, 'mfa_per_page' => $per_page ] );
            $prev_url  = self::settings_url( $tab, $view, [ 'paged' => max( 1, $paged - 1 ), 'mfa_per_page' => $per_page ] );
            $next_url  = self::settings_url( $tab, $view, [ 'paged' => min( $total_pages, $paged + 1 ), 'mfa_per_page' => $per_page ] );
            $last_url  = self::settings_url( $tab, $view, [ 'paged' => $total_pages, 'mfa_per_page' => $per_page ] );

            echo '<span class="pagination-links">';
            if ( $paged > 1 ) {
                echo '<a class="first-page button" href="' . esc_url( $first_url ) . '"><span class="screen-reader-text">' . esc_html__( 'First page', 'bloglogistics-markdown-for-agents' ) . '</span><span aria-hidden="true">«</span></a>';
                echo '<a class="prev-page button" href="' . esc_url( $prev_url ) . '"><span class="screen-reader-text">' . esc_html__( 'Previous page', 'bloglogistics-markdown-for-agents' ) . '</span><span aria-hidden="true">‹</span></a>';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>';
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
            }

            echo '<span class="paging-input"><span class="tablenav-paging-text">' . sprintf(
                esc_html__( '%1$s of %2$s', 'bloglogistics-markdown-for-agents' ),
                '<span class="current-page">' . esc_html( number_format_i18n( $paged ) ) . '</span>',
                '<span class="total-pages">' . esc_html( number_format_i18n( $total_pages ) ) . '</span>'
            ) . '</span></span>';

            if ( $paged < $total_pages ) {
                echo '<a class="next-page button" href="' . esc_url( $next_url ) . '"><span class="screen-reader-text">' . esc_html__( 'Next page', 'bloglogistics-markdown-for-agents' ) . '</span><span aria-hidden="true">›</span></a>';
                echo '<a class="last-page button" href="' . esc_url( $last_url ) . '"><span class="screen-reader-text">' . esc_html__( 'Last page', 'bloglogistics-markdown-for-agents' ) . '</span><span aria-hidden="true">»</span></a>';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
            }
            echo '</span>';
        }

        echo '</div><br class="clear"></div>';
    }

    /**
     * Render either the Pages or Posts management tab.
     *
     * @param array<string,mixed> $health      Saved health snapshot.
     * @param array<int,mixed>    $live_items  Saved live results.
     */
    private static function render_content_tab( string $post_type, string $tab, string $view, array $health, array $live_items ): void {
        $ids      = self::published_ids_by_type( $post_type );
        $items    = isset( $health['items'] ) && is_array( $health['items'] ) ? $health['items'] : [];
        $counts   = self::content_filter_counts( $ids, $health, $live_items );
        $per_page = self::settings_per_page();
        $paged    = self::settings_paged();
        $labels = [
            'all'       => __( 'All', 'bloglogistics-markdown-for-agents' ),
            'attention' => __( 'Needs attention', 'bloglogistics-markdown-for-agents' ),
            'missing'   => __( 'Missing', 'bloglogistics-markdown-for-agents' ),
            'stale'     => __( 'Stale', 'bloglogistics-markdown-for-agents' ),
            'encoding'  => __( 'Encoding', 'bloglogistics-markdown-for-agents' ),
            'live'      => __( 'Live delivery', 'bloglogistics-markdown-for-agents' ),
            'discovery' => __( 'Discovery', 'bloglogistics-markdown-for-agents' ),
            'disabled'  => __( 'Disabled', 'bloglogistics-markdown-for-agents' ),
        ];
        $visible_ids = [];

        foreach ( $ids as $post_id ) {
            $item      = isset( $items[ $post_id ] ) && is_array( $items[ $post_id ] ) ? $items[ $post_id ] : [];
            $live_item = isset( $live_items[ $post_id ] ) && is_array( $live_items[ $post_id ] ) ? $live_items[ $post_id ] : [];
            $state     = self::content_item_state( (int) $post_id, $item, $live_item );
            if ( self::state_matches_view( $state, $view ) ) {
                $visible_ids[] = (int) $post_id;
            }
        }

        $total_visible = count( $visible_ids );
        $total_pages   = max( 1, (int) ceil( $total_visible / max( 1, $per_page ) ) );
        $paged         = min( $paged, $total_pages );
        $offset        = ( $paged - 1 ) * $per_page;
        $page_ids      = array_slice( $visible_ids, $offset, $per_page );

        echo '<h2>' . esc_html( 'page' === $post_type ? __( 'Pages', 'bloglogistics-markdown-for-agents' ) : __( 'Posts', 'bloglogistics-markdown-for-agents' ) ) . '</h2>';
        echo '<p>' . esc_html__( 'Review Markdown health and discovery settings here. Markdown File tells you whether the curated /index.md file exists. Live Delivery uses your administrator browser to check whether the public HTML page and Markdown file load correctly and whether Markdown is served with the expected MIME type.', 'bloglogistics-markdown-for-agents' ) . '</p>';
        echo '<p class="description">' . esc_html__( 'Discovery is a separate check of the HTML <head>: it confirms whether the page advertises its Markdown file and llms.txt using discovery links. A Markdown file can be perfectly reachable even when discovery markup is missing or stale in cached HTML.', 'bloglogistics-markdown-for-agents' ) . '</p>';
        echo '<p class="description">' . esc_html__( 'Large lists are paginated. Choose 20, 50, or 100 items per page; your choice is remembered for your administrator account.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        echo '<ul class="subsubsub bl-mfa-filters">';
        $parts = [];
        foreach ( $labels as $key => $label ) {
            $class   = $view === $key ? ' class="current" aria-current="page"' : '';
            $parts[] = '<li><a' . $class . ' href="' . esc_url( self::settings_url( $tab, $key, [ 'paged' => 1, 'mfa_per_page' => $per_page ] ) ) . '">' . esc_html( $label ) . ' <span class="count">(' . esc_html( number_format_i18n( $counts[ $key ] ) ) . ')</span></a></li>';
        }
        echo implode( ' | ', $parts );
        echo '</ul><div class="clear"></div>';

        self::render_scan_actions( $tab, $view );

        if ( ! $ids ) {
            echo '<p>' . esc_html__( 'No published content exists in this section.', 'bloglogistics-markdown-for-agents' ) . '</p>';
            return;
        }

        if ( ! $visible_ids ) {
            self::render_content_pagination( $post_type, $tab, $view, 1, $per_page, 0, true );
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No items match this filter.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
            return;
        }

        self::render_content_pagination( $post_type, $tab, $view, $paged, $per_page, $total_visible, true );

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bloglogistics_mfa_save_exclusions' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_save_exclusions">';
        echo '<input type="hidden" name="return_tab" value="' . esc_attr( $tab ) . '">';
        echo '<input type="hidden" name="return_view" value="' . esc_attr( $view ) . '">';
        echo '<input type="hidden" name="return_paged" value="' . esc_attr( (string) $paged ) . '">';
        echo '<input type="hidden" name="return_per_page" value="' . esc_attr( (string) $per_page ) . '">';

        // Only the rows on the current page are managed by this form. This is
        // important with pagination: saving page 2 must never alter discovery
        // choices on page 1 or page 3.
        foreach ( $page_ids as $post_id ) {
            echo '<input type="hidden" name="managed_ids[]" value="' . esc_attr( (string) $post_id ) . '">';
        }

        echo '<div class="bl-mfa-actions"><select name="bulk_action">';
        echo '<option value="">' . esc_html__( 'Bulk actions', 'bloglogistics-markdown-for-agents' ) . '</option>';
        echo '<option value="enable">' . esc_html__( 'Enable discovery', 'bloglogistics-markdown-for-agents' ) . '</option>';
        echo '<option value="disable">' . esc_html__( 'Disable discovery', 'bloglogistics-markdown-for-agents' ) . '</option>';
        echo '<option value="rescan">' . esc_html__( 'Force revalidate selected', 'bloglogistics-markdown-for-agents' ) . '</option>';
        echo '<option value="verify">' . esc_html__( 'Live verify selected', 'bloglogistics-markdown-for-agents' ) . '</option>';
        echo '</select><button type="submit" class="button" name="apply_bulk" value="1">' . esc_html__( 'Apply', 'bloglogistics-markdown-for-agents' ) . '</button></div>';

        echo '<div class="bl-mfa-table-wrap"><table class="widefat striped bl-mfa-health-table"><thead><tr>';
        echo '<td class="check-column"><input type="checkbox" class="bl-mfa-select-all" aria-label="' . esc_attr__( 'Select all visible items', 'bloglogistics-markdown-for-agents' ) . '"></td>';
        echo '<th>' . esc_html__( 'Title', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Markdown File', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Freshness', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Encoding', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Live Delivery', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Discovery', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $page_ids as $post_id ) {
            $item      = isset( $items[ $post_id ] ) && is_array( $items[ $post_id ] ) ? $items[ $post_id ] : [];
            $live_item = isset( $live_items[ $post_id ] ) && is_array( $live_items[ $post_id ] ) ? $live_items[ $post_id ] : [];
            $state     = self::content_item_state( $post_id, $item, $live_item );
            $title      = get_the_title( $post_id );
            $md_url     = (string) $state['markdown_url'];
            $edit_md_url = self::settings_url(
                $tab,
                $view,
                [
                    'edit_file'    => 'markdown',
                    'post_id'      => $post_id,
                    'paged'        => $paged,
                    'mfa_per_page' => $per_page,
                ]
            );
            $verify_url = wp_nonce_url(
                add_query_arg(
                    [
                        'action'      => 'bloglogistics_mfa_verify_item',
                        'post_id'     => $post_id,
                        'return_tab'      => $tab,
                        'return_view'     => $view,
                        'return_paged'    => $paged,
                        'return_per_page' => $per_page,
                    ],
                    admin_url( 'admin-post.php' )
                ),
                'bloglogistics_mfa_verify_item_' . $post_id
            );

            echo '<tr><th scope="row" class="check-column"><input class="bl-mfa-row-select" type="checkbox" name="selected_ids[]" value="' . esc_attr( (string) $post_id ) . '" aria-label="' . esc_attr( sprintf( __( 'Select %s', 'bloglogistics-markdown-for-agents' ), $title ) ) . '"></th><td class="column-title"><strong>' . esc_html( $title ) . '</strong>';
            if ( ! empty( $item['markdown_relative'] ) ) {
                echo '<span class="bl-mfa-health-detail"><code>/' . esc_html( ltrim( (string) $item['markdown_relative'], '/' ) ) . '</code></span>';
            }
            echo '<div class="row-actions">';
            $row_actions = [];
            if ( ! empty( $state['exists'] ) && '' !== $md_url ) {
                $row_actions[] = '<a href="' . esc_url( $edit_md_url ) . '">' . esc_html__( 'Edit Markdown', 'bloglogistics-markdown-for-agents' ) . '</a>';
                $row_actions[] = '<a href="' . esc_url( $md_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Markdown', 'bloglogistics-markdown-for-agents' ) . '</a>';
                $row_actions[] = '<a href="' . esc_url( $verify_url ) . '">' . esc_html__( 'Verify', 'bloglogistics-markdown-for-agents' ) . '</a>';
            }
            echo implode( ' | ', $row_actions );
            echo '</div></td>';

            echo '<td>';
            if ( empty( $state['scanned'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'Not scanned yet', 'bloglogistics-markdown-for-agents' ) );
            } elseif ( ! empty( $state['exists'] ) ) {
                echo self::status_markup( 'healthy', __( 'Healthy', 'bloglogistics-markdown-for-agents' ), __( 'Markdown file found', 'bloglogistics-markdown-for-agents' ) );
            } elseif ( ! empty( $state['disabled'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'No Markdown file; discovery is disabled', 'bloglogistics-markdown-for-agents' ) );
            } else {
                echo self::status_markup( 'problem', __( 'Problem', 'bloglogistics-markdown-for-agents' ), __( 'Markdown file missing', 'bloglogistics-markdown-for-agents' ) );
            }
            echo '</td>';

            echo '<td>';
            if ( empty( $state['exists'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'No Markdown file to compare', 'bloglogistics-markdown-for-agents' ) );
            } elseif ( ! empty( $state['stale'] ) ) {
                $post_modified = isset( $item['post_modified'] ) ? (int) $item['post_modified'] : 0;
                $md_modified   = isset( $item['markdown_modified'] ) ? (int) $item['markdown_modified'] : 0;
                $detail        = __( 'WordPress content is newer than Markdown', 'bloglogistics-markdown-for-agents' );
                if ( $post_modified && $md_modified ) {
                    $detail .= ' (' . wp_date( 'Y-m-d H:i', $post_modified ) . ' / ' . wp_date( 'Y-m-d H:i', $md_modified ) . ')';
                }
                echo self::status_markup( 'review', __( 'Review', 'bloglogistics-markdown-for-agents' ), $detail );
            } else {
                echo self::status_markup( 'healthy', __( 'Healthy', 'bloglogistics-markdown-for-agents' ), __( 'Current by timestamp', 'bloglogistics-markdown-for-agents' ) );
            }
            echo '</td>';

            echo '<td>';
            if ( empty( $state['exists'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'No Markdown file to validate', 'bloglogistics-markdown-for-agents' ) );
            } elseif ( ! empty( $state['encoding_bad'] ) ) {
                $issue_labels = [];
                foreach ( (array) ( $state['encoding']['issues'] ?? [] ) as $issue ) {
                    $issue_labels[] = self::encoding_issue_label( (string) $issue );
                }
                echo self::status_markup( 'problem', __( 'Problem', 'bloglogistics-markdown-for-agents' ), implode( ' ', $issue_labels ) );
            } elseif ( ! empty( $state['encoding_bom'] ) ) {
                echo self::status_markup( 'review', __( 'Review', 'bloglogistics-markdown-for-agents' ), __( 'Valid UTF-8, but a BOM was detected', 'bloglogistics-markdown-for-agents' ) );
            } else {
                $detail = __( 'Valid UTF-8', 'bloglogistics-markdown-for-agents' );
                if ( ! empty( $item['validation_reused'] ) ) {
                    $detail .= '; ' . __( 'unchanged result reused', 'bloglogistics-markdown-for-agents' );
                }
                echo self::status_markup( 'healthy', __( 'Healthy', 'bloglogistics-markdown-for-agents' ), $detail );
            }
            echo '</td>';

            echo '<td>';
            if ( empty( $state['exists'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'No Markdown file to verify', 'bloglogistics-markdown-for-agents' ) );
            } elseif ( empty( $state['live'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'Not live-verified yet', 'bloglogistics-markdown-for-agents' ) );
            } else {
                $live_detail = self::live_delivery_detail( $live_item );

                if ( ! empty( $state['live_outdated'] ) ) {
                    echo self::status_markup( 'na', __( 'Not verified', 'bloglogistics-markdown-for-agents' ), __( 'Saved verification is from an earlier verifier or content state. Run live verification to refresh it.', 'bloglogistics-markdown-for-agents' ) );
                } elseif ( ! empty( $state['live_inconclusive'] ) ) {
                    $request_labels = [];
                    foreach ( (array) $state['delivery_warnings'] as $warning ) {
                        if ( in_array( (string) $warning, [ 'html_request_error', 'markdown_request_error' ], true ) ) {
                            $request_labels[] = self::live_issue_label( (string) $warning );
                        }
                    }
                    $request_detail = $request_labels ? implode( ' ', array_unique( $request_labels ) ) : __( 'The browser could not complete the public verification request.', 'bloglogistics-markdown-for-agents' );
                    echo self::status_markup( 'na', __( 'Not verified', 'bloglogistics-markdown-for-agents' ), $request_detail );
                } elseif ( ! empty( $state['live_bad'] ) ) {
                    $issue_labels = [];
                    foreach ( (array) $state['delivery_issues'] as $issue ) {
                        $issue_labels[] = self::live_issue_label( (string) $issue );
                    }
                    if ( $issue_labels ) {
                        $live_detail .= '; ' . implode( ' ', $issue_labels );
                    }
                    echo self::status_markup( 'problem', __( 'Problem', 'bloglogistics-markdown-for-agents' ), $live_detail );
                } elseif ( ! empty( $state['live_review'] ) ) {
                    $warning_labels = [];
                    foreach ( (array) ( $state['severity_delivery_warnings'] ?? [] ) as $warning ) {
                        $warning_labels[] = 'markdown_mime' === (string) $warning
                            ? __( 'A usable, non-preferred Markdown MIME type was returned.', 'bloglogistics-markdown-for-agents' )
                            : self::live_issue_label( (string) $warning );
                    }
                    if ( $warning_labels ) {
                        $live_detail .= '; ' . implode( ' ', $warning_labels );
                    }
                    echo self::status_markup( 'review', __( 'Review', 'bloglogistics-markdown-for-agents' ), $live_detail );
                } else {
                    echo self::status_markup( 'healthy', __( 'Healthy', 'bloglogistics-markdown-for-agents' ), $live_detail );
                }
            }
            echo '</td>';

            echo '<td>';
            if ( empty( $state['exists'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'Not advertised without a Markdown file', 'bloglogistics-markdown-for-agents' ) );
            } elseif ( empty( $state['live'] ) ) {
                $detail = ! empty( $state['disabled'] )
                    ? __( 'Discovery disabled by choice; not live-verified yet', 'bloglogistics-markdown-for-agents' )
                    : __( 'Not live-verified yet', 'bloglogistics-markdown-for-agents' );
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), $detail );
            } elseif ( 200 !== (int) ( $live_item['html_status'] ?? 0 ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'Discovery could not be checked because the HTML page did not return HTTP 200', 'bloglogistics-markdown-for-agents' ) );
            } else {
                $discovery_detail = self::discovery_detail( $live_item );

                if ( ! empty( $state['live_outdated'] ) ) {
                    echo self::status_markup( 'na', __( 'Not verified', 'bloglogistics-markdown-for-agents' ), __( 'Saved discovery verification is from an earlier verifier or content state. Run live verification to refresh it.', 'bloglogistics-markdown-for-agents' ) );
                } elseif ( ! empty( $state['discovery_bad'] ) ) {
                    $issue_labels = [];
                    foreach ( (array) $state['discovery_issues'] as $issue ) {
                        $issue_labels[] = self::live_issue_label( (string) $issue );
                    }
                    if ( $issue_labels ) {
                        $discovery_detail .= '; ' . implode( ' ', $issue_labels );
                    }
                    echo self::status_markup( 'problem', __( 'Problem', 'bloglogistics-markdown-for-agents' ), $discovery_detail );
                } elseif ( ! empty( $state['discovery_review'] ) ) {
                    $warning_labels = [];
                    foreach ( (array) $state['discovery_warnings'] as $warning ) {
                        $warning_labels[] = self::live_issue_label( (string) $warning );
                    }
                    if ( $warning_labels ) {
                        $discovery_detail .= '; ' . implode( ' ', $warning_labels );
                    }
                    echo self::status_markup( 'review', __( 'Review', 'bloglogistics-markdown-for-agents' ), $discovery_detail );
                } elseif ( ! empty( $state['disabled'] ) ) {
                    echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), $discovery_detail );
                } else {
                    echo self::status_markup( 'healthy', __( 'Healthy', 'bloglogistics-markdown-for-agents' ), $discovery_detail );
                }
            }
            echo '<label class="bl-mfa-discovery-toggle"><input type="checkbox" name="disabled_ids[]" value="' . esc_attr( (string) $post_id ) . '" ' . checked( ! empty( $state['disabled'] ), true, false ) . '> ' . esc_html__( 'Disable discovery', 'bloglogistics-markdown-for-agents' ) . '</label>';
            echo '</td></tr>';
        }

        echo '</tbody></table></div>';
        submit_button( __( 'Save Discovery Choices', 'bloglogistics-markdown-for-agents' ) );
        echo '</form>';
        self::render_content_pagination( $post_type, $tab, $view, $paged, $per_page, $total_visible, false );
    }

    /**
     * Render the dedicated llms.txt validation tab.
     *
     * @param array<string,mixed> $health Saved health snapshot.
     */
    private static function render_llms_tab( array $health ): void {
        $health_ready = ! empty( $health['scanned_at'] );
        $llms         = isset( $health['llms'] ) && is_array( $health['llms'] ) ? $health['llms'] : [];
        $exists       = ! empty( $llms['exists'] );
        $valid        = ! empty( $llms['valid'] );
        $encoding     = isset( $llms['encoding'] ) && is_array( $llms['encoding'] ) ? $llms['encoding'] : [];
        $errors       = isset( $llms['errors'] ) && is_array( $llms['errors'] ) ? $llms['errors'] : [];
        $warnings     = isset( $llms['warnings'] ) && is_array( $llms['warnings'] ) ? $llms['warnings'] : [];
        $broken       = isset( $llms['broken_local_links'] ) && is_array( $llms['broken_local_links'] ) ? $llms['broken_local_links'] : [];
        $duplicates   = isset( $llms['duplicate_links'] ) && is_array( $llms['duplicate_links'] ) ? $llms['duplicate_links'] : [];
        $local_links  = isset( $llms['local_links'] ) && is_array( $llms['local_links'] ) ? $llms['local_links'] : [];

        echo '<h2>' . esc_html__( 'llms.txt', 'bloglogistics-markdown-for-agents' ) . '</h2>';
        echo '<p>' . esc_html__( 'This tab validates your user-managed llms.txt file. The plugin never generates or curates its contents automatically; the optional plain editor saves only what an administrator explicitly enters.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        if ( $exists ) {
            $edit_llms_url = self::settings_url( 'llms', 'all', [ 'edit_file' => 'llms' ] );
            echo '<div class="bl-mfa-file-actions">';
            echo '<a class="button button-primary" href="' . esc_url( $edit_llms_url ) . '">' . esc_html__( 'Edit llms.txt', 'bloglogistics-markdown-for-agents' ) . '</a>';
            echo '<a class="button" href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View llms.txt', 'bloglogistics-markdown-for-agents' ) . '</a>';
            echo '</div>';
        }

        echo '<div class="bl-mfa-health-grid">';
        self::render_dashboard_card( __( 'File', 'bloglogistics-markdown-for-agents' ), $exists ? 1 : 0, ! $health_ready ? 'na' : ( $exists ? 'healthy' : 'problem' ), [], $exists ? __( 'Present', 'bloglogistics-markdown-for-agents' ) : __( 'Missing', 'bloglogistics-markdown-for-agents' ) );
        self::render_dashboard_card( __( 'Validation problems', 'bloglogistics-markdown-for-agents' ), count( $errors ), ! $health_ready ? 'na' : ( $errors ? 'problem' : 'healthy' ), [], $valid ? __( 'Validation passed', 'bloglogistics-markdown-for-agents' ) : __( 'Needs attention', 'bloglogistics-markdown-for-agents' ) );
        $encoding_status = ! $health_ready || ! $exists ? 'na' : ( empty( $encoding['valid'] ) ? 'problem' : ( ! empty( $encoding['bom'] ) ? 'review' : 'healthy' ) );
        self::render_dashboard_card( __( 'Encoding', 'bloglogistics-markdown-for-agents' ), empty( $encoding['valid'] ) ? 0 : 1, $encoding_status, [], ! $exists ? __( 'Not applicable', 'bloglogistics-markdown-for-agents' ) : ( ! empty( $encoding['bom'] ) ? __( 'Valid UTF-8 with BOM warning', 'bloglogistics-markdown-for-agents' ) : __( 'Valid UTF-8', 'bloglogistics-markdown-for-agents' ) ) );
        self::render_dashboard_card( __( 'Local Markdown links', 'bloglogistics-markdown-for-agents' ), count( $local_links ), ! $health_ready ? 'na' : ( $broken ? 'problem' : 'healthy' ), [], $broken ? sprintf( _n( '%d broken reference', '%d broken references', count( $broken ), 'bloglogistics-markdown-for-agents' ), count( $broken ) ) : __( 'No broken local references', 'bloglogistics-markdown-for-agents' ) );
        self::render_dashboard_card( __( 'Duplicate links', 'bloglogistics-markdown-for-agents' ), count( $duplicates ), ! $health_ready ? 'na' : ( $duplicates ? 'review' : 'healthy' ), [], $duplicates ? __( 'Review duplicate entries', 'bloglogistics-markdown-for-agents' ) : __( 'No duplicates detected', 'bloglogistics-markdown-for-agents' ) );
        echo '</div>';

        self::render_scan_actions( 'llms', 'all' );

        if ( ! $health_ready ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Run the Markdown scan to validate llms.txt and populate its local-reference table.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
            return;
        }

        if ( $errors ) {
            echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Problems:', 'bloglogistics-markdown-for-agents' ) . '</strong></p><ul class="bl-mfa-issue-list">';
            foreach ( $errors as $issue ) {
                if ( is_array( $issue ) ) {
                    echo '<li>' . esc_html( self::llms_issue_label( $issue ) ) . '</li>';
                }
            }
            echo '</ul></div>';
        }
        if ( $warnings ) {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Review:', 'bloglogistics-markdown-for-agents' ) . '</strong></p><ul class="bl-mfa-issue-list">';
            foreach ( $warnings as $issue ) {
                if ( is_array( $issue ) ) {
                    echo '<li>' . esc_html( self::llms_issue_label( $issue ) ) . '</li>';
                }
            }
            echo '</ul></div>';
        }

        echo '<h3>' . esc_html__( 'Local Markdown References', 'bloglogistics-markdown-for-agents' ) . '</h3>';
        if ( ! $local_links ) {
            if ( ! empty( $llms['local_links_checked'] ) ) {
                echo '<p>' . esc_html__( 'Reference details were not stored by the previous plugin version. Run the scan once with v2.4.0 to populate this table.', 'bloglogistics-markdown-for-agents' ) . '</p>';
            } else {
                echo '<p>' . esc_html__( 'No same-site Markdown links were found in llms.txt.', 'bloglogistics-markdown-for-agents' ) . '</p>';
            }
            return;
        }

        echo '<div class="bl-mfa-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Markdown reference', 'bloglogistics-markdown-for-agents' ) . '</th><th>' . esc_html__( 'Status', 'bloglogistics-markdown-for-agents' ) . '</th></tr></thead><tbody>';
        foreach ( $local_links as $link ) {
            if ( ! is_array( $link ) || empty( $link['url'] ) ) {
                continue;
            }
            $link_exists = ! empty( $link['exists'] );
            echo '<tr><td class="bl-mfa-code-wrap"><a href="' . esc_url( (string) $link['url'] ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( (string) $link['url'] ) . '</code></a></td><td>';
            echo self::status_markup( $link_exists ? 'healthy' : 'problem', $link_exists ? __( 'Healthy', 'bloglogistics-markdown-for-agents' ) : __( 'Problem', 'bloglogistics-markdown-for-agents' ), $link_exists ? __( 'Local file found', 'bloglogistics-markdown-for-agents' ) : __( 'Local file not found', 'bloglogistics-markdown-for-agents' ) );
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * Render server diagnostics and .htaccess tools.
     *
     * @param array<string,mixed> $server          Server diagnostics.
     * @param array<string,mixed> $htaccess_status Saved .htaccess status.
     * @param array<int,mixed>    $backups         Plugin-created backups.
     */
    private static function render_server_tab( array $server, array $htaccess_status, array $backups ): void {
        $server_type_label = match ( (string) ( $server['server_type'] ?? '' ) ) {
            'apache'    => 'Apache',
            'litespeed' => 'LiteSpeed',
            'nginx'     => 'Nginx',
            'iis'       => 'Microsoft IIS',
            default     => __( 'Unknown', 'bloglogistics-markdown-for-agents' ),
        };
        $supports_htaccess      = ! empty( $server['supports_htaccess'] );
        $writable               = ! empty( $server['htaccess_writable'] );
        $rewrite_verified       = ! empty( $htaccess_status['rewrite_rule_verified'] );
        $mime_rule_verified     = ! empty( $htaccess_status['mime_rule_verified'] );
        $live_available         = ! empty( $htaccess_status['live_available'] );
        $live_verified          = ! empty( $htaccess_status['live_verified'] );
        $mime_live_verified     = ! empty( $htaccess_status['mime_live_verified'] );
        $markdown_content_type  = isset( $htaccess_status['markdown_content_type'] ) ? (string) $htaccess_status['markdown_content_type'] : '';
        $verified_at            = isset( $htaccess_status['verified_at'] ) ? (int) $htaccess_status['verified_at'] : 0;

        echo '<h2>' . esc_html__( 'Server & .htaccess', 'bloglogistics-markdown-for-agents' ) . '</h2>';
        echo '<p>' . esc_html__( 'Technical diagnostics and server-rule management live here so everyday Pages and Posts work remains uncluttered.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        echo '<div class="bl-mfa-health-grid">';
        self::render_dashboard_card( __( 'Server', 'bloglogistics-markdown-for-agents' ), $server_type_label, 'na', [], __( 'Detected server family', 'bloglogistics-markdown-for-agents' ) );
        self::render_dashboard_card( __( '.htaccess', 'bloglogistics-markdown-for-agents' ), $supports_htaccess ? 1 : 0, $supports_htaccess ? ( $writable ? 'healthy' : 'review' ) : 'na', [], $supports_htaccess ? ( $writable ? __( 'Available and writable', 'bloglogistics-markdown-for-agents' ) : __( 'Available but not writable', 'bloglogistics-markdown-for-agents' ) ) : __( 'Not used by this server', 'bloglogistics-markdown-for-agents' ) );
        self::render_dashboard_card( __( 'Permalink rule', 'bloglogistics-markdown-for-agents' ), $rewrite_verified ? 1 : 0, ! $supports_htaccess ? 'na' : ( $rewrite_verified ? 'healthy' : 'review' ), [], $rewrite_verified ? __( 'Present and verified', 'bloglogistics-markdown-for-agents' ) : __( 'Not verified', 'bloglogistics-markdown-for-agents' ) );
        self::render_dashboard_card( __( 'Markdown MIME rule', 'bloglogistics-markdown-for-agents' ), $mime_rule_verified ? 1 : 0, ! $supports_htaccess ? 'na' : ( $mime_rule_verified ? 'healthy' : 'review' ), [], $mime_rule_verified ? __( 'Present and verified', 'bloglogistics-markdown-for-agents' ) : __( 'Not verified', 'bloglogistics-markdown-for-agents' ) );
        echo '</div>';

        echo '<h3>' . esc_html__( 'Server Compatibility', 'bloglogistics-markdown-for-agents' ) . '</h3>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__( 'Detected server', 'bloglogistics-markdown-for-agents' ) . '</th><td><strong>' . esc_html( $server_type_label ) . '</strong>';
        if ( ! empty( $server['software'] ) ) {
            echo '<span class="bl-mfa-health-detail">' . esc_html( (string) $server['software'] ) . '</span>';
        }
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__( '.htaccess automation', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . self::status_markup( $supports_htaccess ? 'healthy' : 'na', $supports_htaccess ? __( 'Healthy', 'bloglogistics-markdown-for-agents' ) : __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), $supports_htaccess ? __( 'Supported', 'bloglogistics-markdown-for-agents' ) : __( 'Manual server configuration required', 'bloglogistics-markdown-for-agents' ) ) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'mod_rewrite', 'bloglogistics-markdown-for-agents' ) . '</th><td><code>' . esc_html( (string) ( $server['mod_rewrite'] ?? 'unknown' ) ) . '</code></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'mod_mime', 'bloglogistics-markdown-for-agents' ) . '</th><td><code>' . esc_html( (string) ( $server['mod_mime'] ?? 'unknown' ) ) . '</code></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( '.htaccess writable', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . self::status_markup( ! $supports_htaccess ? 'na' : ( $writable ? 'healthy' : 'review' ), ! $supports_htaccess ? __( 'Not applicable', 'bloglogistics-markdown-for-agents' ) : ( $writable ? __( 'Healthy', 'bloglogistics-markdown-for-agents' ) : __( 'Review', 'bloglogistics-markdown-for-agents' ) ), ! $supports_htaccess ? __( 'Not used by this server', 'bloglogistics-markdown-for-agents' ) : ( $writable ? __( 'Writable', 'bloglogistics-markdown-for-agents' ) : __( 'Not writable', 'bloglogistics-markdown-for-agents' ) ) ) . '</td></tr>';
        echo '</tbody></table>';

        if ( in_array( (string) ( $server['server_type'] ?? '' ), [ 'nginx', 'iis' ], true ) ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This server does not use Apache .htaccess rules. The plugin will not try to install the permalink compatibility or Markdown MIME rules. Equivalent configuration must be set at the web-server level.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
        }

        echo '<h3>' . esc_html__( 'WordPress / Markdown .htaccess Rules', 'bloglogistics-markdown-for-agents' ) . '</h3>';
        echo '<p>' . esc_html__( 'On Apache-compatible servers, the plugin maintains the permalink compatibility rule and the UTF-8 text/markdown MIME rule. Equivalent manually added directives are detected wherever they appear and are never duplicated.', 'bloglogistics-markdown-for-agents' ) . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__( 'Permalink compatibility rule', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . self::status_markup( ! $supports_htaccess ? 'na' : ( $rewrite_verified ? 'healthy' : 'review' ), ! $supports_htaccess ? __( 'Not applicable', 'bloglogistics-markdown-for-agents' ) : ( $rewrite_verified ? __( 'Healthy', 'bloglogistics-markdown-for-agents' ) : __( 'Review', 'bloglogistics-markdown-for-agents' ) ), $rewrite_verified ? __( 'Present and verified', 'bloglogistics-markdown-for-agents' ) : __( 'Not verified', 'bloglogistics-markdown-for-agents' ) ) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Markdown MIME rule', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . self::status_markup( ! $supports_htaccess ? 'na' : ( $mime_rule_verified ? 'healthy' : 'review' ), ! $supports_htaccess ? __( 'Not applicable', 'bloglogistics-markdown-for-agents' ) : ( $mime_rule_verified ? __( 'Healthy', 'bloglogistics-markdown-for-agents' ) : __( 'Review', 'bloglogistics-markdown-for-agents' ) ), $mime_rule_verified ? __( 'Present and verified', 'bloglogistics-markdown-for-agents' ) : __( 'Not verified', 'bloglogistics-markdown-for-agents' ) ) . '</td></tr>';
        if ( ! empty( $htaccess_status['backup_file'] ) ) {
            echo '<tr><th scope="row">' . esc_html__( 'Most recent backup', 'bloglogistics-markdown-for-agents' ) . '</th><td><code>' . esc_html( basename( (string) $htaccess_status['backup_file'] ) ) . '</code></td></tr>';
        }
        if ( $live_available ) {
            $live_status = $live_verified && $mime_live_verified ? 'healthy' : 'problem';
            $live_label  = $live_verified && $mime_live_verified ? __( 'Healthy', 'bloglogistics-markdown-for-agents' ) : __( 'Problem', 'bloglogistics-markdown-for-agents' );
            $live_detail = sprintf(
                __( 'HTML %1$d; Markdown %2$d', 'bloglogistics-markdown-for-agents' ),
                (int) ( $htaccess_status['html_status'] ?? 0 ),
                (int) ( $htaccess_status['markdown_status'] ?? 0 )
            );
            if ( '' !== $markdown_content_type ) {
                $live_detail .= '; ' . $markdown_content_type;
            }
            echo '<tr><th scope="row">' . esc_html__( 'Live server-rule test', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . self::status_markup( $live_status, $live_label, $live_detail ) . '</td></tr>';
        } elseif ( $rewrite_verified && $mime_rule_verified ) {
            echo '<tr><th scope="row">' . esc_html__( 'Live server-rule test', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'Waiting for a detected non-homepage Markdown file', 'bloglogistics-markdown-for-agents' ) ) . '</td></tr>';
        }
        if ( $verified_at ) {
            echo '<tr><th scope="row">' . esc_html__( 'Last .htaccess check', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . esc_html( wp_date( 'Y-m-d H:i:s T', $verified_at ) ) . '</td></tr>';
        }
        if ( ! empty( $htaccess_status['message'] ) ) {
            echo '<tr><th scope="row">' . esc_html__( 'Status message', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . esc_html( (string) $htaccess_status['message'] ) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bloglogistics_mfa_htaccess' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_htaccess">';
        echo '<input type="hidden" name="return_tab" value="server">';
        echo '<input type="hidden" name="return_view" value="all">';
        submit_button( __( 'Install / Repair and Verify .htaccess Rules', 'bloglogistics-markdown-for-agents' ), 'secondary', 'submit', false );
        echo '</form>';
        echo '<p class="description">' . esc_html__( 'Before adding a genuinely missing rule to an existing .htaccess file, the plugin creates a timestamped backup. Existing equivalent directives are left untouched.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        echo '<h3 style="margin-top:1.5em;">' . esc_html__( '.htaccess Backup Management', 'bloglogistics-markdown-for-agents' ) . '</h3>';
        if ( ! $backups ) {
            echo '<p>' . esc_html__( 'No plugin-created .htaccess backups were found.', 'bloglogistics-markdown-for-agents' ) . '</p>';
            return;
        }

        echo '<p>';
        printf(
            esc_html__( '%d plugin-created .htaccess backups are present. Cleanup always preserves the newest three.', 'bloglogistics-markdown-for-agents' ),
            count( $backups )
        );
        echo '</p>';
        echo '<div class="bl-mfa-table-wrap"><table class="widefat striped" style="max-width:1000px"><thead><tr><th>' . esc_html__( 'Backup', 'bloglogistics-markdown-for-agents' ) . '</th><th>' . esc_html__( 'Created', 'bloglogistics-markdown-for-agents' ) . '</th><th>' . esc_html__( 'Size', 'bloglogistics-markdown-for-agents' ) . '</th><th>' . esc_html__( 'Retention', 'bloglogistics-markdown-for-agents' ) . '</th></tr></thead><tbody>';
        foreach ( array_slice( $backups, 0, 10 ) as $index => $backup ) {
            echo '<tr><td><code>' . esc_html( $backup['name'] ) . '</code></td><td>' . esc_html( $backup['mtime'] ? wp_date( 'Y-m-d H:i:s T', $backup['mtime'] ) : __( 'Unknown', 'bloglogistics-markdown-for-agents' ) ) . '</td><td>' . esc_html( size_format( max( 0, (int) $backup['size'] ) ) ) . '</td><td>' . esc_html( $index < self::BACKUP_RETAIN_COUNT ? __( 'Protected newest backup', 'bloglogistics-markdown-for-agents' ) : __( 'Eligible for cleanup', 'bloglogistics-markdown-for-agents' ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ( count( $backups ) > self::BACKUP_RETAIN_COUNT ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;">';
            wp_nonce_field( 'bloglogistics_mfa_cleanup_backups' );
            echo '<input type="hidden" name="action" value="bloglogistics_mfa_cleanup_backups">';
            echo '<input type="hidden" name="return_tab" value="server">';
            echo '<input type="hidden" name="return_view" value="all">';
            submit_button( __( 'Delete Old Backups, Keep Newest 3', 'bloglogistics-markdown-for-agents' ), 'secondary', 'submit', false );
            echo '</form>';
        }
    }

    /**
     * Render the deliberately plain editor for one existing Markdown file or
     * llms.txt. This editor never converts WordPress content into Markdown and
     * never creates missing curated files.
     */
    private static function render_text_editor( string $type, int $post_id, string $tab, string $view ): void {
        $target   = self::editable_text_target( $type, $post_id );
        $paged    = self::settings_paged();
        $per_page = self::settings_per_page();
        $back_extra = in_array( $tab, [ 'pages', 'posts' ], true )
            ? [ 'paged' => $paged, 'mfa_per_page' => $per_page ]
            : [];

        if ( is_wp_error( $target ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $target->get_error_message() ) . '</p></div>';
            echo '<p><a class="button" href="' . esc_url( self::settings_url( $tab, $view, $back_extra ) ) . '">' . esc_html__( 'Back', 'bloglogistics-markdown-for-agents' ) . '</a></p>';
            return;
        }

        $file     = (string) $target['file'];
        $contents = @file_get_contents( $file );
        if ( ! is_string( $contents ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The file could not be read.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
            return;
        }

        if ( str_starts_with( $contents, "\xEF\xBB\xBF" ) ) {
            $contents = substr( $contents, 3 );
        }

        $modified = @filemtime( $file );
        $size     = @filesize( $file );
        $writable = is_writable( $file ) && is_writable( dirname( $file ) );
        $heading  = 'llms' === $type
            ? __( 'Edit llms.txt', 'bloglogistics-markdown-for-agents' )
            : sprintf( __( 'Edit Markdown: %s', 'bloglogistics-markdown-for-agents' ), (string) $target['label'] );

        echo '<div class="bl-mfa-editor-wrap">';
        echo '<h2>' . esc_html( $heading ) . '</h2>';
        echo '<p><code>/' . esc_html( ltrim( (string) $target['relative'], '/' ) ) . '</code></p>';
        echo '<p class="description">' . esc_html__( 'This edits the actual curated text file only. It does not change the WordPress Page or Post and does not generate content from WordPress.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        echo '<div class="bl-mfa-editor-meta">';
        if ( false !== $modified ) {
            echo '<span><strong>' . esc_html__( 'Last modified:', 'bloglogistics-markdown-for-agents' ) . '</strong> ' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $modified ) ) . '</span>';
        }
        if ( false !== $size ) {
            echo '<span><strong>' . esc_html__( 'Size:', 'bloglogistics-markdown-for-agents' ) . '</strong> ' . esc_html( size_format( (int) $size ) ) . '</span>';
        }
        echo '<span><strong>' . esc_html__( 'Writable:', 'bloglogistics-markdown-for-agents' ) . '</strong> ' . esc_html( $writable ? __( 'Yes', 'bloglogistics-markdown-for-agents' ) : __( 'No', 'bloglogistics-markdown-for-agents' ) ) . '</span>';
        echo '</div>';

        if ( ! $writable ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This file or its directory is not writable. The editor is read-only until server permissions are corrected.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bloglogistics_mfa_save_text_file' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_save_text_file">';
        echo '<input type="hidden" name="file_type" value="' . esc_attr( $type ) . '">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '">';
        echo '<input type="hidden" name="original_hash" value="' . esc_attr( hash( 'sha256', @file_get_contents( $file ) ?: '' ) ) . '">';
        echo '<input type="hidden" name="return_tab" value="' . esc_attr( $tab ) . '">';
        echo '<input type="hidden" name="return_view" value="' . esc_attr( $view ) . '">';
        if ( in_array( $tab, [ 'pages', 'posts' ], true ) ) {
            echo '<input type="hidden" name="return_paged" value="' . esc_attr( (string) $paged ) . '">';
            echo '<input type="hidden" name="return_per_page" value="' . esc_attr( (string) $per_page ) . '">';
        }
        echo '<label class="screen-reader-text" for="bl-mfa-text-editor">' . esc_html__( 'Text file contents', 'bloglogistics-markdown-for-agents' ) . '</label>';
        echo '<textarea id="bl-mfa-text-editor" class="large-text code bl-mfa-text-editor" name="file_contents" rows="30" spellcheck="false"' . disabled( $writable, false, false ) . '>' . esc_textarea( $contents ) . '</textarea>';

        echo '<div class="bl-mfa-editor-actions">';
        if ( $writable ) {
            submit_button( 'llms' === $type ? __( 'Save llms.txt', 'bloglogistics-markdown-for-agents' ) : __( 'Save Markdown', 'bloglogistics-markdown-for-agents' ), 'primary', 'submit', false );
        }
        echo ' <a class="button" href="' . esc_url( self::settings_url( $tab, $view, $back_extra ) ) . '">' . esc_html__( 'Cancel', 'bloglogistics-markdown-for-agents' ) . '</a>';
        $view_file_label = 'llms' === $type ? __( 'View llms.txt', 'bloglogistics-markdown-for-agents' ) : __( 'View Markdown', 'bloglogistics-markdown-for-agents' );
        echo ' <a class="button" href="' . esc_url( (string) $target['public_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $view_file_label ) . '</a>';
        echo '</div>';
        echo '</form>';

        echo '<div class="bl-mfa-markdown-help">';
        echo '<h3>' . esc_html__( 'Simple Markdown', 'bloglogistics-markdown-for-agents' ) . '</h3>';
        echo '<p>' . esc_html__( 'Keep formatting simple and readable. These are the most useful forms for curated AI-facing text:', 'bloglogistics-markdown-for-agents' ) . '</p>';
        echo '<pre># Main heading\n## Section heading\n### Subsection heading\n\n**Bold text**\n*Italic text*\n\n- Bullet item\n- Another item\n\n[Link text](https://example.com/)\n\nUse blank lines between paragraphs.</pre>';
        if ( 'llms' === $type ) {
            echo '<p class="description">' . esc_html__( 'For llms.txt, use clear headings, short descriptions, and direct Markdown links to the curated Markdown resources you want agents to discover.', 'bloglogistics-markdown-for-agents' ) . '</p>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Save one administrator-authored Markdown or llms.txt edit.
     */
    public static function handle_save_text_file(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to edit Markdown files.', 'bloglogistics-markdown-for-agents' ) );
        }

        check_admin_referer( 'bloglogistics_mfa_save_text_file' );

        $type          = isset( $_POST['file_type'] ) ? sanitize_key( wp_unslash( $_POST['file_type'] ) ) : '';
        $post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $original_hash = isset( $_POST['original_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['original_hash'] ) ) : '';
        $contents      = isset( $_POST['file_contents'] ) ? (string) wp_unslash( $_POST['file_contents'] ) : '';
        $context       = self::return_context( 'llms' === $type ? 'llms' : 'pages', 'all' );
        $target        = self::editable_text_target( $type, $post_id );

        if ( is_wp_error( $target ) ) {
            wp_die( esc_html( $target->get_error_message() ) );
        }

        $saved = self::write_text_file_safely( $target, $contents, $original_hash );
        if ( is_wp_error( $saved ) ) {
            wp_die(
                '<h1>' . esc_html__( 'Markdown file was not changed', 'bloglogistics-markdown-for-agents' ) . '</h1><p>' . esc_html( $saved->get_error_message() ) . '</p><p>' . esc_html__( 'Use your browser Back button to return to the editor. Your submitted text may still be available there.', 'bloglogistics-markdown-for-agents' ) . '</p>',
                esc_html__( 'Save stopped', 'bloglogistics-markdown-for-agents' ),
                [ 'response' => 409 ]
            );
        }

        // Refresh local health data. Incremental scanning revalidates only the
        // changed file and reuses unchanged validation results for other files.
        self::scan_markdown_files( false );

        $extra = [
            'edit_file'                 => $type,
            'bloglogistics_mfa_message' => 'file_saved',
        ];
        if ( in_array( $context['tab'], [ 'pages', 'posts' ], true ) ) {
            $extra['paged']        = $context['paged'];
            $extra['mfa_per_page'] = $context['per_page'];
        }
        if ( 'markdown' === $type ) {
            $extra['post_id'] = $post_id;
        }

        wp_safe_redirect( self::settings_url( $context['tab'], $context['view'], $extra ) );
        exit;
    }

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'bloglogistics-markdown-for-agents' ) );
        }

        $tab             = self::settings_tab();
        $view            = self::settings_view();
        $last_scan       = (int) get_option( self::LAST_SCAN_OPTION, 0 );
        $message         = isset( $_GET['bloglogistics_mfa_message'] ) ? sanitize_key( wp_unslash( $_GET['bloglogistics_mfa_message'] ) ) : '';
        $htaccess_status = get_option( self::HTACCESS_STATUS_OPTION, [] );
        $health          = get_option( self::HEALTH_OPTION, [] );
        $server          = self::server_diagnostics();
        $backups         = self::htaccess_backups();

        $htaccess_status = is_array( $htaccess_status ) ? $htaccess_status : [];
        $health          = is_array( $health ) ? $health : [];
        $live_health     = isset( $health['live'] ) && is_array( $health['live'] ) ? $health['live'] : [];
        $live_items      = isset( $live_health['items'] ) && is_array( $live_health['items'] ) ? $live_health['items'] : [];
        $page_count      = count( self::published_ids_by_type( 'page' ) );
        $post_count      = count( self::published_ids_by_type( 'post' ) );

        echo '<div class="wrap bl-mfa-wrap">';
        echo '<h1>' . esc_html__( 'Markdown for Agents', 'bloglogistics-markdown-for-agents' ) . '</h1>';
        echo '<style>
            .bl-mfa-wrap{max-width:1400px}.bl-mfa-tabs{margin-bottom:18px}.bl-mfa-health-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:12px 0 20px;max-width:1250px}.bl-mfa-health-card{display:block;box-sizing:border-box;background:#fff;border:1px solid #c3c4c7;border-left-width:4px;border-radius:4px;padding:14px 16px;min-height:116px;text-decoration:none;color:#1d2327}.bl-mfa-health-card:hover{color:#1d2327;box-shadow:0 1px 4px rgba(0,0,0,.12)}.bl-mfa-health-card strong{display:block;font-size:26px;line-height:1.15;margin:5px 0}.bl-mfa-card-label{display:block;font-weight:600}.bl-mfa-card-detail{display:block;color:#50575e;margin-top:4px}.bl-mfa-card-links{display:block;margin-top:8px}.bl-mfa-card-links a{font-weight:600}.bl-mfa-card-healthy{border-left-color:#00a32a;background:#edfaef}.bl-mfa-card-problem{border-left-color:#d63638;background:#fcf0f1}.bl-mfa-card-review{border-left-color:#dba617;background:#fcf9e8}.bl-mfa-card-na{border-left-color:#c3c4c7;background:#f0f0f1}.bl-mfa-status{display:inline-block;border:1px solid transparent;border-radius:999px;padding:2px 8px;font-weight:600;line-height:1.5}.bl-mfa-status-healthy{background:#edfaef;border-color:#00a32a;color:#006b1b}.bl-mfa-status-problem{background:#fcf0f1;border-color:#d63638;color:#8a2424}.bl-mfa-status-review{background:#fcf9e8;border-color:#dba617;color:#755c00}.bl-mfa-status-na{background:#f0f0f1;border-color:#c3c4c7;color:#50575e}.bl-mfa-health-table td,.bl-mfa-health-table th{vertical-align:top}.bl-mfa-health-detail{display:block;color:#646970;margin-top:4px;line-height:1.4}.bl-mfa-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0}.bl-mfa-actions form{margin:0}.bl-mfa-primary-actions{padding:10px 0}.bl-mfa-code-wrap{word-break:break-word}.bl-mfa-table-wrap{overflow-x:auto}.bl-mfa-filters{margin:8px 0 6px}.bl-mfa-discovery-toggle{display:block;margin-top:8px;color:#50575e}.bl-mfa-overview-meta{display:flex;gap:24px;flex-wrap:wrap;margin:6px 0 12px;color:#50575e}.bl-mfa-status-key{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:8px 0 14px}.bl-mfa-status-key strong{margin-right:2px}.bl-mfa-issue-list{list-style:disc;margin-left:2em}.bl-mfa-how-it-works{max-width:900px}.bl-mfa-health-table .row-actions{position:static}.bl-mfa-health-table code{font-size:12px}.bl-mfa-editor-wrap{max-width:1050px}.bl-mfa-text-editor{width:100%;min-height:560px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:13px;line-height:1.55;white-space:pre;tab-size:4}.bl-mfa-editor-meta{display:flex;gap:24px;flex-wrap:wrap;color:#50575e;margin:8px 0 14px}.bl-mfa-editor-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:12px 0 22px}.bl-mfa-editor-actions .button{margin:0}.bl-mfa-markdown-help{max-width:760px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #72aee6;padding:14px 18px;margin-top:18px}.bl-mfa-markdown-help h3{margin-top:0}.bl-mfa-markdown-help pre{background:#f6f7f7;border:1px solid #dcdcde;padding:12px;overflow:auto}.bl-mfa-file-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 16px}.bl-mfa-pagination-nav{margin:8px 0}.bl-mfa-per-page form{display:flex;align-items:center;gap:6px}.bl-mfa-per-page label{font-weight:600}.bl-mfa-pagination-nav .tablenav-pages{margin:0 0 0 auto}@media(max-width:782px){.bl-mfa-health-grid{grid-template-columns:1fr}.bl-mfa-overview-meta{display:block}.bl-mfa-overview-meta span{display:block;margin-bottom:5px}.bl-mfa-editor-meta{display:block}.bl-mfa-editor-meta span{display:block;margin-bottom:5px}}
        </style>';

        echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Editorial control stays with you.', 'bloglogistics-markdown-for-agents' ) . '</strong> ';
        echo esc_html__( 'This plugin never generates or rewrites your curated content automatically. Its optional plain text editor saves only the Markdown or llms.txt text that an administrator explicitly enters.', 'bloglogistics-markdown-for-agents' );
        echo '</p></div>';

        if ( 'file_saved' === $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Text file saved. A timestamped backup of the previous file was created and local health data was refreshed.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
        } elseif ( 'scanned' === $message ) {
            $checked   = isset( $_GET['checked'] ) ? absint( $_GET['checked'] ) : 0;
            $found     = isset( $_GET['found'] ) ? absint( $_GET['found'] ) : 0;
            $removed   = isset( $_GET['removed'] ) ? absint( $_GET['removed'] ) : 0;
            $validated = isset( $_GET['validated'] ) ? absint( $_GET['validated'] ) : 0;
            $reused    = isset( $_GET['reused'] ) ? absint( $_GET['reused'] ) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                esc_html__( 'Scan complete. Checked %1$d published posts/pages, detected %2$d Markdown files, removed %3$d stale Markdown URL fields, validated %4$d changed files, and reused %5$d unchanged validation results.', 'bloglogistics-markdown-for-agents' ),
                $checked,
                $found,
                $removed,
                $validated,
                $reused
            );
            echo '</p></div>';
        } elseif ( 'exclusions_saved' === $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Discovery choices saved.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
        } elseif ( 'bulk_done' === $message ) {
            $bulk     = isset( $_GET['bulk'] ) ? sanitize_key( wp_unslash( $_GET['bulk'] ) ) : '';
            $affected = isset( $_GET['affected'] ) ? absint( $_GET['affected'] ) : 0;
            $label    = match ( $bulk ) {
                'disable' => __( 'Disabled discovery for selected items.', 'bloglogistics-markdown-for-agents' ),
                'enable'  => __( 'Enabled discovery for selected items.', 'bloglogistics-markdown-for-agents' ),
                'rescan'  => __( 'Revalidated selected items.', 'bloglogistics-markdown-for-agents' ),
                'verify'  => __( 'Ran live verification for selected items.', 'bloglogistics-markdown-for-agents' ),
                default   => __( 'Bulk action completed.', 'bloglogistics-markdown-for-agents' ),
            };
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $label ) . ' ';
            printf( esc_html__( 'Items affected: %d.', 'bloglogistics-markdown-for-agents' ), $affected );
            echo '</p></div>';
        } elseif ( 'live_verified' === $message ) {
            $checked      = isset( $_GET['checked'] ) ? absint( $_GET['checked'] ) : 0;
            $passed       = isset( $_GET['passed'] ) ? absint( $_GET['passed'] ) : 0;
            $reviewed     = isset( $_GET['reviewed'] ) ? absint( $_GET['reviewed'] ) : 0;
            $failed       = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
            $inconclusive = isset( $_GET['inconclusive'] ) ? absint( $_GET['inconclusive'] ) : 0;
            $truncated    = ! empty( $_GET['truncated'] );
            $notice_class = $failed ? 'notice-error' : ( $reviewed ? 'notice-warning' : 'notice-success' );
            echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>';
            printf(
                esc_html__( 'Live verification complete. Checked %1$d Markdown files: %2$d healthy, %3$d for review, %4$d with problems, and %5$d not verified.', 'bloglogistics-markdown-for-agents' ),
                $checked,
                $passed,
                $reviewed,
                $failed,
                $inconclusive
            );
            if ( $truncated ) {
                echo ' ' . esc_html__( 'The run reached the per-run verification limit.', 'bloglogistics-markdown-for-agents' );
            }
            echo '</p></div>';
        } elseif ( 'backups_cleaned' === $message ) {
            $deleted  = isset( $_GET['deleted'] ) ? absint( $_GET['deleted'] ) : 0;
            $failed   = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
            $retained = isset( $_GET['retained'] ) ? absint( $_GET['retained'] ) : 0;
            echo '<div class="notice ' . esc_attr( $failed ? 'notice-warning' : 'notice-success' ) . ' is-dismissible"><p>';
            printf(
                esc_html__( 'Backup cleanup complete. Deleted %1$d old backups, retained %2$d newest backups, and %3$d deletions failed.', 'bloglogistics-markdown-for-agents' ),
                $deleted,
                $retained,
                $failed
            );
            echo '</p></div>';
        } elseif ( 'htaccess_checked' === $message && ! empty( $htaccess_status['message'] ) ) {
            $htaccess_state = isset( $htaccess_status['state'] ) ? (string) $htaccess_status['state'] : 'unknown';
            $notice_class   = in_array( $htaccess_state, [ 'installed', 'already_present' ], true ) ? 'notice-success' : 'notice-warning';
            echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( (string) $htaccess_status['message'] ) . '</p></div>';
        }

        self::render_settings_tabs( $tab, $page_count, $post_count );

        $edit_file = isset( $_GET['edit_file'] ) ? sanitize_key( wp_unslash( $_GET['edit_file'] ) ) : '';
        if ( in_array( $edit_file, [ 'markdown', 'llms' ], true ) ) {
            $edit_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
            self::render_text_editor( $edit_file, $edit_post_id, $tab, $view );
            echo '</div>';
            return;
        }

        if ( 'pages' === $tab ) {
            self::render_content_tab( 'page', 'pages', $view, $health, $live_items );
        } elseif ( 'posts' === $tab ) {
            self::render_content_tab( 'post', 'posts', $view, $health, $live_items );
        } elseif ( 'llms' === $tab ) {
            self::render_llms_tab( $health );
        } elseif ( 'server' === $tab ) {
            self::render_server_tab( $server, $htaccess_status, $backups );
        } else {
            self::render_overview_tab( $health, $htaccess_status, $server, $last_scan );
        }

        echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".bl-mfa-select-all").forEach(function(a){a.addEventListener("change",function(){var form=a.closest("form");if(!form){return;}form.querySelectorAll(".bl-mfa-row-select").forEach(function(c){c.checked=a.checked;});});});});</script>';
        echo '</div>';
    }

    public static function handle_scan(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to scan Markdown files.', 'bloglogistics-markdown-for-agents' ) );
        }

        check_admin_referer( 'bloglogistics_mfa_scan' );

        $force   = ! empty( $_POST['force_full_scan'] );
        $context = self::return_context();
        $result  = self::scan_markdown_files( $force );
        self::ensure_htaccess_rule( true );
        delete_option( self::HTACCESS_NOTICE_OPTION );

        wp_safe_redirect(
            add_query_arg(
                array_merge(
                    [ 'page' => self::SETTINGS_SLUG ],
                    self::context_query_args( $context ),
                    [
                    'bloglogistics_mfa_message' => 'scanned',
                    'checked'                   => $result['checked'],
                    'found'                     => $result['found'],
                    'removed'                   => $result['removed'],
                    'validated'                 => $result['validated'],
                    'reused'                    => $result['reused'],
                    'force'                     => $force ? 1 : 0,
                    ]
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function handle_htaccess(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage the .htaccess Markdown rules.', 'bloglogistics-markdown-for-agents' ) );
        }

        check_admin_referer( 'bloglogistics_mfa_htaccess' );
        $context = self::return_context( 'server' );
        self::ensure_htaccess_rule( true );
        delete_option( self::HTACCESS_NOTICE_OPTION );

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                      => self::SETTINGS_SLUG,
                    'tab'                       => $context['tab'],
                    'view'                      => $context['view'],
                    'bloglogistics_mfa_message' => 'htaccess_checked',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Return browser-verification targets for the pending administrator run.
     * URLs are supplied by WordPress; the browser performs the public requests.
     */
    public static function ajax_browser_verify_targets(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to run live Markdown verification.', 'bloglogistics-markdown-for-agents' ) ], 403 );
        }

        check_ajax_referer( 'bloglogistics_mfa_browser_verify', 'nonce' );

        $request = get_transient( self::browser_verify_transient_key() );

        if ( ! is_array( $request ) ) {
            wp_send_json_error( [ 'message' => __( 'The browser verification request expired. Start the verification again.', 'bloglogistics-markdown-for-agents' ) ], 410 );
        }

        $requested_ids = isset( $request['requested_ids'] ) && is_array( $request['requested_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', $request['requested_ids'] ) ) ) )
            : [];

        $post_ids = get_posts(
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
        $post_ids = array_values( array_map( 'absint', $post_ids ) );

        if ( $requested_ids ) {
            $post_ids = array_values(
                array_filter(
                    $post_ids,
                    static fn ( $post_id ): bool => in_array( (int) $post_id, $requested_ids, true )
                )
            );
        }

        $candidate_count = count( $post_ids );
        $truncated       = $candidate_count > self::LIVE_VERIFY_LIMIT;
        $post_ids        = array_slice( $post_ids, 0, self::LIVE_VERIFY_LIMIT );
        $has_llms        = '1' === get_option( self::LLMS_DETECTED_OPTION, '0' );
        $targets         = [];

        foreach ( $post_ids as $post_id ) {
            $html_url     = get_permalink( $post_id );
            $markdown_url = (string) get_post_meta( $post_id, self::MARKDOWN_URL_META, true );

            if ( ! is_string( $html_url ) || '' === $html_url || '' === $markdown_url ) {
                continue;
            }

            $disabled  = self::is_discovery_disabled( $post_id );
            $targets[] = [
                'postId'         => (int) $post_id,
                'htmlUrl'        => esc_url_raw( $html_url ),
                'markdownUrl'    => esc_url_raw( $markdown_url ),
                'llmsUrl'        => esc_url_raw( home_url( '/llms.txt' ) ),
                'disabled'       => $disabled,
                'expectMarkdown' => ! $disabled,
                'expectLlms'     => ! $disabled && $has_llms,
            ];
        }

        $request['target_ids']      = array_values( array_map( static fn ( $target ): int => (int) $target['postId'], $targets ) );
        $request['candidate_count'] = $candidate_count;
        $request['truncated']       = $truncated;
        set_transient( self::browser_verify_transient_key(), $request, 10 * MINUTE_IN_SECONDS );

        wp_send_json_success(
            [
                'targets'        => $targets,
                'candidateCount' => $candidate_count,
                'truncated'      => $truncated,
            ]
        );
    }

    /**
     * Turn browser observations into the plugin's saved live-health format.
     * Status classification is deliberately performed server-side rather than
     * trusting JavaScript to decide whether something is Healthy/Review/Problem.
     *
     * @param array<string,mixed> $observation Browser-provided facts.
     * @return array<string,mixed>
     */
    private static function browser_observation_to_live_item( int $post_id, array $observation, bool $has_llms ): array {
        $markdown_url = (string) get_post_meta( $post_id, self::MARKDOWN_URL_META, true );
        $html_url     = get_permalink( $post_id );
        $disabled     = self::is_discovery_disabled( $post_id );
        $html_status  = isset( $observation['html_status'] ) ? absint( $observation['html_status'] ) : 0;
        $md_status    = isset( $observation['markdown_status'] ) ? absint( $observation['markdown_status'] ) : 0;
        $content_type = isset( $observation['content_type'] ) ? sanitize_text_field( (string) $observation['content_type'] ) : '';
        $mime_state   = self::markdown_mime_state( $content_type );
        $html_error   = isset( $observation['html_error'] ) ? sanitize_text_field( (string) $observation['html_error'] ) : '';
        $md_error     = isset( $observation['markdown_error'] ) ? sanitize_text_field( (string) $observation['markdown_error'] ) : '';
        $markdown_found = ! empty( $observation['markdown_discovery_found'] );
        $llms_found     = ! empty( $observation['llms_discovery_found'] );
        $expect_markdown = ! $disabled;
        $expect_llms     = ! $disabled && $has_llms;
        $delivery_issues   = [];
        $delivery_warnings = [];
        $discovery_warnings = [];

        // A browser/network exception means verification was inconclusive, not
        // that the public resource was proven broken.
        if ( '' !== $html_error ) {
            $delivery_warnings[] = 'html_request_error';
        } elseif ( 200 !== $html_status ) {
            $delivery_issues[] = 'html_status';
        }

        if ( '' !== $md_error ) {
            $delivery_warnings[] = 'markdown_request_error';
        } elseif ( 200 !== $md_status ) {
            $delivery_issues[] = 'markdown_status';
        }

        if ( 200 === $md_status && '' === $md_error ) {
            if ( 'warning' === $mime_state ) {
                $delivery_warnings[] = 'markdown_mime';
            } elseif ( 'missing' === $mime_state ) {
                $delivery_warnings[] = 'markdown_mime_unconfirmed';
            } elseif ( 'bad' === $mime_state ) {
                $delivery_issues[] = 'markdown_mime';
            }
        }

        $html_redirected = ! empty( $observation['html_redirected'] );
        $md_redirected   = ! empty( $observation['markdown_redirected'] );

        // Successful redirects are recorded for detail, but are not health
        // warnings by themselves. Canonical host, scheme, and trailing-slash
        // redirects are common on otherwise healthy WordPress sites.

        $discovery_issues = [];

        if ( 200 === $html_status && '' === $html_error ) {
            if ( $expect_markdown && ! $markdown_found ) {
                $discovery_warnings[] = 'missing_markdown_discovery';
            } elseif ( ! $expect_markdown && $markdown_found ) {
                $discovery_warnings[] = 'unexpected_markdown_discovery';
            }

            if ( $expect_llms && ! $llms_found ) {
                $discovery_warnings[] = 'missing_llms_discovery';
            } elseif ( ! $expect_llms && $llms_found ) {
                $discovery_warnings[] = 'unexpected_llms_discovery';
            }
        }

        $delivery_issues    = array_values( array_unique( $delivery_issues ) );
        $delivery_warnings  = array_values( array_unique( $delivery_warnings ) );
        $discovery_warnings = array_values( array_unique( $discovery_warnings ) );
        $issues             = array_values( array_unique( array_merge( $delivery_issues, $discovery_issues ) ) );
        $warnings           = array_values( array_unique( array_merge( $delivery_warnings, $discovery_warnings ) ) );
        $discovery_passed   = empty( $discovery_issues ) && empty( $discovery_warnings );

        return [
            'post_id'                 => $post_id,
            'checked_at'              => time(),
            'verification_source'     => 'browser',
            'html_url'                => is_string( $html_url ) ? $html_url : '',
            'markdown_url'            => $markdown_url,
            'disabled'                => $disabled,
            'html_initial_status'     => isset( $observation['html_initial_status'] ) ? absint( $observation['html_initial_status'] ) : $html_status,
            'html_status'             => $html_status,
            'html_redirected'         => $html_redirected,
            'markdown_initial_status' => isset( $observation['markdown_initial_status'] ) ? absint( $observation['markdown_initial_status'] ) : $md_status,
            'markdown_status'         => $md_status,
            'markdown_redirected'     => $md_redirected,
            'content_type'            => $content_type,
            'mime_state'              => $mime_state,
            'markdown_fresh_content_type' => '',
            'markdown_fresh_mime_state'   => '',
            'markdown_mime_cache_state'   => 'browser',
            'markdown_recheck_status'     => isset( $observation['markdown_recheck_status'] ) ? absint( $observation['markdown_recheck_status'] ) : 0,
            'expect_markdown'         => $expect_markdown,
            'expect_llms'             => $expect_llms,
            'discovery'               => [
                'passed'         => empty( $discovery_warnings ),
                'markdown_found' => $markdown_found,
                'llms_found'     => $llms_found,
                'issues'         => $discovery_warnings,
            ],
            'discovery_fresh'         => [],
            'discovery_cache_state'   => 'browser',
            'discovery_recheck_status'=> isset( $observation['html_recheck_status'] ) ? absint( $observation['html_recheck_status'] ) : 0,
            'delivery_issues'         => $delivery_issues,
            'delivery_warnings'       => $delivery_warnings,
            'discovery_issues'        => $discovery_issues,
            'discovery_warnings'      => $discovery_warnings,
            'issues'                  => $issues,
            'warnings'                => $warnings,
            'delivery_passed'         => empty( $delivery_issues ),
            'discovery_passed'        => $discovery_passed,
            'passed'                  => empty( $issues ),
            'outdated'                => false,
            'browser_retry_used'      => ! empty( $observation['retry_used'] ),
        ];
    }

    /**
     * Save factual observations collected by the administrator's browser.
     */
    public static function ajax_store_browser_results(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to save live Markdown verification.', 'bloglogistics-markdown-for-agents' ) ], 403 );
        }

        check_ajax_referer( 'bloglogistics_mfa_browser_verify', 'nonce' );

        $request = get_transient( self::browser_verify_transient_key() );

        if ( ! is_array( $request ) ) {
            wp_send_json_error( [ 'message' => __( 'The browser verification request expired. Start the verification again.', 'bloglogistics-markdown-for-agents' ) ], 410 );
        }

        $target_ids = isset( $request['target_ids'] ) && is_array( $request['target_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', $request['target_ids'] ) ) ) )
            : [];
        $raw_json = isset( $_POST['results'] ) ? (string) wp_unslash( $_POST['results'] ) : '[]';
        $decoded  = json_decode( $raw_json, true );
        $decoded  = is_array( $decoded ) ? $decoded : [];
        $by_id    = [];

        foreach ( $decoded as $observation ) {
            if ( ! is_array( $observation ) ) {
                continue;
            }

            $post_id = isset( $observation['post_id'] ) ? absint( $observation['post_id'] ) : 0;
            if ( $post_id && in_array( $post_id, $target_ids, true ) ) {
                $by_id[ $post_id ] = $observation;
            }
        }

        $health        = get_option( self::HEALTH_OPTION, [] );
        $health        = is_array( $health ) ? $health : [];
        $previous_live = isset( $health['live'] ) && is_array( $health['live'] ) ? $health['live'] : [];
        $health_items  = isset( $health['items'] ) && is_array( $health['items'] ) ? $health['items'] : [];
        $scope         = isset( $request['scope'] ) && 'selected' === $request['scope'] ? 'selected' : 'all';
        $live_items    = 'selected' === $scope && isset( $previous_live['items'] ) && is_array( $previous_live['items'] )
            ? $previous_live['items']
            : [];
        $has_llms      = '1' === get_option( self::LLMS_DETECTED_OPTION, '0' );
        $checked       = 0;
        $passed        = 0;
        $reviewed      = 0;
        $failed        = 0;
        $inconclusive  = 0;
        $mime_warnings = 0;
        $mime_failures = 0;
        $discovery_findings = 0;
        $redirected    = 0;
        $request_errors = 0;

        foreach ( $target_ids as $post_id ) {
            $observation = isset( $by_id[ $post_id ] ) ? $by_id[ $post_id ] : [
                'post_id'        => $post_id,
                'html_error'     => __( 'No browser result was returned for this item.', 'bloglogistics-markdown-for-agents' ),
                'markdown_error' => __( 'No browser result was returned for this item.', 'bloglogistics-markdown-for-agents' ),
            ];
            $item = self::browser_observation_to_live_item( $post_id, $observation, $has_llms );
            $scan_item = isset( $health_items[ $post_id ] ) && is_array( $health_items[ $post_id ] ) ? $health_items[ $post_id ] : [];
            $item['scan_signature'] = isset( $scan_item['scan_signature'] ) ? (string) $scan_item['scan_signature'] : '';
            $live_items[ $post_id ] = $item;
            $checked++;

            $request_inconclusive = in_array( 'html_request_error', (array) $item['delivery_warnings'], true )
                || in_array( 'markdown_request_error', (array) $item['delivery_warnings'], true );
            $meaningful_delivery_warnings = array_values( array_diff( (array) $item['delivery_warnings'], [ 'html_request_error', 'markdown_request_error', 'redirected' ] ) );

            if ( ! empty( $item['issues'] ) ) {
                $failed++;
            } elseif ( $request_inconclusive && empty( $meaningful_delivery_warnings ) && empty( $item['discovery_warnings'] ) ) {
                $inconclusive++;
            } elseif ( ! empty( $meaningful_delivery_warnings ) || ! empty( $item['discovery_warnings'] ) ) {
                $reviewed++;
            } else {
                $passed++;
            }

            if ( in_array( 'markdown_mime', (array) $item['delivery_issues'], true ) ) {
                $mime_failures++;
            } elseif (
                in_array( 'markdown_mime', (array) $item['delivery_warnings'], true )
                || in_array( 'markdown_mime_unconfirmed', (array) $item['delivery_warnings'], true )
            ) {
                $mime_warnings++;
            }

            if ( ! empty( $item['discovery_warnings'] ) || ! empty( $item['discovery_issues'] ) ) {
                $discovery_findings++;
            }

            if ( ! empty( $item['html_redirected'] ) || ! empty( $item['markdown_redirected'] ) ) {
                $redirected++;
            }

            if (
                in_array( 'html_request_error', (array) $item['delivery_warnings'], true )
                || in_array( 'markdown_request_error', (array) $item['delivery_warnings'], true )
            ) {
                $request_errors++;
            }
        }

        $outdated_count = 0;
        foreach ( $live_items as $saved_live_item ) {
            if ( is_array( $saved_live_item ) && ! empty( $saved_live_item['outdated'] ) ) {
                $outdated_count++;
            }
        }

        $live = [
            'verified_at'        => time(),
            'verification_source'=> 'browser',
            'scope'              => $scope,
            'candidate_count'    => isset( $request['candidate_count'] ) ? absint( $request['candidate_count'] ) : count( $target_ids ),
            'checked'            => $checked,
            'passed'             => $passed,
            'reviewed'           => $reviewed,
            'failed'             => $failed,
            'inconclusive'       => $inconclusive,
            'mime_warnings'      => $mime_warnings,
            'mime_failures'      => $mime_failures,
            'discovery_failures' => $discovery_findings,
            'redirected'         => $redirected,
            'request_errors'     => $request_errors,
            'outdated_count'     => $outdated_count,
            'truncated'          => ! empty( $request['truncated'] ),
            'items'              => $live_items,
        ];

        $health['live'] = $live;
        update_option( self::HEALTH_OPTION, $health, false );
        delete_transient( self::browser_verify_transient_key() );

        $context = isset( $request['context'] ) && is_array( $request['context'] )
            ? $request['context']
            : [ 'tab' => 'overview', 'view' => 'all' ];
        $tab  = isset( $context['tab'] ) ? sanitize_key( (string) $context['tab'] ) : 'overview';
        $view = isset( $context['view'] ) ? sanitize_key( (string) $context['view'] ) : 'all';

        if ( ! in_array( $tab, [ 'overview', 'pages', 'posts', 'llms', 'server' ], true ) ) {
            $tab = 'overview';
        }
        if ( ! in_array( $view, [ 'all', 'attention', 'missing', 'stale', 'encoding', 'live', 'discovery', 'disabled' ], true ) ) {
            $view = 'all';
        }

        $redirect_context = [
            'tab'      => $tab,
            'view'     => $view,
            'paged'    => isset( $context['paged'] ) ? absint( $context['paged'] ) : 1,
            'per_page' => isset( $context['per_page'] ) ? absint( $context['per_page'] ) : 20,
        ];
        $redirect = add_query_arg(
            array_merge(
                [ 'page' => self::SETTINGS_SLUG ],
                self::context_query_args( $redirect_context ),
                [
                'bloglogistics_mfa_message' => 'live_verified',
                'checked'                   => $checked,
                'passed'                    => $passed,
                'reviewed'                  => $reviewed,
                'failed'                    => $failed,
                'inconclusive'              => $inconclusive,
                'truncated'                 => ! empty( $request['truncated'] ) ? 1 : 0,
                ]
            ),
            admin_url( 'admin.php' )
        );

        wp_send_json_success(
            [
                'redirectUrl' => $redirect,
                'checked'     => $checked,
                'passed'      => $passed,
                'reviewed'    => $reviewed,
                'failed'      => $failed,
                'inconclusive'=> $inconclusive,
            ]
        );
    }

    public static function handle_live_verify(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to run live Markdown verification.', 'bloglogistics-markdown-for-agents' ) );
        }

        check_admin_referer( 'bloglogistics_mfa_live_verify' );
        $context = self::return_context();
        self::schedule_browser_verification( [], $context );

        wp_safe_redirect(
            add_query_arg(
                array_merge(
                    [ 'page' => self::SETTINGS_SLUG ],
                    self::context_query_args( $context ),
                    [ 'browser_verify' => 1 ]
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function handle_verify_item(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to run live Markdown verification.', 'bloglogistics-markdown-for-agents' ) );
        }

        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        check_admin_referer( 'bloglogistics_mfa_verify_item_' . $post_id );
        $context = self::return_context();
        $post    = $post_id ? get_post( $post_id ) : null;

        if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
            wp_die( esc_html__( 'The selected content could not be verified.', 'bloglogistics-markdown-for-agents' ) );
        }

        self::schedule_browser_verification( [ $post_id ], $context );

        wp_safe_redirect(
            add_query_arg(
                array_merge(
                    [ 'page' => self::SETTINGS_SLUG ],
                    self::context_query_args( $context ),
                    [ 'browser_verify' => 1 ]
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function handle_cleanup_backups(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to clean up .htaccess backups.', 'bloglogistics-markdown-for-agents' ) );
        }

        check_admin_referer( 'bloglogistics_mfa_cleanup_backups' );
        $context = self::return_context( 'server' );
        $result  = self::cleanup_old_htaccess_backups();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                      => self::SETTINGS_SLUG,
                    'tab'                       => $context['tab'],
                    'view'                      => $context['view'],
                    'bloglogistics_mfa_message' => 'backups_cleaned',
                    'deleted'                   => (int) $result['deleted'],
                    'failed'                    => (int) $result['failed'],
                    'retained'                  => (int) $result['retained'],
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
        $context = self::return_context();

        $disabled_ids = isset( $_POST['disabled_ids'] ) && is_array( $_POST['disabled_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['disabled_ids'] ) ) ) ) )
            : [];
        $selected_ids = isset( $_POST['selected_ids'] ) && is_array( $_POST['selected_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['selected_ids'] ) ) ) ) )
            : [];
        $managed_ids = isset( $_POST['managed_ids'] ) && is_array( $_POST['managed_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['managed_ids'] ) ) ) ) )
            : [];
        $bulk_action = ! empty( $_POST['apply_bulk'] ) && isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';

        $published_ids = get_posts(
            [
                'post_type'              => [ 'post', 'page' ],
                'post_status'            => 'publish',
                'numberposts'            => -1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );
        $published_ids = array_map( 'absint', $published_ids );

        if ( ! $managed_ids ) {
            // Compatibility with forms submitted by earlier plugin versions.
            $managed_ids = get_posts(
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
            $managed_ids = array_map( 'absint', $managed_ids );
        }

        $managed_ids  = array_values( array_intersect( $managed_ids, $published_ids ) );
        $disabled_ids = array_values( array_intersect( $disabled_ids, $managed_ids ) );
        $selected_ids = array_values( array_intersect( $selected_ids, $managed_ids ) );

        foreach ( $managed_ids as $post_id ) {
            if ( in_array( $post_id, $disabled_ids, true ) ) {
                update_post_meta( $post_id, self::DISABLED_META, '1' );
            } else {
                delete_post_meta( $post_id, self::DISABLED_META );
            }
        }

        $affected  = 0;
        $force_ids = [];

        if ( $selected_ids && 'disable' === $bulk_action ) {
            foreach ( $selected_ids as $post_id ) {
                update_post_meta( $post_id, self::DISABLED_META, '1' );
                $affected++;
            }
        } elseif ( $selected_ids && 'enable' === $bulk_action ) {
            foreach ( $selected_ids as $post_id ) {
                delete_post_meta( $post_id, self::DISABLED_META );
                $affected++;
            }
        } elseif ( $selected_ids && 'rescan' === $bulk_action ) {
            $force_ids = $selected_ids;
            $affected  = count( $selected_ids );
        }

        self::scan_markdown_files( false, $force_ids );

        if ( $selected_ids && 'verify' === $bulk_action ) {
            self::schedule_browser_verification( $selected_ids, $context );
            $affected = count( $selected_ids );

            wp_safe_redirect(
                add_query_arg(
                    array_merge(
                        [ 'page' => self::SETTINGS_SLUG ],
                        self::context_query_args( $context ),
                        [ 'browser_verify' => 1 ]
                    ),
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        $message = in_array( $bulk_action, [ 'disable', 'enable', 'rescan' ], true )
            ? 'bulk_done'
            : 'exclusions_saved';

        wp_safe_redirect(
            add_query_arg(
                array_merge(
                    [ 'page' => self::SETTINGS_SLUG ],
                    self::context_query_args( $context ),
                    [
                    'bloglogistics_mfa_message' => $message,
                    'bulk'                      => $bulk_action,
                    'affected'                  => $affected,
                    ]
                ),
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
            echo '<p><strong>' . esc_html__( 'Markdown file detected:', 'bloglogistics-markdown-for-agents' ) . '</strong></p>';
            echo '<p style="word-break:break-word;"><a href="' . esc_url( $markdown_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $markdown_url ) . '</a></p>';
        } else {
            echo '<p><strong>' . esc_html__( 'No Markdown file is currently recorded for this content.', 'bloglogistics-markdown-for-agents' ) . '</strong></p>';
            echo '<p>' . esc_html__( 'Create the curated index.md file yourself, then run BlogLogistics > Markdown for Agents > Scan Changes and Refresh Health.', 'bloglogistics-markdown-for-agents' ) . '</p>';
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
