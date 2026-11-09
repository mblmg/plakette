<?php
/**
 * Test fixture (mu-plugin): accessibility defects of a typical theme.
 * No lang attribute on <html>, a viewport that blocks zooming, a newsletter field without a label.
 */
add_filter( 'language_attributes', '__return_empty_string' );
add_action( 'wp_head', function () {
	echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">' . "\n";
}, 1 );
add_action( 'wp_footer', function () {
	echo '<form class="plk-test-newsletter" action="#" method="post" style="padding:1rem;text-align:center"><input type="email" name="newsletter_email" placeholder="Ihre E-Mail-Adresse"> <button type="submit">Anmelden</button></form>' . "\n";
}, 5 );
