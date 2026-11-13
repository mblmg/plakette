<?php
/**
 * Features ported from "SEO-Helfer für Breakdance" plus coexistence with the author's older plugins.
 *
 * - Plakette_Legacy:          detects the old plugins "SEO-Helfer für Breakdance" and "i-make.media Toolkit for
 *                             Breakdance", tells other modules whether an old plugin still does their job, migrates.
 * - Plakette_Company_Schema:  company profile (Organization + LocalBusiness subtype) – module "company_schema".
 * - Plakette_Image_Sitemap:   images for the Yoast / Rank Math sitemap – module "image_sitemap".
 * - Plakette_Post_Images:     finds the images of a post (content, Breakdance, Yoast share image).
 */

defined( 'ABSPATH' ) || exit;

/**
 * The author's older plugins. While one of them is active, Plakette's equivalent features stay off
 * so nothing runs twice:  if ( Plakette_Legacy::handles( 'lcp_auto' ) ) { return; }
 */
class Plakette_Legacy {

	/** Plakette module key => [ old plugin, flag in its options ]. */
	const MAP = array(
		'company_schema' => array( 'seo-helper', 'firma' ),
		'image_sitemap'  => array( 'seo-helper', 'sitemap_bilder' ),
		'bd_chapters'    => array( 'seo-helper', 'kapitel' ),
		'bd_author'      => array( 'seo-helper', '' ),
		'local_fonts'    => array( 'toolkit', 'fonts' ),
		'lcp_auto'       => array( 'toolkit', 'lcp' ),
		'lcp_webp'       => array( 'toolkit', 'lcp_webp' ),
		'cdn_local'      => array( 'toolkit', 'cdn_local' ),
		'img_dims'       => array( 'toolkit', 'dims' ),
		'a11y_anim'      => array( 'toolkit', 'a11y_anim' ),
		'link_names'     => array( 'toolkit', 'link_name' ),
	);

	/** Is the old plugin loaded? 'seo-helper' | 'toolkit' */
	public static function active( $which ) {
		if ( 'seo-helper' === $which ) {
			return defined( 'IMM_SH_VERSION' );
		}
		if ( 'toolkit' === $which ) {
			return defined( 'IMM\Toolkit\VERSION' ) || function_exists( 'IMM\Toolkit\opt' );
		}
		return false;
	}

	public static function name( $which ) {
		return 'seo-helper' === $which ? 'SEO-Helfer für Breakdance' : 'i-make.media Toolkit for Breakdance';
	}

	/** Does an active old plugin currently do the job of this Plakette module? */
	public static function handles( $module_key ) {
		if ( ! array_key_exists( (string) $module_key, self::MAP ) ) {
			return false;
		}
		list( $which, $flag ) = self::MAP[ $module_key ];
		if ( ! self::active( $which ) ) {
			return false;
		}
		if ( '' === $flag ) {
			return true;
		}
		$opts = 'seo-helper' === $which ? self::seo_helper_options() : self::toolkit_flags();
		return ! empty( $opts[ $flag ] );
	}

	/** Options of the old SEO-Helfer with its defaults. */
	public static function seo_helper_options() {
		$saved = get_option( 'imm_seo_helfer', array() );
		$o     = array_merge(
			array(
				'sitemap_bilder'   => true,
				'hauptbild'        => true,
				'kapitel'          => false,
				'kapitel_bereiche' => array(),
				'firma'            => false,
				'firma_daten'      => array(),
			),
			is_array( $saved ) ? $saved : array()
		);
		$o['kapitel_bereiche'] = array_values( array_filter( array_map( 'intval', (array) $o['kapitel_bereiche'] ) ) );
		$o['firma_daten']      = is_array( $o['firma_daten'] ) ? $o['firma_daten'] : array();
		return $o;
	}

	/** Effective switches of the old toolkit (performance flags only count when its performance module is on). */
	public static function toolkit_flags() {
		$main = get_option( 'imm_toolkit', array() );
		$main = ( is_array( $main ) ? $main : array() ) + array( 'fonts' => 1, 'performance' => 1 );
		$perf = get_option( 'imm_performance', array() );
		$perf = ( is_array( $perf ) ? $perf : array() ) + array( 'lcp' => 1, 'lcp_webp' => 1, 'cdn_local' => 1, 'dims' => 1, 'a11y_anim' => 1, 'link_name' => 1 );
		$out  = array( 'fonts' => ! empty( $main['fonts'] ) );
		foreach ( $perf as $k => $v ) {
			$out[ $k ] = ! empty( $main['performance'] ) && ! empty( $v );
		}
		return $out;
	}

