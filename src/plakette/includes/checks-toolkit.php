<?php
/**
 * Checks for the features ported from the "i-make.media Toolkit for Breakdance":
 * image dimensions, CDN files, accessible entrance animations, link labels.
 * Runtime parts: includes/modules/performance.php (Plakette_Performance).
 * (Local Google Fonts and the LCP image are handled by the checks privacy_google_fonts and speed_lcp in checks-speed.php.)
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Check_Image_Dims extends Plakette_Check {
	public $id        = 'speed_image_dims';
	public $area      = 'speed';
	public $group     = 'Bilder';
	public $weight    = 5;
	public $title     = 'Bilder haben feste Maße';
	public $problem   = 'Bilder ohne Breite und Höhe – die Seite springt beim Laden';
	public $explain   = 'Steht bei einem Bild nicht dabei, wie breit und hoch es ist, weiß der Browser erst nach dem Laden, wie viel Platz es braucht. Bis dahin rutscht der Text darunter nach unten – das nervt beim Lesen und Google wertet es als „Layout-Verschiebung“ (CLS) ab.';
	public $fix_label = 'Bildmaße ergänzen';
	public $fix_does  = 'Plakette ergänzt beim Ausliefern jeder Seite die fehlende Breite und Höhe aus der Bilddatei (bei SVG aus der viewBox). Deine Inhalte selbst bleiben unverändert.';

	/** Images without width+height on the home page and the sample pages: src => count. */
	private static function unsized( Plakette_Context $ctx ) {
		$pages = array( $ctx->facts() );
		foreach ( $ctx->samples() as $facts ) {
			$pages[] = $facts;
		}
		$out = array();
		foreach ( $pages as $facts ) {
			foreach ( isset( $facts['imgs'] ) ? (array) $facts['imgs'] : array() as $img ) {
				$src = isset( $img['src'] ) ? (string) $img['src'] : '';
				if ( ! empty( $img['sized'] ) || '' === $src || 0 === strpos( $src, 'data:' ) ) {
					continue;
				}
				$out[ $src ] = isset( $out[ $src ] ) ? $out[ $src ] + 1 : 1;
			}
		}
		return $out;
	}

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$list = self::unsized( $ctx );
		if ( ! $list ) {
			return $this->pass( __( 'Alle Bilder auf der Startseite und den geprüften Unterseiten haben Breite und Höhe.', 'plakette' ) );
		}
		$notes = array();
		foreach ( array_slice( array_keys( $list ), 0, 6 ) as $src ) {
			$notes[] = wp_basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
		}
		$detail = sprintf( _n( '%d Bild ohne Breite und Höhe.', '%d Bilder ohne Breite und Höhe.', count( $list ), 'plakette' ), count( $list ) );
		if ( Plakette_Modules::on( 'img_dims' ) ) {
			return $this->warn(
				$detail . ' ' . __( 'Plakette ergänzt die Maße schon, kann sie bei diesen Bildern aber nicht ermitteln (sie liegen z. B. auf einem fremden Server). Trage Breite und Höhe dort im Editor selbst ein.', 'plakette' ),
				array( 'notes' => $notes, 'kind' => 'none' )
			);
		}
		return $this->fail( $detail, array( 'notes' => $notes ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'img_dims', true, $undo );
		Plakette_Page_Cache::purge_all();
		return __( 'Fehlende Bildmaße werden jetzt beim Ausliefern ergänzt.', 'plakette' );
	}
}

class Plakette_Check_Cdn_Files extends Plakette_Check {
	public $id        = 'privacy_cdn';
	public $area      = 'legal';
	public $group     = 'Fremde Server';
	public $weight    = 6;
	public $title     = 'Skripte und Stylesheets kommen vom eigenen Server';
	public $problem   = 'Skripte oder Stylesheets werden von einem öffentlichen CDN geladen';
	public $explain   = 'Manche Themes, Plugins und Page Builder (z. B. Breakdance für Animationen mit GSAP) laden Programmdateien von öffentlichen Servern wie unpkg, jsDelivr oder cdnjs. Dabei geht die IP-Adresse jedes Besuchers an den Betreiber – ohne Einwilligung ein Datenschutzproblem. Außerdem kostet jeder fremde Server eine zusätzliche Verbindung.';
	public $fix_label = 'Dateien lokal ausliefern';
	public $fix_does  = 'Plakette lädt die Dateien einmal herunter (mit Prüfung, dass es wirklich JavaScript bzw. CSS ist), legt sie in deinen Upload-Ordner und tauscht die Adressen beim Ausliefern der Seiten aus. Neue Versionen (z. B. nach einem Breakdance-Update) holt Plakette automatisch; Dateien ohne feste Version werden wöchentlich aufgefrischt. Klappt ein Download nicht, bleibt die alte Adresse – die Seite funktioniert weiter.';

