<?php
/**
 * Plugin Name:       Plakette – Website-Hauptuntersuchung
 * Plugin URI:        https://github.com/mblmg/plakette
 * Description:       Die Hauptuntersuchung für deine WordPress-Website: prüft Datenschutz, Sicherheit, Geschwindigkeit und SEO, erklärt jeden Mangel in einfachen Worten und behebt ihn auf Knopfdruck – jederzeit rückgängig. Aktualisiert sich automatisch aus GitHub.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            i-make.media | Magnus Brühl
 * Author URI:        https://i-make.media
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plakette
 * Update URI:        https://github.com/mblmg/plakette
 */

defined( 'ABSPATH' ) || exit;

define( 'PLAKETTE_VERSION', '1.0.0' );
define( 'PLAKETTE_SLUG', 'plakette' );
define( 'PLAKETTE_REPO', 'mblmg/plakette' );
define( 'PLAKETTE_FILE', __FILE__ );
define( 'PLAKETTE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLAKETTE_URL', plugin_dir_url( __FILE__ ) );

// Core.
foreach ( array( 'class-log', 'class-env', 'class-settings', 'class-services', 'class-html', 'class-modules', 'class-page-cache', 'class-crawler', 'class-check', 'class-auditor', 'class-rest', 'class-updater', 'class-admin' ) as $plakette_file ) {
	require_once PLAKETTE_DIR . 'includes/' . $plakette_file . '.php';
}
// Features (runtime parts that fixes switch on) and checks. Each file registers itself through hooks.
foreach ( array_merge( (array) glob( PLAKETTE_DIR . 'includes/modules/*.php' ), (array) glob( PLAKETTE_DIR . 'includes/checks-*.php' ) ) as $plakette_file ) {
	require_once $plakette_file;
}
unset( $plakette_file );

register_activation_hook(
	__FILE__,
	function () {
		Plakette_Log::install();
		Plakette_Page_Cache::reinstall_if_needed();
		if ( ! wp_next_scheduled( 'plakette_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'plakette_daily' );
		}
		do_action( 'plakette_activate' );
	}
);
register_deactivation_hook(
	__FILE__,
	function () {
		Plakette_Page_Cache::deactivate();
		wp_clear_scheduled_hook( 'plakette_daily' );
		do_action( 'plakette_deactivate' );
		Plakette_Page_Cache::purge_all();
	}
);

// Measurement mode: the admin screen loads the home page in a frame and measures it.
// The page is shown exactly as a visitor who is not logged in sees it (no admin bar, no editor tools).
// phpcs:ignore WordPress.Security.NonceVerification -- only reduces privileges for this one request.
if ( isset( $_GET['plakette_measure'] ) && ! is_admin() ) {
	add_filter( 'determine_current_user', '__return_zero', 999 );
	add_filter( 'show_admin_bar', '__return_false', 999 );
}

add_action(
	'plugins_loaded',
	function () {
		Plakette_Log::maybe_upgrade();
		Plakette_Modules::boot();
		Plakette_Page_Cache::boot();
		Plakette_Rest::boot();
		Plakette_Updater::boot();
		/**
		 * Features hook in here: they check Plakette_Modules::on( 'their_key' ) and add their filters.
		 */
		do_action( 'plakette_boot' );
		if ( is_admin() ) {
			Plakette_Admin::boot();
		}
	}
);

// Daily maintenance (cleanup of local copies etc.). Scheduled again if activation ran without the hook (e.g. copied via FTP).
add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'plakette_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'plakette_daily' );
		}
	}
);
