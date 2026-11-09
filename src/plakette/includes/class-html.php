<?php
/**
 * One output buffer for every feature that has to change the finished HTML of a page
 * (consent blocker, LCP image, image dimensions, link labels, mixed content …).
 *
 * Features register a transform:  Plakette_Html::add( 'name', $callback, $priority );
 * The callback receives the HTML string and returns the changed HTML. Lower priority runs first.
 * A transform that throws is skipped (the page is still delivered).
 *
 * The buffer starts at template_redirect with PHP_INT_MAX, i.e. after page-cache plugins started theirs.
 * It is therefore the innermost buffer: its result is what the cache stores.
 *
 * Comparison mode for the user: ?plakette_aus=1 delivers the page without any transform.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Html {

	/** @var array name => [ callable, priority ] */
	private static $transforms = array();

	public static function add( $name, $callback, $priority = 10 ) {
		self::$transforms[ $name ] = array( $callback, (int) $priority );
		if ( 1 === count( self::$transforms ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'start' ), PHP_INT_MAX );
		}
	}

	public static function has( $name ) {
		return isset( self::$transforms[ $name ] );
	}

	public static function should_process() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() || is_customize_preview() || is_robots() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}
		// Page builders show their editor on the front end – never touch it.
		foreach ( array( 'plakette_aus', 'breakdance', 'breakdance_iframe', 'oxygen', 'ct_builder', 'bricks', 'elementor-preview', 'fl_builder', 'et_fb', 'vc_editable' ) as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return false;
			}
		}
		return (bool) apply_filters( 'plakette_html_process', true );
	}

	public static function start() {
		if ( self::should_process() ) {
			ob_start( array( __CLASS__, 'process' ) );
		}
	}

	public static function process( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 200 || false === stripos( $html, '</head>' ) || false === stripos( $html, '<body' ) ) {
			return $html;
		}
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Content-Type' ) && false === stripos( $header, 'text/html' ) ) {
				return $html;
			}
		}
		$list = self::$transforms;
		uasort(
			$list,
			function ( $a, $b ) {
				return $a[1] - $b[1];
			}
		);
		foreach ( $list as $name => $t ) {
			try {
				$out = call_user_func( $t[0], $html );
				if ( is_string( $out ) && '' !== $out ) {
					$html = $out;
				}
			} catch ( Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'Plakette: Umbau "' . $name . '" fehlgeschlagen: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				}
			}
		}
		return $html;
	}

	/* ------------------------------------------------------------------ helpers for transforms */

	/** Inserts markup at the start of <head> (after <meta charset> when present). */
	public static function insert_in_head( $html, $markup ) {
		$head_end = stripos( $html, '</head>' );
		if ( preg_match( '#<meta\s+charset=[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) && $m[0][1] < $head_end ) {
			$pos = $m[0][1] + strlen( $m[0][0] );
			return substr( $html, 0, $pos ) . "\n" . $markup . substr( $html, $pos );
		}
		if ( preg_match( '#<head\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[0][1] + strlen( $m[0][0] );
			return substr( $html, 0, $pos ) . "\n" . $markup . substr( $html, $pos );
		}
		return $html;
	}

	/** Inserts markup right before </head>. */
	public static function append_to_head( $html, $markup ) {
		$pos = stripos( $html, '</head>' );
		return false === $pos ? $html : substr( $html, 0, $pos ) . $markup . "\n" . substr( $html, $pos );
	}

	/** Inserts markup right before the last </body>. */
	public static function append_to_body( $html, $markup ) {
		$pos = strripos( $html, '</body>' );
		return false === $pos ? $html . $markup : substr( $html, 0, $pos ) . $markup . "\n" . substr( $html, $pos );
	}

	/**
	 * Runs $callback only on the parts of the HTML outside <script>, <style>, <template>, <textarea> and comments
	 * (so regexes on tags never touch code or editable text).
	 */
	public static function outside_code( $html, $callback ) {
		$parts = preg_split( '#(<script\b.*?</script>|<style\b.*?</style>|<template\b.*?</template>|<textarea\b.*?</textarea>|<!--.*?-->)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html;
		}
		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 ) {
				$parts[ $i ] = call_user_func( $callback, $part );
			}
		}
		return implode( '', $parts );
	}

	/** Reads an attribute from a single start tag string ('' when absent). */
	public static function attr( $tag, $name ) {
		if ( preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $tag, $m ) ) {
			$v = isset( $m[4] ) && '' !== $m[4] ? $m[4] : ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : $m[2] );
			return html_entity_decode( $v, ENT_QUOTES, 'UTF-8' );
		}
		return '';
	}

	public static function has_attr( $tag, $name ) {
		return (bool) preg_match( '#\s' . preg_quote( $name, '#' ) . '(\s*=|[\s/>])#i', $tag );
	}

	/** Sets (or replaces) an attribute in a single start tag string. */
	public static function set_attr( $tag, $name, $value ) {
		$tag = self::remove_attr( $tag, $name );
		return preg_replace( '#^<([a-z0-9-]+)#i', '<$1 ' . $name . '="' . esc_attr( $value ) . '"', $tag, 1 );
	}

	public static function remove_attr( $tag, $name ) {
		return preg_replace( '#\s' . preg_quote( $name, '#' ) . '(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?(?=[\s/>])#i', '', $tag );
	}
}
