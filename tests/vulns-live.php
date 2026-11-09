<?php
/**
 * Live check against the real feed (no WordPress): fetches WPVulnerability data and runs the matcher.
 * Run: npx -y @php-wasm/cli@latest tests/vulns-live.php
 * Expect: the old release is flagged with its CVE(s), the current release from wordpress.org is not.
 */
define( 'PLAKETTE_VULNS_TEST', true );
require __DIR__ . '/../src/plakette/includes/class-vulns.php';

$fail  = 0;
$cases = array(
	// slug, old vulnerable version, CVE that must be among the matches
	array( 'contact-form-7', '5.3.1', 'CVE-2020-35489' ),
	array( 'elementor', '3.6.0', 'CVE-2022-1329' ),
	array( 'wp-gdpr-compliance', '2.0.23', 'CVE-2024-33682' ),
);
foreach ( $cases as $case ) {
	list( $slug, $old, $cve ) = $case;
	$json = json_decode( (string) file_get_contents( Plakette_Vulns::API . 'plugin/' . $slug . '/' ), true );
	$list = array_map( array( 'Plakette_Vulns', 'compact' ), (array) ( $json['data']['vulnerability'] ?? array() ) );
	$info = json_decode( (string) @file_get_contents( 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . $slug . '&request[fields][versions]=0' ), true );
	$cur  = isset( $info['version'] ) ? $info['version'] : null;
	$hit  = array_values( array_filter( $list, fn( $v ) => Plakette_Vulns::affects( $old, $v['op'] ) ) );
	$cves = array_merge( ...array_map( fn( $v ) => $v['cves'], $hit ?: array( array( 'cves' => array() ) ) ) );
	echo "{$slug}: feed entries " . count( $list ) . ", installed {$old} → " . count( $hit ) . ' match(es): ' . implode( ', ', array_slice( array_unique( $cves ), 0, 8 ) ) . ( count( array_unique( $cves ) ) > 8 ? ' …' : '' ) . "\n";
	foreach ( array_slice( $hit, 0, 3 ) as $v ) {
		echo '   ' . $v['title'] . ' | CVSS ' . ( $v['score'] ?? '–' ) . ' | ' . json_encode( Plakette_Vulns::fix_version( $v['op'] ) ) . "\n";
	}
	$d = Plakette_Vulns::decide( $hit, $cur, (bool) $cur );
	echo "   needed fix: " . json_encode( $d['fix'] ) . ", action with wordpress.org offer " . var_export( $cur, true ) . ": {$d['action']}\n";
	if ( ! in_array( $cve, $cves, true ) ) {
		echo "   FAIL: {$cve} not matched\n";
		++$fail;
	}
	if ( $cur ) {
		$now = array_filter( $list, fn( $v ) => Plakette_Vulns::affects( $cur, $v['op'] ) );
		echo "   current {$cur} → " . count( $now ) . " match(es)\n";
		if ( $now ) {
			echo '   NOTE: current release still listed: ' . implode( '; ', array_map( fn( $v ) => $v['title'], $now ) ) . "\n";
		}
	} else {
		echo "   (not on wordpress.org any more – closed: " . var_export( ! empty( $json['data']['closed'] ), true ) . ")\n";
	}
}
echo $fail ? "FAILED\n" : "OK\n";
exit( $fail ? 1 : 0 );
