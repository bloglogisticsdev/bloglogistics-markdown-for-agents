<?php
/**
 * Uninstall cleanup for BlogLogistics Markdown for Agents.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'bloglogistics_mfa_settings' );
delete_option( 'bloglogistics_mfa_version' );
