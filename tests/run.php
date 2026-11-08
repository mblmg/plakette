<?php
/**
 * Test driver: /pst-tests/run.php?step=audit|fixall|undoall|state
 * Runs the plugin the same way the REST API does and prints a compact JSON summary.
 */
require __DIR__ . '/../wp-load.php';
wp_set_current_user( 1 );
header( 'Content-Type: application/json; charset=utf-8' );
$step = isset( $_GET['step'] ) ? $_GET['step'] : 'audit';
$out  = array( 'step' => $step );

function pst_summary( $report ) {
	$rows = array();
	foreach ( $report['checks'] as $c ) {
		$rows[] = sprintf( '%-5s %-26s %-6s %s%s%s', $c['status'], $c['id'], $c['kind'], $c['detail'], empty( $c['items'] ) ? '' : ' [items:' . count( $c['items'] ) . ']', empty( $c['blocked'] ) ? '' : ' {blocked: ' . $c['blocked'] . '}' );
	}
	return array( 'scores' => $report['scores'], 'home' => $report['home'], 'rows' => $rows );
}

if ( 'audit' === $step ) {
	$t   = microtime( true );
	$out = array_merge( $out, pst_summary( Pruefstand_Auditor::run() ) );
	$out['seconds'] = round( microtime( true ) - $t, 1 );
} elseif ( 'fixall' === $step ) {
	$report = get_option( Pruefstand_Auditor::OPTION );
	foreach ( $report['checks'] as $c ) {
		if ( ! in_array( $c['status'], array( 'fail', 'warn' ), true ) || ! empty( $c['blocked'] ) || in_array( $c['kind'], array( 'letter', 'none' ), true ) ) {
			continue;
		}
		if ( isset( $_GET['only'] ) && $_GET['only'] !== $c['id'] ) {
			continue;
		}
		$req = array( 'value' => isset( $c['input']['value'] ) ? $c['input']['value'] : '', 'items' => array() );
		if ( 'seo_tagline' === $c['id'] ) {
			$req['value'] = 'Handgemachte Torten aus Freiburg';
		}
		foreach ( isset( $c['items'] ) ? $c['items'] : array() as $it ) {
			$req['items'][] = array( 'id' => $it['id'], 'value' => isset( $it['value'] ) ? $it['value'] : '' );
		}
		$check = Pruefstand_Auditor::get( $c['id'] );
		$undo  = array();
		$run   = 'test' . substr( md5( $c['id'] . microtime() ), 0, 8 );
		$msg   = $check->fix( $req, $undo );
		if ( is_wp_error( $msg ) ) {
			$out['fixes'][ $c['id'] ] = 'ERROR: ' . $msg->get_error_message();
			Pruefstand_Log::apply_ops( $undo );
			continue;
		}
		if ( ! Pruefstand_Log::add( $run, $c['id'], $check->title . ': ' . $msg, $undo ) ) {
			Pruefstand_Log::apply_ops( $undo );
			$out['fixes'][ $c['id'] ] = 'LOG FAILED, rolled back';
			continue;
		}
		$out['fixes'][ $c['id'] ] = $msg . ' (undo ops: ' . count( $undo ) . ')';
	}
	Pruefstand_Page_Cache::purge_all();
} elseif ( 'undoall' === $step ) {
	foreach ( Pruefstand_Log::runs( 200 ) as $run ) {
		if ( ! $run['undone'] ) {
			$r = Pruefstand_Log::undo_run( $run['run_id'] );
			$out['undo'][ $run['check_id'] ] = is_wp_error( $r ) ? 'ERROR ' . $r->get_error_message() : 'ok';
		}
	}
} elseif ( 'state' === $step ) {
	$out['modules'] = get_option( 'pruefstand_modules' );
	foreach ( array( 'blog_public', 'permalink_structure', 'blogdescription', 'wp_attachment_pages_enabled', 'site_icon', 'home' ) as $o ) {
		$out['options'][ $o ] = get_option( $o );
	}
	$out['wp_cache']   = defined( 'WP_CACHE' ) ? WP_CACHE : null;
	$out['dropin']     = file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	$out['wpconfig']   = Pruefstand_Env::wpconfig_path() ? substr( file_get_contents( Pruefstand_Env::wpconfig_path() ), 0, 160 ) : '';
	$out['front']      = get_post( get_option( 'page_on_front' ) )->post_content;
	$out['php']        = PHP_VERSION;
}
echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
