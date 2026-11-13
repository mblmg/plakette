<?php
/**
 * Runtime features that fixes switch on (meta tags, emoji removal, LCP preload …).
 * Stored in one autoloaded option: key => value (true / string / array).
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Modules {

	const OPTION = 'plakette_modules';

	private static $cache = null;

	public static function all() {
		if ( null === self::$cache ) {
			self::$cache = (array) get_option( self::OPTION, array() );
		}
		return self::$cache;
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function on( $key ) {
		return ! empty( self::all()[ $key ] );
	}

	public static function set( $key, $value ) {
		$all = self::all();
		if ( null === $value || false === $value ) {
			unset( $all[ $key ] );
		} else {
			$all[ $key ] = $value;
		}
		self::$cache = $all;
		update_option( self::OPTION, $all, true );
	}

	public static function boot() {
		$seo_plugin = Plakette_Env::seo_plugin();

		// Robots meta for single posts the user marked as "noindex" and (optionally) search pages.
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );

		if ( self::on( 'sitemap' ) ) {
			add_filter( 'wp_sitemaps_enabled', '__return_true', 999 );
		}
		if ( self::on( 'title_tag' ) ) {
			add_action( 'after_setup_theme', array( __CLASS__, 'title_tag_support' ), 99 );
		}
		if ( self::on( 'robots_sitemap' ) ) {
			add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 99, 2 );
		}
		if ( self::on( 'html_gzip' ) ) {
			self::html_gzip();
		}
		if ( self::on( 'force_https' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'force_https' ), 1 );
		}

		if ( ! $seo_plugin ) {
			if ( self::on( 'seo_titles' ) || self::get( 'home_title' ) ) {
				add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 20 );
			}
			if ( self::on( 'meta_desc' ) || self::on( 'og' ) || self::on( 'schema' ) || self::on( 'canonical' ) ) {
				add_action( 'wp_head', array( __CLASS__, 'head_tags' ), 2 );
			}
		}

		// Speed.
		if ( self::on( 'no_emoji' ) ) {
			add_action( 'init', array( __CLASS__, 'disable_emoji' ) );
		}
		if ( self::on( 'no_migrate' ) ) {
			add_action( 'wp_default_scripts', array( __CLASS__, 'remove_migrate' ) );
		}
		if ( self::on( 'no_dashicons' ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_dashicons' ), 100 );
		}
		if ( self::on( 'defer_js' ) ) {
			add_action( 'wp_print_scripts', array( __CLASS__, 'defer_scripts' ), 1 );
		}
		// webp_uploads (old): now part of modern_images (includes/modules/images.php) – keeps originals, adds AVIF/WebP copies.
		if ( self::get( 'lcp' ) ) {
			add_action( 'wp_head', array( __CLASS__, 'lcp_preload' ), 1 );
			add_filter( 'wp_content_img_tag', array( __CLASS__, 'lcp_img_tag' ), 20 );
			add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'lcp_attachment_attrs' ), 20 );
		}
		// local_fonts, lcp_auto, lcp_webp, cdn_local, img_dims, a11y_anim, link_names: includes/modules/fonts.php + performance.php.
		if ( self::get( 'revisions_limit' ) ) {
			add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'revisions_limit' ), 20 );
		}
	}

	/* ---------------------------------------------------------------- SEO */

	public static function robots( $robots ) {
		if ( is_singular() && get_post_meta( get_queried_object_id(), '_plakette_noindex', true ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		if ( self::on( 'noindex_search' ) && ( is_search() || is_404() ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	public static function title_tag_support() {
		if ( ! current_theme_supports( 'title-tag' ) ) {
			add_theme_support( 'title-tag' );
		}
	}

	public static function robots_txt( $output, $public ) {
		if ( $public && false === stripos( $output, 'sitemap:' ) ) {
			$url     = function_exists( 'get_sitemap_url' ) ? get_sitemap_url( 'index' ) : '';
			$output .= $url ? "\nSitemap: " . esc_url_raw( $url ) . "\n" : '';
		}
		return $output;
	}

	/** Compress HTML with PHP when the web server does not do it. */
	public static function html_gzip() {
		if ( is_admin() || wp_doing_ajax() || headers_sent() || ! function_exists( 'ob_gzhandler' ) || ini_get( 'zlib.output_compression' ) ) {
			return;
		}
		$accept = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) : '';
		if ( false === stripos( $accept, 'gzip' ) ) {
			return;
		}
		add_action(
			'template_redirect',
			function () {
				if ( ! headers_sent() && ! in_array( 'ob_gzhandler', ob_list_handlers(), true ) ) {
					ob_start( 'ob_gzhandler' );
				}
			},
			0
		);
	}

	public static function force_https() {
		if ( is_ssl() || 0 !== strpos( home_url(), 'https://' ) || empty( $_SERVER['HTTP_HOST'] ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		wp_safe_redirect( set_url_scheme( home_url( $uri ), 'https' ), 301 );
		exit;
	}

	public static function document_title( $title ) {
		if ( is_front_page() && self::get( 'home_title' ) ) {
			return (string) self::get( 'home_title' );
		}
		if ( is_singular() && self::on( 'seo_titles' ) ) {
			$custom = get_post_meta( get_queried_object_id(), '_plakette_title', true );
			if ( $custom ) {
				return $custom;
			}
		}
		return $title;
	}

	/** Meta description for the current request. */
	public static function current_description() {
		if ( is_front_page() || is_home() ) {
			$desc = (string) self::get( 'home_description', '' );
			if ( '' === $desc && is_front_page() && is_singular() ) {
				$desc = self::post_description( get_queried_object_id() );
			}
			if ( '' === $desc ) {
				$desc = get_bloginfo( 'description' );
			}
			return $desc;
		}
		if ( is_singular() ) {
			return self::post_description( get_queried_object_id() );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return self::shorten( wp_strip_all_tags( term_description() ) );
		}
		return '';
	}

	public static function post_description( $post_id ) {
		$custom = get_post_meta( $post_id, '_plakette_desc', true );
		if ( $custom ) {
			return $custom;
		}
		return self::generate_description( $post_id );
	}

	/** Builds a ~155 character description from the excerpt or the first paragraph. */
	public static function generate_description( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$text = $post->post_excerpt ? $post->post_excerpt : ( class_exists( 'Plakette_Breakdance' ) ? Plakette_Breakdance::content_for( $post ) : $post->post_content );
		$text = excerpt_remove_blocks( $text );
		$text = strip_shortcodes( $text );
		// Headings end a sentence; paragraphs and list items are separated by a space.
		$text = preg_replace( '#\s*</h[1-6]>#i', '. ', $text );
		$text = preg_replace( '#</(p|li|div|figcaption|blockquote)>#i', ' ', $text );
		$text = wp_strip_all_tags( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/([.!?:])\.\s/u', '$1 ', $text );
		return self::shorten( $text );
	}

	public static function shorten( $text, $max = 155 ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max - 1 );
		$space = mb_strrpos( $cut, ' ' );
		if ( $space > 80 ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut, " ,;:-–" ) . '…';
	}

	public static function head_tags() {
		$out  = "\n<!-- Plakette SEO -->\n";
		$desc = self::current_description();

		if ( self::on( 'meta_desc' ) && $desc ) {
			$out .= '<meta name="description" content="' . esc_attr( $desc ) . "\" />\n";
		}

		if ( self::on( 'canonical' ) && ! is_singular() && ! is_search() && ! is_404() ) {
			$url = '';
			if ( is_front_page() || is_home() ) {
				$url = is_front_page() ? home_url( '/' ) : get_permalink( get_option( 'page_for_posts' ) );
			} elseif ( is_category() || is_tag() || is_tax() ) {
				$url = get_term_link( get_queried_object() );
			}
			if ( $url && ! is_wp_error( $url ) ) {
				$paged = (int) get_query_var( 'paged' );
				if ( $paged > 1 ) {
					$url = trailingslashit( $url ) . user_trailingslashit( 'page/' . $paged, 'paged' );
				}
				$out .= '<link rel="canonical" href="' . esc_url( $url ) . "\" />\n";
			}
		}

		if ( self::on( 'og' ) ) {
			$out .= self::open_graph( $desc );
		}
		if ( self::on( 'schema' ) ) {
			$out .= self::schema();
		}
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	private static function share_image() {
		if ( is_singular() && has_post_thumbnail() ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id(), 'large' );
			if ( $src ) {
				return $src;
			}
		}
		$logo = get_theme_mod( 'custom_logo' );
		if ( $logo ) {
			$src = wp_get_attachment_image_src( $logo, 'full' );
			if ( $src ) {
				return $src;
			}
		}
		$icon = get_option( 'site_icon' );
		if ( $icon ) {
			$src = wp_get_attachment_image_src( $icon, 'full' );
			if ( $src ) {
				return $src;
			}
		}
		return null;
	}

	private static function open_graph( $desc ) {
		$title = wp_get_document_title();
		$url   = is_singular() ? get_permalink() : ( is_front_page() ? home_url( '/' ) : '' );
		$tags  = array(
			'og:locale'      => get_locale(),
			'og:site_name'   => get_bloginfo( 'name' ),
			'og:type'        => is_singular( 'post' ) ? 'article' : 'website',
			'og:title'       => $title,
			'og:description' => $desc,
			'og:url'         => $url,
		);
		$image = self::share_image();
		if ( $image ) {
			$tags['og:image']        = $image[0];
			$tags['og:image:width']  = $image[1];
			$tags['og:image:height'] = $image[2];
		}
		$out = '';
		foreach ( $tags as $prop => $value ) {
			if ( '' !== (string) $value ) {
				$out .= '<meta property="' . esc_attr( $prop ) . '" content="' . esc_attr( $value ) . "\" />\n";
			}
		}
		$out .= '<meta name="twitter:card" content="' . ( $image ? 'summary_large_image' : 'summary' ) . "\" />\n";
		return $out;
	}

	private static function schema() {
		$graph = array();
		$home  = home_url( '/' );
		$org   = array(
			'@type' => 'Organization',
			'@id'   => $home . '#organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => $home,
		);
		$logo = get_theme_mod( 'custom_logo' ) ? get_theme_mod( 'custom_logo' ) : get_option( 'site_icon' );
		if ( $logo && wp_get_attachment_image_url( $logo, 'full' ) ) {
			$org['logo'] = wp_get_attachment_image_url( $logo, 'full' );
		}
		$graph[] = (array) apply_filters( 'plakette_schema_organization', $org );
		$graph[] = array(
			'@type'           => 'WebSite',
			'@id'             => $home . '#website',
			'url'             => $home,
			'name'            => get_bloginfo( 'name' ),
			'description'     => get_bloginfo( 'description' ),
			'publisher'       => array( '@id' => $home . '#organization' ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => $home . '?s={search_term_string}',
				'query-input' => 'required name=search_term_string',
			),
		);
		if ( is_singular( 'post' ) ) {
			$post    = get_queried_object();
			$article = array(
				'@type'            => 'BlogPosting',
				'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
				'datePublished'    => get_the_date( 'c', $post ),
				'dateModified'     => get_the_modified_date( 'c', $post ),
				'mainEntityOfPage' => get_permalink( $post ),
				'author'           => array(
					'@type' => 'Person',
					'name'  => get_the_author_meta( 'display_name', $post->post_author ),
				),
				'publisher'        => array( '@id' => $home . '#organization' ),
			);
			$desc = self::post_description( $post->ID );
			if ( $desc ) {
				$article['description'] = $desc;
			}
			if ( has_post_thumbnail( $post ) ) {
				$article['image'] = get_the_post_thumbnail_url( $post, 'large' );
			}
			$graph[] = $article;
		}
		$graph = (array) apply_filters( 'plakette_schema_graph', $graph );
		$json  = wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => array_values( $graph ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return '<script type="application/ld+json">' . str_replace( '</', '<\/', $json ) . "</script>\n";
	}

	/* -------------------------------------------------------------- Speed */

	public static function disable_emoji() {
		if ( is_admin() ) {
			return;
		}
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'emoji_svg_url', '__return_false' );
	}

	public static function remove_migrate( $scripts ) {
		if ( is_admin() || empty( $scripts->registered['jquery'] ) ) {
			return;
		}
		$scripts->registered['jquery']->deps = array_values( array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) ) );
	}

	public static function dequeue_dashicons() {
		if ( ! is_user_logged_in() && ! is_admin_bar_showing() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}

	/** Marks every front-end script (except jQuery itself) as "defer". WordPress keeps scripts blocking where inline code depends on them. */
	public static function defer_scripts() {
		if ( is_admin() || is_customize_preview() ) {
			return;
		}
		$wp_scripts = wp_scripts();
		$skip       = array( 'jquery', 'jquery-core', 'jquery-migrate' );
		$todo       = $wp_scripts->queue;
		$seen       = array();
		while ( $todo ) {
			$handle = array_pop( $todo );
			if ( isset( $seen[ $handle ] ) || empty( $wp_scripts->registered[ $handle ] ) ) {
				continue;
			}
			$seen[ $handle ] = true;
			$todo            = array_merge( $todo, (array) $wp_scripts->registered[ $handle ]->deps );
			if ( in_array( $handle, $skip, true ) || ! $wp_scripts->registered[ $handle ]->src ) {
				continue;
			}
			if ( ! $wp_scripts->get_data( $handle, 'strategy' ) ) {
				$wp_scripts->add_data( $handle, 'strategy', 'defer' );
			}
		}
	}

	private static function lcp_matches( $html_or_url ) {
		$lcp = self::get( 'lcp' );
		if ( empty( $lcp['file'] ) || ! is_front_page() ) {
			return false;
		}
		return false !== strpos( $html_or_url, $lcp['file'] );
	}

	public static function lcp_preload() {
		$lcp = self::get( 'lcp' );
		if ( empty( $lcp['url'] ) || ! is_front_page() ) {
			return;
		}
		// data-plk-lcp: the HTML pipeline (module lcp_auto, includes/modules/performance.php) replaces this preload
		// with its own when it can promote the image in the page – so it is never loaded twice.
		$attrs = ' rel="preload" as="image" fetchpriority="high" href="' . esc_url( $lcp['url'] ) . '" data-plk-lcp';
		if ( ! empty( $lcp['srcset'] ) ) {
			$attrs .= ' imagesrcset="' . esc_attr( $lcp['srcset'] ) . '"';
			// "auto" only works together with lazy loading, which this image no longer has.
			$sizes = trim( preg_replace( '/^\s*auto\s*,?/i', '', (string) $lcp['sizes'] ) );
			if ( '' !== $sizes ) {
				$attrs .= ' imagesizes="' . esc_attr( $sizes ) . '"';
			}
		}
		echo '<link' . $attrs . " />\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	public static function lcp_img_tag( $tag ) {
		if ( ! self::lcp_matches( $tag ) ) {
			return $tag;
		}
		$tag = preg_replace( '/\sloading=(["\'])lazy\1/i', '', $tag );
		$tag = preg_replace( '/\sfetchpriority=(["\'])\w+\1/i', '', $tag );
		return preg_replace( '/^<img\b/i', '<img fetchpriority="high"', $tag );
	}

	public static function lcp_attachment_attrs( $attr ) {
		if ( ! empty( $attr['src'] ) && self::lcp_matches( $attr['src'] ) ) {
			unset( $attr['loading'] );
			$attr['fetchpriority'] = 'high';
		}
		return $attr;
	}

	public static function revisions_limit( $num ) {
		$limit = (int) self::get( 'revisions_limit' );
		return ( $num < 0 || $num > $limit ) ? $limit : $num;
	}
}
