<?php
/**
 * Checks ported from "SEO-Helfer für Breakdance": company profile, image sitemap, featured images,
 * plus the hand-over from the author's older plugins.
 * Runtime parts: includes/modules/seo-helper.php and includes/modules/breakdance.php.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Check_Legacy_Imm extends Plakette_Check {
	public $id        = 'legacy_imm_plugins';
	public $area      = 'seo';
	public $group     = 'Plugins';
	public $weight    = 4;
	public $title     = 'Keine doppelten Plugins von i-make.media';
	public $problem   = 'Ältere Plugins machen, was Plakette schon kann';
	public $explain   = 'Die Plugins „SEO-Helfer für Breakdance“ und „i-make.media Toolkit for Breakdance“ sind Vorgänger von Plakette. Plakette enthält ihre Funktionen. Laufen beide, macht deine Website dieselbe Arbeit doppelt – und du musst zwei Plugins pflegen.';
	public $fix_label = 'Einstellungen übernehmen und alte Plugins abschalten';
	public $fix_does  = 'Übernimmt die Einstellungen der alten Plugins (Firmendaten, Kapitel-Navigation, Bilder-Sitemap, Schriften, Ladezeit-Optionen) in Plakette und deaktiviert die alten Plugins. Gelöscht wird nichts – „Rückgängig“ schaltet sie wieder ein.';
	public $risk      = 'review';
	public $kind      = 'auto';

	private function found() {
		$out = array();
		foreach ( array( 'seo-helper', 'toolkit' ) as $which ) {
			if ( Plakette_Legacy::active( $which ) ) {
				$out[ $which ] = Plakette_Legacy::basename( $which );
			}
		}
		return $out;
	}

	public function run( Plakette_Context $ctx ) {
		$found = $this->found();
		if ( ! $found ) {
			return $this->pass( __( 'Keines der älteren Plugins ist aktiv.', 'plakette' ) );
		}
		$names = array();
		$notes = array();
		$extra = array();
		foreach ( $found as $which => $basename ) {
			$names[] = Plakette_Legacy::name( $which );
			if ( '' === $basename ) {
				$extra['blocked'] = sprintf( __( 'Das Plugin „%s“ konnte nicht eindeutig gefunden werden. Deaktiviere es bitte selbst unter Plugins.', 'plakette' ), Plakette_Legacy::name( $which ) );
			} elseif ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $basename ) ) {
				$extra['blocked'] = sprintf( __( '„%s“ ist für das ganze Netzwerk aktiviert. Deaktiviere es bitte in der Netzwerkverwaltung.', 'plakette' ), Plakette_Legacy::name( $which ) );
			}
		}
		if ( isset( $found['seo-helper'] ) ) {
			$notes[] = __( 'SEO-Helfer: Firmenprofil, Bilder-Sitemap, Kapitel-Navigation und Autoren-Bio stecken jetzt in Plakette. Die Datenfelder in Breakdance behalten ihre Namen – deine Designs funktionieren weiter.', 'plakette' );
		}
		if ( isset( $found['toolkit'] ) ) {
			$notes[] = __( 'Toolkit: Google Fonts lokal, LCP-Bild, CDN-Dateien lokal, Bildmaße, barrierefreie Einblendungen und Link-Beschriftungen stecken jetzt in Plakette.', 'plakette' );
		}
		$extra['notes'] = $notes;
		return $this->warn(
			/* translators: %s: plugin names */
			sprintf( __( 'Aktiv: %s. Plakette enthält diese Funktionen.', 'plakette' ), implode( ', ', $names ) ),
			$extra
		);
	}

	public function fix( array $req, array &$undo ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$found = $this->found();
		if ( ! $found ) {
			return __( 'Die älteren Plugins sind schon abgeschaltet.', 'plakette' );
		}
		$done  = Plakette_Legacy::migrate( $undo );
		$names = array();
		foreach ( $found as $which => $basename ) {
			if ( '' === $basename || ! is_plugin_active( $basename ) ) {
				continue;
			}
			deactivate_plugins( $basename, true );
			$undo[]  = array( 'activate_plugin', $basename );
			$names[] = Plakette_Legacy::name( $which );
		}
		$msg = $names
			/* translators: %s: plugin names */
			? sprintf( __( 'Abgeschaltet: %s.', 'plakette' ), implode( ', ', $names ) )
			: __( 'Kein Plugin abgeschaltet.', 'plakette' );
		if ( $done ) {
			/* translators: %s: list of settings */
			$msg .= ' ' . sprintf( __( 'Übernommen: %s.', 'plakette' ), implode( ', ', $done ) );
		}
		return $msg;
	}
}

