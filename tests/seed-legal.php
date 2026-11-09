<?php
/**
 * Test fixture (runs inside tests/seed.php): legal defects.
 * - no Impressum, only an old "Kontakt" page with "§ 5 TMG", "§ 55 Abs. 2 RStV", "TTDSG" and a link to the closed EU ODR platform,
 * - WordPress's privacy page stays a draft with the sample text (wp_page_for_privacy_policy points to it).
 * Theme defects (missing lang, zoom lock, unlabeled field) come from tests/mu/plk-legal-theme.php.
 */
defined( 'ABSPATH' ) || exit;

$plk_contact = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Kontakt',
		'post_name'    => 'kontakt',
		'post_content' => "<!-- wp:paragraph -->\n<p>Konditorei Sonnenschein<br>Münsterplatz 7<br>79098 Freiburg im Breisgau</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Angaben gemäß § 5 TMG. Verantwortlich für den Inhalt nach § 55 Abs. 2 RStV: Erika Sonnenschein.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Cookies setzen wir nur nach § 25 TTDSG. Mehr dazu im Telemediengesetz.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">EU-Streitschlichtung</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Die Europäische Kommission stellt eine Plattform zur Online-Streitbeilegung (OS) bereit: <a href=\"https://ec.europa.eu/consumers/odr/\">https://ec.europa.eu/consumers/odr/</a>.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Verbraucherstreitbeilegung</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</p>\n<!-- /wp:paragraph -->",
	)
);
// Keep WordPress's draft privacy page with the sample text (create one if this install has none).
$plk_privacy = (int) get_option( 'wp_page_for_privacy_policy' );
if ( ! $plk_privacy || ! get_post( $plk_privacy ) ) {
	$plk_privacy = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Privacy Policy', 'post_name' => 'privacy-policy', 'post_content' => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Who we are</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Our website address is: " . home_url() . ".</p>\n<!-- /wp:paragraph -->" ) );
	update_option( 'wp_page_for_privacy_policy', $plk_privacy );
}
echo "legal: contact=$plk_contact privacy=$plk_privacy (" . get_post_status( $plk_privacy ) . ")\n";
