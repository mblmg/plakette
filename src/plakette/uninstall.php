<?php
/**
 * Removes everything Plakette stored when the plugin is deleted.
 * Content changes (alt texts, titles, descriptions) stay – they are part of the website now.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Every option and transient of the plugin starts with "plakette_" (company data in plakette_settings is removed too).
$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'plakette\\_%' OR option_name LIKE '\\_transient\\_plakette\\_%' OR option_name LIKE '\\_transient\\_timeout\\_plakette\\_%' OR option_name LIKE '\\_site\\_transient\\_%plakette\\_%'" ); // phpcs:ignore WordPress.DB
foreach ( (array) $names as $option ) {
	delete_option( $option );
}
delete_site_transient( 'plakette_release' );
wp_clear_scheduled_hook( 'plakette_daily' );
wp_clear_scheduled_hook( 'plakette_vendor_warm' );
foreach ( array( 'plakette_vulns_cron', 'plakette_vulns_next', 'plakette_weekly_audit' ) as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
delete_metadata( 'user', 0, 'plakette_vulns_dismissed', '', true );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}plakette_log" ); // phpcs:ignore WordPress.DB

// Remove the rule blocks Plakette added to .htaccess.
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
require_once ABSPATH . 'wp-admin/includes/misc.php';
$htaccess = get_home_path() . '.htaccess';
if ( file_exists( $htaccess ) && wp_is_writable( $htaccess ) ) {
	$content = (string) file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( preg_match_all( '/^# BEGIN (Plakette[^\r\n]*)/m', $content, $m ) ) {
		foreach ( array_unique( $m[1] ) as $marker ) {
			insert_with_markers( $htaccess, trim( $marker ), array() );
		}
	}
}

$remove = function ( $dir ) use ( &$remove ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		is_dir( "$dir/$item" ) ? $remove( "$dir/$item" ) : wp_delete_file( "$dir/$item" );
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
};
$remove( WP_CONTENT_DIR . '/cache/plakette' );
$remove( WP_CONTENT_DIR . '/plakette-backup' );
wp_delete_file( WP_CONTENT_DIR . '/plakette-gzip.flag' );
wp_delete_file( WP_CONTENT_DIR . '/plakette-headers.json' );
$uploads = wp_upload_dir( null, false );
foreach ( array( 'plakette-fonts', 'plakette-vendor', 'plakette-avatars' ) as $folder ) {
	$remove( $uploads['basedir'] . '/' . $folder );
}
