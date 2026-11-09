<?php
/**
 * Module "local_fonts": Google Fonts are downloaded once and served from the own domain.
 *
 * Works for every stylesheet WordPress prints (style_loader_src) and for page builders that print their own
 * font link (Breakdance: breakdance_google_fonts_url, Oxygen: oxygen_google_fonts_url).
 * Only the subsets latin + latin-ext are kept, every file is checked (woff2 magic bytes), font-display: swap is added.
 * A new font or new weights create a new Google URL and with it a new local set automatically.
 * Sets nobody requested for 30 days are removed by the daily cron (plakette_daily).
 *
 * Storage: uploads/plakette-fonts/<key>/fonts.css + <family>-<subset>-<style>-<weight>.woff2
 * Data:    option plakette_fonts = [ fonts => key => info, fail => key => time, active => key => time ]
 * Module value: true.
 *
 * Ported from the "i-make.media Toolkit for Breakdance" (fonts.php).
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Local_Fonts {

	const DIR_NAME    = 'plakette-fonts';
	const OPTION      = 'plakette_fonts';
	const UA          = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
	const MAX_PRELOAD = 2;
	const MAX_AGE     = 2592000; // 30 days.
	const RETRY_AFTER = 3600;    // 1 hour after a failed download.

	/** Subsets that are kept (everything else – cyrillic, greek, vietnamese … – is dropped). */
	public static function subsets() {
		return (array) apply_filters( 'plakette_fonts_subsets', array( 'latin', 'latin-ext' ) );
	}

	public static function boot() {
		add_action( 'plakette_daily', array( __CLASS__, 'cleanup' ) );
		if ( ! Plakette_Modules::on( 'local_fonts' ) ) {
			return;
		}
		// The old "i-make.media Toolkit for Breakdance" still localises the fonts – do not do it twice.
		if ( class_exists( 'Plakette_Legacy' ) && Plakette_Legacy::handles( 'local_fonts' ) ) {
			return;
		}
		add_filter( 'breakdance_google_fonts_url', array( __CLASS__, 'localize' ), 99 );
		add_filter( 'oxygen_google_fonts_url', array( __CLASS__, 'localize' ), 99 );
		add_filter( 'style_loader_src', array( __CLASS__, 'localize' ), 99 );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'strip_hints' ), 99, 2 );
		add_action( 'wp_head', array( __CLASS__, 'print_preloads' ), 1 );
	}

	/** @return array [ path, url ] of the storage folder. */
	public static function storage() {
		$u = wp_get_upload_dir();
		return array( trailingslashit( $u['basedir'] ) . self::DIR_NAME, trailingslashit( $u['baseurl'] ) . self::DIR_NAME );
	}

	public static function is_google_css( $url ) {
		return is_string( $url ) && (bool) preg_match( '#^(https?:)?//fonts\.googleapis\.com/css2?\?#i', html_entity_decode( $url ) );
	}

	/** Normalised source URL (https, entities decoded). */
	public static function source( $url ) {
		$src = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
		return 0 === strpos( $src, '//' ) ? 'https:' . $src : $src;
	}

	public static function key( $url ) {
		return substr( md5( self::source( $url ) ), 0, 12 );
	}

	public static function data() {
		$empty = array( 'fonts' => array(), 'fail' => array(), 'active' => array() );
		$d     = get_option( self::OPTION, array() );
		return is_array( $d ) ? $d + $empty : $empty;
	}

	public static function save( array $d ) {
		update_option( self::OPTION, $d, false );
	}

	/** Local stylesheet URL when the set exists, else ''. */
	public static function local_url( $url ) {
		$key                = self::key( $url );
		list( $dir, $base ) = self::storage();
		$d                  = self::data();
		if ( isset( $d['fonts'][ $key ] ) && is_readable( $dir . '/' . $key . '/fonts.css' ) ) {
			return $base . '/' . $key . '/fonts.css?ver=' . (int) $d['fonts'][ $key ]['time'];
		}
		return '';
	}

	/**
	 * Filter callback: local URL for a Google Fonts stylesheet URL. The first call downloads the set.
	 * On errors the original URL stays and the next try follows after one hour.
	 *
	 * @param mixed $url
	 * @return mixed
	 */
	public static function localize( $url ) {
		if ( ! self::is_google_css( $url ) ) {
			return $url;
		}
		$local = self::fetch( $url, false );
		return is_wp_error( $local ) ? $url : $local;
	}

	/**
	 * Makes sure the set for $url exists. $force ignores the one-hour back-off (used by the fix).
	 *
	 * @return string|WP_Error local URL
	 */
	public static function fetch( $url, $force = false ) {
		$src   = self::source( $url );
		$key   = self::key( $src );
		$local = self::local_url( $src );
		if ( '' !== $local ) {
			self::remember( $key );
			return $local;
		}
		$d = self::data();
		if ( ! $force && ! empty( $d['fail'][ $key ] ) && time() - (int) $d['fail'][ $key ] < self::RETRY_AFTER ) {
			return new WP_Error( 'plakette_fonts_wait', __( 'Der letzte Download ist fehlgeschlagen – neuer Versuch in spätestens einer Stunde.', 'plakette' ) );
		}
		$lock = 'plakette_fonts_lock_' . $key;
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'plakette_fonts_busy', __( 'Die Schriften werden gerade heruntergeladen.', 'plakette' ) );
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );
		$result = self::download( $src, $key );
		delete_transient( $lock );

		$d = self::data();
		if ( is_wp_error( $result ) ) {
			$d['fail'][ $key ] = time();
			self::save( $d );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Plakette (Schriften): ' . $result->get_error_message() . ' (' . $src . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
			return $result;
		}
		$d['fonts'][ $key ] = $result;
		unset( $d['fail'][ $key ] );
		self::save( $d );
		self::remember( $key );
		// Cached pages still point to Google – rebuild them.
		Plakette_Page_Cache::purge_all();
		return self::local_url( $src );
	}

	/** Downloads stylesheet + woff2 files. @return array|WP_Error */
	public static function download( $src, $key ) {
		$res = wp_remote_get( $src, array( 'timeout' => 10, 'user-agent' => self::UA ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'plakette_fonts_http', sprintf( __( 'Google Fonts antwortet mit HTTP %d.', 'plakette' ), (int) wp_remote_retrieve_response_code( $res ) ) );
		}
		$css = (string) wp_remote_retrieve_body( $res );
		preg_match_all( '#(?:/\*\s*([a-z0-9-]+)\s*\*/\s*)?@font-face\s*\{([^}]+)\}#i', $css, $m, PREG_SET_ORDER );
		if ( ! $m ) {
			return new WP_Error( 'plakette_fonts_parse', __( 'In der Antwort von Google stehen keine Schriften.', 'plakette' ) );
		}
		list( $dir ) = self::storage();
		$target      = $dir . '/' . $key;
		if ( ! wp_mkdir_p( $target ) ) {
			return new WP_Error( 'plakette_fonts_fs', __( 'Der Upload-Ordner ist nicht beschreibbar.', 'plakette' ) );
		}

		$out      = array();
		$files    = array();
		$preload  = array();
		$families = array();
		foreach ( $m as $block ) {
			$subset = strtolower( isset( $block[1] ) ? $block[1] : '' );
			if ( '' !== $subset && ! in_array( $subset, self::subsets(), true ) ) {
				continue;
			}
			$body = $block[2];
			if ( ! preg_match( '#url\((https://fonts\.gstatic\.com/[^)\s]+\.woff2)\)#i', $body, $u ) ) {
				continue;
			}
			$family = preg_match( '#font-family:\s*[\'"]?([^;\'"]+)#i', $body, $f ) ? trim( $f[1] ) : 'font';
			$style  = preg_match( '#font-style:\s*(\w+)#i', $body, $s ) ? $s[1] : 'normal';
			$weight = preg_match( '#font-weight:\s*([\d ]+)#i', $body, $w ) ? trim( preg_replace( '#\s+#', '-', trim( $w[1] ) ) ) : '400';
			$name   = sanitize_file_name( strtolower( trim( preg_replace( '#[^a-z0-9]+#i', '-', $family ), '-' ) ) . '-' . ( '' !== $subset ? $subset : 'all' ) . '-' . strtolower( $style ) . '-' . $weight . '.woff2' );

			if ( ! isset( $files[ $name ] ) ) {
				$fr = wp_remote_get( $u[1], array( 'timeout' => 15, 'user-agent' => self::UA ) );
				if ( is_wp_error( $fr ) || 200 !== (int) wp_remote_retrieve_response_code( $fr ) ) {
					Plakette_Log::rrmdir( $target );
					return new WP_Error( 'plakette_fonts_file', sprintf( __( 'Schriftdatei nicht ladbar: %s', 'plakette' ), $u[1] ) );
				}
				$bin = (string) wp_remote_retrieve_body( $fr );
				if ( 'wOF2' !== substr( $bin, 0, 4 ) ) {
					Plakette_Log::rrmdir( $target );
					return new WP_Error( 'plakette_fonts_file', sprintf( __( 'Keine gültige woff2-Datei: %s', 'plakette' ), $u[1] ) );
				}
				if ( false === file_put_contents( $target . '/' . $name, $bin ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					Plakette_Log::rrmdir( $target );
					return new WP_Error( 'plakette_fonts_fs', __( 'Schriftdatei nicht schreibbar.', 'plakette' ) );
				}
				$files[ $name ] = strlen( $bin );
			}
			$body = str_replace( $u[1], $name, $body );
			if ( ! preg_match( '#font-display#i', $body ) ) {
				$body .= "\n  font-display: swap;\n";
			}
			$out[]               = ( '' !== $subset ? "/* $subset */\n" : '' ) . '@font-face {' . $body . '}';
			$families[ $family ] = true;
			if ( 'latin' === $subset && 'normal' === strtolower( $style ) ) {
				$preload[] = $name;
			}
		}
		if ( ! $out ) {
			Plakette_Log::rrmdir( $target );
			return new WP_Error( 'plakette_fonts_empty', sprintf( __( 'Keine passenden Zeichensätze (%s) gefunden.', 'plakette' ), implode( ', ', self::subsets() ) ) );
		}
		$header = '/* Lokale Kopie von ' . str_replace( '*/', '', $src ) . "\n   Erzeugt von Plakette am " . gmdate( 'Y-m-d H:i' ) . " UTC */\n";
		$tmp    = $target . '/.fonts.css.tmp';
		if ( false === file_put_contents( $tmp, $header . implode( "\n", $out ) . "\n" ) || ! rename( $tmp, $target . '/fonts.css' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			Plakette_Log::rrmdir( $target );
			return new WP_Error( 'plakette_fonts_fs', __( 'Stylesheet nicht schreibbar.', 'plakette' ) );
		}
		return array(
			'src'      => $src,
			'families' => array_keys( $families ),
			'files'    => $files,
			'preload'  => array_values( array_unique( $preload ) ),
			'time'     => time(),
			'used'     => time(),
		);
	}

	/** Remembers that a set is in use (for preload and cleanup). Writes at most once a day per set. */
	public static function remember( $key ) {
		static $done = array();
		if ( isset( $done[ $key ] ) ) {
			return;
		}
		$done[ $key ] = true;
		$d            = self::data();
		$last         = isset( $d['active'][ $key ] ) ? (int) $d['active'][ $key ] : 0;
		if ( time() - $last > DAY_IN_SECONDS ) {
			$d['active'][ $key ] = time();
			$d['active']         = array_filter(
				$d['active'],
				function ( $t ) {
					return time() - (int) $t < 14 * DAY_IN_SECONDS;
				}
			);
			if ( isset( $d['fonts'][ $key ] ) ) {
				$d['fonts'][ $key ]['used'] = time();
			}
			self::save( $d );
		}
	}

	/** Preloads the latin files of the most recently used sets (max. 2 files). */
	public static function print_preloads() {
		if ( ! apply_filters( 'plakette_fonts_preload', true ) ) {
			return;
		}
		$d                  = self::data();
		list( $dir, $base ) = self::storage();
		arsort( $d['active'] );
		$max = (int) apply_filters( 'plakette_fonts_max_preload', self::MAX_PRELOAD );
		$n   = 0;
		foreach ( array_keys( $d['active'] ) as $key ) {
			$list = isset( $d['fonts'][ $key ]['preload'] ) ? (array) $d['fonts'][ $key ]['preload'] : array();
			foreach ( $list as $file ) {
				if ( $n >= $max || ! is_readable( $dir . '/' . $key . '/' . $file ) ) {
					continue;
				}
				printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( $base . '/' . $key . '/' . $file ) );
				++$n;
			}
		}
	}

	/** Removes preconnect/dns-prefetch hints to the Google font servers (no longer needed). */
	public static function strip_hints( $urls, $relation ) {
		if ( ! in_array( $relation, array( 'preconnect', 'dns-prefetch' ), true ) || ! is_array( $urls ) ) {
			return $urls;
		}
		return array_values(
			array_filter(
				$urls,
				function ( $u ) {
					$href = is_array( $u ) ? ( isset( $u['href'] ) ? $u['href'] : '' ) : (string) $u;
					return ! preg_match( '#fonts\.(googleapis|gstatic)\.com#i', $href );
				}
			)
		);
	}

	/** Removes sets nobody requested for 30 days. @return int number of removed sets */
	public static function cleanup() {
		$d = self::data();
		if ( ! $d['fonts'] && ! $d['fail'] ) {
			return 0;
		}
		list( $dir ) = self::storage();
		$n           = 0;
		foreach ( $d['fonts'] as $key => $f ) {
			$used = isset( $f['used'] ) ? (int) $f['used'] : ( isset( $f['time'] ) ? (int) $f['time'] : 0 );
			if ( time() - $used > self::MAX_AGE ) {
				Plakette_Log::rrmdir( $dir . '/' . $key );
				unset( $d['fonts'][ $key ], $d['active'][ $key ] );
				++$n;
			}
		}
		foreach ( $d['fail'] as $key => $t ) {
			if ( time() - (int) $t > DAY_IN_SECONDS ) {
				unset( $d['fail'][ $key ] );
			}
		}
		self::save( $d );
		return $n;
	}

	/** Deletes every local copy (undo of the fix). */
	public static function clear_all() {
		list( $dir ) = self::storage();
		Plakette_Log::rrmdir( $dir );
		delete_option( self::OPTION );
	}

	/** Summary for the settings panel: HTML list of the local sets. */
	public static function info_html() {
		$d = self::data();
		if ( ! $d['fonts'] ) {
			return '<p>' . esc_html__( 'Noch keine Schriften lokal gespeichert.', 'plakette' ) . '</p>';
		}
		$out = '<ul>';
		foreach ( $d['fonts'] as $f ) {
			$used = isset( $f['used'] ) ? (int) $f['used'] : (int) $f['time'];
			$out .= '<li><strong>' . esc_html( implode( ', ', (array) $f['families'] ) ) . '</strong> – '
				. esc_html(
					sprintf(
						/* translators: 1: number of files, 2: size, 3: date */
						__( '%1$d Dateien, %2$s, zuletzt genutzt am %3$s', 'plakette' ),
						count( (array) $f['files'] ),
						size_format( array_sum( (array) $f['files'] ) ),
						wp_date( 'd.m.Y', $used )
					)
				) . '</li>';
		}
		return $out . '</ul>';
	}
}

add_action( 'plakette_boot', array( 'Plakette_Local_Fonts', 'boot' ) );
