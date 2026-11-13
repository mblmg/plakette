<?php
/**
 * OpenStreetMap: is the business in the free world map, and do its details match the company data?
 * Reads through Plakette_Osm_Profile (no key), takes OSM values over (undoable) or reports corrections to the
 * OSM community as a map note (final – mappers take it over by hand).
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Check_Osm_Profile extends Plakette_Check {
	public $id        = 'seo_osm_profile';
	public $area      = 'seo';
	public $group     = 'Strukturierte Daten';
	public $weight    = 4;
	public $title     = 'Firmendaten stimmen mit OpenStreetMap überein';
	public $problem   = 'Firmendaten weichen von OpenStreetMap ab';
	public $explain   = 'OpenStreetMap ist die freie Weltkarte, gepflegt von Freiwilligen. Viele Karten- und Navi-Apps (z. B. OsmAnd, Organic Maps, Komoot, Magic Earth) und Dienste wie Apple Maps nutzen ihre Daten. Stimmen dort Adresse, Telefon, Öffnungszeiten und Website, finden dich Kundinnen und Kunden auch abseits von Google.';
	public $fix_label = 'Abgleichen';
	public $fix_does  = 'Ausgewählte Angaben „an OpenStreetMap melden“ schickt einen Hinweis (Kartenhinweis) mit den Angaben deiner Website an die OpenStreetMap-Community – Freiwillige prüfen ihn und tragen ihn ein. „Aus OpenStreetMap übernehmen“ ändert die Firmendaten von Plakette.';
	public $risk      = 'review';
	public $kind      = 'items';

	private static function note_warning() {
		return __( 'Hinweise an OpenStreetMap sind öffentlich und lassen sich nicht zurückholen. Sie enthalten nur Firmenangaben, die ohnehin im Impressum stehen.', 'plakette' );
	}

	public function run( Plakette_Context $ctx ) {
		$c    = Plakette_Settings::company();
		$name = Plakette_Osm_Profile::company_name( $c );
		$url  = Plakette_Osm_Profile::url();
		if ( '' === $url ) {
			return $this->unlinked( $c, $name );
		}
		$ref = Plakette_Osm_Profile::parse( $url );
		if ( ! $ref ) {
			return $this->warn(
				sprintf( __( 'Der eingetragene OpenStreetMap-Link „%s“ zeigt auf kein Objekt.', 'plakette' ), $url ),
				array( 'kind' => 'none', 'blocked' => __( 'Trag unter Einstellungen › Firmendaten › OpenStreetMap-Eintrag die Adresse des Objekts ein (z. B. https://www.openstreetmap.org/node/123456) oder lass das Feld leer – dann sucht Plakette selbst.', 'plakette' ) )
			);
		}
		$el = Plakette_Osm_Profile::details();
		if ( is_wp_error( $el ) ) {
			if ( 'plakette_osm_gone' === $el->get_error_code() ) {
				return $this->warn( __( 'Der verknüpfte OpenStreetMap-Eintrag existiert nicht mehr.', 'plakette' ), array( 'kind' => 'none', 'notes' => array( $el->get_error_message() ), 'blocked' => __( 'Leere das Feld „OpenStreetMap-Eintrag“ in den Firmendaten – dann sucht Plakette dein Unternehmen neu.', 'plakette' ) ) );
			}
			return $this->skip( __( 'Abgleich mit OpenStreetMap gerade nicht möglich: ', 'plakette' ) . $el->get_error_message() );
		}

		$osm   = Plakette_Osm_Profile::as_company( $el );
		$diffs = Plakette_Osm_Profile::differences( $osm, $c );
		$notes = array(
			/* translators: 1: name, 2: API address, 3: version, 4: date */
			sprintf( __( 'Abgefragt: „%1$s“ – %2$s (Version %3$d vom %4$s).', 'plakette' ), $osm['name'], $el['source'], $el['version'], $el['timestamp'] ? wp_date( 'j. n. Y', strtotime( $el['timestamp'] ) ) : '?' ),
		);
		if ( '' !== $osm['hours_raw'] ) {
			$notes[] = sprintf( __( 'Öffnungszeiten in OpenStreetMap: %s', 'plakette' ), $osm['hours_raw'] );
		}
		if ( $osm['hours_info']['skipped'] ) {
			$notes[] = sprintf( __( 'Diese Regeln der Öffnungszeiten kann Plakette nicht abbilden und lässt sie beim Vergleich weg: %s', 'plakette' ), implode( '; ', $osm['hours_info']['skipped'] ) );
		}
		$links = array(
			array( 'label' => __( 'Eintrag auf openstreetmap.org', 'plakette' ), 'url' => $el['url'] ),
			array( 'label' => __( 'Selbst bearbeiten (OSM-Konto nötig)', 'plakette' ), 'url' => Plakette_Osm_Profile::site() . 'edit?' . $el['type'] . '=' . $el['id'] ),
		);

		// A note about this object that the mappers have not handled yet: don't report the same again.
		$pending = array();
		$note    = Plakette_Osm_Profile::last_note( $el['url'] );
		if ( $note ) {
			$when = wp_date( 'j. n. Y', (int) $note['time'] );
			if ( 'open' === $note['status'] ) {
				$pending = (array) $note['fields'];
				$notes[] = sprintf( __( 'Hinweis Nr. %1$d vom %2$s wartet noch auf Bearbeitung durch die OpenStreetMap-Community.', 'plakette' ), $note['id'], $when );
			} elseif ( 'closed' === $note['status'] ) {
				$notes[] = sprintf( __( 'Hinweis Nr. %1$d vom %2$s wurde in OpenStreetMap bearbeitet.', 'plakette' ), $note['id'], $when );
			}
			$links[] = array( 'label' => sprintf( __( 'Hinweis Nr. %d', 'plakette' ), $note['id'] ), 'url' => $note['url'] );
		}

		$keys = wp_list_pluck( $diffs, 'key' );
		if ( ! $diffs || array( 'name' ) === $keys ) {
			if ( $diffs ) {
				$notes[] = sprintf( __( 'Der Name weicht ab (Website: „%1$s“, OpenStreetMap: „%2$s“). Das ist in Ordnung, wenn in den Firmendaten der Name fürs Impressum steht.', 'plakette' ), $diffs[0]['ours'], $diffs[0]['osm'] );
			}
			return $this->pass( __( 'Adresse, Telefon, Öffnungszeiten und Website stimmen mit OpenStreetMap überein.', 'plakette' ), array( 'notes' => $notes, 'links' => $links ) );
		}

		$items   = array();
		$waiting = 0;
		$edit    = Plakette_Osm_Edit::connected();
		if ( ! $edit ) {
			$notes[] = __( 'Tipp: Verbinde Plakette unter Einstellungen › OpenStreetMap mit deinem OpenStreetMap-Konto. Dann trägt Plakette Korrekturen direkt in die Karte ein, statt Freiwillige per Hinweis darum zu bitten.', 'plakette' );
		} else {
			$notes[] = sprintf( __( 'Änderungen trägt Plakette unter deinem OpenStreetMap-Namen „%s“ ein – öffentlich sichtbar, im Protokoll zurücknehmbar, solange niemand den Eintrag weiter bearbeitet hat.', 'plakette' ), Plakette_Osm_Edit::account()['user'] );
		}
		foreach ( $diffs as $d ) {
			$sub = sprintf(
				__( 'Website: %1$s · OpenStreetMap: %2$s', 'plakette' ),
				'' !== $d['ours'] ? str_replace( "\n", ' / ', $d['ours'] ) : __( '(leer)', 'plakette' ),
				'' !== $d['osm'] ? str_replace( "\n", ' / ', 'opening_hours' === $d['key'] ? $osm['hours_raw'] : $d['osm'] ) : __( '(fehlt)', 'plakette' )
			);
			if ( in_array( $d['key'], $pending, true ) ) {
				++$waiting; // Reported, waits for the mappers – no second report, no take-over meanwhile.
				continue;
			}
			// Connected: write straight into the map (not the name – OSM wants the name on the sign, not the legal one).
			$change = $edit && 'name' !== $d['key'] ? Plakette_Osm_Edit::changes( array( $d['key'] => $d ), $el['tags'] ) : array();
			if ( $change ) {
				$items[] = array(
					'id'      => 'edit:' . $d['key'],
					'label'   => sprintf( __( '%s in OpenStreetMap eintragen', 'plakette' ), $d['label'] ),
					'sub'     => $sub . ' → ' . implode( ', ', array_map( fn( $k, $v ) => $k . '=' . $v, array_keys( $change ), $change ) ),
					'value'   => '',
					'checked' => true,
				);
			} elseif ( $d['tags'] ) {
				$items[] = array(
					'id'      => 'to:' . $d['key'],
					'label'   => sprintf( __( '%s an OpenStreetMap melden', 'plakette' ), $d['label'] ),
					'sub'     => $sub . ' → ' . implode( ', ', array_map( fn( $k, $v ) => $k . '=' . $v, array_keys( $d['tags'] ), $d['tags'] ) ),
					'value'   => '',
					'checked' => 'name' !== $d['key'],
				);
			}
			if ( '' !== $d['osm'] && 'website' !== $d['key'] ) {
				$items[] = array(
					'id'      => 'from:' . $d['key'],
					'label'   => sprintf( __( '%s aus OpenStreetMap übernehmen', 'plakette' ), $d['label'] ),
					'sub'     => $sub,
					'value'   => '',
					'checked' => '' === $d['ours'],
				);
			}
		}
		if ( in_array( 'name', $keys, true ) ) {
			$notes[] = __( 'Der Name ist nicht vorausgewählt: In den Firmendaten steht der Name fürs Impressum (z. B. mit Rechtsform), in OpenStreetMap der Name auf dem Schild.', 'plakette' );
		}
		if ( ! $items ) {
			return $this->pass( __( 'Die Abweichungen sind an OpenStreetMap gemeldet und warten auf Bearbeitung.', 'plakette' ), array( 'notes' => $notes, 'links' => $links ) );
		}
		$count = count( $diffs ) - $waiting;
		return $this->warn(
			sprintf( _n( '%d Angabe weicht von OpenStreetMap ab.', '%d Angaben weichen von OpenStreetMap ab.', $count, 'plakette' ), $count ),
			array_merge(
				array( 'items' => $items, 'notes' => $notes, 'links' => $links ),
				$edit ? array( 'fix_does' => __( '„In OpenStreetMap eintragen“ ändert deinen Eintrag in der freien Weltkarte direkt – unter deinem OpenStreetMap-Konto, nur die gezeigten Angaben. „Aus OpenStreetMap übernehmen“ ändert die Firmendaten von Plakette.', 'plakette' ) ) : array( 'undo_note' => self::note_warning() )
			)
		);
	}

	/** No link yet: search the business, offer to link it – or report it as missing. */
	private function unlinked( array $c, $name ) {
		if ( '' === trim( (string) $c['city'] ) ) {
			return $this->skip( __( 'Für den Abgleich mit OpenStreetMap braucht Plakette die Firmendaten (mindestens Name und Ort, siehe „Firmenprofil für Google“).', 'plakette' ) );
		}
		$found = Plakette_Osm_Profile::search( $c );
		if ( is_wp_error( $found ) ) {
			return $this->skip( __( 'Suche in OpenStreetMap gerade nicht möglich: ', 'plakette' ) . $found->get_error_message() );
		}
		if ( $found ) {
			$items = array();
			foreach ( $found as $i => $f ) {
				$items[] = array(
					'id'      => 'link:' . $f['type'] . '/' . $f['id'],
					'label'   => $f['name'],
					'sub'     => trim( $f['address'] . ' · ' . $f['kind'] . ' · ' . $f['type'] . '/' . $f['id'], ' ·' ),
					'link'    => $f['url'],
					'value'   => '',
					'checked' => 0 === $i,
				);
			}
			return $this->warn(
				__( 'Dein Unternehmen steht in OpenStreetMap, ist aber noch nicht mit der Website verknüpft.', 'plakette' ),
				array(
					'weight'    => 3,
					'problem'   => __( 'OpenStreetMap-Eintrag nicht verknüpft', 'plakette' ),
					'items'     => $items,
					'fix_label' => __( 'Eintrag verknüpfen', 'plakette' ),
					'fix_does'  => __( 'Speichert den ausgewählten Eintrag in den Firmendaten (Feld „OpenStreetMap-Eintrag“). Plakette verlinkt ihn dann in den strukturierten Daten (sameAs, Koordinaten) und gleicht danach Adresse, Telefon, Öffnungszeiten und Website ab. Wähle genau einen Eintrag.', 'plakette' ),
					'notes'     => array( sprintf( __( 'Gesucht mit Nominatim (Suchdienst von OpenStreetMap) nach „%1$s“ in %2$s.', 'plakette' ), $name, $c['city'] ) ),
				)
			);
		}

		$ref  = 'new:' . md5( mb_strtolower( $name . '|' . $c['street'] . '|' . $c['city'] ) );
		$note = Plakette_Osm_Profile::last_note( $ref );
		if ( $note && 'open' === $note['status'] ) {
			return $this->warn(
				sprintf( __( 'Dein Unternehmen fehlt in OpenStreetMap. Plakette hat es am %s gemeldet – Freiwillige tragen es von Hand ein, das dauert oft ein paar Tage bis Wochen.', 'plakette' ), wp_date( 'j. n. Y', (int) $note['time'] ) ),
				array(
					'weight' => 1,
					'kind'   => 'none',
					'links'  => array( array( 'label' => sprintf( __( 'Hinweis Nr. %d', 'plakette' ), $note['id'] ), 'url' => $note['url'] ) ),
				)
			);
		}
		$geo   = Plakette_Osm_Profile::geocode( $c );
		$extra = array(
			'problem'   => __( 'Unternehmen fehlt in OpenStreetMap', 'plakette' ),
			'kind'      => 'auto',
			'fix_label' => __( 'An OpenStreetMap melden', 'plakette' ),
			'fix_does'  => __( 'Schickt einen Kartenhinweis an die OpenStreetMap-Community: an deiner Adresse, mit Name, Adresse, Telefon, Öffnungszeiten und Website aus den Firmendaten. Freiwillige prüfen ihn und tragen dein Unternehmen ein. Steht dein Unternehmen doch schon drin, trag stattdessen den Link unter Einstellungen › Firmendaten ein.', 'plakette' ),
			'undoable'  => false,
			'undo_note' => self::note_warning(),
			'notes'     => array( sprintf( __( 'Gesucht mit Nominatim (Suchdienst von OpenStreetMap) nach „%1$s“ in %2$s – kein passender Eintrag.', 'plakette' ), $name, $c['city'] ) ),
		);
		if ( $note && 'closed' === $note['status'] ) {
			$extra['notes'][] = sprintf( __( 'Dein Hinweis Nr. %1$d vom %2$s ist erledigt, das Unternehmen wird aber noch nicht gefunden (der Suchindex braucht manchmal ein paar Tage). Steht es schon in der Karte, trag den Link in den Firmendaten ein.', 'plakette' ), $note['id'], wp_date( 'j. n. Y', (int) $note['time'] ) );
		}
		if ( ! $geo ) {
			$extra['kind']    = 'none';
			$extra['blocked'] = __( 'Plakette findet deine Adresse nicht in OpenStreetMap und weiß darum nicht, wo der Hinweis hin soll. Prüfe Straße, PLZ und Ort in den Firmendaten.', 'plakette' );
		} else {
			$extra['notes'][] = sprintf( __( 'Der Hinweis würde hier gesetzt: %s', 'plakette' ), $geo['label'] );
			$extra['links']   = array( array( 'label' => __( 'Selbst eintragen (OSM-Konto nötig)', 'plakette' ), 'url' => sprintf( '%sedit#map=19/%.6F/%.6F', Plakette_Osm_Profile::site(), $geo['lat'], $geo['lon'] ) ) );
		}
		return $this->warn( __( 'Dein Unternehmen ist in OpenStreetMap nicht zu finden.', 'plakette' ), $extra );
	}

	/** All values the website would contribute for a new business, as OSM tags. */
	private static function new_tags( array $c ) {
		$empty = array( 'name' => '', 'street' => '', 'zip' => '', 'city' => '', 'country' => '', 'phone' => '', 'email' => '', 'website' => '', 'opening_hours' => '', 'hours_raw' => '', 'hours_info' => array( 'days' => array(), 'skipped' => array(), 'holidays' => array() ) );
		$tags  = array( 'name' => Plakette_Osm_Profile::company_name( $c ) );
		foreach ( Plakette_Osm_Profile::differences( $empty, $c ) as $d ) {
			if ( 'name' !== $d['key'] ) {
				$tags += $d['tags'];
			}
		}
		if ( '' !== trim( (string) $c['email'] ) ) {
			$tags['email'] = trim( (string) $c['email'] );
		}
		return $tags;
	}

	public function fix( array $req, array &$undo ) {
		$c     = Plakette_Settings::company();
		$items = (array) ( $req['items'] ?? array() );
		$ids   = array_map( fn( $i ) => (string) ( $i['id'] ?? '' ), $items );

		// Link a found object.
		$links = preg_grep( '#^link:(node|way|relation)/\d+$#', $ids );
		if ( $links ) {
			if ( count( $links ) > 1 ) {
				return new WP_Error( 'plakette_osm', __( 'Bitte wähle genau einen Eintrag aus.', 'plakette' ) );
			}
			$ref = Plakette_Osm_Profile::parse( substr( reset( $links ), 5 ) );
			$el  = Plakette_Osm_Profile::element( $ref['type'], $ref['id'] );
			if ( is_wp_error( $el ) ) {
				return $el;
			}
			Plakette_Settings::save( 'company', array( 'osm_url' => $el['url'] ), $undo );
			Plakette_Osm_Profile::details();
			if ( ! Plakette_Modules::on( 'company_schema' ) ) {
				Plakette_Log::set_module( 'company_schema', true, $undo );
			}
			return sprintf( __( 'OpenStreetMap-Eintrag „%s“ verknüpft.', 'plakette' ), (string) ( $el['tags']['name'] ?? $el['url'] ) );
		}

		// Business missing: one note at the address.
		if ( '' === Plakette_Osm_Profile::url() && ! $items ) {
			$geo = Plakette_Osm_Profile::geocode( $c );
			if ( ! $geo ) {
				return new WP_Error( 'plakette_osm', __( 'Deine Adresse wurde in OpenStreetMap nicht gefunden – prüfe Straße, PLZ und Ort in den Firmendaten.', 'plakette' ) );
			}
			$name = Plakette_Osm_Profile::company_name( $c );
			$ref  = 'new:' . md5( mb_strtolower( $name . '|' . $c['street'] . '|' . $c['city'] ) );
			$note = Plakette_Osm_Profile::send_note( $geo['lat'], $geo['lon'], Plakette_Osm_Profile::note_text( $c, self::new_tags( $c ), null ), $ref, array( 'new' ) );
			if ( is_wp_error( $note ) ) {
				return $note;
			}
			return sprintf( __( 'Hinweis Nr. %d an OpenStreetMap geschickt. Freiwillige tragen dein Unternehmen ein – Plakette zeigt hier, wenn es erledigt ist.', 'plakette' ), $note['id'] );
		}

		// Linked object: take OSM values over and/or report ours.
		$el = Plakette_Osm_Profile::details();
		if ( ! is_array( $el ) ) {
			return is_wp_error( $el ) ? $el : new WP_Error( 'plakette_osm', __( 'Kein OpenStreetMap-Eintrag verknüpft.', 'plakette' ) );
		}
		$osm   = Plakette_Osm_Profile::as_company( $el );
		$diffs = array();
		foreach ( Plakette_Osm_Profile::differences( $osm, $c ) as $d ) {
			$diffs[ $d['key'] ] = $d;
		}
		$take  = array();
		$send  = array();
		$write = array();
		foreach ( $ids as $id ) {
			if ( ! preg_match( '/^(to|from|edit):(\w+)$/', $id, $m ) || ! isset( $diffs[ $m[2] ] ) ) {
				continue;
			}
			if ( 'from' === $m[1] && '' !== $diffs[ $m[2] ]['osm'] && 'website' !== $m[2] ) {
				$take[ $m[2] ] = $diffs[ $m[2] ]['osm'];
			} elseif ( 'to' === $m[1] && $diffs[ $m[2] ]['tags'] ) {
				$send[ $m[2] ] = $diffs[ $m[2] ]['tags'];
			} elseif ( 'edit' === $m[1] && 'name' !== $m[2] && $diffs[ $m[2] ]['tags'] ) {
				$write[ $m[2] ] = $diffs[ $m[2] ];
			}
		}
		$both = array_intersect_key( $take, $send + $write );
		if ( $both ) {
			return new WP_Error( 'plakette_osm', sprintf( __( 'Für „%s“ bitte nur eine Richtung wählen: melden oder übernehmen.', 'plakette' ), $diffs[ array_key_first( $both ) ]['label'] ) );
		}
		if ( ! $take && ! $send && ! $write ) {
			return new WP_Error( 'plakette_osm', __( 'Bitte wähle mindestens eine Angabe aus.', 'plakette' ) );
		}
		$done = array();
		if ( $send ) {
			if ( null === $el['lat'] ) {
				return new WP_Error( 'plakette_osm', __( 'Der OpenStreetMap-Eintrag hat keine Koordinaten – der Hinweis kann nicht gesetzt werden.', 'plakette' ) );
			}
			$tags  = array_merge( ...array_values( $send ) );
			$fixes = array();
			if ( isset( $tags['website'] ) && ( isset( $el['tags']['contact:website'] ) || isset( $el['tags']['url'] ) ) ) {
				$fixes[] = '(Bisher eingetragene Website: ' . ( $el['tags']['contact:website'] ?? $el['tags']['url'] ) . ')';
			}
			$note = Plakette_Osm_Profile::send_note( $el['lat'], $el['lon'], Plakette_Osm_Profile::note_text( $c, $tags, $el['url'], $fixes ), $el['url'], array_keys( $send ) );
			if ( is_wp_error( $note ) ) {
				return $note;
			}
			$done[] = sprintf( __( 'Hinweis Nr. %d an OpenStreetMap geschickt – Freiwillige prüfen ihn und tragen die Angaben ein.', 'plakette' ), $note['id'] );
		}
		if ( $write ) {
			$set = Plakette_Osm_Edit::changes( $write, $el['tags'] );
			if ( $set ) {
				$expected = array();
				foreach ( array_keys( $set ) as $k ) {
					$expected[ $k ] = $el['tags'][ $k ] ?? null; // what the owner saw in the report
				}
				$labels = array_values( wp_list_pluck( $write, 'label' ) );
				$r      = Plakette_Osm_Edit::write( $el['type'], $el['id'], $set, $expected, Plakette_Osm_Edit::comment( (string) ( $el['tags']['name'] ?? Plakette_Osm_Profile::company_name( $c ) ), $labels ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$undo[] = array( 'osm_tags', $el['type'], $el['id'], $r['version'], $r['old'], $set );
				Plakette_Osm_Profile::details();
				$done[] = sprintf(
					/* translators: 1: fields, 2: changeset link */
					__( 'In OpenStreetMap eingetragen: %1$s (Änderungssatz %2$s). Karten-Apps zeigen das nach ihrem nächsten Datenabgleich.', 'plakette' ),
					implode( ', ', $labels ),
					Plakette_Osm_Profile::site() . 'changeset/' . $r['changeset']
				);
			}
		}
		if ( $take ) {
			if ( isset( $take['street'] ) && '' !== $osm['country'] ) {
				$take['country'] = $osm['country'];
			}
			Plakette_Settings::save( 'company', $take, $undo );
			$done[] = sprintf( _n( '%d Angabe aus OpenStreetMap übernommen.', '%d Angaben aus OpenStreetMap übernommen.', count( $take ), 'plakette' ), count( $take ) );
		}
		return implode( ' ', $done );
	}
}

add_filter(
	'plakette_checks',
	function ( $classes ) {
		return array_merge( $classes, array( 'Plakette_Check_Osm_Profile' ) );
	},
	21
);