class Plakette_Check_Company_Schema extends Plakette_Check {
	public $id        = 'seo_company_schema';
	public $area      = 'seo';
	public $group     = 'Strukturierte Daten';
	public $weight    = 5;
	public $title     = 'Firmenprofil mit Adresse für Google';
	public $problem   = 'Google kennt deine Adresse und Öffnungszeiten nicht';
	public $explain   = 'Mit einem Firmenprofil in den strukturierten Daten sagst du Google genau, wer du bist: Art des Unternehmens, Adresse, Telefon, Öffnungszeiten und Einzugsgebiet. Das hilft vor allem bei Suchen in deiner Gegend („Konditorei Freiburg“) und für die Infobox neben den Suchergebnissen.';
	public $fix_label = 'Firmenprofil speichern und ausgeben';
	public $fix_does  = 'Speichert die Angaben als Firmendaten in Plakette und gibt sie als Firmenprofil (Organisation und lokales Unternehmen nach schema.org) aus – über dein SEO-Plugin, falls vorhanden.';
	public $kind      = 'form';

	const FIELDS = array( 'name', 'street', 'zip', 'city', 'region', 'country', 'phone', 'email', 'schema_type', 'price_range', 'opening_hours', 'area_served', 'profiles' );

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$orgs = 0;
		foreach ( Plakette_Company_Schema::nodes_from_html( $ctx->html() ) as $node ) {
			if ( isset( $node['@type'] ) && Plakette_Company_Schema::is_org( $node['@type'] ) ) {
				++$orgs;
				if ( Plakette_Company_Schema::has_address( $node ) ) {
					$types = implode( ' + ', array_map( 'strval', (array) $node['@type'] ) );
					/* translators: %s: schema.org type */
					return $this->pass( sprintf( __( 'Firmenprofil (%s) mit Adresse vorhanden.', 'plakette' ), $types ) );
				}
			}
		}
		$extra = array(
			'form' => Plakette_Settings::form( 'company', self::FIELDS ) + array(
				'intro' => __( 'Trag mindestens Name, Straße und Ort ein. Öffnungszeiten z. B. „Mo-Fr 9-17“ – eine Zeile pro Regel.', 'plakette' ),
			),
		);
		if ( defined( 'WPSEO_VERSION' ) && class_exists( 'WPSEO_Options' ) && is_callable( array( 'WPSEO_Options', 'get' ) ) && 'company' !== WPSEO_Options::get( 'company_or_person', 'company' ) ) {
			$extra['blocked'] = __( 'In Yoast SEO ist deine Website als Person eingetragen. Stelle unter Yoast SEO › Einstellungen › Website-Darstellung „Organisation“ ein – dann kann Plakette das Firmenprofil ergänzen.', 'plakette' );
		}
		if ( Plakette_Legacy::handles( 'company_schema' ) ) {
			$extra['notes'] = array( __( 'Das alte Plugin „SEO-Helfer für Breakdance“ ist noch aktiv. Plakette gibt das Firmenprofil erst aus, wenn es abgeschaltet ist (Punkt „Keine doppelten Plugins“).', 'plakette' ) );
		}
		$detail = $orgs
			? __( 'Die Startseite nennt eine Organisation, aber keine Adresse.', 'plakette' )
			: __( 'Auf der Startseite gibt es kein Firmenprofil (Organisation oder lokales Unternehmen).', 'plakette' );
		return $this->fail( $detail, $extra );
	}

	public function fix( array $req, array &$undo ) {
		$values = isset( $req['value'] ) && is_array( $req['value'] ) ? array_intersect_key( $req['value'], array_flip( self::FIELDS ) ) : array();
		$c      = Plakette_Settings::get( 'company' );
		$street = trim( (string) ( isset( $values['street'] ) ? $values['street'] : $c['street'] ) );
		$city   = trim( (string) ( isset( $values['city'] ) ? $values['city'] : $c['city'] ) );
		if ( '' === $street || '' === $city ) {
			return new WP_Error( 'plakette_company', __( 'Bitte trag mindestens Straße und Ort ein.', 'plakette' ) );
		}
		if ( $values ) {
			Plakette_Settings::save( 'company', $values, $undo );
		}
		if ( ! Plakette_Modules::on( 'company_schema' ) ) {
			Plakette_Log::set_module( 'company_schema', true, $undo );
		}
		return __( 'Firmendaten gespeichert, das Firmenprofil wird ausgegeben.', 'plakette' );
	}
}

