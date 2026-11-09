<?php
/**
 * Test fixture: turns a fresh WordPress into a site with many typical SEO and speed problems.
 * Call once: /plk-tests/seed.php
 */
require __DIR__ . '/../wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_set_current_user( 1 );
header( 'Content-Type: text/plain; charset=utf-8' );
if ( get_option( 'plk_seeded' ) ) { echo "already seeded\n"; exit; }

update_option( 'blog_public', '0' );
update_option( 'permalink_structure', '' );
update_option( 'blogname', 'Konditorei Sonnenschein' );
update_option( 'blogdescription', 'Just another WordPress site' );
update_option( 'wp_attachment_pages_enabled', '1' );
flush_rewrite_rules();

function plk_upload( $name ) {
	$up   = wp_upload_dir();
	$dest = $up['path'] . '/' . $name;
	copy( __DIR__ . '/fixtures/' . $name, $dest );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => pathinfo( $name, PATHINFO_FILENAME ), 'post_status' => 'inherit' ), $dest );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
	return $id;
}
$hero  = plk_upload( 'IMG_20240611_183012.jpg' );
$cake  = plk_upload( 'kuchen-mit-erdbeeren.jpg' );
$hurl  = wp_get_attachment_image_url( $hero, 'large' );
$curl  = wp_get_attachment_image_url( $cake, 'large' );

$front = wp_insert_post( array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'Willkommen',
	'post_content' => "<!-- wp:image {\"id\":$hero,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"$hurl\" alt=\"\" class=\"wp-image-$hero\"/></figure>\n<!-- /wp:image -->\n\n<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Torten wie bei Oma</h1>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Seit 1987 backen wir in Freiburg Torten, Kuchen und Gebäck aus regionalen Zutaten. Schauen Sie vorbei oder bestellen Sie Ihre Hochzeitstorte online. Hier geht es zu unserer <a href=\"/alte-preisliste/\">Preisliste</a>.</p>\n<!-- /wp:paragraph -->",
) );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $front );

$long = str_repeat( 'Unsere Erdbeertorte besteht aus einem lockeren Biskuit, frischer Sahne und Erdbeeren aus dem Markgräflerland. ', 40 );
wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Neuigkeiten', 'post_content' => '<!-- wp:paragraph --><p>Ab Juni gibt es wieder Erdbeertorte.</p><!-- /wp:paragraph -->' ) );
wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Neuigkeiten', 'post_content' => '<!-- wp:paragraph --><p>Unser Café hat jetzt auch sonntags geöffnet.</p><!-- /wp:paragraph -->' ) );
$recipe = wp_insert_post( array(
	'post_status'  => 'publish',
	'post_title'   => 'Das große Geheimnis hinter unserer berühmten Erdbeer-Sahne-Torte nach dem Originalrezept unserer Großmutter',
	'post_content' => "<!-- wp:image {\"id\":$cake} -->\n<figure class=\"wp-block-image\"><img src=\"$curl\" class=\"wp-image-$cake\"/></figure>\n<!-- /wp:image -->\n\n<!-- wp:paragraph -->\n<p>$long</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Mehr dazu im <a href=\"" . home_url( '/?p=99999' ) . "\">alten Rezeptbuch</a>.</p>\n<!-- /wp:paragraph -->",
) );
set_post_thumbnail( $recipe, $hero );

// Expired transients and spam to clean up.
for ( $i = 0; $i < 120; $i++ ) {
	update_option( '_transient_timeout_plk_old_' . $i, time() - 3600, false );
	update_option( '_transient_plk_old_' . $i, str_repeat( 'x', 100 ), false );
}
for ( $i = 0; $i < 5; $i++ ) {
	wp_insert_comment( array( 'comment_post_ID' => $recipe, 'comment_content' => 'Buy cheap pills', 'comment_approved' => 'spam', 'comment_author' => 'spammer' ) );
}
// Further defects per topic (tests/seed-*.php), e.g. tracking scripts, embeds, legal pages.
foreach ( glob( __DIR__ . '/seed-*.php' ) as $plk_seed ) {
	require $plk_seed;
}
update_option( 'plk_seeded', 1 );
echo "seeded: front=$front recipe=$recipe hero=$hero cake=$cake\n";
