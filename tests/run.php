<?php
/**
 * Test driver: /plk-tests/run.php?step=audit|fixall|undoall|state
 * Runs the plugin the same way the REST API does and prints a compact JSON summary.
 */
require __DIR__ . '/../wp-load.php';
wp_set_current_user( 1 );
header( 'Content-Type: application/json; charset=utf-8' );
$step = isset( $_GET['step'] ) ? $_GET['step'] : 'audit';
$out  = array( 'step' => $step );

// Never write to the real OpenStreetMap from tests: notes go to the OSM development server.
add_filter( 'plakette_osm_notes_api', fn() => 'https://master.apis.dev.openstreetmap.org/api/0.6/' );

function plk_summary( $report ) {
	$rows = array();
	foreach ( $report['checks'] as $c ) {
		$rows[] = sprintf( '%-5s %-26s %-6s %s%s%s', $c['status'], $c['id'], $c['kind'], $c['detail'], empty( $c['items'] ) ? '' : ' [items:' . count( $c['items'] ) . ']', empty( $c['blocked'] ) ? '' : ' {blocked: ' . $c['blocked'] . '}' );
	}
	return array( 'scores' => $report['scores'], 'home' => $report['home'], 'rows' => $rows );
}

if ( 'audit' === $step ) {
	$t   = microtime( true );
	$out = array_merge( $out, plk_summary( Plakette_Auditor::run() ) );
	$out['seconds'] = round( microtime( true ) - $t, 1 );
} elseif ( 'fixall' === $step ) {
	$report = get_option( Plakette_Auditor::OPTION );
	foreach ( $report['checks'] as $c ) {
		if ( ! in_array( $c['status'], array( 'fail', 'warn' ), true ) || ! empty( $c['blocked'] ) || in_array( $c['kind'], array( 'letter', 'none' ), true ) ) {
			continue;
		}
		if ( isset( $_GET['only'] ) && $_GET['only'] !== $c['id'] ) {
			continue;
		}
		$req = array( 'value' => isset( $c['input']['value'] ) ? $c['input']['value'] : '', 'items' => array() );
		if ( 'form' === $c['kind'] && ! empty( $c['form']['fields'] ) ) {
			// Fill forms like a user would: keep given values, complete empty ones from test data.
			$test = array(
				'name' => 'Konditorei Sonnenschein GmbH', 'legal_form' => 'gmbh', 'owner' => 'Erika Sonnenschein (Geschäftsführerin)',
				'street' => 'Münsterplatz 7', 'zip' => '79098', 'city' => 'Freiburg im Breisgau', 'country' => 'DE',
				'phone' => '+49 761 123456', 'email' => 'info@konditorei-sonnenschein.example', 'vat_id' => 'DE123456789',
				'register' => 'Amtsgericht Freiburg, HRB 712345', 'opening_hours' => "Di-Fr 9-18\nSa 8-14",
				'gbp_url' => 'https://maps.app.goo.gl/TestKonditorei',
			);
			$req['value'] = array();
			foreach ( $c['form']['fields'] as $f ) {
				$v = isset( $f['value'] ) ? $f['value'] : '';
				$req['value'][ $f['key'] ] = ( '' === $v || null === $v ) && isset( $test[ $f['key'] ] ) ? $test[ $f['key'] ] : $v;
			}
		}
		if ( 'seo_tagline' === $c['id'] ) {
			$req['value'] = 'Handgemachte Torten aus Freiburg';
		}
		foreach ( isset( $c['items'] ) ? $c['items'] : array() as $it ) {
			$req['items'][] = array( 'id' => $it['id'], 'value' => isset( $it['value'] ) ? $it['value'] : '' );
		}
		$check = Plakette_Auditor::get( $c['id'] );
		$undo  = array();
		$run   = 'test' . substr( md5( $c['id'] . microtime() ), 0, 8 );
		$msg   = $check->fix( $req, $undo );
		if ( is_wp_error( $msg ) ) {
			$out['fixes'][ $c['id'] ] = 'ERROR: ' . $msg->get_error_message();
			Plakette_Log::apply_ops( $undo );
			continue;
		}
		if ( ! Plakette_Log::add( $run, $c['id'], $check->title . ': ' . $msg, $undo ) ) {
			Plakette_Log::apply_ops( $undo );
			$out['fixes'][ $c['id'] ] = 'LOG FAILED, rolled back';
			continue;
		}
		$out['fixes'][ $c['id'] ] = $msg . ' (undo ops: ' . count( $undo ) . ')';
	}
	Plakette_Page_Cache::purge_all();
} elseif ( 'undoall' === $step ) {
	foreach ( Plakette_Log::runs( 200 ) as $run ) {
		if ( ! $run['undone'] ) {
			$r = Plakette_Log::undo_run( $run['run_id'] );
			$out['undo'][ $run['check_id'] ] = is_wp_error( $r ) ? 'ERROR ' . $r->get_error_message() : 'ok';
		}
	}
} elseif ( 'features' === $step ) {
	// Fixed features ("Feste Funktionen", docs/DEVELOPING.md): must never get lost in a rewrite. 'missing' must stay empty.
	$missing = array();
	$need    = function ( $ok, $what ) use ( &$missing ) {
		if ( ! $ok ) {
			$missing[] = $what;
		}
	};
	// --- preload options (Einstellungen › Bilder und lokale Kopien)
	$fields = array();
	foreach ( Plakette_Settings::state() as $sec ) {
		foreach ( $sec['fields'] as $f ) {
			$fields[ $sec['id'] . '.' . $f['key'] ] = $f['type'];
		}
	}
	foreach ( array( 'performance.preload_fonts' => 'toggle', 'performance.preload_fonts_max' => 'number', 'performance.preload_cdn' => 'toggle', 'performance.preload_extra' => 'textarea', 'stats.enabled' => 'toggle' ) as $key => $type ) {
		$need( isset( $fields[ $key ] ) && $type === $fields[ $key ], 'setting ' . $key );
	}
	$before = get_option( Plakette_Settings::OPTION );
	Plakette_Settings::save( 'performance', array( 'preload_fonts' => false, 'preload_cdn' => true, 'preload_extra' => "/wp-content/uploads/a.woff2\nhttps://fremd.example/b.js" ) );
	$vendor = wp_get_upload_dir()['baseurl'] . '/' . Plakette_Performance::DIR_NAME . '/x-lib.min.js';
	$html   = Plakette_Preload::transform( '<html><head><meta charset="utf-8"></head><body><script src="' . $vendor . '"></script></body></html>' );
	$need( false !== strpos( $html, 'a.woff2" as="font" type="font/woff2" crossorigin' ), 'preload of extra font' );
	$need( false !== strpos( $html, 'x-lib.min.js" as="script"' ), 'preload of local CDN file' );
	$need( false === strpos( $html, 'fremd.example' ), 'foreign hosts are never preloaded' );
	$need( false === apply_filters( 'plakette_fonts_preload', true ) || ! Plakette_Preload::options()['preload_fonts'], 'font preload can be switched off' );
	false === $before ? delete_option( Plakette_Settings::OPTION ) : update_option( Plakette_Settings::OPTION, $before, false );
	Plakette_Settings::flush_cache();
	// --- areas
	$need( array( 'legal', 'security', 'speed', 'seo' ) === array_keys( Plakette_Auditor::areas() ), 'areas legal/security/speed/seo' );
	foreach ( array( 'legal_imprint', 'legal_privacy_policy', 'privacy_consent', 'a11y_zoom' ) as $id ) {
		$c = Plakette_Auditor::get( $id );
		$need( $c && 'legal' === $c->area, $id . ' in area legal' );
	}
	$out['missing'] = $missing;
} elseif ( 'state' === $step ) {
	$out['modules'] = get_option( 'plakette_modules' );
	foreach ( array( 'blog_public', 'permalink_structure', 'blogdescription', 'wp_attachment_pages_enabled', 'site_icon', 'home' ) as $o ) {
		$out['options'][ $o ] = get_option( $o );
	}
	$out['wp_cache']   = defined( 'WP_CACHE' ) ? WP_CACHE : null;
	$out['dropin']     = file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	$out['wpconfig']   = Plakette_Env::wpconfig_path() ? substr( file_get_contents( Plakette_Env::wpconfig_path() ), 0, 160 ) : '';
	$out['front']      = get_post( get_option( 'page_on_front' ) )->post_content;
	$out['php']        = PHP_VERSION;
}
echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
