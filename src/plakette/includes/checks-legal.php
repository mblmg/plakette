<?php
/**
 * Legal checks (area "Sicherheit"): Impressum, Datenschutzerklärung, links to both, outdated law references,
 * shop duties – and the accessibility duties of the BFSG.
 *
 * Legal situation as of November 2026:
 *  - Impressum: § 5 DDG (Digitale-Dienste-Gesetz, replaced the TMG on 14 May 2024), § 18 Abs. 2 MStV (replaced § 55 RStV).
 *  - Cookies: § 25 TDDDG (formerly TTDSG).
 *  - The EU ODR platform was shut down on 20 July 2025 (Regulation (EU) 2024/3228) – links to it are obsolete.
 *  - BFSG since 28 June 2025 for B2C e-commerce and services (micro-enterprises providing services are exempt).
 *
 * Texts for visitors (Impressum, Datenschutzerklärung, Erklärung zur Barrierefreiheit) use the formal "Sie".
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page generators (Gutenberg block markup) and shared helpers of the legal checks.
 */
class Plakette_Legal_Pages {

	const LEGAL = 'Recht';
	const BFSG  = 'Barrierefreiheit (BFSG)';

	/** Company fields for the forms of the legal checks. */
	const IMPRINT_FIELDS = array( 'name', 'legal_form', 'owner', 'street', 'zip', 'city', 'country', 'phone', 'email', 'vat_id', 'register', 'profession', 'authority', 'editorial' );
	const PRIVACY_FIELDS = array( 'name', 'legal_form', 'owner', 'street', 'zip', 'city', 'region', 'country', 'phone', 'email' );

	/* ------------------------------------------------------------------ blocks */

	public static function p( $html ) {
		return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->\n\n";
	}

	public static function h( $text, $level = 2 ) {
		$level = (int) $level;
		$attrs = 2 === $level ? '' : ' {"level":' . $level . '}';
		return "<!-- wp:heading$attrs -->\n<h$level class=\"wp-block-heading\">" . esc_html( $text ) . "</h$level>\n<!-- /wp:heading -->\n\n";
	}

	/** List block; items are HTML (escape before). */
	public static function ul( array $items ) {
		$out = "<!-- wp:list -->\n<ul class=\"wp-block-list\">";
		foreach ( $items as $item ) {
			$out .= "<!-- wp:list-item -->\n<li>" . $item . "</li>\n<!-- /wp:list-item -->";
		}
		return $out . "</ul>\n<!-- /wp:list -->\n\n";
	}

	/** Escaped lines joined with <br>. */
	public static function lines( array $lines ) {
		$lines = array_filter( array_map( 'trim', array_map( 'strval', $lines ) ), 'strlen' );
		return implode( '<br>', array_map( 'esc_html', $lines ) );
	}

	public static function mail_link( $email ) {
		$email = sanitize_email( (string) $email );
		return '' === $email ? '' : '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
	}

