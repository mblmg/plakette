<?php
/**
 * Test: big PNG photo with transparency → "Ausgewählte Bilder verkleinern" replaces it by WebP, rewrites the
 * addresses in post content, serialized builder data (object inside, like Beaver Builder) and JSON (Breakdance),
 * and the undo restores everything byte for byte. Also: ignore list and the "where used" links.
 *
 * Run: cd wordpress && wp eval-file ../plakette/tests/image-webp.php
 */

$fail = 0;
$ok   = function ( $cond, $label ) use ( &$fail ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$fail;
	}
};

wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) );
require_once ABSPATH . 'wp-admin/includes/image.php';

// A noisy 1400×1000 photo-like PNG with a transparent edge: big as PNG, small as WebP.
$up   = wp_upload_dir();
$path = $up['path'] . '/plk-test-mobile-' . wp_generate_password( 4, false, false ) . '.png';
$im   = imagecreatetruecolor( 1400, 1000 );
imagesavealpha( $im, true );
imagealphablending( $im, false );
mt_srand( 7 );
for ( $y = 0; $y < 1000; $y += 2 ) {
	for ( $x = 0; $x < 1400; $x += 2 ) {
		$a = $x < 60 ? 127 : 0;
		$c = imagecolorallocatealpha( $im, ( $x / 6 + mt_rand( 0, 40 ) ) % 256, ( $y / 4 + mt_rand( 0, 40 ) ) % 256, mt_rand( 90, 140 ), $a );
		imagefilledrectangle( $im, $x, $y, $x + 1, $y + 1, $c );
	}
}
imagepng( $im, $path, 9 );
$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'plk test', 'post_status' => 'inherit' ), $path );
wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
$url   = wp_get_attachment_url( $id );
$small = wp_get_attachment_image_url( $id, 'medium' );
echo 'PNG: ' . size_format( filesize( $path ) ) . "\n";

$obj           = new stdClass();
$obj->bg_image = $url;
$obj->text     = 'Ein "Zitat" mit ü und ' . $small;
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'PLK Bildseite', 'post_content' => '<img src="' . $url . '"><img src="' . $small . '"> und ' . $url . '.webp bleibt' ) );
update_post_meta( $page, '_plk_builder', array( 'node' => $obj, 'n' => 3 ) );
update_post_meta( $page, '_plk_json', wp_slash( wp_json_encode( array( 'image' => array( 'url' => $url ) ) ) ) );
global $wpdb;
$raw = function () use ( $wpdb, $page ) {
	return $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE '\\_plk\\_%%' ORDER BY meta_key", $page ) );
};
$before_meta    = $raw();
$before_content = get_post_field( 'post_content', $page );

// Where used.
Plakette_Image_Usage::flush();
$places = Plakette_Image_Usage::places( $id, $path );
$ok( count( $places ) >= 1 && 'PLK Bildseite' === $places[0]['label'] && '' !== $places[0]['url'] && '' !== $places[0]['edit'], 'Fundstelle mit Link und Bearbeiten-Link' );

// Fix.
$check = Plakette_Auditor::get( 'speed_images' );
$undo  = array();
$msg   = $check->fix( array( 'items' => array( array( 'id' => 'att:' . $id ) ) ), $undo );
echo 'Meldung: ' . ( is_wp_error( $msg ) ? 'FEHLER ' . $msg->get_error_message() : $msg ) . "\n";
$ok( is_string( $msg ) && str_contains( $msg, 'WebP' ), 'Bild durch WebP ersetzt' );
$new = wp_get_attachment_url( $id );
$ok( str_ends_with( $new, '.webp' ) && 'image/webp' === get_post_mime_type( $id ), 'Anhang zeigt auf WebP' );
$ok( filesize( get_attached_file( $id ) ) < filesize( $path ) * 0.6, 'WebP deutlich kleiner (' . size_format( filesize( get_attached_file( $id ) ) ) . ')' );
$content = get_post_field( 'post_content', $page );
$ok( str_contains( $content, $new ) && ! str_contains( $content, '"' . $url . '"' ), 'Adresse im Inhalt ersetzt' );
$ok( str_contains( $content, $url . '.webp bleibt' ), 'längerer Name (bild.png.webp) unangetastet' );
$ok( ! str_contains( $content, $small ), 'Bildgröße im Inhalt ersetzt' );
$meta = get_post_meta( $page, '_plk_builder', true );
$ok( is_array( $meta ) && $meta['node'] instanceof stdClass && $meta['node']->bg_image === $new, 'serialisierte Builder-Daten (Objekt) ersetzt' );
$ok( isset( $meta['node']->text ) && str_contains( $meta['node']->text, 'Zitat' ) && ! str_contains( $meta['node']->text, $small ), 'Text im Objekt intakt, Größe ersetzt' );
$json = json_decode( get_post_meta( $page, '_plk_json', true ), true );
$ok( ( $json['image']['url'] ?? '' ) === $new, 'JSON (Breakdance) ersetzt' );
$ok( is_file( $path ), 'alte Datei bleibt liegen' );

// Undo.
$errors = Plakette_Log::apply_ops( $undo );
clean_post_cache( $page );
wp_cache_delete( $page, 'post_meta' );
$ok( ! $errors, 'Rückgängig ohne Fehler' );
$ok( wp_get_attachment_url( $id ) === $url && 'image/png' === get_post_mime_type( $id ), 'Anhang wieder PNG' );
$ok( get_post_field( 'post_content', $page ) === $before_content, 'Inhalt exakt wie vorher' );
$ok( $raw() === $before_meta, 'Metadaten exakt wie vorher' );
$ok( ! is_file( preg_replace( '/\.png$/', '.webp', $path ) ), 'WebP-Dateien wieder gelöscht' );

// Serialized replace: broken data is left alone.
$ok( null === Plakette_Image_Replace::serialized_replace( 'a:1:{s:3:"abc";s:99:"x";}', fn( $s ) => $s ), 'kaputte Serialisierung wird nicht angefasst' );

// Ignore.
Plakette_Check::set_ignored( 'speed_images', 'att:' . $id, 'plk.png', true );
$ok( isset( $check->ignored()[ 'att:' . $id ] ), 'ignorieren gespeichert' );
Plakette_Check::set_ignored( 'speed_images', 'att:' . $id, '', false );
$ok( ! isset( $check->ignored()[ 'att:' . $id ] ), 'wieder aufgenommen' );

// Clean up.
wp_delete_post( $page, true );
wp_delete_attachment( $id, true );
echo $fail ? "\n$fail FEHLER\n" : "\nAlles bestanden.\n";
