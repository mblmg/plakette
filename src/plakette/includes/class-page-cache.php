<?php
/**
 * Simple, conservative page cache.
 *
 * Pages for visitors who are not logged in are stored as static HTML in wp-content/cache/plakette/.
 * The drop-in wp-content/advanced-cache.php serves them before WordPress loads.
 * The whole cache is cleared whenever content, comments, menus, widgets or the theme change.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Page_Cache {

	const MARK = 'Plakette page cache drop-in';

	public static function cache_dir() {
		return WP_CONTENT_DIR . '/cache/plakette';
	}

	public static function dropin_path() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	public static function is_our_dropin() {
		$path = self::dropin_path();
		if ( ! file_exists( $path ) ) {
			return false;
		}
		$head = (string) @file_get_contents( $path, false, null, 0, 400 ); // phpcs:ignore -- the file can vanish between the checks (parallel requests).
		return false !== strpos( $head, self::MARK );
	}

	public static function enabled() {
		return Plakette_Modules::on( 'page_cache' ) && self::is_our_dropin() && defined( 'WP_CACHE' ) && WP_CACHE;
	}

	public static function boot() {
		if ( ! Plakette_Modules::on( 'page_cache' ) ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start_buffer' ), 999 );

		$purge = array(
			'save_post', 'deleted_post', 'trashed_post', 'edit_post', 'switch_theme', 'customize_save_after',
			'wp_update_nav_menu', 'update_option_sidebars_widgets', 'comment_post', 'edit_comment',
			'transition_comment_status', 'deleted_comment', 'activated_plugin', 'deactivated_plugin',
			'upgrader_process_complete', 'edited_term', 'delete_term', 'update_option_blogname',
			'update_option_blogdescription', 'update_option_plakette_modules', 'update_option_permalink_structure',
		);
		foreach ( $purge as $hook ) {
			add_action( $hook, array( __CLASS__, 'purge_all' ) );
		}
	}

	public static function maybe_start_buffer() {
		if ( ! defined( 'PLAKETTE_CACHE_FILE' ) || is_user_logged_in() || is_admin() ) {
			return;
		}
		if ( is_search() || is_404() || is_preview() || is_feed() || is_trackback() || is_robots() || post_password_required() ) {
			return;
		}
		if ( ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'store' ) );
	}

	public static function store( $html ) {
		if ( ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || 200 !== http_response_code() || strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Set-Cookie' ) || ( 0 === stripos( $header, 'Content-Type' ) && false === stripos( $header, 'text/html' ) ) ) {
				return $html;
			}
		}
		$file = PLAKETTE_CACHE_FILE;
		if ( wp_mkdir_p( dirname( $file ) ) ) {
			$stamp = "\n<!-- Plakette Cache: gespeichert " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->";
			$tmp   = $file . '.' . wp_rand() . '.tmp';
			if ( false !== file_put_contents( $tmp, $html . $stamp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				@rename( $tmp, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		return $html;
	}

	/** Empties our own cache and the caches of common cache plugins, so visitors see a change right away. */
	public static function purge_all() {
		$dir = self::cache_dir();
		if ( is_dir( $dir ) ) {
			Plakette_Log::rrmdir( $dir );
		}
		if ( doing_action( 'plakette_purge_others' ) ) {
			return;
		}
		if ( function_exists( 'wpo_cache_flush' ) ) {
			wpo_cache_flush();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		do_action( 'litespeed_purge_all' );
		do_action( 'plakette_purge_others' );
	}

	/** Installs the drop-in and WP_CACHE. Returns undo ops or WP_Error. */
	public static function enable() {
		$undo = array();
		if ( Plakette_Env::cache_plugin() ) {
			return new WP_Error( 'plakette_cache', sprintf( __( 'Es ist bereits %s aktiv. Zwei Caches gleichzeitig verursachen Fehler.', 'plakette' ), Plakette_Env::cache_plugin() ) );
		}
		if ( ! wp_is_writable( WP_CONTENT_DIR ) ) {
			return new WP_Error( 'plakette_cache', __( 'Der Ordner wp-content ist nicht beschreibbar.', 'plakette' ) );
		}
		if ( ! self::is_our_dropin() ) {
			$tpl = file_get_contents( PLAKETTE_DIR . 'dropin/advanced-cache.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === file_put_contents( self::dropin_path(), $tpl ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new WP_Error( 'plakette_cache', __( 'Die Cache-Datei konnte nicht angelegt werden.', 'plakette' ) );
			}
			$undo[] = array( 'file_delete', self::dropin_path() );
		}
		if ( ! ( defined( 'WP_CACHE' ) && WP_CACHE ) ) {
			$op = Plakette_Env::add_wpconfig_define( 'WP_CACHE' );
			if ( is_wp_error( $op ) ) {
				foreach ( $undo as $u ) {
					wp_delete_file( $u[1] );
				}
				return $op;
			}
			$undo[] = $op;
		}
		Plakette_Log::set_module( 'page_cache', true, $undo );
		$undo[] = array( 'callback', 'Plakette_Page_Cache::purge_all' );
		return $undo;
	}

	/** Plugin deactivation: never leave a drop-in behind that points to missing code. */
	public static function deactivate() {
		if ( self::is_our_dropin() ) {
			wp_delete_file( self::dropin_path() );
		}
		Plakette_Env::remove_wpconfig_define( 'WP_CACHE' );
		self::purge_all();
	}

	/** Called on activation again if the module is still switched on. */
	public static function reinstall_if_needed() {
		if ( Plakette_Modules::on( 'page_cache' ) && ! self::is_our_dropin() && ! Plakette_Env::cache_plugin() ) {
			$tpl = file_get_contents( PLAKETTE_DIR . 'dropin/advanced-cache.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( self::dropin_path(), $tpl ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! ( defined( 'WP_CACHE' ) && WP_CACHE ) ) {
				Plakette_Env::add_wpconfig_define( 'WP_CACHE' );
			}
		}
	}
}
