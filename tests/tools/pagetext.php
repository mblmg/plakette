<?php
/* Prints whether Plakette finds e-mail and postcode on a page whose content is only a shortcode. /plk-tests/tools/pagetext.php */
require __DIR__ . '/../../wp-load.php';
header( 'Content-Type: text/plain; charset=utf-8' );
$id = (int) get_option( 'plk_shortcode_page' );
if ( ! $id || ! get_post( $id ) ) {
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Shortcode-Test', 'post_content' => '[plk_erecht24_imprint]' ) );
	update_option( 'plk_shortcode_page', $id );
}
list( $text, $front ) = Plakette_Legal_Pages::page_text( $id );
$mail = (bool) preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text );
$plz  = (bool) preg_match( '/\b\d{5}\b/', $text );
echo 'Shortcode-Impressum: ' . ( $mail && $plz ? 'OK' : 'FEHLER' ) . " (E-Mail: " . ( $mail ? 'ja' : 'nein' ) . ', PLZ: ' . ( $plz ? 'ja' : 'nein' ) . ', Frontend: ' . ( $front ? 'ja' : 'nein' ) . ")\n";
wp_delete_post( $id, true );
delete_option( 'plk_shortcode_page' );