class Plakette_Check_Google_Profile extends Plakette_Check {
	public $id        = 'seo_google_profile';
	public $area      = 'seo';
	public $group     = 'Strukturierte Daten';
	public $weight    = 4;
	public $title     = 'Firmendaten stimmen mit dem Google-Unternehmensprofil überein';
	public $problem   = 'Firmendaten weichen vom Google-Unternehmensprofil ab';
	public $explain   = 'Google vergleicht Name, Adresse und Telefon auf deiner Website mit deinem Unternehmensprofil (Eintrag in Google Maps). Stimmen sie überein, vertraut Google den Angaben mehr – das hilft bei Suchen in deiner Gegend. Widersprüche (z. B. eine alte Telefonnummer) verwirren Google und Kunden.';
	public $fix_label = 'Google-Angaben übernehmen';
	public $fix_does  = 'Übernimmt die ausgewählten Angaben aus deinem Google-Unternehmensprofil in die Firmendaten von Plakette. Damit ändern sich Firmenprofil für Google, Impressum-Angaben aus den Firmendaten und alles, was Plakette daraus erzeugt. An deinem Profil bei Google ändert sich nichts.';
	public $risk      = 'review';
	public $kind      = 'items';

	public function run( Plakette_Context $ctx ) {
		$url = Plakette_Google_Profile::url();
		$key = Plakette_Google_Profile::key();
		if ( '' === $url ) {
			$form = Plakette_Settings::form( 'company', array( 'gbp_url' ) );
			$keyf = Plakette_Settings::form( 'google', array( 'places_key' ) );
			return $this->warn(
				__( 'Dein Google-Unternehmensprofil ist nicht mit der Website verknüpft.', 'plakette' ),
				array(
					'weight'    => 2,
					'problem'   => __( 'Google-Unternehmensprofil nicht verknüpft', 'plakette' ),
					'kind'      => 'form',
					'fix_label' => __( 'Profil verknüpfen', 'plakette' ),
					'fix_does'  => __( 'Nimmt den Link zu deinem Unternehmensprofil in die strukturierten Daten auf (sameAs/hasMap), damit Google Website und Profil sicher zusammenbringt. Mit API-Schlüssel gleicht Plakette danach Name, Adresse, Telefon und Öffnungszeiten ab. Hast du kein Unternehmensprofil, kannst du diesen Punkt ignorieren.', 'plakette' ),
					'form'      => array(
						'section' => 'company',
						'fields'  => array_merge( $form['fields'], $keyf['fields'] ),
						'intro'   => __( 'Den Link findest du in Google Maps: dein Unternehmen öffnen › „Teilen“ › „Link kopieren“. Der API-Schlüssel ist freiwillig.', 'plakette' ),
					),
				)
			);
		}
		if ( ! Plakette_Google_Profile::is_profile_url( $url ) ) {
			return $this->warn(
				sprintf( __( 'Der eingetragene Link „%s“ führt nicht zu Google Maps.', 'plakette' ), $url ),
				array( 'kind' => 'none', 'blocked' => __( 'Trag unter Einstellungen › Firmendaten den Link aus Google Maps ein (Unternehmen öffnen › „Teilen“ › „Link kopieren“) oder die Place ID (beginnt mit „ChIJ“).', 'plakette' ) )
			);
		}
		if ( '' === $key ) {
			return $this->skip( __( 'Das Unternehmensprofil ist verknüpft. Für den Abgleich der Angaben trag unter Einstellungen › Google-Unternehmensprofil einen API-Schlüssel ein.', 'plakette' ) );
		}
		$place = Plakette_Google_Profile::details();
		if ( is_wp_error( $place ) ) {
			return $this->skip( __( 'Abgleich mit Google nicht möglich.', 'plakette' ), array( 'notes' => array( $place->get_error_message() ) ) );
		}
		$google = Plakette_Google_Profile::as_company( $place );
		$ours   = Plakette_Settings::company();
		$diffs  = Plakette_Google_Profile::differences( $google, $ours );
		$notes  = array( sprintf( __( 'Abgefragt: Google-Profil „%1$s“, %2$s (Place ID %3$s).', 'plakette' ), $google['name'], (string) ( $place['formattedAddress'] ?? '' ), (string) ( $place['id'] ?? '' ) ) );
		$links  = ! empty( $place['googleMapsUri'] ) ? array( array( 'label' => __( 'Profil in Google Maps', 'plakette' ), 'url' => (string) $place['googleMapsUri'] ) ) : array();
		if ( ! empty( $place['businessStatus'] ) && 'OPERATIONAL' !== $place['businessStatus'] ) {
			$notes[] = sprintf( __( 'Achtung: Google führt das Unternehmen als „%s“ (vorübergehend oder dauerhaft geschlossen).', 'plakette' ), $place['businessStatus'] );
		}
		if ( ! empty( $place['websiteUri'] ) ) {
			$host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( (string) $place['websiteUri'], PHP_URL_HOST ) ) );
			$own  = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
			if ( $host && $host !== $own ) {
				$notes[] = sprintf( __( 'Im Google-Profil steht als Website %1$s, diese Website ist %2$s. Das änderst du im Unternehmensprofil bei Google.', 'plakette' ), $place['websiteUri'], home_url() );
			}
		} else {
			$notes[] = __( 'Im Google-Profil ist keine Website eingetragen – ergänze sie dort.', 'plakette' );
		}
		if ( ! $diffs ) {
			return $this->pass( __( 'Name, Adresse, Telefon und Öffnungszeiten stimmen mit dem Google-Unternehmensprofil überein.', 'plakette' ), array( 'notes' => $notes, 'links' => $links ) );
		}
		// Only the name differs: usual (legal name in the imprint, brand at Google) – no defect.
		if ( array( 'name' ) === wp_list_pluck( $diffs, 'key' ) ) {
			$notes[] = sprintf( __( 'Der Name weicht ab (Website: „%1$s“, Google: „%2$s“). Das ist in Ordnung, wenn in den Firmendaten der Name fürs Impressum steht.', 'plakette' ), $diffs[0]['ours'], $diffs[0]['google'] );
			return $this->pass( __( 'Adresse, Telefon und Öffnungszeiten stimmen mit dem Google-Unternehmensprofil überein.', 'plakette' ), array( 'notes' => $notes, 'links' => $links ) );
		}
		$items = array();
		foreach ( $diffs as $d ) {
			$items[] = array(
				'id'      => 'gbp:' . $d['key'],
				'label'   => $d['label'],
				'sub'     => sprintf( __( 'Website: %1$s · Google: %2$s', 'plakette' ), '' !== $d['ours'] ? str_replace( "\n", ' / ', $d['ours'] ) : __( '(leer)', 'plakette' ), str_replace( "\n", ' / ', $d['google'] ) ),
				'value'   => $d['google'],
				'checked' => $d['checked'],
			);
		}
		if ( in_array( 'name', wp_list_pluck( $diffs, 'key' ), true ) ) {
			$notes[] = __( 'Der Name ist nicht vorausgewählt: In den Firmendaten steht der Name fürs Impressum (z. B. mit Rechtsform). Weicht nur die Schreibweise ab, passe besser das Google-Profil an.', 'plakette' );
		}
		return $this->warn(
			sprintf( _n( '%d Angabe weicht vom Google-Unternehmensprofil ab.', '%d Angaben weichen vom Google-Unternehmensprofil ab.', count( $items ), 'plakette' ), count( $items ) ),
			array( 'items' => $items, 'notes' => $notes, 'links' => $links )
		);
	}

	public function fix( array $req, array &$undo ) {
		// Form (link + optional key) …
		if ( isset( $req['value'] ) && is_array( $req['value'] ) ) {
			$url = trim( (string) ( $req['value']['gbp_url'] ?? '' ) );
			if ( '' === $url || ! Plakette_Google_Profile::is_profile_url( $url ) ) {
				return new WP_Error( 'plakette_gbp', __( 'Bitte trag den Link aus Google Maps ein (Unternehmen öffnen › „Teilen“ › „Link kopieren“).', 'plakette' ) );
			}
			Plakette_Settings::save( 'company', array( 'gbp_url' => $url ), $undo );
			if ( '' !== trim( (string) ( $req['value']['places_key'] ?? '' ) ) ) {
				Plakette_Settings::save( 'google', array( 'places_key' => $req['value']['places_key'] ), $undo );
			}
			Plakette_Google_Profile::forget();
			if ( ! Plakette_Modules::on( 'company_schema' ) ) {
				Plakette_Log::set_module( 'company_schema', true, $undo );
			}
			return __( 'Das Google-Unternehmensprofil ist verknüpft.', 'plakette' );
		}
		// … or take over the Google values.
		$place = Plakette_Google_Profile::details();
		if ( is_wp_error( $place ) ) {
			return $place;
		}
		$google = Plakette_Google_Profile::as_company( $place );
		$values = array();
		foreach ( (array) ( $req['items'] ?? array() ) as $item ) {
			if ( preg_match( '/^gbp:(name|street|zip|city|phone|opening_hours)$/', (string) ( $item['id'] ?? '' ), $m ) && '' !== $google[ $m[1] ] ) {
				$values[ $m[1] ] = $google[ $m[1] ];
			}
		}
		if ( ! $values ) {
			return new WP_Error( 'plakette_gbp', __( 'Bitte wähle mindestens eine Angabe aus.', 'plakette' ) );
		}
		if ( isset( $values['street'] ) && '' !== $google['country'] ) {
			$values['country'] = $google['country'];
		}
		Plakette_Settings::save( 'company', $values, $undo );
		return sprintf( _n( '%d Angabe aus Google übernommen.', '%d Angaben aus Google übernommen.', count( $values ), 'plakette' ), count( $values ) );
	}
}

