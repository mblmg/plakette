<?php
/**
 * Google Business Profile ("Google-Unternehmensprofil"): reads the public profile through the Places API (New)
 * with the site owner's API key and compares it with the company data (Plakette_Settings::company()).
 *
 * Only reading: Plakette never writes to Google. Differences are shown by the check seo_google_profile, which
 * can take the Google values over into the company data (undoable).
 *
 * Settings: company.gbp_url (link to the profile), google.places_key (API key, Places API (New)).
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Google_Profile {

	/** Option: resolved place { url, id, name }. */
	const PLACE = 'plakette_gbp_place';

	/** Transient prefix for the place details (12 hours). */
	const CACHE = 'plakette_gbp_';

	const API = 'https://places.googleapis.com/v1/';

	/** Fields of the place details (field mask). Phone, website and hours are in the "Enterprise" SKU. */
	const FIELDS = 'id,displayName,formattedAddress,addressComponents,nationalPhoneNumber,internationalPhoneNumber,websiteUri,regularOpeningHours,googleMapsUri,businessStatus';

	public static function boot() {
		add_filter( 'plakette_settings_sections', array( __CLASS__, 'settings_section' ), 5 );
		add_action( 'plakette_settings_saved', array( __CLASS__, 'settings_saved' ) );
	}

	public static function settings_section( $sections ) {
		$sections['google'] = array(
			'title'  => __( 'Google-Unternehmensprofil', 'plakette' ),
			'desc'   => __( 'Mit einem API-Schlüssel liest Plakette dein Google-Unternehmensprofil und vergleicht es mit den Firmendaten (Prüfung „Firmendaten stimmen mit Google überein“). Plakette liest nur – an deinem Profil bei Google ändert sich nichts.', 'plakette' ),
			'fields' => array(
				array(
					'key'         => 'places_key',
					'label'       => __( 'API-Schlüssel (Places API)', 'plakette' ),
					'type'        => 'password',
					'placeholder' => 'AIza…',
					'help'        => __( 'In der Google Cloud Console: Projekt anlegen, „Places API (New)“ aktivieren, unter „Anmeldedaten“ einen API-Schlüssel erstellen und auf die Places API beschränken. Google verlangt eine hinterlegte Zahlungsart; eine Abfrage pro Tag liegt weit im kostenlosen Kontingent.', 'plakette' ),
				),
			),
		);
		return $sections;
	}

	/** A new link or key: resolve the place again and drop cached details. */
	public static function settings_saved( $section ) {
		if ( in_array( $section, array( 'company', 'google' ), true ) ) {
			self::forget();
		}
	}

	public static function forget() {
		$place = get_option( self::PLACE );
		if ( is_array( $place ) && ! empty( $place['id'] ) ) {
			delete_transient( self::CACHE . md5( $place['id'] . '|' . self::key() ) );
		}
		if ( is_array( $place ) && ( ! isset( $place['url'] ) || $place['url'] !== self::url() ) ) {
			delete_option( self::PLACE );
		}
	}

	public static function key() {
		return trim( (string) Plakette_Settings::get( 'google', 'places_key' ) );
	}

	public static function url() {
		return trim( (string) Plakette_Settings::get( 'company', 'gbp_url' ) );
	}

	/** Does the address look like a Google Maps / Business Profile link (or a Place ID)? */
	public static function is_profile_url( $url ) {
		return (bool) preg_match( '#^(ChIJ[\w-]{10,}$|https?://((www\.)?google\.[a-z.]+/maps|maps\.google\.[a-z.]+|maps\.app\.goo\.gl|goo\.gl/maps|g\.page|g\.co/kgs|business\.google\.com|share\.google))#i', trim( (string) $url ) );
	}

	/** One request to the Places API. @return array|WP_Error decoded answer */
	private static function request( $path, $mask, $body = null ) {
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'X-Goog-Api-Key'   => self::key(),
				'X-Goog-FieldMask' => $mask,
				'Content-Type'     => 'application/json',
			),
		);
		$res  = null === $body
			? wp_remote_get( self::API . $path, $args )
			: wp_remote_post( self::API . $path, $args + array( 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'plakette_gbp', sprintf( __( 'Google war nicht erreichbar: %s', 'plakette' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
			return new WP_Error( 'plakette_gbp', sprintf( __( 'Google hat die Anfrage abgelehnt (Antwort %1$d): %2$s', 'plakette' ), $code, $msg ) );
		}
		return $data;
	}

	/** Follows a short link (maps.app.goo.gl, g.page …) to the full Maps address. */
	private static function expand( $url ) {
		for ( $i = 0; $i < 5; $i++ ) {
			if ( ! preg_match( '#^https?://(maps\.app\.goo\.gl|goo\.gl|g\.page|g\.co|share\.google)/#i', $url ) ) {
				break;
			}
			$res = wp_remote_get( $url, array( 'redirection' => 0, 'timeout' => 10 ) );
			$loc = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_header( $res, 'location' );
			if ( '' === $loc ) {
				break;
			}
			$url = $loc;
		}
		return $url;
	}

	/**
	 * Place ID of the linked profile: direct ID, "place_id:" in the link, or a text search with the name from the
	 * link (else company name + city). Cached in the option plakette_gbp_place until the link changes.
	 *
	 * @return array|WP_Error [ id, name, query ]
	 */
	public static function place() {
		$url    = self::url();
		$cached = get_option( self::PLACE );
		if ( is_array( $cached ) && ! empty( $cached['id'] ) && isset( $cached['url'] ) && $cached['url'] === $url ) {
			return $cached;
		}
		if ( preg_match( '/(?:place_id[:=]|^)(ChIJ[\w-]{10,})/', $url, $m ) ) {
			$place = array( 'url' => $url, 'id' => $m[1], 'name' => '', 'query' => '' );
			update_option( self::PLACE, $place, false );
			return $place;
		}
		$full  = self::expand( $url );
		$name  = preg_match( '#/maps/place/([^/@?]+)#', $full, $m ) ? trim( str_replace( '+', ' ', rawurldecode( $m[1] ) ) ) : '';
		$c     = Plakette_Settings::company();
		$query = '' !== $name ? $name : trim( ( '' !== trim( (string) $c['name'] ) ? $c['name'] : get_bloginfo( 'name' ) ) . ' ' . $c['street'] . ' ' . $c['city'] );
		if ( '' !== $name && '' !== trim( (string) $c['city'] ) && false === stripos( $name, (string) $c['city'] ) ) {
			$query .= ' ' . $c['city'];
		}
		$body = array( 'textQuery' => $query, 'languageCode' => 'de' );
		if ( preg_match( '/^[A-Z]{2}$/', (string) $c['country'] ) ) {
			$body['regionCode'] = $c['country'];
		}
		// Coordinates in the link narrow the search to the right place.
		if ( preg_match( '#@(-?\d+\.\d+),(-?\d+\.\d+)#', $full, $ll ) ) {
			$body['locationBias'] = array( 'circle' => array( 'center' => array( 'latitude' => (float) $ll[1], 'longitude' => (float) $ll[2] ), 'radius' => 500.0 ) );
		}
		$data = self::request( 'places:searchText', 'places.id,places.displayName,places.formattedAddress', $body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['places'][0]['id'] ) ) {
			return new WP_Error( 'plakette_gbp', sprintf( __( 'Google findet zu „%s“ kein Unternehmensprofil. Trag statt des Links die Place ID ein (beginnt mit „ChIJ“, zu finden mit dem „Place ID Finder“ von Google).', 'plakette' ), $query ) );
		}
		$place = array(
			'url'   => $url,
			'id'    => (string) $data['places'][0]['id'],
			'name'  => isset( $data['places'][0]['displayName']['text'] ) ? (string) $data['places'][0]['displayName']['text'] : '',
			'query' => $query,
		);
		update_option( self::PLACE, $place, false );
		return $place;
	}

	/** Place details (cached 12 hours). @return array|WP_Error */
	public static function details() {
		$place = self::place();
		if ( is_wp_error( $place ) ) {
			return $place;
		}
		$key = self::CACHE . md5( $place['id'] . '|' . self::key() );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$data = self::request( 'places/' . rawurlencode( $place['id'] ) . '?languageCode=de', self::FIELDS );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		set_transient( $key, $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/** Address component of a place by type (long or short text). */
	private static function component( array $place, $type, $short = false ) {
		foreach ( isset( $place['addressComponents'] ) ? (array) $place['addressComponents'] : array() as $c ) {
			if ( in_array( $type, (array) ( $c['types'] ?? array() ), true ) ) {
				return (string) ( $short ? ( $c['shortText'] ?? '' ) : ( $c['longText'] ?? '' ) );
			}
		}
		return '';
	}

	/** Opening hours of the place as text lines in the format of the company data („Mo-Fr 09:00-17:00“). */
	public static function hours_text( $hours ) {
		$names = array( 1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 0 => 'So' );
		$spans = array();
		foreach ( isset( $hours['periods'] ) ? (array) $hours['periods'] : array() as $p ) {
			if ( ! isset( $p['open']['day'] ) ) {
				continue;
			}
			$open  = sprintf( '%02d:%02d', (int) ( $p['open']['hour'] ?? 0 ), (int) ( $p['open']['minute'] ?? 0 ) );
			$close = isset( $p['close'] ) ? sprintf( '%02d:%02d', (int) ( $p['close']['hour'] ?? 0 ), (int) ( $p['close']['minute'] ?? 0 ) ) : '23:59';
			if ( '00:00' === $close ) {
				$close = '23:59';
			}
			$spans[ (int) $p['open']['day'] ][] = $open . '-' . $close;
		}
		$lines = array();
		$run   = array();
		$prev  = null;
		$flush = function () use ( &$run, &$prev, &$lines, $names ) {
			if ( $run && null !== $prev ) {
				$days    = count( $run ) > 2 ? $names[ $run[0] ] . '-' . $names[ end( $run ) ] : implode( ', ', array_map( fn( $d ) => $names[ $d ], $run ) );
				$lines[] = $days . ' ' . $prev;
			}
			$run = array();
		};
		foreach ( array_keys( $names ) as $day ) {
			$cur = isset( $spans[ $day ] ) ? implode( ', ', $spans[ $day ] ) : null;
			if ( $cur !== $prev || null === $cur ) {
				$flush();
			}
			if ( null !== $cur ) {
				$run[] = $day;
			}
			$prev = $cur;
		}
		$flush();
		return implode( "\n", $lines );
	}

	/** Google's values in the shape of the company data. */
	public static function as_company( array $place ) {
		$street = trim( self::component( $place, 'route' ) . ' ' . self::component( $place, 'street_number' ) );
		return array(
			'name'          => (string) ( $place['displayName']['text'] ?? '' ),
			'street'        => $street,
			'zip'           => self::component( $place, 'postal_code' ),
			'city'          => self::component( $place, 'locality' ) ?: self::component( $place, 'postal_town' ),
			'country'       => self::component( $place, 'country', true ),
			'phone'         => (string) ( $place['nationalPhoneNumber'] ?? '' ),
			'opening_hours' => isset( $place['regularOpeningHours'] ) ? self::hours_text( $place['regularOpeningHours'] ) : '',
		);
	}

	/* --------------------------------------------------------------- comparison */

	private static function norm_text( $s ) {
		$s = mb_strtolower( trim( (string) $s ) );
		$s = str_replace( array( 'strasse', 'str.', 'ß' ), array( 'straße', 'straße', 'ss' ), $s );
		return preg_replace( '/[\s\-.,]+/u', '', $s );
	}

	private static function norm_phone( $s ) {
		$d = preg_replace( '/\D+/', '', (string) $s );
		if ( 0 === strpos( (string) $s, '+' ) || 0 === strpos( $d, '00' ) ) {
			$d = ltrim( $d, '0' );
			$d = preg_match( '/^49/', $d ) ? '0' . substr( $d, 2 ) : $d;
		}
		return $d;
	}

	private static function norm_hours( $text ) {
		$specs = Plakette_Company_Schema::opening_hours( $text );
		$flat  = array();
		foreach ( $specs as $s ) {
			foreach ( $s['dayOfWeek'] as $day ) {
				$flat[] = $day . ' ' . $s['opens'] . '-' . $s['closes'];
			}
		}
		sort( $flat );
		return implode( '|', $flat );
	}

	/**
	 * Differences between company data and Google.
	 *
	 * @return array[] [ key, label, ours, google, checked ]
	 */
	public static function differences( array $google, array $ours ) {
		$labels = array(
			'name'          => __( 'Name', 'plakette' ),
			'street'        => __( 'Straße und Hausnummer', 'plakette' ),
			'zip'           => __( 'PLZ', 'plakette' ),
			'city'          => __( 'Ort', 'plakette' ),
			'phone'         => __( 'Telefon', 'plakette' ),
			'opening_hours' => __( 'Öffnungszeiten', 'plakette' ),
		);
		$out = array();
		foreach ( $labels as $key => $label ) {
			$g = trim( (string) ( $google[ $key ] ?? '' ) );
			$o = trim( (string) ( $ours[ $key ] ?? '' ) );
			if ( '' === $g ) {
				continue; // Google has nothing – no reason to change ours.
			}
			if ( 'phone' === $key ) {
				$same = self::norm_phone( $g ) === self::norm_phone( $o );
			} elseif ( 'opening_hours' === $key ) {
				$same = self::norm_hours( $g ) === self::norm_hours( $o );
			} else {
				$same = self::norm_text( $g ) === self::norm_text( $o );
			}
			if ( ! $same ) {
				// The name in the imprint is the legal name – Google often shows a brand. Not preselected.
				$out[] = array( 'key' => $key, 'label' => $label, 'ours' => $o, 'google' => $g, 'checked' => 'name' !== $key );
			}
		}
		return $out;
	}
}

add_action( 'plakette_boot', array( 'Plakette_Google_Profile', 'boot' ) );
