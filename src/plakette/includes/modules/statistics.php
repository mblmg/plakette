<?php
/**
 * Module "stats": Plakette-Statistik – visitor statistics without cookies and without consent.
 *
 * - The page loads assets/stats.js (≈ 0.5 KB). It sends one beacon per page view (navigator.sendBeacon) with the path
 *   and the host of the referring site. Nothing is stored in the browser, there is no fingerprinting.
 * - The server keeps only daily sums per path, referrer host and device class (mobile/tablet/desktop).
 *   No IP address, no user agent, no visitor id is stored – the data is anonymous from the start (no consent needed:
 *   no access to the device beyond what is needed to show the page, § 25 Abs. 2 Nr. 2 TDDDG; no personal data kept).
 * - Logged-in users and bots are not counted. Works with page caches because counting is triggered in the browser.
 *
 * Table {prefix}plakette_stats (day, path, referrer, device, hits). Rows older than 400 days are removed daily.
 * Module value: true.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Stats {

	const DB_VERSION = '1';
	const DB_OPTION  = 'plakette_stats_db';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'plakette_stats';
	}

	/**
	 * On when a fix switched the module on (a tracking service was replaced) or the user switched it on in
	 * Einstellungen › „Besucherstatistik“.
	 */
	public static function active() {
		if ( Plakette_Modules::on( 'stats' ) ) {
			return true;
		}
		$all = get_option( Plakette_Settings::OPTION, array() );
		return is_array( $all ) && ! empty( $all['stats']['enabled'] );
	}

	public static function settings_section( $sections ) {
		$sections['stats'] = array(
			'title'  => __( 'Besucherstatistik', 'plakette' ),
			'desc'   => __( 'Die Plakette-Statistik zählt Seitenaufrufe ohne Cookies, ohne IP-Adressen und ohne Wiedererkennung von Besuchern – deshalb braucht sie keine Einwilligung und keinen Cookie-Banner. Du siehst Aufrufe pro Tag, die meistgelesenen Seiten, die Herkunft der Besucher und die Geräte. Die Auswertung erscheint oben auf dieser Seite im Abschnitt „Besucher – Plakette-Statistik“.', 'plakette' ),
			'fields' => array(
				array(
					'key'     => 'enabled',
					'label'   => __( 'Besuche zählen', 'plakette' ),
					'type'    => 'toggle',
					'default' => false,
					'help'    => Plakette_Modules::on( 'stats' )
						? __( 'Die Statistik wurde bereits über einen Prüfpunkt eingeschaltet (als Ersatz für einen Tracking-Dienst) und läuft. Abschalten kannst du sie dort im Protokoll mit „Rückgängig“.', 'plakette' )
						: __( 'Angemeldete Nutzer und Suchmaschinen-Bots werden nicht gezählt. Beim Abschalten bleiben die bisherigen Zahlen erhalten.', 'plakette' ),
				),
			),
		);
		return $sections;
	}

	public static function boot() {
		add_action( 'plakette_daily', array( __CLASS__, 'cleanup' ) );
		add_filter( 'plakette_settings_sections', array( __CLASS__, 'settings_section' ) );
		if ( ! self::active() ) {
			return;
		}
		self::maybe_install();
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_script' ), 99 );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			day date NOT NULL,
			path varchar(190) NOT NULL DEFAULT '',
			referrer varchar(100) NOT NULL DEFAULT '',
			device varchar(10) NOT NULL DEFAULT '',
			hits int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY day (day)
		) {$charset};" );
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	public static function maybe_install() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function routes() {
		register_rest_route(
			'plakette/v1',
			'/hit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'hit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function print_script() {
		if ( is_admin() || is_feed() || is_preview() || is_customize_preview() ) {
			return;
		}
		$src = PLAKETTE_URL . 'assets/stats.js?ver=' . rawurlencode( PLAKETTE_VERSION );
		echo '<script src="' . esc_url( $src ) . '" id="plakette-stats-js" data-u="' . esc_url( rest_url( 'plakette/v1/hit' ) ) . '" defer></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources
	}

	public static function is_bot( $ua ) {
		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|scrape|headless|lighthouse|pagespeed|preview|monitor|curl|wget|python|java\/|httpclient|facebookexternalhit|embedly|whatsapp|telegram|plakette/i', $ua );
	}

	public static function device( $ua ) {
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Mobi|iPhone|iPod|Android|Windows Phone|Opera Mini/i', $ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/** One page view. Always answers 204 – the visitor never waits for anything. */
	public static function hit( WP_REST_Request $request ) {
		$res = new WP_REST_Response( null, 204 );
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( self::is_bot( $ua ) || wp_validate_auth_cookie( '', 'logged_in' ) ) {
			return $res;
		}
		// Only beacons from our own pages.
		$origin = (string) $request->get_header( 'origin' );
		$own    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' !== $origin && strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) !== $own ) {
			return $res;
		}
		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) || ! isset( $data['p'] ) ) {
			return $res;
		}
		$path = '/' . ltrim( (string) wp_parse_url( (string) $data['p'], PHP_URL_PATH ), '/' );
		$path = substr( sanitize_text_field( $path ), 0, 190 );
		$ref  = isset( $data['r'] ) ? (string) $data['r'] : '';
		$ref  = strtolower( (string) ( wp_parse_url( $ref, PHP_URL_HOST ) ?: $ref ) );
		$ref  = preg_replace( '/^www\./', '', substr( preg_replace( '/[^a-z0-9.-]/', '', $ref ), 0, 100 ) );
		if ( $ref === preg_replace( '/^www\./', '', $own ) ) {
			$ref = '';
		}
		self::count( current_time( 'Y-m-d' ), $path, $ref, self::device( $ua ) );
		return $res;
	}

	public static function count( $day, $path, $ref, $device ) {
		global $wpdb;
		$table = self::table();
		$done  = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1 WHERE day = %s AND path = %s AND referrer = %s AND device = %s", $day, $path, $ref, $device ) ); // phpcs:ignore WordPress.DB
		if ( ! $done ) {
			$wpdb->insert( $table, array( 'day' => $day, 'path' => $path, 'referrer' => $ref, 'device' => $device, 'hits' => 1 ) ); // phpcs:ignore WordPress.DB
		}
	}

	public static function cleanup() {
		global $wpdb;
		if ( get_option( self::DB_OPTION ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE day < %s', gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		}
	}

	/** Undo of the fix that switched the statistics on: drop the table (the counts were collected by Plakette only). */
	public static function uninstall() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB
		delete_option( self::DB_OPTION );
	}

	/** Summary of the last $days days for the admin screen (null when the statistics are off). */
	public static function summary( $days = 30 ) {
		global $wpdb;
		if ( ! self::active() ) {
			return null;
		}
		self::maybe_install();
		if ( ! get_option( self::DB_OPTION ) ) {
			return null;
		}
		$table = self::table();
		$from  = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) - ( $days - 1 ) * DAY_IN_SECONDS );
		$daily = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$daily[ gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) - $i * DAY_IN_SECONDS ) ] = 0;
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(hits) AS n FROM {$table} WHERE day >= %s GROUP BY day", $from ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$daily[ substr( (string) $r['day'], 0, 10 ) ] = (int) $r['n'];
		}
		$top = function ( $col ) use ( $wpdb, $table, $from ) {
			$out = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$col} AS k, SUM(hits) AS n FROM {$table} WHERE day >= %s GROUP BY {$col} ORDER BY n DESC LIMIT 10", $from ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$out[] = array( 'k' => (string) $r['k'], 'n' => (int) $r['n'] );
			}
			return $out;
		};
		return array(
			'days'      => $days,
			'daily'     => $daily,
			'total'     => array_sum( $daily ),
			'paths'     => $top( 'path' ),
			'referrers' => $top( 'referrer' ),
			'devices'   => $top( 'device' ),
		);
	}
}

add_action( 'plakette_boot', array( 'Plakette_Stats', 'boot' ) );
