<?php
/**
 * Module "consent": built-in cookie consent banner (DSGVO / § 25 TDDDG).
 *
 * - Scripts of services that need consent (statistics, marketing, media – see Plakette_Services) are rewritten
 *   server-side to  <script type="text/plain" data-plakette-consent="<category>" data-plakette-src="…">
 *   (original type kept in data-plakette-type). assets/consent.js turns them back into real scripts, in order,
 *   once the visitor agrees.
 * - Every decision happens in the browser: the cached HTML is identical for every visitor (page caches work).
 * - Consent lives only in the first-party cookie "plakette_consent" (JSON: v = version, t = time, c = categories,
 *   a = banner answered), 6 months. The version is a hash of the known services – a new service asks again.
 * - Reopen: any element with [data-plakette-consent-open], a[href="#plakette-consent"], the shortcode
 *   [plakette_cookie_settings] or the small round button (setting "reopen").
 *
 * Data: option plakette_consent_services = list of service ids the banner names (grows when pages show new ones).
 * Module value: true.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Consent {

	const COOKIE  = 'plakette_consent';
	const OPTION  = 'plakette_consent_services';
	const VERSION = '1';

	/** @var string[] services found on this request that the stored list did not know yet */
	private static $new = array();

	/** Categories the banner asks for. */
	public static function categories() {
		return array( 'statistics', 'marketing', 'media' );
	}

	public static function boot() {
		add_filter( 'plakette_settings_sections', array( __CLASS__, 'section' ) );
		add_shortcode( 'plakette_cookie_settings', array( __CLASS__, 'shortcode' ) );
		if ( ! Plakette_Modules::on( 'consent' ) ) {
			return;
		}
		Plakette_Html::add( 'consent', array( __CLASS__, 'transform' ), 20 );
	}

	/* ------------------------------------------------------------------ settings */

	public static function defaults() {
		return array(
			'title'         => __( 'Cookies und externe Inhalte', 'plakette' ),
			'text'          => __( 'Wir möchten auf dieser Website Dienste anderer Anbieter nutzen, z. B. für Statistiken oder eingebettete Videos. Dabei können Cookies gesetzt und Daten wie Ihre IP-Adresse an diese Anbieter übertragen werden – teils auch in Länder außerhalb der EU. Das geschieht nur, wenn Sie zustimmen. Ihre Auswahl können Sie jederzeit ändern.', 'plakette' ),
			'btn_all'       => __( 'Alle akzeptieren', 'plakette' ),
			'btn_necessary' => __( 'Nur notwendige', 'plakette' ),
			'btn_settings'  => __( 'Einstellungen', 'plakette' ),
			'btn_save'      => __( 'Auswahl speichern', 'plakette' ),
			'link_label'    => __( 'Cookie-Einstellungen', 'plakette' ),
		);
	}

	public static function section( $sections ) {
		$d                   = self::defaults();
		$sections['consent'] = array(
			'title'  => __( 'Cookie-Banner', 'plakette' ),
			'desc'   => __( 'Der Cookie-Banner von Plakette fragt deine Besucher, bevor Statistik-, Marketing- und Mediendienste geladen werden. Die Texte richten sich an Besucher und stehen deshalb in der Sie-Form. Den Link „Cookie-Einstellungen“ kannst du mit dem Shortcode [plakette_cookie_settings] z. B. in den Footer setzen.', 'plakette' ),
			'fields' => array(
				array( 'key' => 'title', 'label' => __( 'Überschrift', 'plakette' ), 'type' => 'text', 'default' => $d['title'] ),
				array( 'key' => 'text', 'label' => __( 'Text', 'plakette' ), 'type' => 'textarea', 'default' => $d['text'] ),
				array( 'key' => 'btn_all', 'label' => __( 'Knopf „Alle akzeptieren“', 'plakette' ), 'type' => 'text', 'default' => $d['btn_all'] ),
				array( 'key' => 'btn_necessary', 'label' => __( 'Knopf „Nur notwendige“', 'plakette' ), 'type' => 'text', 'default' => $d['btn_necessary'] ),
				array( 'key' => 'btn_settings', 'label' => __( 'Knopf „Einstellungen“', 'plakette' ), 'type' => 'text', 'default' => $d['btn_settings'] ),
				array( 'key' => 'btn_save', 'label' => __( 'Knopf „Auswahl speichern“', 'plakette' ), 'type' => 'text', 'default' => $d['btn_save'] ),
				array( 'key' => 'link_label', 'label' => __( 'Text des Links zum Ändern der Auswahl', 'plakette' ), 'type' => 'text', 'default' => $d['link_label'] ),
				array(
					'key'     => 'reopen',
					'label'   => __( 'Kleinen Knopf zum Ändern anzeigen', 'plakette' ),
					'type'    => 'toggle',
					'default' => true,
					'help'    => __( 'Ein runder Knopf unten links öffnet die Einstellungen wieder. Besucher müssen ihre Einwilligung so leicht widerrufen können, wie sie sie gegeben haben – lass ihn an, wenn du keinen eigenen Link im Footer hast.', 'plakette' ),
				),
			),
		);
		return $sections;
	}

	private static function text( $key ) {
		$v = trim( (string) Plakette_Settings::get( 'consent', $key ) );
		if ( '' === $v ) {
			$d = self::defaults();
			$v = isset( $d[ $key ] ) ? $d[ $key ] : '';
		}
		return $v;
	}

	public static function shortcode( $atts ) {
		if ( ! Plakette_Modules::on( 'consent' ) ) {
			return '';
		}
		$atts = shortcode_atts( array( 'text' => '' ), (array) $atts, 'plakette_cookie_settings' );
		$text = '' !== $atts['text'] ? $atts['text'] : self::text( 'link_label' );
		return '<a href="#plakette-consent" class="plakette-consent-link" data-plakette-consent-open>' . esc_html( $text ) . '</a>';
	}

	/* ------------------------------------------------------------------ services */

	/** Service ids the banner names. */
	public static function services() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? array_values( array_unique( array_map( 'strval', $list ) ) ) : array();
	}

	/** Does a catalogue service need our banner? */
	public static function handles( $id ) {
		$s = Plakette_Services::get( $id );
		return $s && ! empty( $s['consent'] ) && in_array( $s['category'], self::categories(), true );
	}

	/** Stores the services the banner names (with undo). Used by the fix. */
	public static function remember( array $ids, array &$undo ) {
		$ids = array_values( array_unique( array_merge( self::services(), array_filter( $ids, array( __CLASS__, 'handles' ) ) ) ) );
		sort( $ids );
		Plakette_Log::set_option( self::OPTION, $ids, $undo, false );
	}

	public static function version( array $ids ) {
		sort( $ids );
		return substr( md5( self::VERSION . '|' . implode( ',', $ids ) ), 0, 10 );
	}

	/* ------------------------------------------------------------------ HTML transform */

	private static function is_js_type( $type ) {
		$type = strtolower( trim( (string) $type ) );
		return '' === $type || 'module' === $type || in_array( $type, array( 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript', 'application/x-javascript' ), true );
	}

	/** Service id for one script element (src or inline code) that our banner must hold back, or ''. */
	public static function script_service( $open_tag, $code ) {
		$src = Plakette_Html::attr( $open_tag, 'src' );
		if ( '' !== $src ) {
			if ( ! preg_match( '#^(https?:)?//#i', $src ) ) {
				return '';
			}
			$id = Plakette_Services::for_url( $src );
			return $id && self::handles( $id ) ? $id : '';
		}
		if ( '' === trim( $code ) ) {
			return '';
		}
		foreach ( Plakette_Services::all() as $id => $s ) {
			if ( ! self::handles( $id ) ) {
				continue;
			}
			foreach ( $s['patterns'] as $re ) {
				if ( @preg_match( $re, $code ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return $id;
				}
			}
		}
		return '';
	}

	/** Rewrites consent-requiring scripts so the browser does not run them. @return string */
	public static function block_scripts( $html, array &$found ) {
		$out = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( &$found ) {
				$open = '<script' . $m[1] . '>';
				if ( Plakette_Services::is_held_back( $open ) || Plakette_Html::has_attr( $open, 'data-plakette-own' ) || 0 === strpos( strtolower( Plakette_Html::attr( $open, 'id' ) ), 'plakette' ) ) {
					return $m[0];
				}
				$type = Plakette_Html::attr( $open, 'type' );
				if ( ! self::is_js_type( $type ) ) {
					return $m[0];
				}
				$id = self::script_service( $open, $m[2] );
				if ( '' === $id ) {
					return $m[0];
				}
				$service      = Plakette_Services::get( $id );
				$found[ $id ] = true;
				$tag          = Plakette_Html::remove_attr( $open, 'type' );
				$src          = Plakette_Html::attr( $tag, 'src' );
				if ( '' !== $src ) {
					$tag = Plakette_Html::remove_attr( $tag, 'src' );
					$tag = Plakette_Html::set_attr( $tag, 'data-plakette-src', $src );
				}
				if ( '' !== $type ) {
					$tag = Plakette_Html::set_attr( $tag, 'data-plakette-type', $type );
				}
				$tag = Plakette_Html::set_attr( $tag, 'data-plakette-service', $id );
				$tag = Plakette_Html::set_attr( $tag, 'data-plakette-consent', $service['category'] );
				$tag = Plakette_Html::set_attr( $tag, 'type', 'text/plain' );
				return $tag . $m[2] . '</script>';
			},
			$html
		);
		return is_string( $out ) ? $out : $html;
	}

	public static function transform( $html ) {
		$found = array();
		$html  = self::block_scripts( $html, $found );
		// Iframes held back by the two-click placeholders also belong to the banner (category media).
		if ( preg_match_all( '#data-plakette-embed="([a-z0-9-]+)"#', $html, $m ) ) {
			foreach ( $m[1] as $id ) {
				if ( self::handles( $id ) ) {
					$found[ $id ] = true;
				}
			}
		}
		$known = self::services();
		$new   = array_diff( array_keys( $found ), $known );
		if ( $new ) {
			self::$new = $new;
			add_action( 'shutdown', array( __CLASS__, 'store_new' ), 50 );
		}
		$ids  = array_values( array_unique( array_merge( $known, array_keys( $found ) ) ) );
		$html = self::insert_banner( $html, $ids );
		return self::assets( $html );
	}

	/** A page showed services the banner did not name yet: remember them (new version = visitors are asked again). */
	public static function store_new() {
		$ids = array_values( array_unique( array_merge( self::services(), self::$new ) ) );
		sort( $ids );
		update_option( self::OPTION, $ids, false );
		self::$new = array();
		if ( class_exists( 'Plakette_Page_Cache' ) ) {
			Plakette_Page_Cache::purge_all();
		}
	}

	private static function insert_banner( $html, array $ids ) {
		if ( false !== strpos( $html, 'id="plakette-consent"' ) ) {
			return $html;
		}
		$markup = self::banner( $ids );
		if ( preg_match( '#<body\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			// First in the tab order for keyboard users, shown fixed at the bottom.
			$pos = $m[0][1] + strlen( $m[0][0] );
			return substr( $html, 0, $pos ) . "\n" . $markup . substr( $html, $pos );
		}
		return Plakette_Html::append_to_body( $html, $markup );
	}

	/** Shared front-end assets (banner and two-click placeholders), added once. */
	public static function assets( $html ) {
		if ( false === strpos( $html, 'id="plakette-consent-css"' ) ) {
			$css = self::file( 'consent.css' );
			if ( '' !== $css ) {
				$html = Plakette_Html::append_to_head( $html, '<style id="plakette-consent-css">' . $css . '</style>' );
			}
		}
		if ( false === strpos( $html, 'id="plakette-consent-js"' ) ) {
			$url  = PLAKETTE_URL . 'assets/consent.js?ver=' . rawurlencode( PLAKETTE_VERSION . '-' . self::mtime( 'consent.js' ) );
			$html = Plakette_Html::append_to_body( $html, '<script src="' . esc_url( $url ) . '" id="plakette-consent-js" defer></script>' );
		}
		return $html;
	}

	private static function mtime( $name ) {
		$f = PLAKETTE_DIR . 'assets/' . $name;
		return file_exists( $f ) ? (string) filemtime( $f ) : '0';
	}

	private static function file( $name ) {
		static $cache = array();
		if ( ! isset( $cache[ $name ] ) ) {
			$f              = PLAKETTE_DIR . 'assets/' . $name;
			$cache[ $name ] = file_exists( $f ) ? trim( (string) file_get_contents( $f ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
			$cache[ $name ] = str_replace( '</', '<\/', $cache[ $name ] );
		}
		return $cache[ $name ];
	}

	public static function imprint_url() {
		$id = (int) get_option( 'plakette_imprint_page' );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}
		return '';
	}

	public static function category_text( $cat ) {
		$t = array(
			'necessary'  => __( 'Für den Betrieb der Website nötig, z. B. um Ihre Auswahl in diesem Fenster zu speichern. Lässt sich nicht abwählen.', 'plakette' ),
			'statistics' => __( 'Hilft uns zu verstehen, wie Besucher die Website nutzen, z. B. welche Seiten oft aufgerufen werden.', 'plakette' ),
			'marketing'  => __( 'Misst den Erfolg von Werbeanzeigen und ermöglicht Werbung, die zu Ihren Interessen passt.', 'plakette' ),
			'media'      => __( 'Videos, Karten und andere Inhalte fremder Plattformen. Ohne Zustimmung sehen Sie an ihrer Stelle einen Platzhalter.', 'plakette' ),
		);
		return isset( $t[ $cat ] ) ? $t[ $cat ] : '';
	}

	/** Banner markup – identical for every visitor; the script decides whether it is shown. */
	public static function banner( array $ids ) {
		$by_cat = array();
		foreach ( $ids as $id ) {
			$s = Plakette_Services::get( $id );
			if ( $s && self::handles( $id ) ) {
				$by_cat[ $s['category'] ][] = $s['name'];
			}
		}
		$cats = array();
		foreach ( self::categories() as $cat ) {
			if ( ! empty( $by_cat[ $cat ] ) || ( 'media' === $cat && Plakette_Modules::on( 'embeds' ) ) ) {
				$cats[] = $cat;
			}
		}
		if ( ! $cats ) {
			$cats[] = 'media';
		}
		$config = array(
			'v'      => self::version( $ids ),
			'cats'   => $cats,
			'reopen' => (bool) Plakette_Settings::get( 'consent', 'reopen' ),
		);

		$rows  = '<div class="plk-cc__cat"><label class="plk-cc__switch"><input type="checkbox" checked disabled> <span>' . esc_html( Plakette_Services::category_label( 'necessary' ) ) . '</span></label>';
		$rows .= '<p class="plk-cc__desc">' . esc_html( self::category_text( 'necessary' ) ) . '</p></div>';
		foreach ( $cats as $cat ) {
			$desc = self::category_text( $cat );
			if ( ! empty( $by_cat[ $cat ] ) ) {
				$names = array_values( array_unique( $by_cat[ $cat ] ) );
				$desc .= ' ' . sprintf( /* translators: %s: list of services */ __( 'Dienste: %s.', 'plakette' ), implode( ', ', $names ) );
			}
			$rows .= '<div class="plk-cc__cat"><label class="plk-cc__switch"><input type="checkbox" role="switch" data-plk-cat="' . esc_attr( $cat ) . '"> <span>' . esc_html( Plakette_Services::category_label( $cat ) ) . '</span></label>';
			$rows .= '<p class="plk-cc__desc">' . esc_html( $desc ) . '</p></div>';
		}

		$links   = array();
		$privacy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		if ( $privacy ) {
			$links[] = '<a href="' . esc_url( $privacy ) . '">' . esc_html__( 'Datenschutzerklärung', 'plakette' ) . '</a>';
		}
		$imprint = self::imprint_url();
		if ( $imprint ) {
			$links[] = '<a href="' . esc_url( $imprint ) . '">' . esc_html__( 'Impressum', 'plakette' ) . '</a>';
		}

		$h  = '<script type="application/json" id="plakette-consent-config">' . wp_json_encode( $config ) . '</script>';
		$h .= '<div id="plakette-consent" class="plk-cc" data-plakette-consent-root role="dialog" aria-modal="false" aria-labelledby="plk-cc-title" aria-describedby="plk-cc-text" hidden>';
		$h .= '<div class="plk-cc__box">';
		$h .= '<h2 class="plk-cc__title" id="plk-cc-title" tabindex="-1">' . esc_html( self::text( 'title' ) ) . '</h2>';
		$h .= '<p class="plk-cc__text" id="plk-cc-text">' . esc_html( self::text( 'text' ) ) . '</p>';
		$h .= '<div class="plk-cc__prefs" id="plk-cc-prefs" hidden>' . $rows . '</div>';
		$h .= '<div class="plk-cc__buttons">';
		$h .= '<button type="button" class="plk-cc__btn" data-plk-cc="all">' . esc_html( self::text( 'btn_all' ) ) . '</button>';
		$h .= '<button type="button" class="plk-cc__btn" data-plk-cc="necessary">' . esc_html( self::text( 'btn_necessary' ) ) . '</button>';
		$h .= '<button type="button" class="plk-cc__btn" data-plk-cc="settings" aria-expanded="false" aria-controls="plk-cc-prefs">' . esc_html( self::text( 'btn_settings' ) ) . '</button>';
		$h .= '<button type="button" class="plk-cc__btn" data-plk-cc="save" hidden>' . esc_html( self::text( 'btn_save' ) ) . '</button>';
		$h .= '</div>';
		if ( $links ) {
			$h .= '<p class="plk-cc__links">' . implode( ' <span aria-hidden="true">·</span> ', $links ) . '</p>';
		}
		$h .= '</div></div>';
		$h .= '<button type="button" class="plk-cc-reopen" data-plakette-consent-open aria-label="' . esc_attr( self::text( 'link_label' ) ) . '" title="' . esc_attr( self::text( 'link_label' ) ) . '" hidden>';
		$h .= '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5zm-3.5 7a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm-1 5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zm6 1a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3z"/></svg>';
		$h .= '</button>';
		return $h;
	}
}

add_action( 'plakette_boot', array( 'Plakette_Consent', 'boot' ) );
