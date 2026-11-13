<?php
/**
 * Module "modern_images": the smallest image every visitor's browser can show – never a broken image.
 *
 * 1. Conversion: next to every JPEG/PNG upload (and each of its generated sizes) Plakette writes copies in the
 *    EWWW naming scheme  bild.jpg.webp  and  bild.jpg.avif. A copy is only kept when it is smaller than the original;
 *    the AVIF copy additionally only when it is smaller than the WebP copy (the browser picks AVIF first).
 *    Every written file is validated (RIFF/WEBP resp. ftyp avif header, > 0 bytes) – some AVIF encoders (e.g. libavif
 *    in PHP-WASM) report success but write nothing for bigger images.
 *    New uploads: wp_update_attachment_metadata (time-limited, the rest via a single cron event).
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
		// Every metadata update (new upload, regenerated sizes, edited image, site icon …) – fresh copies are skipped.
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'on_upload' ), 99, 2 );
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
		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! wp_attachment_is_image( $id ) || ! preg_match( '#^image/(jpe?g|png)$#', (string) get_post_mime_type( $id ) ) ) {
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
		// Another optimiser (EWWW, CompressX, ShortPixel, a CDN, the server …) already delivers modern formats:
		// never run in parallel (no double <picture>, no second set of copies in the page).
		if ( Plakette_Image_Delivery::foreign() ) {
			return $html;
		}
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
		if ( preg_match( '#\s(data-wp-[a-z-]+|data-eio|data-src-webp|data-srcset-webp)\b#i', $tag ) || preg_match( '#\sclass=["\'][^"\']*\b(plk-no-picture|ewww_webp)#i', $tag ) ) {
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

	/**
	 * Who delivers modern formats on this website? Plakette itself, another optimiser (EWWW, CompressX …) or nobody.
	 * Says what visitors really get – not only whether Plakette's own conversion is switched on.
	 */
	public static function status_html() {
		$foreign = Plakette_Image_Delivery::foreign();           // name => images, measured by the last audit.
		$plugins = Plakette_Image_Delivery::active_optimizers(); // name => mode, by active plugin.
		$p       = function ( $text, $em = false ) {
			return '<p>' . ( $em ? '<em>' : '' ) . esc_html( $text ) . ( $em ? '</em>' : '' ) . '</p>';
		};
		if ( self::active() ) {
			$out = $p( __( 'Eingeschaltet: Plakette wandelt deine Bilder um und liefert jedem Browser das kleinste Format.', 'plakette' ) );
			if ( $plugins ) {
				$out .= $p( sprintf( __( 'Zusätzlich aktiv: %s. Zwei Plugins für dieselbe Aufgabe sind unnötig – eines davon kannst du abschalten.', 'plakette' ), implode( ', ', array_keys( $plugins ) ) ) );
			}
			return $out;
		}
		if ( $foreign ) {
			$parts = array();
			foreach ( $foreign as $name => $n ) {
				$parts[] = sprintf( _n( '%1$s (%2$d Bild bei der letzten Prüfung)', '%1$s (%2$d Bilder bei der letzten Prüfung)', (int) $n, 'plakette' ), $name, (int) $n );
			}
			return $p( sprintf( __( 'Aktiv über ein anderes Plugin: %s liefert deinen Besuchern moderne Bildformate aus.', 'plakette' ), implode( ', ', $parts ) ) )
				. $p( __( 'Plakettes eigene Umwandlung ist deshalb aus – zwei Umwandler parallel würden sich nur in die Quere kommen. Möchtest du das Plugin ersetzen: deaktiviere es, starte die Prüfung neu und wähle beim Prüfpunkt „Moderne Bildformate“ den Knopf „Bilder umwandeln“.', 'plakette' ) );
		}
		if ( $plugins ) {
			return $p( sprintf( __( 'Plakettes eigene Umwandlung ist aus. %s ist aktiv, bei der letzten Prüfung kam aber kein Bild in einem modernen Format bei Besuchern an (oder es wurde noch nicht geprüft). Starte die Prüfung – der Prüfpunkt „Moderne Bildformate“ zeigt, was wirklich ausgeliefert wird.', 'plakette' ), implode( ', ', array_keys( $plugins ) ) ) );
		}
		return $p( __( 'Ausgeschaltet. Einschalten: Prüfung starten und beim Prüfpunkt „Moderne Bildformate“ auf „Bilder umwandeln“ klicken.', 'plakette' ), true );
	}

	public static function info_html() {
		$f   = self::formats();
		$s   = self::stats();
		$yes = esc_html__( 'ja', 'plakette' );
		$no  = esc_html__( 'nein', 'plakette' );
		$out = '<ul>'
			. '<li>' . esc_html( sprintf( __( 'Bildbibliothek des Servers: %s', 'plakette' ), self::library() ) ) . '</li>'
			. '<li>' . esc_html__( 'Das könnte Plakette auf diesem Server selbst erzeugen:', 'plakette' ) . ' AVIF: ' . ( $f['avif'] ? $yes : $no ) . ' · WebP: ' . ( $f['webp'] ? $yes : $no ) . '</li>';
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
			$out .= '<li>' . esc_html__( 'Plakette selbst hat noch keine Bilder umgewandelt.', 'plakette' ) . '</li>';
		}
		return $out . '</ul>';
	}
}

