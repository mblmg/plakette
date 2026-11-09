<?php
/**
 * Vulnerability fixtures for the e2e test (runs inside tests/seed.php, WordPress loaded, current user = 1).
 * Header-only fake plugins with the slug and version of really vulnerable releases (WPVulnerability lists them):
 * - contact-form-7 5.3.1   (CVE-2020-35489 …, fixed later → "update"; the download is blocked by
 *                           tests/mu/plk-vulns-fixture.php, so the update must fail gracefully)
 * - wp-gdpr-compliance 2.0.23 (CVE-2024-33682, unfixed + closed on wordpress.org → "deactivate")
 * Both are active so security_inactive does not delete them before our fix runs.
 */
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plk_fakes = array(
	'contact-form-7/wp-contact-form-7.php'         => array( 'Contact Form 7', '5.3.1' ),
	'wp-gdpr-compliance/wp-gdpr-compliance.php' => array( 'WP GDPR Compliance', '2.0.23' ),
);
foreach ( $plk_fakes as $plk_file => $plk_meta ) {
	wp_mkdir_p( WP_PLUGIN_DIR . '/' . dirname( $plk_file ) );
	file_put_contents( WP_PLUGIN_DIR . '/' . $plk_file, "<?php\n/**\n * Plugin Name: {$plk_meta[0]}\n * Version: {$plk_meta[1]}\n * Description: Plakette test fixture (header only).\n */\n" );
}
wp_clean_plugins_cache();
foreach ( array_keys( $plk_fakes ) as $plk_file ) {
	$plk_r = activate_plugin( $plk_file );
	echo 'vulns: ' . $plk_file . ' ' . ( is_wp_error( $plk_r ) ? 'ERROR ' . $plk_r->get_error_message() : 'active' ) . "\n";
}
update_option( 'plk_vulns_block_download', 1 );
delete_site_transient( 'update_plugins' );
