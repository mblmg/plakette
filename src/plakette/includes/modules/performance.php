<?php
/**
 * Runtime features for speed and accessibility that page-cache plugins do not cover.
 * Ported from the "i-make.media Toolkit for Breakdance" (performance.php), works with every theme/builder.
 *
 * Module keys (switched on by the fixes of the checks in checks-toolkit.php / checks-speed.php):
 *   lcp_auto   – LCP image: first big image in <main> (not in <header>) loads at once: no lazy-load, fetchpriority="high",
 *                preload in <head>. CSS class plk-lcp (or imm-lcp) picks another image. Together with the measured
 *                image of module 'lcp' (front page) that one wins.
 *                The preload uses the best modern copy (AVIF/WebP, module modern_images – includes/modules/images.php).
 *   cdn_local  – scripts/styles from public CDNs are downloaded and served from uploads/plakette-vendor/.
 *   img_dims   – missing width/height of images are added from the file (SVG: viewBox).
 *   a11y_anim  – Breakdance entrance animations stay readable for screen readers.
 *   link_names – links without text get an aria-label.
 *
 * All HTML changes run through the shared pipeline Plakette_Html (comparison: ?plakette_aus=1).
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Performance {

	const STATE       = 'plakette_vendor';
	const WEBP_OPTION = 'plakette_lcp_webp';
	const DIR_NAME    = 'plakette-vendor';
	const TTL_FLOAT   = 604800;  // Floating versions (gsap@3, /latest/) are refreshed weekly.
	const MAX_AGE     = 2592000; // Unused local copies are kept 30 days.
	const RETRY_AFTER = 3600;
	const MAX_BYTES   = 3145728;

	const CDN_HOSTS = array( 'unpkg.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'code.jquery.com', 'ajax.googleapis.com' );

	const KNOWN_SITES = array(
		'facebook.com'    => 'Facebook',
		'instagram.com'   => 'Instagram',
		'linkedin.com'    => 'LinkedIn',
		'youtube.com'     => 'YouTube',
		'youtu.be'        => 'YouTube',
		'x.com'           => 'X',
		'twitter.com'     => 'X',
		'tiktok.com'      => 'TikTok',
		'xing.com'        => 'XING',
		'pinterest.com'   => 'Pinterest',
		'pinterest.de'    => 'Pinterest',
		'wa.me'           => 'WhatsApp',
		'whatsapp.com'    => 'WhatsApp',
		't.me'            => 'Telegram',
		'threads.net'     => 'Threads',
		'github.com'      => 'GitHub',
		'vimeo.com'       => 'Vimeo',
		'mastodon.social' => 'Mastodon',
		'bsky.app'        => 'Bluesky',
		'spotify.com'     => 'Spotify',
		'soundcloud.com'  => 'SoundCloud',
	);

	/** Placeholder labels that do not name a link (Breakdance "Social Icons" writes aria-label="custom"). */
	const PLACEHOLDER_LABELS = array( '', 'custom', 'link', 'icon' );

	/* ================================================================== boot */

	/**
	 * Is the module on – and not still done by the old "i-make.media Toolkit for Breakdance"? (Nothing runs twice.)
	 */
	public static function active( $key ) {
		if ( ! Plakette_Modules::on( $key ) ) {
			return false;
		}
		return ! ( class_exists( 'Plakette_Legacy' ) && Plakette_Legacy::handles( $key ) );
	}

	public static function boot() {
		add_action( 'plakette_daily', array( __CLASS__, 'daily' ) );
		add_action( 'plakette_vendor_warm', array( __CLASS__, 'warm' ) );
		add_filter( 'plakette_settings_sections', array( __CLASS__, 'settings_section' ) );

		if ( self::active( 'cdn_local' ) ) {
			// Breakdance loads GSAP & co. from unpkg; the versions change with Breakdance updates.
			add_filter( 'breakdance_reusable_dependencies_urls', array( __CLASS__, 'breakdance_urls' ), 99 );
			add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
			add_action( 'activated_plugin', array( __CLASS__, 'schedule_warm' ) );
		}
		if ( self::any_html_module() ) {
			Plakette_Html::add( 'performance', array( __CLASS__, 'transform' ), 5 );
		}
		if ( self::active( 'link_names' ) ) {
			Plakette_Html::add( 'link_names', array( __CLASS__, 'label_links' ), 6 );
		}
		if ( self::active( 'a11y_anim' ) ) {
			add_action( 'wp_head', array( __CLASS__, 'animation_style' ), 20 );
		}
	}

	private static function any_html_module() {
		foreach ( array( 'lcp', 'lcp_auto', 'cdn_local', 'img_dims' ) as $k ) {
			if ( self::active( $k ) ) {
				return true;
			}
		}
		return false;
	}

	public static function daily() {
		self::cleanup();
		self::warm();
	}

	public static function after_upgrade( $upgrader, $info ) {
		$type = is_array( $info ) && isset( $info['type'] ) ? $info['type'] : '';
		if ( in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			self::schedule_warm();
		}
	}

	/** One single cron event, even when several plugins are updated at once. */
	public static function schedule_warm() {
		if ( ! wp_next_scheduled( 'plakette_vendor_warm' ) ) {
			wp_schedule_single_event( time() + 10, 'plakette_vendor_warm' );
		}
	}

	/* ================================================================== HTML pipeline */

	/** Transform for Plakette_Html: LCP image, CDN files, image dimensions. */
	public static function transform( $html ) {
		$res = self::walk( $html, self::runtime_options() );
		return $res['html'];
	}

	/** Options for the current request. */
	private static function runtime_options() {
		$measured = '';
		$lcp      = Plakette_Modules::get( 'lcp' );
		if ( is_array( $lcp ) && ! empty( $lcp['file'] ) && is_front_page() ) {
			$measured = (string) $lcp['file'];
		}
		return array(
			'dry'      => false,
			'auto'     => self::active( 'lcp_auto' ),
			'measured' => $measured,
			'cdn'      => self::active( 'cdn_local' ),
			'dims'     => self::active( 'img_dims' ),
		);
	}

	/**
	 * Finds the LCP candidate of a page without changing anything (used by the check).
	 *
	 * @return array|null [ src, srcset, loading, fetchpriority, preloaded ]
	 */
	public static function find_lcp( $html, $measured = '' ) {
		$res = self::walk(
			(string) $html,
			array(
				'dry'      => true,
				'auto'     => true,
				'measured' => (string) $measured,
				'cdn'      => false,
				'dims'     => false,
			)
		);
		return $res['lcp'];
	}

	/**
	 * One pass through all tags.
	 *
	 * @return array [ html, lcp (info about the candidate as found, or null) ]
	 */
	private static function walk( $html, array $o ) {
		$out = array( 'html' => $html, 'lcp' => null );
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || '' === $html ) {
			return $out;
		}
		$p           = new WP_HTML_Tag_Processor( $html );
		$explicit    = false !== strpos( $html, 'plk-lcp' ) || false !== strpos( $html, 'imm-lcp' );
		$has_main    = false !== stripos( $html, '<main' );
		$in_main     = false;
		$header      = 0;
		$header_done = false;
		$lcp_done    = ! ( $o['auto'] || '' !== $o['measured'] || $explicit );
		$preload     = null;
		$cdn_left    = array();

		while ( $p->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag    = $p->get_tag();
			$closer = $p->is_tag_closer();

			if ( 'MAIN' === $tag ) {
				$in_main = ! $closer;
				continue;
			}
			if ( 'HEADER' === $tag ) {
				$header = max( 0, $header + ( $closer ? -1 : 1 ) );
				if ( $closer && 0 === $header ) {
					$header_done = true;
				}
				continue;
			}
			if ( $closer ) {
				continue;
			}
			if ( 'SCRIPT' === $tag && $o['cdn'] ) {
				$cdn_left[] = self::rewrite_cdn( $p, 'src' );
				continue;
			}
			if ( 'LINK' === $tag && $o['cdn'] ) {
				if ( 'stylesheet' === strtolower( (string) $p->get_attribute( 'rel' ) ) ) {
					$cdn_left[] = self::rewrite_cdn( $p, 'href' );
				}
				continue;
			}
			if ( 'IMG' !== $tag ) {
				continue;
			}
			if ( ! $lcp_done ) {
				if ( $explicit ) {
					$is_candidate = $p->has_class( 'plk-lcp' ) || $p->has_class( 'imm-lcp' );
				} elseif ( '' !== $o['measured'] ) {
					$is_candidate = self::img_matches( $p, $o['measured'] );
				} else {
					$is_candidate = 0 === $header && ( $has_main ? $in_main : $header_done ) && self::big_enough( $p );
				}
				if ( $is_candidate ) {
					$lcp_done   = true;
					$out['lcp'] = array(
						'src'           => self::img_src( $p ),
						'srcset'        => (string) $p->get_attribute( 'srcset' ),
						'loading'       => strtolower( (string) $p->get_attribute( 'loading' ) ),
						'fetchpriority' => strtolower( (string) $p->get_attribute( 'fetchpriority' ) ),
						'lazy_class'    => $p->has_class( 'lazyload' ) || null !== $p->get_attribute( 'data-src' ),
					);
					if ( ! $o['dry'] ) {
						$preload = self::make_lcp( $p );
					}
				}
			}
			if ( $o['dims'] && ! $o['dry'] ) {
				self::add_dims( $p );
			}
		}
		if ( $o['dry'] ) {
			if ( $out['lcp'] && '' !== $out['lcp']['src'] ) {
				$out['lcp']['preloaded'] = self::has_preload( $html, $out['lcp']['src'] );
			}
			return $out;
		}

		$html = $p->get_updated_html();
		if ( $o['cdn'] ) {
			$html = self::strip_cdn_hints( $html, array_filter( $cdn_left ) );
		}
		if ( $preload ) {
			// The measured LCP image of module 'lcp' is preloaded in wp_head – replace it so nothing loads twice.
			$html = preg_replace( '#<link\b[^>]*\sdata-plk-lcp\b[^>]*>\s*#i', '', $html, 1 );
			if ( ! self::has_preload( $html, $preload['href'] ) ) {
				$html = self::insert_preload( $html, $preload['tag'] );
			}
		}
		$out['html'] = $html;
		return $out;
	}

	/**
	 * Inserts the image preload right after <meta name="viewport"> (else at the start of <head>).
	 * Before the viewport meta a phone still assumes a 980 px layout viewport: the preload scanner would pick a much
	 * bigger srcset candidate than the <img> later does – two downloads of the LCP image (measured with Chromium).
	 */
	private static function insert_preload( $html, $tag ) {
		$head_end = stripos( $html, '</head>' );
		if ( preg_match( '#<meta\b[^>]*\bname=["\']?viewport["\']?[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) && $m[0][1] < $head_end ) {
			$pos = $m[0][1] + strlen( $m[0][0] );
			return substr( $html, 0, $pos ) . "\n" . $tag . substr( $html, $pos );
		}
		return Plakette_Html::insert_in_head( $html, $tag );
	}

	private static function img_src( WP_HTML_Tag_Processor $p ) {
		$src = (string) $p->get_attribute( 'src' );
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			$alt = $p->get_attribute( 'data-src' );
			if ( ! is_string( $alt ) || '' === $alt ) {
				$alt = $p->get_attribute( 'data-lazy-src' );
			}
			$src = is_string( $alt ) ? $alt : $src;
		}
		return $src;
	}

	/** Does the image show the measured upload (any size variant)? */
	private static function img_matches( WP_HTML_Tag_Processor $p, $file ) {
		foreach ( array( 'src', 'srcset', 'data-src', 'data-lazy-src', 'data-srcset' ) as $a ) {
			$v = $p->get_attribute( $a );
			if ( is_string( $v ) && false !== strpos( $v, $file ) ) {
				return true;
			}
		}
		return false;
	}

	/** Images that are recognisably small (icons) are no LCP candidates. */
	private static function big_enough( WP_HTML_Tag_Processor $p ) {
		foreach ( array( 'width', 'data-eio-rwidth' ) as $a ) {
			$w = $p->get_attribute( $a );
			if ( is_string( $w ) && is_numeric( $w ) ) {
				return (int) $w >= 200;
			}
		}
		return true;
	}

	/** Is there already a preload for this image in the page? */
	private static function has_preload( $html, $href ) {
		if ( ! preg_match_all( '#<link\b[^>]*\srel=["\']?preload[^>]*>#i', $html, $m ) ) {
			return false;
		}
		foreach ( $m[0] as $link ) {
			if ( Plakette_Html::has_attr( $link, 'data-plk-lcp' ) ) {
				continue;
			}
			if ( 'image' === strtolower( Plakette_Html::attr( $link, 'as' ) ) && Plakette_Html::attr( $link, 'href' ) === html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Makes the LCP image load at once. @return array|null [ tag, href ] of the preload */
	private static function make_lcp( WP_HTML_Tag_Processor $p ) {
		$src  = (string) $p->get_attribute( 'src' );
		$dsrc = $p->get_attribute( 'data-src' );
		if ( ! is_string( $dsrc ) || '' === $dsrc ) {
			$dsrc = $p->get_attribute( 'data-lazy-src' );
		}
		if ( is_string( $dsrc ) && '' !== $dsrc && ( '' === $src || 0 === strpos( $src, 'data:' ) ) ) {
			$src = $dsrc;
			$p->set_attribute( 'src', $src );
		}
		foreach ( array( 'data-srcset' => 'srcset', 'data-lazy-srcset' => 'srcset', 'data-sizes' => 'sizes', 'data-lazy-sizes' => 'sizes' ) as $from => $to ) {
			$v = $p->get_attribute( $from );
			if ( is_string( $v ) && '' !== $v ) {
				if ( 'sizes' !== $to || 'auto' !== $v ) {
					$p->set_attribute( $to, $v );
				}
				$p->remove_attribute( $from );
			}
		}
		$p->remove_attribute( 'data-src' );
		$p->remove_attribute( 'data-lazy-src' );
		foreach ( array( 'lazyload', 'lazyautosizes', 'lazyloaded', 'lazy', 'lazy-hidden', 'ewww_webp_lazy_load' ) as $c ) {
			$p->remove_class( $c );
		}
		$p->add_class( 'skip-lazy' );
		$p->set_attribute( 'data-skip-lazy', '1' );
		$p->set_attribute( 'data-no-lazy', '1' );
		$p->set_attribute( 'loading', 'eager' );
		$p->set_attribute( 'fetchpriority', 'high' );
		$p->remove_attribute( 'decoding' );

		$srcset = (string) $p->get_attribute( 'srcset' );
		$sizes  = (string) $p->get_attribute( 'sizes' );
		// "auto" only works together with lazy loading, which this image no longer has.
		$sizes = trim( preg_replace( '/^\s*auto\s*,?/i', '', $sizes ) );
		if ( '' !== $srcset ) {
			$sizes = '' !== $sizes ? $sizes : '100vw';
			$p->set_attribute( 'sizes', $sizes );
		}
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return null;
		}
		// The <img> keeps its JPEG/PNG (fallback); modern_images wraps it in <picture> with AVIF/WebP sources.
		// Preload exactly what a modern browser will pick – with type, so browsers without that format skip the
		// preload and simply load the <img> (one download, never two).
		$type    = '';
		$variant = class_exists( 'Plakette_Modern_Images' ) ? Plakette_Modern_Images::best_variant( $src, $srcset ) : null;
		if ( $variant ) {
			$type   = ' type="' . $variant['type'] . '"';
			$src    = $variant['href'];
			$srcset = '' !== $srcset ? $variant['srcset'] : '';
		}
		$tag = '<link rel="preload" as="image" href="' . esc_url( $src ) . '"' . $type . ' fetchpriority="high"';
		if ( '' !== $srcset ) {
			$tag .= ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="' . esc_attr( $sizes ) . '"';
		}
		return array(
			'tag'  => $tag . ">\n",
			'href' => $src,
		);
	}

	/* ================================================================== images: paths, dimensions */

	/** URL of a file in wp-content (uploads, themes, plugins) → path, else null. */
	public static function local_path( $url ) {
		static $bases = null;
		if ( null === $bases ) {
			$u     = wp_get_upload_dir();
			$bases = array();
			foreach ( array( array( $u['baseurl'], $u['basedir'] ), array( content_url(), WP_CONTENT_DIR ) ) as $pair ) {
				foreach ( array( 'https', 'http' ) as $scheme ) {
					$bases[] = array( set_url_scheme( untrailingslashit( $pair[0] ), $scheme ), untrailingslashit( $pair[1] ) );
				}
			}
		}
		$clean = strtok( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ), '?#' );
		if ( ! is_string( $clean ) || '' === $clean ) {
			return null;
		}
		if ( 0 === strpos( $clean, '//' ) ) {
			$clean = 'https:' . $clean;
		} elseif ( 0 === strpos( $clean, '/' ) ) {
			$clean = home_url( $clean );
		}
		foreach ( $bases as $b ) {
			if ( 0 === strpos( $clean, $b[0] . '/' ) ) {
				$rel = rawurldecode( substr( $clean, strlen( $b[0] ) ) );
				return false !== strpos( $rel, '..' ) ? null : $b[1] . $rel;
			}
		}
		return null;
	}

	/** Undo of the former lcp_webp module (old protocol entries): removes the WebP files it created. */
	public static function clear_webp() {
		foreach ( (array) get_option( self::WEBP_OPTION, array() ) as $file ) {
			if ( is_string( $file ) && '.webp' === substr( $file, -5 ) && is_file( $file ) && 0 === strpos( wp_normalize_path( $file ), wp_normalize_path( WP_CONTENT_DIR ) ) ) {
				wp_delete_file( $file );
			}
		}
		delete_option( self::WEBP_OPTION );
	}

	/** Adds width/height from the image file when missing. */
	private static function add_dims( WP_HTML_Tag_Processor $p ) {
		if ( null !== $p->get_attribute( 'width' ) && null !== $p->get_attribute( 'height' ) ) {
			return;
		}
		$w = $p->get_attribute( 'data-eio-rwidth' );
		$h = $p->get_attribute( 'data-eio-rheight' );
		if ( ! ( is_numeric( $w ) && is_numeric( $h ) ) ) {
			$src  = self::img_src( $p );
			$dims = '' !== $src && 0 !== strpos( $src, 'data:' ) ? self::image_dims( $src ) : null;
			if ( ! $dims ) {
				return;
			}
			list( $w, $h ) = $dims;
		}
		$p->set_attribute( 'width', (string) (int) $w );
		$p->set_attribute( 'height', (string) (int) $h );
	}

	/** @return array|null [ width, height ] of an image on this server (cached). */
	public static function image_dims( $url ) {
		static $mem = array();
		$path = self::local_path( $url );
		if ( ! $path || ! is_file( $path ) ) {
			return null;
		}
		$key = 'dims_' . md5( $path . filemtime( $path ) );
		if ( isset( $mem[ $key ] ) ) {
			return $mem[ $key ] ? $mem[ $key ] : null;
		}
		$cached = wp_cache_get( $key, 'plakette' );
		if ( is_array( $cached ) ) {
			$mem[ $key ] = $cached;
			return $cached ? $cached : null;
		}
		$dims = null;
		if ( preg_match( '#\.svg$#i', $path ) ) {
			$svg = (string) file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( preg_match( '#viewBox=["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)#i', $svg, $m ) ) {
				$dims = array( (int) round( (float) $m[1] ), (int) round( (float) $m[2] ) );
			} elseif ( preg_match( '#<svg\b[^>]*\swidth=["\']([\d.]+)(px)?["\'][^>]*\sheight=["\']([\d.]+)(px)?["\']#i', $svg, $m ) ) {
				$dims = array( (int) round( (float) $m[1] ), (int) round( (float) $m[3] ) );
			}
		} else {
			$s = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $s && $s[0] > 0 ) {
				$dims = array( (int) $s[0], (int) $s[1] );
			}
		}
		if ( $dims && ( $dims[0] < 1 || $dims[1] < 1 ) ) {
			$dims = null;
		}
		$mem[ $key ] = $dims ? $dims : array();
		wp_cache_set( $key, $mem[ $key ], 'plakette', DAY_IN_SECONDS );
		return $dims;
	}

	/* ================================================================== CDN files local */

	public static function cdn_hosts() {
		return (array) apply_filters( 'plakette_cdn_hosts', self::CDN_HOSTS );
	}

	/**
	 * true  = CDN file with a fixed version (gsap@3.12.5): content never changes, stays local for good.
	 * false = floating or no version (gsap@3, @3.12, /latest/): local, but refreshed weekly.
	 * null  = no CDN file we touch.
	 */
	public static function cdn_pinned( $url ) {
		$url  = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
		$full = 0 === strpos( $url, '//' ) ? 'https:' . $url : $url;
		$host = strtolower( (string) wp_parse_url( $full, PHP_URL_HOST ) );
		if ( '' === $host || ! in_array( $host, self::cdn_hosts(), true ) ) {
			return null;
		}
		$path = (string) wp_parse_url( $full, PHP_URL_PATH );
		if ( ! preg_match( '#\.(m?js|css)$#i', $path ) ) {
			return null;
		}
		return (bool) preg_match( '#(@\d+\.\d+\.\d+([-+][\w.]+)?/|/\d+\.\d+\.\d+([-+][\w.]+)?/)#', $path );
	}

	/** @return string '' = nothing to do or rewritten, else the CDN host that stays in the page. */
	private static function rewrite_cdn( WP_HTML_Tag_Processor $p, $attr ) {
		$url = $p->get_attribute( $attr );
		if ( ! is_string( $url ) || null === self::cdn_pinned( $url ) ) {
			return '';
		}
		$local = self::local_asset( $url );
		if ( $local ) {
			$p->set_attribute( $attr, $local );
			$p->remove_attribute( 'integrity' );
			$p->remove_attribute( 'crossorigin' );
			return '';
		}
		return strtolower( (string) wp_parse_url( self::normalise( $url ), PHP_URL_HOST ) );
	}

	/**
	 * Removes dns-prefetch/preconnect hints to CDN hosts the page no longer loads anything from
	 * (WordPress prints them automatically for external scripts – each one is a connection to that server).
	 */
	private static function strip_cdn_hints( $html, array $still_used ) {
		$hosts = array_diff( self::cdn_hosts(), $still_used );
		if ( ! $hosts ) {
			return $html;
		}
		$re  = implode( '|', array_map( 'preg_quote', $hosts ) );
		$out = preg_replace( '#<link\b[^>]*\srel=["\']?(dns-prefetch|preconnect)["\']?[^>]*\shref=["\']?(https?:)?//(' . $re . ')/?["\'\s][^>]*>\s*#i', '', $html );
		return null === $out ? $html : $out;
	}

	/** Filter breakdance_reusable_dependencies_urls. */
	public static function breakdance_urls( $urls ) {
		if ( ! is_array( $urls ) ) {
			return $urls;
		}
		foreach ( $urls as $k => $u ) {
			if ( is_string( $u ) ) {
				$local      = self::local_asset( $u );
				$urls[ $k ] = $local ? $local : $u;
			}
		}
		return $urls;
	}

	public static function state() {
		$empty = array( 'files' => array(), 'fail' => array() );
		$s     = get_option( self::STATE, array() );
		return is_array( $s ) ? $s + $empty : $empty;
	}

	/** @return array registered local CDN files: key => [ src, file, time, used, pinned, bytes ] */
	public static function registry() {
		return self::state()['files'];
	}

	/** @return array [ key, file name, folder, local url ] */
	public static function asset_paths( $src ) {
		$u    = wp_get_upload_dir();
		$key  = substr( md5( $src ), 0, 10 );
		$base = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
		$name = $key . '-' . preg_replace( '#[^a-zA-Z0-9._-]+#', '-', $base );
		return array( $key, $name, trailingslashit( $u['basedir'] ) . self::DIR_NAME, trailingslashit( $u['baseurl'] ) . self::DIR_NAME . '/' . $name );
	}

	private static function normalise( $url ) {
		$url = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
		return 0 === strpos( $url, '//' ) ? 'https:' . $url : $url;
	}

	/**
	 * Local URL of a CDN file. Every version has its own URL and therefore its own file: when Breakdance asks for
	 * gsap@3.13.0 instead of gsap@3.12.5 after an update, the new file is fetched automatically. When that fails
	 * the CDN URL stays (the page keeps working) and the next try follows after one hour.
	 */
	public static function local_asset( $url, $force = false ) {
		if ( null === self::cdn_pinned( $url ) ) {
			return null;
		}
		$src                              = self::normalise( $url );
		list( $key, $name, $dir, $local ) = self::asset_paths( $src );
		if ( is_file( $dir . '/' . $name ) ) {
			self::mark_used( $key );
			return $local;
		}
		$state = self::state();
		if ( ! $force && ! empty( $state['fail'][ $key ]['time'] ) && time() - (int) $state['fail'][ $key ]['time'] < self::RETRY_AFTER ) {
			return null;
		}
		return true === self::fetch_asset( $src ) ? $local : null;
	}

	/** Reason of the last failed download of $url ('' when none). */
	public static function fail_reason( $url ) {
		list( $key ) = self::asset_paths( self::normalise( $url ) );
		$state       = self::state();
		return isset( $state['fail'][ $key ]['why'] ) ? (string) $state['fail'][ $key ]['why'] : '';
	}

	/** Downloads one CDN file (atomic, with content checks) and registers it. @return true|string reason */
	public static function fetch_asset( $src ) {
		list( $key, $name, $dir ) = self::asset_paths( $src );
		$res   = wp_remote_get( $src, array( 'timeout' => 10, 'redirection' => 3 ) );
		$ok    = ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res );
		$body  = $ok ? (string) wp_remote_retrieve_body( $res ) : '';
		$type  = $ok ? strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) ) : '';
		$is_js = (bool) preg_match( '#\.m?js$#i', $name );
		$why   = '';
		if ( ! $ok ) {
			$why = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
		} elseif ( '' === $body || strlen( $body ) > self::MAX_BYTES ) {
			$why = __( 'leer oder zu groß', 'plakette' );
		} elseif ( 0 === strpos( ltrim( $body ), '<' ) ) {
			$why = $is_js ? __( 'HTML statt JavaScript', 'plakette' ) : __( 'HTML statt CSS', 'plakette' );
		} elseif ( $is_js && $type && ! preg_match( '#javascript|ecmascript|text/plain#', $type ) ) {
			$why = sprintf( __( 'falscher Inhaltstyp %s', 'plakette' ), $type );
		} elseif ( ! $is_js && $type && false === strpos( $type, 'css' ) && false === strpos( $type, 'text/plain' ) ) {
			$why = sprintf( __( 'falscher Inhaltstyp %s', 'plakette' ), $type );
		} elseif ( ! $is_js && preg_match( '#url\(\s*[\'"]?(?!data:|https?:|//)#i', $body ) ) {
			$why = __( 'CSS mit relativen url()-Verweisen', 'plakette' );
		}
		$state = self::state();
		if ( '' === $why ) {
			if ( wp_mkdir_p( $dir ) ) {
				$tmp = $dir . '/.' . $name . '.tmp';
				if ( false !== file_put_contents( $tmp, $body ) && rename( $tmp, $dir . '/' . $name ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					$old = isset( $state['files'][ $key ]['file'] ) ? $state['files'][ $key ]['file'] : '';
					if ( '' !== $old && $old !== $name && is_file( $dir . '/' . $old ) ) {
						wp_delete_file( $dir . '/' . $old );
					}
					$state['files'][ $key ] = array(
						'src'    => $src,
						'file'   => $name,
						'time'   => time(),
						'used'   => time(),
						'pinned' => (bool) self::cdn_pinned( $src ),
						'bytes'  => strlen( $body ),
					);
					unset( $state['fail'][ $key ] );
					update_option( self::STATE, $state, false );
					return true;
				}
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
			}
			$why = __( 'Upload-Ordner nicht beschreibbar', 'plakette' );
		}
		$state['fail'][ $key ] = array( 'time' => time(), 'why' => $why, 'src' => $src );
		update_option( self::STATE, $state, false );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Plakette (CDN lokal): nicht übernommen (' . $why . '): ' . $src ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		return $why;
	}

	/** Notes usage (for the cleanup). Writes at most once a day per file. */
	private static function mark_used( $key ) {
		static $done = array();
		if ( isset( $done[ $key ] ) ) {
			return;
		}
		$done[ $key ] = true;
		$state        = self::state();
		if ( isset( $state['files'][ $key ] ) && time() - (int) $state['files'][ $key ]['used'] > DAY_IN_SECONDS ) {
			$state['files'][ $key ]['used'] = time();
			update_option( self::STATE, $state, false );
		}
	}

	/**
	 * Pre-loads after updates and daily: asks Breakdance for its current CDN URLs, renders the home page once
	 * (this registers CDN files printed in the HTML) and refreshes floating versions.
	 *
	 * @return string[] checked file names
	 */
	public static function warm() {
		if ( ! self::active( 'cdn_local' ) ) {
			return array();
		}
		if ( function_exists( '\\Breakdance\\AvailableDependencyFiles\\getReusableDependenciesUrls' ) ) {
			\Breakdance\AvailableDependencyFiles\getReusableDependenciesUrls(); // Runs our filter → fetches new versions.
		}
		wp_remote_get( add_query_arg( 'plakette_warm', time(), home_url( '/' ) ), array( 'timeout' => 30, 'sslverify' => false ) );
		$checked = array();
		foreach ( self::registry() as $f ) {
			if ( empty( $f['pinned'] ) && time() - (int) $f['time'] > self::TTL_FLOAT ) {
				self::fetch_asset( $f['src'] ); // On errors the old file stays.
			}
			$checked[] = $f['file'];
		}
		return $checked;
	}

	/** Removes local copies (CDN files) nobody requested for 30 days. @return int */
	public static function cleanup() {
		$state = self::state();
		$n     = 0;
		foreach ( $state['files'] as $key => $f ) {
			$used = isset( $f['used'] ) ? (int) $f['used'] : (int) $f['time'];
			if ( time() - $used > self::MAX_AGE ) {
				list( , , $dir ) = self::asset_paths( $f['src'] );
				if ( is_file( $dir . '/' . $f['file'] ) ) {
					wp_delete_file( $dir . '/' . $f['file'] );
				}
				unset( $state['files'][ $key ] );
				++$n;
			}
		}
		foreach ( $state['fail'] as $key => $f ) {
			if ( time() - (int) ( is_array( $f ) && isset( $f['time'] ) ? $f['time'] : 0 ) > DAY_IN_SECONDS ) {
				unset( $state['fail'][ $key ] );
				++$n;
			}
		}
		if ( $n ) {
			update_option( self::STATE, $state, false );
		}
		return $n;
	}

	/** Undo: deletes every local CDN copy. */
	public static function clear_cdn() {
		$u = wp_get_upload_dir();
		Plakette_Log::rrmdir( trailingslashit( $u['basedir'] ) . self::DIR_NAME );
		delete_option( self::STATE );
		wp_clear_scheduled_hook( 'plakette_vendor_warm' );
	}

	/* ================================================================== accessible entrance animations */

	/**
	 * Breakdance entrance animations hide elements with visibility:hidden until they scroll into view – also for
	 * screen readers, and without JavaScript the page stays empty. Here they stay available to assistive technology
	 * and are only hidden visually (opacity). With "reduce motion" and without JS they show at once.
	 */
	public static function animation_style() {
		if ( ! Plakette_Html::should_process() ) {
			return;
		}
		echo '<style id="plk-einblendungen">[data-entrance]{visibility:visible!important}'
			. '[data-entrance]:not(.is-animating):not(.is-animated){opacity:0}'
			. '@media (prefers-reduced-motion:reduce){[data-entrance]{opacity:1!important;transform:none!important}}</style>' . "\n"
			. '<noscript><style>[data-entrance]{opacity:1!important}</style></noscript>' . "\n";
	}

	/* ================================================================== links without accessible name */

	/** Transform for Plakette_Html. */
	public static function label_links( $html ) {
		return Plakette_Html::outside_code(
			$html,
			function ( $part ) {
				$out = preg_replace_callback( '#<a\b([^>]*)>(.*?)</a>#is', array( __CLASS__, 'label_link' ), $part );
				return null === $out ? $part : $out;
			}
		);
	}

	public static function label_link( array $m ) {
		$r = self::analyse_link( $m[1], $m[2] );
		if ( ! $r['needs'] ) {
			return $m[0];
		}
		if ( null === $r['label'] ) {
			// No good label possible – keep the original (incl. a placeholder label).
			return $m[0];
		}
		return '<a' . $r['attrs'] . ' aria-label="' . esc_attr( $r['label'] ) . '">' . $m[2] . '</a>';
	}

	/**
	 * Does a link need a label?
	 *
	 * @return array [ needs (bool), label (string|null = proposal), attrs (without placeholder aria-label), href ]
	 */
	public static function analyse_link( $attrs, $inner ) {
		$res = array( 'needs' => false, 'label' => null, 'attrs' => $attrs, 'href' => '' );
		// Placeholder labels (Breakdance "Social Icons" writes aria-label="custom" for custom networks).
		if ( preg_match( '#\saria-label\s*=\s*(["\'])\s*(custom|link|icon)?\s*\1#i', $attrs ) ) {
			$attrs        = preg_replace( '#\saria-label\s*=\s*(["\'])[^"\']*\1#i', '', $attrs );
			$res['attrs'] = $attrs;
		}
		if ( preg_match( '#\saria-(label|labelledby)\s*=#i', $attrs ) || preg_match( '#\stitle\s*=\s*["\'][^"\']*\S#i', $attrs ) ) {
			return $res;
		}
		if ( ! preg_match( '#\shref\s*=\s*["\']([^"\']+)#i', $attrs, $h ) ) {
			return $res;
		}
		$res['href'] = html_entity_decode( $h[1], ENT_QUOTES, 'UTF-8' );
		$text        = trim( html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' ), " \t\n\r\0\x0B\xC2\xA0" );
		if ( '' !== $text
			|| preg_match( '#<img\b[^>]*\salt\s*=\s*["\'][^"\']*\S#i', $inner )
			|| preg_match( '#aria-label\s*=\s*["\'][^"\']*\S#i', $inner )
			|| preg_match( '#<title\b[^>]*>\s*[^<\s]#i', $inner ) ) {
			return $res;
		}
		$res['needs'] = true;
		$res['label'] = self::link_label( $res['href'], (bool) preg_match( '#\starget\s*=\s*["\']?_blank#i', $attrs ) );
		return $res;
	}

	/** Label for a link by its target. null = no sensible label. */
	public static function link_label( $href, $new_tab ) {
		$suffix = $new_tab ? __( ' (öffnet in neuem Tab)', 'plakette' ) : '';
		$href   = trim( $href );
		if ( 0 === stripos( $href, 'tel:' ) ) {
			return sprintf( __( 'Anrufen: %s', 'plakette' ), rawurldecode( substr( $href, 4 ) ) );
		}
		if ( 0 === stripos( $href, 'mailto:' ) ) {
			return sprintf( __( 'E-Mail an %s', 'plakette' ), (string) strtok( rawurldecode( substr( $href, 7 ) ), '?' ) );
		}
		if ( 0 === strpos( $href, '#' ) ) {
			return strlen( $href ) > 1 ? sprintf( __( 'Zum Abschnitt %s', 'plakette' ), ucfirst( str_replace( array( '-', '_' ), ' ', substr( $href, 1 ) ) ) ) : null;
		}
		if ( 0 === stripos( $href, 'javascript:' ) ) {
			return null;
		}
		$abs  = 0 === strpos( $href, '//' ) ? 'https:' . $href : $href;
		$host = strtolower( (string) wp_parse_url( $abs, PHP_URL_HOST ) );
		$own  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || $host === $own ) {
			$path = trim( (string) wp_parse_url( $abs, PHP_URL_PATH ), '/' );
			$id   = url_to_postid( '' === $host ? home_url( $href ) : $href );
			if ( '' === $path && ! $id ) {
				return __( 'Startseite', 'plakette' ) . $suffix;
			}
			$title = $id ? trim( wp_strip_all_tags( get_the_title( $id ) ) ) : '';
			if ( '' === $title ) {
				$title = '' !== $path ? ucfirst( str_replace( array( '-', '_' ), ' ', basename( $path ) ) ) : __( 'Startseite', 'plakette' );
			}
			return $title . $suffix;
		}
		$bare  = preg_replace( '#^www\.#', '', $host );
		$sites = (array) apply_filters( 'plakette_known_sites', self::KNOWN_SITES );
		foreach ( $sites as $domain => $name ) {
			if ( $bare === $domain || substr( $bare, -strlen( '.' . $domain ) ) === '.' . $domain ) {
				return $name . $suffix;
			}
		}
		return sprintf( __( 'Website von %s', 'plakette' ), $bare ) . $suffix;
	}

	/**
	 * Links without accessible name in a page (same rules as the transform).
	 *
	 * @return array list of [ href, label (proposal or null) ]
	 */
	public static function unnamed_links( $html ) {
		$found = array();
		Plakette_Html::outside_code(
			(string) $html,
			function ( $part ) use ( &$found ) {
				if ( preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $part, $mm, PREG_SET_ORDER ) ) {
					foreach ( $mm as $m ) {
						$r = self::analyse_link( $m[1], $m[2] );
						if ( $r['needs'] ) {
							$found[] = array( 'href' => $r['href'], 'label' => $r['label'] );
						}
					}
				}
				return $part;
			}
		);
		return $found;
	}

	/* ================================================================== settings panel (info only) */

	public static function settings_section( $sections ) {
		$sections['performance'] = array(
			'title'  => __( 'Bilder und lokale Kopien', 'plakette' ),
			'desc'   => __( 'Welche Bildformate dein Server erzeugen kann und was Plakette von fremden Servern auf deine Website geholt hat. Eingeschaltet werden diese Funktionen über die Prüfpunkte; abschalten kannst du sie im Protokoll mit „Rückgängig“. Ungenutzte Kopien räumt Plakette nach 30 Tagen automatisch weg.', 'plakette' ),
			'fields' => array_merge( class_exists( 'Plakette_Preload' ) ? Plakette_Preload::fields() : array(), array(
				array(
					'key'   => 'images_info',
					'label' => __( 'Moderne Bildformate (AVIF/WebP)', 'plakette' ),
					'type'  => 'info',
					'html'  => class_exists( 'Plakette_Modern_Images' ) ? Plakette_Modern_Images::status_html() . Plakette_Modern_Images::info_html() : '',
				),
				array(
					'key'   => 'fonts_info',
					'label' => __( 'Google-Schriften lokal', 'plakette' ),
					'type'  => 'info',
					'html'  => ( Plakette_Modules::on( 'local_fonts' ) ? '' : '<p><em>' . esc_html__( 'Ausgeschaltet.', 'plakette' ) . '</em></p>' ) . ( class_exists( 'Plakette_Local_Fonts' ) ? Plakette_Local_Fonts::info_html() : '' ),
				),
				array(
					'key'   => 'cdn_info',
					'label' => __( 'CDN-Dateien lokal', 'plakette' ),
					'type'  => 'info',
					'html'  => ( Plakette_Modules::on( 'cdn_local' ) ? '' : '<p><em>' . esc_html__( 'Ausgeschaltet.', 'plakette' ) . '</em></p>' ) . self::cdn_info_html(),
				),
			) ),
		);
		return $sections;
	}

	private static function cdn_info_html() {
		$files = self::registry();
		if ( ! $files ) {
			return '<p>' . esc_html__( 'Noch keine CDN-Dateien lokal gespeichert.', 'plakette' ) . '</p>';
		}
		$out = '<ul>';
		foreach ( $files as $f ) {
			$out .= '<li><code>' . esc_html( preg_replace( '#^https?://#', '', $f['src'] ) ) . '</code> – '
				. esc_html(
					sprintf(
						/* translators: 1: size, 2: fixed/floating, 3: date */
						__( '%1$s, %2$s, zuletzt genutzt am %3$s', 'plakette' ),
						size_format( (int) $f['bytes'] ),
						empty( $f['pinned'] ) ? __( 'gleitende Version (wöchentlich aufgefrischt)', 'plakette' ) : __( 'feste Version', 'plakette' ),
						wp_date( 'd.m.Y', (int) $f['used'] )
					)
				) . '</li>';
		}
		return $out . '</ul>';
	}
}

add_action( 'plakette_boot', array( 'Plakette_Performance', 'boot' ) );
