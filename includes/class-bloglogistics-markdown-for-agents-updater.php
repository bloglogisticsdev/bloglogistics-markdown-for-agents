<?php
/**
 * Manifest updater for BlogLogistics Markdown for Agents.
 *
 * @package BlogLogistics_Markdown_For_Agents
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! class_exists( 'BlogLogistics_Markdown_For_Agents_Updater', false ) ) {

    final class BlogLogistics_Markdown_For_Agents_Updater {

        /**
         * Initialise manifest-based plugin updates.
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

            PucFactory::buildUpdateChecker(
                $args['repo_url'],
                $args['plugin_file'],
                $args['slug']
            );
        }
    }
}