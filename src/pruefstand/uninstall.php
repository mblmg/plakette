<?php
/**
 * Removes everything Prüfstand stored when the plugin is deleted.
 * Content changes (alt texts, titles, descriptions) stay – they are part of the website now.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'pruefstand_modules', 'pruefstand_report', 'pruefstand_measure', 'pruefstand_psi', 'pruefstand_history', 'pruefstand_db_version', 'pruefstand_psi_key' ) as $option ) {
	delete_option( $option );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pruefstand_log" ); // phpcs:ignore WordPress.DB

// Remove the rule blocks Prüfstand added to .htaccess.
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
require_once ABSPATH . 'wp-admin/includes/misc.php';
$htaccess = get_home_path() . '.htaccess';
if ( file_exists( $htaccess ) && wp_is_writable( $htaccess ) ) {
	insert_with_markers( $htaccess, 'Pruefstand Kompression', array() );
	insert_with_markers( $htaccess, 'Pruefstand Browser-Cache', array() );
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
$remove( WP_CONTENT_DIR . '/cache/pruefstand' );
$remove( WP_CONTENT_DIR . '/pruefstand-backup' );
wp_delete_file( WP_CONTENT_DIR . '/pruefstand-gzip.flag' );
$uploads = wp_upload_dir( null, false );
$remove( $uploads['basedir'] . '/pruefstand-fonts' );
