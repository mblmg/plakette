<?php
/**
 * Test fixture (runs inside tests/seed.php after the base seed): assets for the toolkit checks.
 * The markup itself comes from the mu-plugin tests/mu/plk-toolkit-markup.php:
 *   - two images without width/height in the front page content (JPEG upload + SVG with viewBox)
 *   - a script from unpkg.com (GSAP 3.12.5, like Breakdance)
 *   - an icon link without text (Breakdance "Social Icons": aria-label="custom")
 *   - an element with data-entrance (Breakdance entrance animation)
 */
$plk_up  = wp_upload_dir();
$plk_dir = $plk_up['basedir'] . '/plk-toolkit';
wp_mkdir_p( $plk_dir );
file_put_contents( $plk_dir . '/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 80"><rect width="240" height="80" fill="#c33"/><text x="20" y="50" fill="#fff" font-size="28">Sonnenschein</text></svg>' );
update_option(
	'plk_toolkit_assets',
	array(
		'jpg' => wp_get_attachment_image_url( $cake, 'medium' ),
		'svg' => $plk_up['baseurl'] . '/plk-toolkit/logo.svg',
	)
);
echo "toolkit assets: " . wp_json_encode( get_option( 'plk_toolkit_assets' ) ) . "\n";
