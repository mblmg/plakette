<?php
/**
 * Test fixture (mu-plugin): switchable privacy scenarios for tests/consent-probe.mjs.
 *   /?plk_case=pixel&on=1|0   Meta pixel in wp_head (an owner who keeps a marketing pixel)
 * Only answers requests from the local machine.
 */
add_action(
	'init',
	function () {
		if ( ! isset( $_GET['plk_case'] ) || ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) ) {
			return;
		}
		$case = sanitize_key( $_GET['plk_case'] );
		update_option( 'plk_case_' . $case, empty( $_GET['on'] ) ? 0 : 1 );
		if ( class_exists( 'Plakette_Page_Cache' ) ) {
			Plakette_Page_Cache::purge_all();
		}
		header( 'Content-Type: text/plain' );
		echo esc_html( $case ) . '=' . (int) get_option( 'plk_case_' . $case );
		exit;
	}
);
add_action(
	'wp_head',
	function () {
		if ( ! get_option( 'plk_case_pixel' ) ) {
			return;
		}
		echo "<script>\n!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');\nfbq('init', '1234567890');\nfbq('track', 'PageView');\n</script>\n";
	},
	6
);