	/** CDN files on the home page and the sample pages. */
	private static function found( Plakette_Context $ctx ) {
		$pages = array( $ctx->facts() );
		foreach ( $ctx->samples() as $facts ) {
			$pages[] = $facts;
		}
		$out = array();
		foreach ( $pages as $facts ) {
			$urls = array_merge( isset( $facts['scripts'] ) ? (array) $facts['scripts'] : array(), isset( $facts['styles'] ) ? (array) $facts['styles'] : array() );
			foreach ( $urls as $url ) {
				if ( null !== Plakette_Performance::cdn_pinned( $url ) ) {
					$out[] = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function short( $url ) {
		return preg_replace( '#^(https?:)?//#', '', $url );
	}

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$urls = self::found( $ctx );
		if ( ! $urls ) {
			$n = count( Plakette_Performance::registry() );
			if ( Plakette_Modules::on( 'cdn_local' ) && $n ) {
				return $this->pass( sprintf( _n( '%d CDN-Datei wird von deinem Server ausgeliefert.', '%d CDN-Dateien werden von deinem Server ausgeliefert.', $n, 'plakette' ), $n ) );
			}
			return $this->pass( __( 'Keine Dateien von öffentlichen CDNs gefunden.', 'plakette' ) );
		}
		$detail = sprintf( _n( '%d Datei von einem öffentlichen CDN', '%d Dateien von öffentlichen CDNs', count( $urls ), 'plakette' ), count( $urls ) );
		$hosts  = array_unique( array_map( function ( $u ) { return (string) wp_parse_url( 0 === strpos( $u, '//' ) ? 'https:' . $u : $u, PHP_URL_HOST ); }, $urls ) );
		$detail .= ' (' . implode( ', ', $hosts ) . ').';
		if ( ! Plakette_Modules::on( 'cdn_local' ) ) {
			return $this->fail( $detail, array( 'notes' => array_map( array( __CLASS__, 'short' ), array_slice( $urls, 0, 8 ) ) ) );
		}
		// Module is on, but the address is still the CDN: the download did not work (yet).
		$notes = array();
		foreach ( array_slice( $urls, 0, 8 ) as $url ) {
			$why     = Plakette_Performance::fail_reason( $url );
			$notes[] = self::short( $url ) . ( '' !== $why ? ' – ' . $why : '' );
		}
		return $this->warn(
			$detail . ' ' . __( 'Lokales Ausliefern ist eingeschaltet, aber diese Dateien konnten noch nicht heruntergeladen werden. Bis es klappt, bleibt die CDN-Adresse (die Seite funktioniert weiter); Plakette versucht es stündlich erneut.', 'plakette' ),
			array( 'notes' => $notes, 'fix_label' => __( 'Jetzt erneut versuchen', 'plakette' ) )
		);
	}

	public function fix( array $req, array &$undo ) {
		$ctx  = new Plakette_Context();
		$urls = $ctx->home_ok() ? self::found( $ctx ) : array();
		$had_copies = (bool) Plakette_Performance::registry();
		if ( ! Plakette_Modules::on( 'cdn_local' ) ) {
			Plakette_Log::set_module( 'cdn_local', true, $undo );
		}
		if ( ! $had_copies ) {
			// Undo removes the local copies again (replayed before the module is switched off).
			$undo[] = array( 'callback', 'Plakette_Performance::clear_cdn' );
		}
		$failed = array();
		foreach ( $urls as $url ) {
			if ( ! Plakette_Performance::local_asset( $url, true ) ) {
				$why                            = Plakette_Performance::fail_reason( $url );
				$failed[ self::short( $url ) ] = '' !== $why ? $why : '?';
			}
		}
		Plakette_Page_Cache::purge_all();
		if ( $failed ) {
			$list = array();
			foreach ( $failed as $u => $why ) {
				$list[] = $u . ' (' . $why . ')';
			}
			return sprintf(
				/* translators: %s: list of files */
				__( 'Eingeschaltet. Noch nicht heruntergeladen: %s. Bis dahin bleibt die CDN-Adresse, Plakette versucht es stündlich erneut.', 'plakette' ),
				implode( ', ', $list )
			);
		}
		return __( 'Die Dateien kommen jetzt von deinem eigenen Server.', 'plakette' );
	}
}

class Plakette_Check_A11y_Animations extends Plakette_Check {
	public $id        = 'a11y_animations';
	public $area      = 'legal';
	public $group     = 'Barrierefreiheit (BFSG)';
	public $weight    = 4;
	public $title     = 'Einblendanimationen sind für alle lesbar';
	public $problem   = 'Einblendanimationen verstecken Inhalte vor Screenreadern';
	public $explain   = 'Breakdance blendet Elemente mit „Entrance“-Animationen erst beim Hinscrollen ein und versteckt sie bis dahin komplett (visibility: hidden). Blinde Menschen, deren Vorleser die Seite von oben bis unten liest, hören diese Texte dann nicht – und ohne JavaScript bleibt die Seite leer. Das Barrierefreiheitsstärkungsgesetz (BFSG) verlangt, dass Inhalte für alle erreichbar sind.';
	public $fix_label = 'Einblendungen barrierefrei machen';
	public $fix_does  = 'Die Elemente werden nur noch optisch ausgeblendet (durchsichtig) und bleiben für Vorleseprogramme lesbar. Wer in seinem Gerät „Bewegung reduzieren“ eingestellt hat oder kein JavaScript nutzt, sieht sie sofort. Die Animation selbst bleibt.';

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$count  = 0;
		$styled = true;
		foreach ( $ctx->all_html() as $html ) {
			$n = preg_match_all( '#<[a-z][^>]*\sdata-entrance(\s*=|[\s/>])#i', (string) $html );
			if ( $n ) {
				$count += $n;
				if ( false === strpos( (string) $html, 'id="plk-einblendungen"' ) ) {
					$styled = false;
				}
			}
		}
		if ( ! $count ) {
			return $this->skip( __( 'Keine Einblendanimationen (Breakdance „Entrance“) gefunden.', 'plakette' ) );
		}
		if ( $styled || Plakette_Modules::on( 'a11y_anim' ) ) {
			return $this->pass( sprintf( _n( '%d Element mit Einblendanimation – für Vorleseprogramme lesbar.', '%d Elemente mit Einblendanimation – für Vorleseprogramme lesbar.', $count, 'plakette' ), $count ) );
		}
		return $this->fail( sprintf( _n( '%d Element wird bis zum Hinscrollen auch vor Vorleseprogrammen versteckt.', '%d Elemente werden bis zum Hinscrollen auch vor Vorleseprogrammen versteckt.', $count, 'plakette' ), $count ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'a11y_anim', true, $undo );
		Plakette_Page_Cache::purge_all();
		return __( 'Einblendungen bleiben jetzt für Vorleseprogramme lesbar.', 'plakette' );
	}
}

class Plakette_Check_A11y_Link_Names extends Plakette_Check {
	public $id        = 'a11y_link_names';
	public $area      = 'legal';
	public $group     = 'Barrierefreiheit (BFSG)';
	public $weight    = 5;
	public $title     = 'Alle Links haben eine Beschriftung';
	public $problem   = 'Links ohne Text (z. B. Symbole) sind für Vorleseprogramme stumm';
	public $explain   = 'Ein Link, der nur aus einem Symbol oder Logo besteht (Instagram-Icon, Telefonhörer, Pfeil), hat für blinde Menschen keinen Namen – ihr Vorleser sagt nur „Link“. Das Barrierefreiheitsstärkungsgesetz (BFSG) verlangt, dass jeder Link sagt, wohin er führt. Auch Platzhalter wie aria-label="custom" (Breakdance „Social Icons“) zählen nicht.';
	public $fix_label = 'Links beschriften';
	public $fix_does  = 'Plakette gibt solchen Links beim Ausliefern eine unsichtbare Beschriftung nach ihrem Ziel, z. B. „Instagram (öffnet in neuem Tab)“, „Anrufen: 0761 123456“, „E-Mail an info@…“, den Titel der eigenen Seite oder „Website von beispiel.de“. Eigene Beschriftungen haben immer Vorrang.';

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$links = array();
		foreach ( $ctx->all_html() as $html ) {
			foreach ( Plakette_Performance::unnamed_links( $html ) as $l ) {
				$links[ $l['href'] ] = $l['label'];
			}
		}
		if ( ! $links ) {
			return $this->pass( __( 'Alle Links auf der Startseite und den geprüften Unterseiten haben eine Beschriftung.', 'plakette' ) );
		}
		$fixable = array_filter(
			$links,
			function ( $label ) {
				return null !== $label;
			}
		);
		$notes = array();
		foreach ( array_slice( $links, 0, 8, true ) as $href => $label ) {
			$notes[] = null === $label
				? sprintf( __( '%s – kein sinnvolles Ziel, bitte im Editor beschriften', 'plakette' ), $href )
				: sprintf( __( '%1$s → „%2$s“', 'plakette' ), $href, $label );
		}
		$detail = sprintf( _n( '%d Link ohne Beschriftung.', '%d Links ohne Beschriftung.', count( $links ), 'plakette' ), count( $links ) );
		if ( Plakette_Modules::on( 'link_names' ) || ! $fixable ) {
			return $this->warn(
				$detail . ' ' . __( 'Diese Links führen nirgendwohin (z. B. „#“ oder JavaScript), deshalb kann Plakette keine Beschriftung ableiten. Gib ihnen im Editor einen Text oder eine Beschriftung (aria-label).', 'plakette' ),
				array( 'notes' => $notes, 'kind' => 'none' )
			);
		}
		return $this->fail( $detail, array( 'notes' => $notes ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'link_names', true, $undo );
		Plakette_Page_Cache::purge_all();
		return __( 'Links ohne Text werden jetzt automatisch beschriftet.', 'plakette' );
	}
}

add_filter(
	'plakette_checks',
	function ( $classes ) {
		return array_merge(
			$classes,
			array(
				'Plakette_Check_Image_Dims',
				'Plakette_Check_Cdn_Files',
				'Plakette_Check_A11y_Animations',
				'Plakette_Check_A11y_Link_Names',
			)
		);
	},
	35
);