	/** Plugin basename (folder/file.php) of an old plugin from the active plugins, '' if not found. */
	public static function basename( $which ) {
		$file   = 'seo-helper' === $which ? 'imm-seo-helfer.php' : 'imm-toolkit-for-breakdance.php';
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $active as $plugin ) {
			if ( wp_basename( (string) $plugin ) === $file ) {
				return (string) $plugin;
			}
		}
		if ( 'seo-helper' === $which && defined( 'IMM_SH_DATEI' ) ) {
			return plugin_basename( IMM_SH_DATEI );
		}
		if ( 'toolkit' === $which && defined( 'IMM\Toolkit\BASENAME' ) ) {
			return (string) constant( 'IMM\Toolkit\BASENAME' );
		}
		return '';
	}

	/** Old SEO-Helfer company keys => Plakette company keys. */
	const COMPANY_KEYS = array(
		'typ'             => 'schema_type',
		'strasse'         => 'street',
		'plz'             => 'zip',
		'ort'             => 'city',
		'region'          => 'region',
		'land'            => 'country',
		'preisrahmen'     => 'price_range',
		'inhaber'         => 'owner',
		'einzugsgebiet'   => 'area_served',
		'oeffnungszeiten' => 'opening_hours',
		'profile'         => 'profiles',
		'themen'          => 'topics',
		'sprachen'        => 'languages',
		'angebote'        => 'offers',
	);

	/** Toolkit flags => Plakette module keys. */
	const TOOLKIT_MODULES = array(
		'fonts'     => 'local_fonts',
		'lcp'       => 'lcp_auto',
		'lcp_webp'  => 'lcp_webp',
		'cdn_local' => 'cdn_local',
		'dims'      => 'img_dims',
		'a11y_anim' => 'a11y_anim',
		'link_name' => 'link_names',
	);

	/**
	 * Takes over the settings of the old plugins (never overwrites what is already set in Plakette).
	 *
	 * @return string[] short descriptions of what was taken over
	 */
	public static function migrate( array &$undo ) {
		$done = array();
		if ( self::active( 'seo-helper' ) ) {
			$o = self::seo_helper_options();

			// Company data: only fill empty Plakette fields.
			$all     = get_option( Plakette_Settings::OPTION, array() );
			$current = is_array( $all ) && isset( $all['company'] ) && is_array( $all['company'] ) ? $all['company'] : array();
			$empty   = function ( $key ) use ( $current ) {
				return ! isset( $current[ $key ] ) || '' === trim( (string) $current[ $key ] );
			};
			$values  = array();
			foreach ( self::COMPANY_KEYS as $old => $new ) {
				$v = isset( $o['firma_daten'][ $old ] ) ? trim( (string) $o['firma_daten'][ $old ] ) : '';
				if ( '' !== $v && $empty( $new ) ) {
					$values[ $new ] = $v;
				}
			}
			// Name, phone, e-mail and VAT id lived in Yoast's settings.
			if ( class_exists( 'WPSEO_Options' ) && is_callable( array( 'WPSEO_Options', 'get' ) ) ) {
				foreach ( array( 'name' => 'company_name', 'phone' => 'org-phone', 'email' => 'org-email', 'vat_id' => 'org-vat-id' ) as $new => $opt ) {
					$v = trim( (string) WPSEO_Options::get( $opt, '' ) );
					if ( '' !== $v && $empty( $new ) ) {
						$values[ $new ] = $v;
					}
				}
			}
			if ( $values ) {
				Plakette_Settings::save( 'company', $values, $undo );
				$done[] = __( 'Firmendaten', 'plakette' );
			}

			// Chapter navigation: chosen sections become toggles in the settings section "Breakdance".
			if ( ! empty( $o['kapitel'] ) && $o['kapitel_bereiche'] ) {
				$all = get_option( Plakette_Settings::OPTION, array() );
				$all = is_array( $all ) ? $all : array();
				$bd  = isset( $all['breakdance'] ) && is_array( $all['breakdance'] ) ? $all['breakdance'] : array();
				foreach ( $o['kapitel_bereiche'] as $id ) {
					$bd[ 'chapters_' . (int) $id ] = true;
				}
				$all['breakdance'] = $bd;
				Plakette_Log::set_option( Plakette_Settings::OPTION, $all, $undo, false );
				Plakette_Settings::flush_cache();
				$done[] = __( 'Kapitel-Navigation', 'plakette' );
			}
			if ( ! empty( $o['firma'] ) && ! Plakette_Modules::on( 'company_schema' ) ) {
				Plakette_Log::set_module( 'company_schema', true, $undo );
				$done[] = __( 'Firmenprofil für Google', 'plakette' );
			}
			if ( ! empty( $o['sitemap_bilder'] ) && ! Plakette_Modules::on( 'image_sitemap' ) ) {
				Plakette_Log::set_module( 'image_sitemap', true, $undo );
				$done[] = __( 'Bilder in der Sitemap', 'plakette' );
			}
		}
		if ( self::active( 'toolkit' ) ) {
			$flags = self::toolkit_flags();
			$n     = 0;
			foreach ( self::TOOLKIT_MODULES as $flag => $key ) {
				if ( ! empty( $flags[ $flag ] ) && ! Plakette_Modules::get( $key ) ) {
					Plakette_Log::set_module( $key, true, $undo );
					++$n;
				}
			}
			if ( $n ) {
				/* translators: %d: number of features */
				$done[] = sprintf( _n( '%d Toolkit-Funktion', '%d Toolkit-Funktionen', $n, 'plakette' ), $n );
			}
		}
		return $done;
	}
}

