<?php
/**
 * Test fixture: mimics server-side format negotiation (Converter for Media, CompressX/Imagify .htaccess mode,
 * LiteSpeed, nginx map …). The URL keeps its .jpg name; the answer depends on the Accept header:
 *   /plk-tests/tools/negotiate.php/2026/11/bild.jpg  →  bild.jpg.avif | bild.jpg.webp | bild.jpg  (+ Vary: Accept)
 */
$rel  = isset( $_SERVER['PATH_INFO'] ) ? $_SERVER['PATH_INFO'] : ( isset( $_GET['f'] ) ? '/' . $_GET['f'] : '' );
$base = dirname( __DIR__, 2 ) . '/wp-content/uploads';
$file = realpath( $base . $rel );
if ( ! $file || 0 !== strpos( $file, realpath( $base ) ) || ! preg_match( '/\.(jpe?g|png)$/i', $file ) ) {
	http_response_code( 404 );
	exit;
}
$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? $_SERVER['HTTP_ACCEPT'] : '';
$type   = preg_match( '/\.png$/i', $file ) ? 'image/png' : 'image/jpeg';
foreach ( array( 'avif', 'webp' ) as $f ) {
	if ( false !== strpos( $accept, 'image/' . $f ) && is_file( $file . '.mimic.' . $f ) ) {
		$file = $file . '.mimic.' . $f;
		$type = 'image/' . $f;
		break;
	}
}
header( 'Content-Type: ' . $type );
header( 'Vary: Accept' );
header( 'Cache-Control: public, max-age=3600' );
header( 'Content-Length: ' . filesize( $file ) );
if ( 'HEAD' !== $_SERVER['REQUEST_METHOD'] ) {
	readfile( $file );
}
