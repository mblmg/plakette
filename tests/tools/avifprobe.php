<?php
require dirname( __DIR__, 2 ) . '/wp-load.php';
header( 'Content-Type: text/plain' );
echo PHP_VERSION, ' mem ', ini_get( 'memory_limit' ), "\n";
foreach ( array( array( 64, 64 ), array( 150, 150 ), array( 300, 188 ), array( 301, 189 ), array( 512, 512 ), array( 768, 499 ), array( 1024, 666 ) ) as $d ) {
	$im = imagecreatetruecolor( $d[0], $d[1] );
	for ( $i = 0; $i < 200; $i++ ) {
		imagefilledellipse( $im, mt_rand( 0, $d[0] ), mt_rand( 0, $d[1] ), mt_rand( 5, 80 ), mt_rand( 5, 80 ), mt_rand( 0, 0xffffff ) );
	}
	foreach ( array( 6, 10 ) as $speed ) {
		ob_start();
		$t  = microtime( true );
		$ok = @imageavif( $im, null, 60, $speed );
		$b  = ob_get_clean();
		echo "{$d[0]}x{$d[1]} speed $speed ok=", var_export( $ok, true ), ' bytes=', strlen( $b ), ' t=', round( microtime( true ) - $t, 2 ), "\n";
	}
}
