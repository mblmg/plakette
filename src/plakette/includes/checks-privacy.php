<?php
/**
 * Privacy checks: third-party servers, embedded media, cookie consent, avatars and comments.
 * The consent check belongs to the area "security" (group „Recht & DSGVO“).
 */

defined( 'ABSPATH' ) || exit;

abstract class Plakette_Privacy_Check extends Plakette_Check {
	public $area  = 'privacy';
	public $group = 'Fremde Server';

	/** Merged Plakette_Services::detect() of the home page and the sample pages (+ where it was found). */
	protected static function detect_all( Plakette_Context $ctx ) {
		$out = array();
		foreach ( $ctx->all_html() as $post_id => $html ) {
			foreach ( Plakette_Services::detect( $html ) as $id => $hit ) {
				if ( ! isset( $out[ $id ] ) ) {
					$out[ $id ] = array( 'blocked' => true, 'where' => array(), 'pages' => array() );
				}
				$out[ $id ]['blocked'] = $out[ $id ]['blocked'] && $hit['blocked'];
				$out[ $id ]['where']   = array_values( array_unique( array_merge( $out[ $id ]['where'], $hit['where'] ) ) );
				if ( ! $hit['blocked'] ) {
					$out[ $id ]['pages'][] = (int) $post_id;
				}
			}
		}
		return $out;
	}

	protected static function service_name( $id ) {
		$s = Plakette_Services::get( $id );
		return $s ? $s['name'] : $id;
	}

	protected static function page_title( $post_id ) {
		return $post_id ? wp_strip_all_tags( get_the_title( $post_id ) ) : __( 'Startseite', 'plakette' );
	}

	/** Media services embedded in post content: post id => service ids (iframes, embed blocks, oEmbed URLs). */
	protected static function media_in_posts( Plakette_Context $ctx ) {
		$out = array();
		foreach ( $ctx->posts() as $post ) {
			$c = (string) $post->post_content;
			if ( '' === $c || ( false === stripos( $c, 'iframe' ) && false === strpos( $c, '://' ) ) ) {
				continue;
			}
			$urls = array();
			if ( preg_match_all( '#<iframe\b[^>]*>#i', $c, $m ) ) {
				foreach ( $m[0] as $tag ) {
					if ( ! Plakette_Services::is_held_back( $tag ) ) {
						$urls[] = Plakette_Html::attr( $tag, 'src' );
					}
				}
			}
			if ( preg_match_all( '#<!--\s*wp:(?:core-)?embed\b[^>]*"url"\s*:\s*"([^"]+)"#i', $c, $m ) ) {
				$urls = array_merge( $urls, array_map( 'stripslashes', $m[1] ) );
			}
			if ( preg_match_all( '#\[embed[^\]]*\](.*?)\[/embed\]#i', $c, $m ) ) {
				$urls = array_merge( $urls, $m[1] );
			}
			// Classic editor: a URL alone on its line becomes an oEmbed.
			if ( preg_match_all( '#^\s*(?:<p>)?\s*(https?://[^\s<>"]+)\s*(?:</p>)?\s*$#im', $c, $m ) ) {
				$urls = array_merge( $urls, $m[1] );
			}
			foreach ( $urls as $url ) {
				$id = Plakette_Embeds::service_for( trim( (string) $url ) );
				if ( $id ) {
					$out[ $post->ID ][] = $id;
				}
			}
			if ( isset( $out[ $post->ID ] ) ) {
				$out[ $post->ID ] = array_values( array_unique( $out[ $post->ID ] ) );
			}
		}
		return $out;
	}

	/** Are comments in use? (open on any published post/page or approved comments exist) */
	protected static function comments_active() {
		return self::comments_open_anywhere() || self::approved_comments() > 0;
	}

	protected static function comments_open_anywhere() {
		$open = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'comment_status' => 'open',
			)
		);
		return ! empty( $open );
	}

	protected static function approved_comments() {
		$count = wp_count_comments();
		return isset( $count->approved ) ? (int) $count->approved : 0;
	}
}

/* ------------------------------------------------------------------------------------------ overview */

class Plakette_Check_Privacy_Third_Party extends Plakette_Privacy_Check {
	public $id        = 'privacy_third_party';
	public $weight    = 3;
	public $title     = 'Keine fremden Server ohne Einwilligung';
	public $problem   = 'Deine Seite verbindet Besucher mit fremden Servern';
	public $explain   = 'Jede Datei von einem fremden Server (Skript, Schrift, Video, Bild) verrät diesem Server die IP-Adresse deiner Besucher. Nach DSGVO brauchst du dafür einen guten Grund oder die Einwilligung. Hier siehst du, wohin deine Seite Verbindungen aufbaut – und welcher Prüfpunkt dagegen hilft.';
	public $kind      = 'none';

