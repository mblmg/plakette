<?php
/**
 * Runs all checks, scores the result and stores the report.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Auditor {

	const OPTION = 'plakette_report';

	/** Report areas: key => label. Order = order of tabs and meters. */
	public static function areas() {
		return array(
			'legal'    => __( 'Rechtliches', 'plakette' ),
			'security' => __( 'Sicherheit', 'plakette' ),
			'speed'    => __( 'Geschwindigkeit', 'plakette' ),
			'seo'      => __( 'SEO', 'plakette' ),
		);
	}

	/**
	 * Reports and score history stored by versions before 1.2.0 used the areas privacy + security (legal checks were
	 * part of "security"). Sorts the stored checks into the current areas and recalculates the scores – once.
	 */
	public static function migrate() {
		if ( '2' === get_option( 'plakette_areas_version' ) ) {
			return;
		}
		update_option( 'plakette_areas_version', '2', false );
		$report = get_option( self::OPTION );
		if ( is_array( $report ) && ! empty( $report['checks'] ) ) {
			$area = array();
			foreach ( self::classes() as $class ) {
				if ( class_exists( $class ) ) {
					$check              = new $class();
					$area[ $check->id ] = $check->area;
				}
			}
			foreach ( $report['checks'] as $i => $c ) {
				if ( isset( $c['id'], $area[ $c['id'] ] ) ) {
					$report['checks'][ $i ]['area'] = $area[ $c['id'] ];
				}
			}
			$report['scores'] = self::scores( $report['checks'] );
			update_option( self::OPTION, $report, false );
		}
		$history = get_option( 'plakette_history' );
		if ( is_array( $history ) ) {
			foreach ( $history as $i => $entry ) {
				if ( is_array( $entry ) && isset( $entry['privacy'] ) ) {
					// Not comparable with the new area – the trend starts again with the next audit.
					unset( $history[ $i ]['privacy'], $history[ $i ]['security'] );
				}
			}
			update_option( 'plakette_history', $history, false );
		}
	}

	/** Base checks; the files checks-*.php add theirs through the filter "plakette_checks". */
	public static function classes() {
		static $list = null;
		if ( null === $list ) {
			$list = array_values( array_unique( (array) apply_filters( 'plakette_checks', array() ) ) );
		}
		return $list;
	}

	/** @return Plakette_Check|null */
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

	public static function evaluate( Plakette_Check $check, Plakette_Context $ctx ) {
		try {
			$result = $check->run( $ctx );
		} catch ( Throwable $e ) {
			$result = array( 'status' => 'skip', 'detail' => sprintf( __( 'Prüfung fehlgeschlagen: %s', 'plakette' ), $e->getMessage() ) );
		}
		// The result may override meta fields (kind, fix_label, weight).
		$merged = array_merge( $check->meta(), $result );
		if ( 'letter' === $merged['kind'] && 'letter' !== $check->kind && empty( $result['fix_does'] ) ) {
			$merged['fix_label'] = __( 'Nachricht an den Hoster', 'plakette' );
			$merged['fix_does']  = __( 'Das kann auf deinem Server nur dein Hoster ändern. Plakette hat eine fertige Nachricht vorbereitet: Kopiere sie und schicke sie per E-Mail oder Kontaktformular an deinen Hoster.', 'plakette' );
		}
		return $merged;
	}

	public static function run() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$ctx    = new Plakette_Context();
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
			'seo_plugin'   => Plakette_Env::seo_plugin(),
			'cache_plugin' => Plakette_Env::cache_plugin(),
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
		$result = self::evaluate( $check, new Plakette_Context() );
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
		$sum = array();
		foreach ( array_keys( self::areas() ) as $area ) {
			$sum[ $area ] = array( 0, 0 );
		}
		$count = array( 'fail' => 0, 'warn' => 0, 'pass' => 0, 'skip' => 0 );
		foreach ( $checks as $c ) {
			$status = isset( $c['status'] ) ? $c['status'] : 'skip';
			$count[ $status ] = isset( $count[ $status ] ) ? $count[ $status ] + 1 : 1;
			if ( 'skip' === $status || ! isset( $sum[ $c['area'] ] ) ) {
				continue;
			}
			$w                     = (int) $c['weight'];
			$sum[ $c['area'] ][1] += $w;
			$sum[ $c['area'] ][0] += 'pass' === $status ? $w : ( 'warn' === $status ? $w / 2 : 0 );
		}
		$pct    = function ( $pair ) {
			return $pair[1] ? (int) round( 100 * $pair[0] / $pair[1] ) : 100;
		};
		$scores = array();
		$all    = array( 0, 0 );
		foreach ( $sum as $area => $pair ) {
			$scores[ $area ] = $pct( $pair );
			$all[0]         += $pair[0];
			$all[1]         += $pair[1];
		}
		$scores['total'] = $pct( $all );
		$scores['count'] = $count;
		return $scores;
	}

	/** Keep a short history so the user sees progress over time. */
	private static function remember_score( $scores ) {
		$history   = (array) get_option( 'plakette_history', array() );
		$entry     = array( 't' => time() );
		foreach ( $scores as $key => $value ) {
			if ( is_int( $value ) ) {
				$entry[ $key ] = $value;
			}
		}
		$history[] = $entry;
		update_option( 'plakette_history', array_slice( $history, -30 ), false );
	}
}
