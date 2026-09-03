<?php
/**
 * Uninstall cleanup for BlogLogistics Markdown for Agents.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'bloglogistics_mfa_settings' );
delete_option( 'bloglogistics_mfa_version' );
delete_option( 'bloglogistics_mfa_llms_detected' );
delete_option( 'bloglogistics_mfa_last_scan' );

delete_post_meta_by_key( 'bloglogistics_markdown_url' );
delete_post_meta_by_key( 'bloglogistics_markdown_disabled' );
