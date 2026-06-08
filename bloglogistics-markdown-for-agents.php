<?php
/**
 * Plugin Name:       BlogLogistics Markdown for Agents
 * Plugin URI:        https://github.com/bloglogisticsdev/bloglogistics-markdown-for-agents
 * Description:       Adds Markdown content negotiation for AI agents and serves a machine-readable homepage at /index.md using the current WordPress site's URLs and metadata.
 * Version:           1.3.1
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            BlogLogistics
 * Author URI:        https://www.bloglogistics.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:        https://github.com/bloglogisticsdev/bloglogistics-markdown-for-agents
 * Text Domain:       bloglogistics-markdown-for-agents
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BLOGLOGISTICS_MFA_VERSION', '1.3.1' );
define( 'BLOGLOGISTICS_MFA_SLUG', 'bloglogistics-markdown-for-agents' );
define( 'BLOGLOGISTICS_MFA_FILE', __FILE__ );
define( 'BLOGLOGISTICS_MFA_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLOGLOGISTICS_MFA_REPO_URL', 'https://github.com/bloglogisticsdev/bloglogistics-markdown-for-agents/' );
define( 'BLOGLOGISTICS_MFA_UPDATE_MANIFEST_URL', 'https://updates.bloglogistics.com/plugins/bloglogistics-markdown-for-agents.json' );

define( 'BLOGLOGISTICS_MFA_SETTINGS_OPTION', 'bloglogistics_mfa_settings' );
define( 'BLOGLOGISTICS_MFA_VERSION_OPTION', 'bloglogistics_mfa_version' );

$bloglogistics_mfa_puc = BLOGLOGISTICS_MFA_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

if ( file_exists( $bloglogistics_mfa_puc ) ) {
    if ( ! class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class, false ) ) {
        require_once $bloglogistics_mfa_puc;
    }

    require_once BLOGLOGISTICS_MFA_DIR . 'includes/class-bloglogistics-markdown-for-agents-updater.php';

    if ( class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class, false ) && class_exists( 'BlogLogistics_Markdown_For_Agents_Updater', false ) ) {
        BlogLogistics_Markdown_For_Agents_Updater::init( [
            'repo_url'    => BLOGLOGISTICS_MFA_UPDATE_MANIFEST_URL,
            'plugin_file' => BLOGLOGISTICS_MFA_FILE,
            'slug'        => BLOGLOGISTICS_MFA_SLUG,
        ] );
    }
}

require_once BLOGLOGISTICS_MFA_DIR . 'includes/class-bloglogistics-markdown-for-agents.php';

register_activation_hook( __FILE__, [ 'BL_Markdown_For_Agents', 'activate' ] );
BL_Markdown_For_Agents::init();