class Plakette_Check_Image_Sitemap extends Plakette_Check {
	public $id        = 'seo_image_sitemap';
	public $area      = 'seo';
	public $group     = 'Strukturierte Daten';
	public $weight    = 3;
	public $title     = 'Alle Bilder in der Sitemap';
	public $problem   = 'Bilder fehlen in der Sitemap';
	public $explain   = 'Die Sitemap ist das Inhaltsverzeichnis deiner Website für Google. Dein SEO-Plugin nimmt dort auch Bilder auf – aber nur die aus dem normalen Beitragstext. Bilder aus Seitenbaukästen wie Breakdance fehlen, und damit findet die Google-Bildersuche sie schlechter.';
	public $fix_label = 'Bilder ergänzen';
	public $fix_does  = 'Ergänzt in der Sitemap deines SEO-Plugins zu jeder Seite die Bilder aus Breakdance-Layouts, das Beitragsbild und die Bilder im Inhalt.';

	public function run( Plakette_Context $ctx ) {
		$plugin = Plakette_Image_Sitemap::seo_sitemap();
		if ( ! $plugin ) {
			return $this->skip( __( 'Keine Sitemap von Yoast SEO oder Rank Math aktiv. Die eigene Sitemap von WordPress enthält keine Bilder – hier gibt es nichts zu ergänzen.', 'plakette' ) );
		}
		if ( Plakette_Modules::on( 'image_sitemap' ) ) {
			return $this->pass( sprintf( __( 'Plakette ergänzt die Bilder in der Sitemap von %s.', 'plakette' ), $plugin ) );
		}
		if ( Plakette_Legacy::handles( 'image_sitemap' ) ) {
			return $this->pass( __( 'Das alte Plugin „SEO-Helfer für Breakdance“ ergänzt die Bilder gerade.', 'plakette' ) );
		}
		$posts  = 0;
		$images = 0;
		$ids    = get_posts(
			array(
				'post_type'      => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ),
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_breakdance_data', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			$n = count( Plakette_Breakdance::image_ids( $id ) );
			if ( $n ) {
				$images += $n;
				++$posts;
			}
		}
		if ( ! $images ) {
			return $this->pass( sprintf( __( '%s nimmt die Bilder deiner Beiträge bereits auf.', 'plakette' ), $plugin ) );
		}
		/* translators: 1: number of images, 2: number of pages, 3: plugin name */
		return $this->fail( sprintf( __( '%1$d Bilder auf %2$d Breakdance-Seiten fehlen in der Sitemap von %3$s.', 'plakette' ), $images, $posts, $plugin ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'image_sitemap', true, $undo );
		return __( 'Die Bilder werden jetzt in der Sitemap ergänzt.', 'plakette' );
	}
}

class Plakette_Check_Featured_Images extends Plakette_Check {
	public $id        = 'seo_featured_images';
	public $area      = 'seo';
	public $group     = 'Inhalte';
	public $weight    = 4;
	public $title     = 'Jede Seite hat ein Beitragsbild';
	public $problem   = 'Seiten mit Bildern haben kein Beitragsbild';
	public $explain   = 'Das Beitragsbild ist das Hauptbild einer Seite. Google, Facebook, WhatsApp und LinkedIn zeigen es in der Vorschau. Fehlt es, bleibt die Vorschau leer – obwohl auf der Seite passende Bilder sind.';
	public $fix_label = 'Bilder als Beitragsbild setzen';
	public $fix_does  = 'Setzt bei den ausgewählten Seiten das gezeigte Bild als Beitragsbild (das Vorschaubild aus Yoast oder das erste Bild der Seite). Am Inhalt ändert sich nichts.';
	public $kind      = 'items';

