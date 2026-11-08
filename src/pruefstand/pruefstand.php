<?php
/**
 * Plugin Name:       Prüfstand – SEO & Ladezeit
 * Description:       Prüft deine Website auf SEO-Mängel und misst die Ladezeit. Jeden gefundenen Mangel kannst du mit einem Klick beheben – mit einer kurzen Erklärung für Einsteiger und jederzeit rückgängig.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Prüfstand
 * License:           GPL-2.0-or-later
 * Text Domain:       pruefstand
 */

defined( 'ABSPATH' ) || exit;

define( 'PRUEFSTAND_VERSION', '1.0.0' );
define( 'PRUEFSTAND_FILE', __FILE__ );
define( 'PRUEFSTAND_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRUEFSTAND_URL', plugin_dir_url( __FILE__ ) );

require_once PRUEFSTAND_DIR . 'includes/class-log.php';
require_once PRUEFSTAND_DIR . 'includes/class-env.php';
require_once PRUEFSTAND_DIR . 'includes/class-modules.php';
require_once PRUEFSTAND_DIR . 'includes/class-page-cache.php';
require_once PRUEFSTAND_DIR . 'includes/class-crawler.php';
require_once PRUEFSTAND_DIR . 'includes/class-check.php';
require_once PRUEFSTAND_DIR . 'includes/checks-seo.php';
require_once PRUEFSTAND_DIR . 'includes/checks-content.php';
require_once PRUEFSTAND_DIR . 'includes/checks-speed.php';
require_once PRUEFSTAND_DIR . 'includes/class-auditor.php';
require_once PRUEFSTAND_DIR . 'includes/class-rest.php';
require_once PRUEFSTAND_DIR . 'includes/class-admin.php';

register_activation_hook(
	__FILE__,
	function () {
		Pruefstand_Log::install();
		Pruefstand_Page_Cache::reinstall_if_needed();
	}
);
register_deactivation_hook( __FILE__, array( 'Pruefstand_Page_Cache', 'deactivate' ) );

// Measurement mode: the admin screen loads the home page in a frame and measures it.
// The page is shown exactly as a visitor who is not logged in sees it (no admin bar, no editor tools).
// phpcs:ignore WordPress.Security.NonceVerification -- only reduces privileges for this one request.
if ( isset( $_GET['pruefstand_measure'] ) && ! is_admin() ) {
	add_filter( 'determine_current_user', '__return_zero', 999 );
	add_filter( 'show_admin_bar', '__return_false', 999 );
}

add_action( 'plugins_loaded', function () {
	Pruefstand_Log::maybe_upgrade();
	Pruefstand_Modules::boot();
	Pruefstand_Page_Cache::boot();
	Pruefstand_Rest::boot();
	if ( is_admin() ) {
		Pruefstand_Admin::boot();
	}
} );
