<?php
/**
 * Privacy checks: third-party servers, embedded media, cookie consent, avatars and comments.
 * The consent check (privacy_consent) is in the group „DSGVO & Cookies“: it decides with real evidence whether a
 * cookie banner is needed and how to get rid of it.
 */

defined( 'ABSPATH' ) || exit;

abstract class Plakette_Privacy_Check extends Plakette_Check {
	public $area  = 'privacy';
	public $group = 'Fremde Server';

	/** Merged Plakette_Services::detect() of the home page and the sample pages (+ where it was found). */
	public static function detect_all( Plakette_Context $ctx ) {
		$out = array();
		foreach ( $ctx->all_html() as $post_id => $html ) {
			foreach ( Plakette_Services::detect( $html ) as $id => $hit ) {
				if ( ! isset( $out[ $id ] ) ) {
					$out[ $id ] = array( 'blocked' => true, 'where' => array(), 'pages' => array(), 'all' => array() );
				}
				$out[ $id ]['all'][] = (int) $post_id;
				$out[ $id ]['blocked'] = $out[ $id ]['blocked'] && $hit['blocked'];
				$out[ $id ]['where']   = array_values( array_unique( array_merge( $out[ $id ]['where'], $hit['where'] ) ) );
				if ( ! $hit['blocked'] ) {
					$out[ $id ]['pages'][] = (int) $post_id;
				}
			}
		}
		return $out;
	}

	public static function service_name( $id ) {
		$s = Plakette_Services::get( $id );
		return $s ? $s['name'] : $id;
	}

	/** Hosts that belong to a detected service (e.g. www.google.com of a Maps iframe): host => service id. */
	public static function service_hosts( Plakette_Context $ctx ) {
		$out = array();
		foreach ( self::detect_all( $ctx ) as $id => $hit ) {
			foreach ( $hit['where'] as $w ) {
				$out[ strtolower( $w ) ] = $id;
			}
		}
		return $out;
	}

	/** Comments shown or possible somewhere. */
	public static function comments_in_use() {
		return self::comments_active();
	}

	/** "Title (/path/)" of a checked page. */
	public static function page_label( $post_id ) {
		if ( ! $post_id ) {
			return __( 'Startseite', 'plakette' ) . ' (/)';
		}
		$path = (string) wp_parse_url( (string) get_permalink( $post_id ), PHP_URL_PATH );
		$q    = (string) wp_parse_url( (string) get_permalink( $post_id ), PHP_URL_QUERY );
		return self::page_title( $post_id ) . ' (' . $path . ( '' !== $q ? '?' . $q : '' ) . ')';
	}

	public static function page_title( $post_id ) {
		return $post_id ? wp_strip_all_tags( get_the_title( $post_id ) ) : __( 'Startseite', 'plakette' );
	}

