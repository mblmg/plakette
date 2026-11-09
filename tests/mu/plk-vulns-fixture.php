<?php
/**
 * Test fixture (mu-plugin): while the option plk_vulns_block_download is set, downloads of the fake
 * Contact Form 7 fail (as for a plugin without download), so the update path must fail gracefully.
 */
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( get_option( 'plk_vulns_block_download' ) && false !== strpos( (string) $url, 'downloads.wordpress.org/plugin/contact-form-7' ) ) {
			return new WP_Error( 'http_request_failed', 'Download blockiert (Testumgebung)' );
		}
		return $pre;
	},
	10,
	3
);
