<?php
/**
 * OpenStreetMap account: the site owner connects their own OSM account (OAuth 2.0 with PKCE, "out of band" –
 * OSM shows a code that is pasted into Plakette; no client secret in the plugin). Plakette then writes the
 * corrections the owner selected straight into the map – one changeset per fix, only the selected tags of their own
 * business, each change reviewed by the owner (= a manual edit, not an automated edit under the OSM code of conduct).
 *
 * - Fresh object from the API before every edit; aborts when someone changed the affected tags meanwhile.
 * - Changeset tags: comment (what and why), created_by, source.
 * - Undo through the protocol: op "osm_tags" restores the previous values when nobody edited the object since.
 *
 * Settings section "osm": client_id (default PLAKETTE_OSM_CLIENT_ID / filter plakette_osm_client_id), code, disconnect.
 * Options: plakette_osm_token { token, user, uid, api, time }, plakette_osm_pkce { verifier, client, api }.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Osm_Edit {

	const TOKEN  = 'plakette_osm_token';
	const PKCE   = 'plakette_osm_pkce';
	const SCOPES = 'read_prefs write_api write_notes';
	const OOB    = 'urn:ietf:wg:oauth:2.0:oob';

	/** Client ID of the OAuth 2 app "Plakette" on www.openstreetmap.org (public client, not a secret). */
	const CLIENT_ID_LIVE = 'rkRwFbPYtbtlSa6GZsqDCtpe4ezw3p8DSjfOrpf3vow';

	public static function boot() {
		add_filter( 'plakette_settings_sections', array( __CLASS__, 'settings_section' ), 6 );
		add_action( 'plakette_settings_saved', array( __CLASS__, 'settings_saved' ) );
	}

	/* ---------------------------------------------------------------- account */

	/** Client ID of the "Plakette" app at OpenStreetMap (public client, PKCE). */
	public static function client_id() {
		// Read the stored value directly: Plakette_Settings::get() builds the sections, which call this (recursion).
		$all = get_option( Plakette_Settings::OPTION, array() );
		$own = trim( (string) ( $all['osm']['client_id'] ?? '' ) );
		if ( '' !== $own ) {
			return $own;
		}
		// The public "Plakette" app on openstreetmap.org (PKCE, no secret). On the development server: own ID in the field.
		$default = Plakette_Osm_Profile::dev() ? '' : self::CLIENT_ID_LIVE;
		return (string) apply_filters( 'plakette_osm_client_id', defined( 'PLAKETTE_OSM_CLIENT_ID' ) ? PLAKETTE_OSM_CLIENT_ID : $default );
	}

	/** The connection, if it belongs to the OSM server in use. @return array|null */
	public static function account() {
		$t = get_option( self::TOKEN );
		return is_array( $t ) && ! empty( $t['token'] ) && ( $t['api'] ?? '' ) === Plakette_Osm_Profile::api() ? $t : null;
	}

	public static function connected() {
		return null !== self::account();
	}

	/** Authorization address (creates the PKCE verifier once and keeps it until a code was exchanged). */
	public static function authorize_url() {
		$client = self::client_id();
		if ( '' === $client ) {
			return '';
		}
		$p = get_option( self::PKCE );
		if ( ! is_array( $p ) || ( $p['client'] ?? '' ) !== $client || ( $p['api'] ?? '' ) !== Plakette_Osm_Profile::api() ) {
			$p = array(
				'verifier' => rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ),
				'client'   => $client,
				'api'      => Plakette_Osm_Profile::api(),
			);
			update_option( self::PKCE, $p, false );
		}
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $p['verifier'], true ) ), '+/', '-_' ), '=' );
		return Plakette_Osm_Profile::site() . 'oauth2/authorize?' . http_build_query(
			array(
				'response_type'         => 'code',
				'client_id'             => $client,
				'redirect_uri'          => self::OOB,
				'scope'                 => self::SCOPES,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/** Code from OSM → access token + user name. @return true|WP_Error */
	public static function exchange( $code ) {
		$p = get_option( self::PKCE );
		if ( ! is_array( $p ) || empty( $p['verifier'] ) ) {
			return new WP_Error( 'plakette_osm_auth', __( 'Bitte öffne zuerst den Anmelde-Link und kopiere dann den Code.', 'plakette' ) );
		}
		$res  = wp_remote_post(
			Plakette_Osm_Profile::site() . 'oauth2/token',
			array(
				'timeout'    => 15,
				'user-agent' => self::user_agent(),
				'body'       => array(
					'grant_type'    => 'authorization_code',
					'code'          => trim( (string) $code ),
					'redirect_uri'  => self::OOB,
					'client_id'     => $p['client'],
					'code_verifier' => $p['verifier'],
				),
			)
		);
		$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['access_token'] ) ) {
			$why = is_wp_error( $res ) ? $res->get_error_message() : (string) ( $data['error_description'] ?? $data['error'] ?? wp_remote_retrieve_response_code( $res ) );
			return new WP_Error( 'plakette_osm_auth', sprintf( __( 'OpenStreetMap hat den Code nicht angenommen (%s). Codes gelten nur wenige Minuten – hol dir einen neuen.', 'plakette' ), $why ) );
		}
		$token = (string) $data['access_token'];
		$me    = self::request( 'GET', 'user/details.json', null, $token );
		if ( is_wp_error( $me ) ) {
			return $me;
		}
		$me = json_decode( $me, true );
		update_option(
			self::TOKEN,
			array(
				'token' => $token,
				'user'  => (string) ( $me['user']['display_name'] ?? '' ),
				'uid'   => (int) ( $me['user']['id'] ?? 0 ),
				'api'   => Plakette_Osm_Profile::api(),
				'time'  => time(),
			),
			false
		);
		delete_option( self::PKCE );
		return true;
	}

	public static function disconnect() {
		$t = get_option( self::TOKEN );
		if ( is_array( $t ) && ! empty( $t['token'] ) ) {
			wp_remote_post(
				Plakette_Osm_Profile::site() . 'oauth2/revoke',
				array( 'timeout' => 10, 'user-agent' => self::user_agent(), 'body' => array( 'token' => $t['token'], 'client_id' => self::client_id() ) )
			);
		}
		delete_option( self::TOKEN );
		delete_option( self::PKCE );
	}

	public static function settings_section( $sections ) {
		$account = self::account();
		$msg     = get_transient( 'plakette_osm_auth_msg' );
		if ( $account ) {
			$html = '<p>' . sprintf(
				/* translators: %s: OSM user name (link) */
				esc_html__( 'Verbunden mit dem OpenStreetMap-Konto %s. Korrekturen, die du in der Prüfung „Firmendaten stimmen mit OpenStreetMap überein“ auswählst, trägt Plakette unter diesem Namen direkt in die Karte ein.', 'plakette' ),
				'<a href="' . esc_url( Plakette_Osm_Profile::site() . 'user/' . rawurlencode( $account['user'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $account['user'] ) . '</a>'
			) . '</p>';
		} elseif ( '' === self::client_id() && Plakette_Osm_Profile::dev() ) {
			$html = '<p>' . esc_html__( 'Trag unten die Client-ID deiner Anwendung vom Testserver ein und speichere – dann erscheint hier der Anmelde-Link.', 'plakette' ) . '</p>';
		} elseif ( '' === self::client_id() ) {
			$html = '<p>' . esc_html__( 'Die direkte Verbindung ist in dieser Plakette-Version noch nicht freigeschaltet. Bis dahin meldet Plakette Korrekturen als Kartenhinweis.', 'plakette' ) . '</p>';
		} else {
			$html = '<ol><li>' . sprintf(
				/* translators: %s: link */
				esc_html__( '%s (neues Fenster). Noch kein Konto? Dort kannst du kostenlos eines anlegen.', 'plakette' ),
				'<a href="' . esc_url( self::authorize_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Bei OpenStreetMap anmelden und Plakette erlauben', 'plakette' ) . '</a>'
			) . '</li><li>' . esc_html__( 'Den angezeigten Code kopieren, unten einfügen und speichern.', 'plakette' ) . '</li></ol>';
		}
		if ( Plakette_Osm_Profile::dev() ) {
			$html = '<p><strong>' . esc_html__( 'Testmodus: Plakette arbeitet mit dem OpenStreetMap-Testserver (master.apis.dev.openstreetmap.org), nicht mit der echten Karte.', 'plakette' ) . '</strong></p>' . $html;
		}
		if ( is_string( $msg ) && '' !== $msg ) {
			$html .= '<p><strong>' . esc_html( $msg ) . '</strong></p>';
		}
		$fields = array(
			array( 'key' => 'status', 'label' => __( 'Verbindung', 'plakette' ), 'type' => 'info', 'html' => $html ),
		);
		if ( $account ) {
			$fields[] = array( 'key' => 'disconnect', 'label' => __( 'Verbindung trennen', 'plakette' ), 'type' => 'toggle', 'default' => false, 'help' => __( 'Einschalten und speichern: Plakette vergisst den Zugang und meldet ihn bei OpenStreetMap ab.', 'plakette' ) );
		} elseif ( '' !== self::client_id() ) {
			$fields[] = array( 'key' => 'code', 'label' => __( 'Code von OpenStreetMap', 'plakette' ), 'type' => 'text', 'placeholder' => 'z. B. 3fK9…', 'help' => __( 'Der Code gilt nur ein paar Minuten.', 'plakette' ) );
		}
		$fields[] = array( 'key' => 'client_id', 'label' => __( 'Eigene Client-ID (nur für Fachleute)', 'plakette' ), 'type' => 'text', 'help' => __( 'Leer lassen. Nur nötig, wenn du eine eigene OAuth-2-Anwendung bei OpenStreetMap registriert hast (Weiterleitung urn:ietf:wg:oauth:2.0:oob, nicht vertraulich, Rechte: Benutzereinstellungen lesen, Karte bearbeiten, Hinweise bearbeiten).', 'plakette' ) );
		$sections['osm'] = array(
			'title'  => __( 'OpenStreetMap', 'plakette' ),
			'desc'   => __( 'Verbinde Plakette mit deinem OpenStreetMap-Konto. Dann trägt Plakette Korrekturen an deinem Eintrag (Adresse, Telefon, Öffnungszeiten, Website) nach deiner Auswahl direkt in die freie Weltkarte ein – statt Freiwillige per Hinweis darum zu bitten. Jede Änderung ist öffentlich, unter deinem Namen sichtbar und lässt sich im Protokoll zurücknehmen.', 'plakette' ),
			'fields' => $fields,
		);
		return $sections;
	}

	public static function settings_saved( $section ) {
		if ( 'osm' !== $section ) {
			return;
		}
		$msg = '';
		if ( Plakette_Settings::get( 'osm', 'disconnect' ) ) {
			self::disconnect();
			$msg = __( 'Verbindung getrennt.', 'plakette' );
		}
		$code = trim( (string) Plakette_Settings::get( 'osm', 'code' ) );
		if ( '' !== $code ) {
			$ok  = self::exchange( $code );
			$msg = is_wp_error( $ok ) ? $ok->get_error_message() : __( 'Verbunden.', 'plakette' );
		}
		Plakette_Settings::save( 'osm', array( 'code' => '', 'disconnect' => false ) );
		set_transient( 'plakette_osm_auth_msg', $msg, 60 );
	}

	/* -------------------------------------------------------------- requests */

	private static function user_agent() {
		return 'Plakette/' . ( defined( 'PLAKETTE_VERSION' ) ? PLAKETTE_VERSION : '1' ) . ' (WordPress plugin; +https://github.com/mblmg/plakette)';
	}

	/** Authenticated API request. @return string|WP_Error body */
	private static function request( $method, $path, $body = null, $token = null ) {
		$token = $token ?? ( self::account()['token'] ?? '' );
		$res   = wp_remote_request(
			Plakette_Osm_Profile::api() . $path,
			array(
				'method'     => $method,
				'timeout'    => 20,
				'user-agent' => self::user_agent(),
				'headers'    => array_filter(
					array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => null === $body ? null : 'text/xml; charset=utf-8',
					)
				),
				'body'       => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'plakette_osm', sprintf( __( 'OpenStreetMap war nicht erreichbar: %s', 'plakette' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$text = (string) wp_remote_retrieve_body( $res );
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'plakette_osm_auth', sprintf( __( 'OpenStreetMap hat den Zugang abgelehnt (Antwort %1$d: %2$s). Verbinde Plakette unter Einstellungen › OpenStreetMap neu.', 'plakette' ), $code, wp_strip_all_tags( substr( $text, 0, 200 ) ) ) );
		}
		if ( $code >= 400 ) {
			return new WP_Error( 409 === $code ? 'plakette_osm_conflict' : 'plakette_osm', sprintf( __( 'OpenStreetMap hat die Änderung abgelehnt (Antwort %1$d: %2$s).', 'plakette' ), $code, wp_strip_all_tags( substr( $text, 0, 300 ) ) ) );
		}
		return $text;
	}

	/* ------------------------------------------------------------------ edits */

	/** OSM forbids abbreviations in names: "Hauptstr." → "Hauptstraße", "Str." → "Straße". */
	public static function expand_street( $street ) {
		$street = preg_replace( '/(?<=\p{L})str\.(?=\s|$)/u', 'straße', (string) $street );
		return preg_replace( '/\bStr\.(?=\s|$)/u', 'Straße', $street );
	}

	/**
	 * Tag changes for the selected fields of a difference list: our values in the keys the object already uses
	 * (contact:phone stays contact:phone), opening hours keep the holiday and seasonal rules of OSM.
	 *
	 * @param array $diffs differences by key (Plakette_Osm_Profile::differences())
	 * @param array $tags  current tags of the object
	 * @return array tag => new value
	 */
	public static function changes( array $diffs, array $tags ) {
		$out = array();
		foreach ( $diffs as $key => $d ) {
			foreach ( (array) $d['tags'] as $k => $v ) {
				if ( 'phone' === $k && ! isset( $tags['phone'] ) && isset( $tags['contact:phone'] ) ) {
					$k = 'contact:phone';
				} elseif ( 'website' === $k && ! isset( $tags['website'] ) && isset( $tags['contact:website'] ) ) {
					$k = 'contact:website';
				} elseif ( 'addr:street' === $k ) {
					$v = self::expand_street( $v );
				} elseif ( 'opening_hours' === $k ) {
					$keep = self::kept_rules( (string) ( $tags['opening_hours'] ?? '' ) );
					$v    = implode( '; ', array_merge( array( $v ), $keep ) );
				}
				$out[ $k ] = $v;
			}
			if ( 'opening_hours' === $key ) {
				$out['check_date:opening_hours'] = wp_date( 'Y-m-d' );
			}
		}
		// Values that are already there need no edit (e.g. the housenumber when only the street name differs).
		return array_filter( $out, fn( $v, $k ) => ( $tags[ $k ] ?? null ) !== $v, ARRAY_FILTER_USE_BOTH );
	}

	/** Rules of OSM opening hours that the company data cannot express and that should survive: holidays, seasons. */
	public static function kept_rules( $raw ) {
		$info = Plakette_Osm_Profile::parse_osm_hours( $raw );
		$keep = array();
		foreach ( $info['holidays'] as $rule ) {
			// "PH,Su 08:00-18:00" → "PH 08:00-18:00" (the Sunday comes from the company data).
			if ( preg_match( '/^((?:(?:Mo|Tu|We|Th|Fr|Sa|Su|PH|SH)(?:-(?:Mo|Tu|We|Th|Fr|Sa|Su))?\s*,?\s*)+)\s*(.*)$/', $rule, $m ) ) {
				$hol = array_intersect( preg_split( '/\s*,\s*/', trim( $m[1], ' ,' ) ), array( 'PH', 'SH' ) );
				if ( $hol ) {
					$keep[] = implode( ',', $hol ) . ' ' . trim( $m[2] );
				}
			}
		}
		return array_values( array_unique( array_merge( $keep, $info['skipped'] ) ) );
	}

	/** Comment of the changeset: what changed, for whom, from where. */
	public static function comment( $name, array $labels ) {
		return sprintf( '%1$s von „%2$s“ nach Angaben der Unternehmenswebsite aktualisiert', implode( ', ', $labels ), $name );
	}

	/**
	 * Writes tag changes into one object in one changeset.
	 *
	 * @param array $expected tag => value the owner saw (abort when OSM differs now; null = tag absent)
	 * @return array|WP_Error [ changeset, version, old (tag => previous value|null) ]
	 */
	public static function write( $type, $id, array $set, array $expected, $comment ) {
		if ( ! self::connected() ) {
			return new WP_Error( 'plakette_osm_auth', __( 'Plakette ist nicht mit OpenStreetMap verbunden (Einstellungen › OpenStreetMap).', 'plakette' ) );
		}
		$xml = self::request( 'GET', $type . '/' . (int) $id );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}
		$doc = new DOMDocument();
		if ( ! @$doc->loadXML( $xml, LIBXML_NONET ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'plakette_osm', __( 'Unerwartete Antwort von OpenStreetMap.', 'plakette' ) );
		}
		$el = $doc->getElementsByTagName( $type )->item( 0 );
		if ( ! $el || 'false' === $el->getAttribute( 'visible' ) ) {
			return new WP_Error( 'plakette_osm_gone', __( 'Das Objekt gibt es in OpenStreetMap nicht mehr.', 'plakette' ) );
		}
		$current = array();
		foreach ( iterator_to_array( $el->getElementsByTagName( 'tag' ) ) as $t ) {
			$current[ $t->getAttribute( 'k' ) ] = $t->getAttribute( 'v' );
		}
		foreach ( $expected as $k => $v ) {
			if ( ( $current[ $k ] ?? null ) !== $v ) {
				return new WP_Error( 'plakette_osm_conflict', sprintf( __( 'Jemand hat „%1$s“ in OpenStreetMap inzwischen geändert (jetzt: %2$s). Prüfe noch einmal, dann siehst du den neuen Stand.', 'plakette' ), $k, $current[ $k ] ?? __( '(leer)', 'plakette' ) ) );
			}
		}
		$cs = self::open_changeset( $comment );
		if ( is_wp_error( $cs ) ) {
			return $cs;
		}
		$old = array();
		foreach ( $set as $k => $v ) {
			$old[ $k ] = $current[ $k ] ?? null;
		}
		foreach ( iterator_to_array( $el->getElementsByTagName( 'tag' ) ) as $t ) {
			if ( array_key_exists( $t->getAttribute( 'k' ), $set ) ) {
				$el->removeChild( $t );
			}
		}
		foreach ( $set as $k => $v ) {
			if ( null === $v || '' === $v ) {
				continue; // null = remove the tag (undo of a tag Plakette added).
			}
			$t = $doc->createElement( 'tag' );
			$t->setAttribute( 'k', $k );
			$t->setAttribute( 'v', mb_substr( (string) $v, 0, 255 ) );
			$el->appendChild( $t );
		}
		$el->setAttribute( 'changeset', (string) $cs );
		$version = self::request( 'PUT', $type . '/' . (int) $id, $doc->saveXML() );
		self::request( 'PUT', 'changeset/' . $cs . '/close', '' );
		if ( is_wp_error( $version ) ) {
			return $version;
		}
		delete_transient( Plakette_Osm_Profile::CACHE . 'el_' . $type . (int) $id );
		return array( 'changeset' => $cs, 'version' => (int) trim( $version ), 'old' => $old );
	}

	private static function open_changeset( $comment ) {
		$doc = new DOMDocument( '1.0', 'UTF-8' );
		$osm = $doc->appendChild( $doc->createElement( 'osm' ) );
		$cs  = $osm->appendChild( $doc->createElement( 'changeset' ) );
		$tags = array(
			'comment'    => mb_substr( $comment, 0, 255 ),
			'created_by' => 'Plakette ' . ( defined( 'PLAKETTE_VERSION' ) ? PLAKETTE_VERSION : '' ),
			'source'     => 'Website des Unternehmens: ' . home_url( '/' ),
		);
		foreach ( $tags as $k => $v ) {
			$t = $doc->createElement( 'tag' );
			$t->setAttribute( 'k', $k );
			$t->setAttribute( 'v', $v );
			$cs->appendChild( $t );
		}
		$id = self::request( 'PUT', 'changeset/create', $doc->saveXML() );
		return is_wp_error( $id ) ? $id : (int) trim( $id );
	}

	/** Undo op of the protocol: restore the old values – only if nobody edited the object since. */
	public static function undo( $type, $id, $version, array $old, array $new ) {
		$xml = self::request( 'GET', $type . '/' . (int) $id );
		if ( ! is_wp_error( $xml ) && preg_match( '/<' . preg_quote( $type, '/' ) . '\b[^>]*\sversion="(\d+)"/', $xml, $m ) && (int) $m[1] !== (int) $version ) {
			return new WP_Error( 'plakette_osm_conflict', sprintf( __( 'Der OpenStreetMap-Eintrag wurde seit deiner Änderung weiter bearbeitet – Plakette nimmt deshalb nichts zurück. Sieh ihn dir an: %s', 'plakette' ), Plakette_Osm_Profile::link( $type, $id ) ) );
		}
		$r = self::write( $type, $id, $old, $new, 'Rückgängig: eigene Änderung über Plakette zurückgenommen' );
		return is_wp_error( $r ) ? $r : true;
	}
}

add_action( 'plakette_boot', array( 'Plakette_Osm_Edit', 'boot' ) );
