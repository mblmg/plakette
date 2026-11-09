<?php
/**
 * Test fixture (runs inside tests/seed.php): defects for the checks from "SEO-Helfer für Breakdance".
 * - posts/pages with images in the content (or in Breakdance data) but without featured image,
 * - a parent page with three chapters (for the Breakdance chapter navigation),
 * - fake copies of the old plugins "SEO-Helfer für Breakdance" and "i-make.media Toolkit for Breakdance",
 *   activated, with settings to migrate.
 * Uses $hero / $cake / $hurl / $curl from tests/seed.php.
 */
defined( 'ABSPATH' ) || exit;

// Post with an image in the content (block markup) but no featured image.
$plk_sh_post = wp_insert_post( array(
	'post_status'  => 'publish',
	'post_title'   => 'Unsere Hochzeitstorten',
	'post_content' => "<!-- wp:paragraph --><p>Jede Hochzeitstorte backen wir nach euren Wünschen.</p><!-- /wp:paragraph -->\n\n<!-- wp:image {\"id\":$cake} -->\n<figure class=\"wp-block-image\"><img src=\"$curl\" alt=\"Hochzeitstorte\" class=\"wp-image-$cake\"/></figure>\n<!-- /wp:image -->",
) );

// Page with only a plain uploads URL (classic editor, scaled size) – found through the URL.
$plk_sh_page = wp_insert_post( array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'Café',
	'post_content' => '<p>Unser Café am Münsterplatz.</p><p><img src="' . esc_url( $hurl ) . '" alt="Café" width="1024" height="768" /></p>',
) );

// Breakdance-built page: images only in _breakdance_data (one content image, one background image).
$plk_sh_tree = array(
	'root' => array(
		'id'       => 1,
		'data'     => array( 'type' => 'EssentialElements\\Root', 'properties' => null ),
		'children' => array(
			array(
				'id'       => 2,
				'data'     => array(
					'type'       => 'EssentialElements\\Section',
					'properties' => array( 'design' => array( 'background' => array( 'image' => array( 'id' => $hero, 'url' => $hurl ) ) ) ),
				),
				'children' => array(
					array(
						'id'   => 3,
						'data' => array(
							'type'       => 'EssentialElements\\Image',
							'properties' => array( 'content' => array( 'content' => array( 'image' => array( 'id' => $cake, 'filename' => 'kuchen.jpg', 'url' => $curl ) ) ) ),
						),
					),
				),
			),
		),
	),
);
$plk_sh_bd = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Torten-Galerie', 'post_content' => '' ) );
update_post_meta( $plk_sh_bd, '_breakdance_data', wp_slash( wp_json_encode( array( 'tree_json_string' => wp_json_encode( $plk_sh_tree ) ) ) ) );

// A section with three chapters (Breakdance chapter navigation).
$plk_sh_parent = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Backkurs', 'post_content' => '<p>Backkurs in drei Teilen.</p>' ) );
foreach ( array( 'Teil 1: Teig', 'Teil 2: Füllung', 'Teil 3: Verzieren' ) as $plk_sh_i => $plk_sh_title ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $plk_sh_parent, 'menu_order' => $plk_sh_i, 'post_title' => $plk_sh_title, 'post_content' => '<p>Kapitel ' . ( $plk_sh_i + 1 ) . '.</p>' ) );
}

// Fake old plugins (only what Plakette detects), activated with settings.
$plk_sh_dir = WP_PLUGIN_DIR . '/imm-seo-helfer';
wp_mkdir_p( $plk_sh_dir );
file_put_contents( $plk_sh_dir . '/imm-seo-helfer.php', "<?php\n/**\n * Plugin Name: SEO-Helfer für Breakdance\n * Version: 1.2.0\n */\ndefined( 'ABSPATH' ) || exit;\ndefine( 'IMM_SH_VERSION', '1.2.0' );\ndefine( 'IMM_SH_OPTION', 'imm_seo_helfer' );\ndefine( 'IMM_SH_DATEI', __FILE__ );\n" );
$plk_tk_dir = WP_PLUGIN_DIR . '/imm-toolkit-for-breakdance';
wp_mkdir_p( $plk_tk_dir );
file_put_contents( $plk_tk_dir . '/imm-toolkit-for-breakdance.php', "<?php\n/**\n * Plugin Name: i-make.media Toolkit for Breakdance\n * Version: 1.1.1\n */\nnamespace IMM\\Toolkit;\ndefined( 'ABSPATH' ) || exit;\nconst VERSION = '1.1.1';\nconst OPTION = 'imm_toolkit';\ndefine( __NAMESPACE__ . '\\\\BASENAME', plugin_basename( __FILE__ ) );\nfunction opt( \$k ) { \$o = get_option( OPTION, array() ); return ! empty( \$o[ \$k ] ); }\n" );

update_option( 'imm_seo_helfer', array(
	'sitemap_bilder'   => true,
	'hauptbild'        => true,
	'kapitel'          => true,
	'kapitel_bereiche' => array( $plk_sh_parent ),
	'firma'            => true,
	'firma_daten'      => array(
		'typ'             => 'FoodEstablishment',
		'strasse'         => 'Münsterplatz 7',
		'plz'             => '79098',
		'ort'             => 'Freiburg im Breisgau',
		'region'          => 'Baden-Württemberg',
		'land'            => 'DE',
		'preisrahmen'     => '€€',
		'inhaber'         => 'Erika Sonnenschein',
		'einzugsgebiet'   => "Freiburg im Breisgau\nDeutschland",
		'oeffnungszeiten' => "Di-Fr 9-12, 14-18 Uhr\nSa 8:30–14\nSo 13 bis 17",
		'profile'         => "https://www.instagram.com/konditorei.sonnenschein\nhttps://maps.app.goo.gl/abc123\nkein-link",
		'themen'          => "Hochzeitstorten\nKonditorei",
		'sprachen'        => 'Deutsch, Englisch',
		'angebote'        => "Hochzeitstorte | 2.400 | | Dreistöckig, mit Beratung\nKaffee und Kuchen | 7,90 € | pro Person | Ein Stück Torte und Kaffee\nGeschenkgutschein",
	),
) );
update_option( 'imm_toolkit', array( 'fonts' => 1, 'performance' => 1, 'auto_update' => 1 ) );
update_option( 'imm_performance', array( 'lcp' => 1, 'lcp_webp' => 0, 'cdn_local' => 1, 'dims' => 1, 'a11y_anim' => 1, 'link_name' => 1 ) );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_cache_delete( 'plugins', 'plugins' );
foreach ( array( 'imm-seo-helfer/imm-seo-helfer.php', 'imm-toolkit-for-breakdance/imm-toolkit-for-breakdance.php' ) as $plk_sh_plugin ) {
	$plk_sh_res = activate_plugin( $plk_sh_plugin, '', false, true );
	echo 'seohelper: activate ' . $plk_sh_plugin . ': ' . ( is_wp_error( $plk_sh_res ) ? $plk_sh_res->get_error_message() : 'ok' ) . "\n";
}
echo "seohelper: post=$plk_sh_post page=$plk_sh_page bd=$plk_sh_bd parent=$plk_sh_parent\n";
