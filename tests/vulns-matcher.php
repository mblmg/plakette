<?php
/**
 * Unit tests for the pure vulnerability matcher (no WordPress needed).
 * Run: npx -y @php-wasm/cli@latest tests/vulns-matcher.php
 * Operator semantics: https://docs.wpvulnerability.com/openapi.yaml (#/components/schemas/Operator) – PHP version_compare().
 */
define( 'PLAKETTE_VULNS_TEST', true );
require __DIR__ . '/../src/plakette/includes/class-vulns.php';

$fail = 0;
$n    = 0;
function plk_t( $name, $got, $want ) {
	global $fail, $n;
	++$n;
	if ( $got !== $want ) {
		++$fail;
		echo "FAIL  $name: got " . var_export( $got, true ) . ', want ' . var_export( $want, true ) . "\n";
	}
}
$op = function ( $min_v, $min_o, $max_v, $max_o, $unfixed = '0' ) {
	return array( 'min_version' => $min_v, 'min_operator' => $min_o, 'max_version' => $max_v, 'max_operator' => $max_o, 'unfixed' => $unfixed, 'closed' => '0' );
};

// [installed, operator, affected?, note] – ranges taken from real feed entries.
$cases = array(
	// Contact Form 7 [contact-form-7] < 5.3.2  (CVE-2020-35489)
	array( '5.3.1', $op( null, null, '5.3.2', 'lt' ), true, 'cf7 below fix' ),
	array( '5.3.2', $op( null, null, '5.3.2', 'lt' ), false, 'cf7 exactly fixed version' ),
	array( '5.3', $op( null, null, '5.3.2', 'lt' ), true, 'two-part version' ),
	array( '6.1.3', $op( null, null, '5.3.2', 'lt' ), false, 'cf7 current' ),
	array( '5.10.0', $op( null, null, '5.3.2', 'lt' ), false, 'numeric not lexical (5.10 > 5.3)' ),
	// Elementor >= 3.6.0 - <= 3.6.2 (CVE-2022-1329)
	array( '3.6.0', $op( '3.6.0', 'ge', '3.6.2', 'le' ), true, 'elementor lower bound inclusive' ),
	array( '3.6.2', $op( '3.6.0', 'ge', '3.6.2', 'le' ), true, 'elementor upper bound inclusive' ),
	array( '3.6.3', $op( '3.6.0', 'ge', '3.6.2', 'le' ), false, 'elementor just above' ),
	array( '3.5.9', $op( '3.6.0', 'ge', '3.6.2', 'le' ), false, 'elementor just below' ),
	array( '3.6', $op( '3.6.0', 'ge', '3.6.2', 'le' ), true, '3.6 equals 3.6.0 (padding)' ),
	// Really Simple Security >= 9.0.0 - <= 9.1.1.1 (CVE-2024-10924) – four-part versions
	array( '9.1.1.1', $op( '9.0.0', 'ge', '9.1.1.1', 'le' ), true, 'four parts, upper inclusive' ),
	array( '9.1.1.2', $op( '9.0.0', 'ge', '9.1.1.1', 'le' ), false, 'four parts above' ),
	array( '9.1.2', $op( '9.0.0', 'ge', '9.1.1.1', 'le' ), false, '9.1.2 > 9.1.1.1' ),
	array( '8.3.0.1', $op( '9.0.0', 'ge', '9.1.1.1', 'le' ), false, 'older major' ),
	// Exclusive lower bound / other operators
	array( '2.0', $op( '2.0', 'gt', '3.0', 'lt' ), false, 'gt lower bound excludes equal' ),
	array( '2.0.1', $op( '2.0', 'gt', '3.0', 'lt' ), true, 'gt lower bound' ),
	array( '7.1.0', $op( null, null, '7.1.0', 'eq', '1' ), true, 'WooCommerce == 7.1.0 (unfixed): exact' ),
	array( '7.1.1', $op( null, null, '7.1.0', 'eq', '1' ), false, 'WooCommerce == 7.1.0 (unfixed): other version not affected' ),
	array( '1.0', $op( null, null, '1.0', 'ne' ), false, 'ne' ),
	array( '1.1', $op( null, null, '1.0', 'ne' ), true, 'ne other' ),
	// Unfixed
	array( '2.0.23', $op( null, null, '2.0.23', 'le', '1' ), true, 'wp-gdpr-compliance <= 2.0.23 (unfixed + closed)' ),
	array( '99.0', $op( null, null, null, null, '1' ), true, 'no bounds + unfixed = all versions' ),
	array( '1.0', $op( '1.0', 'ge', null, null, '1' ), true, 'lower bound only + unfixed' ),
	array( '0.9', $op( '1.0', 'ge', null, null, '1' ), false, 'below lower bound only' ),
	// Never guess
	array( '1.0', $op( null, null, null, null, '0' ), false, 'no range and not unfixed: undecidable' ),
	array( '', $op( null, null, '5.0', 'lt' ), false, 'empty installed version' ),
	array( '1.0', $op( null, null, '5.0', 'below' ), false, 'unknown operator' ),
	array( '1.0', $op( null, null, '5.0', null ), false, 'bound without operator' ),
	array( '1.0', $op( null, null, '5.0', 'LT' ), true, 'operator case-insensitive' ),
	array( 'v5.3.1', $op( null, null, '5.3.2', 'lt' ), true, 'leading v' ),
	array( '5.3.2-beta1', $op( null, null, '5.3.2', 'lt' ), true, 'pre-release below release' ),
	array( '5.3.2', array( 'max_version' => '5.3.2', 'max_operator' => 'le', 'unfixed' => 0 ), true, 'integer unfixed, missing min keys' ),
);
foreach ( $cases as $c ) {
	plk_t( 'affects ' . $c[3] . " ({$c[0]})", Plakette_Vulns::affects( $c[0], $c[1] ), $c[2] );
}

