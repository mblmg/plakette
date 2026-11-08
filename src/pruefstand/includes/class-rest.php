<?php
/**
 * REST API for the admin screen. Every route requires the capability "manage_options".
 */

defined( 'ABSPATH' ) || exit;

class Pruefstand_Rest {

	const NS = 'pruefstand/v1';

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
		register_rest_route( self::NS, '/psi', $base + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'psi' ) ) );
	}

	public static function state( $report = null ) {
		return array(
			'report'  => $report ? $report : get_option( Pruefstand_Auditor::OPTION, null ),
			'measure' => get_option( 'pruefstand_measure', null ),
			'psi'     => get_option( 'pruefstand_psi', null ),
			'log'     => Pruefstand_Log::runs( 40 ),
			'history' => get_option( 'pruefstand_history', array() ),
			'psiKey'  => '' !== (string) get_option( 'pruefstand_psi_key', '' ),
		);
	}

	public static function audit() {
		return rest_ensure_response( self::state( Pruefstand_Auditor::run() ) );
	}

	public static function report() {
		return rest_ensure_response( self::state() );
	}

	public static function log() {
		return rest_ensure_response( Pruefstand_Log::runs( 40 ) );
	}

	public static function fix( WP_REST_Request $request ) {
		$id    = sanitize_key( (string) $request->get_param( 'check' ) );
		$check = Pruefstand_Auditor::get( $id );
		if ( ! $check ) {
			return new WP_Error( 'pruefstand_unknown', __( 'Unbekannte Prüfung.', 'pruefstand' ), array( 'status' => 400 ) );
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
			$message = new WP_Error( 'pruefstand_crash', $e->getMessage() );
		}
		if ( is_wp_error( $message ) ) {
			// Roll back whatever the fix did before it failed.
			Pruefstand_Log::apply_ops( $undo );
			return new WP_Error( $message->get_error_code(), $message->get_error_message(), array( 'status' => 400 ) );
		}
		if ( ! Pruefstand_Log::add( $run_id, $id, $check->title . ': ' . $message, $undo ) ) {
			// Never keep a change that could not be written to the protocol – it could not be undone later.
			Pruefstand_Log::apply_ops( $undo );
			Pruefstand_Log::install();
			return new WP_Error( 'pruefstand_log', __( 'Die Änderung konnte nicht im Protokoll gespeichert werden und wurde deshalb sofort zurückgenommen. Bitte versuch es noch einmal.', 'pruefstand' ), array( 'status' => 500 ) );
		}
		Pruefstand_Page_Cache::purge_all();

		$report = $request->get_param( 'refresh' ) === false ? get_option( Pruefstand_Auditor::OPTION ) : Pruefstand_Auditor::refresh( $id );
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
		$result = Pruefstand_Log::undo_run( $run_id );
		$check  = sanitize_key( (string) $request->get_param( 'check' ) );
		$report = $check ? Pruefstand_Auditor::refresh( $check ) : get_option( Pruefstand_Auditor::OPTION );
		$state  = self::state( $report );
		$state['message'] = is_wp_error( $result ) ? $result->get_error_message() : __( 'Rückgängig gemacht.', 'pruefstand' );
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
		update_option( 'pruefstand_measure', $data, false );
		return rest_ensure_response( self::state( Pruefstand_Auditor::refresh( 'speed_lcp' ) ) );
	}

	/** Second opinion from Google PageSpeed Insights (Lighthouse + real Chrome user data). */
	public static function psi( WP_REST_Request $request ) {
		$key = $request->get_param( 'key' );
		if ( null !== $key ) {
			$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );
			update_option( 'pruefstand_psi_key', $key, false );
		}
		$key  = (string) get_option( 'pruefstand_psi_key', '' );
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
			return new WP_Error( 'pruefstand_psi', sprintf( __( 'Google PageSpeed war nicht erreichbar: %s', 'pruefstand' ), $res->get_error_message() ), array( 'status' => 502 ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || empty( $body['lighthouseResult'] ) ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : '';
			if ( 429 === wp_remote_retrieve_response_code( $res ) ) {
				$msg = $key
					? __( 'Dein Google-Schlüssel hat sein Tageskontingent aufgebraucht. Versuch es morgen wieder oder öffne die Prüfung direkt bei Google.', 'pruefstand' )
					: __( 'Ohne eigenen Schlüssel lässt Google kaum noch Abfragen zu. Öffne die Prüfung direkt bei Google oder trage unten einen kostenlosen Schlüssel ein.', 'pruefstand' );
			} elseif ( 400 === wp_remote_retrieve_response_code( $res ) && false !== stripos( $msg, 'API key' ) ) {
				$msg = __( 'Google hat den Schlüssel nicht angenommen. Prüfe, ob du ihn vollständig kopiert hast.', 'pruefstand' );
			} elseif ( false !== stripos( $msg, 'unable to resolve' ) || false !== stripos( $msg, 'FAILED_DOCUMENT_REQUEST' ) || false !== stripos( $msg, 'ERRORED_DOCUMENT_REQUEST' ) ) {
				$msg = __( 'Google kann deine Website nicht erreichen. Das klappt nur bei Websites, die öffentlich im Internet stehen.', 'pruefstand' );
			}
			return new WP_Error( 'pruefstand_psi', $msg ? $msg : __( 'Google PageSpeed hat keine Auswertung geliefert.', 'pruefstand' ), array( 'status' => 502 ) );
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
		update_option( 'pruefstand_psi', $data, false );
		return rest_ensure_response( self::state() );
	}
}
