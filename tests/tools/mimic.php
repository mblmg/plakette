<?php
/**
 * Test helper: turns the seeded site into one where ANOTHER optimiser delivers modern formats.
 *   /plk-tests/tools/mimic.php?mode=ewww    EWWW-like: bild.jpg.webp copies + <picture> with lazy data-srcset + <noscript>
 *                                          on the front page, EWWW JS-WebP mode (data-eio="j", data-srcset-webp) on posts
 *   /plk-tests/tools/mimic.php?mode=accept  server negotiation: same .jpg URLs (via tools/negotiate.php), answer by Accept
 *   /plk-tests/tools/mimic.php?mode=off
 * Markup: tests/mu/plk-toolkit-mimic.php. Plain images outside the block content (tests/mu/plk-toolkit-markup.php) stay JPEG.
 */
require dirname( __DIR__, 2 ) . '/wp-load.php';
header( 'Content-Type: text/plain; charset=utf-8' );
$mode = isset( $_GET['mode'] ) ? preg_replace( '/[^a-z]/', '', $_GET['mode'] ) : 'off';
$made = 0;
// Remove what the previous mimic mode wrote.
foreach ( (array) get_option( 'plk_mimic_files', array() ) as $f ) {
	is_file( $f ) && unlink( $f );
}
$written = array();
foreach ( get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
	$file  = get_attached_file( $id );
	$meta  = wp_get_attachment_metadata( $id );
	$files = array( $file );
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
		$files[] = dirname( $file ) . '/' . $s['file'];
	}
	foreach ( $files as $f ) {
		foreach ( array( 'webp', 'avif' ) as $fmt ) {
			$target = 'ewww' === $mode ? "$f.$fmt" : "$f.mimic.$fmt";
			if ( 'off' === $mode ) {
				continue;
			}
			if ( 'ewww' === $mode && 'avif' === $fmt ) {
				continue; // EWWW (free) delivers WebP.
			}
			if ( ! is_file( $f ) || is_file( $target ) ) {
				continue;
			}
			$ed = wp_get_image_editor( $f );
			if ( is_wp_error( $ed ) ) {
				continue;
			}
			$q = 'avif' === $fmt ? 63 : 80;
			$force = function () use ( $q ) {
				return $q;
			};
			add_filter( 'wp_editor_set_quality', $force, 999 );
			$r = @$ed->save( "$target.tmp.$fmt", "image/$fmt" );
			remove_filter( 'wp_editor_set_quality', $force, 999 );
			if ( ! is_wp_error( $r ) && is_file( "$target.tmp.$fmt" ) && filesize( "$target.tmp.$fmt" ) > 0 && filesize( "$target.tmp.$fmt" ) < filesize( $f ) ) {
				rename( "$target.tmp.$fmt", $target );
				$written[] = $target;
				$made++;
			} elseif ( is_file( "$target.tmp.$fmt" ) ) {
				unlink( "$target.tmp.$fmt" );
			}
		}
	}
}
update_option( 'plk_mimic', 'off' === $mode ? '' : $mode );
update_option( 'plk_mimic_files', $written );
foreach ( glob( WP_CONTENT_DIR . '/cache/plakette/*' ) ?: array() as $f ) {
	is_dir( $f ) ? Plakette_Log::rrmdir( $f ) : unlink( $f );
}
Plakette_Page_Cache::purge_all();
echo "mode=$mode, copies made: $made\n";
