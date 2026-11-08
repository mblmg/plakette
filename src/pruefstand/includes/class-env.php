<?php
/**
 * Facts about the environment: other plugins, web server, writable files.
 */

defined( 'ABSPATH' ) || exit;

class Pruefstand_Env {

	/** Name of an active SEO plugin that already writes meta tags, or ''. */
	public static function seo_plugin() {
		$known = array(
			'WPSEO_VERSION'              => 'Yoast SEO',
			'RANK_MATH_VERSION'          => 'Rank Math',
			'AIOSEO_VERSION'             => 'All in One SEO',
			'SEOPRESS_VERSION'           => 'SEOPress',
			'THE_SEO_FRAMEWORK_VERSION'  => 'The SEO Framework',
			'SQ_VERSION'                 => 'Squirrly SEO',
			'SLIM_SEO_VER'               => 'Slim SEO',
		);
		foreach ( $known as $const => $name ) {
			if ( defined( $const ) ) {
				return $name;
			}
		}
		return '';
	}

	/** Name of an active caching plugin, or ''. */
	public static function cache_plugin() {
		$known = array(
			'WP_ROCKET_VERSION'      => 'WP Rocket',
			'W3TC'                   => 'W3 Total Cache',
			'LSCWP_V'                => 'LiteSpeed Cache',
			'WPFC_WP_CONTENT_DIR'    => 'WP Fastest Cache',
			'WPCACHEHOME'            => 'WP Super Cache',
			'SG_OPTIMIZER_VERSION'   => 'SiteGround Optimizer',
			'CACHE_ENABLER_VERSION'  => 'Cache Enabler',
			'BREEZE_VERSION'         => 'Breeze',
			'WPHB_VERSION'           => 'Hummingbird',
			'COMET_CACHE_VERSION'    => 'Comet Cache',
			'FLYING_PRESS_VERSION'   => 'FlyingPress',
			'NITROPACK_VERSION'      => 'NitroPack',
		);
		foreach ( $known as $const => $name ) {
			if ( defined( $const ) ) {
				return $name;
			}
		}
		$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( file_exists( $dropin ) && ! Pruefstand_Page_Cache::is_our_dropin() ) {
			return __( 'ein anderes Cache-Plugin', 'pruefstand' );
		}
		return '';
	}

	/** Optimisation plugins that already defer scripts / remove emojis etc. */
	public static function optimize_plugin() {
		$known = array(
			'AUTOPTIMIZE_PLUGIN_VERSION' => 'Autoptimize',
			'WP_ROCKET_VERSION'          => 'WP Rocket',
			'LSCWP_V'                    => 'LiteSpeed Cache',
			'PERFMATTERS_VERSION'        => 'Perfmatters',
			'FLYING_PRESS_VERSION'       => 'FlyingPress',
		);
		foreach ( $known as $const => $name ) {
			if ( defined( $const ) ) {
				return $name;
			}
		}
		return '';
	}

	public static function is_apache() {
		global $is_apache;
		return ! empty( $is_apache );
	}

	public static function server_name() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		return $software ? $software : __( 'unbekannt', 'pruefstand' );
	}

	public static function htaccess_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	public static function htaccess_writable() {
		$path = self::htaccess_path();
		return self::is_apache() && ( file_exists( $path ) ? wp_is_writable( $path ) : wp_is_writable( dirname( $path ) ) );
	}

	/** Adds rules inside our own marker block; returns undo op or WP_Error. */
	public static function write_htaccess_block( $marker, array $lines ) {
		if ( ! self::htaccess_writable() ) {
			return new WP_Error( 'pruefstand_htaccess', __( 'Die Datei .htaccess ist nicht beschreibbar.', 'pruefstand' ) );
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$path = self::htaccess_path();
		$ok   = insert_with_markers( $path, $marker, $lines );
		if ( ! $ok ) {
			return new WP_Error( 'pruefstand_htaccess', __( 'Die Datei .htaccess konnte nicht geschrieben werden.', 'pruefstand' ) );
		}
		return array( 'markers', $path, $marker );
	}

	public static function wpconfig_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		if ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return dirname( ABSPATH ) . '/wp-config.php';
		}
		return '';
	}

	/**
	 * Adds `define( 'NAME', true ); // Prüfstand` right after the opening PHP tag.
	 * Only touches the file when the constant is not defined anywhere in it.
	 */
	public static function add_wpconfig_define( $name ) {
		$path = self::wpconfig_path();
		if ( ! $path || ! wp_is_writable( $path ) ) {
			return new WP_Error( 'pruefstand_wpconfig', __( 'Die Datei wp-config.php ist nicht beschreibbar.', 'pruefstand' ) );
		}
		$src = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $src ) {
			return new WP_Error( 'pruefstand_wpconfig', __( 'Die Datei wp-config.php konnte nicht gelesen werden.', 'pruefstand' ) );
		}
		if ( preg_match( "/define\s*\(\s*['\"]" . preg_quote( $name, '/' ) . "['\"]/", $src ) ) {
			return new WP_Error( 'pruefstand_wpconfig', sprintf( __( '%s ist in wp-config.php bereits gesetzt (auf false). Bitte dort auf true ändern.', 'pruefstand' ), $name ) );
		}
		$line = "define( '{$name}', true ); // Added by Pruefstand";
		$new  = preg_replace( '/^<\?php\s*/', "<?php\n{$line}\n", $src, 1, $count );
		if ( ! $count ) {
			return new WP_Error( 'pruefstand_wpconfig', __( 'wp-config.php hat ein unerwartetes Format.', 'pruefstand' ) );
		}
		if ( false === file_put_contents( $path, $new, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'pruefstand_wpconfig', __( 'Die Datei wp-config.php konnte nicht geschrieben werden.', 'pruefstand' ) );
		}
		return array( 'wpconfig_define', $name );
	}

	public static function remove_wpconfig_define( $name ) {
		$path = self::wpconfig_path();
		if ( ! $path || ! wp_is_writable( $path ) ) {
			return new WP_Error( 'pruefstand_wpconfig', __( 'Die Datei wp-config.php ist nicht beschreibbar.', 'pruefstand' ) );
		}
		$src = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$new = preg_replace( "/^define\( '" . preg_quote( $name, '/' ) . "', true \); \/\/ Added by Pruefstand\R/m", '', (string) $src );
		if ( $new !== $src ) {
			file_put_contents( $path, $new, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return true;
	}

	/** Private folder for backups, not reachable over the web. */
	public static function backup_dir() {
		$dir = WP_CONTENT_DIR . '/pruefstand-backup';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/** Data that goes into the prepared message for the hosting provider. */
	public static function host_facts() {
		global $wp_version;
		return array(
			'site'   => home_url( '/' ),
			'php'    => PHP_VERSION,
			'wp'     => $wp_version,
			'server' => self::server_name(),
		);
	}
}
