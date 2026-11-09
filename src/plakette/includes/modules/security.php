<?php
/**
 * Security features ("Sicherheit › Schutz vor Angriffen") plus shared helpers for checks-security.php.
 *
 * Modules (Plakette_Modules keys):
 *   sec_headers    array name => value  – security headers for front-end pages (send_headers). The same list is written
 *                                         to wp-content/plakette-headers.json, which the page-cache drop-in reads.
 *   no_xmlrpc      true                 – xmlrpc.php answers 403, no X-Pingback header, no RSD link.
 *   no_user_enum   true                 – ?author=N redirects anonymous visitors home, no users in REST/sitemap/oEmbed.
 *   login_limit    true                 – 5 failed logins per IP in 15 minutes lock that IP for 15 minutes; generic errors.
 *   hide_errors    true                 – display_errors off for every request outside wp-admin (from plugins_loaded on).
 *   no_generator   true                 – no <meta name="generator"> (version numbers in ?ver= stay for cache busting).
 *   mixed_content  true                 – http://own-host → https://own-host in the delivered HTML (https sites only).
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Security {

	const LOGIN_MAX    = 5;
	const LOGIN_WINDOW = 900; // 15 minutes.
	const LOGIN_LOCK   = 900;

	public static function boot() {
		if ( Plakette_Modules::on( 'hide_errors' ) && ! is_admin() ) {
			@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet
		}
		if ( Plakette_Modules::get( 'sec_headers' ) ) {
			add_action( 'send_headers', array( __CLASS__, 'send_headers' ) );
		}
		if ( Plakette_Modules::on( 'no_xmlrpc' ) ) {
			self::disable_xmlrpc();
		}
		if ( Plakette_Modules::on( 'no_user_enum' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'block_author_query' ), 1 );
			add_filter( 'rest_endpoints', array( __CLASS__, 'rest_endpoints' ) );
			add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'sitemap_provider' ), 10, 2 );
			add_filter( 'oembed_response_data', array( __CLASS__, 'oembed_data' ), 20 );
		}
		if ( Plakette_Modules::on( 'login_limit' ) ) {
			add_filter( 'authenticate', array( __CLASS__, 'login_check_lock' ), 30, 3 );
			add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ) );
			add_action( 'wp_login', array( __CLASS__, 'login_success' ) );
			add_filter( 'wp_login_errors', array( __CLASS__, 'login_errors_generic' ) );
		}
		if ( Plakette_Modules::on( 'no_generator' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string', 99 );
		}
		if ( Plakette_Modules::on( 'mixed_content' ) && 0 === strpos( home_url(), 'https://' ) ) {
			Plakette_Html::add( 'mixed_content', array( __CLASS__, 'fix_mixed_content' ), 90 );
		}
	}

	/* ------------------------------------------------------------------ detection helpers */

	/** Active plugin basenames (site + network). */
	public static function active_plugins() {
		$list = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$list = array_merge( $list, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_filter( array_map( 'strval', $list ) ) );
	}

	/** Name of the first active plugin whose folder is in $known (folder => name), or ''. */
	public static function find_plugin( array $known ) {
		foreach ( self::active_plugins() as $basename ) {
			$folder = strtolower( strtok( $basename, '/' ) );
			if ( isset( $known[ $folder ] ) ) {
				return $known[ $folder ];
			}
		}
		return '';
	}

	/** Security plugins: folder => name. */
	public static function security_plugins() {
		return array(
			'wordfence'                          => 'Wordfence',
			'better-wp-security'                 => 'Solid Security',
			'ithemes-security-pro'               => 'Solid Security Pro',
			'solid-security'                     => 'Solid Security',
			'all-in-one-wp-security-and-firewall' => 'All-In-One Security',
			'all-in-one-wp-security'             => 'All-In-One Security',
			'sucuri-scanner'                     => 'Sucuri Security',
			'wp-simple-firewall'                 => 'Shield Security',
			'limit-login-attempts-reloaded'      => 'Limit Login Attempts Reloaded',
			'limit-login-attempts'               => 'Limit Login Attempts',
			'loginizer'                          => 'Loginizer',
			'wps-hide-login'                     => 'WPS Hide Login',
			'really-simple-ssl'                  => 'Really Simple Security',
			'really-simple-ssl-pro'              => 'Really Simple Security Pro',
			'jetpack-protect'                    => 'Jetpack Protect',
		);
	}

	/** Name of an active security plugin, or ''. */
	public static function security_plugin() {
		$name = self::find_plugin( self::security_plugins() );
		if ( '' === $name ) {
			if ( defined( 'WORDFENCE_VERSION' ) ) {
				$name = 'Wordfence';
			} elseif ( class_exists( 'ITSEC_Core' ) ) {
				$name = 'Solid Security';
			} elseif ( defined( 'AIO_WP_SECURITY_VERSION' ) ) {
				$name = 'All-In-One Security';
			}
		}
		return $name;
	}

	/** Plugin that protects the login against password guessing, or ''. */
	public static function login_protection() {
		$known = self::security_plugins();
		unset( $known['sucuri-scanner'], $known['wps-hide-login'] ); // Sucuri: only with its paid firewall; WPS Hide Login only hides the address.
		$name = self::find_plugin( $known );
		if ( '' === $name && self::jetpack_active() && class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) && Jetpack::is_module_active( 'protect' ) ) {
			$name = 'Jetpack (Brute-Force-Schutz)';
		}
		return $name;
	}

	public static function jetpack_active() {
		return '' !== self::find_plugin( array( 'jetpack' => 'Jetpack' ) ) || defined( 'JETPACK__VERSION' );
	}

	/** Backup plugin or managed host that makes backups, or ''. */
	public static function backup_solution() {
		$name = self::find_plugin(
			array(
				'updraftplus'                   => 'UpdraftPlus',
				'updraftplus-premium'           => 'UpdraftPlus Premium',
				'backwpup'                      => 'BackWPup',
				'backwpup-pro'                  => 'BackWPup Pro',
				'duplicator'                    => 'Duplicator',
				'duplicator-pro'                => 'Duplicator Pro',
				'all-in-one-wp-migration'       => 'All-in-One WP Migration',
				'wpvivid-backuprestore'         => 'WPvivid Backup',
				'wpvivid-backup-pro'            => 'WPvivid Backup Pro',
				'blogvault-real-time-backup'    => 'BlogVault',
				'jetpack-backup'                => 'Jetpack VaultPress Backup',
				'vaultpress'                    => 'VaultPress',
				'backupbuddy'                   => 'Solid Backups',
				'solid-backups'                 => 'Solid Backups',
				'worker'                        => 'ManageWP Worker',
				'mainwp-child'                  => 'MainWP Child',
				'backup-guard'                  => 'Backup Guard',
				'backup'                        => 'Backup Guard',
				'backup-guard-platinum'         => 'Backup Guard',
				'backup-backup'                 => 'Backup Migration',
				'wp-time-capsule'               => 'WP Time Capsule',
				'xcloner-backup-and-restore'    => 'XCloner',
				'boldgrid-backup'               => 'Total Upkeep',
			)
		);
		if ( '' !== $name ) {
			return $name;
		}
		$hosts = array(
			'WPE_APIKEY'           => 'WP Engine',
			'KINSTAMU_VERSION'     => 'Kinsta',
			'IS_ATOMIC'            => 'WordPress.com',
			'IS_PRESSABLE'         => 'Pressable',
			'PANTHEON_ENVIRONMENT' => 'Pantheon',
			'FLYWHEEL_CONFIG_DIR'  => 'Flywheel',
			'GD_SYSTEM_PLUGIN_DIR' => 'GoDaddy Managed WordPress',
			'RAIDBOXES_VERSION'    => 'Raidboxes',
		);
		foreach ( $hosts as $const => $host ) {
			if ( defined( $const ) ) {
				return $host;
			}
		}
		return '';
	}

	/** Shop or membership plugin that needs open registration, or ''. */
	public static function membership_plugin() {
		return self::find_plugin(
			array(
				'woocommerce'                => 'WooCommerce',
				'buddypress'                 => 'BuddyPress',
				'buddyboss-platform'         => 'BuddyBoss',
				'bbpress'                    => 'bbPress',
				'memberpress'                => 'MemberPress',
				'ultimate-member'            => 'Ultimate Member',
				'paid-memberships-pro'       => 'Paid Memberships Pro',
				'sfwd-lms'                   => 'LearnDash',
				'learnpress'                 => 'LearnPress',
				'lifterlms'                  => 'LifterLMS',
				'easy-digital-downloads'     => 'Easy Digital Downloads',
				'restrict-content'           => 'Restrict Content',
				'restrict-content-pro'       => 'Restrict Content Pro',
				's2member'                   => 's2Member',
				'tutor'                      => 'Tutor LMS',
				'profile-builder'            => 'Profile Builder',
				'user-registration'          => 'User Registration',
			)
		);
	}

	/** Anonymous request with a short timeout (no redirects unless asked). */
	public static function request( $url, array $args = array() ) {
		$res = wp_remote_request(
			$url,
			array_merge(
				array(
					'method'              => 'GET',
					'timeout'             => 8,
					'redirection'         => 0,
					'sslverify'           => false,
					'user-agent'          => Plakette_Crawler::UA,
					'cookies'             => array(),
					'limit_response_size' => 300000,
				),
				$args
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'status' => 0, 'error' => $res->get_error_message(), 'headers' => array(), 'body' => '' );
		}
		$headers = array();
		foreach ( wp_remote_retrieve_headers( $res ) as $name => $value ) {
			$headers[ strtolower( $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return array(
			'ok'      => true,
			'status'  => (int) wp_remote_retrieve_response_code( $res ),
			'headers' => $headers,
			'body'    => (string) wp_remote_retrieve_body( $res ),
		);
	}

	/** IP address of the visitor. Only REMOTE_ADDR – behind a proxy use the filter "plakette_client_ip". */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'plakette_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Do visitors probably arrive through a proxy (then REMOTE_ADDR is the same for everyone)? */
	public static function behind_proxy() {
		if ( has_filter( 'plakette_client_ip' ) ) {
			return false;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$forwarded = ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) || ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) || ! empty( $_SERVER['HTTP_X_REAL_IP'] );
		return $forwarded && $ip && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/* ------------------------------------------------------------------ sec_headers */

	public static function headers_file() {
		return WP_CONTENT_DIR . '/plakette-headers.json';
	}

	public static function send_headers( $wp = null ) {
		if ( headers_sent() || is_admin() ) {
			return;
		}
		$list = Plakette_Modules::get( 'sec_headers' );
		if ( ! is_array( $list ) ) {
			return;
		}
		$present = array();
		foreach ( headers_list() as $line ) {
			$present[ strtolower( trim( strtok( $line, ':' ) ) ) ] = true;
		}
		$embed = is_object( $wp ) && ! empty( $wp->query_vars['embed'] );
		foreach ( $list as $name => $value ) {
			if ( ! is_string( $name ) || ! is_string( $value ) || ! preg_match( '/^[A-Za-z-]+$/', $name ) || preg_match( '/[\r\n]/', $value ) ) {
				continue;
			}
			if ( isset( $present[ strtolower( $name ) ] ) ) {
				continue;
			}
			// Embeds of your posts on other websites (/embed/) must stay embeddable.
			if ( $embed && 'x-frame-options' === strtolower( $name ) ) {
				continue;
			}
			if ( 'strict-transport-security' === strtolower( $name ) && ! is_ssl() ) {
				continue;
			}
			header( $name . ': ' . $value );
		}
	}

	/* ------------------------------------------------------------------ no_xmlrpc */

	private static function disable_xmlrpc() {
		add_filter( 'xmlrpc_enabled', '__return_false', 99 );
		add_filter( 'xmlrpc_methods', '__return_empty_array', 99 );
		add_filter( 'wp_headers', array( __CLASS__, 'drop_pingback_header' ), 99 );
		add_filter( 'bloginfo_url', array( __CLASS__, 'drop_pingback_url' ), 99, 2 );
		remove_action( 'wp_head', 'rsd_link' );
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			// The XML-RPC server answers system.listMethods & co. even without methods – stop before it starts.
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'XML-RPC ist auf dieser Website abgeschaltet.'; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
	}

	public static function drop_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public static function drop_pingback_url( $output, $show ) {
		return 'pingback_url' === $show ? '' : $output;
	}

	/* ------------------------------------------------------------------ no_user_enum */

	public static function block_author_query() {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['author'] ) && ! is_user_logged_in() && ! is_admin() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	public static function rest_endpoints( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints; // The block editor needs the user list.
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	public static function sitemap_provider( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	public static function oembed_data( $data ) {
		unset( $data['author_name'], $data['author_url'] );
		return $data;
	}

	/* ------------------------------------------------------------------ login_limit */

	private static function login_key( $ip ) {
		return 'plakette_ll_' . md5( $ip );
	}

	/** Seconds until the lock for this IP ends (0 = not locked). */
	public static function locked_for( $ip ) {
		$data = get_transient( self::login_key( $ip ) );
		if ( is_array( $data ) && ! empty( $data['until'] ) && $data['until'] > time() ) {
			return (int) $data['until'] - time();
		}
		return 0;
	}

	public static function login_check_lock( $user, $username = '', $password = '' ) {
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return $user;
		}
		$wait = self::locked_for( $ip );
		if ( $wait > 0 ) {
			return new WP_Error(
				'plakette_locked',
				sprintf(
					/* translators: %d: minutes */
					__( '<strong>Zu viele Fehlversuche.</strong> Aus Sicherheitsgründen sind Anmeldungen von deinem Anschluss für %d Minuten gesperrt. Bitte versuche es danach noch einmal.', 'plakette' ),
					max( 1, (int) ceil( $wait / 60 ) )
				)
			);
		}
		return $user;
	}

	public static function login_failed( $username = '' ) {
		$ip = self::client_ip();
		if ( '' === $ip || self::locked_for( $ip ) ) {
			return;
		}
		$key  = self::login_key( $ip );
		$data = get_transient( $key );
		$now  = time();
		if ( ! is_array( $data ) || empty( $data['start'] ) || $now - (int) $data['start'] > self::LOGIN_WINDOW ) {
			$data = array( 'start' => $now, 'count' => 0, 'until' => 0 );
		}
		++$data['count'];
		if ( $data['count'] >= self::LOGIN_MAX ) {
			$data['until'] = $now + self::LOGIN_LOCK;
			set_transient( $key, $data, self::LOGIN_LOCK );
			return;
		}
		set_transient( $key, $data, self::LOGIN_WINDOW );
	}

	public static function login_success() {
		$ip = self::client_ip();
		if ( '' !== $ip ) {
			delete_transient( self::login_key( $ip ) );
		}
	}

	/** Same message for "unknown user" and "wrong password" – attackers learn nothing about existing accounts. */
	public static function login_errors_generic( $errors ) {
		if ( ! is_wp_error( $errors ) ) {
			return $errors;
		}
		$codes = $errors->get_error_codes();
		if ( array_intersect( $codes, array( 'invalid_username', 'incorrect_password', 'invalid_email' ) ) ) {
			$generic = new WP_Error();
			foreach ( $codes as $code ) {
				if ( ! in_array( $code, array( 'invalid_username', 'incorrect_password', 'invalid_email' ), true ) ) {
					$generic->add( $code, $errors->get_error_message( $code ), $errors->get_error_data( $code ) );
				}
			}
			$generic->add( 'plakette_login_failed', __( '<strong>Fehler:</strong> Benutzername, E-Mail-Adresse oder Passwort sind nicht richtig.', 'plakette' ) );
			return $generic;
		}
		return $errors;
	}

	/* ------------------------------------------------------------------ mixed_content */

	/** Hosts of this site (home + WordPress address). */
	public static function own_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			$port = wp_parse_url( $url, PHP_URL_PORT );
			if ( $host ) {
				$hosts[] = strtolower( $host ) . ( $port ? ':' . $port : '' );
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/** Regex that finds insecure references to the own host(s): http://host and http:\/\/host (JSON). */
	public static function mixed_regex() {
		$alts = array_map(
			function ( $h ) {
				return preg_quote( $h, '#' );
			},
			self::own_hosts()
		);
		if ( ! $alts ) {
			return '';
		}
		return '#http:(//|\\\\/\\\\/)(' . implode( '|', $alts ) . ')(?=[/"\'\s?\#):,\\\\]|$)#i';
	}

	public static function fix_mixed_content( $html ) {
		$re = self::mixed_regex();
		if ( '' === $re ) {
			return $html;
		}
		$out = preg_replace( $re, 'https:$1$2', $html );
		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Insecure references to the own host in resources (src, srcset, href, url(), poster, data-src, action).
	 * @return string[] up to 10 examples
	 */
	public static function find_mixed( $html ) {
		$alts = array_map(
			function ( $h ) {
				return preg_quote( $h, '#' );
			},
			self::own_hosts()
		);
		if ( ! $alts || '' === $html ) {
			return array();
		}
		$host = '(?:' . implode( '|', $alts ) . ')';
		$hits = array();
		$res  = array(
			'#\s(?:src|srcset|href|poster|data-src|data-srcset|action|content)\s*=\s*["\']?[^"\'>]*?(http://' . $host . '[^"\'\s>,]*)#i',
			'#url\(\s*["\']?(http://' . $host . '[^"\')\s]*)#i',
		);
		foreach ( $res as $re ) {
			if ( preg_match_all( $re, $html, $m ) ) {
				$hits = array_merge( $hits, $m[1] );
			}
		}
		return array_slice( array_values( array_unique( $hits ) ), 0, 10 );
	}
}

add_action( 'plakette_boot', array( 'Plakette_Security', 'boot' ) );
