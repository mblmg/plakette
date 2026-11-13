<?php
/**
 * OpenStreetMap: the free counterpart of the Google Business Profile – without API key, without costs.
 *
 * - Reads the business object (node/way/relation) through the official OSM API (raw tags, version, coordinates).
 * - Finds the business through Nominatim when no link is set (name + address of the company data).
 * - Compares name, address, phone, opening hours and website with the company data (Plakette_Settings::company()).
 * - Feeds OSM: differences or a missing business go to the mappers as an OSM note (map note, no account needed) –
 *   the community way without an account. With a connected account Plakette_Osm_Edit writes the selected changes.
 * - Feeds the website's structured data: sameAs/hasMap link to the OSM object and geo coordinates.
 *
 * Usage policies: https://operations.osmfoundation.org/policies/api/ and …/nominatim/ – identifying User-Agent,
 * at most a few requests per audit, results cached (details 12 h, search 7 days).
 *
 * Settings: company.osm_url. Options: plakette_osm_place (resolved object + coordinates), plakette_osm_notes (sent notes).
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Osm_Profile {

	/** Option: resolved object { url, type, id, name, lat, lon }. */
	const PLACE = 'plakette_osm_place';

	/** Option: sent notes, newest first [ { id, ref, fields, text, time } ]. */
	const NOTES = 'plakette_osm_notes';

	/** Transient prefix (details 12 h, searches 7 days). */
	const CACHE = 'plakette_osm_';

	/** OSM day names (index 0 = Monday) and the German ones of the company data. */
	const DAYS_OSM = array( 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su' );
	const DAYS_DE  = array( 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So' );

	public static function boot() {
		add_action( 'plakette_settings_saved', array( __CLASS__, 'settings_saved' ) );
	}

	public static function settings_saved( $section ) {
		if ( 'company' === $section ) {
			self::forget();
		}
	}

	/** Drops cached details and – when the link changed – the resolved object. */
	public static function forget() {
		$place = get_option( self::PLACE );
		if ( is_array( $place ) && ! empty( $place['id'] ) ) {
			delete_transient( self::CACHE . 'el_' . $place['type'] . $place['id'] );
		}
		if ( is_array( $place ) && ( ! isset( $place['url'] ) || $place['url'] !== self::url() ) ) {
			delete_option( self::PLACE );
		}
	}

	/** Test mode: define( 'PLAKETTE_OSM_DEV', true ) in wp-config.php – reads, edits and notes use the OSM development server. */
	public static function dev() {
		return defined( 'PLAKETTE_OSM_DEV' ) && PLAKETTE_OSM_DEV;
	}

	/** Base of the OSM API (filter: tests use the OSM development server). */
	public static function api() {
		return trailingslashit( (string) apply_filters( 'plakette_osm_api', self::dev() ? 'https://master.apis.dev.openstreetmap.org/api/0.6/' : 'https://api.openstreetmap.org/api/0.6/' ) );
	}

	/** Where notes go (filter: tests send them to the OSM development server, never to the real map). */
	public static function notes_api() {
		return trailingslashit( (string) apply_filters( 'plakette_osm_notes_api', self::api() ) );
	}

	/** Public map address for notes. */
	public static function notes_site() {
		return 0 === strpos( self::notes_api(), 'https://api.openstreetmap.org/' ) ? 'https://www.openstreetmap.org/' : preg_replace( '#api/0\.6/$#', '', self::notes_api() );
	}

	public static function nominatim() {
		return trailingslashit( (string) apply_filters( 'plakette_osm_nominatim', 'https://nominatim.openstreetmap.org/' ) );
	}

	/** Public map address of the API in use (for links). */
	public static function site() {
		return 0 === strpos( self::api(), 'https://api.openstreetmap.org/' ) ? 'https://www.openstreetmap.org/' : preg_replace( '#api/0\.6/$#', '', self::api() );
	}

	/** Both usage policies ask for an identifying User-Agent. */
	private static function args( array $extra = array() ) {
		return array_merge(
			array(
				'timeout'    => 15,
				'user-agent' => 'Plakette/' . ( defined( 'PLAKETTE_VERSION' ) ? PLAKETTE_VERSION : '1' ) . ' (WordPress plugin; +https://github.com/mblmg/plakette; ' . home_url( '/' ) . ')',
				'headers'    => array( 'Accept-Language' => 'de' ),
			),
			$extra
		);
	}

	private static function get_json( $url ) {
		$res = wp_remote_get( $url, self::args() );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'plakette_osm', sprintf( __( 'OpenStreetMap war nicht erreichbar: %s', 'plakette' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 404 === $code || 410 === $code ) {
			return new WP_Error( 'plakette_osm_gone', sprintf( __( 'Das Objekt gibt es in OpenStreetMap nicht (mehr) (Antwort %1$d von %2$s).', 'plakette' ), $code, $url ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			return new WP_Error( 'plakette_osm', sprintf( __( 'OpenStreetMap hat die Anfrage abgelehnt (Antwort %1$d von %2$s).', 'plakette' ), $code, $url ) );
		}
		return $data;
	}

	/* ------------------------------------------------------------- the object */

	public static function url() {
		return trim( (string) Plakette_Settings::get( 'company', 'osm_url' ) );
	}

	/**
	 * Object reference from a link or short form: openstreetmap.org/node/123, osm.org/way/5, "n123", "way/5",
	 * "N123" (Nominatim). Links of the OSM development server count too.
	 *
	 * @return array|null [ type, id ]
	 */
	public static function parse( $url ) {
		$url = trim( (string) $url );
		if ( preg_match( '#^(?:https?://[^/]*(?:openstreetmap\.org|osm\.org)/(?:browse/)?)?(node|way|relation)/(\d+)\b#i', $url, $m ) ) {
			return array( 'type' => strtolower( $m[1] ), 'id' => (int) $m[2] );
		}
		if ( preg_match( '/^([nwr])\s*(\d+)$/i', $url, $m ) ) {
			$types = array( 'n' => 'node', 'w' => 'way', 'r' => 'relation' );
			return array( 'type' => $types[ strtolower( $m[1] ) ], 'id' => (int) $m[2] );
		}
		return null;
	}

	public static function link( $type, $id ) {
		return self::site() . $type . '/' . (int) $id;
	}

	/**
	 * One OSM object with tags and a coordinate (node: its position, way: centre of its nodes, relation: Nominatim).
	 * Cached 12 hours. @return array|WP_Error [ type, id, lat, lon, tags, version, timestamp, user, url, source ]
	 */
	public static function element( $type, $id ) {
		$key = self::CACHE . 'el_' . $type . (int) $id;
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$src  = self::api() . $type . '/' . (int) $id . ( 'way' === $type ? '/full' : '' ) . '.json';
		$data = self::get_json( $src );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$el  = null;
		$lat = array();
		$lon = array();
		foreach ( (array) ( $data['elements'] ?? array() ) as $e ) {
			if ( ( $e['type'] ?? '' ) === $type && (int) ( $e['id'] ?? 0 ) === (int) $id ) {
				$el = $e;
			} elseif ( 'node' === ( $e['type'] ?? '' ) && isset( $e['lat'], $e['lon'] ) ) {
				$lat[] = (float) $e['lat'];
				$lon[] = (float) $e['lon'];
			}
		}
		if ( ! $el ) {
			return new WP_Error( 'plakette_osm', sprintf( __( 'Unerwartete Antwort von %s.', 'plakette' ), $src ) );
		}
		if ( isset( $el['visible'] ) && false === $el['visible'] ) {
			return new WP_Error( 'plakette_osm_gone', sprintf( __( 'Das Objekt wurde in OpenStreetMap gelöscht (%s).', 'plakette' ), $src ) );
		}
		if ( 'node' === $type ) {
			$la = (float) ( $el['lat'] ?? 0 );
			$lo = (float) ( $el['lon'] ?? 0 );
		} elseif ( $lat ) {
			// Centre of the bounding box of the way.
			$la = ( min( $lat ) + max( $lat ) ) / 2;
			$lo = ( min( $lon ) + max( $lon ) ) / 2;
		} else {
			$look = self::get_json( self::nominatim() . 'lookup?format=jsonv2&osm_ids=' . strtoupper( $type[0] ) . (int) $id );
			$la   = is_array( $look ) && isset( $look[0]['lat'] ) ? (float) $look[0]['lat'] : null;
			$lo   = is_array( $look ) && isset( $look[0]['lon'] ) ? (float) $look[0]['lon'] : null;
		}
		$out = array(
			'type'      => $type,
			'id'        => (int) $id,
			'lat'       => null === $la ? null : round( $la, 7 ),
			'lon'       => null === $lo ? null : round( $lo, 7 ),
			'tags'      => (array) ( $el['tags'] ?? array() ),
			'version'   => (int) ( $el['version'] ?? 0 ),
			'timestamp' => (string) ( $el['timestamp'] ?? '' ),
			'user'      => (string) ( $el['user'] ?? '' ),
			'url'       => self::link( $type, $id ),
			'source'    => $src,
		);
		set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * The linked object, with its coordinates remembered for the structured data (no request on page views).
	 *
	 * @return array|WP_Error|null element, error, or null when no (valid) link is set
	 */
	public static function details() {
		$ref = self::parse( self::url() );
		if ( ! $ref ) {
			return null;
		}
		$el = self::element( $ref['type'], $ref['id'] );
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$place = array(
			'url'  => self::url(),
			'type' => $el['type'],
			'id'   => $el['id'],
			'name' => (string) ( $el['tags']['name'] ?? '' ),
			'lat'  => $el['lat'],
			'lon'  => $el['lon'],
		);
		if ( get_option( self::PLACE ) !== $place ) {
			update_option( self::PLACE, $place, false );
		}
		return $el;
	}

	/** Remembered object of the current link (for the structured data). @return array|null */
	public static function place() {
		$place = get_option( self::PLACE );
		return is_array( $place ) && isset( $place['url'] ) && $place['url'] === self::url() && '' !== self::url() ? $place : null;
	}

	/* ----------------------------------------------------------------- search */

	private static function norm_name( $s ) {
		$s = mb_strtolower( remove_accents( (string) $s ) );
		$s = preg_replace( '/\b(gmbh|ug|ag|kg|ohg|gbr|e\s*\.?\s*k\.?|e\s*\.?\s*v\.?|co|und|&|haftungsbeschränkt|inh\.?.*)\b/u', ' ', $s );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
	}

	/** Do two business names mean the same? (legal form, accents and order ignored) */
	public static function same_name( $a, $b ) {
		$a = self::norm_name( $a );
		$b = self::norm_name( $b );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b || str_contains( $a, $b ) || str_contains( $b, $a ) ) {
			return true;
		}
		similar_text( $a, $b, $pct );
		return $pct >= 80;
	}

	/** Name of the business for searches: company name, else the site title. */
	public static function company_name( array $c ) {
		return trim( (string) ( '' !== trim( (string) $c['name'] ) ? $c['name'] : ( $c['name_fallback'] ?? get_bloginfo( 'name' ) ) ) );
	}

	/**
	 * Candidates for the business in OSM (Nominatim free-text search name + address, filtered to objects whose name
	 * matches). Cached 7 days per query. @return array[]|WP_Error [ type, id, name, address, kind, url ]
	 */
	public static function search( array $c ) {
		$name = self::company_name( $c );
		$city = trim( (string) $c['city'] );
		if ( '' === $name || '' === $city ) {
			return array();
		}
		$params = array(
			'q'              => trim( $name . ', ' . $c['street'] . ', ' . $c['zip'] . ' ' . $city, ' ,' ),
			'format'         => 'jsonv2',
			'addressdetails' => 1,
			'limit'          => 10,
		);
		if ( preg_match( '/^[A-Z]{2}$/', (string) $c['country'] ) ) {
			$params['countrycodes'] = strtolower( $c['country'] );
		}
		$found = self::cached_search( $params );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( ! $found && '' !== trim( (string) $c['street'] ) ) {
			// Second try: name + city only (the street in OSM may be spelled differently).
			$params['q'] = $name . ', ' . $city;
			$found       = self::cached_search( $params );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
		}
		$out = array();
		foreach ( $found as $r ) {
			$type = (string) ( $r['osm_type'] ?? '' );
			if ( ! in_array( $type, array( 'node', 'way', 'relation' ), true ) || in_array( (string) ( $r['category'] ?? '' ), array( 'boundary', 'place', 'highway', 'landuse', 'natural', 'waterway', 'railway' ), true ) ) {
				continue;
			}
			if ( ! self::same_name( (string) ( $r['name'] ?? '' ), $name ) ) {
				continue;
			}
			$a     = (array) ( $r['address'] ?? array() );
			$out[] = array(
				'type'    => $type,
				'id'      => (int) $r['osm_id'],
				'name'    => (string) $r['name'],
				'address' => trim( trim( ( $a['road'] ?? '' ) . ' ' . ( $a['house_number'] ?? '' ) ) . ', ' . trim( ( $a['postcode'] ?? '' ) . ' ' . ( $a['city'] ?? $a['town'] ?? $a['village'] ?? '' ) ), ' ,' ),
				'kind'    => (string) ( $r['type'] ?? '' ),
				'url'     => self::link( $type, (int) $r['osm_id'] ),
			);
		}
		return array_slice( $out, 0, 5 );
	}

	private static function cached_search( array $params ) {
		$url = self::nominatim() . 'search?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$key = self::CACHE . 's_' . md5( $url );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$data = self::get_json( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		set_transient( $key, $data, 7 * DAY_IN_SECONDS );
		return $data;
	}

	/** Coordinates of the company address (for a note about a business that is missing). @return array|null [lat, lon, label] */
	public static function geocode( array $c ) {
		if ( '' === trim( (string) $c['street'] ) || '' === trim( (string) $c['city'] ) ) {
			return null;
		}
		$params = array(
			'street'     => $c['street'],
			'postalcode' => $c['zip'],
			'city'       => $c['city'],
			'format'     => 'jsonv2',
			'limit'      => 1,
		);
		if ( preg_match( '/^[A-Z]{2}$/', (string) $c['country'] ) ) {
			$params['countrycodes'] = strtolower( $c['country'] );
		}
		$found = self::cached_search( array_filter( $params, 'strlen' ) );
		if ( is_wp_error( $found ) || empty( $found[0]['lat'] ) ) {
			return null;
		}
		return array( 'lat' => (float) $found[0]['lat'], 'lon' => (float) $found[0]['lon'], 'label' => (string) ( $found[0]['display_name'] ?? '' ) );
	}

	/* ---------------------------------------------------------- opening hours */

	/**
	 * OSM opening_hours → day map (0 = Monday) with spans "HH:MM-HH:MM". Covers the usual business forms
	 * ("Mo-Fr 09:00-12:00,14:00-18:00; Sa 10:00-14:00; Su,PH off", "24/7", additional rules after ", ").
	 * Rules Plakette cannot express (months, weeks, sunrise, PH/SH times …) are left out and reported.
	 *
	 * @return array [ days => [ day => [spans] ], skipped => [rules], holidays => [rules] ]
	 */
	public static function parse_osm_hours( $value ) {
		$value = trim( (string) $value );
		$days  = array();
		$skip  = array();
		$hol   = array();
		if ( '' === $value ) {
			return array( 'days' => $days, 'skipped' => $skip, 'holidays' => $hol );
		}
		if ( '24/7' === $value ) {
			return array( 'days' => array_fill( 0, 7, array( '00:00-23:59' ) ), 'skipped' => $skip, 'holidays' => $hol );
		}
		$names = array_flip( self::DAYS_OSM );
		// Rules: separated by ";" (normal rule) or ", " before a day name (additional rule).
		$tokens = preg_split( '/\s*(;|(?<=\d\d|off|closed),(?=\s*(?:Mo|Tu|We|Th|Fr|Sa|Su|PH|SH)\b))\s*/', $value, -1, PREG_SPLIT_DELIM_CAPTURE );
		$sep    = ';';
		foreach ( $tokens as $i => $tok ) {
			if ( 1 === $i % 2 ) {
				$sep = $tok;
				continue;
			}
			$rule = trim( $tok );
			if ( '' === $rule ) {
				continue;
			}
			if ( ! preg_match( '/^((?:(?:Mo|Tu|We|Th|Fr|Sa|Su|PH|SH)(?:\s*-\s*(?:Mo|Tu|We|Th|Fr|Sa|Su))?\s*,?\s*)*)\s*(.*)$/', $rule, $m ) ) {
				$skip[] = $rule;
				continue;
			}
			$sel   = trim( $m[1], " ,\t" );
			$times = strtolower( trim( $m[2] ) );
			$set   = array();
			$is_ph = false;
			foreach ( '' === $sel ? array( 'Mo-Su' ) : preg_split( '/\s*,\s*/', $sel ) as $part ) {
				if ( 'PH' === $part || 'SH' === $part ) {
					$is_ph = true;
					continue;
				}
				$r = array_map( 'trim', explode( '-', $part ) );
				$a = $names[ $r[0] ];
				$b = isset( $r[1] ) ? $names[ $r[1] ] : $a;
				for ( $d = $a; ; $d = ( $d + 1 ) % 7 ) {
					$set[] = $d;
					if ( $d === $b ) {
						break;
					}
				}
			}
			if ( $is_ph && ! $set ) {
				$hol[] = $rule;
				continue;
			}
			if ( $is_ph ) {
				$hol[] = $rule;
			}
			if ( in_array( $times, array( 'off', 'closed' ), true ) ) {
				foreach ( $set as $d ) {
					unset( $days[ $d ] );
				}
				continue;
			}
			if ( ! preg_match_all( '/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/', $times, $sp, PREG_SET_ORDER ) || '' !== trim( preg_replace( '/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})|[,\s]/', '', $times ) ) ) {
				$skip[] = $rule;
				continue;
			}
			$spans = array();
			foreach ( $sp as $s ) {
				$close   = sprintf( '%02d:%02d', (int) $s[3], (int) $s[4] );
				$spans[] = sprintf( '%02d:%02d', (int) $s[1], (int) $s[2] ) . '-' . ( in_array( $close, array( '24:00', '00:00' ), true ) ? '23:59' : $close );
			}
			foreach ( $set as $d ) {
				$days[ $d ] = ',' === $sep && isset( $days[ $d ] ) ? array_merge( $days[ $d ], $spans ) : $spans;
			}
		}
		ksort( $days );
		return array( 'days' => $days, 'skipped' => $skip, 'holidays' => $hol );
	}

	private static function sorted_days( array $days ) {
		foreach ( $days as &$spans ) {
			$spans = array_values( array_unique( $spans ) );
			sort( $spans );
		}
		ksort( $days );
		return $days;
	}

	/** Company-data text (German, one rule per line) → day map. */
	public static function text_days( $text ) {
		$index = array( 'Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 'Thursday' => 3, 'Friday' => 4, 'Saturday' => 5, 'Sunday' => 6 );
		$days  = array();
		foreach ( Plakette_Company_Schema::opening_hours( $text ) as $spec ) {
			foreach ( $spec['dayOfWeek'] as $day ) {
				$days[ $index[ $day ] ][] = $spec['opens'] . '-' . $spec['closes'];
			}
		}
		ksort( $days );
		return $days;
	}

	/**
	 * Day map → text. OSM: "Mo-Fr 09:00-17:00; Sa 10:00-14:00"; German: one line per group ("Mo-Fr 09:00-17:00").
	 * Consecutive days with the same hours are grouped.
	 */
	public static function format_days( array $days, $osm = true ) {
		$names = $osm ? self::DAYS_OSM : self::DAYS_DE;
		$rules = array();
		$run   = array();
		$prev  = null;
		for ( $d = 0; $d <= 7; $d++ ) {
			$cur = $d < 7 && isset( $days[ $d ] ) ? implode( $osm ? ',' : ', ', $days[ $d ] ) : null;
			if ( $run && ( $cur !== $prev || null === $cur ) ) {
				$label   = count( $run ) > 2 ? $names[ $run[0] ] . '-' . $names[ end( $run ) ] : implode( ',', array_map( fn( $x ) => $names[ $x ], $run ) );
				$rules[] = $label . ' ' . $prev;
				$run     = array();
			}
			if ( null !== $cur ) {
				$run[] = $d;
			}
			$prev = $cur;
		}
		return implode( $osm ? '; ' : "\n", $rules );
	}

	/* ----------------------------------------------------------- comparison */

	/** First value of a tag list ("a;b") among several keys. */
	private static function tag( array $tags, array $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $tags[ $k ] ) && '' !== trim( (string) $tags[ $k ] ) ) {
				return trim( explode( ';', (string) $tags[ $k ] )[0] );
			}
		}
		return '';
	}

	/** OSM values in the shape of the company data (+ website, raw opening_hours). */
	public static function as_company( array $el ) {
		$t     = (array) $el['tags'];
		$hours = self::parse_osm_hours( (string) ( $t['opening_hours'] ?? '' ) );
		return array(
			'name'          => (string) ( $t['name'] ?? '' ),
			'street'        => trim( self::tag( $t, array( 'addr:street', 'addr:place' ) ) . ' ' . self::tag( $t, array( 'addr:housenumber' ) ) ),
			'zip'           => self::tag( $t, array( 'addr:postcode' ) ),
			'city'          => self::tag( $t, array( 'addr:city' ) ),
			'country'       => strtoupper( self::tag( $t, array( 'addr:country' ) ) ),
			'phone'         => self::tag( $t, array( 'phone', 'contact:phone', 'contact:mobile', 'mobile' ) ),
			'email'         => self::tag( $t, array( 'email', 'contact:email' ) ),
			'website'       => self::tag( $t, array( 'website', 'contact:website', 'url' ) ),
			'opening_hours' => self::format_days( $hours['days'], false ),
			'hours_raw'     => (string) ( $t['opening_hours'] ?? '' ),
			'hours_info'    => $hours,
		);
	}

	private static function norm_text( $s ) {
		$s = mb_strtolower( trim( (string) $s ) );
		$s = str_replace( array( 'strasse', 'str.', 'ß' ), array( 'straße', 'straße', 'ss' ), $s );
		return preg_replace( '/[\s\-.,]+/u', '', $s );
	}

	/** Phone digits in national form (+49 761 … = 0761 …). */
	public static function norm_phone( $s, $country = 'DE' ) {
		$d    = preg_replace( '/\D+/', '', (string) $s );
		$code = self::calling_code( $country );
		if ( 0 === strpos( trim( (string) $s ), '+' ) || 0 === strpos( $d, '00' ) ) {
			$d = ltrim( $d, '0' );
			$d = '' !== $code && 0 === strpos( $d, $code ) ? '0' . substr( $d, strlen( $code ) ) : '+' . $d;
		}
		return $d;
	}

	private static function calling_code( $country ) {
		$codes = array( 'DE' => '49', 'AT' => '43', 'CH' => '41', 'LI' => '423', 'LU' => '352', 'NL' => '31', 'BE' => '32', 'FR' => '33', 'IT' => '39', 'DK' => '45', 'PL' => '48', 'CZ' => '420' );
		return $codes[ strtoupper( (string) $country ) ] ?? '';
	}

	/** Phone in the OSM convention (international, E.123): "0761 4791031" → "+49 761 4791031". */
	public static function intl_phone( $s, $country = 'DE' ) {
		$s    = trim( preg_replace( '/[^\d+ ]+/', ' ', (string) $s ) );
		$s    = trim( preg_replace( '/\s+/', ' ', $s ) );
		$code = self::calling_code( $country );
		if ( 0 === strpos( $s, '00' ) ) {
			return '+' . substr( $s, 2 );
		}
		if ( '' !== $code && preg_match( '/^0(?!0)/', $s ) ) {
			return '+' . $code . ' ' . ltrim( substr( $s, 1 ) );
		}
		return $s;
	}

	private static function norm_host( $url ) {
		return strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( (string) $url, PHP_URL_HOST ) ) );
	}

	/**
	 * Field-by-field comparison. Each difference knows both values and the OSM tag(s) that would carry ours.
	 *
	 * @return array[] [ key, label, ours, osm, tags (tag => value for OSM) ]
	 */
	public static function differences( array $osm, array $ours ) {
		$country = preg_match( '/^[A-Z]{2}$/', (string) $ours['country'] ) ? $ours['country'] : 'DE';
		$labels  = array(
			'name'          => __( 'Name', 'plakette' ),
			'street'        => __( 'Straße und Hausnummer', 'plakette' ),
			'zip'           => __( 'PLZ', 'plakette' ),
			'city'          => __( 'Ort', 'plakette' ),
			'phone'         => __( 'Telefon', 'plakette' ),
			'opening_hours' => __( 'Öffnungszeiten', 'plakette' ),
			'website'       => __( 'Website', 'plakette' ),
		);
		// Only a public address is worth reporting (not a staging site like *.localhost, *.test or an IP).
		$host            = self::norm_host( home_url( '/' ) );
		$ours['website'] = preg_match( '/(^|\.)(localhost|local|test|example|invalid|internal|lan)$|^[\d.]+$|^\[|^[^.]+$/', $host ) ? '' : home_url( '/' );
		if ( '' === $ours['website'] ) {
			unset( $labels['website'] );
		}
		$out             = array();
		foreach ( $labels as $key => $label ) {
			$o = trim( (string) ( $ours[ $key ] ?? '' ) );
			$g = trim( (string) ( $osm[ $key ] ?? '' ) );
			if ( '' === $o && '' === $g ) {
				continue;
			}
			switch ( $key ) {
				case 'phone':
					$same = self::norm_phone( $g, $country ) === self::norm_phone( $o, $country );
					break;
				case 'opening_hours':
					$same = self::sorted_days( self::text_days( $o ) ) === self::sorted_days( $osm['hours_info']['days'] );
					break;
				case 'website':
					$same = '' !== $g && self::norm_host( $g ) === self::norm_host( $o );
					break;
				case 'name':
					$same = self::same_name( $g, $o );
					break;
				default:
					$same = self::norm_text( $g ) === self::norm_text( $o );
			}
			if ( $same ) {
				continue;
			}
			$tags = array();
			if ( '' !== $o ) {
				switch ( $key ) {
					case 'street':
						if ( preg_match( '/^(.*?)\s+(\d+\s*[a-z]?(?:\s*[-\/]\s*\d+\s*[a-z]?)?)$/iu', $o, $m ) ) {
							$tags = array( 'addr:street' => $m[1], 'addr:housenumber' => str_replace( ' ', '', $m[2] ) );
						} else {
							$tags = array( 'addr:street' => $o );
						}
						break;
					case 'zip':
						$tags = array( 'addr:postcode' => $o );
						break;
					case 'city':
						$tags = array( 'addr:city' => $o );
						break;
					case 'phone':
						$tags = array( 'phone' => self::intl_phone( $o, $country ) );
						break;
					case 'opening_hours':
						$tags = array( 'opening_hours' => self::format_days( self::text_days( $o ), true ) );
						break;
					default:
						$tags = array( $key => $o );
				}
			}
			$out[] = array( 'key' => $key, 'label' => $label, 'ours' => $o, 'osm' => $g, 'tags' => $tags );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ notes */

	/** Sent notes (newest first). */
	public static function notes() {
		$n = get_option( self::NOTES, array() );
		return is_array( $n ) ? $n : array();
	}

	/** The last note about the current object/business, with its state from OSM (cached 6 h). */
	public static function last_note( $ref ) {
		foreach ( self::notes() as $note ) {
			if ( ( $note['ref'] ?? '' ) === $ref && 0 === strpos( (string) ( $note['api'] ?? self::notes_api() ), self::notes_api() ) ) {
				$key   = self::CACHE . 'note_' . (int) $note['id'];
				$state = get_transient( $key );
				if ( ! is_string( $state ) ) {
					$data  = self::get_json( self::notes_api() . 'notes/' . (int) $note['id'] . '.json' );
					$state = is_wp_error( $data ) ? 'unknown' : (string) ( $data['properties']['status'] ?? 'unknown' );
					set_transient( $key, $state, 6 * HOUR_IN_SECONDS );
				}
				$note['status'] = $state;
				$note['url']    = self::notes_site() . 'note/' . (int) $note['id'];
				return $note;
			}
		}
		return null;
	}

	/**
	 * Text of a note for the mappers: who reports, what should change (as OSM tags), and the source.
	 *
	 * @param array       $tags tag => value
	 * @param string|null $ref  object link, null = business is missing in OSM
	 */
	public static function note_text( array $c, array $tags, $ref, array $fixes = array() ) {
		$name  = self::company_name( $c );
		$lines = array();
		if ( $ref ) {
			$lines[] = sprintf( 'Angaben des Unternehmens „%1$s“ (%2$s) weichen von OpenStreetMap ab. Bitte prüfen und ggf. übernehmen:', $name, $ref );
		} else {
			$lines[] = sprintf( 'Das Unternehmen „%s“ fehlt hier in OpenStreetMap. Angaben des Unternehmens:', $name );
		}
		$lines[] = '';
		foreach ( $tags as $k => $v ) {
			$lines[] = $k . '=' . $v;
		}
		foreach ( $fixes as $line ) {
			$lines[] = $line;
		}
		$imprint = class_exists( 'Plakette_Legal' ) ? Plakette_Legal::imprint_page() : 0;
		$lines[] = '';
		$lines[] = 'Quelle: Website des Unternehmens, ' . ( $imprint ? get_permalink( $imprint ) : home_url( '/' ) ) . ' (Stand ' . wp_date( 'Y-m-d' ) . ').';
		$lines[] = 'Gemeldet über Plakette (WordPress-Plugin) im Auftrag des Website-Betreibers.';
		return implode( "\n", $lines );
	}

	/** Opens an anonymous OSM note. @return array|WP_Error the stored note */
	public static function send_note( $lat, $lon, $text, $ref, array $fields ) {
		$res = wp_remote_post(
			self::notes_api() . 'notes.json',
			self::args(
				array(
					// With a connected account the note carries the owner's name – mappers can ask back.
					'headers' => array_filter(
						array(
							'Accept-Language' => 'de',
							'Authorization'   => class_exists( 'Plakette_Osm_Edit' ) && Plakette_Osm_Edit::account() && self::notes_api() === self::api() ? 'Bearer ' . Plakette_Osm_Edit::account()['token'] : null,
						)
					),
					'body' => array(
						'lat'  => sprintf( '%.7F', (float) $lat ),
						'lon'  => sprintf( '%.7F', (float) $lon ),
						'text' => $text,
					),
				)
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'plakette_osm', sprintf( __( 'OpenStreetMap war nicht erreichbar: %s', 'plakette' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 || empty( $data['properties']['id'] ) ) {
			return new WP_Error( 'plakette_osm', sprintf( __( 'OpenStreetMap hat den Hinweis nicht angenommen (Antwort %1$d): %2$s', 'plakette' ), $code, wp_strip_all_tags( substr( (string) wp_remote_retrieve_body( $res ), 0, 200 ) ) ) );
		}
		$note = array(
			'id'     => (int) $data['properties']['id'],
			'ref'    => (string) $ref,
			'fields' => array_values( $fields ),
			'text'   => $text,
			'time'   => time(),
			'api'    => self::notes_api(),
		);
		$all = self::notes();
		array_unshift( $all, $note );
		update_option( self::NOTES, array_slice( $all, 0, 20 ), false );
		return $note;
	}
}

add_action( 'plakette_boot', array( 'Plakette_Osm_Profile', 'boot' ) );