/**
 * Finds the images that belong to a post.
 */
class Plakette_Post_Images {

	/** Image ids in the post content (block classes wp-image-N and uploads URLs). @return int[] */
	public static function content_ids( $post ) {
		$post = get_post( $post );
		if ( ! $post || '' === (string) $post->post_content ) {
			return array();
		}
		$ids     = array();
		$content = (string) $post->post_content;
		if ( preg_match_all( '/<img\b[^>]*>/i', $content, $tags ) ) {
			foreach ( array_slice( $tags[0], 0, 30 ) as $tag ) {
				if ( preg_match( '/\bwp-image-(\d+)\b/', $tag, $m ) ) {
					$ids[] = (int) $m[1];
				} elseif ( preg_match( '/\bsrc=(["\'])([^"\']+)\1/i', $tag, $m ) && false !== strpos( $m[2], '/wp-content/uploads/' ) ) {
					$ids[] = Plakette_Breakdance::id_from_url( html_entity_decode( $m[2] ) );
				}
			}
		}
		return array_values(
			array_unique(
				array_filter(
					$ids,
					function ( $id ) {
						return $id > 0 && wp_attachment_is_image( $id );
					}
				)
			)
		);
	}

	/** All images of a post: featured image, content, Breakdance. @return int[] */
	public static function all_ids( $post_id ) {
		$ids   = array();
		$thumb = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			$ids[] = $thumb;
		}
		return array_values( array_unique( array_merge( $ids, self::content_ids( $post_id ), Plakette_Breakdance::image_ids( $post_id ) ) ) );
	}

	/** Best image to become the featured image: Yoast share image, first content image, first Breakdance image. */
	public static function candidate( $post_id ) {
		$yoast = (int) get_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', true );
		if ( $yoast && wp_attachment_is_image( $yoast ) ) {
			return $yoast;
		}
		$ids = self::content_ids( $post_id );
		if ( $ids ) {
			return $ids[0];
		}
		$ids = Plakette_Breakdance::image_ids( $post_id );
		return $ids ? $ids[0] : 0;
	}
}

/**
 * Company profile for Google: Organization + LocalBusiness subtype with address, opening hours, area served, offers.
 */
class Plakette_Company_Schema {

	public static function boot() {
		if ( ! Plakette_Modules::on( 'company_schema' ) || Plakette_Legacy::handles( 'company_schema' ) ) {
			return;
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			add_filter( 'wpseo_schema_organization', array( __CLASS__, 'yoast' ), 20 );
		} elseif ( defined( 'RANK_MATH_VERSION' ) ) {
			add_filter( 'rank_math/json_ld', array( __CLASS__, 'rank_math' ), 99, 2 );
		} elseif ( ! Plakette_Env::seo_plugin() && Plakette_Modules::on( 'schema' ) ) {
			add_filter( 'plakette_schema_organization', array( __CLASS__, 'plakette' ) );
		} else {
			add_action( 'wp_head', array( __CLASS__, 'print_own' ), 3 );
		}
	}

