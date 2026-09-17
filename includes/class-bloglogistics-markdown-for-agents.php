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
        add_action( 'admin_notices', [ __CLASS__, 'render_htaccess_admin_notice' ] );

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
     * It does, however, install the .htaccess compatibility rule required for
     * /slug/ WordPress permalinks to coexist with physical /slug/index.md files
     * on Apache-compatible servers.
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

            if ( isset( $seen[ $link ] ) ) {
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

            if ( null === $target || ! self::is_safe_readable_file( $target, $public_root ) ) {
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
     * Insert or reposition the managed rule without altering the surrounding
     * .htaccess content more than necessary.
     */
    private static function build_htaccess_contents( string $contents ): string {
        if ( self::htaccess_rule_exists( $contents ) ) {
            return $contents;
        }

        $eol        = self::detect_eol( $contents );
        $normalised = self::normalise_newlines( $contents );
        $block      = self::htaccess_rule_block();

        // Remove a misplaced copy of the exact block before inserting it in
        // the canonical location. This keeps the operation idempotent.
        $normalised = str_replace( $block . "\n\n", '', $normalised );
        $normalised = str_replace( "\n\n" . $block, '', $normalised );
        $normalised = str_replace( $block, '', $normalised );
        $normalised = ltrim( $normalised, "\n" );

        $wp_pos = strpos( $normalised, '# BEGIN WordPress' );

        if ( false !== $wp_pos ) {
            $before = rtrim( substr( $normalised, 0, $wp_pos ), "\n" );
            $after  = ltrim( substr( $normalised, $wp_pos ), "\n" );

            $new_contents = '' !== $before
                ? $before . "\n\n" . $block . "\n\n" . $after
                : $block . "\n\n" . $after;
        } else {
            $new_contents = '' !== trim( $normalised )
                ? $block . "\n\n" . ltrim( $normalised, "\n" )
                : $block . "\n";
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
     * @return array{available:bool,passed:bool,html_url:string,markdown_url:string,html_status:int,markdown_status:int,message:string}
     */
    private static function verify_htaccess_live(): array {
        $target = self::verification_target();

        if ( null === $target ) {
            return [
                'available'       => false,
                'passed'          => false,
                'html_url'        => '',
                'markdown_url'    => '',
                'html_status'     => 0,
                'markdown_status' => 0,
                'message'         => __( 'The rule is present in .htaccess, but no non-homepage Markdown companion is currently recorded for an end-to-end live test. Run the Markdown scan after uploading companion files.', 'bloglogistics-markdown-for-agents' ),
            ];
        }

        $token       = rawurlencode( (string) time() . '-' . (string) wp_rand( 100000, 999999 ) );
        $html_test   = add_query_arg( 'bloglogistics_mfa_verify', $token, $target['html_url'] );
        $md_test     = add_query_arg( 'bloglogistics_mfa_verify', $token, $target['markdown_url'] );
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
                'available'       => true,
                'passed'          => false,
                'html_url'        => $target['html_url'],
                'markdown_url'    => $target['markdown_url'],
                'html_status'     => 0,
                'markdown_status' => 0,
                'message'         => sprintf(
                    /* translators: %s: HTTP request error. */
                    __( 'The .htaccess rule is present, but the public HTML verification request failed: %s', 'bloglogistics-markdown-for-agents' ),
                    $html_response->get_error_message()
                ),
            ];
        }

        $html_status = (int) wp_remote_retrieve_response_code( $html_response );
        $md_response = wp_remote_get( $md_test, $request_args );

        if ( is_wp_error( $md_response ) ) {
            return [
                'available'       => true,
                'passed'          => false,
                'html_url'        => $target['html_url'],
                'markdown_url'    => $target['markdown_url'],
                'html_status'     => $html_status,
                'markdown_status' => 0,
                'message'         => sprintf(
                    /* translators: %s: HTTP request error. */
                    __( 'The HTML page responded, but the Markdown verification request failed: %s', 'bloglogistics-markdown-for-agents' ),
                    $md_response->get_error_message()
                ),
            ];
        }

        $markdown_status = (int) wp_remote_retrieve_response_code( $md_response );
        $passed          = 200 === $html_status && 200 === $markdown_status;

        return [
            'available'       => true,
            'passed'          => $passed,
            'html_url'        => $target['html_url'],
            'markdown_url'    => $target['markdown_url'],
            'html_status'     => $html_status,
            'markdown_status' => $markdown_status,
            'message'         => $passed
                ? __( 'Live verification passed: both the WordPress page and its Markdown companion returned HTTP 200.', 'bloglogistics-markdown-for-agents' )
                : sprintf(
                    /* translators: 1: HTML status code, 2: Markdown status code. */
                    __( 'Live verification did not pass. The WordPress page returned HTTP %1$d and the Markdown companion returned HTTP %2$d.', 'bloglogistics-markdown-for-agents' ),
                    $html_status,
                    $markdown_status
                ),
        ];
    }

    /**
     * Install or repair the .htaccess compatibility rule, backing up the
     * existing file before every write and verifying the result afterwards.
     *
     * @param bool $verify_live Whether to perform a public end-to-end check.
     * @return array<string,mixed>
     */
    private static function ensure_htaccess_rule( bool $verify_live = true ): array {
        $public_root   = self::public_root();
        $htaccess_file = wp_normalize_path( $public_root . '.htaccess' );
        $status        = [
            'state'            => 'unknown',
            'message'          => '',
            'htaccess_file'    => $htaccess_file,
            'backup_file'      => '',
            'file_verified'    => false,
            'live_available'   => false,
            'live_verified'    => false,
            'html_url'         => '',
            'markdown_url'     => '',
            'html_status'      => 0,
            'markdown_status'  => 0,
            'verified_at'      => time(),
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
                $status['message'] = __( 'The website .htaccess file is not readable, so the compatibility rule could not be checked or installed.', 'bloglogistics-markdown-for-agents' );
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

        $needs_write = ! self::htaccess_rule_exists( $contents );

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
                $status['message'] = __( 'The website root is not writable, so the .htaccess compatibility rule could not be created.', 'bloglogistics-markdown-for-agents' );
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
                $status['message'] = __( 'The compatibility rule could not be written to .htaccess. The original file was restored when possible.', 'bloglogistics-markdown-for-agents' );
                update_option( self::HTACCESS_STATUS_OPTION, $status, false );
                update_option( self::HTACCESS_NOTICE_OPTION, '1', false );
                return $status;
            }
        }

        clearstatcache( true, $htaccess_file );
        $saved_contents = @file_get_contents( $htaccess_file );
        $file_verified  = is_string( $saved_contents ) && self::htaccess_rule_exists( $saved_contents );

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

        $status['file_verified'] = true;
        $status['state']         = $needs_write ? 'installed' : 'already_present';
        $status['message']       = $needs_write
            ? __( 'The Markdown companion compatibility rule was added to .htaccess and confirmed in the saved file.', 'bloglogistics-markdown-for-agents' )
            : __( 'An equivalent Markdown companion compatibility rule is already present in .htaccess. No changes were made.', 'bloglogistics-markdown-for-agents' );

        if ( $verify_live ) {
            $live = self::verify_htaccess_live();

            $status['live_available']  = $live['available'];
            $status['live_verified']   = $live['passed'];
            $status['html_url']        = $live['html_url'];
            $status['markdown_url']    = $live['markdown_url'];
            $status['html_status']     = $live['html_status'];
            $status['markdown_status'] = $live['markdown_status'];
            $status['message']        .= ' ' . $live['message'];

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
     * Filesystem, timestamp, encoding, and llms.txt checks occur only during
     * this explicit administrator action. Nothing here runs on public requests.
     *
     * @return array{checked:int,found:int,removed:int,missing:int,stale:int,encoding_issues:int,llms:bool}
     */
    private static function scan_markdown_files(): array {
        $public_root       = self::public_root();
        $front_page        = ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_on_front' ) : 0;
        $checked           = 0;
        $found             = 0;
        $removed           = 0;
        $missing           = 0;
        $stale             = 0;
        $encoding_issues   = 0;
        $encoding_warnings = 0;
        $excluded          = 0;
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
            $encoding      = [];
            $md_modified   = 0;
            $post_modified = (int) get_post_modified_time( 'U', true, $post_id );
            $is_stale      = false;

            if ( $exists ) {
                update_post_meta( $post_id, self::MARKDOWN_URL_META, esc_url_raw( $markdown_url ) );
                $found++;

                $encoding    = self::validate_text_file( $markdown_file, $public_root );
                $md_modified = (int) @filemtime( $markdown_file );
                $is_stale    = $post_modified > 0 && $md_modified > 0 && $post_modified > ( $md_modified + 60 );

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
                'markdown_url'       => $exists ? esc_url_raw( $markdown_url ) : '',
                'markdown_relative'  => $relative_markdown,
                'post_modified'      => $post_modified,
                'markdown_modified'  => $md_modified,
                'stale'              => $is_stale,
                'encoding'           => $encoding,
            ];
        }

        $llms_health = self::validate_llms_file( $public_root );
        $has_llms    = ! empty( $llms_health['exists'] );
        $scanned_at  = time();

        $health = [
            'scanned_at'          => $scanned_at,
            'published'           => $checked,
            'found'               => $found,
            'missing'             => $missing,
            'stale'               => $stale,
            'encoding_issues'     => $encoding_issues,
            'encoding_warnings'   => $encoding_warnings,
            'excluded'            => $excluded,
            'llms'                => $llms_health,
            'items'               => $items,
        ];

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

        $last_scan       = (int) get_option( self::LAST_SCAN_OPTION, 0 );
        $has_llms        = '1' === get_option( self::LLMS_DETECTED_OPTION, '0' );
        $message         = isset( $_GET['bloglogistics_mfa_message'] ) ? sanitize_key( wp_unslash( $_GET['bloglogistics_mfa_message'] ) ) : '';
        $htaccess_status = get_option( self::HTACCESS_STATUS_OPTION, [] );
        $health          = get_option( self::HEALTH_OPTION, [] );

        if ( ! is_array( $htaccess_status ) ) {
            $htaccess_status = [];
        }

        if ( ! is_array( $health ) ) {
            $health = [];
        }

        $health_ready = ! empty( $health['scanned_at'] ) && isset( $health['items'] ) && is_array( $health['items'] );
        $llms_health  = isset( $health['llms'] ) && is_array( $health['llms'] ) ? $health['llms'] : [];

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

        echo '<style>
            .bl-mfa-health-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;max-width:1100px;margin:12px 0 20px}
            .bl-mfa-health-card{background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:14px 16px}
            .bl-mfa-health-card strong{display:block;font-size:24px;line-height:1.2;margin-bottom:4px}
            .bl-mfa-health-ok{color:#008a20}.bl-mfa-health-warn{color:#996800}.bl-mfa-health-bad{color:#b32d2e}
            .bl-mfa-health-table td,.bl-mfa-health-table th{vertical-align:top}
            .bl-mfa-health-detail{display:block;color:#646970;margin-top:3px}
        </style>';

        echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Editorial control stays with you.', 'bloglogistics-markdown-for-agents' ) . '</strong> ';
        echo esc_html__( 'This plugin does not create, rewrite, or curate llms.txt or any Markdown file. You create and maintain those files yourself. The plugin discovers and validates them only during an administrator-run scan.', 'bloglogistics-markdown-for-agents' );
        echo '</p></div>';

        if ( 'scanned' === $message ) {
            $checked = isset( $_GET['checked'] ) ? absint( $_GET['checked'] ) : 0;
            $found   = isset( $_GET['found'] ) ? absint( $_GET['found'] ) : 0;
            $removed = isset( $_GET['removed'] ) ? absint( $_GET['removed'] ) : 0;
            $stale   = isset( $health['stale'] ) ? (int) $health['stale'] : 0;
            $encoding_issues = isset( $health['encoding_issues'] ) ? (int) $health['encoding_issues'] : 0;

            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                esc_html__( 'Scan complete. Checked %1$d published posts/pages, detected %2$d Markdown companions, removed %3$d stale Markdown URL fields, found %4$d possibly stale companions, and found %5$d encoding issues.', 'bloglogistics-markdown-for-agents' ),
                $checked,
                $found,
                $removed,
                $stale,
                $encoding_issues
            );
            echo '</p></div>';
        } elseif ( 'exclusions_saved' === $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Per-page discovery choices saved.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
        } elseif ( 'htaccess_checked' === $message && ! empty( $htaccess_status['message'] ) ) {
            $htaccess_state = isset( $htaccess_status['state'] ) ? (string) $htaccess_status['state'] : 'unknown';
            $notice_class   = in_array( $htaccess_state, [ 'installed', 'already_present' ], true ) ? 'notice-success' : 'notice-warning';
            echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( (string) $htaccess_status['message'] ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Markdown Health Dashboard', 'bloglogistics-markdown-for-agents' ) . '</h2>';

        if ( ! $health_ready ) {
            echo '<p>' . esc_html__( 'Run the Markdown scan to populate the health dashboard. The scan checks companion presence, file freshness, UTF-8 encoding, common mojibake patterns, and llms.txt references without changing your Markdown content.', 'bloglogistics-markdown-for-agents' ) . '</p>';
        } else {
            $published         = isset( $health['published'] ) ? (int) $health['published'] : 0;
            $found_count       = isset( $health['found'] ) ? (int) $health['found'] : 0;
            $missing_count     = isset( $health['missing'] ) ? (int) $health['missing'] : 0;
            $stale_count       = isset( $health['stale'] ) ? (int) $health['stale'] : 0;
            $encoding_count    = isset( $health['encoding_issues'] ) ? (int) $health['encoding_issues'] : 0;
            $encoding_warnings = isset( $health['encoding_warnings'] ) ? (int) $health['encoding_warnings'] : 0;
            $excluded_count    = isset( $health['excluded'] ) ? (int) $health['excluded'] : 0;

            echo '<div class="bl-mfa-health-grid">';
            echo '<div class="bl-mfa-health-card"><strong>' . esc_html( number_format_i18n( $published ) ) . '</strong>' . esc_html__( 'Published posts/pages', 'bloglogistics-markdown-for-agents' ) . '</div>';
            echo '<div class="bl-mfa-health-card"><strong class="bl-mfa-health-ok">' . esc_html( number_format_i18n( $found_count ) ) . '</strong>' . esc_html__( 'Companions found', 'bloglogistics-markdown-for-agents' ) . '</div>';
            echo '<div class="bl-mfa-health-card"><strong class="' . esc_attr( $missing_count ? 'bl-mfa-health-warn' : 'bl-mfa-health-ok' ) . '">' . esc_html( number_format_i18n( $missing_count ) ) . '</strong>' . esc_html__( 'Missing companions', 'bloglogistics-markdown-for-agents' ) . '</div>';
            echo '<div class="bl-mfa-health-card"><strong class="' . esc_attr( $stale_count ? 'bl-mfa-health-warn' : 'bl-mfa-health-ok' ) . '">' . esc_html( number_format_i18n( $stale_count ) ) . '</strong>' . esc_html__( 'Possibly stale', 'bloglogistics-markdown-for-agents' ) . '</div>';
            echo '<div class="bl-mfa-health-card"><strong class="' . esc_attr( $encoding_count ? 'bl-mfa-health-bad' : 'bl-mfa-health-ok' ) . '">' . esc_html( number_format_i18n( $encoding_count ) ) . '</strong>' . esc_html__( 'Encoding issues', 'bloglogistics-markdown-for-agents' );
            if ( $encoding_warnings ) {
                echo '<span class="bl-mfa-health-detail">' . esc_html( sprintf( _n( '%d BOM warning', '%d BOM warnings', $encoding_warnings, 'bloglogistics-markdown-for-agents' ), $encoding_warnings ) ) . '</span>';
            }
            echo '</div>';
            echo '<div class="bl-mfa-health-card"><strong>' . esc_html( number_format_i18n( $excluded_count ) ) . '</strong>' . esc_html__( 'Discovery disabled', 'bloglogistics-markdown-for-agents' ) . '</div>';
            echo '</div>';

            echo '<p class="description">';
            printf(
                esc_html__( 'Health snapshot refreshed %s. “Possibly stale” means the WordPress post/page was modified more than 60 seconds after the companion file timestamp. Deployment tools can alter file timestamps, so treat this as a review signal rather than proof that content differs.', 'bloglogistics-markdown-for-agents' ),
                esc_html( wp_date( 'Y-m-d H:i:s T', (int) $health['scanned_at'] ) )
            );
            echo '</p>';

            echo '<h3>' . esc_html__( 'llms.txt Validation', 'bloglogistics-markdown-for-agents' ) . '</h3>';
            echo '<table class="form-table" role="presentation"><tbody>';
            $llms_exists = ! empty( $llms_health['exists'] );
            $llms_valid  = ! empty( $llms_health['valid'] );
            echo '<tr><th scope="row">' . esc_html__( 'llms.txt', 'bloglogistics-markdown-for-agents' ) . '</th><td><strong class="' . esc_attr( $llms_exists ? 'bl-mfa-health-ok' : 'bl-mfa-health-warn' ) . '">' . ( $llms_exists ? esc_html__( 'Present', 'bloglogistics-markdown-for-agents' ) : esc_html__( 'Missing', 'bloglogistics-markdown-for-agents' ) ) . '</strong></td></tr>';
            echo '<tr><th scope="row">' . esc_html__( 'Validation', 'bloglogistics-markdown-for-agents' ) . '</th><td><strong class="' . esc_attr( $llms_valid ? 'bl-mfa-health-ok' : 'bl-mfa-health-bad' ) . '">' . ( $llms_valid ? esc_html__( 'Passed', 'bloglogistics-markdown-for-agents' ) : esc_html__( 'Needs attention', 'bloglogistics-markdown-for-agents' ) ) . '</strong></td></tr>';

            $llms_encoding = isset( $llms_health['encoding'] ) && is_array( $llms_health['encoding'] ) ? $llms_health['encoding'] : [];
            if ( $llms_exists ) {
                $llms_encoding_valid = ! empty( $llms_encoding['valid'] );
                $llms_bom            = ! empty( $llms_encoding['bom'] );
                echo '<tr><th scope="row">' . esc_html__( 'Encoding', 'bloglogistics-markdown-for-agents' ) . '</th><td><strong class="' . esc_attr( $llms_encoding_valid ? 'bl-mfa-health-ok' : 'bl-mfa-health-bad' ) . '">' . ( $llms_encoding_valid ? esc_html__( 'Valid UTF-8', 'bloglogistics-markdown-for-agents' ) : esc_html__( 'Needs attention', 'bloglogistics-markdown-for-agents' ) ) . '</strong>';
                if ( $llms_bom ) {
                    echo '<span class="bl-mfa-health-detail">' . esc_html__( 'UTF-8 BOM detected.', 'bloglogistics-markdown-for-agents' ) . '</span>';
                }
                echo '</td></tr>';
            }

            echo '<tr><th scope="row">' . esc_html__( 'Local Markdown links checked', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . esc_html( number_format_i18n( isset( $llms_health['local_links_checked'] ) ? (int) $llms_health['local_links_checked'] : 0 ) ) . '</td></tr>';
            $broken_count = isset( $llms_health['broken_local_links'] ) && is_array( $llms_health['broken_local_links'] ) ? count( $llms_health['broken_local_links'] ) : 0;
            echo '<tr><th scope="row">' . esc_html__( 'Broken local Markdown references', 'bloglogistics-markdown-for-agents' ) . '</th><td><strong class="' . esc_attr( $broken_count ? 'bl-mfa-health-bad' : 'bl-mfa-health-ok' ) . '">' . esc_html( number_format_i18n( $broken_count ) ) . '</strong></td></tr>';
            echo '</tbody></table>';

            $llms_errors   = isset( $llms_health['errors'] ) && is_array( $llms_health['errors'] ) ? $llms_health['errors'] : [];
            $llms_warnings = isset( $llms_health['warnings'] ) && is_array( $llms_health['warnings'] ) ? $llms_health['warnings'] : [];

            if ( $llms_errors ) {
                echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'llms.txt errors:', 'bloglogistics-markdown-for-agents' ) . '</strong></p><ul style="list-style:disc;margin-left:2em;">';
                foreach ( $llms_errors as $issue ) {
                    if ( is_array( $issue ) ) {
                        echo '<li>' . esc_html( self::llms_issue_label( $issue ) ) . '</li>';
                    }
                }
                echo '</ul></div>';
            }

            if ( $llms_warnings ) {
                echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'llms.txt warnings:', 'bloglogistics-markdown-for-agents' ) . '</strong></p><ul style="list-style:disc;margin-left:2em;">';
                foreach ( $llms_warnings as $issue ) {
                    if ( is_array( $issue ) ) {
                        echo '<li>' . esc_html( self::llms_issue_label( $issue ) ) . '</li>';
                    }
                }
                echo '</ul></div>';
            }

            echo '<h3 style="margin-top:1.5em;">' . esc_html__( 'Content Health', 'bloglogistics-markdown-for-agents' ) . '</h3>';
            echo '<table class="widefat striped bl-mfa-health-table"><thead><tr>';
            echo '<th>' . esc_html__( 'Title', 'bloglogistics-markdown-for-agents' ) . '</th>';
            echo '<th>' . esc_html__( 'Type', 'bloglogistics-markdown-for-agents' ) . '</th>';
            echo '<th>' . esc_html__( 'Companion', 'bloglogistics-markdown-for-agents' ) . '</th>';
            echo '<th>' . esc_html__( 'Freshness', 'bloglogistics-markdown-for-agents' ) . '</th>';
            echo '<th>' . esc_html__( 'Encoding', 'bloglogistics-markdown-for-agents' ) . '</th>';
            echo '<th>' . esc_html__( 'Discovery', 'bloglogistics-markdown-for-agents' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $health['items'] as $post_id => $item ) {
                $post_id = (int) $post_id;

                if ( ! $post_id || ! is_array( $item ) || ! get_post( $post_id ) ) {
                    continue;
                }

                $exists   = ! empty( $item['exists'] );
                $disabled = ! empty( $item['disabled'] );
                $is_stale = ! empty( $item['stale'] );
                $encoding = isset( $item['encoding'] ) && is_array( $item['encoding'] ) ? $item['encoding'] : [];
                $edit_link = get_edit_post_link( $post_id );

                echo '<tr><td>';
                if ( $edit_link ) {
                    echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a>';
                } else {
                    echo esc_html( get_the_title( $post_id ) );
                }
                if ( ! empty( $item['markdown_relative'] ) ) {
                    echo '<span class="bl-mfa-health-detail"><code>/' . esc_html( ltrim( (string) $item['markdown_relative'], '/' ) ) . '</code></span>';
                }
                echo '</td>';
                echo '<td>' . esc_html( get_post_type( $post_id ) ) . '</td>';

                if ( $exists ) {
                    echo '<td><strong class="bl-mfa-health-ok">' . esc_html__( 'Found', 'bloglogistics-markdown-for-agents' ) . '</strong></td>';
                } elseif ( $disabled ) {
                    echo '<td><strong>' . esc_html__( 'Excluded', 'bloglogistics-markdown-for-agents' ) . '</strong></td>';
                } else {
                    echo '<td><strong class="bl-mfa-health-warn">' . esc_html__( 'Missing', 'bloglogistics-markdown-for-agents' ) . '</strong></td>';
                }

                echo '<td>';
                if ( ! $exists ) {
                    echo '&mdash;';
                } elseif ( $is_stale ) {
                    echo '<strong class="bl-mfa-health-warn">' . esc_html__( 'Possibly stale', 'bloglogistics-markdown-for-agents' ) . '</strong>';
                    $post_modified = isset( $item['post_modified'] ) ? (int) $item['post_modified'] : 0;
                    $md_modified   = isset( $item['markdown_modified'] ) ? (int) $item['markdown_modified'] : 0;
                    if ( $post_modified && $md_modified ) {
                        echo '<span class="bl-mfa-health-detail">';
                        printf(
                            esc_html__( 'Page: %1$s; Markdown: %2$s', 'bloglogistics-markdown-for-agents' ),
                            esc_html( wp_date( 'Y-m-d H:i', $post_modified ) ),
                            esc_html( wp_date( 'Y-m-d H:i', $md_modified ) )
                        );
                        echo '</span>';
                    }
                } else {
                    echo '<strong class="bl-mfa-health-ok">' . esc_html__( 'Current by timestamp', 'bloglogistics-markdown-for-agents' ) . '</strong>';
                }
                echo '</td>';

                echo '<td>';
                if ( ! $exists ) {
                    echo '&mdash;';
                } elseif ( ! empty( $encoding['valid'] ) ) {
                    echo '<strong class="bl-mfa-health-ok">' . esc_html__( 'Valid UTF-8', 'bloglogistics-markdown-for-agents' ) . '</strong>';
                    if ( ! empty( $encoding['bom'] ) ) {
                        echo '<span class="bl-mfa-health-detail">' . esc_html__( 'BOM warning', 'bloglogistics-markdown-for-agents' ) . '</span>';
                    }
                } else {
                    echo '<strong class="bl-mfa-health-bad">' . esc_html__( 'Needs attention', 'bloglogistics-markdown-for-agents' ) . '</strong>';
                    if ( isset( $encoding['issues'] ) && is_array( $encoding['issues'] ) ) {
                        foreach ( $encoding['issues'] as $issue ) {
                            echo '<span class="bl-mfa-health-detail">' . esc_html( self::encoding_issue_label( (string) $issue ) ) . '</span>';
                        }
                    }
                }
                echo '</td>';

                if ( $disabled ) {
                    echo '<td><strong>' . esc_html__( 'Disabled', 'bloglogistics-markdown-for-agents' ) . '</strong></td>';
                } elseif ( $exists ) {
                    echo '<td><strong class="bl-mfa-health-ok">' . esc_html__( 'Advertised', 'bloglogistics-markdown-for-agents' ) . '</strong></td>';
                } else {
                    echo '<td>' . esc_html__( 'Not advertised', 'bloglogistics-markdown-for-agents' ) . '</td>';
                }

                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '<div style="margin-top:1em;">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
        wp_nonce_field( 'bloglogistics_mfa_scan' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_scan">';
        submit_button( __( 'Scan for Markdown Files and Refresh Health', 'bloglogistics-markdown-for-agents' ), 'primary', 'submit', false );
        echo '</form>';
        echo '</div>';

        echo '<p class="description">' . esc_html__( 'The scan reads local files only. It does not make external requests for llms.txt link validation and it does not alter Markdown or llms.txt.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        echo '<h2 style="margin-top:2em;">' . esc_html__( 'How it works', 'bloglogistics-markdown-for-agents' ) . '</h2>';
        echo '<ol>';
        echo '<li>' . esc_html__( 'You create a carefully curated /llms.txt file and any Markdown companion files you want to publish.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'For a normal page such as /about-us/, place its companion at /about-us/index.md. The homepage companion is /index.md.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'Run the scan after files are added, removed, moved, or edited. The scan stores detected Markdown URLs and refreshes the administrator health snapshot.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'On public page loads, the plugin performs no filesystem checks and generates no Markdown. It only reads already-stored metadata and outputs discovery markup once for eligible pages.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '</ol>';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__( 'llms.txt detected at last scan', 'bloglogistics-markdown-for-agents' ) . '</th><td><strong>' . ( $has_llms ? esc_html__( 'Yes', 'bloglogistics-markdown-for-agents' ) : esc_html__( 'No', 'bloglogistics-markdown-for-agents' ) ) . '</strong></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Markdown companions detected', 'bloglogistics-markdown-for-agents' ) . '</th><td><strong>' . esc_html( number_format_i18n( count( $detected_ids ) ) ) . '</strong></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Last scan', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . ( $last_scan ? esc_html( wp_date( 'Y-m-d H:i:s T', $last_scan ) ) : esc_html__( 'Not yet scanned', 'bloglogistics-markdown-for-agents' ) ) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2 style="margin-top:2em;">' . esc_html__( 'WordPress / Markdown Directory Compatibility', 'bloglogistics-markdown-for-agents' ) . '</h2>';
        echo '<p>' . esc_html__( 'Static /slug/index.md companions create real directories. On Apache-compatible servers, the plugin maintains a root .htaccess rule so /slug/ continues to load the WordPress page while /slug/index.md continues to serve the Markdown file.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        $file_verified  = ! empty( $htaccess_status['file_verified'] );
        $live_available = ! empty( $htaccess_status['live_available'] );
        $live_verified  = ! empty( $htaccess_status['live_verified'] );
        $verified_at    = isset( $htaccess_status['verified_at'] ) ? (int) $htaccess_status['verified_at'] : 0;

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__( '.htaccess rule', 'bloglogistics-markdown-for-agents' ) . '</th><td><strong>' . ( $file_verified ? esc_html__( 'Present and verified', 'bloglogistics-markdown-for-agents' ) : esc_html__( 'Not verified', 'bloglogistics-markdown-for-agents' ) ) . '</strong></td></tr>';

        if ( ! empty( $htaccess_status['backup_file'] ) ) {
            echo '<tr><th scope="row">' . esc_html__( 'Most recent backup', 'bloglogistics-markdown-for-agents' ) . '</th><td><code>' . esc_html( basename( (string) $htaccess_status['backup_file'] ) ) . '</code></td></tr>';
        }

        if ( $live_available ) {
            echo '<tr><th scope="row">' . esc_html__( 'Live coexistence test', 'bloglogistics-markdown-for-agents' ) . '</th><td><strong>' . ( $live_verified ? esc_html__( 'Passed', 'bloglogistics-markdown-for-agents' ) : esc_html__( 'Did not pass', 'bloglogistics-markdown-for-agents' ) ) . '</strong>';

            if ( ! empty( $htaccess_status['html_url'] ) ) {
                echo '<br><span class="description">' . esc_html__( 'WordPress page:', 'bloglogistics-markdown-for-agents' ) . ' <code>' . esc_html( (string) $htaccess_status['html_url'] ) . '</code> (' . esc_html( (string) (int) $htaccess_status['html_status'] ) . ')</span>';
            }

            if ( ! empty( $htaccess_status['markdown_url'] ) ) {
                echo '<br><span class="description">' . esc_html__( 'Markdown companion:', 'bloglogistics-markdown-for-agents' ) . ' <code>' . esc_html( (string) $htaccess_status['markdown_url'] ) . '</code> (' . esc_html( (string) (int) $htaccess_status['markdown_status'] ) . ')</span>';
            }

            echo '</td></tr>';
        } elseif ( $file_verified ) {
            echo '<tr><th scope="row">' . esc_html__( 'Live coexistence test', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . esc_html__( 'Waiting for a detected non-homepage Markdown companion.', 'bloglogistics-markdown-for-agents' ) . '</td></tr>';
        }

        if ( $verified_at ) {
            echo '<tr><th scope="row">' . esc_html__( 'Last compatibility check', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . esc_html( wp_date( 'Y-m-d H:i:s T', $verified_at ) ) . '</td></tr>';
        }

        if ( ! empty( $htaccess_status['message'] ) ) {
            echo '<tr><th scope="row">' . esc_html__( 'Status', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . esc_html( (string) $htaccess_status['message'] ) . '</td></tr>';
        }

        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bloglogistics_mfa_htaccess' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_htaccess">';
        submit_button( __( 'Install / Repair and Verify .htaccess Rule', 'bloglogistics-markdown-for-agents' ), 'secondary', 'submit', false );
        echo '</form>';

        echo '<p class="description">' . esc_html__( 'Before changing an existing .htaccess file, the plugin creates a timestamped backup beside it. If the write cannot be verified, the plugin attempts to restore the original file automatically.', 'bloglogistics-markdown-for-agents' ) . '</p>';

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
        self::ensure_htaccess_rule( true );
        delete_option( self::HTACCESS_NOTICE_OPTION );

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

    public static function handle_htaccess(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage the .htaccess compatibility rule.', 'bloglogistics-markdown-for-agents' ) );
        }

        check_admin_referer( 'bloglogistics_mfa_htaccess' );
        self::ensure_htaccess_rule( true );
        delete_option( self::HTACCESS_NOTICE_OPTION );

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                      => self::SETTINGS_SLUG,
                    'bloglogistics_mfa_message' => 'htaccess_checked',
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

        // Refresh the administrator-only health snapshot so the dashboard's
        // discovery-disabled count and per-page status match the saved choices.
        self::scan_markdown_files();

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