	/** What helps against a service (which other check). */
	private static function remedy( $id, $category ) {
		$map = array(
			'google-fonts' => __( 'Schriften lokal speichern (Prüfpunkt „Schriften kommen vom eigenen Server“)', 'plakette' ),
			'adobe-fonts'  => __( 'Schriften lokal einbinden (Einstellung im Theme bzw. bei Adobe)', 'plakette' ),
			'font-awesome' => __( 'Symbole lokal einbinden (Einstellung im Theme oder Plugin)', 'plakette' ),
			'public-cdn'   => __( 'Dateien lokal ausliefern (Prüfpunkt „Skripte und Stylesheets kommen vom eigenen Server“)', 'plakette' ),
			'gravatar'     => __( 'Lokale Profilbilder (Prüfpunkt „Profilbilder ohne Gravatar“)', 'plakette' ),
			'recaptcha'    => __( 'Spamschutz ohne Google wählen oder erst nach Einwilligung laden', 'plakette' ),
			'mailchimp'    => __( 'Anmeldeformular erst nach Einwilligung laden (Cookie-Banner)', 'plakette' ),
		);
		if ( isset( $map[ $id ] ) ) {
			return $map[ $id ];
		}
		if ( 'media' === $category ) {
			return __( 'Vorschaubild mit Klick zum Laden (Prüfpunkt „Videos und Karten erst nach Klick“)', 'plakette' );
		}
		if ( in_array( $category, array( 'statistics', 'marketing' ), true ) ) {
			return __( 'Erst nach Einwilligung laden (Prüfpunkt „Fremde Dienste starten erst nach Einwilligung“ unter Sicherheit)', 'plakette' );
		}
		return __( 'Prüfen, ob nötig; sonst lokal einbinden oder erst nach Einwilligung laden', 'plakette' );
	}

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$found = self::detect_all( $ctx );
		$hosts = (array) $ctx->facts()['hosts'];
		foreach ( $ctx->samples() as $facts ) {
			foreach ( isset( $facts['hosts'] ) ? (array) $facts['hosts'] : array() as $h => $types ) {
				$hosts[ $h ] = isset( $hosts[ $h ] ) ? array_values( array_unique( array_merge( $hosts[ $h ], $types ) ) ) : $types;
			}
		}
		$notes   = array();
		$names   = array();
		$handled = 0;
		$seen    = array();
		foreach ( $found as $id => $hit ) {
			$s = Plakette_Services::get( $id );
			if ( ! $s || 'necessary' === $s['category'] || empty( $s['consent'] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			if ( $hit['blocked'] ) {
				$handled++;
				continue;
			}
			$names[] = $s['name'];
			$where   = array_filter(
				$hit['where'],
				function ( $w ) {
					return 'Inline-Skript' !== $w;
				}
			);
			$notes[] = ( $where ? implode( ', ', array_slice( $where, 0, 2 ) ) : __( 'Skript im Seitenquelltext', 'plakette' ) ) . ' – ' . $s['name'] . ' – ' . self::remedy( $id, $s['category'] );
		}
		foreach ( $hosts as $host => $types ) {
			$id = Plakette_Services::for_url( $host );
			if ( $id ) {
				$s = Plakette_Services::get( $id );
				if ( isset( $seen[ $id ] ) || 'necessary' === $s['category'] || empty( $s['consent'] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$names[]     = $s['name'];
				$notes[]     = $host . ' – ' . $s['name'] . ' – ' . self::remedy( $id, $s['category'] );
				continue;
			}
			$names[] = $host;
			$notes[] = $host . ' – ' . __( 'unbekannter Dienst', 'plakette' ) . ' (' . implode( ', ', (array) $types ) . ') – ' . self::remedy( '', '' );
		}
		if ( ! $notes ) {
			if ( $handled ) {
				return $this->pass( sprintf( _n( '%d fremder Dienst wird erst nach Einwilligung geladen, sonst nur der eigene Server.', '%d fremde Dienste werden erst nach Einwilligung geladen, sonst nur der eigene Server.', $handled, 'plakette' ), $handled ) );
			}
			return $this->pass( __( 'Deine Seite lädt alles vom eigenen Server.', 'plakette' ) );
		}
		$names = array_values( array_unique( $names ) );
		return $this->warn(
			sprintf(
				/* translators: 1: number, 2: list */
				_n( '%1$d fremder Dienst wird ohne Einwilligung kontaktiert: %2$s.', '%1$d fremde Dienste werden ohne Einwilligung kontaktiert: %2$s.', count( $names ), 'plakette' ),
				count( $names ),
				implode( ', ', array_slice( $names, 0, 8 ) ) . ( count( $names ) > 8 ? ' …' : '' )
			),
			array( 'notes' => array_slice( $notes, 0, 20 ) )
		);
	}
}

/* ------------------------------------------------------------------------------------------ embeds */

class Plakette_Check_Privacy_Embeds extends Plakette_Privacy_Check {
	public $id        = 'privacy_embeds';
	public $weight    = 6;
	public $title     = 'Videos und Karten laden erst nach Klick';
	public $problem   = 'Videos oder Karten senden Daten, bevor jemand klickt';
	public $explain   = 'Ein eingebettetes YouTube-Video oder eine Google-Karte meldet sich schon beim Öffnen der Seite bei Google – mit IP-Adresse und oft mit Cookies. Das ist ohne Einwilligung nicht erlaubt. Eine Zwei-Klick-Lösung zeigt stattdessen einen Platzhalter; erst ein Klick lädt den Inhalt. Nebenbei wird die Seite deutlich schneller.';
	public $fix_label = 'Zwei-Klick-Lösung einschalten';
	public $fix_does  = 'Ersetzt eingebettete Videos, Karten und Player (YouTube, Vimeo, Google Maps, OpenStreetMap, Spotify, SoundCloud, Calendly …) durch einen gleich großen Platzhalter mit Hinweis und Knopf „laden“. YouTube läuft danach im erweiterten Datenschutzmodus (youtube-nocookie.com). Deine Beiträge selbst werden nicht verändert.';
	public $risk      = 'safe';

	public function run( Plakette_Context $ctx ) {
		$posts = self::media_in_posts( $ctx );
		$home  = $ctx->home_ok();
		if ( ! $home && ! $posts ) {
			return $this->no_html( $ctx );
		}
		$pages = array();
		$found = array();
		if ( $home ) {
			foreach ( self::detect_all( $ctx ) as $id => $hit ) {
				$s = Plakette_Services::get( $id );
				if ( $s && 'media' === $s['category'] && ! empty( $s['consent'] ) ) {
					$found[ $id ] = $hit;
					if ( ! $hit['blocked'] ) {
						$pages[ $id ] = $hit['pages'];
					}
				}
			}
		}
		$tool  = $home ? Plakette_Services::consent_tool( $ctx->html() ) : '';
		$names = array();
		foreach ( $posts as $ids ) {
			foreach ( $ids as $id ) {
				$names[ $id ] = self::service_name( $id );
			}
		}
		foreach ( array_keys( $pages ) as $id ) {
			$names[ $id ] = self::service_name( $id );
		}
		$notes = array();
		foreach ( array_slice( $posts, 0, 10, true ) as $post_id => $ids ) {
			$notes[] = self::page_title( $post_id ) . ' – ' . implode( ', ', array_map( array( __CLASS__, 'service_name' ), $ids ) );
		}
		if ( count( $posts ) > 10 ) {
			$notes[] = sprintf( __( '… und %d weitere', 'plakette' ), count( $posts ) - 10 );
		}

		if ( Plakette_Modules::on( 'embeds' ) ) {
			if ( $pages ) {
				$list = array();
				foreach ( $pages as $id => $where ) {
					$list[] = self::service_name( $id ) . ' (' . implode( ', ', array_map( array( __CLASS__, 'page_title' ), array_unique( $where ) ) ) . ')';
				}
				return $this->warn(
					sprintf( __( 'Die Zwei-Klick-Lösung ist an, aber diese Inhalte laden trotzdem sofort: %s. Sie werden vermutlich per JavaScript nachgeladen (z. B. von einem Page-Builder-Element) – schalte den Cookie-Banner ein oder nutze im Builder das normale Einbettungs-Element.', 'plakette' ), implode( '; ', $list ) ),
					array( 'kind' => 'none' )
				);
			}
			$count = count( $posts );
			return $this->pass(
				$count
					? sprintf( _n( 'Zwei-Klick-Lösung aktiv: Einbettungen in %d Beitrag laden erst nach Klick.', 'Zwei-Klick-Lösung aktiv: Einbettungen in %d Beiträgen laden erst nach Klick.', $count, 'plakette' ), $count )
					: __( 'Zwei-Klick-Lösung aktiv. Zurzeit sind keine Videos oder Karten eingebettet.', 'plakette' )
			);
		}
		if ( $tool && 'Plakette' !== $tool && ! $pages ) {
			return $this->pass( sprintf( __( 'Eingebettete Inhalte werden von %s blockiert, bis Besucher zustimmen.', 'plakette' ), $tool ) );
		}
		if ( ! $pages && ! $posts ) {
			return $this->pass( __( 'Keine eingebetteten Videos, Karten oder Player gefunden.', 'plakette' ) );
		}
		$detail = sprintf(
			/* translators: %s: services */
			__( 'Diese Inhalte laden ohne Klick und Einwilligung: %s.', 'plakette' ),
			implode( ', ', array_values( $names ) )
		);
		if ( $posts ) {
			$detail .= ' ' . sprintf( _n( 'Betroffen: %d Beitrag bzw. Seite.', 'Betroffen: %d Beiträge bzw. Seiten.', count( $posts ), 'plakette' ), count( $posts ) );
		}
		return $this->fail( $detail, $notes ? array( 'notes' => $notes ) : array() );
	}

	public function fix( array $req, array &$undo ) {
		if ( ! Plakette_Modules::on( 'embeds' ) ) {
			list( $dir ) = Plakette_Embeds::storage();
			if ( ! is_dir( $dir ) ) {
				$undo[] = array( 'file_delete', $dir );
			}
		}
		Plakette_Log::set_module( 'embeds', true, $undo );
		Plakette_Page_Cache::purge_all();
		return __( 'Videos, Karten und Player zeigen jetzt einen Platzhalter und laden erst nach Klick.', 'plakette' );
	}
}

/* ------------------------------------------------------------------------------------------ consent */

class Plakette_Check_Legal_Consent extends Plakette_Privacy_Check {
	public $id        = 'legal_consent';
	public $area      = 'security';
	public $group     = 'Recht & DSGVO';
	public $weight    = 9;
	public $title     = 'Fremde Dienste starten erst nach Einwilligung';
	public $problem   = 'Tracking und fremde Inhalte laden ohne Einwilligung';
	public $explain   = 'Statistik-, Werbe- und Video-Dienste dürfen erst laden, wenn Besucher ausdrücklich zugestimmt haben (§ 25 TDDDG, DSGVO). Sonst drohen Abmahnungen und Bußgelder. Ein Cookie-Banner fragt nach und hält die Dienste bis dahin zurück. Gut zu wissen: Ohne solche Dienste brauchst du gar keinen Banner.';
	public $fix_label = 'Cookie-Banner einschalten';
	public $fix_does  = 'Schaltet den Cookie-Banner von Plakette ein: „Alle akzeptieren“, „Nur notwendige“ und „Einstellungen“ gleichwertig, mit Links zu Datenschutzerklärung und Impressum. Skripte von Statistik-, Marketing- und Mediendiensten werden zurückgehalten, bis Besucher zustimmen; eingebettete Videos und Karten bekommen einen Platzhalter. Ohne Verbindung zu fremden Servern. Texte änderst du unter Einstellungen › Cookie-Banner.';
	public $risk      = 'review';

	/** Cookies that are technically necessary (no consent needed). */
	private static function essential_cookie( $name ) {
		return (bool) preg_match( '/^(wordpress_test_cookie|PHPSESSID|wp-settings|wp_woocommerce_session_|woocommerce_|wp_lang$|plakette_consent$|wordpress_logged_in|wordpress_sec|comment_author)/i', $name );
	}

	/** Services our banner is responsible for that load unblocked: id => hit. */
	private static function unblocked( array $found ) {
		$out = array();
		foreach ( $found as $id => $hit ) {
			if ( ! $hit['blocked'] && Plakette_Consent::handles( $id ) ) {
				$out[ $id ] = $hit;
			}
		}
		return $out;
	}

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$found     = self::detect_all( $ctx );
		$unblocked = self::unblocked( $found );
		$posts     = Plakette_Modules::on( 'embeds' ) ? array() : self::media_in_posts( $ctx );
		$tool      = Plakette_Services::consent_tool( $ctx->html() );
		$names     = array_map( array( __CLASS__, 'service_name' ), array_keys( $unblocked ) );
		foreach ( $posts as $ids ) {
			foreach ( $ids as $id ) {
				$names[] = self::service_name( $id );
			}
		}
		$names = array_values( array_unique( $names ) );

		if ( $tool && 'Plakette' !== $tool ) {
			$extra = array();
			if ( $names ) {
				$extra['notes'] = array( sprintf( __( 'Prüfe in %1$s, ob diese Dienste erfasst sind: %2$s.', 'plakette' ), $tool, implode( ', ', $names ) ) );
			}
			return $this->pass( sprintf( __( 'Cookie-Banner: %s ist aktiv und kümmert sich um die Einwilligung.', 'plakette' ), $tool ), $extra );
		}

		if ( Plakette_Modules::on( 'consent' ) ) {
			if ( $unblocked ) {
				$notes = array();
				foreach ( $unblocked as $id => $hit ) {
					$notes[] = self::service_name( $id ) . ' – ' . implode( ', ', array_map( array( __CLASS__, 'page_title' ), array_unique( $hit['pages'] ) ) ) . ' (' . implode( ', ', $hit['where'] ) . ')';
				}
				return $this->warn(
					sprintf( __( 'Der Cookie-Banner ist aktiv, aber diese Dienste laden trotzdem ohne Einwilligung: %s. Vermutlich werden sie per JavaScript nachgeladen (z. B. über den Tag Manager oder ein Plugin) – stelle sie im jeweiligen Plugin auf „erst nach Einwilligung“ oder binde sie über den Code-Bereich deines Themes ein.', 'plakette' ), implode( ', ', array_map( array( __CLASS__, 'service_name' ), array_keys( $unblocked ) ) ) ),
					array( 'kind' => 'none', 'notes' => $notes )
				);
			}
			if ( 'Plakette' !== $tool ) {
				return $this->warn( __( 'Der Cookie-Banner ist eingeschaltet, erscheint aber nicht auf der Startseite. Ein Cache oder ein anderes Plugin liefert die Seite womöglich unverändert aus – leere den Cache.', 'plakette' ), array( 'kind' => 'none' ) );
			}
			$held = array();
			foreach ( $found as $id => $hit ) {
				if ( Plakette_Consent::handles( $id ) ) {
					$held[] = self::service_name( $id );
				}
			}
			return $this->pass(
				$held
					? sprintf( __( 'Der Cookie-Banner von Plakette ist aktiv und hält diese Dienste bis zur Einwilligung zurück: %s.', 'plakette' ), implode( ', ', $held ) )
					: __( 'Der Cookie-Banner von Plakette ist aktiv.', 'plakette' )
			);
		}

		if ( $names ) {
			return $this->fail( sprintf( __( 'Diese Dienste laden, ohne dass Besucher zugestimmt haben: %s.', 'plakette' ), implode( ', ', $names ) ) );
		}

		$cookies = array_values( array_filter( $ctx->cookies(), function ( $c ) {
			return ! self::essential_cookie( $c );
		} ) );
		if ( $cookies ) {
			return $this->warn(
				sprintf( __( 'Keine Tracking-Dienste gefunden, aber die Startseite setzt diese Cookies: %s. Prüfe, wofür sie da sind: Sind sie technisch nötig (z. B. Warenkorb, Login), brauchst du keinen Banner – sonst musst du vorher fragen.', 'plakette' ), implode( ', ', $cookies ) ),
				array( 'kind' => 'none' )
			);
		}
		return $this->pass( __( 'Kein Cookie-Banner nötig: Deine Seite lädt keine Statistik-, Werbe- oder Mediendienste und setzt keine unnötigen Cookies. Ein Banner wäre hier nur lästig – viele Seiten brauchen gar keinen.', 'plakette' ) );
	}

	public function fix( array $req, array &$undo ) {
		$ctx   = new Plakette_Context();
		$found = $ctx->home_ok() ? self::detect_all( $ctx ) : array();
		$ids   = array_keys( $found );
		$media = false;
		foreach ( self::media_in_posts( $ctx ) as $list ) {
			$ids   = array_merge( $ids, $list );
			$media = true;
		}
		foreach ( $found as $id => $hit ) {
			$s = Plakette_Services::get( $id );
			if ( $s && 'media' === $s['category'] && ! $hit['blocked'] ) {
				$media = true;
			}
		}
		Plakette_Consent::remember( $ids, $undo );
		Plakette_Log::set_module( 'consent', true, $undo );
		$msg = __( 'Der Cookie-Banner ist eingeschaltet. Statistik-, Marketing- und Mediendienste laden erst nach Einwilligung.', 'plakette' );
		if ( $media && ! Plakette_Modules::on( 'embeds' ) ) {
			list( $dir ) = Plakette_Embeds::storage();
			if ( ! is_dir( $dir ) ) {
				$undo[] = array( 'file_delete', $dir );
			}
			Plakette_Log::set_module( 'embeds', true, $undo );
			$msg .= ' ' . __( 'Eingebettete Videos und Karten zeigen jetzt einen Platzhalter.', 'plakette' );
		}
		Plakette_Page_Cache::purge_all();
		if ( ! get_privacy_policy_url() ) {
			$msg .= ' ' . __( 'Tipp: Lege eine Datenschutzerklärung an – der Banner verlinkt sie automatisch.', 'plakette' );
		}
		return $msg;
	}
}

/* ------------------------------------------------------------------------------------------ comments */

class Plakette_Check_Privacy_Gravatar extends Plakette_Privacy_Check {
	public $id        = 'privacy_gravatar';
	public $group     = 'Kommentare';
	public $weight    = 4;
	public $title     = 'Profilbilder ohne Gravatar';
	public $problem   = 'Profilbilder kommen von Gravatar';
	public $explain   = 'WordPress lädt Profilbilder bei Kommentaren vom Dienst Gravatar (USA). Dabei gehen die IP-Adresse jedes Besuchers und ein Hashwert der E-Mail-Adresse jedes Kommentierenden an Automattic. Lokale Bilder mit Initialen sehen ordentlich aus und verraten nichts.';
	public $fix_label = 'Lokale Profilbilder verwenden';
	public $fix_does  = 'Statt Gravatar zeigt WordPress ein Bild mit den Initialen der Person in einer festen Farbe. Die Bilder werden auf deinem Server erzeugt (Ordner uploads/plakette-avatars), es gibt keine Anfrage an Gravatar mehr.';
	public $risk      = 'safe';

	public function run( Plakette_Context $ctx ) {
		if ( ! get_option( 'show_avatars' ) ) {
			return $this->pass( __( 'Profilbilder sind ausgeschaltet (Einstellungen › Diskussion).', 'plakette' ) );
		}
		$on_pages = false;
		if ( $ctx->home_ok() ) {
			$found    = self::detect_all( $ctx );
			$on_pages = isset( $found['gravatar'] ) && ! $found['gravatar']['blocked'];
		}
		if ( Plakette_Modules::on( 'local_avatars' ) ) {
			if ( $on_pages ) {
				return $this->warn( __( 'Lokale Profilbilder sind an, aber ein Theme oder Plugin lädt trotzdem Bilder von gravatar.com.', 'plakette' ), array( 'kind' => 'none' ) );
			}
			return $this->pass( __( 'Profilbilder werden lokal erzeugt – keine Verbindung zu Gravatar.', 'plakette' ) );
		}
		if ( ! $on_pages && ! self::comments_active() ) {
			return $this->pass( __( 'Profilbilder sind an, werden aber nirgends gezeigt (keine Kommentare).', 'plakette' ) );
		}
		return $this->fail( $on_pages ? __( 'Deine Seiten laden Bilder von gravatar.com.', 'plakette' ) : __( 'Bei Kommentaren werden Profilbilder von gravatar.com geladen.', 'plakette' ) );
	}

	public function fix( array $req, array &$undo ) {
		list( $dir ) = Plakette_Local_Avatars::storage();
		if ( ! Plakette_Modules::on( 'local_avatars' ) && ! is_dir( $dir ) ) {
			$undo[] = array( 'file_delete', $dir );
		}
		Plakette_Log::set_module( 'local_avatars', true, $undo );
		Plakette_Page_Cache::purge_all();
		return __( 'Profilbilder werden jetzt lokal erzeugt.', 'plakette' );
	}
}

class Plakette_Check_Privacy_Comment_Ip extends Plakette_Privacy_Check {
	public $id        = 'privacy_comment_ip';
	public $group     = 'Kommentare';
	public $weight    = 3;
	public $title     = 'Kommentare speichern nur gekürzte IP-Adressen';
	public $problem   = 'Kommentare speichern vollständige IP-Adressen';
	public $explain   = 'WordPress merkt sich zu jedem Kommentar die komplette IP-Adresse. Das ist ein personenbezogenes Datum, das du für den Spamschutz nicht vollständig brauchst. Eine gekürzte Adresse (z. B. 203.0.113.0) reicht und schützt die Kommentierenden.';
	public $fix_label = 'IP-Adressen kürzen';
	public $fix_does  = 'Neue Kommentare speichern nur noch die gekürzte IP-Adresse (IPv4: letzte Zahl wird 0, IPv6: nur die ersten drei Blöcke). Bereits gespeicherte Adressen werden ebenfalls gekürzt – bis zu 2.000 pro Klick, die neuesten zuerst.';
	public $risk      = 'safe';

	const LIMIT = 2000;

	/** Newest comments with a full IP: [ [comment_ID, comment_author_IP], … ] */
	private static function full_ips( $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT comment_ID, comment_author_IP FROM {$wpdb->comments} WHERE comment_author_IP <> '' AND comment_author_IP NOT LIKE %s AND comment_author_IP NOT LIKE %s ORDER BY comment_ID DESC LIMIT %d", '%.0', '%::', (int) $limit ), ARRAY_N ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $r ) {
			if ( ! Plakette_Comment_Privacy::is_anonymous( $r[1] ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	private static function count_full() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author_IP <> '' AND comment_author_IP NOT LIKE %s AND comment_author_IP NOT LIKE %s", '%.0', '%::' ) ); // phpcs:ignore WordPress.DB
	}

	public function run( Plakette_Context $ctx ) {
		$full = self::count_full();
		$open = self::comments_open_anywhere();
		if ( Plakette_Modules::on( 'comment_ip' ) ) {
			if ( $full ) {
				return $this->warn( sprintf( _n( 'Neue Kommentare speichern gekürzte IP-Adressen, aber %d älterer Kommentar hat noch die vollständige Adresse.', 'Neue Kommentare speichern gekürzte IP-Adressen, aber %d ältere Kommentare haben noch die vollständige Adresse.', $full, 'plakette' ), $full ) );
			}
			return $this->pass( __( 'Kommentare speichern nur gekürzte IP-Adressen.', 'plakette' ) );
		}
		if ( ! $full && ! $open ) {
			return $this->pass( __( 'Kommentare sind geschlossen und keine vollständigen IP-Adressen gespeichert.', 'plakette' ) );
		}
		if ( $full ) {
			return $this->fail( sprintf( _n( '%d Kommentar enthält die vollständige IP-Adresse.', '%d Kommentare enthalten die vollständige IP-Adresse.', $full, 'plakette' ), $full ) . ( $open ? ' ' . __( 'Neue Kommentare speichern sie ebenfalls.', 'plakette' ) : '' ) );
		}
		return $this->fail( __( 'Kommentare sind offen – neue Kommentare speichern die vollständige IP-Adresse.', 'plakette' ) );
	}

	public function fix( array $req, array &$undo ) {
		global $wpdb;
		Plakette_Log::set_module( 'comment_ip', true, $undo );
		$total = self::count_full();
		$rows  = self::full_ips( self::LIMIT );
		foreach ( $rows as $r ) {
			$new = Plakette_Comment_Privacy::anonymize( $r[1] );
			$undo[] = array( 'comment_field', (int) $r[0], 'comment_author_IP', (string) $r[1] );
			$wpdb->update( $wpdb->comments, array( 'comment_author_IP' => $new ), array( 'comment_ID' => (int) $r[0] ) ); // phpcs:ignore WordPress.DB
			clean_comment_cache( (int) $r[0] );
		}
		$msg = __( 'Neue Kommentare speichern nur noch gekürzte IP-Adressen.', 'plakette' );
		if ( $rows ) {
			$msg .= ' ' . sprintf( _n( '%d gespeicherte Adresse wurde gekürzt.', '%d gespeicherte Adressen wurden gekürzt.', count( $rows ), 'plakette' ), count( $rows ) );
		}
		if ( $total > count( $rows ) ) {
			$msg .= ' ' . sprintf( __( 'Es bleiben %d ältere – klicke noch einmal.', 'plakette' ), $total - count( $rows ) );
		}
		return $msg;
	}
}

class Plakette_Check_Privacy_Comment_Consent extends Plakette_Privacy_Check {
	public $id        = 'privacy_comment_consent';
	public $group     = 'Kommentare';
	public $weight    = 4;
	public $title     = 'Kommentarformular mit Datenschutzhinweis';
	public $problem   = 'Das Kommentarformular erklärt nicht, was gespeichert wird';
	public $explain   = 'Wer kommentiert, gibt Name, E-Mail-Adresse und IP-Adresse preis. Die DSGVO verlangt, dass du direkt am Formular darüber informierst und auf die Datenschutzerklärung verlinkst. Außerdem sollte WordPress fragen, bevor es Name und E-Mail für das nächste Mal in einem Cookie speichert.';
	public $fix_label = 'Hinweis und Cookie-Abfrage einschalten';
	public $fix_does  = 'Zeigt über dem Knopf „Kommentar abschicken“ einen kurzen Hinweis mit Link zur Datenschutzerklärung und schaltet die WordPress-Abfrage „Meinen Namen und meine E-Mail-Adresse speichern“ ein (Einstellungen › Diskussion).';
	public $risk      = 'safe';

	/** Does the comment form on a real page mention privacy? null = no form found. */
	private static function form_has_notice( Plakette_Context $ctx ) {
		$htmls = $ctx->all_html();
		$open  = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'comment_status' => 'open', 'has_password' => false ) );
		if ( $open && ! isset( $htmls[ $open[0] ] ) ) {
			$res = Plakette_Crawler::fetch( get_permalink( $open[0] ) );
			if ( $res['ok'] && $res['status'] < 400 ) {
				$htmls[ $open[0] ] = $res['body'];
			}
		}
		$policy = get_privacy_policy_url();
		foreach ( $htmls as $html ) {
			if ( ! preg_match( '#<form\b[^>]*id=["\']commentform["\'][^>]*>(.*?)</form>#is', (string) $html, $m ) ) {
				continue;
			}
			$form = $m[1];
			return false !== strpos( $form, 'plk-comment-note' ) || ( $policy && false !== strpos( $form, esc_url( $policy ) ) ) || (bool) preg_match( '#datenschutz|privacy#i', wp_strip_all_tags( $form ) );
		}
		return null;
	}

	public function run( Plakette_Context $ctx ) {
		if ( ! self::comments_active() ) {
			return $this->pass( __( 'Kommentare sind überall geschlossen – kein Hinweis nötig.', 'plakette' ) );
		}
		$opt_in = (bool) get_option( 'show_comments_cookies_opt_in' );
		$notice = Plakette_Modules::on( 'comment_note' );
		if ( ! $notice && $ctx->home_ok() ) {
			$notice = (bool) self::form_has_notice( $ctx );
		}
		if ( $opt_in && $notice ) {
			return $this->pass( __( 'Am Kommentarformular steht ein Datenschutzhinweis, und WordPress fragt vor dem Speichern von Name und E-Mail.', 'plakette' ) );
		}
		$missing = array();
		if ( ! $notice ) {
			$missing[] = __( 'kein Hinweis auf die Datenschutzerklärung am Formular', 'plakette' );
		}
		if ( ! $opt_in ) {
			$missing[] = __( 'Name und E-Mail werden ohne Nachfrage in einem Cookie gespeichert', 'plakette' );
		}
		$detail = ucfirst( implode( '; ', $missing ) ) . '.';
		return ( $notice || $opt_in ) ? $this->warn( $detail ) : $this->fail( $detail );
	}

	public function fix( array $req, array &$undo ) {
		if ( ! get_option( 'show_comments_cookies_opt_in' ) ) {
			Plakette_Log::set_option( 'show_comments_cookies_opt_in', '1', $undo );
		}
		Plakette_Log::set_module( 'comment_note', true, $undo );
		Plakette_Page_Cache::purge_all();
		$msg = __( 'Das Kommentarformular zeigt jetzt einen Datenschutzhinweis.', 'plakette' );
		if ( ! get_privacy_policy_url() ) {
			$msg .= ' ' . __( 'Es gibt noch keine veröffentlichte Datenschutzerklärung – der Hinweis erscheint deshalb ohne Link.', 'plakette' );
		}
		return $msg;
	}
}

add_filter(
	'plakette_checks',
	function ( $c ) {
		return array_merge(
			$c,
			array(
				'Plakette_Check_Privacy_Third_Party',
				'Plakette_Check_Legal_Consent',
				'Plakette_Check_Privacy_Embeds',
				'Plakette_Check_Privacy_Gravatar',
				'Plakette_Check_Privacy_Comment_Ip',
				'Plakette_Check_Privacy_Comment_Consent',
			)
		);
	},
	20
);
