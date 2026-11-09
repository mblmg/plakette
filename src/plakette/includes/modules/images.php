<?php
/**
 * Module "modern_images": the smallest image every visitor's browser can show – never a broken image.
 *
 * 1. Conversion: next to every JPEG/PNG upload (and each of its generated sizes) Plakette writes copies in the
 *    EWWW naming scheme  bild.jpg.webp  and  bild.jpg.avif. A copy is only kept when it is smaller than the original;
 *    the AVIF copy additionally only when it is smaller than the WebP copy (the browser picks AVIF first).
 *    Every written file is validated (RIFF/WEBP resp. ftyp avif header, > 0 bytes) – some AVIF encoders (e.g. libavif
 *    in PHP-WASM) report success but write nothing for bigger images.
 *    New uploads: wp_generate_attachment_metadata (time-limited, the rest via a single cron event).
 *    Existing images: the fix of the check speed_webp in batches; the undo deletes exactly the files Plakette wrote.
 *    Postmeta _plakette_modern = [ files => [ relative path => bytes ] (written by Plakette), saved, time, done ].
 *
 * 2. Delivery (HTML pipeline): local upload images with copies are wrapped in
 *      <picture style="display:contents"><source type="image/avif" srcset sizes><source type="image/webp" …><img …></picture>
 *    The original <img> stays untouched as fallback (Safari < 16, Edge < 121 and every browser without AVIF/WebP).
 *    A format is only offered when ALL srcset candidates have a fresh copy (never a blurry upscaled candidate).
 *    Skipped: images already inside <picture>, in <noscript>, SVG/GIF/WebP/AVIF, external images, images with
 *    data-wp-* directives (core lightbox: its CSS uses "img:hover + button") and the opt-out class plk-no-picture.
 *
 * Quality (measured on tests/fixtures with tests/tools/quality.sh, SSIM against a lossless reference):
 *   WebP 80 ≈ AVIF 63 visually; at that quality AVIF is ~20–25 % smaller than WebP and ~60 % smaller than JPEG 82.
 *   Filters: plakette_avif_quality (63), plakette_webp_quality (80).
 *
 * Replaces the former modules webp_uploads (changed WordPress' output format) and lcp_webp (LCP image only);
 * both keys still switch this module on.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Modern_Images {

	const META         = '_plakette_modern';
	const STATS        = 'plakette_modern_stats';
	const AVIF_QUALITY = 63;
	const WEBP_QUALITY = 80;
	const BUDGET       = 18; // seconds per request for batch conversions.
	const MIME         = array(
		'avif' => 'image/avif',
		'webp' => 'image/webp',
	);

	/* ================================================================== state */

	public static function active() {
		return Plakette_Modules::on( 'modern_images' ) || Plakette_Modules::on( 'webp_uploads' ) || Plakette_Modules::on( 'lcp_webp' );
	}

	/** Which formats can this server write? [ 'avif' => bool, 'webp' => bool ] */
	public static function formats() {
		static $f = null;
		if ( null === $f ) {
			$f = array(
				'avif' => (bool) apply_filters( 'plakette_modern_avif', wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ),
				'webp' => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ),
			);
		}
		return $f;
	}

	/** Name of the image library WordPress uses (GD / Imagick). */
	public static function library() {
		$editor = _wp_image_editor_choose( array( 'mime_type' => 'image/jpeg' ) );
		return $editor ? str_replace( 'WP_Image_Editor_', '', (string) $editor ) : '–';
	}

	public static function quality( $format ) {
		return 'avif' === $format
			? (int) apply_filters( 'plakette_avif_quality', self::AVIF_QUALITY )
			: (int) apply_filters( 'plakette_webp_quality', self::WEBP_QUALITY );
	}

	/* ================================================================== boot */

	public static function boot() {
		add_action( 'plakette_daily', array( __CLASS__, 'daily' ) );
		add_action( 'plakette_modern_convert', array( __CLASS__, 'cron_convert' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'delete_copies' ) );
		if ( ! self::active() ) {
			return;
		}
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 99, 2 );
		Plakette_Html::add( 'modern_images', array( __CLASS__, 'transform' ), 7 );
	}

	/* ================================================================== conversion */

	/** Is the file a valid image of this format? */
	private static function valid( $file, $format ) {
		if ( ! is_file( $file ) || filesize( $file ) < 16 ) {
			return false;
		}
		$head = (string) file_get_contents( $file, false, null, 0, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( 'webp' === $format ) {
			return 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 );
		}
		return 'ftyp' === substr( $head, 4, 4 ) && in_array( substr( $head, 8, 4 ), array( 'avif', 'avis', 'mif1' ), true );
	}

	/** A copy is usable when it exists, is valid-looking and not older than the original. */
	public static function fresh( $path, $copy ) {
		return is_file( $copy ) && filesize( $copy ) > 0 && filemtime( $copy ) >= filemtime( $path );
	}

	/**
	 * Writes the WebP/AVIF copies of one file.
	 *
	 * @return array [ created => [ path => bytes ], saved => bytes (all valid copies vs. original), avif_failed => bool ]
	 */
	public static function convert_file( $path ) {
		$out = array( 'created' => array(), 'saved' => 0, 'avif_failed' => false );
		if ( ! is_file( $path ) || ! preg_match( '/\.(jpe?g|png)$/i', $path ) ) {
			return $out;
		}
		$orig    = filesize( $path );
		$formats = self::formats();
		$sizes   = array();
		foreach ( array( 'webp', 'avif' ) as $format ) {
			if ( empty( $formats[ $format ] ) ) {
				continue;
			}
			$copy = $path . '.' . $format;
			if ( self::fresh( $path, $copy ) ) {
				$sizes[ $format ] = filesize( $copy );
			} else {
				$bytes = self::encode( $path, $copy, $format );
				if ( $bytes ) {
					$sizes[ $format ]       = $bytes;
					$out['created'][ $copy ] = $bytes;
				} elseif ( 'avif' === $format ) {
					$out['avif_failed'] = true;
				}
			}
			// Keep only what helps: smaller than the original – AVIF also smaller than the WebP copy.
			$limit = $orig;
			if ( 'avif' === $format && isset( $sizes['webp'] ) ) {
				$limit = min( $limit, $sizes['webp'] );
			}
			if ( isset( $sizes[ $format ] ) && $sizes[ $format ] >= $limit ) {
				if ( isset( $out['created'][ $copy ] ) ) {
					wp_delete_file( $copy );
					unset( $out['created'][ $copy ] );
				}
				unset( $sizes[ $format ] );
			}
		}
		if ( $sizes ) {
			$out['saved'] = $orig - min( $sizes );
		}
		return $out;
	}

	/** Encodes one copy (atomic: temp file, validate, rename). @return int bytes or 0 */
	private static function encode( $path, $target, $format ) {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) || ! $editor->supports_mime_type( self::MIME[ $format ] ) ) {
			return 0;
		}
		// WordPress resets the quality when the output format changes – force ours through the filter.
		$q     = self::quality( $format );
		$force = function () use ( $q ) {
			return $q;
		};
		add_filter( 'wp_editor_set_quality', $force, 999 );
		$editor->set_quality( $q );
		$tmp   = $target . '.plk-tmp.' . $format;
		$saved = @$editor->save( $tmp, self::MIME[ $format ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- encoder warnings are handled below.
		remove_filter( 'wp_editor_set_quality', $force, 999 );
		$file = ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ? $saved['path'] : $tmp;
		if ( is_wp_error( $saved ) || ! self::valid( $file, $format ) ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
			return 0;
		}
		if ( ! @rename( $file, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $file );
			return 0;
		}
		clearstatcache( true, $target );
		return (int) filesize( $target );
	}

	/** All files of an attachment: the main file plus every generated size. */
	public static function attachment_files( $id, $meta = null ) {
		$file = get_attached_file( $id );
		if ( ! $file ) {
			return array();
		}
		$meta  = is_array( $meta ) ? $meta : wp_get_attachment_metadata( $id );
		$dir   = dirname( $file );
		$files = array( $file );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
			}
		}
		return array_values( array_unique( array_filter( $files, 'is_file' ) ) );
	}

	/** Has the attachment fresh copies (as far as the server can make them)? */
	public static function is_done( $id ) {
		$m = get_post_meta( $id, self::META, true );
		if ( ! is_array( $m ) || empty( $m['done'] ) ) {
			return false;
		}
		// The original changed after the conversion (edited, resized …) → convert again.
		$file = get_attached_file( $id );
		return ! $file || ! is_file( $file ) || (int) $m['time'] >= filemtime( $file );
	}

	/**
	 * Converts one attachment until $deadline.
	 *
	 * @return array [ created => [ path => bytes ], saved, done ]
	 */
	public static function convert_attachment( $id, $deadline, $meta = null ) {
		$upload  = wp_get_upload_dir();
		$base    = trailingslashit( wp_normalize_path( $upload['basedir'] ) );
		$old     = get_post_meta( $id, self::META, true );
		$known   = is_array( $old ) && isset( $old['files'] ) ? (array) $old['files'] : array();
		$created = array();
		$saved   = 0;
		$done    = true;
		$avif_ko = 0;
		foreach ( self::attachment_files( $id, $meta ) as $path ) {
			if ( microtime( true ) > $deadline ) {
				$done = false;
				break;
			}
			$r       = self::convert_file( $path );
			$saved  += $r['saved'];
			$avif_ko += $r['avif_failed'] ? 1 : 0;
			foreach ( $r['created'] as $copy => $bytes ) {
				$created[ $copy ] = $bytes;
				$rel              = str_replace( $base, '', wp_normalize_path( $copy ) );
				$known[ $rel ]    = $bytes;
			}
		}
		// Forget copies that no longer exist (e.g. not kept because bigger).
		foreach ( array_keys( $known ) as $rel ) {
			if ( ! is_file( $base . $rel ) ) {
				unset( $known[ $rel ] );
			}
		}
		update_post_meta(
			$id,
			self::META,
			array(
				'files'       => $known,
				'saved'       => $saved,
				'time'        => time(),
				'done'        => $done,
				'avif_failed' => $avif_ko,
			)
		);
		return array( 'created' => $created, 'saved' => $saved, 'done' => $done );
	}

	/** New uploads: convert right away (time-limited), the rest in the background. */
	public static function on_upload( $meta, $id ) {
		if ( ! wp_attachment_is_image( $id ) || ! preg_match( '#^image/(jpe?g|png)$#', (string) get_post_mime_type( $id ) ) ) {
			return $meta;
		}
		$r = self::convert_attachment( $id, microtime( true ) + 8, $meta );
		if ( ! $r['done'] && ! wp_next_scheduled( 'plakette_modern_convert', array( (int) $id ) ) ) {
			wp_schedule_single_event( time() + 30, 'plakette_modern_convert', array( (int) $id ) );
		}
		return $meta;
	}

	public static function cron_convert( $id ) {
		if ( self::active() && get_post( (int) $id ) ) {
			self::convert_attachment( (int) $id, microtime( true ) + self::BUDGET );
		}
	}

	/** Daily: convert what is still missing (max. one budget). */
	public static function daily() {
		if ( ! self::active() ) {
			return;
		}
		$deadline = microtime( true ) + self::BUDGET;
		foreach ( self::todo( 200 ) as $id ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			self::convert_attachment( $id, $deadline );
		}
	}

	/** JPEG/PNG attachments without (fresh) copies. @return int[] */
	public static function todo( $limit = 2000 ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png') ORDER BY ID DESC LIMIT %d", 5000 ) ); // phpcs:ignore WordPress.DB
		$out = array();
		foreach ( $ids as $id ) {
			if ( ! self::is_done( (int) $id ) ) {
				$out[] = (int) $id;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}

	/** Removes the copies Plakette wrote for an attachment (when it is deleted). */
	public static function delete_copies( $id ) {
		$m = get_post_meta( $id, self::META, true );
		if ( ! is_array( $m ) || empty( $m['files'] ) ) {
			return;
		}
		$upload = wp_get_upload_dir();
		foreach ( array_keys( (array) $m['files'] ) as $rel ) {
			$file = trailingslashit( $upload['basedir'] ) . ltrim( (string) $rel, '/' );
			if ( false === strpos( (string) $rel, '..' ) && preg_match( '/\.(webp|avif)$/', $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		delete_post_meta( $id, self::META );
	}

	/** Undo of the module: removes every copy Plakette wrote. */
	public static function clear_all() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			self::delete_copies( (int) $id );
		}
	}

	/** Totals for the settings panel. */
	public static function stats() {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 5000", self::META ) ); // phpcs:ignore WordPress.DB
		$s    = array( 'images' => 0, 'avif' => 0, 'webp' => 0, 'saved' => 0, 'avif_failed' => 0 );
		foreach ( $rows as $raw ) {
			$m = maybe_unserialize( $raw );
			if ( ! is_array( $m ) ) {
				continue;
			}
			++$s['images'];
			$s['saved']       += (int) $m['saved'];
			$s['avif_failed'] += isset( $m['avif_failed'] ) ? (int) $m['avif_failed'] : 0;
			foreach ( array_keys( (array) $m['files'] ) as $rel ) {
				$ext = substr( (string) $rel, -4 );
				if ( isset( $s[ $ext ] ) ) {
					++$s[ $ext ];
				}
			}
		}
		return $s;
	}

	/* ================================================================== delivery */

	/** URL of a fresh copy of an upload in this format, or ''. */
	public static function copy_url( $url, $format ) {
		static $cache = array();
		$key = $format . '|' . $url;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$cache[ $key ] = '';
		$clean         = (string) strtok( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ), '?#' );
		if ( ! preg_match( '/\.(jpe?g|png)$/i', $clean ) ) {
			return '';
		}
		$path = Plakette_Performance::local_path( $clean );
		$up   = wp_get_upload_dir();
		if ( ! $path || 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( $up['basedir'] ) ) || ! is_file( $path ) ) {
			return '';
		}
		if ( self::fresh( $path, $path . '.' . $format ) ) {
			$cache[ $key ] = $clean . '.' . $format;
		}
		return $cache[ $key ];
	}

	/** srcset with every candidate in this format – or '' (never half). */
	public static function convert_srcset( $srcset, $format ) {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $srcset ) ) ) as $cand ) {
			$parts = preg_split( '/\s+/', $cand, 2 );
			$u     = self::copy_url( $parts[0], $format );
			if ( '' === $u ) {
				return '';
			}
			$out[] = $u . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
		}
		return implode( ', ', $out );
	}

	/**
	 * Best variant for a preload (LCP image): [ type, href, srcset ] of AVIF, else WebP – or null.
	 * Only ONE preload is printed (with type): browsers without that format skip it and load the <img> normally.
	 */
	public static function best_variant( $src, $srcset ) {
		if ( ! self::active() ) {
			return null;
		}
		foreach ( array( 'avif', 'webp' ) as $format ) {
			$s  = '' !== (string) $srcset ? self::convert_srcset( $srcset, $format ) : '';
			$u  = self::copy_url( $src, $format );
			$ok = '' !== (string) $srcset ? '' !== $s : '' !== $u;
			if ( $ok ) {
				return array(
					'type'   => self::MIME[ $format ],
					'href'   => '' !== $u ? $u : $src,
					'srcset' => $s,
				);
			}
		}
		return null;
	}

	/** Transform for Plakette_Html. */
	public static function transform( $html ) {
		return Plakette_Html::outside_code(
			$html,
			function ( $part ) {
				// <picture> and <noscript> blocks stay as they are.
				$pieces = preg_split( '#(<picture\b.*?</picture>|<noscript\b.*?</noscript>)#is', $part, -1, PREG_SPLIT_DELIM_CAPTURE );
				if ( ! is_array( $pieces ) ) {
					return $part;
				}
				foreach ( $pieces as $i => $piece ) {
					if ( 0 === $i % 2 && false !== stripos( $piece, '<img' ) ) {
						$new          = preg_replace_callback( '#<img\b[^>]*>#i', array( __CLASS__, 'wrap' ), $piece );
						$pieces[ $i ] = null === $new ? $piece : $new;
					}
				}
				return implode( '', $pieces );
			}
		);
	}

	/** Wraps one <img> in <picture> when copies exist. */
	public static function wrap( array $m ) {
		$tag = $m[0];
		if ( preg_match( '#\sdata-wp-[a-z-]+#i', $tag ) || preg_match( '#\sclass=["\'][^"\']*\bplk-no-picture\b#i', $tag ) ) {
			return $tag;
		}
		$src    = Plakette_Html::attr( $tag, 'src' );
		$srcset = Plakette_Html::attr( $tag, 'srcset' );
		$sizes  = Plakette_Html::attr( $tag, 'sizes' );
		$lazy   = false;
		// Lazy-load placeholders (data:… / empty src + data-src): the sources must be lazy, too.
		$dsrc = Plakette_Html::attr( $tag, 'data-src' );
		if ( '' !== $dsrc && ( '' === $src || 0 === strpos( $src, 'data:' ) ) ) {
			$lazy   = true;
			$src    = $dsrc;
			$srcset = Plakette_Html::attr( $tag, 'data-srcset' );
			$dsizes = Plakette_Html::attr( $tag, 'data-sizes' );
			$sizes  = '' !== $dsizes ? $dsizes : $sizes;
		}
		if ( '' === $src || ! preg_match( '/\.(jpe?g|png)(\?|#|$)/i', $src ) ) {
			return $tag;
		}
		$sources = '';
		foreach ( array( 'avif', 'webp' ) as $format ) {
			$set = '' !== $srcset ? self::convert_srcset( $srcset, $format ) : self::copy_url( $src, $format );
			if ( '' === $set ) {
				continue;
			}
			$sources .= '<source type="' . self::MIME[ $format ] . '" ' . ( $lazy ? 'data-srcset' : 'srcset' ) . '="' . esc_attr( $set ) . '"'
				. ( '' !== $sizes ? ' ' . ( $lazy && '' !== Plakette_Html::attr( $tag, 'data-sizes' ) ? 'data-sizes' : 'sizes' ) . '="' . esc_attr( $sizes ) . '"' : '' ) . '>';
		}
		if ( '' === $sources ) {
			return $tag;
		}
		return '<picture class="plk-picture" style="display:contents">' . $sources . $tag . '</picture>';
	}

	/* ================================================================== settings (info) */

	public static function info_html() {
		$f   = self::formats();
		$s   = self::stats();
		$yes = esc_html__( 'ja', 'plakette' );
		$no  = esc_html__( 'nein', 'plakette' );
		$out = '<ul>'
			. '<li>' . esc_html( sprintf( __( 'Bildbibliothek des Servers: %s', 'plakette' ), self::library() ) ) . '</li>'
			. '<li>AVIF: ' . ( $f['avif'] ? $yes : $no ) . ' · WebP: ' . ( $f['webp'] ? $yes : $no ) . '</li>';
		if ( $s['images'] ) {
			$out .= '<li>' . esc_html(
				sprintf(
					/* translators: 1: images, 2: avif files, 3: webp files, 4: bytes */
					__( '%1$d Bilder umgewandelt: %2$d AVIF- und %3$d WebP-Dateien, zusammen %4$s gespart (je Bildgröße, bei Auslieferung des kleinsten Formats).', 'plakette' ),
					$s['images'],
					$s['avif'],
					$s['webp'],
					size_format( $s['saved'], 1 )
				)
			) . '</li>';
			if ( $f['avif'] && $s['avif_failed'] ) {
				$out .= '<li>' . esc_html( sprintf( __( 'Bei %d Bildgrößen hat der AVIF-Encoder des Servers nichts erzeugt (meist große Bilder) – dort gibt es WebP.', 'plakette' ), $s['avif_failed'] ) ) . '</li>';
			}
		} else {
			$out .= '<li>' . esc_html__( 'Noch keine Bilder umgewandelt.', 'plakette' ) . '</li>';
		}
		return $out . '</ul>';
	}
}

add_action( 'plakette_boot', array( 'Plakette_Modern_Images', 'boot' ) );
