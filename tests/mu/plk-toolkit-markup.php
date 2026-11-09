<?php
/**
 * Test fixture (mu-plugin): defects for the toolkit checks (speed_image_dims, privacy_cdn, a11y_animations, a11y_link_names).
 * Assets are created by tests/seed-toolkit.php.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_script( 'plk-test-gsap', 'https://unpkg.com/gsap@3.12.5/dist/gsap.min.js', array(), null, true );
	}
);

// Images without width/height in the content of the front page (no wp-image-N class, so WordPress cannot add them).
add_filter(
	'the_content',
	function ( $content ) {
		static $done = false;
		$a = get_option( 'plk_toolkit_assets' );
		if ( $done || ! is_front_page() || ! is_array( $a ) ) {
			return $content;
		}
		$done = true;
		return $content . "\n" . '<p class="plk-test-imgs"><img src="' . esc_url( $a['jpg'] ) . '" alt="Erdbeerkuchen im Anschnitt"> <img src="' . esc_url( $a['svg'] ) . '" alt="Logo der Konditorei Sonnenschein"></p>';
	},
	99
);

// Breakdance-like markup: entrance animation and a social icon link without text.
add_action(
	'wp_footer',
	function () {
		if ( ! is_front_page() ) {
			return;
		}
		echo '<div class="plk-test-social" data-entrance="fadeInUp"><p>Folge uns</p>'
			. '<a class="breakdance-link" href="https://www.instagram.com/x" target="_blank" aria-label="custom"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M2 2h20v20H2z"/></svg></a>'
			. '</div>' . "\n";
	},
	5
);
