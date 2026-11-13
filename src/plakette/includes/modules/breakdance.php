<?php
/**
 * Breakdance extras (ported from "SEO-Helfer für Breakdance"):
 * - images from the Breakdance element tree (used by the image sitemap and the featured image check),
 * - chapter navigation as Breakdance dynamic data fields (module key "bd_chapters"),
 * - author bio with real paragraphs as a dynamic data field (module key "bd_author").
 *
 * Chapters and author bio are settings (section "breakdance" in the settings panel), not checks.
 * The field slugs keep the old names imm_sh_* so existing Breakdance designs keep working.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Breakdance {

	/** Is Breakdance active? */
	public static function active() {
		return defined( '__BREAKDANCE_VERSION' ) || function_exists( '\Breakdance\Data\get_meta' );
	}

	public static function boot() {
		if ( ! self::active() ) {
			return;
		}
		add_filter( 'plakette_settings_sections', array( __CLASS__, 'settings_section' ) );
		if ( self::seo_bridge_on() ) {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'seo_bridge_script' ), 20 );
			add_filter( 'wpseo_schema_article', array( __CLASS__, 'schema_word_count' ), 10, 2 );
		}
		// The old SEO-Helfer registers the same field slugs – registering them twice would clash.
		if ( ! Plakette_Legacy::active( 'seo-helper' ) ) {
			add_action( 'init', array( __CLASS__, 'register_fields' ), 20 );
		}
	}

	/* ------------------------------------------------------------ images */

	/** Element tree of a post, null when it is not built with Breakdance. */
	public static function tree( $post_id ) {
		$raw = get_post_meta( $post_id, '_breakdance_data', true );
		if ( ! $raw ) {
			return null;
		}
		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$tree = is_array( $data ) && ! empty( $data['tree_json_string'] ) && is_string( $data['tree_json_string'] ) ? json_decode( $data['tree_json_string'], true ) : null;
		return is_array( $tree ) && ! empty( $tree['root'] ) ? $tree : null;
	}

	/**
	 * Media library ids of all content images of a Breakdance post (no background images – mostly decoration).
	 * Elements that store an image as {id, url} plus images that only know an uploads URL.
	 *
	 * @return int[]
	 */
	public static function image_ids( $post_id ) {
		$post_id = (int) $post_id;
		$key     = 'bd_images_' . $post_id . '_' . get_post_modified_time( 'U', true, $post_id );
		$hit     = wp_cache_get( $key, 'plakette' );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$tree = self::tree( $post_id );
		if ( ! $tree ) {
			return array();
		}
		$ids  = array();
		$urls = array();
		$walk = function ( $value, $key = '' ) use ( &$walk, &$ids, &$urls ) {
			if ( ! is_array( $value ) ) {
				return;
			}
			if ( false !== stripos( (string) $key, 'background' ) ) {
				return;
			}
			if ( isset( $value['id'], $value['url'] ) && is_numeric( $value['id'] ) && is_string( $value['url'] ) ) {
				$ids[] = (int) $value['id'];
			} elseif ( isset( $value['url'] ) && is_string( $value['url'] ) && ! isset( $value['id'] )
				&& preg_match( '#/wp-content/uploads/.+\.(jpe?g|png|gif|webp|avif|svg)$#i', $value['url'] ) ) {
				$urls[] = $value['url'];
			}
			foreach ( $value as $k => $child ) {
				$walk( $child, $k );
			}
		};
		$walk( $tree['root'] );
		foreach ( array_unique( $urls ) as $url ) {
			$ids[] = self::id_from_url( $url );
		}
		$ids = array_values(
			array_unique(
				array_filter(
					$ids,
					function ( $id ) {
						return $id > 0 && wp_attachment_is_image( $id );
					}
				)
			)
		);
		wp_cache_set( $key, $ids, 'plakette', DAY_IN_SECONDS );
		return $ids;
	}

	/** Media library id for an image URL, also for scaled variants (-scaled, -800x600). */
	public static function id_from_url( $url ) {
		$id = attachment_url_to_postid( $url );
		if ( ! $id ) {
			$base = preg_replace( '/-(scaled|\d+x\d+)(\.\w+)$/', '$2', $url );
			$id   = attachment_url_to_postid( $base );
			// Sizes of big uploads belong to the "-scaled" original (foo-1024x768.jpg → foo-scaled.jpg).
			if ( ! $id && $base !== $url ) {
				$id = attachment_url_to_postid( preg_replace( '/(\.\w+)$/', '-scaled$1', $base ) );
			}
		}
		return (int) $id;
	}

	/* ------------------------------------------------------------ text */

	/** Keys inside an element's "content" properties that hold visible text (HTML allowed). */
	const TEXT_KEYS = array( 'text', 'title', 'content', 'description', 'heading', 'subheading', 'subtitle', 'caption', 'quote', 'question', 'answer', 'excerpt', 'before', 'after' );

	/** Sub trees of the content properties that never hold visible text. */
	const SKIP_KEYS = array( 'link', 'url', 'icon', 'svgcode', 'tags', 'tag', 'image', 'images', 'video', 'id', 'slug', 'css', 'type' );

	/**
	 * The content of a Breakdance post as plain HTML (headings, paragraphs, lists, links, images) – what Yoast,
	 * Rank Math and Plakette read instead of the empty post content. Global blocks are followed (2 levels).
	 * Empty string when the post is not built with Breakdance.
	 */
	public static function html( $post_id, $depth = 0 ) {
		$post_id = (int) $post_id;
		$key     = 'bd_html_' . $post_id . '_' . get_post_modified_time( 'U', true, $post_id );
		$hit     = 0 === $depth ? wp_cache_get( $key, 'plakette' ) : false;
		if ( is_string( $hit ) ) {
			return $hit;
		}
		$tree = self::tree( $post_id );
		if ( ! $tree ) {
			return '';
		}
		$out  = array();
		$walk = function ( $node ) use ( &$walk, &$out, $depth ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			$type  = isset( $node['data']['type'] ) ? (string) $node['data']['type'] : '';
			$short = strtolower( (string) substr( strrchr( '\\' . $type, '\\' ), 1 ) );
			$props = isset( $node['data']['properties'] ) && is_array( $node['data']['properties'] ) ? $node['data']['properties'] : array();
			$cont  = isset( $props['content'] ) && is_array( $props['content'] ) ? $props['content'] : array();
			$main  = isset( $cont['content'] ) && is_array( $cont['content'] ) ? $cont['content'] : array();
			if ( 'globalblock' === $short ) {
				$block = isset( $main['block'] ) ? (int) $main['block'] : 0;
				if ( $block && $depth < 2 ) {
					$out[] = self::html( $block, $depth + 1 );
				}
			} elseif ( 'heading' === $short && isset( $main['text'] ) && is_string( $main['text'] ) ) {
				$tag   = isset( $main['tags'] ) && preg_match( '/^h[1-6]$/', (string) $main['tags'] ) ? $main['tags'] : 'h2';
				$out[] = '<' . $tag . '>' . wp_kses_post( $main['text'] ) . '</' . $tag . '>';
			} else {
				self::collect_text( $cont, $out );
			}
			self::map_images(
				$cont,
				function ( $img ) use ( &$out ) {
					$img   = (array) $img;
					$out[] = '<img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( self::image_alt( $img ) ) . '">';
					return $img;
				}
			);
			foreach ( isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array() as $child ) {
				$walk( $child );
			}
		};
		$walk( $tree['root'] );
		$html = implode( "\n", array_filter( $out, 'strlen' ) );
		if ( 0 === $depth ) {
			wp_cache_set( $key, $html, 'plakette', DAY_IN_SECONDS );
		}
		return $html;
	}

	/** Visible text strings of an element's content properties (rich text, accordion items, tabs …). */
	private static function collect_text( $value, array &$out ) {
		$value = (array) $value;
		// Buttons and text links: { text, link: { url } } or { text, url }.
		$url = isset( $value['link']['url'] ) && is_string( $value['link']['url'] ) ? $value['link']['url'] : ( isset( $value['url'] ) && is_string( $value['url'] ) ? $value['url'] : '' );
		if ( '' !== $url && isset( $value['text'] ) && is_string( $value['text'] ) && '' !== trim( $value['text'] ) && ! preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)$/i', $url ) ) {
			$out[] = '<p><a href="' . esc_url( $url ) . '">' . wp_kses_post( $value['text'] ) . '</a></p>';
			unset( $value['text'] );
		}
		foreach ( $value as $k => $child ) {
			$k = strtolower( (string) $k );
			if ( in_array( $k, self::SKIP_KEYS, true ) || false !== strpos( $k, 'url' ) ) {
				continue;
			}
			if ( is_array( $child ) ) {
				self::collect_text( $child, $out );
			} elseif ( is_string( $child ) && ( is_int( $k ) || in_array( $k, self::TEXT_KEYS, true ) ) && '' !== trim( $child ) && ! preg_match( '#^(https?:)?//|^\#|^[\w-]+$#', trim( $child ) ) ) {
				$html  = wp_kses_post( $child );
				$out[] = preg_match( '#<(p|h[1-6]|ul|ol|li|div|blockquote|table)[\s>]#i', $html ) ? $html : '<p>' . $html . '</p>';
			}
		}
	}

	/* ------------------------------------------------------------ tree helpers */

	/** Is this array/object an image reference ({url: …jpg, id|filename|mime})? */
	private static function is_image( $v ) {
		$a = (array) $v;
		return isset( $a['url'] ) && is_string( $a['url'] ) && preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)(\?.*)?$/i', $a['url'] )
			&& ( isset( $a['id'] ) || isset( $a['filename'] ) || isset( $a['mime'] ) || array_key_exists( 'alt', $a ) );
	}

	/** Alt text of an image reference: its own alt, else the media library alt. */
	public static function image_alt( $img ) {
		$img = (array) $img;
		$alt = isset( $img['alt'] ) && is_string( $img['alt'] ) ? trim( $img['alt'] ) : '';
		if ( '' === $alt && ! empty( $img['id'] ) && (int) $img['id'] > 0 ) {
			$alt = trim( (string) get_post_meta( (int) $img['id'], '_wp_attachment_image_alt', true ) );
		}
		return $alt;
	}

	/**
	 * Calls $fn( $image ) for every image reference below $v (element images, galleries – not the generated
	 * "sizes" variants) and puts the returned value back. Works on arrays and on objects (edit mode).
	 */
	public static function map_images( $v, callable $fn, $key = '' ) {
		if ( in_array( (string) $key, array( 'sizes', 'icon', 'background', 'background_image' ), true ) ) {
			return $v;
		}
		if ( ! is_array( $v ) && ! is_object( $v ) ) {
			return $v;
		}
		if ( self::is_image( $v ) ) {
			return $fn( $v );
		}
		foreach ( (array) $v as $k => $child ) {
			$new = self::map_images( $child, $fn, $k );
			if ( is_object( $v ) ) {
				$v->$k = $new;
			} else {
				$v[ $k ] = $new;
			}
		}
		return $v;
	}

	/** Calls $fn( $string, $key ) for every string below $v and puts the result back (arrays and objects). */
	public static function map_strings( $v, callable $fn, $key = '' ) {
		if ( is_string( $v ) ) {
			return $fn( $v, (string) $key );
		}
		if ( is_array( $v ) || is_object( $v ) ) {
			foreach ( (array) $v as $k => $child ) {
				$new = self::map_strings( $child, $fn, $k );
				if ( is_object( $v ) ) {
					$v->$k = $new;
				} else {
					$v[ $k ] = $new;
				}
			}
		}
		return $v;
	}

	/**
	 * Walks the element tree of a post as objects (keeps {} and [] apart, so Breakdance gets back exactly what it
	 * stored) and calls $fn( $node ) for every element; $fn changes the node in place and returns the number of
	 * changes. With $undo the changed tree is saved (undoable in the protocol); without it nothing is written.
	 *
	 * @return int number of changes
	 */
	public static function visit( $post_id, callable $fn, ?array &$undo = null ) {
		$raw   = get_post_meta( (int) $post_id, '_breakdance_data', true );
		$outer = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $outer ) || empty( $outer['tree_json_string'] ) || ! is_string( $outer['tree_json_string'] ) ) {
			return 0;
		}
		$tree = json_decode( $outer['tree_json_string'] );
		if ( ! is_object( $tree ) || empty( $tree->root ) ) {
			return 0;
		}
		$count = 0;
		$walk  = function ( $node ) use ( &$walk, $fn, &$count ) {
			if ( ! is_object( $node ) ) {
				return;
			}
			if ( isset( $node->data ) && is_object( $node->data ) ) {
				$count += (int) $fn( $node );
			}
			if ( isset( $node->children ) && is_array( $node->children ) ) {
				foreach ( $node->children as $child ) {
					$walk( $child );
				}
			}
		};
		$walk( $tree->root );
		if ( $count && null !== $undo ) {
			$outer['tree_json_string'] = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$value                     = is_array( $raw ) ? $outer : wp_json_encode( $outer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			Plakette_Log::set_postmeta( (int) $post_id, '_breakdance_data', $value, $undo );
			self::forget( $post_id );
		}
		return $count;
	}

	/**
	 * Applies $fn( $string, $key ) to every string in the content properties of all elements. Without $undo it
	 * only counts what would change (dry run). @return int number of changed strings
	 */
	public static function transform( $post_id, callable $fn, ?array &$undo = null ) {
		return self::visit(
			$post_id,
			function ( $node ) use ( $fn ) {
				$n = 0;
				self::map_strings(
					self::content_of( $node ),
					function ( $str, $key ) use ( $fn, &$n ) {
						$new = $fn( $str, $key );
						if ( $new !== $str ) {
							++$n;
						}
						return $new;
					}
				);
				return $n;
			},
			$undo
		);
	}

	/** Post types in which Breakdance keeps headers, footers, templates, global blocks and popups. */
	public static function part_types() {
		return self::active() ? array_values( array_filter( array( 'breakdance_template', 'breakdance_header', 'breakdance_footer', 'breakdance_block', 'breakdance_popup' ), 'post_type_exists' ) ) : array();
	}

	/** Drops the cached HTML/images of a post (its modification time does not change when only the tree does). */
	public static function forget( $post_id ) {
		$t = get_post_modified_time( 'U', true, (int) $post_id );
		wp_cache_delete( 'bd_html_' . (int) $post_id . '_' . $t, 'plakette' );
		wp_cache_delete( 'bd_images_' . (int) $post_id . '_' . $t, 'plakette' );
	}

	/** Content properties of a node (object form), or null. */
	public static function content_of( $node ) {
		if ( isset( $node->data->properties ) && is_object( $node->data->properties ) && isset( $node->data->properties->content ) ) {
			return $node->data->properties->content;
		}
		return null;
	}

	/** Short element type: "EssentialElements\Heading" → "heading". */
	public static function type_of( $node ) {
		$type = isset( $node->data->type ) ? (string) $node->data->type : '';
		return strtolower( (string) substr( (string) strrchr( '\\' . $type, '\\' ), 1 ) );
	}

	/** Is the post built with Breakdance (and Breakdance active)? */
	public static function builds( $post_id ) {
		return self::active() && null !== self::tree( (int) $post_id );
	}

	/**
	 * Raw source for scans that look for embeds, iframes and tracking pixels: the post content plus every
	 * HTML string of the Breakdance tree (unfiltered) and the addresses of video/map/embed elements, one per line.
	 */
	public static function scan_source( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$src = (string) $post->post_content;
		if ( ! self::builds( $post->ID ) ) {
			return $src;
		}
		$lines = array();
		self::visit(
			$post->ID,
			function ( $node ) use ( &$lines ) {
				$media = (bool) preg_match( '/video|embed|iframe|audio|map|vimeo|youtube|spotify|soundcloud|calendly/i', self::type_of( $node ) );
				if ( $media && false !== strpos( self::type_of( $node ), 'googlemap' ) ) {
					$lines[] = 'https://www.google.com/maps/embed';
				}
				self::map_strings(
					self::content_of( $node ),
					function ( $s ) use ( &$lines, $media ) {
						if ( false !== strpos( $s, '<' ) || false !== stripos( $s, 'vgwort' ) ) {
							$lines[] = $s;
						} elseif ( $media && preg_match( '#^https?://\S+$#i', trim( $s ) ) ) {
							$lines[] = trim( $s );
						}
						return $s;
					}
				);
				return 0;
			}
		);
		return $src . "\n" . implode( "\n", $lines );
	}

	/** Number of words of an HTML text (tags count as word boundaries). */
	public static function words( $html ) {
		$text = preg_replace( '/<[^>]*>/', ' ', strip_shortcodes( excerpt_remove_blocks( (string) $html ) ) );
		$text = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
		return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
	}

	/** Content to analyse for a post: the Breakdance content when Breakdance builds it, else the post content. */
	public static function content_for( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		if ( self::active() ) {
			$html = self::html( $post->ID );
			if ( '' !== $html ) {
				return $html;
			}
		}
		return (string) $post->post_content;
	}

	/* ---------------------------------------------------------- SEO bridge */

	/** Breakdance content for Yoast SEO / Rank Math (setting "seo_bridge", default on)? */
	public static function seo_bridge_on() {
		$s = self::stored();
		return ! array_key_exists( 'seo_bridge', $s ) || ! empty( $s['seo_bridge'] );
	}

	/**
	 * Post editor of a Breakdance post: hands the Breakdance content to the content analysis of Yoast SEO
	 * (registerModification "content") and Rank Math (filter rank_math_content). Yoast stores the scores
	 * when the post is saved.
	 */
	public static function seo_bridge_script( $hook ) {
		if ( 'post.php' !== $hook ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$html = self::html( $post->ID );
		if ( '' === $html ) {
			return;
		}
		$yoast = wp_script_is( 'yoast-seo-post-edit', 'registered' ) || wp_script_is( 'yoast-seo-post-edit-classic', 'registered' ) || defined( 'WPSEO_VERSION' );
		$rank  = defined( 'RANK_MATH_VERSION' );
		if ( ! $yoast && ! $rank ) {
			return;
		}
		wp_register_script( 'plakette-bd-seo', '', array( 'jquery', 'wp-hooks' ), PLAKETTE_VERSION, true );
		wp_enqueue_script( 'plakette-bd-seo' );
		wp_add_inline_script( 'plakette-bd-seo', 'window.plaketteBdContent=' . wp_json_encode( $html ) . ';' . self::bridge_js() );
	}

	private static function bridge_js() {
		return <<<'JS'
(function(){
	var extra = window.plaketteBdContent || '';
	var add = function (content) { return (content || '') + '\n' + extra; };
	var yoast = function () {
		if (!window.YoastSEO || !YoastSEO.app || !YoastSEO.app.registerPlugin || window.plaketteBdYoast) { return; }
		window.plaketteBdYoast = true;
		YoastSEO.app.registerPlugin('plaketteBreakdance', { status: 'ready' });
		YoastSEO.app.registerModification('content', add, 'plaketteBreakdance', 5);
		if (YoastSEO.app.pluginReloaded) { YoastSEO.app.pluginReloaded('plaketteBreakdance'); }
	};
	if (window.jQuery) { jQuery(window).on('YoastSEO:ready', yoast); }
	if (window.YoastSEO && YoastSEO.app) { yoast(); }
	if (window.wp && wp.hooks) {
		wp.hooks.addFilter('rank_math_content', 'plakette/breakdance', add);
		var refresh = function () { if (window.rankMathEditor && rankMathEditor.refresh) { rankMathEditor.refresh('content'); } };
		if (window.jQuery) { jQuery(refresh); }
	}
})();
JS;
	}

	/** Yoast's Article schema counts the empty post content – use the Breakdance text. */
	public static function schema_word_count( $data, $context = null ) {
		$id = is_object( $context ) && isset( $context->id ) ? (int) $context->id : (int) get_queried_object_id();
		if ( ! is_array( $data ) || ! $id ) {
			return $data;
		}
		$html = self::html( $id );
		if ( '' !== $html ) {
			$words = self::words( $html );
			if ( $words > ( isset( $data['wordCount'] ) ? (int) $data['wordCount'] : 0 ) ) {
				$data['wordCount'] = $words;
			}
		}
		return $data;
	}

	/* ---------------------------------------------------------- chapters */

	/** Public hierarchical post types (pages and similar). */
	public static function chapter_types() {
		return array_values( get_post_types( array( 'public' => true, 'hierarchical' => true ) ) );
	}

	/** Published children of a parent in page order. @return int[] */
	public static function chapters_of( $parent_id ) {
		$type = get_post_type( $parent_id );
		if ( ! $type ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => $type,
					'post_parent'    => (int) $parent_id,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			)
		);
	}

	/**
	 * All possible sections (a parent with at least two published children), largest first.
	 *
	 * @return array<int, int[]> parent id => chapter ids
	 */
	public static function sections() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$types = self::chapter_types();
		if ( ! $types ) {
			return $cache = array();
		}
		global $wpdb;
		$ph      = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$parents = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT post_parent FROM {$wpdb->posts} WHERE post_type IN ($ph) AND post_status = 'publish' AND post_parent > 0 GROUP BY post_parent HAVING COUNT(*) >= 2", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				$types
			)
		);
		$sections = array();
		foreach ( (array) $parents as $id ) {
			if ( 'publish' === get_post_status( (int) $id ) ) {
				$sections[ (int) $id ] = self::chapters_of( $id );
			}
		}
		uasort(
			$sections,
			function ( $a, $b ) {
				return count( $b ) - count( $a );
			}
		);
		return $cache = $sections;
	}

	/** Raw values of the settings section (also when the section is not registered). */
	private static function stored() {
		$all = get_option( Plakette_Settings::OPTION, array() );
		return is_array( $all ) && isset( $all['breakdance'] ) && is_array( $all['breakdance'] ) ? $all['breakdance'] : array();
	}

	/** Parent ids whose children get a chapter navigation (module "bd_chapters"). @return int[] */
	public static function chapter_parents() {
		$ids = array();
		foreach ( self::stored() as $key => $on ) {
			if ( $on && preg_match( '/^chapters_(\d+)$/', (string) $key, $m ) ) {
				$ids[] = (int) $m[1];
			}
		}
		return $ids;
	}

	/** Author bio field switched on (module "bd_author", default on)? */
	public static function author_on() {
		$s = self::stored();
		return ! array_key_exists( 'author_bio', $s ) || ! empty( $s['author_bio'] );
	}

	/** Previous (-1) or next (+1) chapter, 0 if there is none or the section is off. */
	public static function neighbour( $post_id, $direction ) {
		if ( ! $post_id ) {
			return 0;
		}
		$parent = (int) wp_get_post_parent_id( $post_id );
		if ( ! $parent || ! in_array( $parent, self::chapter_parents(), true ) ) {
			return 0;
		}
		$ids = self::chapters_of( $parent );
		$pos = array_search( (int) $post_id, $ids, true );
		if ( false === $pos || ! isset( $ids[ $pos + $direction ] ) ) {
			return 0;
		}
		return $ids[ $pos + $direction ];
	}

	/** Settings panel section "Breakdance". */
	public static function settings_section( $sections ) {
		$fields = array(
			array(
				'key'   => 'intro',
				'label' => __( 'So nutzt du die Felder', 'plakette' ),
				'type'  => 'info',
				'html'  => esc_html__( 'Im Breakdance-Editor findest du bei „Dynamische Daten“ die Kategorie „Plakette“. Dort gibt es Titel und Link des vorherigen und des nächsten Kapitels und die Autoren-Bio in Absätzen. Für die Autoren-Bio im Text-Element als Tag „div“ wählen.', 'plakette' ),
			),
			array(
				'key'     => 'seo_bridge',
				'label'   => __( 'Breakdance-Inhalte an Yoast SEO / Rank Math übergeben', 'plakette' ),
				'type'    => 'toggle',
				'default' => true,
				'help'    => __( 'Yoast und Rank Math lesen nur den WordPress-Editor und sehen bei Breakdance-Seiten „0 Wörter“. Plakette reicht ihnen beim Bearbeiten den Text aus Breakdance weiter (Überschriften, Absätze, Links, Bilder). Speichere eine Seite einmal im WordPress-Editor, damit Yoast die neue Bewertung übernimmt.', 'plakette' ),
			),
			array(
				'key'     => 'author_bio',
				'label'   => __( 'Autoren-Bio in Absätzen', 'plakette' ),
				'type'    => 'toggle',
				'default' => true,
				'help'    => __( 'Breakdances eigenes Feld „Author Bio“ zeigt den Text ohne Absätze. Dieses Feld setzt Leerzeilen in echte Absätze um.', 'plakette' ),
			),
		);
		foreach ( self::sections() as $parent => $children ) {
			$fields[] = array(
				'key'     => 'chapters_' . $parent,
				/* translators: 1: page title, 2: number of sub pages */
				'label'   => sprintf( __( 'Kapitel-Navigation für „%1$s“ (%2$d Unterseiten)', 'plakette' ), wp_strip_all_tags( get_the_title( $parent ) ), count( $children ) ),
				'type'    => 'toggle',
				'default' => false,
				'help'    => __( 'Die Unterseiten bekommen „Vorheriges Kapitel“ und „Nächstes Kapitel“ in der Reihenfolge der Seiten-Reihenfolge.', 'plakette' ),
			);
		}
		$sections['breakdance'] = array(
			'title'  => __( 'Breakdance', 'plakette' ),
			'desc'   => __( 'Breakdance-Inhalte für SEO-Plugins lesbar machen und zusätzliche Datenfelder für den Breakdance-Editor: Kapitel-Navigation für Unterseiten und die Autoren-Bio mit Absätzen.', 'plakette' ),
			'fields' => $fields,
		);
		return $sections;
	}

	/** Registers the dynamic data fields (same slugs as the old SEO-Helfer). */
	public static function register_fields() {
		if ( ! function_exists( '\Breakdance\DynamicData\registerField' ) || ! class_exists( '\Breakdance\DynamicData\StringField' ) ) {
			return;
		}
		$fields = array(
			array( 'imm_sh_kapitel_zurueck_titel', __( 'Vorheriges Kapitel: Titel', 'plakette' ), -1, 'string' ),
			array( 'imm_sh_kapitel_zurueck_url', __( 'Vorheriges Kapitel: Link', 'plakette' ), -1, 'url' ),
			array( 'imm_sh_kapitel_weiter_titel', __( 'Nächstes Kapitel: Titel', 'plakette' ), 1, 'string' ),
			array( 'imm_sh_kapitel_weiter_url', __( 'Nächstes Kapitel: Link', 'plakette' ), 1, 'url' ),
		);
		foreach ( $fields as $field ) {
			\Breakdance\DynamicData\registerField(
				new class( $field ) extends \Breakdance\DynamicData\StringField {
					private $slug;
					private $label;
					private $direction;
					private $type;

					public function __construct( $field ) {
						list( $this->slug, $this->label, $this->direction, $this->type ) = $field;
					}

					public function label() {
						return $this->label;
					}

					public function category() {
						return 'Plakette';
					}

					public function slug() {
						return $this->slug;
					}

					public function returnTypes() {
						return array( $this->type );
					}

					public function handler( $attributes ): \Breakdance\DynamicData\StringData {
						$target = Plakette_Breakdance::neighbour( get_the_ID(), $this->direction );
						if ( ! $target ) {
							return \Breakdance\DynamicData\StringData::emptyString();
						}
						$value = 'url' === $this->type ? get_permalink( $target ) : get_the_title( $target );
						return \Breakdance\DynamicData\StringData::fromString( strip_shortcodes( (string) $value ) );
					}
				}
			);
		}

		if ( ! self::author_on() ) {
			return;
		}
		\Breakdance\DynamicData\registerField(
			new class() extends \Breakdance\DynamicData\StringField {
				public function label() {
					return __( 'Autoren-Bio in Absätzen', 'plakette' );
				}

				public function category() {
					return 'Plakette';
				}

				public function slug() {
					return 'imm_sh_autor_bio';
				}

				public function handler( $attributes ): \Breakdance\DynamicData\StringData {
					$post = get_post();
					if ( ! $post ) {
						return \Breakdance\DynamicData\StringData::emptyString();
					}
					$bio = trim( (string) get_the_author_meta( 'description', $post->post_author ) );
					if ( '' === $bio ) {
						return \Breakdance\DynamicData\StringData::emptyString();
					}
					return \Breakdance\DynamicData\StringData::fromString( wpautop( wp_kses_post( $bio ), false ) );
				}
			}
		);
	}
}

add_action( 'plakette_boot', array( 'Plakette_Breakdance', 'boot' ) );
