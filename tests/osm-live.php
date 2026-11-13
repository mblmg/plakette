<?php
// Run: wp eval-file tests/osm-live.php (staging with define( 'PLAKETTE_OSM_DEV', true ) and a connected account).
// Live test against master.apis.dev.openstreetmap.org with the account connected in staging (PLAKETTE_OSM_DEV).
if ( ! Plakette_Osm_Profile::dev() || ! Plakette_Osm_Edit::account() ) { exit( "not in dev mode or not connected\n" ); }
$api = Plakette_Osm_Profile::api(); $tok = Plakette_Osm_Edit::account()['token'];
$req = function ( $m, $path, $body = null ) use ( $api, $tok ) {
	$r = wp_remote_request( $api . $path, array( 'method' => $m, 'timeout' => 30, 'headers' => array_filter( array( 'Authorization' => 'Bearer ' . $tok, 'Content-Type' => $body ? 'text/xml' : null ) ), 'body' => $body ) );
	if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) { throw new Exception( "$m $path: " . ( is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_response_code( $r ) . ' ' . wp_remote_retrieve_body( $r ) ) ); }
	return wp_remote_retrieve_body( $r ); };
$cs = fn( $c ) => (int) $req( 'PUT', 'changeset/create', '<osm><changeset><tag k="comment" v="' . esc_attr( $c ) . '"/><tag k="created_by" v="Plakette test"/></changeset></osm>' );
$tags = fn( $id ) => json_decode( $req( 'GET', "node/$id.json" ), true )['elements'][0];
$ok = 0; $fail = 0; $t = function ( $name, $cond, $info = '' ) use ( &$ok, &$fail ) { $cond ? $ok++ : $fail++; echo ( $cond ? 'PASS ' : 'FAIL ' ), $name, $info ? " – $info" : '', "\n"; };
$opt = Plakette_Settings::OPTION; $backup = get_option( $opt ); $node = 0;
try {
	echo 'connected as ', Plakette_Osm_Edit::account()['user'], " on $api\n";
	$orig = array( 'name' => 'Plakette Testladen', 'shop' => 'books', 'addr:street' => 'Kunzenweg', 'addr:housenumber' => '26', 'addr:postcode' => '79117', 'addr:city' => 'Freiburg im Breisgau', 'contact:phone' => '+49 761 11111', 'opening_hours' => 'Mo-Fr 09:00-18:00; Sa 09:00-13:00; PH off', 'wheelchair' => 'limited' );
	$c = $cs( 'Plakette: Testobjekt anlegen' ); $x = '<osm><node changeset="' . $c . '" lat="47.9805753" lon="7.8922356">';
	foreach ( $orig as $k => $v ) { $x .= '<tag k="' . esc_attr( $k ) . '" v="' . esc_attr( $v ) . '"/>'; }
	$node = (int) $req( 'PUT', 'node/create', $x . '</node></osm>' ); $req( 'PUT', "changeset/$c/close", '' );
	echo "test node: {$api}node/$node\n";
	Plakette_Settings::save( 'company', array( 'name' => 'Plakette Testladen GmbH', 'street' => 'Kunzenweg 26', 'zip' => '79117', 'city' => 'Freiburg im Breisgau', 'country' => 'DE', 'phone' => '0761 22222', 'opening_hours' => "Mo-Fr 9-18\nSa 9-14", 'osm_url' => Plakette_Osm_Profile::link( 'node', $node ) ) );
	$check = new Plakette_Check_Osm_Profile(); $ctx = new Plakette_Context();
	$r = $check->run( $ctx ); $ids = wp_list_pluck( $r['items'] ?? array(), 'id' );
	$t( 'report offers direct edits', in_array( 'edit:opening_hours', $ids, true ) && in_array( 'edit:phone', $ids, true ), implode( ',', $ids ) );
	$t( 'name is never edited', ! in_array( 'edit:name', $ids, true ) );
	$undo = array(); $msg = $check->fix( array( 'items' => array( array( 'id' => 'edit:opening_hours' ), array( 'id' => 'edit:phone' ) ) ), $undo );
	$t( 'fix succeeds', ! is_wp_error( $msg ), is_wp_error( $msg ) ? $msg->get_error_message() : $msg );
	$e = $tags( $node );
	$t( 'opening_hours written, PH kept', 'Mo-Fr 09:00-18:00; Sa 09:00-14:00; PH off' === ( $e['tags']['opening_hours'] ?? '' ), $e['tags']['opening_hours'] ?? '' );
	$t( 'phone in existing key contact:phone', '+49 761 22222' === ( $e['tags']['contact:phone'] ?? '' ) && ! isset( $e['tags']['phone'] ) );
	$t( 'check_date:opening_hours set', wp_date( 'Y-m-d' ) === ( $e['tags']['check_date:opening_hours'] ?? '' ) );
	$t( 'other tags untouched', 'limited' === $e['tags']['wheelchair'] && 'Plakette Testladen' === $e['tags']['name'] && 2 === $e['version'] );
	$t( 'position untouched', abs( $e['lat'] - 47.9805753 ) < 1e-7 && abs( $e['lon'] - 7.8922356 ) < 1e-7 );
	$csx = $req( 'GET', 'changeset/' . $e['changeset'] );
	$t( 'changeset comment + created_by + closed', str_contains( $csx, 'Öffnungszeiten, Telefon von „Plakette Testladen“' ) && str_contains( $csx, 'Plakette ' . PLAKETTE_VERSION ) && str_contains( $csx, 'open="false"' ) );
	$r = $check->run( $ctx ); $t( 'report passes afterwards', 'pass' === $r['status'], $r['status'] . ' ' . $r['detail'] );
	$err = Plakette_Log::apply_ops( $undo ); $e = $tags( $node );
	$t( 'undo restores original tags', ! $err && $e['tags'] == $orig, wp_json_encode( $err ) );
	// Conflict: someone else changes the hours after the report.
	delete_transient( Plakette_Osm_Profile::CACHE . 'el_node' . $node ); $check->run( $ctx );
	$c = $cs( 'Plakette-Test: fremde Änderung' ); $x = $req( 'GET', "node/$node" ); $x = str_replace( array( 'Mo-Fr 09:00-18:00; Sa 09:00-13:00; PH off', 'changeset="' . $e['changeset'] . '"' ), array( 'Mo-Fr 08:00-18:00', 'changeset="' . $c . '"' ), $x );
	$x = preg_replace( '/changeset="\d+"/', 'changeset="' . $c . '"', $x ); $req( 'PUT', "node/$node", $x ); $req( 'PUT', "changeset/$c/close", '' );
	$undo = array(); $msg = $check->fix( array( 'items' => array( array( 'id' => 'edit:opening_hours' ) ) ), $undo );
	$t( 'edit refused after foreign change', is_wp_error( $msg ) && 'plakette_osm_conflict' === $msg->get_error_code(), is_wp_error( $msg ) ? $msg->get_error_message() : 'no error!' );
	// Undo refused after a foreign edit.
	delete_transient( Plakette_Osm_Profile::CACHE . 'el_node' . $node ); $check->run( $ctx );
	$undo = array(); $check->fix( array( 'items' => array( array( 'id' => 'edit:opening_hours' ) ) ), $undo );
	$c = $cs( 'Plakette-Test: fremde Änderung 2' ); $x = preg_replace( '/changeset="\d+"/', 'changeset="' . $c . '"', $req( 'GET', "node/$node" ) ); $x = str_replace( 'v="limited"', 'v="yes"', $x ); $req( 'PUT', "node/$node", $x ); $req( 'PUT', "changeset/$c/close", '' );
	$err = Plakette_Log::apply_ops( $undo ); $e = $tags( $node );
	$t( 'undo refused after foreign edit, nothing overwritten', $err && 'yes' === $e['tags']['wheelchair'], $err[0] ?? '' );
	// Authenticated note for a missing business (dev server).
	$n = Plakette_Osm_Profile::send_note( 47.9805, 7.8922, 'Plakette-Test (bitte ignorieren)', 'test', array( 'new' ) );
	$nj = is_wp_error( $n ) ? array() : json_decode( $req( 'GET', 'notes/' . $n['id'] . '.json' ), true );
	$t( 'note carries the account name', ( $nj['properties']['comments'][0]['user'] ?? '' ) === Plakette_Osm_Edit::account()['user'], $nj['properties']['comments'][0]['user'] ?? 'anonymous' );
} catch ( Throwable $ex ) { $fail++; echo 'FAIL exception: ', $ex->getMessage(), "\n"; }
finally {
	if ( $node ) { try { $c = $cs( 'Plakette-Test: Testobjekt löschen' ); $x = preg_replace( '/changeset="\d+"/', 'changeset="' . $c . '"', $req( 'GET', "node/$node" ) ); $req( 'DELETE', "node/$node", $x ); $req( 'PUT', "changeset/$c/close", '' ); echo "test node deleted\n"; } catch ( Throwable $ex ) { echo 'cleanup failed: ', $ex->getMessage(), "\n"; } }
	update_option( $opt, $backup, false ); delete_option( Plakette_Osm_Profile::PLACE ); delete_option( Plakette_Osm_Profile::NOTES );
	echo "settings restored\n$ok passed, $fail failed\n";
}