add_action( 'plakette_boot', array( 'Plakette_Modern_Images', 'boot' ) );

/**
 * What does a real visitor receive? Resolves for every image of a page the candidate a browser downloads –
 * independent of who optimises (Plakette, EWWW, CompressX, ShortPixel, Imagify, Optimole, Jetpack, Converter for
 * Media, LiteSpeed, a CDN or the web server):
 *  - inside <picture>: the first <source> with a supported type (avif/webp/…), also lazy data-srcset;
 *  - EWWW JS mode: data-src-webp / data-srcset-webp;  lazy loaders: data-src / data-srcset instead of data: placeholders;
 *  - <noscript> fallbacks, <script>, <template> and comments are ignored;
 *  - the candidate is picked from srcset/sizes for a phone (412 CSS px × DPR 2.6) and a desktop (1366 px × 1);
 *  - the format comes from a request like a browser makes it (Accept: image/avif,image/webp,…) – a .jpg URL that the
 *    server answers with image/avif counts as AVIF (rewrite by Accept header, Vary: Accept).
 */
class Plakette_Image_Delivery {

	const ACCEPT    = 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8';
	const VIEWPORTS = array(
		'mobile'  => array( 412, 2.6 ),
		'desktop' => array( 1366, 1.0 ),
	);
	const MAX_PROBES = 60;
	const OPTION     = 'plakette_image_delivery';

	/** Optimisers by plugin folder: name, delivery mode (picture | js | server | cdn | url). */
	const OPTIMIZERS = array(
		'ewww-image-optimizer'          => array( 'EWWW', 'picture' ),
		'ewww-image-optimizer-cloud'    => array( 'EWWW', 'picture' ),
		'compressx'                     => array( 'CompressX', 'server' ),
		'shortpixel-image-optimiser'    => array( 'ShortPixel', 'picture' ),
		'shortpixel-adaptive-images'    => array( 'ShortPixel', 'cdn' ),
		'imagify'                       => array( 'Imagify', 'picture' ),
		'optimole-wp'                   => array( 'Optimole', 'cdn' ),
		'webp-converter-for-media'      => array( 'Converter for Media', 'server' ),
		'litespeed-cache'               => array( 'LiteSpeed', 'url' ),
		'jetpack'                       => array( 'Jetpack', 'cdn' ),
		'jetpack-boost'                 => array( 'Jetpack Boost', 'cdn' ),
		'wp-smushit'                    => array( 'Smush', 'server' ),
		'webp-uploads'                  => array( 'Modern Image Formats', 'url' ),
		'performance-lab'               => array( 'Performance Lab', 'url' ),
		'webp-express'                  => array( 'WebP Express', 'server' ),
		'wp-optimize'                   => array( 'WP-Optimize', 'server' ),
		'tiny-compress-images'          => array( 'TinyPNG', 'picture' ),
		'wp-rocket'                     => array( 'WP Rocket', 'picture' ),
	);

	private static $probes = array();
	private static $count  = 0;
	private static $ctx    = array();

	/* ------------------------------------------------------------------ plugins */