// fix_version
plk_t( 'fix lt', Plakette_Vulns::fix_version( $op( null, null, '5.3.2', 'lt' ) ), array( 'version' => '5.3.2', 'exact' => true ) );
plk_t( 'fix le', Plakette_Vulns::fix_version( $op( '3.6.0', 'ge', '3.6.2', 'le' ) ), array( 'version' => '3.6.2', 'exact' => false ) );
plk_t( 'fix unfixed', Plakette_Vulns::fix_version( $op( null, null, '2.0.23', 'le', '1' ) ), null );
plk_t( 'fix open', Plakette_Vulns::fix_version( $op( '1.0', 'ge', null, null ) ), null );
plk_t( 'needed max', Plakette_Vulns::needed_fix( array( $op( null, null, '5.0.4', 'lt' ), $op( null, null, '5.3.2', 'lt' ), $op( null, null, '5.1', 'lt' ) ) ), array( 'version' => '5.3.2', 'exact' => true ) );
plk_t( 'needed same version le wins', Plakette_Vulns::needed_fix( array( $op( null, null, '5.3.2', 'lt' ), $op( null, null, '5.3.2', 'le' ) ) ), array( 'version' => '5.3.2', 'exact' => false ) );
plk_t( 'needed with unfixed', Plakette_Vulns::needed_fix( array( $op( null, null, '5.3.2', 'lt' ), $op( null, null, '6.0', 'le', '1' ) ) ), null );

// decide(): what to do about a component
$m = fn( ...$ops ) => array_map( fn( $o ) => array( 'op' => Plakette_Vulns::operator( $o ) ), $ops );
plk_t( 'decide update', Plakette_Vulns::decide( $m( $op( null, null, '5.3.2', 'lt' ) ), '6.1.3', true )['action'], 'update' );
plk_t( 'decide no package', Plakette_Vulns::decide( $m( $op( null, null, '5.3.2', 'lt' ) ), '6.1.3', false )['action'], 'manual' );
plk_t( 'decide no offer but fixed exists', Plakette_Vulns::decide( $m( $op( null, null, '5.3.2', 'lt' ) ), null, false )['action'], 'manual' );
plk_t( 'decide unfixed', Plakette_Vulns::decide( $m( $op( null, null, '2.0.23', 'le', '1' ) ), null, false )['action'], 'nofix' );
plk_t( 'decide offer still affected', Plakette_Vulns::decide( $m( $op( null, null, null, null, '1' ) ), '9.0', true )['action'], 'nofix' );
plk_t( 'decide unfixed eq, offer clean', Plakette_Vulns::decide( $m( $op( null, null, '7.1.0', 'eq', '1' ) ), '10.0.0', true )['action'], 'update' );