	public static function stand() {
		$months = array( 1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' );
		$now    = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		return self::p( esc_html( sprintf( __( 'Stand: %1$s %2$s', 'plakette' ), $months[ (int) gmdate( 'n', $now ) ], gmdate( 'Y', $now ) ) ) );
	}

	/* ------------------------------------------------------------------ company */

	public static function company() {
		$c = Plakette_Settings::company();
		if ( '' === trim( (string) $c['name'] ) && ! empty( $c['name_fallback'] ) ) {
			$c['name'] = $c['name_fallback'];
		}
		return $c;
	}

	public static function v( array $c, $key ) {
		return isset( $c[ $key ] ) ? trim( (string) $c[ $key ] ) : '';
	}

	public static function country_name( $code ) {
		$names = array( 'DE' => '', 'AT' => 'Österreich', 'CH' => 'Schweiz', 'LI' => 'Liechtenstein', 'LU' => 'Luxemburg', 'NL' => 'Niederlande', 'BE' => 'Belgien', 'FR' => 'Frankreich', 'IT' => 'Italien', 'DK' => 'Dänemark', 'PL' => 'Polen', 'CZ' => 'Tschechien', 'ES' => 'Spanien' );
		$code  = strtoupper( (string) $code );
		return isset( $names[ $code ] ) ? $names[ $code ] : $code;
	}

	public static function address_lines( array $c, $with_name = true ) {
		return array(
			$with_name ? self::v( $c, 'name' ) : '',
			self::v( $c, 'street' ),
			trim( self::v( $c, 'zip' ) . ' ' . self::v( $c, 'city' ) ),
			self::country_name( self::v( $c, 'country' ) ),
		);
	}

	/**
	 * Missing required company data for a page (labels). Empty = complete.
	 *
	 * @param string $for imprint|privacy|bfsg
	 */
	public static function missing( array $c, $for ) {
		$labels = array();
		foreach ( Plakette_Settings::company_fields() as $f ) {
			$labels[ $f['key'] ] = $f['label'];
		}
		$need = array( 'name', 'street', 'zip', 'city', 'email' );
		if ( 'imprint' === $for && 'privat' !== self::v( $c, 'legal_form' ) ) {
			$need[] = 'owner';
		}
		if ( 'bfsg' === $for ) {
			$need = array( 'name', 'email' );
		}
		$missing = array();
		foreach ( $need as $k ) {
			if ( '' === self::v( $c, $k ) ) {
				$missing[] = $labels[ $k ];
			}
		}
		if ( '' !== self::v( $c, 'email' ) && ! is_email( self::v( $c, 'email' ) ) ) {
			$missing[] = __( 'eine gültige E-Mail-Adresse', 'plakette' );
		}
		if ( 'bfsg' !== $for && in_array( self::v( $c, 'country' ), array( '', 'DE' ), true ) && '' !== self::v( $c, 'zip' ) && ! preg_match( '/^\d{5}$/', self::v( $c, 'zip' ) ) ) {
			$missing[] = __( 'eine fünfstellige PLZ', 'plakette' );
		}
		return $missing;
	}

	/** Saves the form values (if any) and returns the company data or a WP_Error naming what is missing. */
	public static function save_company( array $req, $for, array &$undo ) {
		if ( isset( $req['value'] ) && is_array( $req['value'] ) && $req['value'] ) {
			Plakette_Settings::save( 'company', $req['value'], $undo );
		}
		$c       = self::company();
		$missing = self::missing( $c, $for );
		if ( $missing ) {
			return new WP_Error(
				'plakette_missing',
				/* translators: %s: list of fields */
				sprintf( __( 'Bitte ergänze noch: %s.', 'plakette' ), implode( ', ', $missing ) )
			);
		}
		return $c;
	}

	/* ------------------------------------------------------------------ Impressum */

	public static function imprint( array $c ) {
		$form    = self::v( $c, 'legal_form' );
		$private = 'privat' === $form;
		$out     = '';
		$contact = array();
		if ( '' !== self::v( $c, 'phone' ) ) {
			$contact[] = esc_html__( 'Telefon:', 'plakette' ) . ' ' . esc_html( self::v( $c, 'phone' ) );
		}
		$contact[] = esc_html__( 'E-Mail:', 'plakette' ) . ' ' . self::mail_link( self::v( $c, 'email' ) );

		if ( $private ) {
			$out .= self::h( __( 'Verantwortlich für diese Website', 'plakette' ) );
			$out .= self::p( self::lines( self::address_lines( $c ) ) );
			$out .= self::h( __( 'Kontakt', 'plakette' ) );
			$out .= self::p( implode( '<br>', $contact ) );
			if ( '' !== self::v( $c, 'editorial' ) ) {
				$out .= self::h( __( 'Redaktionell verantwortlich', 'plakette' ) );
				$out .= self::p( esc_html__( 'Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV:', 'plakette' ) . '<br>' . esc_html( self::v( $c, 'editorial' ) ) );
			}
			return $out;
		}

		$out .= self::h( __( 'Angaben gemäß § 5 DDG', 'plakette' ) );
		$out .= self::p( self::lines( self::address_lines( $c ) ) );
		if ( '' !== self::v( $c, 'owner' ) ) {
			$out .= self::h( 'einzel' === $form ? __( 'Inhaber', 'plakette' ) : __( 'Vertreten durch', 'plakette' ) );
			$out .= self::p( esc_html( self::v( $c, 'owner' ) ) );
		}
		$out .= self::h( __( 'Kontakt', 'plakette' ) );
		$out .= self::p( implode( '<br>', $contact ) );
		if ( '' !== self::v( $c, 'register' ) ) {
			$out .= self::h( __( 'Registereintrag', 'plakette' ) );
			$out .= self::p( esc_html__( 'Registergericht und Registernummer:', 'plakette' ) . '<br>' . esc_html( self::v( $c, 'register' ) ) );
		}
		if ( '' !== self::v( $c, 'vat_id' ) ) {
			$out .= self::h( __( 'Umsatzsteuer-ID', 'plakette' ) );
			$out .= self::p( esc_html__( 'Umsatzsteuer-Identifikationsnummer gemäß § 27 a Umsatzsteuergesetz:', 'plakette' ) . '<br>' . esc_html( self::v( $c, 'vat_id' ) ) );
		}
		if ( '' !== self::v( $c, 'profession' ) ) {
			$out .= self::h( __( 'Berufsbezeichnung und berufsrechtliche Regelungen', 'plakette' ) );
			$out .= self::p( self::lines( explode( "\n", self::v( $c, 'profession' ) ) ) );
		}
		if ( '' !== self::v( $c, 'authority' ) ) {
			$out .= self::h( __( 'Zuständige Aufsichtsbehörde', 'plakette' ) );
			$out .= self::p( esc_html( self::v( $c, 'authority' ) ) );
		}
		if ( '' !== self::v( $c, 'editorial' ) ) {
			$out .= self::h( __( 'Redaktionell verantwortlich', 'plakette' ) );
			$out .= self::p( esc_html__( 'Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV:', 'plakette' ) . '<br>' . esc_html( self::v( $c, 'editorial' ) ) );
		}
		$out .= self::h( __( 'Verbraucherstreitbeilegung', 'plakette' ) );
		$out .= self::p( esc_html__( 'Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.', 'plakette' ) );
		return $out;
	}

	/* ------------------------------------------------------------------ Datenschutzerklärung */

	/** Data protection authority of a German state ('' if unknown). */
	public static function authority_for( $region ) {
		$key  = strtolower( (string) preg_replace( '/[^a-zäöü]/iu', '', (string) $region ) );
		$key  = strtr( $key, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue' ) );
		$list = array(
			'badenwuerttemberg'      => array( array( 'bw' ), 'Der Landesbeauftragte für den Datenschutz und die Informationsfreiheit Baden-Württemberg' ),
			'bayern'                 => array( array( 'by', 'bavaria' ), 'Bayerisches Landesamt für Datenschutzaufsicht (BayLDA)' ),
			'berlin'                 => array( array( 'be' ), 'Berliner Beauftragte für Datenschutz und Informationsfreiheit' ),
			'brandenburg'            => array( array( 'bb' ), 'Die Landesbeauftragte für den Datenschutz und für das Recht auf Akteneinsicht Brandenburg' ),
			'bremen'                 => array( array( 'hb' ), 'Die Landesbeauftragte für Datenschutz und Informationsfreiheit der Freien Hansestadt Bremen' ),
			'hamburg'                => array( array( 'hh' ), 'Der Hamburgische Beauftragte für Datenschutz und Informationsfreiheit' ),
			'hessen'                 => array( array( 'he', 'hesse' ), 'Der Hessische Beauftragte für Datenschutz und Informationsfreiheit' ),
			'mecklenburgvorpommern'  => array( array( 'mv' ), 'Der Landesbeauftragte für Datenschutz und Informationsfreiheit Mecklenburg-Vorpommern' ),
			'niedersachsen'          => array( array( 'ni', 'nds', 'lowersaxony' ), 'Die Landesbeauftragte für den Datenschutz Niedersachsen' ),
			'nordrheinwestfalen'     => array( array( 'nw', 'nrw' ), 'Landesbeauftragte für Datenschutz und Informationsfreiheit Nordrhein-Westfalen' ),
			'rheinlandpfalz'         => array( array( 'rp', 'rlp' ), 'Der Landesbeauftragte für den Datenschutz und die Informationsfreiheit Rheinland-Pfalz' ),
			'saarland'               => array( array( 'sl' ), 'Unabhängiges Datenschutzzentrum Saarland' ),
			'sachsen'                => array( array( 'sn', 'saxony' ), 'Die Sächsische Datenschutz- und Transparenzbeauftragte' ),
			'sachsenanhalt'          => array( array( 'st', 'saxonyanhalt' ), 'Der Landesbeauftragte für den Datenschutz Sachsen-Anhalt' ),
			'schleswigholstein'      => array( array( 'sh' ), 'Unabhängiges Landeszentrum für Datenschutz Schleswig-Holstein (ULD)' ),
			'thueringen'             => array( array( 'th', 'thuringia' ), 'Der Thüringer Landesbeauftragte für den Datenschutz und die Informationsfreiheit' ),
		);
		foreach ( $list as $name => $entry ) {
			if ( $key === $name || in_array( $key, $entry[0], true ) ) {
				return $entry[1];
			}
		}
		return '';
	}

	/** Services in iframes / embed blocks / oEmbed lines of published content: id => where. */
	public static function content_services( array $posts ) {
		$found = array();
		foreach ( $posts as $post ) {
			$content = (string) $post->post_content;
			if ( false === strpos( $content, '://' ) ) {
				continue;
			}
			$urls = array();
			if ( preg_match_all( '#<!--\s*wp:(?:core-)?embed\b[^>]*?"url"\s*:\s*"([^"]+)"#i', $content, $m ) ) {
				$urls = array_merge( $urls, array_map( 'stripslashes', $m[1] ) );
			}
			if ( preg_match_all( '#<iframe\b[^>]*>#i', $content, $m ) ) {
				foreach ( $m[0] as $tag ) {
					$urls[] = Plakette_Html::attr( $tag, 'src' );
					$urls[] = Plakette_Html::attr( $tag, 'data-src' );
				}
			}
			if ( preg_match_all( '#^\s*(https?://[^\s<>"]+)\s*$#mi', wp_strip_all_tags( $content ), $m ) ) {
				$urls = array_merge( $urls, $m[1] );
			}
			foreach ( array_filter( $urls ) as $url ) {
				$id = Plakette_Services::for_url( $url );
				if ( $id && ! isset( $found[ $id ] ) ) {
					$found[ $id ] = sprintf( __( 'eingebettet in „%s“', 'plakette' ), get_the_title( $post ) );
				}
			}
		}
		return $found;
	}

	/** Services the site uses: id => service + 'where' (list). Sorted by category. */
	public static function services( Plakette_Context $ctx ) {
		$found = array();
		foreach ( Plakette_Services::detect( implode( "\n", $ctx->all_html() ) ) as $id => $hit ) {
			$found[ $id ] = $hit['where'];
		}
		foreach ( self::content_services( $ctx->posts() ) as $id => $where ) {
			if ( ! isset( $found[ $id ] ) ) {
				$found[ $id ] = array( $where );
			}
		}
		$order = array( 'necessary' => 0, 'functional' => 1, 'statistics' => 2, 'marketing' => 3, 'media' => 4 );
		$out   = array();
		foreach ( $found as $id => $where ) {
			$s = Plakette_Services::get( $id );
			if ( $s ) {
				$s['where'] = (array) $where;
				$s['order'] = isset( $order[ $s['category'] ] ) ? $order[ $s['category'] ] : 9;
				$out[ $id ] = $s;
			}
		}
		uasort(
			$out,
			function ( $a, $b ) {
				return $a['order'] - $b['order'];
			}
		);
		return $out;
	}

	/** Is the service named in this (lower-case, tag-free) text? */
	public static function mentioned( $text, array $service ) {
		foreach ( array_merge( array( $service['name'] ), isset( $service['aliases'] ) ? (array) $service['aliases'] : array() ) as $word ) {
			if ( '' !== $word && false !== mb_stripos( $text, $word ) ) {
				return true;
			}
		}
		return false;
	}

	public static function plain_text( $content ) {
		// Tags become spaces, so "7<br>79098" does not turn into "779098".
		$content = (string) preg_replace( '#<(script|style)\b.*?</\1>#is', ' ', (string) $content );
		return html_entity_decode( (string) preg_replace( '#<[^>]*>#', ' ', $content ), ENT_QUOTES, 'UTF-8' );
	}

	/** Blocks for one service in the privacy policy. */
	public static function service_blocks( array $s ) {
		$out = self::h( $s['name'], 3 ) . self::p( esc_html( $s['policy'] ) );
		if ( ! empty( $s['provider'] ) ) {
			$out .= self::p( esc_html__( 'Anbieter:', 'plakette' ) . ' ' . esc_html( $s['provider'] ) );
		}
		return $out;
	}

	/** Contact form plugins / builder forms in use. */
	public static function form_tools( $html ) {
		$tools = array(
			'Contact Form 7'   => defined( 'WPCF7_VERSION' ) || false !== strpos( $html, 'wpcf7' ),
			'WPForms'          => defined( 'WPFORMS_VERSION' ) || false !== strpos( $html, 'wpforms-form' ),
			'Gravity Forms'    => class_exists( 'GFForms', false ) || false !== strpos( $html, 'gform_wrapper' ),
			'Fluent Forms'     => defined( 'FLUENTFORM' ) || false !== strpos( $html, 'fluentform' ),
			'Ninja Forms'      => class_exists( 'Ninja_Forms', false ) || false !== strpos( $html, 'nf-form-cont' ),
			'Formidable Forms' => class_exists( 'FrmAppHelper', false ) || false !== strpos( $html, 'frm_forms' ),
			'Elementor'        => false !== strpos( $html, 'elementor-form' ),
			'Breakdance'       => false !== strpos( $html, 'breakdance-form' ),
		);
		return array_keys( array_filter( $tools ) );
	}

	public static function comments_open() {
		global $wpdb;
		if ( 'open' === get_option( 'default_comment_status' ) ) {
			return true;
		}
		return (bool) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND comment_status = 'open' AND post_type IN ('post','page') LIMIT 1" ); // phpcs:ignore WordPress.DB
	}

	/** Everything the privacy policy generator needs to know about the site. */
	public static function privacy_facts( Plakette_Context $ctx ) {
		$html = implode( "\n", $ctx->all_html() );
		return array(
			'services' => self::services( $ctx ),
			'consent'  => Plakette_Modules::on( 'consent' ) ? 'Plakette' : Plakette_Services::consent_tool( $html ),
			'comments' => self::comments_open(),
			'forms'    => self::form_tools( $html ),
			'register' => (bool) get_option( 'users_can_register' ),
			'shop'     => Plakette_Legal::shop_system(),
			'https'    => 0 === strpos( home_url( '/' ), 'https://' ),
		);
	}

	public static function privacy( array $c, array $f ) {
		$out = '';

		$out .= self::h( __( 'Verantwortlicher', 'plakette' ) );
		$out .= self::p( esc_html__( 'Verantwortlich für die Verarbeitung personenbezogener Daten auf dieser Website ist:', 'plakette' ) );
		$who  = self::lines( self::address_lines( $c ) );
		if ( '' !== self::v( $c, 'owner' ) && 'privat' !== self::v( $c, 'legal_form' ) ) {
			$who .= '<br>' . esc_html( ( 'einzel' === self::v( $c, 'legal_form' ) ? __( 'Inhaber:', 'plakette' ) : __( 'Vertreten durch:', 'plakette' ) ) . ' ' . self::v( $c, 'owner' ) );
		}
		if ( '' !== self::v( $c, 'phone' ) ) {
			$who .= '<br>' . esc_html__( 'Telefon:', 'plakette' ) . ' ' . esc_html( self::v( $c, 'phone' ) );
		}
		$who .= '<br>' . esc_html__( 'E-Mail:', 'plakette' ) . ' ' . self::mail_link( self::v( $c, 'email' ) );
		$out .= self::p( $who );

		$out .= self::h( __( 'Allgemeine Hinweise', 'plakette' ) );
		$out .= self::p( esc_html__( 'Personenbezogene Daten sind alle Daten, mit denen Sie persönlich identifiziert werden können. Mit dieser Datenschutzerklärung informieren wir Sie nach Art. 13 DSGVO darüber, welche Daten wir beim Besuch dieser Website verarbeiten, zu welchem Zweck und auf welcher Rechtsgrundlage.', 'plakette' ) );

		$out .= self::h( __( 'Hosting und Server-Logfiles', 'plakette' ) );
		$out .= self::p( esc_html__( 'Diese Website wird bei einem externen Dienstleister (Hoster) betrieben. Die Daten, die beim Besuch der Website anfallen, werden auf dessen Servern verarbeitet. Der Hoster handelt in unserem Auftrag und nach unseren Weisungen (Auftragsverarbeitung nach Art. 28 DSGVO).', 'plakette' ) );
		$out .= self::p( esc_html__( 'Bei jedem Aufruf einer Seite speichert der Server automatisch Informationen, die Ihr Browser übermittelt (Server-Logfiles): IP-Adresse, Datum und Uhrzeit der Anfrage, aufgerufene Adresse, zuvor besuchte Seite (Referrer), Browser und Betriebssystem. Diese Daten sind für die sichere und stabile Auslieferung der Website technisch erforderlich. Rechtsgrundlage ist unser berechtigtes Interesse an einem sicheren Betrieb (Art. 6 Abs. 1 lit. f DSGVO). Die Logfiles werden nach kurzer Zeit automatisch gelöscht, sofern sie nicht zur Aufklärung eines Sicherheitsvorfalls benötigt werden.', 'plakette' ) );

		if ( ! empty( $f['https'] ) ) {
			$out .= self::h( __( 'SSL- bzw. TLS-Verschlüsselung', 'plakette' ) );
			$out .= self::p( esc_html__( 'Diese Website nutzt aus Sicherheitsgründen eine SSL- bzw. TLS-Verschlüsselung. Eine verschlüsselte Verbindung erkennen Sie an „https://“ in der Adresszeile Ihres Browsers. Daten, die Sie an uns übermitteln, können so nicht von Dritten mitgelesen werden.', 'plakette' ) );
		}

		$needs_consent = false;
		foreach ( $f['services'] as $s ) {
			$needs_consent = $needs_consent || ! empty( $s['consent'] );
		}
		$out .= self::h( __( 'Cookies und Einwilligung', 'plakette' ) );
		$out .= self::p( esc_html__( 'Cookies sind kleine Textdateien, die Ihr Browser speichert. Technisch notwendige Cookies, etwa für die Anmeldung oder einen Warenkorb, setzen wir auf Grundlage von § 25 Abs. 2 Nr. 2 TDDDG und Art. 6 Abs. 1 lit. f DSGVO. Alle anderen Cookies und vergleichbaren Technologien, etwa für Statistik, Marketing oder externe Medien, nutzen wir nur mit Ihrer Einwilligung (§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO).', 'plakette' ) );
		if ( '' !== (string) $f['consent'] ) {
			$tool = 'Plakette' === $f['consent'] ? '' : ' (' . $f['consent'] . ')';
			$text = esc_html( sprintf( __( 'Ihre Einwilligung holen wir beim ersten Besuch über ein Cookie-Banner ein%s. Ihre Auswahl speichern wir in Ihrem Browser, damit wir nicht bei jedem Besuch erneut fragen müssen. Sie können Ihre Einwilligung jederzeit mit Wirkung für die Zukunft ändern oder widerrufen', 'plakette' ), $tool ) );
			$text .= 'Plakette' === $f['consent']
				? ': <a href="#plakette-consent">' . esc_html__( 'Cookie-Einstellungen öffnen', 'plakette' ) . '</a>.'
				: esc_html__( ', und zwar über die Cookie-Einstellungen.', 'plakette' );
			$out .= self::p( $text );
		} elseif ( ! $needs_consent ) {
			$out .= self::p( esc_html__( 'Diese Website setzt nur technisch notwendige Cookies.', 'plakette' ) );
		}

		if ( ! empty( $f['comments'] ) ) {
			$out .= self::h( __( 'Kommentare', 'plakette' ) );
			$ip   = Plakette_Modules::on( 'comment_ip' )
				? __( 'Ihre IP-Adresse speichern wir dabei nur gekürzt, sodass sie Ihnen nicht mehr zugeordnet werden kann.', 'plakette' )
				: __( 'Außerdem speichern wir Ihre IP-Adresse, um bei Missbrauch (z. B. beleidigenden Inhalten) handeln zu können.', 'plakette' );
			$out .= self::p( esc_html__( 'Wenn Sie einen Kommentar schreiben, speichern wir Ihren Namen, Ihre E-Mail-Adresse (sie wird nicht veröffentlicht), eine eventuell angegebene Website, den Kommentartext und den Zeitpunkt.', 'plakette' ) . ' ' . esc_html( $ip ) . ' ' . esc_html__( 'Rechtsgrundlage ist unser berechtigtes Interesse an der Darstellung der Kommentare und der Abwehr von Spam (Art. 6 Abs. 1 lit. f DSGVO). Kommentare bleiben gespeichert, bis Sie deren Löschung verlangen oder der Zweck entfällt.', 'plakette' ) );
			if ( Plakette_Modules::on( 'local_avatars' ) ) {
				$out .= self::p( esc_html__( 'Profilbilder zu Kommentaren erzeugen wir auf unserem eigenen Server; dabei werden keine Daten an externe Avatar-Dienste übertragen.', 'plakette' ) );
			}
		}

		$forms = (array) $f['forms'];
		$out  .= self::h( $forms ? __( 'Kontaktformular und Kontakt per E-Mail', 'plakette' ) : __( 'Kontakt per E-Mail oder Telefon', 'plakette' ) );
		$out  .= self::p( esc_html( $forms ? __( 'Wenn Sie uns über ein Formular auf dieser Website, per E-Mail oder telefonisch kontaktieren, verarbeiten wir Ihre Angaben (z. B. Name, E-Mail-Adresse, Nachricht), um Ihre Anfrage zu bearbeiten.', 'plakette' ) : __( 'Wenn Sie uns per E-Mail oder telefonisch kontaktieren, verarbeiten wir Ihre Angaben (z. B. Name, E-Mail-Adresse, Nachricht), um Ihre Anfrage zu bearbeiten.', 'plakette' ) ) . ' ' . esc_html__( 'Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO, sofern Ihre Anfrage mit einem Vertrag oder vorvertraglichen Maßnahmen zusammenhängt, ansonsten unser berechtigtes Interesse an der Beantwortung (Art. 6 Abs. 1 lit. f DSGVO). Wir löschen die Daten, sobald Ihre Anfrage erledigt ist und keine gesetzlichen Aufbewahrungspflichten entgegenstehen.', 'plakette' ) );

		if ( ! empty( $f['register'] ) || ! empty( $f['shop'] ) ) {
			$out .= self::h( __( 'Registrierung und Kundenkonto', 'plakette' ) );
			$out .= self::p( esc_html__( 'Sie können auf dieser Website ein Benutzerkonto anlegen. Dabei speichern wir die Daten, die Sie bei der Registrierung angeben (z. B. Benutzername, E-Mail-Adresse, Passwort in verschlüsselter Form), um Ihnen den Zugang zu Ihrem Konto zu ermöglichen (Art. 6 Abs. 1 lit. b DSGVO). Sie können Ihr Konto jederzeit löschen lassen.', 'plakette' ) );
		}
		if ( ! empty( $f['shop'] ) ) {
			$out .= self::h( __( 'Bestellungen und Buchungen', 'plakette' ) );
			$out .= self::p( esc_html__( 'Wenn Sie bei uns bestellen oder buchen, verarbeiten wir die dafür nötigen Daten (z. B. Name, Anschrift, E-Mail-Adresse, Bestell- und Zahlungsdaten), um den Vertrag zu erfüllen (Art. 6 Abs. 1 lit. b DSGVO). Zur Abwicklung geben wir Daten an Versand- und Zahlungsdienstleister weiter, soweit dies erforderlich ist. Rechnungs- und Buchungsdaten bewahren wir nach den handels- und steuerrechtlichen Fristen (bis zu zehn Jahre) auf (Art. 6 Abs. 1 lit. c DSGVO).', 'plakette' ) );
		}

		if ( $f['services'] ) {
			$out .= self::h( __( 'Eingebundene Dienste und Inhalte Dritter', 'plakette' ) );
			$out .= self::p( esc_html__( 'Auf dieser Website nutzen wir die folgenden Dienste anderer Anbieter. Dabei kann Ihre IP-Adresse an den jeweiligen Anbieter übertragen werden, weil dieser die Inhalte sonst nicht an Ihren Browser senden könnte.', 'plakette' ) );
			foreach ( $f['services'] as $s ) {
				$out .= self::service_blocks( $s );
			}
		}

		if ( Plakette_Modules::get( 'local_fonts' ) && ! isset( $f['services']['google-fonts'] ) ) {
			$out .= self::h( __( 'Schriftarten', 'plakette' ) );
			$out .= self::p( esc_html__( 'Die Schriftarten dieser Website sind lokal auf unserem Server gespeichert. Beim Aufruf der Seiten wird keine Verbindung zu Servern von Google oder anderen Schriftanbietern hergestellt.', 'plakette' ) );
		}
		if ( Plakette_Modules::on( 'embeds' ) ) {
			$out .= self::h( __( 'Eingebettete Inhalte mit Zwei-Klick-Lösung', 'plakette' ) );
			$out .= self::p( esc_html__( 'Videos, Karten und andere Inhalte fremder Anbieter laden wir erst, wenn Sie auf den jeweiligen Platzhalter klicken. Vorher werden keine Daten an den Anbieter übertragen. Mit dem Klick willigen Sie in die Übertragung ein (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).', 'plakette' ) );
		}

		$out .= self::h( __( 'Speicherdauer', 'plakette' ) );
		$out .= self::p( esc_html__( 'Soweit in dieser Datenschutzerklärung keine speziellere Speicherdauer genannt ist, verbleiben Ihre Daten bei uns, bis der Zweck der Verarbeitung entfällt oder Sie Ihre Einwilligung widerrufen. Gesetzliche Aufbewahrungsfristen (z. B. sechs bzw. zehn Jahre nach Handels- und Steuerrecht) bleiben unberührt; die Daten werden in diesem Fall gesperrt und nach Ablauf der Frist gelöscht.', 'plakette' ) );

		$out .= self::h( __( 'Ihre Rechte', 'plakette' ) );
		$out .= self::p( esc_html__( 'Sie haben gegenüber uns folgende Rechte hinsichtlich Ihrer personenbezogenen Daten:', 'plakette' ) );
		$out .= self::ul(
			array(
				esc_html__( 'Recht auf Auskunft (Art. 15 DSGVO)', 'plakette' ),
				esc_html__( 'Recht auf Berichtigung (Art. 16 DSGVO)', 'plakette' ),
				esc_html__( 'Recht auf Löschung (Art. 17 DSGVO)', 'plakette' ),
				esc_html__( 'Recht auf Einschränkung der Verarbeitung (Art. 18 DSGVO)', 'plakette' ),
				esc_html__( 'Recht auf Datenübertragbarkeit (Art. 20 DSGVO)', 'plakette' ),
				esc_html__( 'Recht auf Widerspruch gegen die Verarbeitung (Art. 21 DSGVO)', 'plakette' ),
				esc_html__( 'Recht, eine erteilte Einwilligung jederzeit mit Wirkung für die Zukunft zu widerrufen (Art. 7 Abs. 3 DSGVO)', 'plakette' ),
			)
		);
		$out .= self::p( '<strong>' . esc_html__( 'Widerspruchsrecht:', 'plakette' ) . '</strong> ' . esc_html__( 'Verarbeiten wir Ihre Daten auf Grundlage unseres berechtigten Interesses (Art. 6 Abs. 1 lit. f DSGVO), können Sie dieser Verarbeitung aus Gründen, die sich aus Ihrer besonderen Situation ergeben, jederzeit widersprechen (Art. 21 DSGVO). Wenden Sie sich dazu einfach an die oben genannte Kontaktadresse.', 'plakette' ) );

		$out .= self::h( __( 'Beschwerderecht bei einer Aufsichtsbehörde', 'plakette' ) );
		$text = esc_html__( 'Sie haben das Recht, sich bei einer Datenschutz-Aufsichtsbehörde zu beschweren (Art. 77 DSGVO), insbesondere in dem Mitgliedstaat Ihres gewöhnlichen Aufenthalts, Ihres Arbeitsplatzes oder des Orts des mutmaßlichen Verstoßes.', 'plakette' );
		$dpa  = in_array( self::v( $c, 'country' ), array( '', 'DE' ), true ) ? self::authority_for( self::v( $c, 'region' ) ) : '';
		if ( '' !== $dpa ) {
			$text .= ' ' . esc_html( sprintf( __( 'Für uns zuständig ist: %s.', 'plakette' ), $dpa ) );
		}
		$out .= self::p( $text );

		return $out . self::stand();
	}

	/* ------------------------------------------------------------------ Erklärung zur Barrierefreiheit */

	public static function bfsg( array $c, $system ) {
		$name = self::v( $c, 'name' );
		$out  = self::p( esc_html( sprintf( __( '%s möchte diese Website und die darüber angebotenen Dienstleistungen für alle Menschen zugänglich machen – auch für Menschen mit Behinderungen. Diese Erklärung beschreibt nach § 14 und Anlage 3 des Barrierefreiheitsstärkungsgesetzes (BFSG), wie wir die Anforderungen an die Barrierefreiheit erfüllen.', 'plakette' ), $name ) ) );

		$out .= self::h( __( 'Beschreibung der Dienstleistung', 'plakette' ) );
		$out .= self::p( esc_html__( 'Auf dieser Website können Sie sich über unser Angebot informieren und Waren oder Dienstleistungen online bestellen bzw. buchen. Die Bestellung oder Buchung erfolgt in mehreren Schritten: Auswahl, Eingabe Ihrer Kontakt- und Zahlungsdaten, Überprüfung und verbindlicher Abschluss. Die Website lässt sich mit allen gängigen Browsern auf Computer, Tablet und Smartphone nutzen.', 'plakette' ) );

		$out .= self::h( __( 'Wie wir die Barrierefreiheitsanforderungen erfüllen', 'plakette' ) );
		$out .= self::p( esc_html__( 'Wir orientieren uns an der europäischen Norm EN 301 549 und den Web Content Accessibility Guidelines (WCAG) 2.1 auf Stufe AA. Dazu gehören insbesondere:', 'plakette' ) );
		$out .= self::ul(
			array(
				esc_html__( 'Textalternativen für Bilder und Grafiken', 'plakette' ),
				esc_html__( 'Bedienbarkeit aller Funktionen mit der Tastatur und ein sichtbarer Fokus', 'plakette' ),
				esc_html__( 'ausreichende Farbkontraste und Text, der sich auf mindestens 200 % vergrößern lässt', 'plakette' ),
				esc_html__( 'beschriftete Formularfelder und verständliche Fehlermeldungen', 'plakette' ),
				esc_html__( 'eine klare Seitenstruktur mit Überschriften, Listen und korrekt ausgezeichneter Sprache', 'plakette' ),
			)
		);

		$out .= self::h( __( 'Stand der Vereinbarkeit', 'plakette' ) );
		$out .= self::p( esc_html__( 'Diese Website erfüllt die Anforderungen nach unserer eigenen Prüfung teilweise. Bekannte Barrieren beheben wir fortlaufend. Wenn Sie auf eine Barriere stoßen, helfen wir Ihnen gern auf anderem Weg weiter, zum Beispiel per E-Mail oder Telefon.', 'plakette' ) );

		$out .= self::h( __( 'Feedback und Kontakt', 'plakette' ) );
		$out .= self::p( esc_html__( 'Sind Ihnen Mängel bei der Barrierefreiheit aufgefallen, oder benötigen Sie Informationen in einer für Sie zugänglichen Form? Schreiben Sie uns:', 'plakette' ) );
		$contact = self::lines( self::address_lines( $c ) );
		if ( '' !== self::v( $c, 'phone' ) ) {
			$contact .= '<br>' . esc_html__( 'Telefon:', 'plakette' ) . ' ' . esc_html( self::v( $c, 'phone' ) );
		}
		$contact .= '<br>' . esc_html__( 'E-Mail:', 'plakette' ) . ' ' . self::mail_link( self::v( $c, 'email' ) );
		$out     .= self::p( $contact );

		$out .= self::h( __( 'Marktüberwachungsbehörde', 'plakette' ) );
		$out .= self::p( esc_html__( 'Wenn Sie der Ansicht sind, dass diese Website oder unsere Dienstleistung die Anforderungen des BFSG nicht erfüllt, können Sie sich an die zuständige Marktüberwachungsbehörde wenden:', 'plakette' ) );
		$out .= self::p( self::lines( array( 'Marktüberwachungsstelle der Länder für die Barrierefreiheit von Produkten und Dienstleistungen (MLBF)', 'Anstalt des öffentlichen Rechts', 'Magdeburg' ) ) );

		return $out . self::stand();
	}

	/* ------------------------------------------------------------------ editing existing content */

	/** Changes several fields of a post at once and records the old values. @return true|WP_Error */
	public static function update_post( $post_id, array $fields, array &$undo ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'plakette_post', __( 'Seite nicht gefunden.', 'plakette' ) );
		}
		foreach ( $fields as $field => $value ) {
			$undo[] = array( 'post_field', (int) $post_id, $field, $post->$field );
		}
		$res = wp_update_post( wp_slash( array_merge( array( 'ID' => (int) $post_id ), $fields ) ), true );
		return is_wp_error( $res ) ? $res : true;
	}

