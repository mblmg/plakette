<?php
/** Debug helper: /plk-tests/vulns-probe.php – times the vulnerability check alone and prints its result. */
require __DIR__ . '/../wp-load.php';
wp_set_current_user( 1 );
header( 'Content-Type: application/json; charset=utf-8' );
$t = microtime( true );
$r = Plakette_Auditor::evaluate( Plakette_Auditor::get( 'security_vulnerabilities' ), new Plakette_Context() );
echo wp_json_encode( array( 'seconds' => round( microtime( true ) - $t, 2 ), 'status' => $r['status'], 'detail' => $r['detail'], 'items' => $r['items'] ?? array(), 'state' => array_diff_key( (array) Plakette_Vulns::state(), array( 'found' => 1 ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
