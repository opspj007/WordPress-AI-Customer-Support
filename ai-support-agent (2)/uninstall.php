<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
foreach ( array( 'conversations', 'messages', 'leads', 'chunks', 'events' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aisa_{$t}" ); // phpcs:ignore
}
delete_option( 'aisa_settings' );
delete_option( 'aisa_db_version' );
delete_option( 'aisa_reindex_status' );
wp_clear_scheduled_hook( 'aisa_daily' );
