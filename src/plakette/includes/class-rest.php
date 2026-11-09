<?php
/**
 * REST API for the admin screen. Every route requires the capability "manage_options".
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Rest {

	const NS = 'plakette/v1';

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can() {
		return current_user_can( 'manage_options' );
	}

	public static function routes() {
		$base = array( 'permission_callback' => array( __CLASS__, 'can' ) );
		register_rest_route( self::NS, '/audit', $base + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'audit' ) ) );
		register_rest_route( self::NS, '/report', $base + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'report' ) ) );
		register_rest_route( self::NS, '/fix', $base + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'fix' ) ) );
		register_rest_route( self::NS, '/undo', $base + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'undo' ) ) );
		register_rest_route( self::NS, '/log', $base + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'log' ) ) );
		register_rest_route( self::NS, '/measure', $base + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'measure' ) ) );
		register_rest_route( self::NS, '/privacy-probe', $base + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'privacy_probe' ) ) );
		register_rest_route( self::NS, '/psi', $base + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'psi' ) ) );
		register_rest_route( self::NS, '/settings', $base + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_settings' ) ) );
		register_rest_route( self::NS, '/update-check', $base + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update_check' ) ) );
	}

	public static function save_settings( WP_REST_Request $request ) {
		$section = sanitize_key( (string) $request->get_param( 'section' ) );
		$values  = $request->get_param( 'values' );
		if ( ! is_array( $values ) || ! isset( Plakette_Settings::sections()[ $section ] ) ) {
			return new WP_Error( 'plakette_settings', __( 'Unbekannter Bereich.', 'plakette' ), array( 'status' => 400 ) );
		}
		Plakette_Settings::save( $section, $values );
		do_action( 'plakette_settings_saved', $section );
		Plakette_Page_Cache::purge_all();
		$state            = self::state();
		$state['message'] = __( 'Gespeichert.', 'plakette' );
		return rest_ensure_response( $state );
	}

	public static function update_check() {
		Plakette_Updater::latest( true );
		delete_site_transient( 'update_plugins' );
		return rest_ensure_response( self::state() );
	}

	public static function state( $report = null ) {
		return array(
			'report'  => $report ? $report : get_option( Plakette_Auditor::OPTION, null ),
			'measure' => get_option( 'plakette_measure', null ),
			'psi'     => get_option( 'plakette_psi', null ),
			'log'     => Plakette_Log::runs( 40 ),
			'history' => get_option( 'plakette_history', array() ),
			'psiKey'   => '' !== (string) get_option( 'plakette_psi_key', '' ),
			'areas'    => Plakette_Auditor::areas(),
			'settings' => Plakette_Settings::state(),
			'updater'  => Plakette_Updater::state(),
			'probe'    => get_option( 'plakette_privacy_probe', null ),
			'probeSetup' => self::probe_setup(),
			'stats'    => class_exists( 'Plakette_Stats' ) ? Plakette_Stats::summary() : null,
		);
	}

	/** What the browser probe needs: pages to visit, consent cookies to hide for a "first visit", current fingerprint. */
	public static function probe_setup() {
		$urls = array( home_url( '/' ) );
		$ids  = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 3,
				'orderby'        => 'modified',
				'fields'         => 'ids',
				'has_password'   => false,
				'post__not_in'   => array_filter( array( (int) get_option( 'page_on_front' ) ) ),
			)
		);
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}
		$probe = get_option( 'plakette_privacy_probe', null );
		return array(
			'urls'          => array_values( array_unique( array_filter( $urls ) ) ),
			'consentCookie' => Plakette_Services::consent_cookie_pattern(),
			'fresh'         => class_exists( 'Plakette_Privacy_Evidence' ) ? Plakette_Privacy_Evidence::probe_fresh( $probe ) : false,
		);
	}

	/**
	 * Result of the privacy measurement in the browser (admin.js › privacyProbe()): what a first visit stores and contacts.
	 * Only names, hosts and the page are kept – never cookie values.
	 */
	public static function privacy_probe( WP_REST_Request $request ) {
		$p    = (array) $request->get_json_params();
		$own  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$page = function ( $u ) {
			$u = esc_url_raw( (string) $u );
			return '' !== $u ? $u : home_url( '/' );
		};
		$name = function ( $v ) {
			return substr( preg_replace( '/[^\x21-\x7E]/', '', (string) $v ), 0, 120 );
		};
		$data = array(
			'time'      => time(),
			'signature' => class_exists( 'Plakette_Consent' ) ? Plakette_Consent::signature() : '',
			'pages'     => array(),
			'cookies'   => array(),
			'storage'   => array(),
			'hosts'     => array(),
			'errors'    => array(),
		);
		foreach ( array_slice( (array) ( $p['pages'] ?? array() ), 0, 10 ) as $u ) {
			$data['pages'][] = $page( $u );
		}
		$seen = array();
		foreach ( array_slice( (array) ( $p['cookies'] ?? array() ), 0, 200 ) as $c ) {
			$n = $name( $c['name'] ?? '' );
			if ( '' !== $n && ! isset( $seen[ 'c' . $n ] ) ) {
				$seen[ 'c' . $n ]  = true;
				$data['cookies'][] = array( 'name' => $n, 'page' => $page( $c['page'] ?? '' ) );
			}
		}
		foreach ( array_slice( (array) ( $p['storage'] ?? array() ), 0, 200 ) as $st ) {
			$area = in_array( $st['area'] ?? '', array( 'local', 'session', 'idb' ), true ) ? $st['area'] : 'local';
			$k    = $name( $st['key'] ?? '' );
			if ( '' !== $k && ! isset( $seen[ $area . $k ] ) ) {
				$seen[ $area . $k ] = true;
				$data['storage'][]  = array( 'area' => $area, 'key' => $k, 'page' => $page( $st['page'] ?? '' ) );
			}
		}
		foreach ( array_slice( (array) ( $p['hosts'] ?? array() ), 0, 200 ) as $h ) {
			$host = strtolower( preg_replace( '/[^a-z0-9.-]/i', '', (string) ( $h['host'] ?? '' ) ) );
			if ( '' !== $host && $host !== $own && ! isset( $seen[ 'h' . $host ] ) ) {
				$seen[ 'h' . $host ] = true;
				$data['hosts'][]     = array( 'host' => $host, 'type' => sanitize_key( $h['type'] ?? '' ), 'page' => $page( $h['page'] ?? '' ) );
			}
		}
		foreach ( array_slice( (array) ( $p['errors'] ?? array() ), 0, 5 ) as $err ) {
			$data['errors'][] = sanitize_text_field( (string) $err );
		}
		update_option( 'plakette_privacy_probe', $data, false );
		Plakette_Auditor::refresh( 'privacy_third_party' );
		return rest_ensure_response( self::state( Plakette_Auditor::refresh( 'privacy_consent' ) ) );
	}

	public static function audit() {
		return rest_ensure_response( self::state( Plakette_Auditor::run() ) );
	}

	public static function report() {
		return rest_ensure_response( self::state() );
	}

	public static function log() {
		return rest_ensure_response( Plakette_Log::runs( 40 ) );
	}

	public static function fix( WP_REST_Request $request ) {
		$id    = sanitize_key( (string) $request->get_param( 'check' ) );
		$check = Plakette_Auditor::get( $id );
		if ( ! $check ) {
			return new WP_Error( 'plakette_unknown', __( 'Unbekannte Prüfung.', 'plakette' ), array( 'status' => 400 ) );
		}
		$run_id = sanitize_key( (string) $request->get_param( 'run' ) );
		if ( '' === $run_id ) {
			$run_id = wp_generate_password( 12, false );
		}
		$req = array(
			'value' => $request->get_param( 'value' ),
			'items' => (array) $request->get_param( 'items' ),
		);
		$undo = array();
		try {
			$message = $check->fix( $req, $undo );
		} catch ( Throwable $e ) {
			$message = new WP_Error( 'plakette_crash', $e->getMessage() );
		}
		if ( is_wp_error( $message ) ) {
			// Roll back whatever the fix did before it failed.
			Plakette_Log::apply_ops( $undo );
			return new WP_Error( $message->get_error_code(), $message->get_error_message(), array( 'status' => 400 ) );
		}
		if ( ! Plakette_Log::add( $run_id, $id, $check->title . ': ' . $message, $undo ) ) {
			// Never keep a change that could not be written to the protocol – it could not be undone later.
			Plakette_Log::apply_ops( $undo );
			Plakette_Log::install();
			return new WP_Error( 'plakette_log', __( 'Die Änderung konnte nicht im Protokoll gespeichert werden und wurde deshalb sofort zurückgenommen. Bitte versuch es noch einmal.', 'plakette' ), array( 'status' => 500 ) );
		}
		Plakette_Page_Cache::purge_all();

		$report = $request->get_param( 'refresh' ) === false ? get_option( Plakette_Auditor::OPTION ) : Plakette_Auditor::refresh( $id );
		return rest_ensure_response(
			array_merge(
				self::state( $report ),
				array(
					'message' => $message,
					'run'     => $run_id,
				)
			)
		);
	}

	public static function undo( WP_REST_Request $request ) {
		$run_id = sanitize_key( (string) $request->get_param( 'run' ) );
		$result = Plakette_Log::undo_run( $run_id );
		$check  = sanitize_key( (string) $request->get_param( 'check' ) );
		$report = $check ? Plakette_Auditor::refresh( $check ) : get_option( Plakette_Auditor::OPTION );
		$state  = self::state( $report );
		$state['message'] = is_wp_error( $result ) ? $result->get_error_message() : __( 'Rückgängig gemacht.', 'plakette' );
		$state['ok']      = ! is_wp_error( $result );
		return rest_ensure_response( $state );
	}

	/** Metrics measured in the browser (see admin.js › measure()). */
	public static function measure( WP_REST_Request $request ) {
		$num = function ( $v, $max = 600000 ) {
			return is_numeric( $v ) ? max( 0, min( $max, (float) $v ) ) : null;
		};
		$p   = $request->get_json_params();
		$data = array(
			'time'      => time(),
			'url'       => esc_url_raw( isset( $p['url'] ) ? $p['url'] : home_url( '/' ) ),
			'lcp'       => $num( isset( $p['lcp'] ) ? $p['lcp'] : null ),
			'cls'       => $num( isset( $p['cls'] ) ? $p['cls'] : null, 100 ),
			'fcp'       => $num( isset( $p['fcp'] ) ? $p['fcp'] : null ),
			'ttfb'      => $num( isset( $p['ttfb'] ) ? $p['ttfb'] : null ),
			'load'      => $num( isset( $p['load'] ) ? $p['load'] : null ),
			'bytes'     => $num( isset( $p['bytes'] ) ? $p['bytes'] : null, 1e10 ),
			'requests'  => $num( isset( $p['requests'] ) ? $p['requests'] : null, 10000 ),
			'blocking'  => $num( isset( $p['blocking'] ) ? $p['blocking'] : null, 10000 ),
			'lcpTag'    => sanitize_key( isset( $p['lcpTag'] ) ? $p['lcpTag'] : '' ),
			'lcpImage'  => null,
			'byType'    => array(),
		);
		if ( ! empty( $p['byType'] ) && is_array( $p['byType'] ) ) {
			foreach ( $p['byType'] as $type => $bytes ) {
				$data['byType'][ sanitize_key( $type ) ] = $num( $bytes, 1e10 );
			}
		}
		if ( ! empty( $p['lcpImage']['url'] ) ) {
			$data['lcpImage'] = array(
				'url'      => esc_url_raw( $p['lcpImage']['url'] ),
				'srcset'   => sanitize_text_field( isset( $p['lcpImage']['srcset'] ) ? $p['lcpImage']['srcset'] : '' ),
				'sizes'    => sanitize_text_field( isset( $p['lcpImage']['sizes'] ) ? $p['lcpImage']['sizes'] : '' ),
				'loading'  => sanitize_key( isset( $p['lcpImage']['loading'] ) ? $p['lcpImage']['loading'] : '' ),
				'priority' => sanitize_key( isset( $p['lcpImage']['priority'] ) ? $p['lcpImage']['priority'] : '' ),
			);
		}
		update_option( 'plakette_measure', $data, false );
		return rest_ensure_response( self::state( Plakette_Auditor::refresh( 'speed_lcp' ) ) );
	}

	/** Second opinion from Google PageSpeed Insights (Lighthouse + real Chrome user data). */
	public static function psi( WP_REST_Request $request ) {
		$key = $request->get_param( 'key' );
		if ( null !== $key ) {
			$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );
			update_option( 'plakette_psi_key', $key, false );
		}
		$key  = (string) get_option( 'plakette_psi_key', '' );
		$url = add_query_arg(
			array(
				'url'      => rawurlencode( home_url( '/' ) ),
				'strategy' => 'mobile',
				'locale'   => 'de',
			),
			'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
		);
		$url .= '&category=performance&category=seo' . ( $key ? '&key=' . rawurlencode( $key ) : '' );
		$res  = wp_remote_get( $url, array( 'timeout' => 90 ) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'plakette_psi', sprintf( __( 'Google PageSpeed war nicht erreichbar: %s', 'plakette' ), $res->get_error_message() ), array( 'status' => 502 ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || empty( $body['lighthouseResult'] ) ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : '';
			if ( 429 === wp_remote_retrieve_response_code( $res ) ) {
				$msg = $key
					? __( 'Dein Google-Schlüssel hat sein Tageskontingent aufgebraucht. Versuch es morgen wieder oder öffne die Prüfung direkt bei Google.', 'plakette' )
					: __( 'Ohne eigenen Schlüssel lässt Google kaum noch Abfragen zu. Öffne die Prüfung direkt bei Google oder trage unten einen kostenlosen Schlüssel ein.', 'plakette' );
			} elseif ( 400 === wp_remote_retrieve_response_code( $res ) && false !== stripos( $msg, 'API key' ) ) {
				$msg = __( 'Google hat den Schlüssel nicht angenommen. Prüfe, ob du ihn vollständig kopiert hast.', 'plakette' );
			} elseif ( false !== stripos( $msg, 'unable to resolve' ) || false !== stripos( $msg, 'FAILED_DOCUMENT_REQUEST' ) || false !== stripos( $msg, 'ERRORED_DOCUMENT_REQUEST' ) ) {
				$msg = __( 'Google kann deine Website nicht erreichen. Das klappt nur bei Websites, die öffentlich im Internet stehen.', 'plakette' );
			}
			return new WP_Error( 'plakette_psi', $msg ? $msg : __( 'Google PageSpeed hat keine Auswertung geliefert.', 'plakette' ), array( 'status' => 502 ) );
		}
		$lh     = $body['lighthouseResult'];
		$audit  = function ( $key ) use ( $lh ) {
			return isset( $lh['audits'][ $key ]['numericValue'] ) ? (float) $lh['audits'][ $key ]['numericValue'] : null;
		};
		$field  = array();
		if ( ! empty( $body['loadingExperience']['metrics'] ) ) {
			foreach ( $body['loadingExperience']['metrics'] as $key => $metric ) {
				$field[ sanitize_key( $key ) ] = array(
					'p75'      => isset( $metric['percentile'] ) ? $metric['percentile'] : null,
					'category' => isset( $metric['category'] ) ? sanitize_key( $metric['category'] ) : '',
				);
			}
		}
		$data = array(
			'time'        => time(),
			'performance' => isset( $lh['categories']['performance']['score'] ) ? (int) round( 100 * $lh['categories']['performance']['score'] ) : null,
			'seo'         => isset( $lh['categories']['seo']['score'] ) ? (int) round( 100 * $lh['categories']['seo']['score'] ) : null,
			'lcp'         => $audit( 'largest-contentful-paint' ),
			'cls'         => $audit( 'cumulative-layout-shift' ),
			'tbt'         => $audit( 'total-blocking-time' ),
			'fcp'         => $audit( 'first-contentful-paint' ),
			'field'       => $field,
		);
		update_option( 'plakette_psi', $data, false );
		return rest_ensure_response( self::state() );
	}
}
