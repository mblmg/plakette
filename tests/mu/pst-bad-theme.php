<?php
/**
 * Test fixture (mu-plugin): behaves like a typical slow theme/plugin.
 * Google Fonts, a render-blocking script in <head>, jQuery Migrate, Dashicons for visitors,
 * and lazy-loading on every image (including the hero image).
 */
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'pst-test-gfonts', 'https://fonts.googleapis.com/css2?family=Lobster&family=Open+Sans:wght@400;700', array(), null );
	wp_enqueue_script( 'pst-test-slider', content_url( 'mu-plugins/pst-slider.js' ), array( 'jquery' ), '1.0' );
	wp_enqueue_style( 'dashicons' );
} );
add_filter( 'wp_get_loading_optimization_attributes', function ( $attrs ) {
	return array( 'loading' => 'lazy' );
}, 99 );
