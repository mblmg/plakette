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
			'desc'   => __( 'Zusätzliche Datenfelder für den Breakdance-Editor: Kapitel-Navigation für Unterseiten und die Autoren-Bio mit Absätzen.', 'plakette' ),
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
