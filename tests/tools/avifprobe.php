<?php
require dirname( __DIR__, 2 ) . '/wp-load.php';
header( 'Content-Type: text/plain' );
foreach ( get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => -1 ) ) as $p ) {
	echo $p->ID, ' ', get_post_meta( $p->ID, '_wp_attached_file', true ), ' ', wp_get_attachment_url( $p->ID ), "\n";
}
$u = wp_get_upload_dir();
echo $u['baseurl'], "\n", attachment_url_to_postid( $u['baseurl'] . '/2026/11/kuchen-mit-erdbeeren.jpg' ), "\n";