	/** Media services embedded in post content: post id => service ids (iframes, embed blocks, oEmbed URLs). */
	public static function media_in_posts( Plakette_Context $ctx ) {
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
			return __( 'Ersetzen oder entfernen – siehe Prüfpunkt „Kein Cookie-Banner nötig“ (So wird deine Website bannerfrei)', 'plakette' );
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
		// What a real browser contacted (browser probe), if it is up to date.
		$probe = Plakette_Privacy_Evidence::probe();
		if ( Plakette_Privacy_Evidence::probe_fresh( $probe ) ) {
			foreach ( (array) $probe['hosts'] as $h ) {
				$hosts[ $h['host'] ] = array_values( array_unique( array_merge( isset( $hosts[ $h['host'] ] ) ? $hosts[ $h['host'] ] : array(), array( $h['type'] ) ) ) );
			}
		}
		$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		unset( $hosts[ $own ] );
		$notes   = array();
		$extra   = array();
		if ( isset( $found['vg-wort'] ) || array_filter( array_keys( $hosts ), function ( $h ) { return 'vg-wort' === Plakette_Services::for_url( $h ); } ) ) {
			list( $text, $links ) = Plakette_Services::vgwort_note();
			$extra['links']       = $links;
			$vg_note              = $text;
		}
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
		$known_hosts = self::service_hosts( $ctx );
		foreach ( $hosts as $host => $types ) {
			$id = Plakette_Services::for_url( $host );
			if ( ! $id && isset( $known_hosts[ $host ] ) ) {
				$id = $known_hosts[ $host ];
			}
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
			if ( isset( $vg_note ) ) {
				$extra['notes'] = array( $vg_note );
			}
			if ( $handled ) {
				return $this->pass( sprintf( _n( '%d fremder Dienst wird erst nach Einwilligung geladen, sonst nur der eigene Server.', '%d fremde Dienste werden erst nach Einwilligung geladen, sonst nur der eigene Server.', $handled, 'plakette' ), $handled ), $extra );
			}
			return $this->pass( isset( $vg_note ) ? __( 'Außer dem VG-Wort-Zählpixel lädt deine Seite alles vom eigenen Server.', 'plakette' ) : __( 'Deine Seite lädt alles vom eigenen Server.', 'plakette' ), $extra );
		}
		if ( isset( $vg_note ) ) {
			$notes[] = $vg_note;
		}
		$names = array_values( array_unique( $names ) );
		return $this->warn(
			sprintf(
				/* translators: 1: number, 2: list */
				_n( '%1$d fremder Dienst wird ohne Einwilligung kontaktiert: %2$s.', '%1$d fremde Dienste werden ohne Einwilligung kontaktiert: %2$s.', count( $names ), 'plakette' ),
				count( $names ),
				implode( ', ', array_slice( $names, 0, 8 ) ) . ( count( $names ) > 8 ? ' …' : '' )
			),
			array_merge( $extra, array( 'notes' => array_slice( $notes, 0, 20 ) ) )
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

/**
 * Evidence for the consent question, collected like a data-protection auditor does it:
 *  1. what a real browser stores and contacts on a first visit without any click (browser probe in the admin,
 *     admin.js › privacyProbe(), stored in the option plakette_privacy_probe),
 *  2. Set-Cookie headers the server sends to a first-time visitor,
 *  3. services found in the HTML of the home page and sample pages (Plakette_Services::detect).
 */
class Plakette_Privacy_Evidence {

	const PROBE = 'plakette_privacy_probe';

	/** Services the banner categories cover; others (fonts, CDN, reCAPTCHA …) a banner cannot hold back. */
	const BANNER_CATS = array( 'statistics', 'marketing', 'media' );

	public static function probe() {
		$p = get_option( self::PROBE );
		return is_array( $p ) && ! empty( $p['time'] ) ? $p : null;
	}

	/** Was the browser probe taken with the current set-up (modules, plugins, theme)? */
	public static function probe_fresh( $p ) {
		return $p && isset( $p['signature'] ) && Plakette_Consent::signature() === $p['signature'] && time() - (int) $p['time'] < 30 * DAY_IN_SECONDS;
	}

	/** Does this service need consent at all (and is not essential for a contract, e.g. payment)? */
	public static function relevant( $id ) {
		$s = Plakette_Services::get( $id );
		return $s && ! empty( $s['consent'] ) && 'necessary' !== $s['category'];
	}

	/**
	 * @return array {
	 *   probe: array|null, fresh: bool, tool: string,
	 *   services: id => [ state: loads|held|click, where: string[], src: string[] ],
	 *   entries: [ type: cookie|local|session|idb, name, src: server|browser, kb: array ] (each name once),
	 *   hosts: unknown third-party host => source,
	 * }
	 */
	public static function collect( Plakette_Context $ctx ) {
		$probe = self::probe();
		$fresh = self::probe_fresh( $probe );
		$e     = array(
			'disputed' => array(),
			'probe'    => $probe,
			'fresh'    => $fresh,
			'tool'     => Plakette_Modules::on( 'consent' ) ? 'Plakette' : Plakette_Services::consent_tool( $ctx->html() ),
			'services' => array(),
			'entries'  => array(),
			'hosts'    => array(),
			'checked'  => array(),
		);
		$add = function ( $id, $loads, $where, $src, $pages = array() ) use ( &$e ) {
			if ( ! self::relevant( $id ) ) {
				return;
			}
			if ( ! isset( $e['services'][ $id ] ) ) {
				$e['services'][ $id ] = array( 'state' => 'held', 'where' => array(), 'src' => array(), 'pages' => array() );
			}
			$e['services'][ $id ]['pages'] = array_values( array_unique( array_merge( $e['services'][ $id ]['pages'], (array) $pages ) ) );
			if ( $loads ) {
				$e['services'][ $id ]['state'] = 'loads';
			}
			$e['services'][ $id ]['where'] = array_values( array_unique( array_merge( $e['services'][ $id ]['where'], (array) $where ) ) );
			$e['services'][ $id ]['src']   = array_values( array_unique( array_merge( $e['services'][ $id ]['src'], array( $src ) ) ) );
		};

		// 3. HTML.
		$e['checked'] = array();
		foreach ( array_keys( $ctx->all_html() ) as $pid ) {
			$u              = $pid ? (string) get_permalink( $pid ) : home_url( '/' );
			$q              = (string) wp_parse_url( $u, PHP_URL_QUERY );
			$e['checked'][] = ( (string) wp_parse_url( $u, PHP_URL_PATH ) ?: '/' ) . ( '' !== $q ? '?' . $q : '' );
		}
		$e['disputed'] = array();
		foreach ( Plakette_Privacy_Check::detect_all( $ctx ) as $id => $hit ) {
			if ( ! empty( Plakette_Services::get( $id )['disputed'] ) ) {
				$e['disputed'][ $id ] = array_map( array( 'Plakette_Privacy_Check', 'page_label' ), array_unique( $hit['all'] ) );
			}
			$add( $id, ! $hit['blocked'], $hit['where'], 'html', array_map( array( 'Plakette_Privacy_Check', 'page_label' ), array_unique( $hit['all'] ) ) );
		}
		// Embeds in post content (pages that were not crawled count too) – unless the two-click solution is on.
		if ( ! Plakette_Modules::on( 'embeds' ) ) {
			foreach ( Plakette_Privacy_Check::media_in_posts( $ctx ) as $pid => $ids ) {
				foreach ( $ids as $id ) {
					$add( $id, true, __( 'Beitragsinhalt', 'plakette' ), 'content', array( Plakette_Privacy_Check::page_label( $pid ) ) );
				}
			}
		}
		// VG Wort pixels are usually inserted per post – scan the content, not only the crawled pages.
		foreach ( $ctx->posts() as $post ) {
			if ( false !== stripos( (string) $post->post_content, '.met.vgwort.de/' ) ) {
				$e['disputed']['vg-wort'][] = Plakette_Privacy_Check::page_label( $post->ID );
			}
		}
		// Gravatar: comment avatars appear on every post with comments.
		if ( get_option( 'show_avatars' ) && ! Plakette_Modules::on( 'local_avatars' ) && Plakette_Privacy_Check::comments_in_use() ) {
			$add( 'gravatar', true, 'gravatar.com', 'comments' );
		}
		$own   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$hosts = (array) $ctx->facts()['hosts'];
		foreach ( $ctx->samples() as $facts ) {
			$hosts += isset( $facts['hosts'] ) ? (array) $facts['hosts'] : array();
		}
		$known_hosts = Plakette_Privacy_Check::service_hosts( $ctx );
		foreach ( array_keys( $hosts ) as $host ) {
			if ( '' === Plakette_Services::for_url( $host ) && $host !== $own && ! isset( $known_hosts[ $host ] ) ) {
				$e['hosts'][ $host ] = 'html';
			}
		}

		// 2. Server cookies.
		$names = array();
		foreach ( $ctx->cookies() as $name ) {
			$names[ 'cookie|' . $name ] = array( 'cookie', $name, 'server', __( 'Startseite', 'plakette' ) );
		}
		// WordPress' emoji script writes "wpEmojiSettingsSupports" into sessionStorage on every first visit.
		if ( ! empty( $ctx->facts()['emoji'] ) ) {
			$names['session|wpEmojiSettingsSupports'] = array( 'session', 'wpEmojiSettingsSupports', 'html', __( 'Startseite', 'plakette' ) );
		}
		$path = function ( $url ) {
			$p = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
			$q = (string) wp_parse_url( (string) $url, PHP_URL_QUERY );
			$q = trim( preg_replace( '/(^|&)plakette_[a-z]+=[^&]*/', '', $q ), '&' );
			return ( '' === $p ? '/' : $p ) . ( '' !== $q ? '?' . $q : '' );
		};

		// 1. Browser.
		if ( $fresh ) {
			foreach ( (array) $probe['pages'] as $u ) {
				$e['checked'][] = $path( $u );
			}
			foreach ( (array) $probe['cookies'] as $c ) {
				$names[ 'cookie|' . $c['name'] ] = array( 'cookie', $c['name'], 'browser', $path( $c['page'] ) );
			}
			foreach ( (array) $probe['storage'] as $s ) {
				$names[ $s['area'] . '|' . $s['key'] ] = array( $s['area'], $s['key'], 'browser', $path( $s['page'] ) );
			}
			foreach ( (array) $probe['hosts'] as $h ) {
				$id = Plakette_Services::for_url( $h['host'] );
				if ( $id && ! empty( Plakette_Services::get( $id )['disputed'] ) ) {
					$e['disputed'][ $id ][] = $path( $h['page'] );
				}
				if ( $id ) {
					$add( $id, true, $h['host'], 'browser', array( $path( $h['page'] ) ) );
				} elseif ( strtolower( $h['host'] ) !== $own && ! isset( $known_hosts[ strtolower( $h['host'] ) ] ) ) {
					$e['hosts'][ $h['host'] ] = 'browser';
				}
			}
		}
		foreach ( $names as $n ) {
			$kb             = Plakette_Services::classify_storage( $n[1] );
			$e['entries'][] = array( 'type' => $n[0], 'name' => $n[1], 'src' => $n[2], 'kb' => $kb, 'page' => $n[3] );
			// A cookie of a known service proves that the service runs without consent.
			if ( 'no' === $kb['necessary'] && '' !== $kb['service'] ) {
				$add( $kb['service'], true, ( 'cookie' === $n[0] ? 'Cookie ' : 'Speicher ' ) . $n[1], 'cookie', array( $n[3] ) );
			}
		}
		$e['checked'] = array_values( array_unique( $e['checked'] ) );
		// Two-click placeholders are consent per embed – they do not need a banner.
		foreach ( $e['services'] as $id => $s ) {
			$cat = Plakette_Services::get( $id )['category'];
			if ( 'held' === $s['state'] && 'media' === $cat && Plakette_Modules::on( 'embeds' ) ) {
				$e['services'][ $id ]['state'] = 'click';
			}
		}
		return $e;
	}

	/**
	 * The measure that makes a service unnecessary without a banner.
	 *
	 * @return array [ key, label, sub, checked, avoidable, check ] or [ 'advice' => text, 'avoidable' => false ]
	 */
	public static function measure( $id ) {
		$s    = Plakette_Services::get( $id );
		$name = $s ? $s['name'] : $id;
		switch ( $id ) {
			case 'google-fonts':
				return array( 'key' => 'm:fonts', 'label' => __( 'Schriften lokal speichern (statt Google Fonts)', 'plakette' ), 'checked' => true, 'avoidable' => true, 'check' => 'privacy_google_fonts' );
			case 'public-cdn':
			case 'font-awesome':
				return array( 'key' => 'm:cdn', 'label' => __( 'Dateien von öffentlichen CDNs lokal ausliefern', 'plakette' ), 'checked' => true, 'avoidable' => true, 'check' => 'privacy_cdn' );
			case 'gravatar':
				return array( 'key' => 'm:avatars', 'label' => __( 'Profilbilder lokal erzeugen (statt Gravatar)', 'plakette' ), 'checked' => true, 'avoidable' => true, 'check' => 'privacy_gravatar' );
			case 'recaptcha':
				return array( 'advice' => __( 'Google reCAPTCHA: Spamschutz ohne Google nutzen – Honigtopf/Zeitfalle (Contact Form 7: Plugin „Honeypot for Contact Form 7“, WPForms: eingebauter Spamschutz) oder Friendly Captcha (EU-gehostet). Danach reCAPTCHA im Formular-Plugin abschalten.', 'plakette' ), 'avoidable' => false );
			case 'adobe-fonts':
				return array( 'advice' => __( 'Adobe Fonts: Schriften herunterladen und lokal einbinden (bei Adobe nur mit passender Lizenz) oder eine freie Alternative wählen.', 'plakette' ), 'avoidable' => false );
			case 'mailchimp':
				return array( 'advice' => __( 'Mailchimp: das Anmeldeformular als einfaches Formular auf deiner Seite einbauen (ohne Mailchimp-Skript) oder nur als Link zur Anmeldeseite.', 'plakette' ), 'avoidable' => false );
		}
		if ( ! $s ) {
			return array( 'advice' => '', 'avoidable' => false );
		}
		if ( 'media' === $s['category'] && in_array( $id, array( 'youtube', 'vimeo', 'google-maps', 'openstreetmap', 'spotify', 'soundcloud', 'calendly' ), true ) ) {
			$sub = '';
			if ( 'google-maps' === $id ) {
				$sub = __( 'Alternative ohne Klick: ein Bild der Karte mit Link „Route in Google Maps öffnen“.', 'plakette' );
			} elseif ( 'calendly' === $id ) {
				$sub = __( 'Alternative: statt der Einbettung ein Link „Termin buchen“ zu Calendly.', 'plakette' );
			}
			return array( 'key' => 'm:embeds', 'label' => __( 'Videos, Karten und Player erst nach Klick laden (Zwei-Klick-Lösung)', 'plakette' ), 'sub' => $sub, 'checked' => true, 'avoidable' => true, 'check' => 'privacy_embeds' );
		}
		$plugin = Plakette_Services::plugin_for( $id );
		$how    = $plugin
			/* translators: %s: plugin name */
			? sprintf( __( 'Kommt vom Plugin „%s“ – Plakette deaktiviert es (rückgängig machbar).', 'plakette' ), $plugin[1] )
			: __( 'Plakette entfernt die Skripte beim Ausliefern der Seiten (rückgängig machbar).', 'plakette' );
		if ( 'statistics' === $s['category'] ) {
			return array(
				'key'       => 'remove:' . $id,
				/* translators: %s: service */
				'label'     => sprintf( __( '%s entfernen – Besucherzahlen misst dann die Plakette-Statistik (ohne Cookies)', 'plakette' ), $name ),
				'sub'       => $how,
				'checked'   => true,
				'avoidable' => true,
			);
		}
		// Marketing pixels, social media posts: only with a banner – or not at all.
		return array(
			'key'       => 'remove:' . $id,
			/* translators: %s: service */
			'label'     => sprintf( __( '%s entfernen (darauf verzichten)', 'plakette' ), $name ),
			'sub'       => $how . ' ' . __( 'Nur mit Cookie-Banner erlaubt – brauchst du es wirklich?', 'plakette' ),
			'checked'   => false,
			'avoidable' => false,
		);
	}

	/** Plugins that start a PHP session (session_start) – to name the culprit of PHPSESSID. */
	public static function session_plugins() {
		$cached = get_transient( 'plakette_session_plugins' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all   = get_plugins();
		$found = array();
		$files = 0;
		foreach ( (array) get_option( 'active_plugins', array() ) as $base ) {
			$dir = WP_PLUGIN_DIR . '/' . dirname( $base );
			if ( '.' === dirname( $base ) || ! is_dir( $dir ) || 0 === strpos( $base, 'plakette/' ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( ++$files > 4000 ) {
					break 2;
				}
				if ( 'php' !== $file->getExtension() || $file->getSize() > 1048576 || false !== strpos( $file->getPathname(), '/vendor/' ) ) {
					continue;
				}
				$code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( preg_match( '/\bsession_start\s*\(/', $code ) ) {
					$found[] = isset( $all[ $base ]['Name'] ) ? $all[ $base ]['Name'] : dirname( $base );
					continue 2;
				}
			}
		}
		set_transient( 'plakette_session_plugins', $found, HOUR_IN_SECONDS );
		return $found;
	}

	/** Known consent plugins: dir prefix => name. */
	public static function consent_plugin_dirs() {
		return array(
			'borlabs-cookie'              => 'Borlabs Cookie',
			'complianz-gdpr'              => 'Complianz',
			'complianz-gdpr-premium'      => 'Complianz',
			'real-cookie-banner'          => 'Real Cookie Banner',
			'real-cookie-banner-pro'      => 'Real Cookie Banner',
			'cookie-law-info'             => 'CookieYes',
			'cookiebot'                   => 'Cookiebot',
			'gdpr-cookie-compliance'      => 'GDPR Cookie Compliance',
			'cookie-notice'               => 'Cookie Notice',
			'iubenda-cookie-law-solution' => 'iubenda',
			'usercentrics-cmp'            => 'Usercentrics',
			'plk-test-consent-plugin'     => 'Test-Cookie-Plugin',
		);
	}

	/** Active consent plugin: [ basename, name ] or null. */
	public static function consent_plugin() {
		foreach ( (array) get_option( 'active_plugins', array() ) as $base ) {
			$dir = dirname( $base );
			if ( isset( self::consent_plugin_dirs()[ $dir ] ) ) {
				return array( $base, self::consent_plugin_dirs()[ $dir ] );
			}
		}
		return null;
	}

	/** Lines with the raw evidence for the report. */
	public static function notes( array $e ) {
		$notes = array();
		$p     = $e['probe'];
		$label = array(
			'cookie'  => __( 'Cookie', 'plakette' ),
			'local'   => 'localStorage',
			'session' => 'sessionStorage',
			'idb'     => 'IndexedDB',
		);
		$need  = array(
			'yes'     => __( 'technisch notwendig', 'plakette' ),
			'no'      => __( 'nicht notwendig – braucht Einwilligung', 'plakette' ),
			'depends' => __( 'prüfen', 'plakette' ),
		);
		if ( $e['fresh'] ) {
			$cookies = 0;
			$storage = 0;
			foreach ( $e['entries'] as $en ) {
				if ( 'browser' === $en['src'] ) {
					'cookie' === $en['type'] ? ++$cookies : ++$storage;
				}
			}
			$third = count( (array) $p['hosts'] );
			$notes[] = sprintf(
				/* translators: 1: date, 2: pages, 3: cookies, 4: storage entries, 5: hosts */
				__( 'Browser-Messung vom %1$s (%2$d Seiten, erster Besuch, ohne Klick): %3$d neue Cookies, %4$d Speicher-Einträge, %5$d fremde Server.', 'plakette' ),
				wp_date( 'd.m.Y H:i', (int) $p['time'] ),
				count( (array) $p['pages'] ),
				$cookies,
				$storage,
				$third
			);
		} elseif ( $p ) {
			$notes[] = __( 'Die letzte Browser-Messung ist veraltet – seitdem hat sich etwas geändert. Starte oben „Datenschutz-Messung“, damit Plakette sieht, was ein Browser wirklich speichert.', 'plakette' );
		} else {
			$notes[] = __( 'Noch keine Browser-Messung: Das Ergebnis stammt aus dem Seitenquelltext und den Cookies des Servers. Für Gewissheit starte oben „Datenschutz-Messung“.', 'plakette' );
		}
		foreach ( $e['entries'] as $en ) {
			$kb      = $en['kb'];
			$line    = $label[ $en['type'] ] . ' „' . $en['name'] . '“ – ' . $kb['label'] . ' – ' . $need[ $kb['necessary'] ];
			$line   .= 'server' === $en['src'] ? ' ' . __( '(vom Server gesetzt)', 'plakette' ) : ( 'html' === $en['src'] ? ' ' . __( '(aus dem Seitenquelltext abgeleitet)', 'plakette' ) : '' );
			if ( 'yes' !== $kb['necessary'] ) {
				$line .= '. ' . $kb['reason'];
				if ( 'PHPSESSID' === $en['name'] ) {
					$who   = self::session_plugins();
					$line .= ' ' . ( $who
						/* translators: %s: plugin names */
						? sprintf( __( 'Startet eine Sitzung: %s.', 'plakette' ), implode( ', ', $who ) )
						: __( 'Kein aktives Plugin ruft session_start() auf – vermutlich das Theme oder der Server.', 'plakette' ) );
				}
				if ( '' !== $kb['measure'] ) {
					$line .= ' → ' . $kb['measure'];
				}
			}
			$notes[] = $line;
		}
		$state = array(
			'loads' => __( 'lädt ohne Einwilligung', 'plakette' ),
			'held'  => __( 'wartet auf Einwilligung (Cookie-Banner)', 'plakette' ),
			'click' => __( 'lädt erst nach Klick (Zwei-Klick-Lösung)', 'plakette' ),
		);
		foreach ( $e['services'] as $id => $s ) {
			$notes[] = __( 'Dienst', 'plakette' ) . ' ' . Plakette_Services::get( $id )['name'] . ' (' . implode( ', ', array_slice( $s['where'], 0, 3 ) ) . ') – ' . $state[ $s['state'] ];
		}
		foreach ( $e['hosts'] as $host => $src ) {
			$notes[] = __( 'Fremder Server', 'plakette' ) . ' ' . $host . ' – ' . __( 'unbekannt – prüfen, welches Plugin ihn einbindet und ob er nötig ist', 'plakette' );
		}
		return $notes;
	}
}

class Plakette_Check_Privacy_Consent extends Plakette_Privacy_Check {
	public $id        = 'privacy_consent';
	public $group     = 'DSGVO & Cookies';
	public $weight    = 9;
	public $title     = 'Kein Cookie-Banner nötig';
	public $problem   = 'Ein Cookie-Banner ist nötig – das lässt sich vermeiden';
	public $explain   = 'Ein Cookie-Banner ist nur nötig, wenn deine Seite beim ersten Besuch etwas im Browser speichert, das nicht technisch notwendig ist (§ 25 TDDDG), oder Daten an fremde Dienste schickt (DSGVO). Fehlt dann die Einwilligung, drohen Abmahnungen. Besser als jeder Banner: Diese Dienste ersetzen – dann brauchst du gar keinen. Banner nerven Besucher und kosten Anfragen und Umsatz.';
	public $fix_label = 'Ausgewählte Maßnahmen umsetzen';
	public $fix_does  = 'Setzt die ausgewählten Maßnahmen um. Sind alle erledigt, braucht deine Website keinen Cookie-Banner mehr: nichts wird ohne Klick gespeichert, keine fremden Dienste laden ungefragt. Was du behalten willst und eine Einwilligung braucht, hält der Plakette-Cookie-Banner zurück.';
	public $risk      = 'review';
	public $kind      = 'items';

	/** Splits the evidence into what still needs consent. */
	private static function analyse( array $e ) {
		$a = array( 'loads' => array(), 'held' => array(), 'review' => array(), 'avoidable' => array(), 'keep' => array(), 'advice' => array() );
		foreach ( $e['services'] as $id => $s ) {
			if ( 'click' === $s['state'] ) {
				continue;
			}
			$a[ $s['state'] ][] = $id;
			$m                  = Plakette_Privacy_Evidence::measure( $id );
			if ( ! empty( $m['avoidable'] ) ) {
				$a['avoidable'][] = $id;
			} else {
				$a['keep'][] = $id;
				if ( ! empty( $m['advice'] ) ) {
					$a['advice'][] = $m['advice'];
				}
			}
		}
		$a['fixes'] = array();
		foreach ( $e['entries'] as $en ) {
			if ( 'no' === $en['kb']['necessary'] && ! empty( $en['kb']['fix'] ) ) {
				$a['fixes'][ $en['kb']['fix'] ] = $en;
			} elseif ( 'depends' === $en['kb']['necessary'] || ( 'no' === $en['kb']['necessary'] && '' === $en['kb']['service'] ) ) {
				$a['review'][] = $en['name'];
			}
		}
		foreach ( array_keys( $e['hosts'] ) as $host ) {
			$a['review'][] = $host;
		}
		return $a;
	}

	/** Pages that need consent: path => [ label, service names ]. */
	private static function per_page( array $e, array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			foreach ( (array) $e['services'][ $id ]['pages'] as $label ) {
				$key = preg_match( '/\(([^()]*)\)$/', $label, $m ) ? $m[1] : $label;
				if ( ! isset( $out[ $key ] ) || strlen( $label ) > strlen( $out[ $key ][0] ) ) {
					$out[ $key ] = array( $label, isset( $out[ $key ] ) ? $out[ $key ][1] : array() );
				}
				$out[ $key ][1][] = self::service_name( $id );
			}
		}
		foreach ( $out as $k => $v ) {
			$out[ $k ][1] = array_values( array_unique( $v[1] ) );
		}
		return $out;
	}

	private static function pages_text( array $e, array $ids ) {
		$pages = self::per_page( $e, $ids );
		if ( ! $pages ) {
			return '';
		}
		$total = count( array_unique( array_merge( array_keys( $pages ), (array) $e['checked'] ) ) );
		// Services on every checked page are named once; the rest per page.
		$every   = null;
		$crawled = 0;
		foreach ( $pages as $key => $p ) {
			if ( in_array( $key, (array) $e['checked'], true ) ) {
				++$crawled;
				$every = null === $every ? $p[1] : array_values( array_intersect( $every, $p[1] ) );
			}
		}
		$every = $crawled > 1 && $crawled >= count( (array) $e['checked'] ) ? (array) $every : array();
		$text  = ' ' . sprintf( /* translators: 1: pages, 2: checked pages */ __( 'Einwilligung nötig auf %1$d von %2$d geprüften Seiten.', 'plakette' ), count( $pages ), $total );
		if ( $every ) {
			$text .= ' ' . sprintf( __( 'Auf jeder Seite: %s.', 'plakette' ), implode( ', ', $every ) );
		}
		$list = array();
		foreach ( $pages as $p ) {
			$rest = array_values( array_diff( $p[1], $every ) );
			if ( $rest ) {
				$list[] = $p[0] . ': ' . implode( ', ', $rest );
			}
		}
		if ( $list ) {
			$text .= ' ' . ( $every ? __( 'Zusätzlich', 'plakette' ) : __( 'Seiten', 'plakette' ) ) . ' – ' . implode( '; ', array_slice( $list, 0, 5 ) ) . ( count( $list ) > 5 ? ' …' : '' ) . '.';
		}
		return $text;
	}

	/** Items for storage entries that another check's fix removes. */
	private static function fix_items( array $fixes ) {
		$items = array();
		foreach ( $fixes as $check_id => $en ) {
			$items[] = array(
				'id'      => 'check:' . $check_id,
				'label'   => $en['kb']['measure'],
				'sub'     => sprintf( __( 'Entfernt „%1$s“ (%2$s).', 'plakette' ), $en['name'], $en['kb']['label'] ),
				'checked' => true,
			);
		}
		return $items;
	}

	private static function names( array $ids ) {
		return implode( ', ', array_map( array( 'Plakette_Privacy_Check', 'service_name' ), array_values( array_unique( $ids ) ) ) );
	}

	/** Items (measures) for a list of services. */
	private static function items( array $e, array $ids, $with_banner, $banner_checked ) {
		$items = array();
		$pages = array();
		foreach ( array_unique( $ids ) as $id ) {
			$m = Plakette_Privacy_Evidence::measure( $id );
			if ( empty( $m['key'] ) ) {
				continue;
			}
			foreach ( (array) $e['services'][ $id ]['pages'] as $label ) {
				$pages[ $m['key'] ][] = preg_match( '/\(([^()]*)\)$/', $label, $mm ) ? $mm[1] : $label;
			}
			if ( ! isset( $items[ $m['key'] ] ) ) {
				$items[ $m['key'] ] = array(
					'id'      => $m['key'],
					'label'   => $m['label'],
					'sub'     => trim( ( isset( $m['sub'] ) ? $m['sub'] : '' ) ),
					'checked' => ! empty( $m['checked'] ),
				);
			}
		}
		foreach ( $pages as $key => $list ) {
			$list                 = array_values( array_unique( $list ) );
			$items[ $key ]['sub'] = trim( $items[ $key ]['sub'] . ' ' . __( 'Betrifft:', 'plakette' ) . ' ' . implode( ', ', array_slice( $list, 0, 6 ) ) . ( count( $list ) > 6 ? ' …' : '' ) );
		}
		if ( $with_banner ) {
			$items['banner'] = array(
				'id'      => 'banner',
				'label'   => __( 'Plakette-Cookie-Banner einschalten', 'plakette' ),
				'sub'     => __( 'Für alles, was du behältst und eine Einwilligung braucht. Wird nur eingeschaltet, wenn nach den anderen Maßnahmen noch etwas übrig ist.', 'plakette' ),
				'checked' => $banner_checked,
			);
		}
		return array_values( $items );
	}

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$e     = Plakette_Privacy_Evidence::collect( $ctx );
		$a     = self::analyse( $e );
		$notes = Plakette_Privacy_Evidence::notes( $e );
		$tool  = $e['tool'];
		$need  = array_merge( $a['loads'], $a['held'] );
		$fixes = $a['fixes'];
		$keep  = array_values( array_intersect( $a['keep'], $need ) );
		$keep_banner = array_values(
			array_filter(
				$keep,
				function ( $id ) {
					return in_array( Plakette_Services::get( $id )['category'], Plakette_Privacy_Evidence::BANNER_CATS, true );
				}
			)
		);
		$without = $keep ? ' ' . sprintf(
			/* translators: %s: services */
			__( 'Ohne Banner geht es nur, wenn du auf %s verzichtest.', 'plakette' ),
			self::names( $keep )
		) : '';
		foreach ( $a['advice'] as $advice ) {
			$notes[] = __( 'So geht es ohne Banner:', 'plakette' ) . ' ' . $advice;
		}
		$links = array();
		if ( isset( $e['disputed']['vg-wort'] ) ) {
			list( $text, $links ) = Plakette_Services::vgwort_note();
			$pages                = array_values( array_unique( $e['disputed']['vg-wort'] ) );
			$notes[]              = $text . ' ' . __( 'Gefunden auf:', 'plakette' ) . ' ' . implode( ', ', array_slice( $pages, 0, 5 ) ) . '.';
		}
		$with = function ( array $extra ) use ( $links ) {
			if ( $links ) {
				$extra['links'] = $links;
			}
			return $extra;
		};

		// Only storage entries a one-click fix removes (e.g. the emoji script) – handled like an avoidable service.
		if ( ! $need && $fixes ) {
			$items = self::fix_items( $fixes );
			return $this->fail(
				sprintf( __( 'Beim ersten Besuch wird etwas im Browser gespeichert: %s. Das lässt sich ohne Banner lösen – mit den Maßnahmen unten.', 'plakette' ), implode( ', ', array_map( function ( $en ) { return $en['name'] . ' (' . $en['kb']['label'] . ')'; }, $fixes ) ) ),
				$with( array( 'items' => $items, 'notes' => $notes ) )
			);
		}

		// Nothing needs consent.
		if ( ! $need ) {
			if ( '' !== $tool ) {
				$plugin = Plakette_Privacy_Evidence::consent_plugin();
				$label  = 'Plakette' === $tool ? __( 'Plakette-Cookie-Banner ausschalten', 'plakette' ) : sprintf( __( '%s deaktivieren', 'plakette' ), $plugin ? $plugin[1] : $tool );
				$extra  = array(
					'problem'   => __( 'Dein Cookie-Banner ist überflüssig', 'plakette' ),
					'notes'     => $notes,
					'kind'      => 'auto',
					'fix_label' => $label,
					'fix_does'  => 'Plakette' === $tool
						? __( 'Schaltet den Cookie-Banner von Plakette ab. Deine Besucher sehen sofort die Seite.', 'plakette' )
						: __( 'Deaktiviert das Cookie-Banner-Plugin. Deine Besucher sehen sofort die Seite. Über das Protokoll lässt es sich wieder aktivieren.', 'plakette' ),
				);
				if ( 'Plakette' !== $tool && ! $plugin ) {
					$extra['kind']    = 'none';
					$extra['blocked'] = sprintf( __( '%s ist nicht als Plugin aktiv (z. B. per Code oder Tag Manager eingebunden) – entferne es dort.', 'plakette' ), $tool );
				}
				return $this->warn(
					sprintf( __( 'Dein Cookie-Banner (%s) ist überflüssig: Beim ersten Besuch wird nichts gespeichert und kein fremder Dienst geladen, der eine Einwilligung braucht. Banner nerven Besucher und kosten Anfragen und Umsatz – schalte ihn ab.', 'plakette' ), $tool ),
					$with( $extra )
				);
			}
			if ( $a['review'] ) {
				return $this->warn(
					sprintf( __( 'Wahrscheinlich kein Cookie-Banner nötig – prüfe aber diese Einträge: %s.', 'plakette' ), implode( ', ', array_unique( $a['review'] ) ) ),
					$with( array( 'kind' => 'none', 'notes' => $notes, 'problem' => __( 'Fast kein Cookie-Banner nötig – ein paar Einträge prüfen', 'plakette' ) ) )
				);
			}
			return $this->pass(
				__( 'Kein Cookie-Banner nötig: Beim ersten Besuch werden keine unnötigen Cookies oder Speicher-Einträge angelegt, und es laden keine fremden Dienste, die eine Einwilligung brauchen.', 'plakette' ),
				$with( array( 'notes' => $notes ) )
			);
		}

		// Everything that needs consent is held back by a banner.
		if ( ! $a['loads'] ) {
			$avoid = array_values( array_intersect( $a['avoidable'], $a['held'] ) );
			if ( $avoid ) {
				$items   = self::items( $e, $avoid, false, false );
				$items[] = array(
					'id'      => 'off',
					'label'   => __( 'Danach den Cookie-Banner ausschalten', 'plakette' ),
					'sub'     => __( 'Nur, wenn nach den Maßnahmen nichts mehr eine Einwilligung braucht.', 'plakette' ),
					'checked' => ! $keep_banner,
				);
				return $this->warn(
					sprintf( __( 'Dein Cookie-Banner (%1$s) hält %2$s zurück. Das lässt sich ohne Banner lösen.', 'plakette' ), $tool, self::names( $a['held'] ) ) . self::pages_text( $e, $a['held'] ) . $without,
					$with( array( 'items' => $items, 'notes' => $notes, 'problem' => __( 'Dein Cookie-Banner lässt sich vermeiden', 'plakette' ) ) )
				);
			}
			return $this->pass(
				sprintf( __( 'Ein Cookie-Banner ist nötig und aktiv (%1$s): Er hält %2$s zurück, bis Besucher zustimmen.', 'plakette' ), $tool, self::names( $a['held'] ) ) . self::pages_text( $e, $a['held'] ) . $without,
				$with( array( 'notes' => $notes, 'title' => __( 'Cookie-Banner nötig und aktiv', 'plakette' ) ) )
			);
		}

		// Something loads without consent.
		$avoid       = array_values( array_intersect( $a['avoidable'], $need ) );
		$keep_loads  = array_values( array_intersect( $keep_banner, $a['loads'] ) );
		$items       = self::items( $e, array_merge( $avoid, $keep ), (bool) $keep_banner || (bool) array_intersect( $avoid, $need ), (bool) $keep_loads && 'Plakette' !== $tool );
		$detail      = sprintf( __( 'Ohne Einwilligung laden: %s.', 'plakette' ), self::names( $a['loads'] ) );
		if ( $avoid && ! $keep ) {
			$detail .= ' ' . __( 'Das lässt sich alles ohne Banner lösen – mit den Maßnahmen unten.', 'plakette' );
		} elseif ( $avoid ) {
			$detail .= ' ' . sprintf( __( 'Ohne Banner lösbar mit den Maßnahmen unten: %s.', 'plakette' ), self::names( $avoid ) );
		}
		$detail .= self::pages_text( $e, $need ) . $without;
		$extra = array( 'notes' => $notes );
		if ( ! $avoid ) {
			$extra['problem'] = __( 'Ein Cookie-Banner ist nötig', 'plakette' );
		}
		if ( ! $items ) {
			$extra['kind'] = 'none';
		}
		$extra['items'] = array_merge( $items, self::fix_items( $fixes ) );
		if ( $extra['items'] ) {
			unset( $extra['kind'] );
		}
		return $this->fail( $detail, $with( $extra ) );
	}

	public function fix( array $req, array &$undo ) {
		$picked = array();
		foreach ( (array) $req['items'] as $it ) {
			if ( isset( $it['id'] ) ) {
				$picked[ (string) $it['id'] ] = true;
			}
		}
		$ctx  = new Plakette_Context();
		$e    = Plakette_Privacy_Evidence::collect( $ctx );
		$done = array();
		$left = array();

		// Superfluous banner (no items) – switch it off.
		$need = array();
		foreach ( $e['services'] as $id => $s ) {
			if ( 'click' !== $s['state'] ) {
				$need[] = $id;
			}
		}
		$storage_fixes = array();
		foreach ( $e['entries'] as $en ) {
			if ( 'no' === $en['kb']['necessary'] && ! empty( $en['kb']['fix'] ) ) {
				$storage_fixes[ $en['kb']['fix'] ] = true;
			}
		}
		if ( ! $need && ! $storage_fixes && empty( $picked ) ) {
			return $this->banner_off( $e, $undo );
		}
		foreach ( array_keys( $picked ) as $key ) {
			if ( 0 === strpos( $key, 'check:' ) && isset( $storage_fixes[ substr( $key, 6 ) ] ) ) {
				$check = Plakette_Auditor::get( substr( $key, 6 ) );
				if ( $check ) {
					$res    = $check->fix( array( 'value' => '', 'items' => array() ), $undo );
					$done[] = is_wp_error( $res ) ? $res->get_error_message() : $check->title . ': ' . $res;
				}
			}
		}

		$stats  = false;
		$remove = array();
		$checks = array();
		foreach ( $need as $id ) {
			$m = Plakette_Privacy_Evidence::measure( $id );
			if ( empty( $m['key'] ) || empty( $picked[ $m['key'] ] ) ) {
				$left[] = $id;
				continue;
			}
			if ( ! empty( $m['check'] ) ) {
				$checks[ $m['check'] ] = $m['label'];
			} elseif ( 0 === strpos( $m['key'], 'remove:' ) ) {
				$remove[] = $id;
				$stats    = $stats || 'statistics' === Plakette_Services::get( $id )['category'];
			}
		}
		foreach ( $checks as $check_id => $label ) {
			$check = Plakette_Auditor::get( $check_id );
			if ( ! $check ) {
				continue;
			}
			$res    = $check->fix( array( 'value' => '', 'items' => array() ), $undo );
			$done[] = is_wp_error( $res ) ? $label . ': ' . $res->get_error_message() : $res;
		}
		if ( $remove ) {
			$strip = array();
			foreach ( $remove as $id ) {
				$plugin = Plakette_Services::plugin_for( $id );
				if ( $plugin && is_plugin_active( $plugin[0] ) ) {
					deactivate_plugins( $plugin[0], true );
					$undo[] = array( 'activate_plugin', $plugin[0] );
					$done[] = sprintf( __( 'Plugin „%s“ deaktiviert.', 'plakette' ), $plugin[1] );
				} elseif ( ! $plugin ) {
					$strip[] = $id;
				}
			}
			if ( $strip ) {
				Plakette_Remove_Services::add( $strip, $undo );
				$done[] = sprintf( __( 'Entfernt: %s.', 'plakette' ), self::names( $strip ) );
			}
		}
		if ( $stats && ! Plakette_Modules::on( 'stats' ) ) {
			if ( ! get_option( Plakette_Stats::DB_OPTION ) ) {
				$undo[] = array( 'callback', 'Plakette_Stats::uninstall' );
			}
			Plakette_Log::set_module( 'stats', true, $undo );
			Plakette_Stats::install();
			$done[] = __( 'Die Plakette-Statistik zählt jetzt Besuche – ohne Cookies, ohne Einwilligung.', 'plakette' );
		}

		$left_banner = array_values(
			array_filter(
				$left,
				function ( $id ) {
					return in_array( Plakette_Services::get( $id )['category'], Plakette_Privacy_Evidence::BANNER_CATS, true );
				}
			)
		);
		if ( $left_banner && ! empty( $picked['banner'] ) && ! Plakette_Modules::on( 'consent' ) ) {
			Plakette_Consent::remember( $left_banner, $undo );
			Plakette_Log::set_module( 'consent', true, $undo );
			$done[] = sprintf( __( 'Der Plakette-Cookie-Banner ist eingeschaltet und hält %s bis zur Einwilligung zurück.', 'plakette' ), self::names( $left_banner ) );
		}
		if ( ! $left_banner && ! empty( $picked['off'] ) ) {
			$off = $this->banner_off( $e, $undo );
			if ( is_string( $off ) ) {
				$done[] = $off;
			}
		}
		Plakette_Page_Cache::purge_all();

		$msg = implode( ' ', $done );
		if ( ! $left ) {
			$msg .= ' ' . __( 'Damit braucht deine Website keinen Cookie-Banner mehr. Starte zur Kontrolle die Datenschutz-Messung.', 'plakette' );
		} else {
			$msg .= ' ' . sprintf( __( 'Noch offen: %s.', 'plakette' ), self::names( $left ) );
			if ( Plakette_Modules::on( 'consent' ) && $left_banner ) {
				$msg .= ' ' . sprintf( __( 'Ohne Banner geht es nur, wenn du auf %s verzichtest.', 'plakette' ), self::names( $left_banner ) );
			}
		}
		return trim( $msg );
	}

	/** Switch a superfluous banner off (ours or a consent plugin). */
	private function banner_off( array $e, array &$undo ) {
		if ( Plakette_Modules::on( 'consent' ) ) {
			Plakette_Log::set_module( 'consent', null, $undo );
			Plakette_Page_Cache::purge_all();
			return __( 'Der Cookie-Banner ist ausgeschaltet. Deine Besucher sehen sofort die Seite.', 'plakette' );
		}
		$plugin = Plakette_Privacy_Evidence::consent_plugin();
		if ( $plugin ) {
			deactivate_plugins( $plugin[0], true );
			$undo[] = array( 'activate_plugin', $plugin[0] );
			Plakette_Page_Cache::purge_all();
			return sprintf( __( '%s ist deaktiviert. Deine Besucher sehen sofort die Seite.', 'plakette' ), $plugin[1] );
		}
		return new WP_Error( 'plakette_consent', __( 'Es ist kein Cookie-Banner aktiv, den Plakette abschalten könnte.', 'plakette' ) );
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
				'Plakette_Check_Privacy_Consent',
				'Plakette_Check_Privacy_Embeds',
				'Plakette_Check_Privacy_Gravatar',
				'Plakette_Check_Privacy_Comment_Ip',
				'Plakette_Check_Privacy_Comment_Consent',
			)
		);
	},
	20
);
