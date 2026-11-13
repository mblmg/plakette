<?php
/**
 * Settings shown in the "Einstellungen" panel of the admin screen, and the company data that
 * Impressum, privacy policy and structured data (schema.org) share.
 *
 * Features add their own sections through the filter "plakette_settings_sections":
 *
 *   $sections['consent'] = array(
 *       'title'  => 'Cookie-Banner',
 *       'desc'   => 'Kurzer Text unter der Überschrift.',
 *       'fields' => array(
 *           array( 'key' => 'title', 'label' => 'Überschrift', 'type' => 'text', 'default' => 'Datenschutz' ),
 *       ),
 *   );
 *
 * Field types: text, textarea, email, url, tel, number, select (options: value => label), toggle (bool), info (read-only html).
 * Values live in the option "plakette_settings" as  section => key => value.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Settings {

	const OPTION = 'plakette_settings';

	private static $cache = null;

	public static function sections() {
		$sections = array(
			'general' => array(
				'title'  => __( 'Plugin', 'plakette' ),
				'desc'   => __( 'Plakette aktualisiert sich aus GitHub. Neue Versionen erscheinen wie gewohnt unter Plugins.', 'plakette' ),
				'fields' => array(
					array(
						'key'     => 'auto_update',
						'label'   => __( 'Updates automatisch installieren', 'plakette' ),
						'type'    => 'toggle',
						'default' => true,
						'help'    => __( 'WordPress installiert neue Versionen von Plakette selbstständig (zweimal täglich wird nachgesehen).', 'plakette' ),
					),
				),
			),
			'company' => array(
				'title'  => __( 'Firmendaten', 'plakette' ),
				'desc'   => __( 'Einmal eintragen – Plakette nutzt die Angaben für Impressum, Datenschutzerklärung und die strukturierten Daten für Google (Firmenprofil, Adresse, Öffnungszeiten).', 'plakette' ),
				'fields' => self::company_fields(),
			),
		);
		return (array) apply_filters( 'plakette_settings_sections', $sections );
	}

	/** Field definitions of the company data. */
	public static function company_fields() {
		$types = array();
		foreach ( self::schema_types() as $type => $label ) {
			$types[ $type ] = $label;
		}
		return array(
			array( 'key' => 'name', 'label' => __( 'Name der Firma bzw. dein Name', 'plakette' ), 'type' => 'text', 'required' => true, 'placeholder' => 'Muster GmbH' ),
			array( 'key' => 'legal_form', 'label' => __( 'Rechtsform', 'plakette' ), 'type' => 'select', 'options' => array( '' => __( '– bitte wählen –', 'plakette' ), 'einzel' => __( 'Einzelunternehmen / Freiberufler', 'plakette' ), 'gbr' => 'GbR', 'gmbh' => 'GmbH / UG', 'ag' => 'AG', 'kg' => 'KG / GmbH & Co. KG', 'ohg' => 'OHG', 'ev' => __( 'eingetragener Verein (e. V.)', 'plakette' ), 'privat' => __( 'private Website (nicht geschäftlich)', 'plakette' ), 'andere' => __( 'andere', 'plakette' ) ) ),
			array( 'key' => 'owner', 'label' => __( 'Inhaber bzw. vertretungsberechtigte Person(en)', 'plakette' ), 'type' => 'text', 'required' => true, 'placeholder' => 'Max Mustermann (Geschäftsführer)' ),
			array( 'key' => 'street', 'label' => __( 'Straße und Hausnummer', 'plakette' ), 'type' => 'text', 'required' => true, 'help' => __( 'Eine ladungsfähige Anschrift – ein Postfach reicht nicht.', 'plakette' ) ),
			array( 'key' => 'zip', 'label' => __( 'PLZ', 'plakette' ), 'type' => 'text', 'required' => true ),
			array( 'key' => 'city', 'label' => __( 'Ort', 'plakette' ), 'type' => 'text', 'required' => true ),
			array( 'key' => 'region', 'label' => __( 'Bundesland / Region', 'plakette' ), 'type' => 'text' ),
			array( 'key' => 'country', 'label' => __( 'Land (Kürzel)', 'plakette' ), 'type' => 'text', 'default' => 'DE', 'placeholder' => 'DE' ),
			array( 'key' => 'phone', 'label' => __( 'Telefon', 'plakette' ), 'type' => 'tel', 'help' => __( 'Das Impressum muss eine schnelle Kontaktaufnahme ermöglichen: Telefon oder ein zweiter direkter Weg neben der E-Mail.', 'plakette' ) ),
			array( 'key' => 'email', 'label' => __( 'E-Mail-Adresse', 'plakette' ), 'type' => 'email', 'required' => true ),
			array( 'key' => 'vat_id', 'label' => __( 'Umsatzsteuer-ID', 'plakette' ), 'type' => 'text', 'placeholder' => 'DE123456789', 'help' => __( 'Nur falls vorhanden. Die Steuernummer gehört nicht ins Impressum.', 'plakette' ) ),
			array( 'key' => 'register', 'label' => __( 'Registereintrag', 'plakette' ), 'type' => 'text', 'placeholder' => 'Amtsgericht Freiburg, HRB 123456', 'help' => __( 'Für GmbH, UG, AG, KG, OHG, e. V. und eingetragene Kaufleute.', 'plakette' ) ),
			array( 'key' => 'profession', 'label' => __( 'Berufsangaben (nur reglementierte Berufe)', 'plakette' ), 'type' => 'textarea', 'help' => __( 'Z. B. für Ärztinnen, Anwälte, Architektinnen, Handwerk mit Meisterpflicht: Berufsbezeichnung, Staat der Verleihung, zuständige Kammer, berufsrechtliche Regelungen.', 'plakette' ) ),
			array( 'key' => 'authority', 'label' => __( 'Zuständige Aufsichtsbehörde', 'plakette' ), 'type' => 'text', 'help' => __( 'Nur falls deine Tätigkeit eine behördliche Zulassung braucht (z. B. Makler, Inkasso).', 'plakette' ) ),
			array( 'key' => 'editorial', 'label' => __( 'Verantwortlich für den Inhalt (§ 18 Abs. 2 MStV)', 'plakette' ), 'type' => 'text', 'help' => __( 'Nur bei journalistisch-redaktionellen Inhalten, z. B. einem Blog mit Meinungsbeiträgen: Name und Anschrift.', 'plakette' ) ),
			array( 'key' => 'schema_type', 'label' => __( 'Art des Unternehmens (für Google)', 'plakette' ), 'type' => 'select', 'options' => $types, 'default' => 'ProfessionalService' ),
			array( 'key' => 'price_range', 'label' => __( 'Preisniveau', 'plakette' ), 'type' => 'select', 'options' => array( '' => '–', '€' => '€', '€€' => '€€', '€€€' => '€€€', '€€€€' => '€€€€' ) ),
			array( 'key' => 'opening_hours', 'label' => __( 'Öffnungszeiten', 'plakette' ), 'type' => 'textarea', 'placeholder' => "Mo-Fr 9-17\nSa 10:00-14:00", 'help' => __( 'Eine Zeile pro Regel, z. B. „Mo-Fr 9-17“ oder „Di, Do 14-18“.', 'plakette' ) ),
			array( 'key' => 'area_served', 'label' => __( 'Einzugsgebiet', 'plakette' ), 'type' => 'textarea', 'placeholder' => "Freiburg\nDeutschland", 'help' => __( 'Eine Stadt oder ein Land pro Zeile.', 'plakette' ) ),
			array( 'key' => 'gbp_url', 'label' => __( 'Google-Unternehmensprofil (Link)', 'plakette' ), 'type' => 'url', 'placeholder' => 'https://maps.app.goo.gl/…', 'help' => __( 'In Google Maps dein Unternehmen öffnen › „Teilen“ › Link kopieren. Plakette verknüpft das Profil in den strukturierten Daten und gleicht die Angaben ab (dafür unter „Google-Unternehmensprofil“ einen API-Schlüssel eintragen).', 'plakette' ) ),
			array( 'key' => 'profiles', 'label' => __( 'Profile im Netz', 'plakette' ), 'type' => 'textarea', 'placeholder' => "https://www.linkedin.com/company/…\nhttps://maps.app.goo.gl/…", 'help' => __( 'Eine Adresse pro Zeile: Google-Unternehmensprofil, LinkedIn, Instagram …', 'plakette' ) ),
			array( 'key' => 'topics', 'label' => __( 'Fachgebiete', 'plakette' ), 'type' => 'textarea', 'help' => __( 'Ein Thema pro Zeile (für „knowsAbout“).', 'plakette' ) ),
			array( 'key' => 'languages', 'label' => __( 'Sprachen', 'plakette' ), 'type' => 'text', 'placeholder' => 'Deutsch, Englisch' ),
			array( 'key' => 'offers', 'label' => __( 'Angebote', 'plakette' ), 'type' => 'textarea', 'placeholder' => 'Website-Paket | 2.400 | | Fertige Website mit 5 Seiten', 'help' => __( 'Eine Zeile pro Angebot: Name | Preis | Einheit | Beschreibung. Preis und Einheit dürfen leer bleiben.', 'plakette' ) ),
		);
	}

	public static function schema_types() {
		return array(
			'ProfessionalService'          => __( 'Dienstleister', 'plakette' ),
			'LocalBusiness'                => __( 'Lokales Geschäft (allgemein)', 'plakette' ),
			'Store'                        => __( 'Laden', 'plakette' ),
			'FoodEstablishment'            => __( 'Gastronomie', 'plakette' ),
			'HomeAndConstructionBusiness'  => __( 'Handwerk / Bau', 'plakette' ),
			'HealthAndBeautyBusiness'      => __( 'Gesundheit / Schönheit', 'plakette' ),
			'MedicalBusiness'              => __( 'Medizin', 'plakette' ),
			'LegalService'                 => __( 'Recht', 'plakette' ),
			'FinancialService'             => __( 'Finanzen', 'plakette' ),
			'EducationalOrganization'      => __( 'Bildung', 'plakette' ),
			'EntertainmentBusiness'        => __( 'Unterhaltung', 'plakette' ),
			'SportsActivityLocation'       => __( 'Sport', 'plakette' ),
			'LodgingBusiness'              => __( 'Unterkunft', 'plakette' ),
			'AutomotiveBusiness'           => __( 'Kfz', 'plakette' ),
			'Organization'                 => __( 'Organisation ohne Ladengeschäft', 'plakette' ),
		);
	}

	/* ------------------------------------------------------------------ values */

	private static function all() {
		if ( null === self::$cache ) {
			$v           = get_option( self::OPTION, array() );
			self::$cache = is_array( $v ) ? $v : array();
		}
		return self::$cache;
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	private static function field( $section, $key ) {
		$sections = self::sections();
		if ( empty( $sections[ $section ]['fields'] ) ) {
			return null;
		}
		foreach ( $sections[ $section ]['fields'] as $f ) {
			if ( $f['key'] === $key ) {
				return $f;
			}
		}
		return null;
	}

	/** One value (with the field default) or the whole section when $key is null. */
	public static function get( $section, $key = null ) {
		$all  = self::all();
		$vals = isset( $all[ $section ] ) && is_array( $all[ $section ] ) ? $all[ $section ] : array();
		if ( null === $key ) {
			$out = array();
			$sections = self::sections();
			foreach ( isset( $sections[ $section ]['fields'] ) ? $sections[ $section ]['fields'] : array() as $f ) {
				$out[ $f['key'] ] = array_key_exists( $f['key'], $vals ) ? $vals[ $f['key'] ] : ( isset( $f['default'] ) ? $f['default'] : '' );
			}
			return $out + $vals;
		}
		if ( array_key_exists( $key, $vals ) ) {
			return $vals[ $key ];
		}
		$f = self::field( $section, $key );
		return $f && isset( $f['default'] ) ? $f['default'] : '';
	}

	/** Company data with defaults. */
	public static function company() {
		$c = self::get( 'company' );
		if ( '' === trim( (string) $c['name'] ) ) {
			$c['name_fallback'] = get_bloginfo( 'name' );
		}
		return $c;
	}

	/** Missing required company fields (labels). */
	public static function company_missing( array $keys = array() ) {
		$c       = self::get( 'company' );
		$missing = array();
		foreach ( self::company_fields() as $f ) {
			if ( ! empty( $f['required'] ) && ( ! $keys || in_array( $f['key'], $keys, true ) ) && '' === trim( (string) $c[ $f['key'] ] ) ) {
				$missing[ $f['key'] ] = $f['label'];
			}
		}
		return $missing;
	}

	public static function sanitize_value( array $f, $value ) {
		switch ( isset( $f['type'] ) ? $f['type'] : 'text' ) {
			case 'toggle':
				return (bool) ( is_string( $value ) ? in_array( strtolower( $value ), array( '1', 'true', 'on', 'yes' ), true ) : $value );
			case 'number':
				return is_numeric( $value ) ? 0 + $value : ( isset( $f['default'] ) ? $f['default'] : 0 );
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'email':
				return sanitize_email( (string) $value );
			case 'url':
				return esc_url_raw( (string) $value );
			case 'select':
				$value = (string) $value;
				return isset( $f['options'][ $value ] ) ? $value : ( isset( $f['default'] ) ? $f['default'] : '' );
			case 'info':
				return null;
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Saves values of one section (only known fields). With $undo the change can be undone through the protocol.
	 *
	 * @return array the stored section values
	 */
	public static function save( $section, array $values, ?array &$undo = null ) {
		$sections = self::sections();
		if ( empty( $sections[ $section ] ) ) {
			return array();
		}
		$all     = self::all();
		$current = isset( $all[ $section ] ) && is_array( $all[ $section ] ) ? $all[ $section ] : array();
		foreach ( $sections[ $section ]['fields'] as $f ) {
			if ( array_key_exists( $f['key'], $values ) ) {
				$v = self::sanitize_value( $f, $values[ $f['key'] ] );
				if ( null !== $v ) {
					$current[ $f['key'] ] = $v;
				}
			}
		}
		if ( 'company' === $section && isset( $current['country'] ) ) {
			$current['country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $current['country'] ), 0, 2 ) );
		}
		$all[ $section ] = $current;
		if ( null !== $undo ) {
			Plakette_Log::set_option( self::OPTION, $all, $undo, false );
		} else {
			update_option( self::OPTION, $all, false );
		}
		self::$cache = $all;
		return $current;
	}

	/**
	 * Field definitions with current values, for a form in a check ("kind" => "form") or the settings panel.
	 *
	 * @param string   $section
	 * @param string[] $keys    only these fields (in this order); empty = all
	 */
	public static function form( $section, array $keys = array() ) {
		$sections = self::sections();
		$fields   = isset( $sections[ $section ]['fields'] ) ? $sections[ $section ]['fields'] : array();
		$values   = self::get( $section );
		$out      = array();
		if ( $keys ) {
			$by_key = array();
			foreach ( $fields as $f ) {
				$by_key[ $f['key'] ] = $f;
			}
			$fields = array();
			foreach ( $keys as $k ) {
				if ( isset( $by_key[ $k ] ) ) {
					$fields[] = $by_key[ $k ];
				}
			}
		}
		foreach ( $fields as $f ) {
			$f['value'] = isset( $values[ $f['key'] ] ) ? $values[ $f['key'] ] : '';
			$out[]      = $f;
		}
		return array(
			'section' => $section,
			'fields'  => $out,
		);
	}

	/** Everything the settings panel needs. */
	public static function state() {
		$out = array();
		foreach ( self::sections() as $id => $s ) {
			$form  = self::form( $id );
			$out[] = array(
				'id'     => $id,
				'title'  => $s['title'],
				'desc'   => isset( $s['desc'] ) ? $s['desc'] : '',
				'fields' => $form['fields'],
			);
		}
		return $out;
	}
}
