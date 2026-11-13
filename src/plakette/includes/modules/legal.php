<?php
/**
 * Legal pages and small accessibility repairs – shared helpers for checks-legal.php and the runtime modules:
 *
 *   legal_links       Impressum · Datenschutz · (Barrierefreiheit) · Cookie-Einstellungen on every page:
 *                     items in a classic footer menu, otherwise a small link row at the end of the page.
 *                     Links the page already has are not repeated.
 *   shop_button       WooCommerce order button „Zahlungspflichtig bestellen“ (§ 312j Abs. 3 BGB).
 *   a11y_lang         <html lang> from the WordPress language.
 *   a11y_zoom         Viewport meta that allows zooming (WCAG 1.4.4).
 *   a11y_form_labels  aria-label for form fields without a label (from placeholder or name).
 *
 * Option plakette_imprint_page: page id of the Impressum (other modules read it).
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Legal {

	const IMPRINT_OPTION = 'plakette_imprint_page';
	const CACHE          = 'plakette_legal_pages';

	/** Link texts that count as "links to the Impressum / privacy policy / accessibility statement". */
	const IMPRINT_TEXT = '/^[\s\p{P}]*(impressum|imprint|legal notice|anbieterkennzeichnung)/iu';
	const PRIVACY_TEXT = '/^[\s\p{P}]*(datenschutz|privacy)/iu';
	const BFSG_TEXT    = '/^[\s\p{P}]*(erklärung zur barrierefreiheit|barrierefreiheit|accessibility)/iu';

	public static function boot() {
		// Cache of the legal page ids for the front end – dropped whenever a page or a relevant option changes.
		foreach ( array( 'save_post_page', 'deleted_post', 'trashed_post', 'untrashed_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
		foreach ( array( 'updated_option', 'added_option', 'deleted_option' ) as $hook ) {
			add_action(
				$hook,
				function ( $name ) {
					if ( in_array( $name, array( self::IMPRINT_OPTION, 'wp_page_for_privacy_policy', Plakette_Modules::OPTION ), true ) ) {
						Plakette_Legal::flush();
					}
				}
			);
		}

		if ( Plakette_Modules::on( 'legal_links' ) ) {
			Plakette_Html::add( 'legal_links', array( __CLASS__, 'links_transform' ), 60 );
		}
		if ( Plakette_Modules::on( 'a11y_lang' ) ) {
			Plakette_Html::add( 'a11y_lang', array( __CLASS__, 'lang_transform' ), 5 );
		}
		if ( Plakette_Modules::on( 'a11y_zoom' ) ) {
			Plakette_Html::add( 'a11y_zoom', array( __CLASS__, 'zoom_transform' ), 5 );
		}
		if ( Plakette_Modules::on( 'a11y_form_labels' ) ) {
			Plakette_Html::add( 'a11y_form_labels', array( __CLASS__, 'labels_transform' ), 70 );
		}
		if ( Plakette_Modules::on( 'shop_button' ) ) {
			add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'order_button_text' ), 99 );
			add_filter( 'render_block_data', array( __CLASS__, 'order_button_block' ), 20 );
		}
	}

	public static function flush() {
		delete_transient( self::CACHE );
	}

	/* ------------------------------------------------------------------ pages */

	public static function published_page( $id ) {
		$p = $id ? get_post( (int) $id ) : null;
		return $p && 'page' === $p->post_type && 'publish' === $p->post_status && '' === $p->post_password;
	}

	/** First published page with one of the slugs or a title matching the regex (0 = none). */
	public static function find_page( array $slugs, $title_re ) {
		$pages = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => 300,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $pages as $p ) {
			if ( in_array( $p->post_name, $slugs, true ) || preg_match( $title_re, trim( wp_strip_all_tags( $p->post_title ) ) ) ) {
				return (int) $p->ID;
			}
		}
		return 0;
	}

	public static function imprint_page() {
		$id = (int) get_option( self::IMPRINT_OPTION );
		if ( self::published_page( $id ) ) {
			return $id;
		}
		return self::find_page( array( 'impressum', 'imprint', 'legal-notice', 'anbieterkennzeichnung' ), '/^(impressum|imprint|legal notice|anbieterkennzeichnung)$/iu' );
	}

	/** Is this the untouched sample text WordPress puts into its privacy page? */
	public static function is_sample_privacy( $content ) {
		$content = (string) $content;
		return ( false !== stripos( $content, 'Wer wir sind' ) || false !== stripos( $content, 'Who we are' ) )
			&& ( false !== stripos( $content, 'Unsere Website-Adresse ist' ) || false !== stripos( $content, 'Our website address is' ) );
	}

	public static function privacy_page() {
		$id = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( self::published_page( $id ) && ! self::is_sample_privacy( get_post( $id )->post_content ) ) {
			return $id;
		}
		$found = self::find_page( array( 'datenschutz', 'datenschutzerklaerung', 'datenschutzerklarung', 'datenschutzhinweise', 'privacy-policy', 'privacy' ), '/^(datenschutz(erklärung|hinweise|information(en)?)?|privacy policy|privacy)$/iu' );
		return $found && ! self::is_sample_privacy( get_post( $found )->post_content ) ? $found : 0;
	}

	public static function bfsg_page() {
		return self::find_page( array( 'barrierefreiheit', 'erklaerung-zur-barrierefreiheit', 'barrierefreiheitserklaerung', 'accessibility-statement', 'accessibility' ), '/barrierefrei|accessibility statement/iu' );
	}

	/**
	 * The legal pages that must be linked everywhere: key => [ id, label, re ].
	 *
	 * @param bool $cached use the transient (front end)
	 */
	public static function legal_pages( $cached = false ) {
		$ids = $cached ? get_transient( self::CACHE ) : false;
		if ( ! is_array( $ids ) ) {
			$ids = array(
				'imprint' => self::imprint_page(),
				'privacy' => self::privacy_page(),
				'bfsg'    => self::bfsg_page(),
			);
			if ( $cached ) {
				set_transient( self::CACHE, $ids, 12 * HOUR_IN_SECONDS );
			}
		}
		$meta = array(
			'imprint' => array( __( 'Impressum', 'plakette' ), self::IMPRINT_TEXT ),
			'privacy' => array( __( 'Datenschutz', 'plakette' ), self::PRIVACY_TEXT ),
			'bfsg'    => array( __( 'Barrierefreiheit', 'plakette' ), self::BFSG_TEXT ),
		);
		$out = array();
		foreach ( $meta as $key => $m ) {
			if ( ! empty( $ids[ $key ] ) ) {
				$out[ $key ] = array(
					'id'    => (int) $ids[ $key ],
					'label' => $m[0],
					're'    => $m[1],
				);
			}
		}
		return $out;
	}

	/**
	 * Pages nobody searches for and that need no SEO polish (meta description, featured image): the legal pages
	 * (Impressum, Datenschutz, Barrierefreiheit, AGB, Widerruf) and the functional shop pages (cart, checkout, account).
	 * They may stay in the index – Google needs neither a description nor a noindex for them.
	 *
	 * @return array post id => true
	 */
	public static function utility_pages() {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}
		$ids = array();
		foreach ( self::legal_pages() as $page ) {
			$ids[ (int) $page['id'] ] = true;
		}
		$found = array(
			self::find_page( array( 'agb', 'allgemeine-geschaeftsbedingungen', 'terms', 'terms-and-conditions' ), '/^(AGB|Allgemeine Geschäftsbedingungen|terms( and conditions| of service)?)$/iu' ),
			self::find_page( array( 'widerruf', 'widerrufsbelehrung', 'widerrufsrecht' ), '/^widerruf/iu' ),
			self::find_page( array( 'versand', 'versandkosten', 'zahlung-und-versand', 'versand-und-zahlung' ), '/^(Versand|Zahlung)( |$)/iu' ),
			self::find_page( array( 'cookie-richtlinie', 'cookie-richtlinie-eu', 'cookies', 'cookie-policy' ), '/^cookie/iu' ),
		);
		foreach ( array( 'wp_page_for_privacy_policy', 'woocommerce_terms_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ) as $option ) {
			$found[] = (int) get_option( $option );
		}
		foreach ( array_filter( array_map( 'intval', $found ) ) as $id ) {
			$ids[ $id ] = true;
		}
		$ids = (array) apply_filters( 'plakette_utility_pages', $ids );
		return $ids;
	}

	/** Is the post kept out of search engines on purpose (Plakette or the SEO plugin)? Then it needs no SEO texts. */
	public static function is_noindex( $post_id ) {
		$post_id = (int) $post_id;
		if ( get_post_meta( $post_id, '_plakette_noindex', true ) || '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}
		if ( in_array( 'noindex', (array) get_post_meta( $post_id, 'rank_math_robots', true ), true ) ) {
			return true;
		}
		return 'yes' === get_post_meta( $post_id, '_seopress_robots_index', true ) || get_post_meta( $post_id, '_genesis_noindex', true );
	}

	/** Shortcut for the content checks: does this page need a description / featured image for Google? */
	public static function needs_seo_texts( $post_id ) {
		return ! isset( self::utility_pages()[ (int) $post_id ] ) && ! self::is_noindex( $post_id );
	}

	/** B2C shop or booking system that is active ('' = none). */
	public static function shop_system() {
		$known = array(
			'WooCommerce'                 => 'WooCommerce',
			'Easy_Digital_Downloads'      => 'Easy Digital Downloads',
			'WC_Bookings'                 => 'WooCommerce Bookings',
			'AmeliaBooking\Plugin'        => 'Amelia',
			'Bookly\Lib\Plugin'           => 'Bookly',
			'BookingPress'                => 'BookingPress',
			'HotelBookingPlugin'          => 'MotoPress Hotel Booking',
			'Simply_Schedule_Appointments' => 'Simply Schedule Appointments',
			'SureCart'                    => 'SureCart',
			'Jigoshop'                    => 'Jigoshop',
		);
		foreach ( $known as $class => $name ) {
			if ( class_exists( $class, false ) ) {
				return $name;
			}
		}
		foreach ( array( 'AMELIA_VERSION' => 'Amelia', 'BOOKINGPRESS_VERSION' => 'BookingPress', 'SURECART_PLUGIN_FILE' => 'SureCart', 'WPSC_VERSION' => 'WP eCommerce', 'MPHB_PLUGIN_FILE' => 'MotoPress Hotel Booking' ) as $const => $name ) {
			if ( defined( $const ) ) {
				return $name;
			}
		}
		return '';
	}

	public static function woocommerce() {
		return class_exists( 'WooCommerce', false );
	}

	/* ------------------------------------------------------------------ links */

	/** Links of a HTML document: [ href, text ] (consent dialogs and templates left out). */
	public static function links( $html ) {
		$html = self::without_dialogs( (string) $html );
		$out  = array();
		if ( preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $a ) {
				$tag  = '<a' . $a[1] . '>';
				$text = html_entity_decode( wp_strip_all_tags( $a[2] ), ENT_QUOTES, 'UTF-8' );
				$text = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
				if ( '' === $text ) {
					$text = trim( Plakette_Html::attr( $tag, 'aria-label' ) . ' ' . Plakette_Html::attr( $tag, 'title' ) );
				}
				$out[] = array(
					'href' => Plakette_Html::attr( $tag, 'href' ),
					'text' => $text,
				);
			}
		}
		return $out;
	}

	/** Removes <template>, <dialog> and the Plakette consent banner (their links are not always visible). */
	private static function without_dialogs( $html ) {
		$html = (string) preg_replace( '#<(template|dialog)\b.*?</\1>#is', '', $html );
		$pos  = strpos( $html, 'data-plakette-consent-root' );
		if ( false !== $pos ) {
			$start = strrpos( substr( $html, 0, $pos ), '<' );
			if ( false !== $start && preg_match( '#^<([a-z0-9-]+)#i', substr( $html, $start, 30 ), $tm ) ) {
				$end = self::element_end( $html, $start, strtolower( $tm[1] ) );
				if ( $end > $start ) {
					$html = substr( $html, 0, $start ) . substr( $html, $end );
				}
			}
		}
		return $html;
	}

	/** Offset after the closing tag of the element starting at $start (0 if not found). */
	private static function element_end( $html, $start, $name ) {
		$depth = 0;
		if ( ! preg_match_all( '#<(/?)' . preg_quote( $name, '#' ) . '\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $start ) ) {
			return 0;
		}
		foreach ( $m[0] as $i => $tag ) {
			$depth += '/' === $m[1][ $i ][0] ? -1 : 1;
			if ( 0 === $depth ) {
				return $tag[1] + strlen( $tag[0] );
			}
		}
		return 0;
	}

	private static function norm_url( $url ) {
		$url = Plakette_Crawler::absolutize( $url );
		$p   = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$host = strtolower( (string) preg_replace( '/^www\./i', '', $p['host'] ) );
		return $host . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . ( isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	}

	/** Does the href point to this page? */
	public static function href_is( $href, $page_id ) {
		$href = html_entity_decode( trim( (string) $href ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $href || '#' === $href[0] || ! $page_id ) {
			return false;
		}
		$query = (string) wp_parse_url( $href, PHP_URL_QUERY );
		if ( '' !== $query ) {
			$q = array();
			wp_parse_str( $query, $q );
			foreach ( array( 'page_id', 'p' ) as $k ) {
				if ( isset( $q[ $k ] ) && (int) $q[ $k ] === (int) $page_id ) {
					return true;
				}
			}
		}
		$target = self::norm_url( (string) get_permalink( $page_id ) );
		return '' !== $target && self::norm_url( $href ) === $target;
	}

	/** Is the page linked in these links (by address or by link text)? */
	public static function is_linked( array $links, $page_id, $text_re ) {
		foreach ( $links as $l ) {
			if ( ( $page_id && self::href_is( $l['href'], $page_id ) ) || ( $text_re && preg_match( $text_re, $l['text'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ module legal_links */

	/** Classic footer menu (menu term id) or 0. Block themes have no menu locations. */
	public static function footer_menu() {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return 0;
		}
		$locations = get_nav_menu_locations();
		foreach ( get_registered_nav_menus() as $slug => $name ) {
			if ( ( false !== stripos( $slug, 'footer' ) || false !== stripos( (string) $name, 'footer' ) ) && ! empty( $locations[ $slug ] ) && wp_get_nav_menu_object( $locations[ $slug ] ) ) {
				return (int) $locations[ $slug ];
			}
		}
		return 0;
	}

	/**
	 * Makes the legal pages reachable from every page: adds them to the footer menu (classic themes)
	 * and switches on the link row (it only shows links a page does not have yet).
	 *
	 * @return string what happened (for the success message)
	 */
	public static function enable_links( array &$undo ) {
		$pages = self::legal_pages();
		// Remember the Impressum so other modules (and the front end) find it quickly.
		if ( isset( $pages['imprint'] ) && (int) get_option( self::IMPRINT_OPTION ) !== $pages['imprint']['id'] ) {
			Plakette_Log::set_option( self::IMPRINT_OPTION, $pages['imprint']['id'], $undo );
		}
		$added = 0;
		$menu  = self::footer_menu();
		if ( $menu ) {
			$in_menu = array();
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
				if ( 'post_type' === $item->type ) {
					$in_menu[] = (int) $item->object_id;
				}
			}
			foreach ( $pages as $page ) {
				if ( in_array( $page['id'], $in_menu, true ) ) {
					continue;
				}
				$item = wp_update_nav_menu_item(
					$menu,
					0,
					array(
						'menu-item-object-id' => $page['id'],
						'menu-item-object'    => 'page',
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
						'menu-item-title'     => $page['label'],
					)
				);
				if ( ! is_wp_error( $item ) && $item ) {
					$undo[] = array( 'delete_post', (int) $item );
					++$added;
				}
			}
		}
		if ( ! Plakette_Modules::on( 'legal_links' ) ) {
			Plakette_Log::set_module( 'legal_links', true, $undo );
		}
		self::flush();
		return $added
			? sprintf( _n( '%d Link wurde ins Footer-Menü eingetragen.', '%d Links wurden ins Footer-Menü eingetragen.', $added, 'plakette' ), $added )
			: __( 'Am Ende jeder Seite erscheint jetzt eine kleine Linkzeile zu den Rechtstexten.', 'plakette' );
	}

	public static function links_transform( $html ) {
		$links = self::links( $html );
		$items = array();
		foreach ( self::legal_pages( true ) as $page ) {
			if ( ! self::is_linked( $links, $page['id'], $page['re'] ) ) {
				$items[] = '<li><a href="' . esc_url( get_permalink( $page['id'] ) ) . '">' . esc_html( $page['label'] ) . '</a></li>';
			}
		}
		if ( Plakette_Modules::on( 'consent' ) && false === strpos( $html, '#plakette-consent' ) && false === stripos( $html, 'data-plakette-consent-open' ) ) {
			$items[] = '<li><a href="#plakette-consent" data-plakette-consent-open>' . esc_html__( 'Cookie-Einstellungen', 'plakette' ) . '</a></li>';
		}
		if ( ! $items ) {
			return $html;
		}
		$nav  = '<nav class="plk-legal" aria-label="' . esc_attr__( 'Rechtliches', 'plakette' ) . '"><ul>' . implode( '', $items ) . '</ul></nav>';
		$css  = '<style id="plk-legal-css">.plk-legal{box-sizing:border-box;width:100%;padding:.75rem 1rem;font-size:.875rem;line-height:1.5;text-align:center;color:inherit}'
			. '.plk-legal ul{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1rem;list-style:none;margin:0 auto;padding:0}'
			. '.plk-legal li{margin:0;padding:0}.plk-legal li+li::before{content:"\00B7";content:"\00B7"/"";margin-right:1rem;opacity:.6}'
			. '.plk-legal a{display:inline-block;min-height:24px;padding:.125rem 0;color:inherit;text-decoration:underline;text-underline-offset:.15em}'
			. '.plk-legal a:focus-visible{outline:2px solid currentColor;outline-offset:2px}</style>';
		$html = Plakette_Html::append_to_head( $html, $css );
		// Right below the site footer if there is one, otherwise at the end of the page.
		$pos = strripos( $html, '</footer>' );
		$end = strripos( $html, '</body>' );
		if ( false !== $pos && ( false === $end || $pos < $end ) ) {
			$pos += strlen( '</footer>' );
			return substr( $html, 0, $pos ) . "\n" . $nav . substr( $html, $pos );
		}
		return Plakette_Html::append_to_body( $html, $nav );
	}

	/* ------------------------------------------------------------------ module shop_button */

	public static function order_button_text( $text ) {
		return __( 'Zahlungspflichtig bestellen', 'plakette' );
	}

	/** Block checkout (WooCommerce Blocks): label of the order button. */
	public static function order_button_block( $block ) {
		if ( isset( $block['blockName'] ) && 'woocommerce/checkout-actions-block' === $block['blockName'] ) {
			$block['attrs']['placeOrderButtonLabel'] = __( 'Zahlungspflichtig bestellen', 'plakette' );
		}
		return $block;
	}

	/** Does a button text make the payment obligation clear (§ 312j Abs. 3 BGB)? */
	public static function clear_button_text( $text ) {
		return (bool) preg_match( '/zahlungspflichtig|kostenpflichtig|kaufen|jetzt bezahlen|order and pay|buy now|pay now/iu', (string) $text );
	}

	/* ------------------------------------------------------------------ module a11y_lang */

	public static function multilingual() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || defined( 'POLYLANG_VERSION' ) || class_exists( 'TRP_Translate_Press', false ) || defined( 'WEGLOT_VERSION' );
	}

	public static function primary_lang( $lang ) {
		return strtolower( (string) strtok( str_replace( '_', '-', (string) $lang ), '-' ) );
	}

	/** Problem with <html lang> in this document ('' = fine). */
	public static function lang_problem( $html_lang, $want = null ) {
		$want = null === $want ? get_bloginfo( 'language' ) : $want;
		if ( '' === trim( (string) $html_lang ) ) {
			return 'missing';
		}
		if ( ! self::multilingual() && $want && self::primary_lang( $html_lang ) !== self::primary_lang( $want ) ) {
			return 'wrong';
		}
		return '';
	}

	public static function lang_transform( $html ) {
		$want = Plakette_Modules::get( 'a11y_lang' );
		$want = is_string( $want ) && '' !== $want ? $want : get_bloginfo( 'language' );
		if ( ! $want || ! preg_match( '#<html\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$tag = $m[0][0];
		if ( '' === self::lang_problem( Plakette_Html::attr( $tag, 'lang' ), $want ) ) {
			return $html;
		}
		return substr_replace( $html, Plakette_Html::set_attr( $tag, 'lang', $want ), $m[0][1], strlen( $tag ) );
	}

	/* ------------------------------------------------------------------ module a11y_zoom */

	/** Does a viewport content value prevent zooming? */
	public static function zoom_blocked( $content ) {
		$c = strtolower( (string) $content );
		if ( preg_match( '/user-scalable\s*=\s*["\']?(no|0)\b/', $c ) ) {
			return true;
		}
		return preg_match( '/maximum-scale\s*=\s*["\']?([0-9.]+)/', $c, $m ) && (float) $m[1] < 2;
	}

	/** Content values of all <meta name="viewport"> tags. */
	public static function viewports( $html ) {
		$out = array();
		if ( preg_match_all( '#<meta\b[^>]*>#i', (string) $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				if ( 'viewport' === strtolower( Plakette_Html::attr( $tag, 'name' ) ) ) {
					$out[] = Plakette_Html::attr( $tag, 'content' );
				}
			}
		}
		return $out;
	}

	public static function zoom_transform( $html ) {
		return Plakette_Html::outside_code(
			$html,
			function ( $part ) {
				return preg_replace_callback(
					'#<meta\b[^>]*>#i',
					function ( $m ) {
						$tag = $m[0];
						if ( 'viewport' !== strtolower( Plakette_Html::attr( $tag, 'name' ) ) || ! Plakette_Legal::zoom_blocked( Plakette_Html::attr( $tag, 'content' ) ) ) {
							return $tag;
						}
						$value = 'width=device-width, initial-scale=1';
						if ( false !== stripos( Plakette_Html::attr( $tag, 'content' ), 'viewport-fit=cover' ) ) {
							$value .= ', viewport-fit=cover';
						}
						return Plakette_Html::set_attr( $tag, 'content', $value );
					},
					$part
				);
			}
		);
	}

	/* ------------------------------------------------------------------ module a11y_form_labels */

	/**
	 * Form fields without an accessible name.
	 *
	 * @return array[] [ 'pos' => offset, 'tag' => start tag, 'kind' => input type|select|textarea, 'label' => suggested label ('' = none) ]
	 */
	public static function unlabeled_fields( $html ) {
		$html = (string) $html;
		$out  = array();
		if ( ! preg_match_all( '#<(input|select|textarea)\b[^>]*>#i', $html, $fields, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		$skip = array();
		if ( preg_match_all( '#<script\b.*?</script>|<style\b.*?</style>|<template\b.*?</template>|<!--.*?-->|<label\b[^>]*>.*?</label>#is', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $r ) {
				$skip[] = array( $r[1], $r[1] + strlen( $r[0] ) );
			}
		}
		$for = array();
		if ( preg_match_all( '#<label\b[^>]*>#i', $html, $labels ) ) {
			foreach ( $labels[0] as $l ) {
				$id = Plakette_Html::attr( $l, 'for' );
				if ( '' !== $id ) {
					$for[ $id ] = true;
				}
			}
		}
		foreach ( $fields[0] as $i => $f ) {
			$tag  = $f[0];
			$pos  = $f[1];
			$kind = strtolower( $fields[1][ $i ][0] );
			if ( 'input' === $kind ) {
				$type = strtolower( Plakette_Html::attr( $tag, 'type' ) );
				$kind = '' === $type ? 'text' : $type;
				if ( in_array( $kind, array( 'hidden', 'submit', 'button', 'image', 'reset' ), true ) ) {
					continue;
				}
			}
			$inside = false;
			foreach ( $skip as $r ) {
				if ( $pos > $r[0] && $pos < $r[1] ) {
					$inside = true;
					break;
				}
			}
			if ( $inside ) {
				continue;
			}
			if ( '' !== trim( Plakette_Html::attr( $tag, 'aria-label' ) ) || '' !== trim( Plakette_Html::attr( $tag, 'aria-labelledby' ) ) || '' !== trim( Plakette_Html::attr( $tag, 'title' ) ) ) {
				continue;
			}
			$id = Plakette_Html::attr( $tag, 'id' );
			if ( '' !== $id && isset( $for[ $id ] ) ) {
				continue;
			}
			// Hidden fields and spam traps are not read out anyway.
			if ( Plakette_Html::has_attr( $tag, 'hidden' ) || 'true' === strtolower( Plakette_Html::attr( $tag, 'aria-hidden' ) ) || '-1' === Plakette_Html::attr( $tag, 'tabindex' ) || preg_match( '/display\s*:\s*none|visibility\s*:\s*hidden/i', Plakette_Html::attr( $tag, 'style' ) ) ) {
				continue;
			}
			$out[] = array(
				'pos'   => $pos,
				'tag'   => $tag,
				'kind'  => $kind,
				'name'  => Plakette_Html::attr( $tag, 'name' ),
				'label' => self::suggest_label( $tag ),
			);
		}
		return $out;
	}

	/** A label from placeholder or a readable field name ('' when nothing usable). */
	public static function suggest_label( $tag ) {
		$placeholder = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', Plakette_Html::attr( $tag, 'placeholder' ) ) );
		$placeholder = trim( $placeholder, " \t.…*:" );
		if ( '' !== $placeholder ) {
			return $placeholder;
		}
		$name  = strtolower( Plakette_Html::attr( $tag, 'name' ) );
		$words = preg_split( '/[^a-zäöüß]+/u', $name, -1, PREG_SPLIT_NO_EMPTY );
		$stop  = array( 'input', 'field', 'fields', 'form', 'wpforms', 'wpcf', 'nf', 'frm', 'item', 'meta', 'data', 'value', 'your', 'de', 'en', 'id' );
		$words = array_values(
			array_filter(
				(array) $words,
				function ( $w ) use ( $stop ) {
					return ! in_array( $w, $stop, true ) && strlen( $w ) > 1;
				}
			)
		);
		if ( ! $words ) {
			return '';
		}
		$map = array(
			'email' => 'E-Mail', 'mail' => 'E-Mail', 'name' => 'Name', 'first' => 'Vorname', 'last' => 'Nachname', 'firstname' => 'Vorname', 'lastname' => 'Nachname',
			'phone' => 'Telefon', 'tel' => 'Telefon', 'message' => 'Nachricht', 'subject' => 'Betreff', 'search' => 'Suche', 's' => 'Suche', 'company' => 'Firma',
			'address' => 'Adresse', 'street' => 'Straße', 'city' => 'Ort', 'zip' => 'PLZ', 'postcode' => 'PLZ', 'country' => 'Land', 'comment' => 'Kommentar',
			'url' => 'Website', 'website' => 'Website', 'password' => 'Passwort', 'username' => 'Benutzername', 'quantity' => 'Menge', 'qty' => 'Menge', 'date' => 'Datum',
			'newsletter' => 'Newsletter', 'billing' => 'Rechnung', 'shipping' => 'Versand',
		);
		if ( 1 === count( $words ) && isset( $map[ $words[0] ] ) ) {
			return $map[ $words[0] ];
		}
		$label = implode( ' ', $words );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 ) : ucfirst( $label );
	}

	public static function labels_transform( $html ) {
		$fields = self::unlabeled_fields( $html );
		if ( ! $fields ) {
			return $html;
		}
		// Back to front so the offsets stay valid.
		foreach ( array_reverse( $fields ) as $f ) {
			if ( '' === $f['label'] ) {
				continue;
			}
			$html = substr_replace( $html, Plakette_Html::set_attr( $f['tag'], 'aria-label', $f['label'] ), $f['pos'], strlen( $f['tag'] ) );
		}
		return $html;
	}
}

add_action( 'plakette_boot', array( 'Plakette_Legal', 'boot' ) );