	/** Published pages plus customised template parts, templates and synced patterns (legal texts often live there). */
	public static function content_posts() {
		return get_posts(
			array(
				'post_type'              => array( 'page', 'wp_template_part', 'wp_template', 'wp_block' ),
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

	public static function item( WP_Post $post, $sub ) {
		$type = get_post_type_object( $post->post_type );
		return array(
			'id'    => (string) $post->ID,
			'label' => '' !== $post->post_title ? $post->post_title : sprintf( '#%d', $post->ID ),
			'sub'   => ( $type ? $type->labels->singular_name . ' · ' : '' ) . $sub,
			'link'  => 'page' === $post->post_type ? get_permalink( $post ) : (string) get_edit_post_link( $post->ID, 'raw' ),
		);
	}

	/** Removes links to the closed EU ODR platform: whole paragraphs in block content, otherwise only the link. */
	public static function remove_odr( $content ) {
		$odr = 'ec\.europa\.eu/consumers/odr';
		$new = preg_replace_callback(
			'#<!-- wp:paragraph(?:\s[^>]*)? -->.*?<!-- /wp:paragraph -->\s*#s',
			function ( $m ) use ( $odr ) {
				return preg_match( '#' . $odr . '|Plattform zur Online-Streitbeilegung|OS-Plattform#i', $m[0] ) ? '' : $m[0];
			},
			(string) $content
		);
		// A heading "EU-Streitschlichtung" without text below it is removed as well.
		$new = preg_replace( '#<!-- wp:heading(?:\s[^>]*)? -->\s*<h[1-6][^>]*>[^<]*(EU-Streitschlichtung|Online-Streitbeilegung|OS-Plattform)[^<]*</h[1-6]>\s*<!-- /wp:heading -->\s*(?=<!-- wp:heading|$)#iu', '', (string) $new );
		$new = preg_replace( '#<a\b[^>]*href\s*=\s*["\'][^"\']*' . $odr . '[^"\']*["\'][^>]*>(.*?)</a>#is', '$1', (string) $new );
		return (string) $new;
	}

	/** Outdated law names => current ones. Applied to text outside HTML tags only. */
	public static function law_rules() {
		$sp = '(?:\s|&nbsp;|\x{00A0})*';
		return array(
			'/§' . $sp . '5' . $sp . '(?:Abs\.?' . $sp . '\d+' . $sp . ')?TMG\b/u'                                     => '§ 5 DDG',
			'/§' . $sp . '55' . $sp . '(?:Abs\.?' . $sp . '2' . $sp . ')?(?:RStV|Rundfunkstaatsvertrag(?:es)?)\b/u' => '§ 18 Abs. 2 MStV',
			'/\bTelekommunikation-Telemedien-Datenschutz-Gesetz(es)?\b/u'                                               => 'Telekommunikation-Digitale-Dienste-Datenschutz-Gesetz$1',
			'/\bTelemediengesetz(es)?\b/u'                                                                             => 'Digitale-Dienste-Gesetz$1',
			'/\bRundfunkstaatsvertrag(es)?\b/u'                                                                        => 'Medienstaatsvertrag$1',
			'/\bTTDSG\b/u'                                                                                             => 'TDDDG',
			'/\bTMG\b/u'                                                                                               => 'DDG',
			'/\bRStV\b/u'                                                                                              => 'MStV',
		);
	}

	/** Terms found + modernised content. @return array [ string[] found, string new_content ] */
	public static function modernise( $content ) {
		$rules = self::law_rules();
		$found = array();
		$parts = preg_split( '#(<[^>]*>)#', (string) $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return array( array(), (string) $content );
		}
		foreach ( $parts as $i => $part ) {
			if ( 0 !== $i % 2 || '' === $part ) {
				continue;
			}
			foreach ( $rules as $re => $replacement ) {
				if ( preg_match_all( $re, $part, $m ) ) {
					foreach ( $m[0] as $hit ) {
						$found[] = trim( str_replace( array( '&nbsp;', "\xC2\xA0" ), ' ', $hit ) );
					}
					$part = preg_replace( $re, $replacement, $part );
				}
			}
			$parts[ $i ] = $part;
		}
		return array( array_values( array_unique( $found ) ), implode( '', $parts ) );
	}
}

/* ====================================================================== Recht & DSGVO */

class Plakette_Check_Legal_Imprint extends Plakette_Check {
	public $id        = 'legal_imprint';
	public $area      = 'security';
	public $group     = Plakette_Legal_Pages::LEGAL;
	public $weight    = 10;
	public $title     = 'Impressum vorhanden und verlinkt';
	public $problem   = 'Das Impressum fehlt';
	public $explain   = 'Wer geschäftsmäßig eine Website betreibt, muss nach § 5 DDG (früher TMG) ein Impressum haben: Name, Anschrift, schnelle Kontaktmöglichkeit und je nach Rechtsform Register und USt-ID. Es muss von jeder Seite aus mit einem Klick erreichbar sein. Ein fehlendes Impressum ist einer der häufigsten Abmahngründe.';
	public $fix_label = 'Impressum erstellen';
	public $fix_does  = 'Speichert deine Firmendaten, legt die Seite „Impressum“ mit den Pflichtangaben nach § 5 DDG an und verlinkt sie am Ende jeder Seite. Bitte lies die Seite danach einmal durch – sie ersetzt keine Rechtsberatung.';
	public $risk      = 'review';
	public $kind      = 'form';

	public function run( Plakette_Context $ctx ) {
		$page = Plakette_Legal::imprint_page();
		if ( ! $page ) {
			return $this->fail(
				__( 'Es gibt keine veröffentlichte Seite „Impressum“.', 'plakette' ),
				array(
					'form' => array_merge(
						Plakette_Settings::form( 'company', Plakette_Legal_Pages::IMPRINT_FIELDS ),
						array( 'intro' => __( 'Trag deine Angaben ein. Felder, die auf dich nicht zutreffen (z. B. Register, Berufsangaben, Aufsichtsbehörde), lässt du einfach leer. Plakette baut daraus ein Impressum nach § 5 DDG.', 'plakette' ) )
					),
				)
			);
		}
		$title = get_the_title( $page );
		if ( $ctx->home_ok() && ! Plakette_Legal::is_linked( Plakette_Legal::links( $ctx->html() ), $page, Plakette_Legal::IMPRINT_TEXT ) ) {
			return $this->fail(
				sprintf( __( 'Die Seite „%s“ gibt es, aber die Startseite verlinkt sie nicht.', 'plakette' ), $title ),
				array(
					'kind'      => 'auto',
					'risk'      => 'safe',
					'problem'   => __( 'Das Impressum ist nicht verlinkt', 'plakette' ),
					'fix_label' => __( 'Impressum verlinken', 'plakette' ),
					'fix_does'  => __( 'Trägt das Impressum ins Footer-Menü ein (falls dein Theme eins hat) oder zeigt am Ende jeder Seite eine kleine Linkzeile „Impressum · Datenschutz“. Links, die schon da sind, werden nicht doppelt gezeigt.', 'plakette' ),
				)
			);
		}
		$text  = Plakette_Legal_Pages::plain_text( get_post( $page )->post_content );
		$notes = array();
		if ( ! preg_match( '/[A-Z0-9._%+-]+(@|\s*[\[(]\s*(at|ät)\s*[\])]\s*)[A-Z0-9.-]+(\.|\s*[\[(]\s*(dot|punkt)\s*[\])]\s*)[A-Z]{2,}/i', $text ) ) {
			$notes[] = __( 'Keine E-Mail-Adresse gefunden – sie ist Pflicht (schnelle elektronische Kontaktaufnahme).', 'plakette' );
		}
		$country = (string) Plakette_Settings::get( 'company', 'country' );
		if ( in_array( $country, array( '', 'DE' ), true ) && ! preg_match( '/\b\d{5}\b/', $text ) ) {
			$notes[] = __( 'Keine fünfstellige Postleitzahl gefunden – es muss eine vollständige Anschrift (kein Postfach) dastehen.', 'plakette' );
		}
		if ( $notes ) {
			return $this->warn(
				sprintf( __( 'Im Impressum „%s“ fehlen vermutlich Pflichtangaben.', 'plakette' ), $title ),
				array(
					'kind'    => 'none',
					'problem' => __( 'Im Impressum fehlen Pflichtangaben', 'plakette' ),
					'notes'   => $notes,
					'links'   => array( array( 'label' => __( 'Impressum bearbeiten', 'plakette' ), 'url' => (string) get_edit_post_link( $page, 'raw' ) ) ),
				)
			);
		}
		return $this->pass( sprintf( __( 'Impressum: „%s“, von der Startseite aus verlinkt.', 'plakette' ), $title ), array( 'url' => get_permalink( $page ) ) );
	}

	public function fix( array $req, array &$undo ) {
		if ( Plakette_Legal::imprint_page() ) {
			return Plakette_Legal::enable_links( $undo );
		}
		$c = Plakette_Legal_Pages::save_company( $req, 'imprint', $undo );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$id = Plakette_Log::insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'Impressum', 'plakette' ),
				'post_name'      => 'impressum',
				'post_content'   => Plakette_Legal_Pages::imprint( $c ),
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			$undo
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Plakette_Log::set_option( Plakette_Legal::IMPRINT_OPTION, (int) $id, $undo );
		Plakette_Legal::enable_links( $undo );
		return __( 'Das Impressum ist angelegt und von jeder Seite aus verlinkt. Bitte lies es einmal durch.', 'plakette' );
	}
}

class Plakette_Check_Legal_Privacy_Policy extends Plakette_Check {
	public $id        = 'legal_privacy_policy';
	public $area      = 'privacy';
	public $group     = 'DSGVO & Cookies';
	public $weight    = 10;
	public $title     = 'Datenschutzerklärung vorhanden und verlinkt';
	public $problem   = 'Die Datenschutzerklärung fehlt';
	public $explain   = 'Jede Website verarbeitet personenbezogene Daten – schon die IP-Adresse beim Aufruf. Die DSGVO (Art. 13) verlangt deshalb eine Datenschutzerklärung, die erklärt, welche Daten wozu verarbeitet werden und welche Rechte Besucher haben. Sie muss von jeder Seite aus erreichbar sein.';
	public $fix_label = 'Datenschutzerklärung erstellen';
	public $fix_does  = 'Erstellt eine Datenschutzerklärung aus dem, was Plakette auf deiner Website erkannt hat (Hosting, Cookies, Kommentare, Formulare, eingebundene Dienste wie Google Fonts oder YouTube), setzt sie in WordPress als Datenschutzseite und verlinkt sie auf jeder Seite. Das ersetzt keine Rechtsberatung: Bitte lies den Text durch und ergänze, was Plakette nicht sehen kann (z. B. Newsletter-Versand, Bewerbungen, Social-Media-Auftritte).';
	public $risk      = 'review';
	public $kind      = 'auto';

	public function run( Plakette_Context $ctx ) {
		$page = Plakette_Legal::privacy_page();
		if ( $page ) {
			$title = get_the_title( $page );
			if ( $ctx->home_ok() && ! Plakette_Legal::is_linked( Plakette_Legal::links( $ctx->html() ), $page, Plakette_Legal::PRIVACY_TEXT ) ) {
				return $this->fail(
					sprintf( __( 'Die Seite „%s“ gibt es, aber die Startseite verlinkt sie nicht.', 'plakette' ), $title ),
					array(
						'risk'      => 'safe',
						'problem'   => __( 'Die Datenschutzerklärung ist nicht verlinkt', 'plakette' ),
						'fix_label' => __( 'Datenschutzerklärung verlinken', 'plakette' ),
						'fix_does'  => __( 'Trägt die Datenschutzerklärung ins Footer-Menü ein (falls dein Theme eins hat) oder zeigt am Ende jeder Seite eine kleine Linkzeile „Impressum · Datenschutz“. Links, die schon da sind, werden nicht doppelt gezeigt.', 'plakette' ),
					)
				);
			}
			return $this->pass( sprintf( __( 'Datenschutzerklärung: „%s“, von der Startseite aus verlinkt.', 'plakette' ), $title ), array( 'url' => get_permalink( $page ) ) );
		}
		$opt    = (int) get_option( 'wp_page_for_privacy_policy' );
		$post   = $opt ? get_post( $opt ) : null;
		$detail = __( 'Es gibt keine veröffentlichte Datenschutzerklärung.', 'plakette' );
		if ( $post && 'trash' !== $post->post_status ) {
			$detail = Plakette_Legal::is_sample_privacy( $post->post_content )
				? sprintf( __( 'Die Datenschutzseite „%s“ enthält nur den englischsprachigen bzw. allgemeinen Mustertext von WordPress.', 'plakette' ), get_the_title( $post ) )
				: sprintf( __( 'Die Datenschutzseite „%s“ ist nicht veröffentlicht.', 'plakette' ), get_the_title( $post ) );
		}
		$extra = array();
		if ( Plakette_Legal_Pages::missing( Plakette_Legal_Pages::company(), 'privacy' ) ) {
			$extra['kind'] = 'form';
			$extra['form'] = array_merge(
				Plakette_Settings::form( 'company', Plakette_Legal_Pages::PRIVACY_FIELDS ),
				array( 'intro' => __( 'Für die Datenschutzerklärung braucht Plakette den „Verantwortlichen“: wer du bist und wie man dich erreicht. Mit dem Bundesland kann Plakette auch die zuständige Datenschutzbehörde nennen.', 'plakette' ) )
			);
		}
		return $this->fail( $detail, $extra );
	}

	public function fix( array $req, array &$undo ) {
		if ( Plakette_Legal::privacy_page() ) {
			return Plakette_Legal::enable_links( $undo );
		}
		$c = Plakette_Legal_Pages::save_company( $req, 'privacy', $undo );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$facts   = Plakette_Legal_Pages::privacy_facts( new Plakette_Context() );
		$content = Plakette_Legal_Pages::privacy( $c, $facts );
		$fields  = array(
			'post_title'     => __( 'Datenschutzerklärung', 'plakette' ),
			'post_content'   => $content,
			'post_status'    => 'publish',
			'post_name'      => 'datenschutz',
			'comment_status' => 'closed',
		);
		// WordPress's own privacy page with the untouched sample text is reused, everything else stays as it is.
		$opt  = (int) get_option( 'wp_page_for_privacy_policy' );
		$post = $opt ? get_post( $opt ) : null;
		if ( $post && 'page' === $post->post_type && 'trash' !== $post->post_status && Plakette_Legal::is_sample_privacy( $post->post_content ) ) {
			$res = Plakette_Legal_Pages::update_post( $opt, $fields, $undo );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$id = $opt;
		} else {
			$id = Plakette_Log::insert_post( array_merge( $fields, array( 'post_type' => 'page', 'ping_status' => 'closed' ) ), $undo );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
		}
		Plakette_Log::set_option( 'wp_page_for_privacy_policy', (int) $id, $undo );
		Plakette_Legal::enable_links( $undo );
		$n = count( $facts['services'] );
		return sprintf(
			/* translators: %d: number of services */
			_n( 'Die Datenschutzerklärung ist veröffentlicht und verlinkt (%d eingebundener Dienst berücksichtigt). Bitte lies sie durch – sie ersetzt keine Rechtsberatung.', 'Die Datenschutzerklärung ist veröffentlicht und verlinkt (%d eingebundene Dienste berücksichtigt). Bitte lies sie durch – sie ersetzt keine Rechtsberatung.', $n, 'plakette' ),
			$n
		);
	}
}

class Plakette_Check_Legal_Privacy_Coverage extends Plakette_Check {
	public $id        = 'legal_privacy_coverage';
	public $area      = 'privacy';
	public $group     = 'DSGVO & Cookies';
	public $weight    = 6;
	public $title     = 'Alle eingebundenen Dienste stehen in der Datenschutzerklärung';
	public $problem   = 'Die Datenschutzerklärung erwähnt nicht alle Dienste';
	public $explain   = 'Jeder fremde Dienst, der Daten deiner Besucher bekommt (z. B. Google Fonts, YouTube, Analytics), muss in der Datenschutzerklärung mit Anbieter, Zweck und Rechtsgrundlage stehen. Fehlt einer, ist die Erklärung unvollständig – auch wenn der Dienst erst nach Einwilligung lädt.';
	public $fix_label = 'Abschnitte ergänzen';
	public $fix_does  = 'Fügt für jeden ausgewählten Dienst einen kurzen Abschnitt (Überschrift, Beschreibung, Anbieter) in deine Datenschutzerklärung ein – vor der Zeile „Stand“ bzw. am Ende. Der übrige Text bleibt unverändert.';
	public $risk      = 'review';
	public $kind      = 'items';

	public function run( Plakette_Context $ctx ) {
		$page = Plakette_Legal::privacy_page();
		if ( ! $page ) {
			return $this->skip( __( 'Wird geprüft, sobald es eine Datenschutzerklärung gibt.', 'plakette' ) );
		}
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$text     = Plakette_Legal_Pages::plain_text( get_post( $page )->post_content );
		$services = Plakette_Legal_Pages::services( $ctx );
		$items    = array();
		foreach ( $services as $id => $s ) {
			if ( ! Plakette_Legal_Pages::mentioned( $text, $s ) ) {
				$items[] = array(
					'id'    => $id,
					'label' => $s['name'],
					'sub'   => Plakette_Services::category_label( $s['category'] ) . ' · ' . implode( ', ', array_slice( $s['where'], 0, 3 ) ),
				);
			}
		}
		if ( $items ) {
			return $this->fail(
				sprintf( _n( '%d Dienst fehlt in der Datenschutzerklärung.', '%d Dienste fehlen in der Datenschutzerklärung.', count( $items ), 'plakette' ), count( $items ) ),
				array( 'items' => $items )
			);
		}
		return $this->pass(
			$services
				? sprintf( _n( 'Der erkannte Dienst wird erwähnt.', 'Alle %d erkannten Dienste werden erwähnt.', count( $services ), 'plakette' ), count( $services ) )
				: __( 'Plakette hat keine fremden Dienste gefunden.', 'plakette' )
		);
	}

	public function fix( array $req, array &$undo ) {
		$page = Plakette_Legal::privacy_page();
		if ( ! $page ) {
			return new WP_Error( 'plakette_privacy', __( 'Es gibt keine Datenschutzerklärung.', 'plakette' ) );
		}
		$blocks = '';
		$names  = array();
		foreach ( (array) $req['items'] as $item ) {
			$s = Plakette_Services::get( isset( $item['id'] ) ? (string) $item['id'] : '' );
			if ( $s ) {
				$blocks .= Plakette_Legal_Pages::service_blocks( $s );
				$names[] = $s['name'];
			}
		}
		if ( '' === $blocks ) {
			return new WP_Error( 'plakette_empty', __( 'Bitte wähle mindestens einen Dienst aus.', 'plakette' ) );
		}
		$content = (string) get_post( $page )->post_content;
		// In a policy Plakette wrote: before "Speicherdauer"; otherwise before the "Stand" line, otherwise at the end.
		$pos = false;
		foreach ( array( '#<!-- wp:heading(?:\s[^>]*)? -->\s*<h2[^>]*>\s*Speicherdauer#i', '#<!-- wp:paragraph(?:\s[^>]*)? -->\s*<p[^>]*>\s*Stand:#i', '#<p[^>]*>\s*Stand:#i' ) as $re ) {
			if ( preg_match( $re, $content, $m, PREG_OFFSET_CAPTURE ) ) {
				$pos = $m[0][1];
				break;
			}
		}
		$content = false === $pos ? rtrim( $content ) . "\n\n" . $blocks : substr( $content, 0, $pos ) . $blocks . substr( $content, $pos );
		$res     = Plakette_Log::set_post_field( $page, 'post_content', $content, $undo );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return sprintf( __( 'Ergänzt: %s. Bitte prüfe die neuen Abschnitte.', 'plakette' ), implode( ', ', $names ) );
	}
}

class Plakette_Check_Legal_Links extends Plakette_Check {
	public $id        = 'legal_links';
	public $area      = 'security';
	public $group     = Plakette_Legal_Pages::LEGAL;
	public $weight    = 8;
	public $title     = 'Impressum und Datenschutz auf jeder Seite verlinkt';
	public $problem   = 'Rechtstexte sind nicht von jeder Seite aus erreichbar';
	public $explain   = 'Impressum und Datenschutzerklärung müssen „leicht erkennbar, unmittelbar erreichbar und ständig verfügbar“ sein – also von jeder Unterseite aus mit einem Klick, üblicherweise im Footer. Ein Link nur auf der Startseite reicht nicht.';
	public $fix_label = 'Links auf allen Seiten zeigen';
	public $fix_does  = 'Trägt die Rechtstexte ins Footer-Menü ein (falls dein Theme eins hat) und zeigt sonst am Ende jeder Seite eine kleine, unauffällige Linkzeile in den Farben deines Themes. Links, die eine Seite schon hat, werden nicht doppelt gezeigt.';

	public function run( Plakette_Context $ctx ) {
		$pages = Plakette_Legal::legal_pages();
		if ( ! $pages ) {
			return $this->skip( __( 'Es gibt noch kein Impressum und keine Datenschutzerklärung – siehe die Punkte darüber.', 'plakette' ) );
		}
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$all     = $ctx->all_html();
		$missing = array();
		foreach ( $all as $id => $html ) {
			$links = Plakette_Legal::links( $html );
			foreach ( $pages as $key => $page ) {
				if ( ! Plakette_Legal::is_linked( $links, $page['id'], $page['re'] ) ) {
					$missing[ $page['label'] ][] = 0 === $id ? __( 'Startseite', 'plakette' ) : '„' . get_the_title( $id ) . '“';
				}
			}
		}
		if ( $missing ) {
			$parts = array();
			$notes = array();
			foreach ( $missing as $label => $where ) {
				$parts[] = sprintf( __( '%1$s fehlt auf %2$d von %3$d geprüften Seiten', 'plakette' ), $label, count( $where ), count( $all ) );
				$notes[] = $label . ': ' . implode( ', ', $where );
			}
			return $this->fail( implode( '; ', $parts ) . '.', array( 'notes' => $notes ) );
		}
		$labels = wp_list_pluck( $pages, 'label' );
		return $this->pass( sprintf( __( '%1$s auf allen %2$d geprüften Seiten verlinkt.', 'plakette' ), implode( ', ', $labels ), count( $all ) ) );
	}

	public function fix( array $req, array &$undo ) {
		return Plakette_Legal::enable_links( $undo );
	}
}

class Plakette_Check_Legal_Odr extends Plakette_Check {
	public $id        = 'legal_odr';
	public $area      = 'security';
	public $group     = Plakette_Legal_Pages::LEGAL;
	public $weight    = 3;
	public $title     = 'Kein Link auf die abgeschaltete EU-Streitschlichtungsplattform';
	public $problem   = 'Veralteter Link auf die EU-Streitschlichtung (OS-Plattform)';
	public $explain   = 'Früher mussten Online-Händler auf die EU-Plattform zur Online-Streitbeilegung verlinken. Die Plattform wurde am 20. Juli 2025 abgeschaltet und die Pflicht abgeschafft (Verordnung (EU) 2024/3228). Der Link führt ins Leere und wirkt veraltet – ein Hinweis, dass die Rechtstexte länger nicht gepflegt wurden.';
	public $fix_label = 'Hinweis entfernen';
	public $fix_does  = 'Entfernt in den ausgewählten Seiten den Absatz mit dem Link zur OS-Plattform (bei Block-Inhalten) bzw. den Link selbst. Der Hinweis zur Verbraucherschlichtung bleibt erhalten.';
	public $risk      = 'review';
	public $kind      = 'items';

	public function run( Plakette_Context $ctx ) {
		$items = array();
		foreach ( Plakette_Legal_Pages::content_posts() as $post ) {
			if ( false !== stripos( $post->post_content, 'ec.europa.eu/consumers/odr' ) && Plakette_Legal_Pages::remove_odr( $post->post_content ) !== $post->post_content ) {
				$items[] = Plakette_Legal_Pages::item( $post, __( 'verlinkt ec.europa.eu/consumers/odr', 'plakette' ) );
			}
		}
		if ( $items ) {
			return $this->warn( sprintf( _n( '%d Seite verlinkt die OS-Plattform – veraltet seit 20.07.2025.', '%d Seiten verlinken die OS-Plattform – veraltet seit 20.07.2025.', count( $items ), 'plakette' ), count( $items ) ), array( 'items' => $items ) );
		}
		foreach ( $ctx->all_html() as $html ) {
			if ( preg_match( '#href\s*=\s*["\'][^"\']*ec\.europa\.eu/consumers/odr#i', $html ) ) {
				return $this->warn(
					__( 'Im Footer, einem Widget oder im Theme steht noch ein Link zur OS-Plattform – veraltet seit 20.07.2025.', 'plakette' ),
					array(
						'kind'  => 'none',
						'notes' => array( __( 'Plakette findet den Link nicht in deinen Seiten. Entferne ihn dort, wo dein Footer gepflegt wird (Design › Widgets, Website-Editor oder Page-Builder).', 'plakette' ) ),
					)
				);
			}
		}
		return $this->pass( __( 'Kein Link auf die abgeschaltete OS-Plattform gefunden.', 'plakette' ) );
	}

	public function fix( array $req, array &$undo ) {
		$done = 0;
		foreach ( (array) $req['items'] as $item ) {
			$post = get_post( isset( $item['id'] ) ? (int) $item['id'] : 0 );
			if ( ! $post ) {
				continue;
			}
			$new = Plakette_Legal_Pages::remove_odr( $post->post_content );
			if ( $new !== $post->post_content ) {
				$res = Plakette_Log::set_post_field( $post->ID, 'post_content', $new, $undo );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				++$done;
			}
		}
		return sprintf( _n( 'Hinweis auf %d Seite entfernt.', 'Hinweis auf %d Seiten entfernt.', $done, 'plakette' ), $done );
	}
}

class Plakette_Check_Legal_Outdated_Law extends Plakette_Check {
	public $id        = 'legal_outdated_law';
	public $area      = 'security';
	public $group     = Plakette_Legal_Pages::LEGAL;
	public $weight    = 3;
	public $title     = 'Rechtstexte nennen die aktuellen Gesetze';
	public $problem   = 'Rechtstexte nennen veraltete Gesetze';
	public $explain   = 'Das Telemediengesetz (TMG) wurde am 14. Mai 2024 durch das Digitale-Dienste-Gesetz (DDG) ersetzt, der Rundfunkstaatsvertrag (RStV) durch den Medienstaatsvertrag (MStV) und das TTDSG heißt jetzt TDDDG. Steht im Impressum noch „§ 5 TMG“, ist das zwar kein großer Fehler, zeigt aber, dass der Text veraltet ist.';
	public $fix_label = 'Gesetzesnamen aktualisieren';
	public $fix_does  = 'Ersetzt in den ausgewählten Seiten „§ 5 TMG“ durch „§ 5 DDG“, „§ 55 Abs. 2 RStV“ durch „§ 18 Abs. 2 MStV“, „Telemediengesetz“ durch „Digitale-Dienste-Gesetz“ und „TTDSG“ durch „TDDDG“. Nur der sichtbare Text wird geändert, Links bleiben unberührt.';
	public $risk      = 'review';
	public $kind      = 'items';

	public function run( Plakette_Context $ctx ) {
		$items = array();
		foreach ( Plakette_Legal_Pages::content_posts() as $post ) {
			if ( ! preg_match( '/TMG|RStV|TTDSG|Telemediengesetz|Rundfunkstaatsvertrag|Telekommunikation-Telemedien/u', $post->post_content ) ) {
				continue;
			}
			list( $found, $new ) = Plakette_Legal_Pages::modernise( $post->post_content );
			if ( $found && $new !== $post->post_content ) {
				$items[] = Plakette_Legal_Pages::item( $post, implode( ', ', array_slice( $found, 0, 4 ) ) );
			}
		}
		if ( $items ) {
			return $this->warn( sprintf( _n( '%d Seite nennt veraltete Gesetze.', '%d Seiten nennen veraltete Gesetze.', count( $items ), 'plakette' ), count( $items ) ), array( 'items' => $items ) );
		}
		return $this->pass( __( 'Keine veralteten Gesetzesnamen gefunden.', 'plakette' ) );
	}

	public function fix( array $req, array &$undo ) {
		$done = 0;
		foreach ( (array) $req['items'] as $item ) {
			$post = get_post( isset( $item['id'] ) ? (int) $item['id'] : 0 );
			if ( ! $post ) {
				continue;
			}
			list( $found, $new ) = Plakette_Legal_Pages::modernise( $post->post_content );
			if ( $found && $new !== $post->post_content ) {
				$res = Plakette_Log::set_post_field( $post->ID, 'post_content', $new, $undo );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				++$done;
			}
		}
		return sprintf( _n( '%d Seite aktualisiert.', '%d Seiten aktualisiert.', $done, 'plakette' ), $done );
	}
}

class Plakette_Check_Legal_Shop_Button extends Plakette_Check {
	public $id        = 'legal_shop_button';
	public $area      = 'security';
	public $group     = Plakette_Legal_Pages::LEGAL;
	public $weight    = 7;
	public $title     = 'Bestellknopf „Zahlungspflichtig bestellen“';
	public $problem   = 'Der Bestellknopf nennt die Zahlungspflicht nicht';
	public $explain   = 'Nach § 312j Abs. 3 BGB muss der letzte Knopf im Bestellvorgang eindeutig sagen, dass die Bestellung etwas kostet – z. B. „zahlungspflichtig bestellen“. Steht dort nur „Bestellen“ oder „Weiter“, kommt rechtlich kein Vertrag zustande, und Abmahnungen drohen.';
	public $fix_label = 'Knopftext ändern';
	public $fix_does  = 'Ändert die Beschriftung des Bestellknopfs in WooCommerce (klassische Kasse und Block-Kasse) auf „Zahlungspflichtig bestellen“.';

	public function run( Plakette_Context $ctx ) {
		if ( ! Plakette_Legal::woocommerce() ) {
			return $this->skip( __( 'Kein Shop', 'plakette' ) );
		}
		$text = (string) apply_filters( 'woocommerce_order_button_text', __( 'Place order', 'woocommerce' ) ); // phpcs:ignore WordPress.WP.I18n
		if ( Plakette_Modules::on( 'shop_button' ) || Plakette_Legal::clear_button_text( $text ) ) {
			return $this->pass( sprintf( __( 'Knopftext: „%s“', 'plakette' ), Plakette_Modules::on( 'shop_button' ) ? __( 'Zahlungspflichtig bestellen', 'plakette' ) : $text ) );
		}
		return $this->fail( sprintf( __( 'Der Knopf heißt „%s“.', 'plakette' ), $text ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'shop_button', true, $undo );
		return __( 'Der Bestellknopf heißt jetzt „Zahlungspflichtig bestellen“.', 'plakette' );
	}
}

class Plakette_Check_Legal_Shop_Pages extends Plakette_Check {
	public $id        = 'legal_shop_pages';
	public $area      = 'security';
	public $group     = Plakette_Legal_Pages::LEGAL;
	public $weight    = 8;
	public $title     = 'AGB, Widerrufsbelehrung und Versandinfos vorhanden';
	public $problem   = 'Pflichtseiten für den Shop fehlen';
	public $explain   = 'Ein Online-Shop braucht neben Impressum und Datenschutz eine Widerrufsbelehrung (mit Muster-Widerrufsformular), Informationen zu Versand und Zahlung und in der Regel AGB. Diese Texte hängen stark von deinem Angebot ab – nutze dafür einen Rechtstexte-Dienst, der sie bei Gesetzesänderungen aktualisiert.';
	public $kind      = 'none';

	public function run( Plakette_Context $ctx ) {
		if ( ! Plakette_Legal::woocommerce() ) {
			return $this->skip( __( 'Kein Shop', 'plakette' ) );
		}
		if ( class_exists( 'WooCommerce_Germanized', false ) || class_exists( 'Woocommerce_German_Market', false ) || defined( 'WC_GERMANIZED_VERSION' ) ) {
			return $this->pass( __( 'Germanized bzw. German Market kümmert sich um die Pflichtseiten.', 'plakette' ) );
		}
		$missing = array();
		if ( ! Plakette_Legal::published_page( (int) get_option( 'woocommerce_terms_page_id' ) ) && ! Plakette_Legal::find_page( array( 'agb' ), '/\bAGB\b|Geschäftsbedingungen|terms/iu' ) ) {
			$missing[] = __( 'AGB (in WooCommerce unter Einstellungen › Erweitert als Seite „Allgemeine Geschäftsbedingungen“ wählen)', 'plakette' );
		}
		if ( ! Plakette_Legal::find_page( array( 'widerruf', 'widerrufsbelehrung', 'widerrufsrecht' ), '/widerruf/iu' ) ) {
			$missing[] = __( 'Widerrufsbelehrung mit Muster-Widerrufsformular', 'plakette' );
		}
		if ( ! Plakette_Legal::find_page( array( 'versand', 'zahlung', 'versand-und-zahlung', 'zahlung-und-versand', 'lieferung' ), '/versand|zahlung|lieferung/iu' ) ) {
			$missing[] = __( 'Versand- und Zahlungsinformationen (Lieferzeiten, Versandkosten, Zahlungsarten)', 'plakette' );
		}
		if ( ! $missing ) {
			return $this->pass( __( 'AGB, Widerrufsbelehrung und Versandinformationen sind vorhanden.', 'plakette' ) );
		}
		return $this->fail(
			sprintf( _n( '%d Pflichtseite fehlt.', '%d Pflichtseiten fehlen.', count( $missing ), 'plakette' ), count( $missing ) ),
			array(
				'notes' => $missing,
				'links' => array(
					array( 'label' => 'IT-Recht Kanzlei', 'url' => 'https://www.it-recht-kanzlei.de/' ),
					array( 'label' => 'Händlerbund', 'url' => 'https://www.haendlerbund.de/' ),
					array( 'label' => 'Trusted Shops', 'url' => 'https://www.trustedshops.de/' ),
				),
			)
		);
	}
}

/* ====================================================================== Barrierefreiheit (BFSG) */

class Plakette_Check_Legal_Bfsg_Statement extends Plakette_Check {
	public $id        = 'legal_bfsg_statement';
	public $area      = 'security';
	public $group     = Plakette_Legal_Pages::BFSG;
	public $weight    = 5;
	public $title     = 'Erklärung zur Barrierefreiheit vorhanden';
	public $problem   = 'Die Erklärung zur Barrierefreiheit fehlt';
	public $explain   = 'Seit dem 28. Juni 2025 gilt das Barrierefreiheitsstärkungsgesetz (BFSG) für Online-Shops und Buchungsdienste, die sich an Verbraucher richten. Sie müssen in einer Erklärung beschreiben, wie ihr Angebot barrierefrei ist, und eine Kontaktmöglichkeit sowie die Marktüberwachungsbehörde nennen. Ausgenommen sind Kleinstunternehmen mit weniger als 10 Beschäftigten und höchstens 2 Mio. € Jahresumsatz, die Dienstleistungen anbieten.';
	public $fix_label = 'Erklärung erstellen';
	public $fix_does  = 'Legt die Seite „Erklärung zur Barrierefreiheit“ mit einer Vorlage nach § 14 BFSG an (Beschreibung des Angebots, umgesetzte Anforderungen, Kontakt, Marktüberwachungsbehörde MLBF) und verlinkt sie am Ende jeder Seite. Bitte passe die Vorlage an dein Angebot an.';
	public $risk      = 'review';

	public function run( Plakette_Context $ctx ) {
		$system = Plakette_Legal::shop_system();
		if ( '' === $system ) {
			return $this->skip( __( 'Nur für Online-Shops und Buchungsdienste Pflicht.', 'plakette' ) );
		}
		$page = Plakette_Legal::bfsg_page();
		if ( ! $page ) {
			$extra = array();
			if ( Plakette_Legal_Pages::missing( Plakette_Legal_Pages::company(), 'bfsg' ) ) {
				$extra['kind'] = 'form';
				$extra['form'] = Plakette_Settings::form( 'company', array( 'name', 'street', 'zip', 'city', 'phone', 'email' ) );
			}
			return $this->fail( sprintf( __( '%s ist aktiv, aber es gibt keine Erklärung zur Barrierefreiheit.', 'plakette' ), $system ), $extra );
		}
		if ( $ctx->home_ok() && ! Plakette_Legal::is_linked( Plakette_Legal::links( $ctx->html() ), $page, Plakette_Legal::BFSG_TEXT ) ) {
			return $this->fail(
				sprintf( __( 'Die Seite „%s“ gibt es, aber die Startseite verlinkt sie nicht.', 'plakette' ), get_the_title( $page ) ),
				array(
					'risk'      => 'safe',
					'fix_label' => __( 'Erklärung verlinken', 'plakette' ),
					'fix_does'  => __( 'Zeigt die Erklärung zur Barrierefreiheit zusammen mit Impressum und Datenschutz am Ende jeder Seite (oder trägt sie ins Footer-Menü ein).', 'plakette' ),
				)
			);
		}
		return $this->pass( sprintf( __( 'Erklärung: „%s“, verlinkt.', 'plakette' ), get_the_title( $page ) ) );
	}

	public function fix( array $req, array &$undo ) {
		if ( ! Plakette_Legal::bfsg_page() ) {
			$c = Plakette_Legal_Pages::save_company( $req, 'bfsg', $undo );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			$id = Plakette_Log::insert_post(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_title'     => __( 'Erklärung zur Barrierefreiheit', 'plakette' ),
					'post_name'      => 'barrierefreiheit',
					'post_content'   => Plakette_Legal_Pages::bfsg( $c, Plakette_Legal::shop_system() ),
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				),
				$undo
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
		}
		Plakette_Legal::enable_links( $undo );
		return __( 'Die Erklärung zur Barrierefreiheit ist angelegt und verlinkt. Bitte passe sie an dein Angebot an.', 'plakette' );
	}
}

class Plakette_Check_A11y_Lang extends Plakette_Check {
	public $id        = 'a11y_lang';
	public $area      = 'security';
	public $group     = Plakette_Legal_Pages::BFSG;
	public $weight    = 5;
	public $title     = 'Die Sprache der Seiten ist angegeben';
	public $problem   = 'Die Sprache der Seiten fehlt oder ist falsch';
	public $explain   = 'Im HTML steht, in welcher Sprache eine Seite geschrieben ist (lang="de-DE"). Screenreader wählen damit die richtige Aussprache – fehlt die Angabe oder steht dort Englisch, wird ein deutscher Text mit englischer Stimme vorgelesen und ist kaum zu verstehen. Auch Übersetzungsfunktionen und Google nutzen die Angabe.';
	public $fix_label = 'Sprache eintragen';
	public $fix_does  = 'Setzt beim Ausliefern jeder Seite die richtige Sprachangabe in das <html>-Element.';

	/** Language the pages should declare: the module value, else German if the content is German, else the WordPress setting. */
	public static function expected( $html ) {
		$set = Plakette_Modules::get( 'a11y_lang' );
		if ( is_string( $set ) && '' !== $set ) {
			return $set;
		}
		$wp = get_bloginfo( 'language' );
		if ( 'de' !== Plakette_Legal::primary_lang( $wp ) && ! Plakette_Legal::multilingual() && self::looks_german( $html ) ) {
			return 'de-DE';
		}
		return $wp;
	}

	public static function looks_german( $html ) {
		$text = strtolower( wp_strip_all_tags( (string) preg_replace( '#<(script|style|head)\b.*?</\1>#is', ' ', (string) $html ) ) );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$words = array_count_values( preg_split( '/[^a-zäöüß]+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
		$count = function ( array $list ) use ( $words ) {
			$n = 0;
			foreach ( $list as $w ) {
				$n += isset( $words[ $w ] ) ? $words[ $w ] : 0;
			}
			return $n;
		};
		$de = $count( array( 'und', 'der', 'die', 'das', 'nicht', 'mit', 'für', 'ist', 'wir', 'sie', 'ein', 'eine', 'auf', 'zu', 'den', 'von', 'ihre', 'unsere', 'oder', 'auch', 'aus', 'bei', 'sich', 'dem', 'im' ) );
		$en = $count( array( 'the', 'and', 'with', 'for', 'is', 'we', 'you', 'your', 'our', 'of', 'to', 'this', 'that', 'are', 'not', 'or', 'on', 'it', 'be', 'from' ) );
		return $de >= 5 && $de > 2 * $en;
	}

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$want  = self::expected( implode( "\n", $ctx->all_html() ) );
		$pages = array( 0 => $ctx->facts() ) + $ctx->samples();
		$bad   = array();
		$found = '';
		foreach ( $pages as $id => $f ) {
			$lang = isset( $f['lang'] ) ? (string) $f['lang'] : '';
			if ( '' !== Plakette_Legal::lang_problem( $lang, $want ) ) {
				$bad[] = 0 === $id ? __( 'Startseite', 'plakette' ) : '„' . get_the_title( $id ) . '“';
				$found = '' === $found ? $lang : $found;
			}
		}
		if ( ! $bad ) {
			return $this->pass( sprintf( __( 'Sprache: %s', 'plakette' ), (string) $ctx->facts()['lang'] ) );
		}
		$detail = '' === $found
			? sprintf( __( 'Im <html>-Element fehlt die Sprachangabe (lang) – richtig wäre „%s“.', 'plakette' ), $want )
			: sprintf( __( 'Die Seiten sind als „%1$s“ ausgezeichnet, der Inhalt ist aber „%2$s“.', 'plakette' ), $found, $want );
		$notes = array( sprintf( __( 'Betroffen: %s', 'plakette' ), implode( ', ', $bad ) ) );
		if ( Plakette_Legal::primary_lang( $want ) !== Plakette_Legal::primary_lang( get_bloginfo( 'language' ) ) ) {
			$notes[] = __( 'Deine Inhalte sind deutsch, WordPress ist aber auf eine andere Sprache eingestellt (Einstellungen › Allgemein › Sprache der Website). Plakette setzt deshalb „de-DE“.', 'plakette' );
		}
		return $this->fail( $detail, array( 'notes' => $notes ) );
	}

	public function fix( array $req, array &$undo ) {
		$ctx  = new Plakette_Context();
		$want = self::expected( $ctx->home_ok() ? implode( "\n", $ctx->all_html() ) : '' );
		Plakette_Log::set_module( 'a11y_lang', $want === get_bloginfo( 'language' ) ? true : $want, $undo );
		return sprintf( __( 'Die Seiten tragen jetzt die Sprachangabe „%s“.', 'plakette' ), $want );
	}
}

class Plakette_Check_A11y_Zoom extends Plakette_Check {
	public $id        = 'a11y_zoom';
	public $area      = 'security';
	public $group     = Plakette_Legal_Pages::BFSG;
	public $weight    = 6;
	public $title     = 'Besucher können die Seite vergrößern';
	public $problem   = 'Vergrößern (Zoomen) ist auf dem Handy gesperrt';
	public $explain   = 'Dein Theme verbietet Smartphones das Vergrößern mit zwei Fingern (user-scalable=no bzw. maximum-scale=1). Menschen mit Sehschwäche können kleine Schrift dann nicht lesen. Die Barrierefreiheitsregeln (WCAG 1.4.4) verlangen, dass Text auf 200 % vergrößert werden kann.';
	public $fix_label = 'Zoomen erlauben';
	public $fix_does  = 'Ändert die Viewport-Angabe beim Ausliefern auf „width=device-width, initial-scale=1“. Das Layout bleibt gleich, nur die Zoom-Sperre fällt weg.';

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$bad = array();
		foreach ( $ctx->all_html() as $html ) {
			foreach ( Plakette_Legal::viewports( $html ) as $v ) {
				if ( Plakette_Legal::zoom_blocked( $v ) ) {
					$bad[ $v ] = true;
				}
			}
		}
		if ( $bad ) {
			return $this->fail( sprintf( __( 'Viewport: „%s“', 'plakette' ), implode( '“, „', array_keys( $bad ) ) ) );
		}
		return $this->pass( __( 'Zoomen ist erlaubt.', 'plakette' ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'a11y_zoom', true, $undo );
		return __( 'Besucher können die Seiten jetzt vergrößern.', 'plakette' );
	}
}

class Plakette_Check_A11y_Form_Labels extends Plakette_Check {
	public $id        = 'a11y_form_labels';
	public $area      = 'security';
	public $group     = Plakette_Legal_Pages::BFSG;
	public $weight    = 4;
	public $title     = 'Alle Formularfelder sind beschriftet';
	public $problem   = 'Formularfelder ohne Beschriftung';
	public $explain   = 'Ein Screenreader liest bei einem Eingabefeld dessen Beschriftung vor („E-Mail-Adresse“). Fehlt sie, hört man nur „Eingabefeld“ und weiß nicht, was einzutragen ist. Ein grauer Platzhaltertext im Feld zählt nicht als Beschriftung – er verschwindet beim Tippen und wird nicht überall vorgelesen.';
	public $fix_label = 'Beschriftungen ergänzen';
	public $fix_does  = 'Gibt Feldern ohne Beschriftung beim Ausliefern eine unsichtbare Beschriftung für Screenreader (aria-label) – aus dem Platzhaltertext oder dem Feldnamen. Das Aussehen ändert sich nicht.';

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$fixable = array();
		$rest    = array();
		foreach ( $ctx->all_html() as $id => $html ) {
			foreach ( Plakette_Legal::unlabeled_fields( $html ) as $f ) {
				$desc = '<' . ( in_array( $f['kind'], array( 'select', 'textarea' ), true ) ? $f['kind'] : 'input type="' . $f['kind'] . '"' ) . ( '' !== $f['name'] ? ' name="' . $f['name'] . '"' : '' ) . '>';
				$desc .= ' – ' . ( 0 === $id ? __( 'Startseite', 'plakette' ) : '„' . get_the_title( $id ) . '“' );
				if ( '' !== $f['label'] ) {
					$fixable[ $desc ] = $f['label'];
				} else {
					$rest[ $desc ] = true;
				}
			}
		}
		if ( ! $fixable && ! $rest ) {
			return $this->pass( __( 'Alle Eingabefelder auf den geprüften Seiten haben eine Beschriftung.', 'plakette' ) );
		}
		$notes = array();
		foreach ( array_slice( $fixable, 0, 8, true ) as $desc => $label ) {
			$notes[] = sprintf( __( '%1$s → wird „%2$s“', 'plakette' ), $desc, $label );
		}
		foreach ( array_slice( array_keys( $rest ), 0, 8 ) as $desc ) {
			$notes[] = sprintf( __( '%s → kein Platzhalter oder Name, bitte im Formular-Plugin eine Beschriftung eintragen', 'plakette' ), $desc );
		}
		if ( $fixable && ! Plakette_Modules::on( 'a11y_form_labels' ) ) {
			return $this->fail( sprintf( _n( '%d Feld ohne Beschriftung.', '%d Felder ohne Beschriftung.', count( $fixable ) + count( $rest ), 'plakette' ), count( $fixable ) + count( $rest ) ), array( 'notes' => $notes ) );
		}
		return $this->warn(
			sprintf( _n( '%d Feld kann Plakette nicht automatisch beschriften.', '%d Felder kann Plakette nicht automatisch beschriften.', count( $fixable ) + count( $rest ), 'plakette' ), count( $fixable ) + count( $rest ) ),
			array(
				'kind'  => 'none',
				'notes' => $notes,
			)
		);
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'a11y_form_labels', true, $undo );
		return __( 'Formularfelder ohne Beschriftung bekommen jetzt eine Beschriftung für Screenreader.', 'plakette' );
	}
}

add_filter(
	'plakette_checks',
	function ( $classes ) {
		return array_merge(
			$classes,
			array(
				'Plakette_Check_Legal_Imprint',
				'Plakette_Check_Legal_Privacy_Policy',
				'Plakette_Check_Legal_Privacy_Coverage',
				'Plakette_Check_Legal_Links',
				'Plakette_Check_Legal_Odr',
				'Plakette_Check_Legal_Outdated_Law',
				'Plakette_Check_Legal_Shop_Button',
				'Plakette_Check_Legal_Shop_Pages',
				'Plakette_Check_Legal_Bfsg_Statement',
				'Plakette_Check_A11y_Lang',
				'Plakette_Check_A11y_Zoom',
				'Plakette_Check_A11y_Form_Labels',
			)
		);
	},
	25
);
