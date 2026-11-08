<?php
/**
 * Runs all checks, scores the result and stores the report.
 */

defined( 'ABSPATH' ) || exit;

class Pruefstand_Auditor {

	const OPTION = 'pruefstand_report';

	/** Order = order in the report. */
	public static function classes() {
		return apply_filters(
			'pruefstand_checks',
			array(
				'Pruefstand_Check_Visibility',
				'Pruefstand_Check_Https',
				'Pruefstand_Check_Permalinks',
				'Pruefstand_Check_Sitemap',
				'Pruefstand_Check_Robots',
				'Pruefstand_Check_Tagline',
				'Pruefstand_Check_Site_Icon',
				'Pruefstand_Check_Attachment_Pages',
				'Pruefstand_Check_Home_Title',
				'Pruefstand_Check_Home_Description',
				'Pruefstand_Check_Social',
				'Pruefstand_Check_Schema',
				'Pruefstand_Check_Canonical',
				'Pruefstand_Check_Post_Descriptions',
				'Pruefstand_Check_Alt_Text',
				'Pruefstand_Check_Titles',
				'Pruefstand_Check_Content_H1',
				'Pruefstand_Check_Broken_Links',
				'Pruefstand_Check_Sample_Content',
				'Pruefstand_Check_Thin_Content',
				'Pruefstand_Check_Page_Cache',
				'Pruefstand_Check_Compression',
				'Pruefstand_Check_Browser_Cache',
				'Pruefstand_Check_Php_Version',
				'Pruefstand_Check_Database',
				'Pruefstand_Check_Object_Cache',
				'Pruefstand_Check_Large_Images',
				'Pruefstand_Check_Lcp_Image',
				'Pruefstand_Check_Webp',
				'Pruefstand_Check_Render_Blocking',
				'Pruefstand_Check_Google_Fonts',
				'Pruefstand_Check_Emoji',
				'Pruefstand_Check_Jquery_Migrate',
				'Pruefstand_Check_Dashicons',
			)
		);
	}

	/** @return Pruefstand_Check|null */
	public static function get( $id ) {
		foreach ( self::classes() as $class ) {
			if ( class_exists( $class ) ) {
				$check = new $class();
				if ( $check->id === $id ) {
					return $check;
				}
			}
		}
		return null;
	}

	public static function evaluate( Pruefstand_Check $check, Pruefstand_Context $ctx ) {
		try {
			$result = $check->run( $ctx );
		} catch ( Throwable $e ) {
			$result = array( 'status' => 'skip', 'detail' => sprintf( __( 'Prüfung fehlgeschlagen: %s', 'pruefstand' ), $e->getMessage() ) );
		}
		// The result may override meta fields (kind, fix_label, weight).
		$merged = array_merge( $check->meta(), $result );
		if ( 'letter' === $merged['kind'] && 'letter' !== $check->kind && empty( $result['fix_does'] ) ) {
			$merged['fix_label'] = __( 'Nachricht an den Hoster', 'pruefstand' );
			$merged['fix_does']  = __( 'Das kann auf deinem Server nur dein Hoster ändern. Prüfstand hat eine fertige Nachricht vorbereitet: Kopiere sie und schicke sie per E-Mail oder Kontaktformular an deinen Hoster.', 'pruefstand' );
		}
		return $merged;
	}

	public static function run() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$ctx    = new Pruefstand_Context();
		$checks = array();
		foreach ( self::classes() as $class ) {
			if ( class_exists( $class ) ) {
				$checks[] = self::evaluate( new $class(), $ctx );
			}
		}
		$home   = $ctx->home();
		$report = array(
			'time'         => time(),
			'site'         => home_url( '/' ),
			'name'         => get_bloginfo( 'name' ),
			'home'         => array(
				'ok'            => $ctx->home_ok(),
				'status'        => isset( $home['status'] ) ? $home['status'] : 0,
				'error'         => isset( $home['error'] ) ? $home['error'] : '',
				'time_ms'       => isset( $home['time_ms'] ) ? $home['time_ms'] : 0,
				'time_ms_first' => isset( $home['time_ms_first'] ) ? $home['time_ms_first'] : 0,
				'bytes'         => isset( $home['facts']['bytes'] ) ? $home['facts']['bytes'] : 0,
				'dom'           => isset( $home['facts']['dom_nodes'] ) ? $home['facts']['dom_nodes'] : 0,
			),
			'seo_plugin'   => Pruefstand_Env::seo_plugin(),
			'cache_plugin' => Pruefstand_Env::cache_plugin(),
			'checks'       => $checks,
		);
		$report['scores'] = self::scores( $checks );
		update_option( self::OPTION, $report, false );
		self::remember_score( $report['scores'] );
		return $report;
	}

	/** Re-run a single check (after a fix) and update the stored report. */
	public static function refresh( $id ) {
		$report = get_option( self::OPTION );
		$check  = self::get( $id );
		if ( ! $check ) {
			return $report;
		}
		$result = self::evaluate( $check, new Pruefstand_Context() );
		if ( ! is_array( $report ) || empty( $report['checks'] ) ) {
			return self::run();
		}
		foreach ( $report['checks'] as $i => $c ) {
			if ( $c['id'] === $id ) {
				$report['checks'][ $i ] = $result;
			}
		}
		$report['scores'] = self::scores( $report['checks'] );
		update_option( self::OPTION, $report, false );
		return $report;
	}

	public static function scores( array $checks ) {
		$sum = array(
			'seo'   => array( 0, 0 ),
			'speed' => array( 0, 0 ),
		);
		$count = array( 'fail' => 0, 'warn' => 0, 'pass' => 0, 'skip' => 0 );
		foreach ( $checks as $c ) {
			$status = isset( $c['status'] ) ? $c['status'] : 'skip';
			$count[ $status ] = isset( $count[ $status ] ) ? $count[ $status ] + 1 : 1;
			if ( 'skip' === $status ) {
				continue;
			}
			$w                      = (int) $c['weight'];
			$sum[ $c['area'] ][1]  += $w;
			$sum[ $c['area'] ][0]  += 'pass' === $status ? $w : ( 'warn' === $status ? $w / 2 : 0 );
		}
		$pct = function ( $pair ) {
			return $pair[1] ? (int) round( 100 * $pair[0] / $pair[1] ) : 100;
		};
		return array(
			'seo'   => $pct( $sum['seo'] ),
			'speed' => $pct( $sum['speed'] ),
			'total' => $pct( array( $sum['seo'][0] + $sum['speed'][0], $sum['seo'][1] + $sum['speed'][1] ) ),
			'count' => $count,
		);
	}

	/** Keep a short history so the user sees progress over time. */
	private static function remember_score( $scores ) {
		$history   = (array) get_option( 'pruefstand_history', array() );
		$history[] = array( 't' => time(), 'seo' => $scores['seo'], 'speed' => $scores['speed'], 'total' => $scores['total'] );
		update_option( 'pruefstand_history', array_slice( $history, -30 ), false );
	}
}
