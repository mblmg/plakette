<?php
/**
 * Test fixture (runs inside tests/seed.php): privacy defects.
 * - a post with a YouTube embed block, a raw YouTube iframe and a Google Maps iframe (no two-click solution)
 * - approved comments with full IPv4/IPv6 addresses, comments open, avatars on (Gravatar)
 * Google Analytics is printed in wp_head by tests/mu/plk-privacy-tracking.php.
 */
defined( 'ABSPATH' ) || exit;

update_option( 'show_avatars', 1 );
update_option( 'default_comment_status', 'open' );

$plk_video = wp_insert_post(
	array(
		'post_status'    => 'publish',
		'post_title'     => 'So entsteht unsere Hochzeitstorte (Video)',
		'comment_status' => 'open',
		'post_content'   => "<!-- wp:paragraph -->\n<p>Im Video zeigen wir, wie eine dreistöckige Hochzeitstorte entsteht.</p>\n<!-- /wp:paragraph -->\n\n"
			. "<!-- wp:embed {\"url\":\"https://www.youtube.com/watch?v=dQw4w9WgXcQ\",\"type\":\"video\",\"providerNameSlug\":\"youtube\",\"responsive\":true,\"className\":\"wp-embed-aspect-16-9 wp-has-aspect-ratio\"} -->\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n</div></figure>\n<!-- /wp:embed -->\n\n"
			. "<!-- wp:html -->\n<iframe width=\"560\" height=\"315\" src=\"https://www.youtube.com/embed/dQw4w9WgXcQ\" title=\"YouTube video player\" frameborder=\"0\" allow=\"accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture\" allowfullscreen></iframe>\n<!-- /wp:html -->\n\n"
			. "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">So finden Sie uns</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:html -->\n<iframe src=\"https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2659.5!2d7.852!3d47.995!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1\" width=\"600\" height=\"450\" style=\"border:0;\" allowfullscreen=\"\" loading=\"lazy\" referrerpolicy=\"no-referrer-when-downgrade\"></iframe>\n<!-- /wp:html -->",
	)
);

$plk_comments = array(
	array( 'Anna Becker', 'anna.becker@example.org', '203.0.113.42', 'Die Torte sieht großartig aus!' ),
	array( 'Jonas Wolf', 'jonas@example.net', '2001:db8:85a3:8d3:1319:8a2e:370:7348', 'Gibt es die auch vegan?' ),
	array( 'Mia', 'mia@example.com', '198.51.100.7', 'Danke für das Video.' ),
);
foreach ( $plk_comments as $i => $c ) {
	wp_insert_comment(
		array(
			'comment_post_ID'      => $plk_video,
			'comment_author'       => $c[0],
			'comment_author_email' => $c[1],
			'comment_author_IP'    => $c[2],
			'comment_content'      => $c[3],
			'comment_approved'     => 1,
			'comment_agent'        => 'Mozilla/5.0',
			'comment_date'         => gmdate( 'Y-m-d H:i:s', time() - ( 3 - $i ) * HOUR_IN_SECONDS ),
		)
	);
}
echo "privacy: video post $plk_video, " . count( $plk_comments ) . " comments\n";