	public function run( Plakette_Context $ctx ) {
		$items = array();
		foreach ( $ctx->posts() as $post ) {
			if ( has_post_thumbnail( $post ) || post_password_required( $post ) ) {
				continue;
			}
			$img = Plakette_Post_Images::candidate( $post->ID );
			if ( ! $img ) {
				continue;
			}
			$items[] = array(
				'id'    => 'post:' . $post->ID,
				'label' => wp_strip_all_tags( get_the_title( $post ) ) ? wp_strip_all_tags( get_the_title( $post ) ) : __( '(ohne Titel)', 'plakette' ),
				/* translators: %s: file name */
				'sub'   => sprintf( __( 'Bild: %s', 'plakette' ), wp_basename( (string) get_attached_file( $img ) ) ),
				'value' => (string) $img,
				'thumb' => wp_get_attachment_image_url( $img, 'thumbnail' ),
				'link'  => get_permalink( $post ),
			);
			if ( count( $items ) >= 200 ) {
				break;
			}
		}
		if ( ! $items ) {
			return $this->pass( __( 'Alle Seiten mit Bildern haben ein Beitragsbild.', 'plakette' ) );
		}
		/* translators: %d: number of pages */
		return $this->fail( sprintf( _n( '%d Seite hat Bilder, aber kein Beitragsbild.', '%d Seiten haben Bilder, aber kein Beitragsbild.', count( $items ), 'plakette' ), count( $items ) ), array( 'items' => $items ) );
	}

