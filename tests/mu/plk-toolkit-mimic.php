<?php
/**
 * Test fixture (mu-plugin): markup of other image optimisers, switched by the option plk_mimic (tests/tools/mimic.php).
 *   ewww   – front page: EWWW <picture> + lazy data-srcset (.jpg.webp) + data: placeholder + <noscript> fallback;
 *            posts: EWWW JS-WebP mode (<img data-eio="j" data-src-webp data-srcset-webp>).
 *   accept – block images point to tools/negotiate.php/…/bild.jpg (format by Accept header, URL stays .jpg).
 */
add_filter(
	'the_content',
	function ( $content ) {
		$mode = get_option( 'plk_mimic' );
		if ( ! $mode || is_admin() ) {
			return $content;
		}
		$up = wp_get_upload_dir();
		if ( 'accept' === $mode ) {
			return preg_replace_callback(
				'#<img\b[^>]*\bwp-image-\d+[^>]*>#',
				fn( $m ) => str_replace( $up['baseurl'] . '/', home_url( '/plk-tests/tools/negotiate.php/' ), $m[0] ),
				$content
			);
		}
		return preg_replace_callback(
			'#<img\b[^>]*\bwp-image-\d+[^>]*>#',
			function ( $m ) {
				$img    = $m[0];
				$src    = preg_match( '#\ssrc="([^"]+)"#', $img, $a ) ? $a[1] : '';
				$srcset = preg_match( '#\ssrcset="([^"]+)"#', $img, $a ) ? $a[1] : '';
				$sizes  = preg_match( '#\ssizes="([^"]+)"#', $img, $a ) ? $a[1] : '';
				$webp   = preg_replace( '#\.(jpe?g|png)(\s|,|$)#', '.$1.webp$2', $srcset );
				if ( is_front_page() ) {
					$lazy = preg_replace( array( '#\ssrc="[^"]+"#', '#\ssrcset="[^"]+"#', '#\ssizes="[^"]+"#', '#\sloading="lazy"#', '#class="#' ), array( ' src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-src="' . $src . '"', ' data-srcset="' . $srcset . '"', ' data-sizes="' . $sizes . '"', '', 'data-eio="p" class="lazyload ' ), $img );
					return '<picture><source type="image/webp" data-srcset="' . $webp . '" data-sizes="' . $sizes . '">' . $lazy . '</picture><noscript>' . $img . '</noscript>';
				}
				return str_replace( '<img ', '<img data-eio="j" data-src-webp="' . $src . '.webp" data-srcset-webp="' . $webp . '" ', $img );
			},
			$content
		);
	},
	999
);

// Tiny stand-in for EWWW's lazy loader / JS-WebP script.
add_action(
	'wp_footer',
	function () {
		if ( 'ewww' !== get_option( 'plk_mimic' ) ) {
			return;
		}
		echo "<script>(function(){var c=document.createElement('canvas'),w=c.toDataURL&&c.toDataURL('image/webp').indexOf('data:image/webp')===0;"
			. "document.querySelectorAll('[data-srcset],[data-src]').forEach(function(e){if(e.dataset.sizes)e.sizes=e.dataset.sizes;if(e.dataset.srcset)e.srcset=e.dataset.srcset;if(e.dataset.src)e.src=e.dataset.src;});"
			. "if(w)document.querySelectorAll('img[data-srcset-webp]').forEach(function(e){e.srcset=e.dataset.srcsetWebp;e.src=e.dataset.srcWebp;});})();</script>\n";
	},
	99
);