	/** Active image optimisers (by plugin folder): [ name => mode ]. */
	public static function active_optimizers() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( $active as $plugin ) {
			$dir = strtok( (string) $plugin, '/' );
			if ( isset( self::OPTIMIZERS[ $dir ] ) ) {
				$out[ self::OPTIMIZERS[ $dir ][0] ] = self::OPTIMIZERS[ $dir ][1];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ parsing */

	private static function absolute( $url, $base ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url || str_starts_with( $url, 'data:' ) ) {
			return '';
		}
		if ( str_starts_with( $url, '//' ) ) {
			return 'https:' . $url;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$p    = wp_parse_url( $base );
		$root = ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		if ( str_starts_with( $url, '/' ) ) {
			return $root . $url;
		}
		$path = isset( $p['path'] ) ? preg_replace( '#/[^/]*$#', '/', $p['path'] ) : '/';
		return $root . $path . $url;
	}

	/** First non-empty attribute (skipping data: placeholders). */
	private static function first_attr( $tag, array $names ) {
		foreach ( $names as $n ) {
			$v = Plakette_Html::attr( $tag, $n );
			if ( '' !== trim( $v ) && ! str_starts_with( trim( $v ), 'data:' ) ) {
				return $v;
			}
		}
		return '';
	}

	/** srcset string → [ [ url, w|null, x|null ] ] (absolute URLs, placeholders dropped). */
	public static function parse_srcset( $srcset, $base ) {
		$out = array();
		// URLs may contain commas (Cloudinary, Optimole) – split on comma followed by whitespace or after a descriptor.
		foreach ( preg_split( '/,\s+|(?<=\d[wx]),/', trim( (string) $srcset ) ) as $cand ) {
			$parts = preg_split( '/\s+/', trim( $cand ) );
			$url   = self::absolute( $parts[0] ?? '', $base );
			if ( '' === $url ) {
				continue;
			}
			$d = strtolower( $parts[1] ?? '' );
			$out[] = array(
				'url' => $url,
				'w'   => str_ends_with( $d, 'w' ) ? (float) $d : null,
				'x'   => str_ends_with( $d, 'x' ) ? (float) $d : ( '' === $d ? 1.0 : null ),
			);
		}
		return $out;
	}

	/** Width in CSS px of one sizes length (px, vw, em/rem, simple calc). */
	private static function length( $len, $vw ) {
		$len = trim( strtolower( $len ) );
		if ( preg_match( '/^calc\((.*)\)$/', $len, $m ) ) {
			$total = 0.0;
			if ( preg_match_all( '/([+-]?)\s*([\d.]+)(px|vw|r?em)/', $m[1], $terms, PREG_SET_ORDER ) ) {
				foreach ( $terms as $t ) {
					$v      = self::length( $t[2] . $t[3], $vw );
					$total += '-' === $t[1] ? -$v : $v;
				}
				return max( 1.0, $total );
			}
			return (float) $vw;
		}
		if ( preg_match( '/^([\d.]+)(px|vw|r?em)?$/', $len, $m ) ) {
			$n    = (float) $m[1];
			$unit = $m[2] ?? 'px';
			return 'vw' === $unit ? $n * $vw / 100 : ( 'px' === $unit ? $n : $n * 16 );
		}
		return (float) $vw;
	}

	/** Evaluates a sizes attribute for a viewport width. */
	public static function slot( $sizes, $vw, $attr_width ) {
		$sizes = trim( (string) $sizes );
		if ( '' === $sizes ) {
			return (float) $vw;
		}
		foreach ( explode( ',', $sizes ) as $entry ) {
			$entry = trim( $entry );
			if ( 'auto' === strtolower( $entry ) ) {
				if ( $attr_width > 0 ) {
					return min( (float) $attr_width, (float) $vw );
				}
				continue;
			}
			if ( preg_match( '/^\((.+)\)\s+(\S.*)$/', $entry, $m ) ) {
				$ok = true;
				foreach ( preg_split( '/\)\s*and\s*\(/i', $m[1] ) as $cond ) {
					if ( preg_match( '/(min|max)-width\s*:\s*([\d.]+)(px|r?em)?/i', $cond, $c ) ) {
						$px = (float) $c[2] * ( isset( $c[3] ) && '' !== $c[3] && 'px' !== strtolower( $c[3] ) ? 16 : 1 );
						$ok = $ok && ( 'min' === strtolower( $c[1] ) ? $vw >= $px : $vw <= $px );
					}
				}
				if ( $ok ) {
					return self::length( $m[2], $vw );
				}
				continue;
			}
			return self::length( $entry, $vw );
		}
		return (float) $vw;
	}

	/** Candidate a browser picks for viewport $vw / $dpr. */
	public static function pick( array $cands, $sizes, $vw, $dpr, $attr_width ) {
		if ( ! $cands ) {
			return '';
		}
		$w = array_filter( $cands, fn( $c ) => null !== $c['w'] );
		if ( $w ) {
			usort( $w, fn( $a, $b ) => $a['w'] <=> $b['w'] );
			$need = self::slot( $sizes, $vw, $attr_width ) * $dpr;
			foreach ( $w as $c ) {
				if ( $c['w'] >= $need ) {
					return $c['url'];
				}
			}
			return end( $w )['url'];
		}
		$x = array_filter( $cands, fn( $c ) => null !== $c['x'] );
		usort( $x, fn( $a, $b ) => $a['x'] <=> $b['x'] );
		foreach ( $x as $c ) {
			if ( $c['x'] >= $dpr ) {
				return $c['url'];
			}
		}
		return $x ? end( $x )['url'] : $cands[0]['url'];
	}

	/**
	 * All images of a page with their candidate sets (no network).
	 *
	 * @return array list of [ fallback, fallback_set, sizes, width, set (delivered candidates), via, marker ]
	 */
	public static function images( $html, $base ) {
		$html = preg_replace( '#<(noscript|script|template|style|textarea)\b.*?</\1>|<!--.*?-->#is', '', (string) $html );
		$out  = array();
		if ( ! preg_match_all( '#<picture\b.*?</picture>|<img\b[^>]*>#is', (string) $html, $mm ) ) {
			return $out;
		}
		foreach ( $mm[0] as $chunk ) {
			$picture = 0 === stripos( $chunk, '<picture' );
			if ( ! preg_match( '#<img\b[^>]*>#i', $chunk, $im ) ) {
				continue;
			}
			$img      = $im[0];
			$src      = self::first_attr( $img, array( 'src', 'data-src', 'data-lazy-src', 'data-src-img', 'data-orig-src' ) );
			$srcset   = self::first_attr( $img, array( 'srcset', 'data-srcset', 'data-lazy-srcset', 'data-srcset-img' ) );
			// Lazy loaders: real srcset is in data-srcset while srcset holds a placeholder.
			if ( '' !== Plakette_Html::attr( $img, 'data-srcset' ) && ( '' === Plakette_Html::attr( $img, 'srcset' ) || str_contains( Plakette_Html::attr( $img, 'srcset' ), 'data:' ) ) ) {
				$srcset = Plakette_Html::attr( $img, 'data-srcset' );
			}
			$sizes    = self::first_attr( $img, array( 'sizes', 'data-sizes' ) );
			$fallback = self::parse_srcset( $srcset, $base );
			$first    = self::absolute( $src, $base );
			if ( ! $fallback && '' !== $first ) {
				$fallback = array( array( 'url' => $first, 'w' => null, 'x' => 1.0 ) );
			}
			if ( ! $fallback ) {
				continue;
			}
			$width = (int) Plakette_Html::attr( $img, 'width' );
			$h     = (int) Plakette_Html::attr( $img, 'height' );
			if ( ( $width > 0 && $width < 3 ) || ( $h > 0 && $h < 3 ) ) {
				continue; // tracking pixel
			}
			$set    = $fallback;
			$ssizes = $sizes;
			$via    = 'img';
			$marker = '';
			if ( $picture && preg_match_all( '#<source\b[^>]*>#i', $chunk, $sm ) ) {
				foreach ( $sm[0] as $source ) {
					$type = strtolower( Plakette_Html::attr( $source, 'type' ) );
					if ( '' !== Plakette_Html::attr( $source, 'media' ) && ! preg_match( '/^\s*all\s*$/i', Plakette_Html::attr( $source, 'media' ) ) ) {
						continue; // art direction – keep it simple: the default image decides
					}
					if ( '' !== $type && ! in_array( $type, array( 'image/avif', 'image/webp', 'image/jpeg', 'image/png', 'image/apng', 'image/gif' ), true ) ) {
						continue; // a type Chromium does not support (e.g. image/jxl)
					}
					$ss    = self::first_attr( $source, array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) );
					$cands = self::parse_srcset( $ss, $base );
					if ( $cands ) {
						$set    = $cands;
						$ssizes = self::first_attr( $source, array( 'sizes', 'data-sizes' ) );
						$ssizes = '' !== $ssizes ? $ssizes : $sizes;
						$via    = 'picture';
						$marker = str_contains( $chunk, 'plk-picture' ) ? 'Plakette' : ( str_contains( $chunk, 'data-eio' ) ? 'EWWW' : '' );
						break;
					}
				}
			} else {
				// EWWW JS-WebP mode: <img data-eio="j" data-src-webp="…" data-srcset-webp="…">
				$wss = Plakette_Html::attr( $img, 'data-srcset-webp' );
				$ws  = Plakette_Html::attr( $img, 'data-src-webp' );
				if ( '' !== $wss || '' !== $ws ) {
					$cands = '' !== $wss ? self::parse_srcset( $wss, $base ) : array( array( 'url' => self::absolute( $ws, $base ), 'w' => null, 'x' => 1.0 ) );
					if ( $cands && '' !== $cands[0]['url'] ) {
						$set    = $cands;
						$via    = 'js';
						$marker = 'EWWW';
					}
				}
			}
			if ( '' === $marker && str_contains( $img, 'data-eio' ) ) {
				$marker = 'EWWW';
			}
			$out[] = array(
				'fallback'     => $first ? $first : $fallback[0]['url'],
				'fallback_set' => $fallback,
				'sizes'        => $ssizes,
				'img_sizes'    => $sizes,
				'width'        => $width,
				'set'          => $set,
				'via'          => $via,
				'marker'       => $marker,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ network */

	private static function format_of( $mime_or_url ) {
		$s = strtolower( (string) $mime_or_url );
		foreach ( array( 'avif', 'webp', 'png', 'gif', 'svg' ) as $f ) {
			if ( str_contains( $s, 'image/' . $f ) || preg_match( '/\.' . $f . '(\?|#|$)/', $s ) ) {
				return $f;
			}
		}
		return ( str_contains( $s, 'jpeg' ) || preg_match( '/\.jpe?g(\?|#|$)/', $s ) ) ? 'jpeg' : '';
	}

	/**
	 * Requests an image like a browser (Accept with AVIF/WebP). Cached per request and for a day.
	 *
	 * @return array [ format, bytes, negotiated (Vary: Accept), ok ]
	 */
	public static function probe( $url ) {
		if ( isset( self::$probes[ $url ] ) ) {
			return self::$probes[ $url ];
		}
		$tkey   = 'plakette_imgprobe_' . md5( $url );
		$cached = get_transient( $tkey );
		if ( is_array( $cached ) ) {
			return self::$probes[ $url ] = $cached;
		}
		$res = array( 'format' => self::format_of( $url ), 'bytes' => 0, 'negotiated' => false, 'ok' => false );
		if ( self::$count >= self::MAX_PROBES ) {
			$local = Plakette_Performance::local_path( $url );
			if ( $local && is_file( $local ) ) {
				$res['bytes'] = (int) filesize( $local );
			}
			return self::$probes[ $url ] = $res;
		}
		++self::$count;
		$args = array(
			'timeout'     => 8,
			'redirection' => 3,
			'sslverify'   => false,
			'headers'     => array( 'Accept' => self::ACCEPT ),
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Plakette',
		);
		$r     = wp_remote_head( $url, $args );
		$len   = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_header( $r, 'content-length' );
		$code  = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		if ( is_wp_error( $r ) || $code >= 400 || ! $len ) {
			$r    = wp_remote_get( $url, $args + array( 'limit_response_size' => 8 * MB_IN_BYTES ) );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			$len  = is_wp_error( $r ) ? 0 : strlen( (string) wp_remote_retrieve_body( $r ) );
		}
		if ( ! is_wp_error( $r ) && $code > 0 && $code < 400 ) {
			$type              = (string) wp_remote_retrieve_header( $r, 'content-type' );
			$fmt               = self::format_of( $type );
			$res['format']     = '' !== $fmt ? $fmt : $res['format'];
			$res['bytes']      = $len;
			$res['negotiated'] = (bool) preg_match( '/\baccept\b/i', (string) wp_remote_retrieve_header( $r, 'vary' ) ) || ( '' !== $fmt && $fmt !== self::format_of( $url ) && in_array( $fmt, array( 'avif', 'webp' ), true ) );
			$res['ok']         = true;
			set_transient( $tkey, $res, HOUR_IN_SECONDS );
		}
		return self::$probes[ $url ] = $res;
	}

	/** Who delivers this image in a modern format? */
	private static function who( array $img, array $d ) {
		if ( '' !== $img['marker'] ) {
			return $img['marker'];
		}
		$host = strtolower( (string) wp_parse_url( $d['url'], PHP_URL_HOST ) );
		if ( preg_match( '/(^|\.)i\d\.wp\.com$/', $host ) ) {
			return 'Jetpack';
		}
		if ( str_contains( $host, 'optimole' ) ) {
			return 'Optimole';
		}
		if ( str_contains( $host, 'shortpixel' ) ) {
			return 'ShortPixel';
		}
		$opt = self::active_optimizers();
		foreach ( $opt as $name => $mode ) {
			if ( 'server' === $d['how'] && in_array( $mode, array( 'server', 'cdn' ), true ) ) {
				return $name;
			}
			if ( 'server' !== $d['how'] && in_array( $mode, array( 'picture', 'url' ), true ) ) {
				return $name;
			}
		}
		if ( 'server' === $d['how'] ) {
			return __( 'der Server', 'plakette' );
		}
		$path = Plakette_Performance::local_path( $img['fallback'] );
		return $path && preg_match( '/\.(webp|avif)$/i', $path ) ? __( 'die Mediathek (Upload ist schon modern)', 'plakette' ) : __( 'die Website', 'plakette' );
	}

	/**
	 * Delivered candidate per viewport.
	 *
	 * @return array images with mobile/desktop => [ url, format, bytes, how (url|picture|js|server) ], who, attachment
	 */
	public static function analyse( $html, $base ) {
		$out = array();
		foreach ( self::images( $html, $base ) as $img ) {
			$row = $img;
			foreach ( self::VIEWPORTS as $vp => $v ) {
				$url = self::pick( $img['set'], $img['sizes'], $v[0], $v[1], $img['width'] );
				$p   = self::probe( $url );
				$how = in_array( $img['via'], array( 'picture', 'js' ), true ) ? $img['via'] : 'url';
				if ( $p['negotiated'] && in_array( $p['format'], array( 'avif', 'webp' ), true ) && ! in_array( self::format_of( $url ), array( 'avif', 'webp' ), true ) ) {
					$how = 'server';
				}
				$row[ $vp ] = array( 'url' => $url, 'format' => $p['format'], 'bytes' => $p['bytes'], 'how' => $how );
			}
			$row['modern']     = in_array( $row['desktop']['format'], array( 'avif', 'webp' ), true ) && in_array( $row['mobile']['format'], array( 'avif', 'webp' ), true );
			$row['who']        = $row['modern'] ? self::who( $img, $row['desktop'] ) : '';
			// Delivered by an optimiser (not just an upload that already is WebP/AVIF)?
			$row['optimized'] = $row['modern'] && ( in_array( $row['desktop']['how'], array( 'picture', 'js', 'server' ), true )
				|| '' !== $img['marker']
				|| preg_match( '/\.(jpe?g|png)\.(webp|avif)(\?|$)/i', $row['desktop']['url'] )
				|| in_array( $row['who'], array( 'Jetpack', 'Optimole', 'ShortPixel' ), true ) );
			$row['attachment'] = self::attachment_id( $img['fallback'] );
			$out[]             = $row;
		}
		return $out;
	}

	/** Attachment id of a (size variant) upload URL, 0 if none. */
	public static function attachment_id( $url ) {
		static $cache = array();
		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}
		$clean = (string) strtok( $url, '?#' );
		$id    = attachment_url_to_postid( $clean );
		if ( ! $id ) {
			$id = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $clean ) );
		}
		if ( ! $id ) {
			$id = attachment_url_to_postid( preg_replace( '/(-\d+x\d+)?(\.\w+)$/', '-scaled$2', $clean, 1 ) );
		}
		return $cache[ $url ] = (int) $id;
	}

	/** Analysis of home + sample pages, once per audit. Also stored for the HTML transform. */
	public static function for_context( Plakette_Context $ctx ) {
		$key = spl_object_id( $ctx );
		if ( isset( self::$ctx[ $key ] ) ) {
			return self::$ctx[ $key ];
		}
		$images = array();
		$seen   = array();
		foreach ( $ctx->all_html() as $id => $html ) {
			$base = $id ? (string) get_permalink( $id ) : home_url( '/' );
			foreach ( self::analyse( $html, $base ) as $row ) {
				$k = $row['fallback'] . '|' . $row['via'];
				if ( isset( $seen[ $k ] ) ) {
					continue;
				}
				$seen[ $k ]  = true;
				$row['page'] = $base;
				$images[]    = $row;
			}
		}
		$foreign = array();
		$server  = false;
		foreach ( $images as $row ) {
			if ( $row['optimized'] && 'Plakette' !== $row['who'] ) {
				$foreign[ $row['who'] ] = ( $foreign[ $row['who'] ] ?? 0 ) + 1;
				$server                 = $server || 'server' === $row['desktop']['how'];
			}
		}
		update_option(
			self::OPTION,
			array(
				'foreign' => $foreign,
				'server'  => $server,
				'time'    => time(),
			),
			false
		);
		return self::$ctx[ $key ] = $images;
	}

	/** Raster images only (no SVG/GIF, nothing unknown). */
	public static function raster( array $images ) {
		return array_values( array_filter( $images, fn( $r ) => in_array( $r['desktop']['format'], array( 'jpeg', 'png', 'webp', 'avif' ), true ) ) );
	}

	/** Who else serves modern formats here (found by the last audit): [ name => images ]. */
	public static function foreign() {
		$d = get_option( self::OPTION, array() );
		return is_array( $d ) && ! empty( $d['foreign'] ) ? (array) $d['foreign'] : array();
	}

	/** Modern sibling of a library file on disk (EWWW/Plakette/LiteSpeed: bild.jpg.webp, ShortPixel/Imagify: bild.webp, Converter for Media, CompressX). */
	public static function has_modern_copy( $path ) {
		return self::modern_copy_bytes( $path ) > 0;
	}

	/** Bytes of the smallest modern sibling on disk (0 = none). */
	public static function modern_copy_bytes( $path ) {
		$cands = array();
		foreach ( array( 'avif', 'webp' ) as $f ) {
			$cands[] = $path . '.' . $f;
			$cands[] = preg_replace( '/\.\w+$/', '.' . $f, $path );
			foreach ( array( 'uploads-webpc', 'compressx-nextgen' ) as $dir ) {
				$cands[] = str_replace( WP_CONTENT_DIR . '/', WP_CONTENT_DIR . '/' . $dir . '/', $path ) . '.' . $f;
			}
		}
		$min = 0;
		foreach ( $cands as $c ) {
			if ( $c !== $path && is_file( $c ) && filesize( $c ) > 0 ) {
				$min = $min ? min( $min, (int) filesize( $c ) ) : (int) filesize( $c );
			}
		}
		return $min;
	}

	/** One line for notes: "bild.jpg – JPEG, 120 KB am Handy, 310 KB am Desktop". */
	public static function describe( array $row ) {
		$name = wp_basename( (string) wp_parse_url( $row['fallback'], PHP_URL_PATH ) );
		$m    = $row['mobile'];
		$d    = $row['desktop'];
		$kb   = fn( $b ) => $b ? size_format( $b, $b >= MB_IN_BYTES ? 1 : 0 ) : '?';
		if ( $m['url'] === $d['url'] ) {
			return sprintf( '%s – %s, %s', $name, self::label( $d['format'] ), $kb( $d['bytes'] ) );
		}
		return sprintf( __( '%1$s – %2$s, %3$s am Handy, %4$s am Desktop', 'plakette' ), $name, self::label( $d['format'] ), $kb( $m['bytes'] ), $kb( $d['bytes'] ) );
	}

	public static function label( $format ) {
		return array( 'avif' => 'AVIF', 'webp' => 'WebP', 'jpeg' => 'JPEG', 'png' => 'PNG', 'gif' => 'GIF', 'svg' => 'SVG' )[ $format ] ?? '?';
	}
}

/**
 * Which library images are used anywhere on the website?
 *
 * The check for big images must not blame files that only lie in the media library: nobody downloads them.
 * One pass over the content of the site collects every image file name it mentions – post content (all post types
 * except revisions), post meta (page builders such as Breakdance, Elementor, Bricks and ACF keep their data there),
 * term meta and the options of theme, widgets and builders – plus the attachments used by id (featured images,
 * product galleries, logo, site icon, core image blocks, ACF image fields).
 * File names are compared without folder, size suffix (-300x200, -scaled) and extension, so every size variant and
 * every modern copy (bild.jpg.webp) counts for its original. A name that appears in any content counts as "used":
 * in doubt an image stays in the list (no false all-clear).
 * The result is cached for ten minutes (transient) and dropped when content changes.
 */
class Plakette_Image_Usage {

