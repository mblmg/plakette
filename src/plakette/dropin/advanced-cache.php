<?php
/**
 * Plakette page cache drop-in.
 * Written by the plugin "Plakette – Website-Hauptuntersuchung". Deactivating the plugin removes this file.
 *
 * Runs before WordPress loads. Serves a stored copy of the page to visitors who are not logged in;
 * otherwise tells the plugin where to store the page after WordPress has built it.
 */

defined( 'ABSPATH' ) || exit;

( function () {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return;
	}
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	// Plakette measures the page as a visitor would see it – serve the cached copy if there is one.
	$measure = isset( $_GET['plakette_measure'] ) && 1 === count( $_GET );

	foreach ( $measure ? array() : array_keys( $_COOKIE ) as $name ) {
		if ( preg_match( '/^(wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_|edd_items_in_cart|wordpress_no_cache)/', $name ) ) {
			return;
		}
	}

	$uri   = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
	$parts = explode( '?', $uri, 2 );
	$path  = rawurldecode( $parts[0] );

	// Tracking parameters do not change the page; anything else does.
	if ( isset( $parts[1] ) && '' !== $parts[1] ) {
		parse_str( $parts[1], $query );
		foreach ( array_keys( $query ) as $key ) {
			if ( ! preg_match( '/^(utm_[a-z]+|fbclid|gclid|msclkid|mc_cid|mc_eid|_ga|plakette_measure)$/', $key ) ) {
				return;
			}
		}
	}

	if ( preg_match( '#(^|/)(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php|wp-content|wp-includes)(/|$)#', $path )
		|| preg_match( '#\.(php|xml|txt|xsl)$#i', $path )
		|| false !== strpos( $path, '..' )
		|| ! preg_match( '#^[\p{L}\p{N}/_.~\-]*$#u', $path ) ) {
		return;
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( $_SERVER['HTTP_HOST'] ) : '';
	if ( '' === $host || ! preg_match( '/^[a-z0-9.\-:]+$/', $host ) ) {
		return;
	}
	$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) ) || ( isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] ) || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );

	$file = WP_CONTENT_DIR . '/cache/plakette/' . ( $https ? 'https' : 'http' ) . '/' . str_replace( ':', '_', $host ) . '/' . trim( $path, '/' );
	$file = rtrim( $file, '/' ) . '/index.html';

	$mtime = is_readable( $file ) ? @filemtime( $file ) : false; // the file can be purged in between
	if ( $mtime && ( time() - $mtime ) < 36000 ) {
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Plakette-Cache: HIT' );
		// Security headers the plugin sends on normal requests (written by the feature "Sicherheits-Header").
		$extra = WP_CONTENT_DIR . '/plakette-headers.json';
		if ( is_readable( $extra ) ) {
			$list = json_decode( (string) file_get_contents( $extra ), true );
			foreach ( is_array( $list ) ? $list : array() as $name => $value ) {
				if ( is_string( $name ) && is_string( $value ) && preg_match( '/^[A-Za-z-]+$/', $name ) && ! preg_match( '/[\r\n]/', $value ) ) {
					header( $name . ': ' . $value );
				}
			}
		}
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		$accept = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
		if ( file_exists( WP_CONTENT_DIR . '/plakette-gzip.flag' ) && false !== stripos( $accept, 'gzip' ) && function_exists( 'ob_gzhandler' ) && ! ini_get( 'zlib.output_compression' ) ) {
			ob_start( 'ob_gzhandler' );
		}
		if ( 'HEAD' !== $method ) {
			@readfile( $file );
		}
		exit;
	}

	if ( $measure ) {
		return;
	}
	header( 'X-Plakette-Cache: MISS' );
	define( 'PLAKETTE_CACHE_FILE', $file );
} )();
