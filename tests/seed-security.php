<?php
/**
 * Security defects for the e2e test (runs inside tests/seed.php, WordPress loaded, current user = 1).
 * Plus a few direct self-tests of Plakette_Security helpers (printed to the seed output).
 */

// A second administrator with a guessable login (user 1 stays the test runner's account).
if ( ! username_exists( 'administrator' ) ) {
	wp_insert_user(
		array(
			'user_login'   => 'administrator',
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => 'administrator@konditorei-sonnenschein.example',
			'role'         => 'administrator',
			'display_name' => 'administrator',
		)
	);
}

// Open registration with a role that may write posts.
update_option( 'users_can_register', 1 );
update_option( 'default_role', 'editor' );

// Automatic minor core updates switched off.
update_site_option( 'auto_update_core_minor', 'disabled' );

// An old, inactive plugin.
$plk_dir = WP_PLUGIN_DIR . '/plk-old-gallery';
wp_mkdir_p( $plk_dir );
file_put_contents( $plk_dir . '/plk-old-gallery.php', "<?php\n/**\n * Plugin Name: PLK Old Gallery\n * Version: 0.9\n */\n" );

// A debug.log with content in wp-content.
file_put_contents( WP_CONTENT_DIR . '/debug.log', '[' . gmdate( 'd-M-Y H:i:s' ) . " UTC] PHP Warning:  Undefined variable \$foo in /wordpress/wp-content/themes/x/functions.php on line 12\n", FILE_APPEND );

// Fixture switches read by tests/mu/plk-security-fixture.php.
update_option( 'plk_sec_display', 1 );
update_option( 'plk_sec_fake_listing', 1 );

// One weak secret key in wp-config.php (only when the file is writable).
$plk_cfg = ABSPATH . 'wp-config.php';
if ( file_exists( $plk_cfg ) && is_writable( $plk_cfg ) ) {
	$plk_src = file_get_contents( $plk_cfg );
	$plk_new = preg_replace( "/define\(\s*'NONCE_SALT',\s*'[^']*'\s*\);/", "define( 'NONCE_SALT', 'put your unique phrase here' );", $plk_src, 1, $plk_n );
	if ( $plk_n ) {
		file_put_contents( $plk_cfg, $plk_new );
		echo "security: NONCE_SALT set to placeholder\n";
	} else {
		echo "security: NONCE_SALT define not found in wp-config.php\n";
	}
} else {
	echo "security: wp-config.php not writable\n";
}

/* ---- self-tests ---- */
$plk_t = array();
$plk_h = Plakette_Security::own_hosts();
$plk_in  = '<img src="http://' . $plk_h[0] . '/a.jpg" srcset="http://' . $plk_h[0] . '/a.jpg 1x, http://' . $plk_h[0] . '/b.jpg 2x"><a href="http://other.example/x">x</a><script>var u="http:\/\/' . $plk_h[0] . '\/x";</script><div style="background:url(http://' . $plk_h[0] . ')">';
$plk_out = Plakette_Security::fix_mixed_content( $plk_in );
$plk_t['mixed_found']    = count( Plakette_Security::find_mixed( $plk_in ) );
$plk_t['mixed_left']     = substr_count( $plk_out, 'http://' . $plk_h[0] ) + substr_count( $plk_out, 'http:\/\/' . $plk_h[0] );
$plk_t['mixed_other_ok'] = false !== strpos( $plk_out, 'http://other.example/x' );
$plk_t['mixed_after']    = count( Plakette_Security::find_mixed( $plk_out ) );

$plk_ip                 = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
for ( $i = 0; $i < 4; $i++ ) {
	Plakette_Security::login_failed( 'x' );
}
$plk_t['lock_after_4'] = is_wp_error( Plakette_Security::login_check_lock( null, 'x', 'y' ) );
Plakette_Security::login_failed( 'x' );
$plk_lock               = Plakette_Security::login_check_lock( null, 'x', 'y' );
$plk_t['lock_after_5']  = is_wp_error( $plk_lock ) ? $plk_lock->get_error_code() : 'none';
Plakette_Security::login_success();
$plk_t['lock_after_ok'] = is_wp_error( Plakette_Security::login_check_lock( null, 'x', 'y' ) );
$_SERVER['REMOTE_ADDR'] = $plk_ip;
$plk_e                  = new WP_Error( 'incorrect_password', 'wrong' );
$plk_t['generic_codes'] = implode( ',', Plakette_Security::login_errors_generic( $plk_e )->get_error_codes() );
$plk_t['pp_none']       = Plakette_Check_Sec_Headers::permissions_policy( array() );
$plk_t['pp_maps']       = Plakette_Check_Sec_Headers::permissions_policy( array( 'https://www.google.com' ) );
echo 'security self-test: ' . wp_json_encode( $plk_t ) . "\n";
