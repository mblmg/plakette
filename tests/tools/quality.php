<?php
/**
 * Quality/size sweep for the modern-image encoder (runs inside Playground):
 *   /plk-tests/tools/quality.php  → writes tests/out/quality/<fixture>.<q>.(webp|avif) + JSON with sizes and times.
 * Compare locally with tests/tools/quality.sh (ImageMagick SSIM/DSSIM against the original).
 */
require dirname( __DIR__, 2 ) . '/wp-load.php';
header( 'Content-Type: application/json' );
$out = dirname( __DIR__ ) . '/out/quality';
wp_mkdir_p( $out );
$res = array(
	'php'  => PHP_VERSION,
	'avif' => wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ),
	'webp' => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ),
	'gd'   => function_exists( 'gd_info' ) ? gd_info() : null,
);
foreach ( glob( dirname( __DIR__ ) . '/fixtures/*.jpg' ) as $src ) {
	$name = pathinfo( $src, PATHINFO_FILENAME );
	// Same pipeline as WordPress: a 1024 px wide "large" size.
	$ed = wp_get_image_editor( $src );
	$ed->resize( 512, 512 ); // AVIF in Playground's PHP-WASM only works up to ~512x512 px (see report).
	$ed->set_quality( 82 );
	$base = $ed->save( "$out/$name.jpg", 'image/jpeg' );
	// Lossless reference for the metric.
	$ed->save( "$out/$name.ref.png", 'image/png' );
	$res['files'][ $name ]['jpg'] = filesize( "$out/$name.jpg" );
	foreach ( array( 'webp' => array( 70, 75, 80, 85 ), 'avif' => array( 30, 40, 50, 55, 60, 65, 70, 80 ) ) as $fmt => $qs ) {
		foreach ( $qs as $q ) {
			$e = wp_get_image_editor( "$out/$name.jpg" );
			if ( is_wp_error( $e ) ) {
				continue;
			}
			// WordPress resets the quality when the output format changes – force it through the filter.
			$force = function () use ( $q ) {
				return $q;
			};
			add_filter( 'wp_editor_set_quality', $force, 999 );
			$e->set_quality( $q );
			$t = microtime( true );
			$r = $e->save( "$out/$name.$q.$fmt", "image/$fmt" );
			remove_filter( 'wp_editor_set_quality', $force, 999 );
			$res['files'][ $name ][ "$fmt$q" ] = is_wp_error( $r ) ? $r->get_error_message() : array( filesize( "$out/$name.$q.$fmt" ), round( microtime( true ) - $t, 2 ) );
		}
	}
}
echo wp_json_encode( $res, JSON_PRETTY_PRINT );