// severity bands (CVSS v3)
plk_t( 'sev 10', Plakette_Vulns::severity( 10.0 ), 'critical' );
plk_t( 'sev 9.0', Plakette_Vulns::severity( 9.0 ), 'critical' );
plk_t( 'sev 8.9', Plakette_Vulns::severity( 8.9 ), 'high' );
plk_t( 'sev 4.0', Plakette_Vulns::severity( 4.0 ), 'medium' );
plk_t( 'sev 3.9', Plakette_Vulns::severity( 3.9 ), 'low' );
plk_t( 'sev none', Plakette_Vulns::severity( null ), '' );

// compact() of a real feed entry (contact-form-7, shortened)
$entry = json_decode( '{"uuid":"69f4e580612c835bfea455eb87c0aa010b02c26489b2edccc6a5d9b1154113dc","name":"Contact Form 7 [contact-form-7] < 5.3.2","description":null,"operator":{"min_version":null,"min_operator":null,"max_version":"5.3.2","max_operator":"lt","unfixed":"0","closed":"0"},"source":[{"id":"CVE-2020-35489","name":"CVE-2020-35489","link":"https://www.cve.org/CVERecord?id=CVE-2020-35489","description":"[en] The contact-form-7 plugin before 5.3.2 for WordPress allows Unrestricted File Upload and remote code execution because a filename may contain special characters.","date":"2020-12-17"},{"id":"7391118e-eef5-4ff8-a8ea-f6b65f442c63","name":"Contact Form 7 &lt; 5.3.2 - Unrestricted File Upload","link":"https://wpscan.com/vulnerability/7391118e-eef5-4ff8-a8ea-f6b65f442c63","description":"x","date":null}],"impact":{"cvss":{"score":"10.0","severity":"c"},"cvss3":{"version":"3.1","score":"10.0","severity":"critical"}}}', true );
$c     = Plakette_Vulns::compact( $entry );
plk_t( 'compact cves', $c['cves'], array( 'CVE-2020-35489' ) );
plk_t( 'compact title (html decoded, non-CVE source)', $c['title'], 'Contact Form 7 < 5.3.2 - Unrestricted File Upload' );
plk_t( 'compact ref', $c['ref'], array( 'WPScan', 'https://wpscan.com/vulnerability/7391118e-eef5-4ff8-a8ea-f6b65f442c63' ) );
plk_t( 'compact score', $c['score'], 10.0 );
plk_t( 'compact sev', $c['sev'], 'critical' );
plk_t( 'compact affects 5.3.1', Plakette_Vulns::affects( '5.3.1', $c['op'] ), true );
$cve_only = Plakette_Vulns::compact( array( 'uuid' => 'x', 'operator' => array(), 'source' => array( array( 'id' => 'CVE-2018-20979', 'name' => 'CVE-2018-20979', 'description' => '[en] The contact-form-7 plugin before 5.0.4 for WordPress has privilege escalation because of capability_type mishandling in register_post_type. More text.' ) ), 'impact' => array() ) );
plk_t( 'compact title from CVE description', $cve_only['title'], 'The contact-form-7 plugin before 5.0.4 for WordPress has privilege escalation because of capability_type mishandling in register_post_type.' );
plk_t( 'compact no score', $cve_only['score'], null );

echo ( $fail ? "FAILED: $fail of $n\n" : "OK: all $n matcher tests passed\n" );
exit( $fail ? 1 : 0 );