	const CACHE = 'plakette_image_usage';
	const EXT   = 'jpe?g|png|webp|avif|gif';

	public static function boot() {
		foreach ( array( 'save_post', 'deleted_post', 'switch_theme', 'customize_save_after' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ), 10, 0 );
		}
	}

	public static function flush() {
		static $done = false;
		if ( ! $done ) {
			$done = true;
			delete_transient( self::CACHE );
		}
	}

	/** Comparable name of an image file: no folder, no size suffix, no extension(s), lower case. */
	public static function key( $file ) {
		$name = strtolower( rawurldecode( wp_basename( str_replace( '\\', '/', (string) $file ) ) ) );
		$name = preg_replace( '#(\.(' . self::EXT . '))+$#', '', $name );
		return preg_replace( '#(-\d+x\d+|-scaled|-rotated|-e\d{10,})+$#', '', $name );
	}

	/** Adds every image file name found in $text to $names. */
	private static function collect( $text, array &$names ) {
		if ( ! is_string( $text ) || '' === $text || ! preg_match( '#\.(?:' . self::EXT . ')#i', $text ) ) {
			return;
		}
		if ( preg_match_all( '#([^\s"\'\\\\/<>()?\#,=;:&|]+)\.(?:' . self::EXT . ')(?![a-z0-9])#i', $text, $m ) ) {
			foreach ( $m[1] as $name ) {
				$names[ self::key( $name . '.jpg' ) ] = true;
			}
		}
	}