	public function fix( array $req, array &$undo ) {
		$set = 0;
		foreach ( isset( $req['items'] ) ? (array) $req['items'] : array() as $item ) {
			if ( ! preg_match( '/^post:(\d+)$/', isset( $item['id'] ) ? (string) $item['id'] : '', $m ) || ! current_user_can( 'edit_post', (int) $m[1] ) ) {
				continue;
			}
			$post_id = (int) $m[1];
			if ( has_post_thumbnail( $post_id ) ) {
				continue;
			}
			$img = isset( $item['value'] ) ? (int) $item['value'] : 0;
			if ( ! $img || ! wp_attachment_is_image( $img ) ) {
				$img = Plakette_Post_Images::candidate( $post_id );
			}
			if ( ! $img ) {
				continue;
			}
			Plakette_Log::set_postmeta( $post_id, '_thumbnail_id', $img, $undo );
			++$set;
		}
		if ( ! $set ) {
			return new WP_Error( 'plakette_thumbs', __( 'Es wurde kein Beitragsbild gesetzt.', 'plakette' ) );
		}
		/* translators: %d: number of pages */
		return sprintf( _n( '%d Beitragsbild gesetzt.', '%d Beitragsbilder gesetzt.', $set, 'plakette' ), $set );
	}
}

add_filter(
	'plakette_checks',
	function ( $classes ) {
		return array_merge(
			$classes,
			array(
				'Plakette_Check_Legacy_Imm',
				'Plakette_Check_Company_Schema',
				'Plakette_Check_Google_Profile',
				'Plakette_Check_Image_Sitemap',
				'Plakette_Check_Featured_Images',
			)
		);
	},
	20
);
