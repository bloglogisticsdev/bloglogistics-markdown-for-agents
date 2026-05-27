<?php
/**
 * Standard GitHub updater for BlogLogistics plugins.
 *
 * @package BlogLogistics_Markdown_For_Agents
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class BlogLogistics_GitHub_Plugin_Updater {

    /**
     * Initialise GitHub-based plugin updates.
     *
     * @param array<string, string> $args Updater arguments.
     */
    public static function init( array $args ): void {
        if (
            empty( $args['repo_url'] ) ||
            empty( $args['plugin_file'] ) ||
            empty( $args['slug'] )
        ) {
            return;
        }

        if ( ! class_exists( PucFactory::class ) ) {
            return;
        }

        $update_checker = PucFactory::buildUpdateChecker(
            $args['repo_url'],
            $args['plugin_file'],
            $args['slug']
        );

        $update_checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );
    }
}