	/** @return array [ names => key => true, ids => attachment id => true ] */
	public static function index() {
		global $wpdb;
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['names'], $cached['ids'] ) ) {
			return $cached;
		}
		$names = array();
		$ids   = array();

		// Post content of everything that is not an attachment or a revision (pages, posts, templates, blocks, products …).
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ('attachment','revision') AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID ASC LIMIT 200", $last ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $row ) {
				$last = (int) $row->ID;
				self::collect( $row->post_content . ' ' . $row->post_excerpt, $names );
				if ( preg_match_all( '#wp-image-(\d+)|"(?:id|mediaId|mediaID)":(\d+)|\bids=["\']([\d,\s]+)#', $row->post_content, $m ) ) {
					foreach ( array_merge( $m[1], $m[2], explode( ',', implode( ',', $m[3] ) ) ) as $id ) {
						if ( (int) $id ) {
							$ids[ (int) $id ] = true;
						}
					}
				}
			}
		} while ( count( (array) $rows ) === 200 );

		// Post meta: builder data and custom fields. The attachment's own bookkeeping (and backups of it) does not count.
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_id > %d AND p.post_type NOT IN ('attachment','revision') AND p.post_status NOT IN ('trash','auto-draft') AND ( pm.meta_key IN ('_thumbnail_id','_product_image_gallery') OR pm.meta_value REGEXP %s ) ORDER BY pm.meta_id ASC LIMIT 200", $last, '\\.(jpe?g|png|webp|avif|gif)' ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $row ) {
				$last = (int) $row->meta_id;
				if ( '_thumbnail_id' === $row->meta_key || '_product_image_gallery' === $row->meta_key ) {
					foreach ( explode( ',', (string) $row->meta_value ) as $id ) {
						if ( (int) $id ) {
							$ids[ (int) $id ] = true;
						}
					}
					continue;
				}
				self::collect( $row->meta_value, $names );
			}
		} while ( count( (array) $rows ) === 200 );

		// ACF image/gallery fields keep attachment ids: a meta value "123" whose companion "_key" points to an ACF field.
		$acf = $wpdb->get_col( "SELECT v.meta_value FROM {$wpdb->postmeta} k JOIN {$wpdb->postmeta} v ON v.post_id = k.post_id AND v.meta_key = SUBSTRING(k.meta_key, 2) WHERE k.meta_key LIKE '\\_%' AND k.meta_value LIKE 'field\\_%' AND ( v.meta_value REGEXP '^[0-9]+$' OR v.meta_value LIKE 'a:%' ) LIMIT 5000" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $acf as $value ) {
			$list = is_numeric( $value ) ? array( $value ) : (array) maybe_unserialize( $value );
			foreach ( $list as $id ) {
				if ( is_scalar( $id ) && ctype_digit( (string) $id ) && (int) $id ) {
					$ids[ (int) $id ] = true;
				}
			}
		}

		// Term meta (category images) and options (theme settings, widgets, builder settings) – not Plakette's own data.
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_value REGEXP %s LIMIT 2000", '\\.(jpe?g|png|webp|avif|gif)' ) ) as $value ) { // phpcs:ignore WordPress.DB
			self::collect( $value, $names );
		}
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_value REGEXP %s LIMIT 2000", 'plakette\\_%', '\\_transient\\_%', '\\_site\\_transient\\_%', '\\.(jpe?g|png|webp|avif|gif)' ) ) as $value ) { // phpcs:ignore WordPress.DB
			self::collect( $value, $names );
		}
		foreach ( array( (int) get_option( 'site_icon' ), (int) get_theme_mod( 'custom_logo' ), (int) get_theme_mod( 'header_image_data' ), (int) get_theme_mod( 'background_image_thumb' ) ) as $id ) {
			if ( $id ) {
				$ids[ $id ] = true;
			}
		}
		$index = array(
			'names' => $names,
			'ids'   => $ids,
		);
		set_transient( self::CACHE, $index, 10 * MINUTE_IN_SECONDS );
		return $index;
	}

	/** Is this library image used anywhere on the website? */
	public static function is_used( $attachment_id, $file = '' ) {
		$index = self::index();
		if ( isset( $index['ids'][ (int) $attachment_id ] ) ) {
			return true;
		}
		$file = '' !== $file ? $file : (string) get_attached_file( $attachment_id );
		return '' !== $file && isset( $index['names'][ self::key( $file ) ] );
	}

	/**
	 * Public addresses of published pages that show this library image (post content or builder data).
	 * Templates and global blocks have no address of their own and are not returned.
	 *
	 * @return string[] up to $limit permalinks
	 */
	public static function pages( $attachment_id, $file = '', $limit = 3 ) {
		global $wpdb;
		$file = '' !== $file ? $file : (string) get_attached_file( $attachment_id );
		$key  = '' !== $file ? self::key( $file ) : '';
		if ( strlen( $key ) < 4 ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $key ) . '%';
		$ids  = array_merge(
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('attachment','revision') AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY post_modified DESC LIMIT 20", $like, '%wp-image-' . (int) $attachment_id . '%' ) ), // phpcs:ignore WordPress.DB
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_status = 'publish' AND p.post_type NOT IN ('attachment','revision') AND ( pm.meta_value LIKE %s OR ( pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s ) ) LIMIT 20", $like, (string) (int) $attachment_id ) ) // phpcs:ignore WordPress.DB
		);
		$out = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( ! is_post_publicly_viewable( $id ) || post_password_required( $id ) ) {
				continue;
			}
			$url = get_permalink( $id );
			if ( $url ) {
				$out[ $url ] = $url;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return array_values( $out );
	}
}

add_action( 'plakette_boot', array( 'Plakette_Image_Usage', 'boot' ) );
