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
            default     => sprintf(
                /* translators: %s: Content-Type response header. */
                __( 'Incorrect or missing Markdown MIME type (%s)', 'bloglogistics-markdown-for-agents' ),
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
            'markdown_status'               => __( 'The Markdown companion did not return HTTP 200.', 'bloglogistics-markdown-for-agents' ),
            'markdown_mime'                 => __( 'The Markdown companion returned an incorrect or missing Content-Type.', 'bloglogistics-markdown-for-agents' ),
            'missing_markdown_discovery'    => __( 'The expected rel="alternate" Markdown discovery link was not found.', 'bloglogistics-markdown-for-agents' ),
            'unexpected_markdown_discovery' => __( 'A Markdown discovery link is still present even though discovery is disabled.', 'bloglogistics-markdown-for-agents' ),
            'missing_llms_discovery'        => __( 'The expected llms.txt rel="describedby" link was not found.', 'bloglogistics-markdown-for-agents' ),
            'unexpected_llms_discovery'     => __( 'An llms.txt discovery link is present when it is not expected.', 'bloglogistics-markdown-for-agents' ),
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
    private static function live_request( string $url, int $response_limit ): array {
        $result = [
            'url'            => $url,
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

        $token    = rawurlencode( (string) time() . '-' . (string) wp_rand( 100000, 999999 ) );
        $test_url = add_query_arg( 'bloglogistics_mfa_live', $token, $url );
        $common   = [
            'timeout'             => 12,
            'reject_unsafe_urls'  => true,
            'limit_response_size' => $response_limit,
            'headers'             => [
                'Cache-Control' => 'no-cache',
                'Pragma'        => 'no-cache',
            ],
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
            'post_id'              => $post_id,
            'checked_at'           => $checked_at,
            'html_url'             => is_string( $html_url ) ? $html_url : '',
            'markdown_url'         => $markdown_url,
            'disabled'             => $disabled,
            'html_initial_status'  => 0,
            'html_status'          => 0,
            'html_redirected'      => false,
            'markdown_initial_status' => 0,
            'markdown_status'      => 0,
            'markdown_redirected'  => false,
            'content_type'         => '',
            'mime_state'           => 'bad',
            'discovery'            => [],
            'issues'               => [],
            'warnings'             => [],
            'passed'               => false,
        ];

        if ( ! is_string( $html_url ) || '' === $html_url || '' === $markdown_url ) {
            $result['issues'][] = 'missing_stored_url';
            return $result;
        }

        $html = self::live_request( $html_url, 1048576 );
        $md   = self::live_request( $markdown_url, 131072 );

        $result['html_initial_status']     = (int) $html['initial_status'];
        $result['html_status']             = (int) $html['status'];
        $result['html_redirected']         = ! empty( $html['redirected'] );
        $result['markdown_initial_status'] = (int) $md['initial_status'];
        $result['markdown_status']         = (int) $md['status'];
        $result['markdown_redirected']     = ! empty( $md['redirected'] );
        $result['content_type']            = (string) $md['content_type'];
        $result['mime_state']              = self::markdown_mime_state( (string) $md['content_type'] );

        if ( '' !== (string) $html['error'] ) {
            $result['issues'][] = 'html_request_error';
            $result['html_error'] = (string) $html['error'];
        } elseif ( 200 !== (int) $html['status'] ) {
            $result['issues'][] = 'html_status';
        }

        if ( '' !== (string) $md['error'] ) {
            $result['issues'][] = 'markdown_request_error';
            $result['markdown_error'] = (string) $md['error'];
        } elseif ( 200 !== (int) $md['status'] ) {
            $result['issues'][] = 'markdown_status';
        }

        if ( 200 === (int) $md['status'] ) {
            if ( 'bad' === $result['mime_state'] ) {
                $result['issues'][] = 'markdown_mime';
            } elseif ( 'warning' === $result['mime_state'] ) {
                $result['warnings'][] = 'markdown_mime';
            }
        }

        if ( 200 === (int) $html['status'] ) {
            $expect_discovery    = ! $disabled;
            $result['discovery'] = self::verify_discovery_markup(
                (string) $html['body'],
                $markdown_url,
                $expect_discovery,
                $expect_discovery && $has_llms
            );

            foreach ( $result['discovery']['issues'] as $issue ) {
                $result['issues'][] = (string) $issue;
            }
        }

        if ( $result['html_redirected'] || $result['markdown_redirected'] ) {
            $result['warnings'][] = 'redirected';
        }

        $result['issues']   = array_values( array_unique( $result['issues'] ) );
        $result['warnings'] = array_values( array_unique( $result['warnings'] ) );
        $result['passed']   = empty( $result['issues'] );

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
        $live_items       = $only_ids && isset( $previous_live['items'] ) && is_array( $previous_live['items'] )
            ? $previous_live['items']
            : [];

        $checked            = 0;
        $passed             = 0;
        $failed             = 0;
        $mime_warnings      = 0;
        $mime_failures      = 0;
        $discovery_failures = 0;
        $redirected         = 0;
        $request_errors     = 0;

        foreach ( $post_ids as $post_id ) {
            $item = self::verify_live_item( (int) $post_id, $has_llms );
            $live_items[ (int) $post_id ] = $item;
            $checked++;

            if ( ! empty( $item['passed'] ) ) {
                $passed++;
            } else {
                $failed++;
            }

            if ( 200 === (int) ( $item['markdown_status'] ?? 0 ) ) {
                if ( 'warning' === (string) ( $item['mime_state'] ?? '' ) ) {
                    $mime_warnings++;
                } elseif ( 'bad' === (string) ( $item['mime_state'] ?? '' ) ) {
                    $mime_failures++;
                }
            }

            if ( isset( $item['discovery']['passed'] ) && ! $item['discovery']['passed'] ) {
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

        $live = [
            'verified_at'        => time(),
            'scope'              => $only_ids ? 'selected' : 'all',
            'candidate_count'    => $total_candidates,
            'checked'            => $checked,
            'passed'             => $passed,
            'failed'             => $failed,
            'mime_warnings'      => $mime_warnings,
            'mime_failures'      => $mime_failures,
            'discovery_failures' => $discovery_failures,
            'redirected'         => $redirected,
            'request_errors'     => $request_errors,
            'truncated'          => $truncated,
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
                'message'               => __( 'The .htaccess rules are present, but no non-homepage Markdown companion is currently recorded for an end-to-end live test. Run the Markdown scan after uploading companion files.', 'bloglogistics-markdown-for-agents' ),
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
            $message = __( 'Live verification passed: the WordPress page and Markdown companion returned HTTP 200, and the Markdown companion returned the preferred text/markdown Content-Type.', 'bloglogistics-markdown-for-agents' );
        } elseif ( ! $http_passed ) {
            $message = sprintf(
                /* translators: 1: HTML status code, 2: Markdown status code. */
                __( 'Live verification did not pass. The WordPress page returned HTTP %1$d and the Markdown companion returned HTTP %2$d.', 'bloglogistics-markdown-for-agents' ),
                $html_status,
                $markdown_status
            );
        } else {
            $content_type_label = '' !== trim( $markdown_content_type )
                ? trim( $markdown_content_type )
                : __( 'No Content-Type header', 'bloglogistics-markdown-for-agents' );

            $message = sprintf(
                /* translators: %s: Content-Type response header or no-header label. */
                __( 'Both endpoints returned HTTP 200, but the Markdown MIME rule is not live yet. The Markdown companion returned: %s.', 'bloglogistics-markdown-for-agents' ),
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

        if ( 0 === $changed && isset( $previous_health['live'] ) && is_array( $previous_health['live'] ) ) {
            $health['live'] = $previous_health['live'];
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

    private static function settings_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

        return in_array( $tab, [ 'overview', 'pages', 'posts', 'llms', 'server' ], true ) ? $tab : 'overview';
    }

    private static function settings_view(): string {
        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all';

        return in_array( $view, [ 'all', 'attention', 'missing', 'stale', 'encoding', 'live', 'disabled' ], true ) ? $view : 'all';
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
        }

        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    /**
     * Preserve the administrator's current tab/filter after an action.
     *
     * @return array{tab:string,view:string}
     */
    private static function return_context( string $default_tab = 'overview', string $default_view = 'all' ): array {
        $tab_source  = isset( $_POST['return_tab'] ) ? wp_unslash( $_POST['return_tab'] ) : ( isset( $_GET['return_tab'] ) ? wp_unslash( $_GET['return_tab'] ) : $default_tab );
        $view_source = isset( $_POST['return_view'] ) ? wp_unslash( $_POST['return_view'] ) : ( isset( $_GET['return_view'] ) ? wp_unslash( $_GET['return_view'] ) : $default_view );
        $tab         = sanitize_key( (string) $tab_source );
        $view        = sanitize_key( (string) $view_source );

        if ( ! in_array( $tab, [ 'overview', 'pages', 'posts', 'llms', 'server' ], true ) ) {
            $tab = $default_tab;
        }

        if ( ! in_array( $view, [ 'all', 'attention', 'missing', 'stale', 'encoding', 'live', 'disabled' ], true ) ) {
            $view = $default_view;
        }

        return [
            'tab'  => $tab,
            'view' => $view,
        ];
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
        $live_bad = $live && empty( $live_item['passed'] );
        $warnings = $live && isset( $live_item['warnings'] ) && is_array( $live_item['warnings'] ) ? $live_item['warnings'] : [];
        $live_review = $live && ! $live_bad && ! empty( $warnings );
        $missing     = $scanned && ! $exists && ! $disabled;
        $attention   = $missing || $stale || $enc_bad || $enc_bom || $live_bad || $live_review;

        return [
            'scanned'       => $scanned,
            'exists'        => $exists,
            'disabled'      => $disabled,
            'missing'       => $missing,
            'stale'         => $stale,
            'encoding'      => $encoding,
            'encoding_bad'  => $enc_bad,
            'encoding_bom'  => $enc_bom,
            'live'          => $live,
            'live_bad'      => $live_bad,
            'live_review'   => $live_review,
            'attention'     => $attention,
            'item'          => $item,
            'live_item'     => $live_item,
            'markdown_url'  => $exists && ! empty( $item['markdown_url'] ) ? (string) $item['markdown_url'] : (string) get_post_meta( $post_id, self::MARKDOWN_URL_META, true ),
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
            'all'       => count( $ids ),
            'attention' => 0,
            'problems'  => 0,
            'reviews'   => 0,
            'missing'   => 0,
            'stale'     => 0,
            'encoding'  => 0,
            'live'      => 0,
            'disabled'  => 0,
            'found'     => 0,
        ];
        $items = isset( $health['items'] ) && is_array( $health['items'] ) ? $health['items'] : [];

        foreach ( $ids as $post_id ) {
            $item       = isset( $items[ $post_id ] ) && is_array( $items[ $post_id ] ) ? $items[ $post_id ] : [];
            $live_item  = isset( $live_items[ $post_id ] ) && is_array( $live_items[ $post_id ] ) ? $live_items[ $post_id ] : [];
            $state      = self::content_item_state( (int) $post_id, $item, $live_item );

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

            $has_problem = ! empty( $state['missing'] ) || ! empty( $state['encoding_bad'] ) || ! empty( $state['live_bad'] );
            $has_review  = ! empty( $state['stale'] ) || ! empty( $state['encoding_bom'] ) || ! empty( $state['live_review'] );

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
        submit_button( __( 'Scan Changes and Refresh Health', 'bloglogistics-markdown-for-agents' ), 'primary', 'submit', false );
        echo ' <button type="submit" class="button" name="force_full_scan" value="1">' . esc_html__( 'Force Full Rescan', 'bloglogistics-markdown-for-agents' ) . '</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bloglogistics_mfa_live_verify' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_live_verify">';
        echo '<input type="hidden" name="return_tab" value="' . esc_attr( $tab ) . '">';
        echo '<input type="hidden" name="return_view" value="' . esc_attr( $view ) . '">';
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
        $live_issues  = $page_counts['live'] + $post_counts['live'];
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
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Run the Markdown scan to populate health information. The scan checks companion presence, freshness, UTF-8 encoding, common mojibake patterns, and llms.txt references without changing your Markdown content.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
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
            __( 'Companions found', 'bloglogistics-markdown-for-agents' ),
            $found,
            $health_ready ? 'healthy' : 'na',
            [
                sprintf( __( 'Pages %d', 'bloglogistics-markdown-for-agents' ), $page_counts['found'] ) => self::settings_url( 'pages', 'all' ),
                sprintf( __( 'Posts %d', 'bloglogistics-markdown-for-agents' ), $post_counts['found'] ) => self::settings_url( 'posts', 'all' ),
            ]
        );
        self::render_dashboard_card(
            __( 'Missing companions', 'bloglogistics-markdown-for-agents' ),
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
        self::render_dashboard_card(
            __( 'Live issues', 'bloglogistics-markdown-for-agents' ),
            $live_issues,
            ! $live_health ? 'na' : ( ! empty( $live_health['failed'] ) ? 'problem' : ( $live_issues ? 'review' : 'healthy' ) ),
            [
                sprintf( __( 'Pages %d', 'bloglogistics-markdown-for-agents' ), $page_counts['live'] ) => self::settings_url( 'pages', 'live' ),
                sprintf( __( 'Posts %d', 'bloglogistics-markdown-for-agents' ), $post_counts['live'] ) => self::settings_url( 'posts', 'live' ),
            ],
            $live_health ? __( 'Based on the latest saved live checks', 'bloglogistics-markdown-for-agents' ) : __( 'Not live-verified yet', 'bloglogistics-markdown-for-agents' )
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
        echo '<p class="description">' . esc_html__( 'The incremental scan performs local administrator-only checks. Live verification is separate because it makes same-site HTTP requests. Neither operation runs during normal public page loads.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        echo '<h2 style="margin-top:2em;">' . esc_html__( 'How it works', 'bloglogistics-markdown-for-agents' ) . '</h2>';
        echo '<ol class="bl-mfa-how-it-works">';
        echo '<li>' . esc_html__( 'Create and maintain your curated /llms.txt and Markdown companion files.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'For a normal page such as /about-us/, place the companion at /about-us/index.md. The homepage companion is /index.md.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'Run the incremental scan after files or WordPress content change. Use Force Full Rescan when every companion should be re-read.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'Use the Pages and Posts tabs to review health, filter problems, change discovery settings, or verify selected content.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '<li>' . esc_html__( 'Use the llms.txt and Server & .htaccess tabs for focused validation and technical diagnostics.', 'bloglogistics-markdown-for-agents' ) . '</li>';
        echo '</ol>';
    }

    /**
     * Render either the Pages or Posts management tab.
     *
     * @param array<string,mixed> $health      Saved health snapshot.
     * @param array<int,mixed>    $live_items  Saved live results.
     */
    private static function render_content_tab( string $post_type, string $tab, string $view, array $health, array $live_items ): void {
        $ids    = self::published_ids_by_type( $post_type );
        $items  = isset( $health['items'] ) && is_array( $health['items'] ) ? $health['items'] : [];
        $counts = self::content_filter_counts( $ids, $health, $live_items );
        $labels = [
            'all'       => __( 'All', 'bloglogistics-markdown-for-agents' ),
            'attention' => __( 'Needs attention', 'bloglogistics-markdown-for-agents' ),
            'missing'   => __( 'Missing', 'bloglogistics-markdown-for-agents' ),
            'stale'     => __( 'Stale', 'bloglogistics-markdown-for-agents' ),
            'encoding'  => __( 'Encoding', 'bloglogistics-markdown-for-agents' ),
            'live'      => __( 'Live issues', 'bloglogistics-markdown-for-agents' ),
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

        echo '<h2>' . esc_html( 'page' === $post_type ? __( 'Pages', 'bloglogistics-markdown-for-agents' ) : __( 'Posts', 'bloglogistics-markdown-for-agents' ) ) . '</h2>';
        echo '<p>' . esc_html__( 'Review Markdown health and discovery settings here. The table combines companion detection, freshness, encoding, live verification, and discovery state in one place.', 'bloglogistics-markdown-for-agents' ) . '</p>';

        echo '<ul class="subsubsub bl-mfa-filters">';
        $parts = [];
        foreach ( $labels as $key => $label ) {
            $class   = $view === $key ? ' class="current" aria-current="page"' : '';
            $parts[] = '<li><a' . $class . ' href="' . esc_url( self::settings_url( $tab, $key ) ) . '">' . esc_html( $label ) . ' <span class="count">(' . esc_html( number_format_i18n( $counts[ $key ] ) ) . ')</span></a></li>';
        }
        echo implode( ' | ', $parts );
        echo '</ul><div class="clear"></div>';

        self::render_scan_actions( $tab, $view );

        if ( ! $ids ) {
            echo '<p>' . esc_html__( 'No published content exists in this section.', 'bloglogistics-markdown-for-agents' ) . '</p>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'bloglogistics_mfa_save_exclusions' );
        echo '<input type="hidden" name="action" value="bloglogistics_mfa_save_exclusions">';
        echo '<input type="hidden" name="return_tab" value="' . esc_attr( $tab ) . '">';
        echo '<input type="hidden" name="return_view" value="' . esc_attr( $view ) . '">';

        foreach ( $ids as $post_id ) {
            echo '<input type="hidden" name="managed_ids[]" value="' . esc_attr( (string) $post_id ) . '">';
            if ( ! in_array( $post_id, $visible_ids, true ) && self::is_discovery_disabled( (int) $post_id ) ) {
                echo '<input type="hidden" name="disabled_ids[]" value="' . esc_attr( (string) $post_id ) . '">';
            }
        }

        echo '<div class="bl-mfa-actions"><select name="bulk_action">';
        echo '<option value="">' . esc_html__( 'Bulk actions', 'bloglogistics-markdown-for-agents' ) . '</option>';
        echo '<option value="enable">' . esc_html__( 'Enable discovery', 'bloglogistics-markdown-for-agents' ) . '</option>';
        echo '<option value="disable">' . esc_html__( 'Disable discovery', 'bloglogistics-markdown-for-agents' ) . '</option>';
        echo '<option value="rescan">' . esc_html__( 'Force revalidate selected', 'bloglogistics-markdown-for-agents' ) . '</option>';
        echo '<option value="verify">' . esc_html__( 'Live verify selected', 'bloglogistics-markdown-for-agents' ) . '</option>';
        echo '</select><button type="submit" class="button" name="apply_bulk" value="1">' . esc_html__( 'Apply', 'bloglogistics-markdown-for-agents' ) . '</button></div>';

        if ( ! $visible_ids ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No items match this filter.', 'bloglogistics-markdown-for-agents' ) . '</p></div>';
            echo '</form>';
            return;
        }

        echo '<div class="bl-mfa-table-wrap"><table class="widefat striped bl-mfa-health-table"><thead><tr>';
        echo '<td class="check-column"><input type="checkbox" class="bl-mfa-select-all" aria-label="' . esc_attr__( 'Select all visible items', 'bloglogistics-markdown-for-agents' ) . '"></td>';
        echo '<th>' . esc_html__( 'Title', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Companion', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Freshness', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Encoding', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Live Status', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '<th>' . esc_html__( 'Discovery', 'bloglogistics-markdown-for-agents' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $visible_ids as $post_id ) {
            $item      = isset( $items[ $post_id ] ) && is_array( $items[ $post_id ] ) ? $items[ $post_id ] : [];
            $live_item = isset( $live_items[ $post_id ] ) && is_array( $live_items[ $post_id ] ) ? $live_items[ $post_id ] : [];
            $state     = self::content_item_state( $post_id, $item, $live_item );
            $title     = get_the_title( $post_id );
            $edit_link = get_edit_post_link( $post_id );
            $view_link = get_permalink( $post_id );
            $md_url    = (string) $state['markdown_url'];
            $verify_url = wp_nonce_url(
                add_query_arg(
                    [
                        'action'      => 'bloglogistics_mfa_verify_item',
                        'post_id'     => $post_id,
                        'return_tab'  => $tab,
                        'return_view' => $view,
                    ],
                    admin_url( 'admin-post.php' )
                ),
                'bloglogistics_mfa_verify_item_' . $post_id
            );

            echo '<tr><th scope="row" class="check-column"><input class="bl-mfa-row-select" type="checkbox" name="selected_ids[]" value="' . esc_attr( (string) $post_id ) . '" aria-label="' . esc_attr( sprintf( __( 'Select %s', 'bloglogistics-markdown-for-agents' ), $title ) ) . '"></th><td class="column-title"><strong>';
            if ( $edit_link ) {
                echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $title ) . '</a>';
            } else {
                echo esc_html( $title );
            }
            echo '</strong>';
            if ( ! empty( $item['markdown_relative'] ) ) {
                echo '<span class="bl-mfa-health-detail"><code>/' . esc_html( ltrim( (string) $item['markdown_relative'], '/' ) ) . '</code></span>';
            }
            echo '<div class="row-actions">';
            $row_actions = [];
            if ( $edit_link ) {
                $row_actions[] = '<a href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Edit', 'bloglogistics-markdown-for-agents' ) . '</a>';
            }
            if ( is_string( $view_link ) && '' !== $view_link ) {
                $view_label    = 'page' === $post_type ? __( 'View Page', 'bloglogistics-markdown-for-agents' ) : __( 'View Post', 'bloglogistics-markdown-for-agents' );
                $row_actions[] = '<a href="' . esc_url( $view_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $view_label ) . '</a>';
            }
            if ( ! empty( $state['exists'] ) && '' !== $md_url ) {
                $row_actions[] = '<a href="' . esc_url( $md_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Markdown', 'bloglogistics-markdown-for-agents' ) . '</a>';
                $row_actions[] = '<a href="' . esc_url( $verify_url ) . '">' . esc_html__( 'Verify', 'bloglogistics-markdown-for-agents' ) . '</a>';
            }
            echo implode( ' | ', $row_actions );
            echo '</div></td>';

            echo '<td>';
            if ( empty( $state['scanned'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'Not scanned yet', 'bloglogistics-markdown-for-agents' ) );
            } elseif ( ! empty( $state['exists'] ) ) {
                echo self::status_markup( 'healthy', __( 'Healthy', 'bloglogistics-markdown-for-agents' ), __( 'Companion found', 'bloglogistics-markdown-for-agents' ) );
            } elseif ( ! empty( $state['disabled'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'No companion; discovery is disabled', 'bloglogistics-markdown-for-agents' ) );
            } else {
                echo self::status_markup( 'problem', __( 'Problem', 'bloglogistics-markdown-for-agents' ), __( 'Companion missing', 'bloglogistics-markdown-for-agents' ) );
            }
            echo '</td>';

            echo '<td>';
            if ( empty( $state['exists'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'No companion to compare', 'bloglogistics-markdown-for-agents' ) );
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
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'No companion to validate', 'bloglogistics-markdown-for-agents' ) );
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
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'No companion to verify', 'bloglogistics-markdown-for-agents' ) );
            } elseif ( empty( $state['live'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'Not live-verified yet', 'bloglogistics-markdown-for-agents' ) );
            } else {
                $live_detail = sprintf(
                    __( 'HTML %1$d; Markdown %2$d', 'bloglogistics-markdown-for-agents' ),
                    (int) ( $live_item['html_status'] ?? 0 ),
                    (int) ( $live_item['markdown_status'] ?? 0 )
                );
                $mime_label = self::markdown_mime_label( (string) ( $live_item['mime_state'] ?? 'bad' ), (string) ( $live_item['content_type'] ?? '' ) );
                if ( '' !== $mime_label ) {
                    $live_detail .= '; ' . $mime_label;
                }
                if ( ! empty( $state['live_bad'] ) ) {
                    $issue_labels = [];
                    foreach ( (array) ( $live_item['issues'] ?? [] ) as $issue ) {
                        $issue_labels[] = self::live_issue_label( (string) $issue );
                    }
                    if ( $issue_labels ) {
                        $live_detail .= '; ' . implode( ' ', $issue_labels );
                    }
                    echo self::status_markup( 'problem', __( 'Problem', 'bloglogistics-markdown-for-agents' ), $live_detail );
                } elseif ( ! empty( $state['live_review'] ) ) {
                    echo self::status_markup( 'review', __( 'Review', 'bloglogistics-markdown-for-agents' ), $live_detail . '; ' . __( 'warning recorded', 'bloglogistics-markdown-for-agents' ) );
                } else {
                    echo self::status_markup( 'healthy', __( 'Healthy', 'bloglogistics-markdown-for-agents' ), $live_detail );
                }
            }
            echo '</td>';

            echo '<td>';
            if ( ! empty( $state['disabled'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'Discovery disabled by choice', 'bloglogistics-markdown-for-agents' ) );
            } elseif ( empty( $state['exists'] ) ) {
                echo self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'Not advertised without a companion', 'bloglogistics-markdown-for-agents' ) );
            } elseif ( ! empty( $live_item['discovery'] ) && empty( $live_item['discovery']['passed'] ) ) {
                echo self::status_markup( 'problem', __( 'Problem', 'bloglogistics-markdown-for-agents' ), __( 'Live discovery needs attention', 'bloglogistics-markdown-for-agents' ) );
            } else {
                $detail = ! empty( $live_item['discovery']['passed'] ) ? __( 'Enabled and live-verified', 'bloglogistics-markdown-for-agents' ) : __( 'Enabled', 'bloglogistics-markdown-for-agents' );
                echo self::status_markup( 'healthy', __( 'Healthy', 'bloglogistics-markdown-for-agents' ), $detail );
            }
            echo '<label class="bl-mfa-discovery-toggle"><input type="checkbox" name="disabled_ids[]" value="' . esc_attr( (string) $post_id ) . '" ' . checked( ! empty( $state['disabled'] ), true, false ) . '> ' . esc_html__( 'Disable discovery', 'bloglogistics-markdown-for-agents' ) . '</label>';
            echo '</td></tr>';
        }

        echo '</tbody></table></div>';
        submit_button( __( 'Save Discovery Choices', 'bloglogistics-markdown-for-agents' ) );
        echo '</form>';
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
        echo '<p>' . esc_html__( 'This tab validates your user-managed llms.txt file. The plugin does not create, rewrite, or curate the file.', 'bloglogistics-markdown-for-agents' ) . '</p>';

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
            echo '<tr><th scope="row">' . esc_html__( 'Live server-rule test', 'bloglogistics-markdown-for-agents' ) . '</th><td>' . self::status_markup( 'na', __( 'Not applicable', 'bloglogistics-markdown-for-agents' ), __( 'Waiting for a detected non-homepage Markdown companion', 'bloglogistics-markdown-for-agents' ) ) . '</td></tr>';
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
            .bl-mfa-wrap{max-width:1400px}.bl-mfa-tabs{margin-bottom:18px}.bl-mfa-health-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:12px 0 20px;max-width:1250px}.bl-mfa-health-card{display:block;box-sizing:border-box;background:#fff;border:1px solid #c3c4c7;border-left-width:4px;border-radius:4px;padding:14px 16px;min-height:116px;text-decoration:none;color:#1d2327}.bl-mfa-health-card:hover{color:#1d2327;box-shadow:0 1px 4px rgba(0,0,0,.12)}.bl-mfa-health-card strong{display:block;font-size:26px;line-height:1.15;margin:5px 0}.bl-mfa-card-label{display:block;font-weight:600}.bl-mfa-card-detail{display:block;color:#50575e;margin-top:4px}.bl-mfa-card-links{display:block;margin-top:8px}.bl-mfa-card-links a{font-weight:600}.bl-mfa-card-healthy{border-left-color:#00a32a;background:#edfaef}.bl-mfa-card-problem{border-left-color:#d63638;background:#fcf0f1}.bl-mfa-card-review{border-left-color:#dba617;background:#fcf9e8}.bl-mfa-card-na{border-left-color:#c3c4c7;background:#f0f0f1}.bl-mfa-status{display:inline-block;border:1px solid transparent;border-radius:999px;padding:2px 8px;font-weight:600;line-height:1.5}.bl-mfa-status-healthy{background:#edfaef;border-color:#00a32a;color:#006b1b}.bl-mfa-status-problem{background:#fcf0f1;border-color:#d63638;color:#8a2424}.bl-mfa-status-review{background:#fcf9e8;border-color:#dba617;color:#755c00}.bl-mfa-status-na{background:#f0f0f1;border-color:#c3c4c7;color:#50575e}.bl-mfa-health-table td,.bl-mfa-health-table th{vertical-align:top}.bl-mfa-health-detail{display:block;color:#646970;margin-top:4px;line-height:1.4}.bl-mfa-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0}.bl-mfa-actions form{margin:0}.bl-mfa-primary-actions{padding:10px 0}.bl-mfa-code-wrap{word-break:break-word}.bl-mfa-table-wrap{overflow-x:auto}.bl-mfa-filters{margin:8px 0 6px}.bl-mfa-discovery-toggle{display:block;margin-top:8px;color:#50575e}.bl-mfa-overview-meta{display:flex;gap:24px;flex-wrap:wrap;margin:6px 0 12px;color:#50575e}.bl-mfa-status-key{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:8px 0 14px}.bl-mfa-status-key strong{margin-right:2px}.bl-mfa-issue-list{list-style:disc;margin-left:2em}.bl-mfa-how-it-works{max-width:900px}.bl-mfa-health-table .row-actions{position:static}.bl-mfa-health-table code{font-size:12px}@media(max-width:782px){.bl-mfa-health-grid{grid-template-columns:1fr}.bl-mfa-overview-meta{display:block}.bl-mfa-overview-meta span{display:block;margin-bottom:5px}}
        </style>';

        echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Editorial control stays with you.', 'bloglogistics-markdown-for-agents' ) . '</strong> ';
        echo esc_html__( 'This plugin does not create, rewrite, or curate llms.txt or any Markdown file. It discovers, validates, and verifies the files you maintain.', 'bloglogistics-markdown-for-agents' );
        echo '</p></div>';

        if ( 'scanned' === $message ) {
            $checked   = isset( $_GET['checked'] ) ? absint( $_GET['checked'] ) : 0;
            $found     = isset( $_GET['found'] ) ? absint( $_GET['found'] ) : 0;
            $removed   = isset( $_GET['removed'] ) ? absint( $_GET['removed'] ) : 0;
            $validated = isset( $_GET['validated'] ) ? absint( $_GET['validated'] ) : 0;
            $reused    = isset( $_GET['reused'] ) ? absint( $_GET['reused'] ) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                esc_html__( 'Scan complete. Checked %1$d published posts/pages, detected %2$d companions, removed %3$d stale Markdown URL fields, validated %4$d changed files, and reused %5$d unchanged validation results.', 'bloglogistics-markdown-for-agents' ),
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
            $checked   = isset( $_GET['checked'] ) ? absint( $_GET['checked'] ) : 0;
            $passed    = isset( $_GET['passed'] ) ? absint( $_GET['passed'] ) : 0;
            $failed    = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
            $truncated = ! empty( $_GET['truncated'] );
            echo '<div class="notice ' . esc_attr( $failed ? 'notice-warning' : 'notice-success' ) . ' is-dismissible"><p>';
            printf(
                esc_html__( 'Live verification complete. Checked %1$d companions, %2$d passed and %3$d need attention.', 'bloglogistics-markdown-for-agents' ),
                $checked,
                $passed,
                $failed
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
                [
                    'page'                      => self::SETTINGS_SLUG,
                    'tab'                       => $context['tab'],
                    'view'                      => $context['view'],
                    'bloglogistics_mfa_message' => 'scanned',
                    'checked'                   => $result['checked'],
                    'found'                     => $result['found'],
                    'removed'                   => $result['removed'],
                    'validated'                 => $result['validated'],
                    'reused'                    => $result['reused'],
                    'force'                     => $force ? 1 : 0,
                ],
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

    public static function handle_live_verify(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to run live Markdown verification.', 'bloglogistics-markdown-for-agents' ) );
        }

        check_admin_referer( 'bloglogistics_mfa_live_verify' );
        $context = self::return_context();
        $result  = self::verify_live_endpoints();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                      => self::SETTINGS_SLUG,
                    'tab'                       => $context['tab'],
                    'view'                      => $context['view'],
                    'bloglogistics_mfa_message' => 'live_verified',
                    'checked'                   => (int) $result['checked'],
                    'passed'                    => (int) $result['passed'],
                    'failed'                    => (int) $result['failed'],
                    'truncated'                 => ! empty( $result['truncated'] ) ? 1 : 0,
                ],
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

        $result = self::verify_live_endpoints( [ $post_id ] );

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                      => self::SETTINGS_SLUG,
                    'tab'                       => $context['tab'],
                    'view'                      => $context['view'],
                    'bloglogistics_mfa_message' => 'live_verified',
                    'checked'                   => (int) $result['checked'],
                    'passed'                    => (int) $result['passed'],
                    'failed'                    => (int) $result['failed'],
                    'truncated'                 => 0,
                ],
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
            $live     = self::verify_live_endpoints( $selected_ids );
            $affected = (int) $live['checked'];
        }

        $message = in_array( $bulk_action, [ 'disable', 'enable', 'rescan', 'verify' ], true )
            ? 'bulk_done'
            : 'exclusions_saved';

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                      => self::SETTINGS_SLUG,
                    'tab'                       => $context['tab'],
                    'view'                      => $context['view'],
                    'bloglogistics_mfa_message' => $message,
                    'bulk'                      => $bulk_action,
                    'affected'                  => $affected,
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