	/** Non-empty lines of a text field. @return string[] */
	public static function lines( $text ) {
		return array_values( array_filter( array_map( 'trim', (array) preg_split( '/\R/u', (string) $text ) ), 'strlen' ) );
	}

	/**
	 * Opening hours from lines like „Mo, Di, Do–Sa 09:00–19:00“ or „Mo-Fr 9-12, 14-18 Uhr“.
	 *
	 * @return array[] OpeningHoursSpecification nodes
	 */
	public static function opening_hours( $text ) {
		$days  = array( 'mo' => 'Monday', 'di' => 'Tuesday', 'mi' => 'Wednesday', 'do' => 'Thursday', 'fr' => 'Friday', 'sa' => 'Saturday', 'so' => 'Sunday' );
		$order = array_keys( $days );
		$out   = array();
		foreach ( self::lines( $text ) as $line ) {
			$line = str_replace( array( '–', '—', ' bis ' ), '-', mb_strtolower( $line ) );
			if ( ! preg_match_all( '/(\d{1,2})(?:[:.](\d{2}))?\s*(?:uhr)?\s*-\s*(\d{1,2})(?:[:.](\d{2}))?/u', $line, $spans, PREG_SET_ORDER ) ) {
				continue;
			}
			$parts    = preg_split( '/\d/u', $line, 2 );
			$day_part = (string) $parts[0];
			$found    = array();
			foreach ( (array) preg_split( '/[,;\/]+|\s+und\s+/u', $day_part ) as $piece ) {
				$piece = trim( $piece );
				if ( preg_match( '/^(mo|di|mi|do|fr|sa|so)\w*\s*-\s*(mo|di|mi|do|fr|sa|so)/u', $piece, $m ) ) {
					$from = array_search( $m[1], $order, true );
					$to   = array_search( $m[2], $order, true );
					for ( $i = $from; $i <= $to; $i++ ) {
						$found[] = $days[ $order[ $i ] ];
					}
				} elseif ( preg_match( '/^(mo|di|mi|do|fr|sa|so)/u', $piece, $m ) ) {
					$found[] = $days[ $m[1] ];
				}
			}
			if ( ! $found ) {
				continue;
			}
			foreach ( $spans as $s ) {
				$out[] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array_values( array_unique( $found ) ),
					'opens'     => sprintf( '%02d:%02d', (int) $s[1], isset( $s[2] ) ? (int) $s[2] : 0 ),
					'closes'    => sprintf( '%02d:%02d', (int) $s[3], isset( $s[4] ) ? (int) $s[4] : 0 ),
				);
			}
		}
		return $out;
	}

	/** Price like „2.600“, „189“ or „19,90 €“ as a number string for schema.org. */
	public static function price( $text ) {
		$text = preg_replace( '/[^\d.,]/u', '', (string) $text );
		if ( '' === $text ) {
			return '';
		}
		if ( false !== strpos( $text, ',' ) ) {
			$text = str_replace( ',', '.', str_replace( '.', '', $text ) );
		} elseif ( preg_match( '/\.\d{3}$/', $text ) ) {
			$text = str_replace( '.', '', $text );
		}
		return $text;
	}

	/** Offers from lines „Name | Preis | Einheit | Beschreibung“ (price, unit and description optional). */
	public static function offers( $text ) {
		$offers = array();
		foreach ( self::lines( $text ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line ) );
			if ( '' === $p[0] ) {
				continue;
			}
			$offer = array( '@type' => 'Offer', 'name' => $p[0] );
			if ( ! empty( $p[3] ) ) {
				$offer['description'] = $p[3];
			}
			$price = self::price( isset( $p[1] ) ? $p[1] : '' );
			if ( '' !== $price ) {
				if ( ! empty( $p[2] ) ) {
					$offer['priceSpecification'] = array(
						'@type'         => 'UnitPriceSpecification',
						'price'         => $price,
						'priceCurrency' => 'EUR',
						'unitText'      => $p[2],
					);
				} else {
					$offer['price']         = $price;
					$offer['priceCurrency'] = 'EUR';
				}
			}
			$offers[] = $offer;
		}
		return $offers;
	}

	/**
	 * The company properties to merge into an Organization node.
	 *
	 * @param array|null $c company data (Plakette_Settings::company())
	 */
	public static function data( $c = null ) {
		$c     = null === $c ? Plakette_Settings::company() : $c;
		$get   = function ( $k ) use ( $c ) {
			return isset( $c[ $k ] ) ? trim( (string) $c[ $k ] ) : '';
		};
		$types = Plakette_Settings::schema_types();
		$type  = isset( $types[ $get( 'schema_type' ) ] ) ? $get( 'schema_type' ) : 'LocalBusiness';
		$new   = array( '@type' => 'Organization' === $type ? 'Organization' : array( 'Organization', $type ) );

		$address = array_filter(
			array(
				'streetAddress'   => $get( 'street' ),
				'postalCode'      => $get( 'zip' ),
				'addressLocality' => $get( 'city' ),
				'addressRegion'   => $get( 'region' ),
				'addressCountry'  => $get( 'country' ),
			),
			'strlen'
		);
		if ( ! empty( $address['addressLocality'] ) ) {
			$new['address'] = array( '@type' => 'PostalAddress' ) + $address;
		}
		foreach ( array( 'telephone' => 'phone', 'email' => 'email', 'vatID' => 'vat_id' ) as $prop => $key ) {
			if ( '' !== $get( $key ) ) {
				$new[ $prop ] = $get( $key );
			}
		}
		if ( '' !== $get( 'price_range' ) && 'Organization' !== $type ) {
			$new['priceRange'] = $get( 'price_range' );
		}

		$areas = self::lines( $get( 'area_served' ) );
		if ( $areas ) {
			$new['areaServed'] = array_map(
				function ( $name ) {
					$country = in_array( mb_strtolower( $name ), array( 'deutschland', 'österreich', 'schweiz', 'germany', 'austria', 'switzerland' ), true );
					return array( '@type' => $country ? 'Country' : 'City', 'name' => $name );
				},
				$areas
			);
		}

		$hours = self::opening_hours( $get( 'opening_hours' ) );
		if ( $hours && 'Organization' !== $type ) {
			$new['openingHoursSpecification'] = $hours;
		}

		// OpenStreetMap object: canonical link (also for short forms like "n123").
		$osm_ref = class_exists( 'Plakette_Osm_Profile' ) ? Plakette_Osm_Profile::parse( $get( 'osm_url' ) ) : null;
		$osm_url = $osm_ref ? Plakette_Osm_Profile::link( $osm_ref['type'], $osm_ref['id'] ) : '';

		$profiles = array_values(
			array_filter(
				array_unique( array_merge( array( $get( 'gbp_url' ), $osm_url ), self::lines( $get( 'profiles' ) ) ) ),
				function ( $url ) {
					return (bool) wp_http_validate_url( $url );
				}
			)
		);
		if ( $profiles ) {
			$new['sameAs'] = $profiles;
			foreach ( $profiles as $url ) {
				if ( preg_match( '#(google\.[a-z.]+/maps|maps\.app\.goo\.gl|g\.page|goo\.gl/maps)#i', $url ) ) {
					$new['hasMap'] = $url;
					break;
				}
			}
			if ( empty( $new['hasMap'] ) && '' !== $osm_url ) {
				$new['hasMap'] = $osm_url;
			}
		}

		// Coordinates of the business from the linked OSM object (remembered by the audit, no request here).
		$place = $osm_ref ? Plakette_Osm_Profile::place() : null;
		if ( $place && isset( $place['lat'], $place['lon'] ) && 'Organization' !== $type ) {
			$new['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => round( (float) $place['lat'], 6 ),
				'longitude' => round( (float) $place['lon'], 6 ),
			);
		}

		if ( '' !== $get( 'owner' ) ) {
			// "Max Mustermann (Geschäftsführer)" → only the name.
			$owner = trim( preg_replace( '/\s*\([^)]*\)\s*$/u', '', $get( 'owner' ) ) );
			if ( '' !== $owner ) {
				$new['founder'] = array( '@type' => 'Person', 'name' => $owner );
			}
		}
		$topics = self::lines( $get( 'topics' ) );
		if ( $topics ) {
			$new['knowsAbout'] = $topics;
		}
		$languages = self::lines( str_replace( ',', "\n", $get( 'languages' ) ) );
		if ( $languages ) {
			$new['knowsLanguage'] = $languages;
		}
		$offers = self::offers( $get( 'offers' ) );
		if ( $offers ) {
			$new['hasOfferCatalog'] = array(
				'@type'           => 'OfferCatalog',
				'name'            => __( 'Angebote', 'plakette' ),
				'itemListElement' => $offers,
			);
		}
		return $new;
	}

	/** Merges the company data into an existing Organization node (keeps name, logo, url of the SEO plugin). */
	public static function merge( array $node ) {
		$new = self::data();
		if ( ! empty( $new['sameAs'] ) ) {
			$new['sameAs'] = array_values( array_unique( array_merge( isset( $node['sameAs'] ) ? (array) $node['sameAs'] : array(), $new['sameAs'] ) ) );
		}
		// Contact details the SEO plugin already has win.
		foreach ( array( 'telephone', 'email', 'vatID' ) as $prop ) {
			if ( ! empty( $node[ $prop ] ) ) {
				unset( $new[ $prop ] );
			}
		}
		return array_merge( $node, $new );
	}

	public static function yoast( $data ) {
		return is_array( $data ) ? self::merge( $data ) : $data;
	}

	/** Rank Math: find the Organization / LocalBusiness entity and extend it; add one if there is none. */
	public static function rank_math( $data, $jsonld = null ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $key => $entity ) {
			if ( is_array( $entity ) && isset( $entity['@type'] ) && self::is_org( $entity['@type'] ) ) {
				$data[ $key ] = self::merge( $entity );
				return $data;
			}
		}
		if ( is_front_page() ) {
			$data['plakette_company'] = self::own_node();
		}
		return $data;
	}

	/** Plakette's own schema output (module "schema"). */
	public static function plakette( $org ) {
		$org = self::merge( (array) $org );
		$c   = Plakette_Settings::company();
		if ( '' !== trim( (string) $c['name'] ) ) {
			$org['name'] = trim( (string) $c['name'] );
		}
		if ( ! empty( $org['logo'] ) && empty( $org['image'] ) ) {
			$org['image'] = $org['logo'];
		}
		return $org;
	}

	/** Complete node for sites where nobody else prints an Organization. */
	public static function own_node() {
		$home = home_url( '/' );
		$c    = Plakette_Settings::company();
		$name = '' !== trim( (string) $c['name'] ) ? trim( (string) $c['name'] ) : get_bloginfo( 'name' );
		$node = array(
			'@type' => 'Organization',
			'@id'   => $home . '#organization',
			'name'  => $name,
			'url'   => $home,
		);
		$logo = get_theme_mod( 'custom_logo' ) ? get_theme_mod( 'custom_logo' ) : get_option( 'site_icon' );
		$url  = $logo ? wp_get_attachment_image_url( $logo, 'full' ) : '';
		if ( $url ) {
			$node['logo']  = $url;
			$node['image'] = $url;
		}
		return self::merge( $node );
	}

	public static function print_own() {
		if ( ! is_front_page() ) {
			return;
		}
		$json = wp_json_encode( array( '@context' => 'https://schema.org' ) + self::own_node(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		echo "<script type=\"application/ld+json\" class=\"plakette-company\">" . str_replace( '</', '<\/', (string) $json ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON.
	}

	/** Is a schema.org @type (string or list) an organisation or local business? */
	public static function is_org( $type ) {
		foreach ( (array) $type as $t ) {
			$t = (string) $t;
			if ( in_array( $t, array( 'Person', 'PostalAddress', 'WebSite', 'WebPage', 'ImageObject', 'Place' ), true ) ) {
				continue;
			}
			if ( array_key_exists( $t, Plakette_Settings::schema_types() ) || preg_match( '/(Organization|Organisation|Business|Service|Store|Establishment|Location|Corporation|Agency|Office|Shop|Practice|Restaurant)$/', $t ) ) {
				return true;
			}
		}
		return false;
	}

	/** All JSON-LD nodes of an HTML page (top level, lists and @graph). @return array[] */
	public static function nodes_from_html( $html ) {
		$nodes = array();
		if ( ! preg_match_all( '#<script\b[^>]*type=(["\']?)application/ld\+json\1[^>]*>(.*?)</script>#is', (string) $html, $m ) ) {
			return $nodes;
		}
		foreach ( $m[2] as $json ) {
			$data = json_decode( html_entity_decode( trim( $json ), ENT_NOQUOTES, 'UTF-8' ), true );
			if ( null === $data ) {
				$data = json_decode( trim( $json ), true );
			}
			if ( ! is_array( $data ) ) {
				continue;
			}
			$list = isset( $data['@graph'] ) ? (array) $data['@graph'] : ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ? $data : array( $data ) );
			foreach ( $list as $node ) {
				if ( is_array( $node ) && isset( $node['@graph'] ) ) {
					foreach ( (array) $node['@graph'] as $sub ) {
						if ( is_array( $sub ) ) {
							$nodes[] = $sub;
						}
					}
				} elseif ( is_array( $node ) ) {
					$nodes[] = $node;
				}
			}
		}
		return $nodes;
	}

	/** Has an address node with at least a city or street? */
	public static function has_address( array $node ) {
		if ( empty( $node['address'] ) ) {
			return false;
		}
		if ( is_string( $node['address'] ) ) {
			return '' !== trim( $node['address'] );
		}
		$list = isset( $node['address']['@type'] ) || isset( $node['address']['addressLocality'] ) || isset( $node['address']['streetAddress'] ) ? array( $node['address'] ) : (array) $node['address'];
		foreach ( $list as $a ) {
			if ( is_array( $a ) && ( ! empty( $a['addressLocality'] ) || ! empty( $a['streetAddress'] ) ) ) {
				return true;
			}
		}
		return false;
	}
}

/**
 * Adds images to the Yoast / Rank Math sitemap that these plugins do not find themselves
 * (Breakdance layouts, featured image, images in the content).
 */
class Plakette_Image_Sitemap {

	public static function boot() {
		if ( ! Plakette_Modules::on( 'image_sitemap' ) || Plakette_Legacy::handles( 'image_sitemap' ) ) {
			return;
		}
		add_filter( 'wpseo_sitemap_urlimages', array( __CLASS__, 'images' ), 10, 2 );
		add_filter( 'rank_math/sitemap/urlimages', array( __CLASS__, 'images' ), 10, 2 );
	}

	/** File URL without size suffix, for comparing. */
	private static function norm( $url ) {
		$url = preg_replace( '#^https?:#i', '', strtok( (string) $url, '?' ) );
		return preg_replace( '/-(scaled|\d+x\d+)(\.\w+)$/', '$2', (string) $url );
	}

	public static function images( $images, $post_id = 0 ) {
		$images = is_array( $images ) ? $images : array();
		if ( ! $post_id ) {
			return $images;
		}
		$have = array();
		foreach ( $images as $img ) {
			if ( is_array( $img ) && ! empty( $img['src'] ) ) {
				$have[ self::norm( $img['src'] ) ] = true;
			}
		}
		foreach ( Plakette_Post_Images::all_ids( $post_id ) as $id ) {
			$src = wp_get_attachment_url( $id );
			if ( ! $src || isset( $have[ self::norm( $src ) ] ) ) {
				continue;
			}
			$images[]                  = array(
				'src'   => $src,
				'title' => get_the_title( $id ),
				'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			);
			$have[ self::norm( $src ) ] = true;
		}
		return $images;
	}

	/** Does the active SEO plugin write a sitemap? '' or plugin name. */
	public static function seo_sitemap() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			$on = class_exists( 'WPSEO_Options' ) && is_callable( array( 'WPSEO_Options', 'get' ) ) ? WPSEO_Options::get( 'enable_xml_sitemap', true ) : true;
			return $on ? 'Yoast SEO' : '';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$on = class_exists( '\RankMath\Helper' ) && is_callable( array( '\RankMath\Helper', 'is_module_active' ) ) ? \RankMath\Helper::is_module_active( 'sitemap' ) : true;
			return $on ? 'Rank Math' : '';
		}
		return '';
	}
}

add_action(
	'plakette_boot',
	function () {
		Plakette_Company_Schema::boot();
		Plakette_Image_Sitemap::boot();
	}
);
