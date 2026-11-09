<?php
/**
 * CLI-like view of Plakette's image-delivery analysis (same code as the checks):
 *   /plk-tests/tools/delivery.php?url=https://example.com/   (read-only GET/HEAD requests)
 * Prints per image: delivered candidate for phone (412 px × 2.6) and desktop (1366 px), format, bytes, who delivers it.
 */
require dirname( __DIR__, 2 ) . '/wp-load.php';
header( 'Content-Type: application/json; charset=utf-8' );
$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : home_url( '/' );
$res = wp_remote_get( $url, array( 'timeout' => 30, 'sslverify' => false, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' ) );
if ( is_wp_error( $res ) ) {
	echo wp_json_encode( array( 'error' => $res->get_error_message() ) );
	exit;
}
$rows = array();
foreach ( Plakette_Image_Delivery::analyse( wp_remote_retrieve_body( $res ), $url ) as $r ) {
	$rows[] = array(
		'img'        => $r['fallback'],
		'via'        => $r['via'] . ( $r['marker'] ? ' (' . $r['marker'] . ')' : '' ),
		'mobile'     => array( $r['mobile']['url'], $r['mobile']['format'], $r['mobile']['bytes'], $r['mobile']['how'] ),
		'desktop'    => array( $r['desktop']['url'], $r['desktop']['format'], $r['desktop']['bytes'], $r['desktop']['how'] ),
		'modern'     => $r['modern'],
		'who'        => $r['who'],
		'optimized'  => $r['optimized'],
		'attachment' => $r['attachment'],
	);
}
echo wp_json_encode( array( 'url' => $url, 'images' => $rows, 'optimizers_active_here' => Plakette_Image_Delivery::active_optimizers() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
