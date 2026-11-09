<?php
/**
 * Test fixture (mu-plugin) for the security checks – only active after tests/seed-security.php ran.
 * - display_errors on (Plakette's module "hide_errors" switches it off again at plugins_loaded).
 * - The upload folders "list their files" (faked through pre_http_request) as long as they have no index.php.
 */
if ( get_option( 'plk_sec_display' ) ) {
	@ini_set( 'display_errors', '1' );
}
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( ! get_option( 'plk_sec_fake_listing' ) ) {
			return $pre;
		}
		$up   = wp_get_upload_dir();
		$base = untrailingslashit( $up['baseurl'] );
		if ( 0 !== strpos( $url, $base . '/' ) ) {
			return $pre;
		}
		$rel = substr( $url, strlen( $base ) );
		if ( ! preg_match( '#^/(\d{4}/(\d{2}/)?)?$#', $rel ) ) {
			return $pre;
		}
		$dir = untrailingslashit( $up['basedir'] ) . rtrim( $rel, '/' );
		if ( ! is_dir( $dir ) || file_exists( $dir . '/index.php' ) ) {
			return $pre;
		}
		return array(
			'headers'  => array( 'content-type' => 'text/html' ),
			'body'     => '<html><head><title>Index of ' . esc_html( $rel ) . '</title></head><body><h1>Index of ' . esc_html( $rel ) . '</h1><ul><li>a.jpg</li></ul></body></html>',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
