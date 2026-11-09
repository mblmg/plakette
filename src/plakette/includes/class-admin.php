<?php
/**
 * Admin screen: menu entry, assets and the empty container the JavaScript app renders into.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Admin {

	const SLUG = 'plakette';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLAKETTE_FILE ), array( __CLASS__, 'action_link' ) );
	}

	public static function menu() {
		// Small plakette as menu icon.
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm0 2.2a6.8 6.8 0 1 1 0 13.6 6.8 6.8 0 0 1 0-13.6zM8.7 13.4 5.6 10.3l1.3-1.3 1.8 1.8 4.4-4.4 1.3 1.3z"/></svg>' );
		add_menu_page(
			__( 'Plakette – Website-Hauptuntersuchung', 'plakette' ),
			__( 'Plakette', 'plakette' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			$icon,
			81
		);
	}

	public static function action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Website prüfen', 'plakette' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		$ver = PLAKETTE_VERSION . '-' . filemtime( PLAKETTE_DIR . 'admin/js/admin.js' );
		wp_enqueue_style( 'plakette-admin', PLAKETTE_URL . 'admin/css/admin.css', array(), $ver );
		wp_enqueue_script( 'plakette-admin', PLAKETTE_URL . 'admin/js/admin.js', array(), $ver, array( 'in_footer' => true ) );
		wp_localize_script(
			'plakette-admin',
			'PLAKETTE',
			array(
				'rest'    => esc_url_raw( rest_url( Plakette_Rest::NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'home'    => home_url( '/' ),
				'name'    => get_bloginfo( 'name' ),
				'state'   => Plakette_Rest::state(),
				'locale'  => str_replace( '_', '-', get_user_locale() ),
			)
		);
	}

	public static function render() {
		echo '<div class="wrap plk-wrap"><h1 class="screen-reader-text">' . esc_html__( 'Plakette – Website-Hauptuntersuchung', 'plakette' ) . '</h1>';
		echo '<div id="plakette-app" class="plk" aria-live="polite"><p class="plk-boot">' . esc_html__( 'Plakette wird geladen …', 'plakette' ) . '</p></div>';
		echo '<noscript><p>' . esc_html__( 'Plakette braucht JavaScript.', 'plakette' ) . '</p></noscript></div>';
	}
}
