<?php
/**
 * SEO checks for site-wide settings and the HTML of the home page.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Check_Visibility extends Plakette_Check {
	public $id        = 'seo_visibility';
	public $group     = 'Grundeinstellungen';
	public $weight    = 10;
	public $title     = 'Suchmaschinen dürfen deine Website besuchen';
	public $problem   = 'Suchmaschinen werden ausgesperrt';
	public $explain   = 'In den WordPress-Einstellungen ist das Häkchen „Suchmaschinen davon abhalten, diese Website zu indexieren“ gesetzt. Damit bittest du Google, deine Seite nicht anzuzeigen. Das ist nur während des Aufbaus sinnvoll.';
	public $fix_label = 'Suchmaschinen erlauben';
	public $fix_does  = 'Entfernt das Häkchen unter Einstellungen › Lesen. Google kann deine Seiten danach in den nächsten Tagen aufnehmen.';

	public function run( Plakette_Context $ctx ) {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return $this->fail( __( 'Einstellung „Sichtbarkeit für Suchmaschinen“ ist aktiv.', 'plakette' ) );
		}
		return $this->pass( __( 'Suchmaschinen sind willkommen.', 'plakette' ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_option( 'blog_public', '1', $undo );
		return __( 'Suchmaschinen sind jetzt erlaubt.', 'plakette' );
	}
}

class Plakette_Check_Permalinks extends Plakette_Check {
	public $id        = 'seo_permalinks';
	public $group     = 'Grundeinstellungen';
	public $weight    = 7;
	public $title     = 'Sprechende Adressen (Permalinks)';
	public $problem   = 'Adressen bestehen nur aus Zahlen';
	public $explain   = 'Deine Seiten haben Adressen wie „?p=123“. Adressen mit Wörtern wie „/kuchen-rezept/“ verstehen Menschen und Google besser, und sie werden häufiger angeklickt.';
	public $fix_label = 'Sprechende Adressen einschalten';
	public $fix_does  = 'Stellt die Permalinks auf „Beitragsname“ um. Alte Links leitet WordPress automatisch weiter. Plakette testet danach eine Seite und nimmt die Änderung sofort zurück, falls der Server sie nicht unterstützt.';
	public $risk      = 'review';

	public function run( Plakette_Context $ctx ) {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return $this->fail( __( 'Einstellung: „Einfach“ (?p=123).', 'plakette' ) );
		}
		return $this->pass( sprintf( __( 'Struktur: %s', 'plakette' ), get_option( 'permalink_structure' ) ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_option( 'permalink_structure', '/%postname%/', $undo );
		flush_rewrite_rules( true );

		// Verify on a real post that the server knows the new addresses.
		$post = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) );
		if ( $post ) {
			$res = Plakette_Crawler::fetch( get_permalink( $post[0] ) );
			if ( ! $res['ok'] || $res['status'] >= 400 ) {
				update_option( 'permalink_structure', '' );
				flush_rewrite_rules( true );
				$undo = array();
				return new WP_Error(
					'plakette_permalinks',
					__( 'Der Server kennt die neuen Adressen noch nicht (Seite nicht gefunden). Die Änderung wurde sofort zurückgenommen. Bitte deinen Hoster, „mod_rewrite“ bzw. „Pretty Permalinks“ für WordPress einzurichten.', 'plakette' )
				);
			}
		}
		return __( 'Sprechende Adressen sind aktiv und funktionieren.', 'plakette' );
	}
}

class Plakette_Check_Https extends Plakette_Check {
	public $id        = 'security_https';
	public $area      = 'security';
	public $group     = 'Schutz vor Angriffen';
	public $weight    = 8;
	public $title     = 'Sichere Verbindung (HTTPS)';
	public $problem   = 'Die Website läuft ohne sichere Verbindung';
	public $explain   = 'Ohne HTTPS kann jeder im selben WLAN mitlesen, was Besucher eintippen – auch Passwörter und Formulare. Die DSGVO verlangt Verschlüsselung, sobald personenbezogene Daten übertragen werden. Browser zeigen sonst „Nicht sicher“, und Google bewertet die Seite schlechter.';
	public $fix_label = 'Auf HTTPS umstellen';
	public $fix_does  = 'Stellt die Adresse der Website auf https:// um, ersetzt unsichere Links beim Ausliefern und leitet alle http-Aufrufe dauerhaft weiter. Eventuell musst du dich danach neu anmelden.';
	public $risk      = 'review';

	public function run( Plakette_Context $ctx ) {
		$home = home_url( '/' );
		if ( 0 === strpos( $home, 'https://' ) ) {
			$probe = self::probe_http( $home );
			if ( ! $probe['open'] ) {
				return $this->pass( __( 'Die Website nutzt HTTPS, die http-Adresse leitet weiter.', 'plakette' ) );
			}
			$extra  = array(
				'notes'     => $probe['notes'],
				'fix_label' => __( 'Weiterleitung auf HTTPS einschalten', 'plakette' ),
				'fix_does'  => __( 'Trägt die Weiterleitung von http auf https ganz oben in die Datei .htaccess ein. Dort greift sie, bevor WordPress oder ein Seiten-Cache antwortet. Plakette testet danach, ob die Website erreichbar bleibt, und nimmt die Änderung sonst sofort zurück.', 'plakette' ),
			);
			$detail = $probe['cache']
				? __( 'HTTPS ist eingerichtet, aber über die unsichere http-Adresse liefert ein Seiten-Cache die Seite ohne Weiterleitung aus.', 'plakette' )
				: __( 'HTTPS ist eingerichtet, aber die unsichere http-Adresse zeigt die Seite noch ohne Weiterleitung an.', 'plakette' );
			if ( ! Plakette_Env::htaccess_writable() && ( $probe['cache'] || Plakette_Modules::on( 'force_https' ) ) ) {
				// The redirect inside WordPress is not enough here and Plakette cannot write server rules.
				$extra['kind']   = 'letter';
				$extra['letter'] = $this->letter( sprintf( __( 'Bitte richten Sie für die Domain %s eine serverseitige, dauerhafte Weiterleitung (301) aller http-Aufrufe auf https ein. Aktuell wird die Seite über http ohne Weiterleitung ausgeliefert (aus dem Seiten-Cache).', 'plakette' ), wp_parse_url( $home, PHP_URL_HOST ) ) );
			}
			if ( $probe['hsts'] ) {
				// Returning browsers never use http again – a gap for first visits through old links, not missing encryption.
				$extra['weight'] = 4;
				return $this->warn( $detail . ' ' . __( 'Browser, die schon einmal da waren, erzwingen bei dir HTTPS (HSTS) – betroffen sind nur erste Aufrufe über alte http-Links.', 'plakette' ), $extra );
			}
			return $this->fail( $detail, $extra );
		}
		$https = wp_remote_get( set_url_scheme( $home, 'https' ), array( 'timeout' => 10, 'sslverify' => true ) );
		if ( is_wp_error( $https ) || wp_remote_retrieve_response_code( $https ) >= 400 ) {
			return $this->fail(
				__( 'Für deine Adresse ist noch kein SSL-Zertifikat eingerichtet. Das kann nur dein Hoster – bei fast allen ist es kostenlos (Let’s Encrypt).', 'plakette' ),
				array(
					'kind'   => 'letter',
					'letter' => $this->letter( sprintf( __( 'Bitte richten Sie für die Domain %s ein SSL-Zertifikat ein (z. B. kostenlos über Let’s Encrypt), damit die Website über HTTPS erreichbar ist.', 'plakette' ), wp_parse_url( $home, PHP_URL_HOST ) ) ),
				)
			);
		}
		return $this->fail( __( 'Dein Zertifikat funktioniert bereits, WordPress nutzt aber noch die http-Adresse.', 'plakette' ) );
	}

	const MARKER = 'Plakette HTTPS';

	/**
	 * Does the http address show the site instead of redirecting to https? Asked from the server itself, like a
	 * first-time visitor (no cookies), with proof for the report. A second request with an unknown parameter goes
	 * past page caches: when that one redirects, a cache answers the first one before WordPress can redirect
	 * (seen with WP-Optimize: it stores one copy per address and serves it for http and https alike).
	 *
	 * @return array [ open (bool), cache (bool), hsts (bool), url, notes (string[]) ]
	 */
	public static function probe_http( $home ) {
		$url  = set_url_scheme( $home, 'http' );
		$out  = array( 'open' => false, 'cache' => false, 'hsts' => false, 'url' => $url, 'notes' => array() );
		$ask  = function ( $try ) {
			$res = wp_remote_get( $try, array( 'redirection' => 0, 'timeout' => 10, 'sslverify' => false, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Plakette' ) );
			if ( is_wp_error( $res ) ) {
				return null; // Port 80 closed or not reachable from the server itself: nothing is shown over http.
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = (string) wp_remote_retrieve_body( $res );
			$loc  = (string) wp_remote_retrieve_header( $res, 'location' );
			$via  = array();
			foreach ( array( 'server', 'wpo-cache-status', 'x-cache', 'x-plakette-cache', 'x-litespeed-cache', 'cf-cache-status', 'x-cache-enabled', 'x-cache-status', 'x-rocket-cache', 'x-varnish', 'via', 'age' ) as $h ) {
				$v = wp_remote_retrieve_header( $res, $h );
				if ( is_string( $v ) && '' !== $v ) {
					$via[ $h ] = $h . ': ' . $v;
				}
			}
			return array(
				'code'   => $code,
				'https'  => $code >= 300 && $code < 400 && 0 === stripos( $loc, 'https://' ),
				// A page that sends the browser on by itself (meta refresh of some hosters) counts as a redirect.
				'page'   => 200 === $code && ! preg_match( '#<meta[^>]+http-equiv=["\']?refresh["\']?[^>]+url=\s*https://#i', $body ),
				'loc'    => $loc,
				'bytes'  => strlen( $body ),
				'title'  => preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $m ) ? trim( wp_strip_all_tags( $m[1] ) ) : '',
				'via'    => $via,
				'cached' => (bool) array_diff_key( $via, array( 'server' => 1, 'via' => 1 ) ),
			);
		};
		$first = $ask( $url );
		// http://www… → http://… → https://… is fine: follow redirects that stay on http (max. 3 hops).
		for ( $hop = 0; $hop < 3 && $first && ! $first['https'] && $first['code'] >= 300 && $first['code'] < 400 && 0 === stripos( $first['loc'], 'http://' ); $hop++ ) {
			$url   = $first['loc'];
			$first = $ask( $url );
		}
		$out['url'] = $url;
		if ( ! $first || ( ! $first['page'] && ( $first['https'] || $first['code'] >= 400 || 200 === $first['code'] ) ) ) {
			return $out; // Redirects to https, or the site is not shown over http at all.
		}
		$out['open'] = true;
		if ( $first['page'] ) {
			$out['notes'][] = sprintf(
				/* translators: 1: url, 2: size, 3: page title, 4: response headers */
				__( 'Abruf von %1$s (wie ein Erstbesucher, ohne Weiterleitungen zu folgen) → Antwort 200 mit dem Inhalt der Seite (%2$s, Titel „%3$s“) statt einer Weiterleitung auf https. Antwort-Kopfzeilen: %4$s.', 'plakette' ),
				$url,
				size_format( $first['bytes'] ),
				'' !== $first['title'] ? $first['title'] : '–',
				$first['via'] ? implode( '; ', $first['via'] ) : '–'
			);
		} else {
			$out['notes'][] = sprintf( __( 'Abruf von %1$s → Antwort %2$d, Weiterleitung nach %3$s (nicht https).', 'plakette' ), $url, $first['code'], '' !== $first['loc'] ? $first['loc'] : '?' );
		}
		$try    = add_query_arg( 'plakette_https_test', time(), $url );
		$second = $ask( $try );
		if ( $second && $second['https'] ) {
			$out['cache']   = true;
			$out['notes'][] = sprintf( __( 'Gegenprobe am Cache vorbei: %1$s → Antwort %2$d, Weiterleitung nach %3$s. WordPress selbst leitet also richtig weiter.', 'plakette' ), $try, $second['code'], $second['loc'] );
			$out['notes'][] = sprintf(
				__( 'Ursache: Ein Seiten-Cache%s liefert die gespeicherte Seite auch über http aus, bevor WordPress weiterleiten kann. Im Browser fällt das selten auf, weil er sich HTTPS merkt – wer aber einem alten http-Link folgt, bekommt die Seite unverschlüsselt.', 'plakette' ),
				Plakette_Env::cache_plugin() ? ' (' . Plakette_Env::cache_plugin() . ')' : ''
			);
		} elseif ( $first['cached'] ) {
			$out['notes'][] = __( 'Die Antwort kam aus einem Cache (siehe Kopfzeilen).', 'plakette' );
		}
		$https       = wp_remote_get( $home, array( 'redirection' => 0, 'timeout' => 10, 'sslverify' => false ) );
		$hsts        = is_wp_error( $https ) ? '' : wp_remote_retrieve_header( $https, 'strict-transport-security' );
		$hsts        = is_array( $hsts ) ? implode( ', ', $hsts ) : (string) $hsts;
		$out['hsts'] = (bool) preg_match( '#max-age=["\']?([1-9]\d{5,})#i', $hsts );
		if ( $out['hsts'] ) {
			$out['notes'][] = sprintf( __( 'Die https-Adresse sendet „Strict-Transport-Security: %s“ – Browser, die schon einmal da waren, nutzen damit nur noch HTTPS.', 'plakette' ), $hsts );
		}
		$out['notes'][] = __( 'Selbst nachprüfen: Rufe die http-Adresse mit einem Werkzeug ab, das Weiterleitungen anzeigt (z. B. httpstatus.io). Der Browser taugt dafür nur bedingt – er wechselt oft von selbst auf https.', 'plakette' );
		return $out;
	}

	/** The redirect in .htaccess: runs before PHP and therefore before every page cache. */
	public static function htaccess_lines() {
		return array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteCond %{HTTPS} !=on',
			'RewriteCond %{HTTP:X-Forwarded-Proto} !https [NC]',
			'RewriteCond %{HTTP:X-Forwarded-SSL} !on [NC]',
			'RewriteCond %{REQUEST_URI} !^/\\.well-known/',
			'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]',
			'</IfModule>',
		);
	}

	public function fix( array $req, array &$undo ) {
		if ( 0 !== strpos( home_url(), 'https://' ) ) {
			foreach ( array( 'home', 'siteurl', 'https_migration_required' ) as $opt ) {
				$sentinel = new stdClass();
				$old      = get_option( $opt, $sentinel );
				$undo[]   = array( 'option', $opt, $old !== $sentinel, $old !== $sentinel ? $old : null );
			}
			if ( ! function_exists( 'wp_update_urls_to_https' ) || ! wp_update_urls_to_https() ) {
				$undo = array();
				return new WP_Error( 'plakette_https', __( 'WordPress konnte die Adresse nicht umstellen. Es wurde nichts verändert.', 'plakette' ) );
			}
		}
		Plakette_Log::set_module( 'force_https', true, $undo );
		$note = '';
		if ( 0 === strpos( home_url(), 'https://' ) && Plakette_Env::htaccess_writable() ) {
			// Server rule in front of everything (page caches answer before WordPress can redirect).
			$op = Plakette_Env::write_htaccess_block( self::MARKER, self::htaccess_lines(), true );
			if ( ! is_wp_error( $op ) ) {
				$test = wp_remote_get( add_query_arg( 'plakette_https_test', time(), home_url( '/' ) ), array( 'redirection' => 0, 'timeout' => 15, 'sslverify' => false ) );
				$code = is_wp_error( $test ) ? 0 : (int) wp_remote_retrieve_response_code( $test );
				if ( 200 !== $code ) {
					// Redirect loop behind a proxy or rules not allowed: take it back at once.
					Plakette_Env::write_htaccess_block( self::MARKER, array() );
					$note = ' ' . sprintf( __( 'Die Server-Regel in der .htaccess hat auf diesem Server nicht funktioniert (Antwort %s) und wurde sofort wieder entfernt. Bitte deinen Hoster um eine serverseitige Weiterleitung von http auf https.', 'plakette' ), $code ? $code : __( 'keine', 'plakette' ) );
				} else {
					$undo[] = $op;
				}
			}
		}
		Plakette_Page_Cache::purge_all();
		$probe = self::probe_http( home_url( '/' ) );
		if ( $probe['open'] ) {
			return __( 'Die Weiterleitung in WordPress ist eingeschaltet, die http-Adresse zeigt die Seite aber weiterhin an.', 'plakette' ) . $note . ( '' === $note ? ' ' . __( 'Leere den Cache deines Cache-Plugins und starte die Prüfung noch einmal.', 'plakette' ) : '' );
		}
		return __( 'Die Website läuft über HTTPS, http-Aufrufe werden weitergeleitet.', 'plakette' ) . $note;
	}
}

class Plakette_Check_Tagline extends Plakette_Check {
	public $id        = 'seo_tagline';
	public $group     = 'Grundeinstellungen';
	public $weight    = 3;
	public $title     = 'Eigener Untertitel';
	public $problem   = 'Der Untertitel ist noch der WordPress-Standardtext';
	public $explain   = 'Der Untertitel erscheint oft im Browser-Tab und bei Google neben deinem Namen. „Eine weitere WordPress-Website“ sagt Besuchern nichts über dich.';
	public $fix_label = 'Untertitel speichern';
	public $fix_does  = 'Ersetzt den Untertitel unter Einstellungen › Allgemein durch deinen Text.';
	public $kind      = 'input';

	const DEFAULTS = array( 'just another wordpress site', 'eine weitere wordpress-website', 'eine weitere wordpress-seite', 'ein weiterer wordpress-blog', 'just another wordpress weblog' );

	public function run( Plakette_Context $ctx ) {
		$tagline = trim( (string) get_option( 'blogdescription' ) );
		if ( in_array( strtolower( $tagline ), self::DEFAULTS, true ) ) {
			return $this->fail(
				sprintf( __( 'Aktuell: „%s“', 'plakette' ), $tagline ),
				array( 'input' => array( 'label' => __( 'Beschreibe in wenigen Worten, was du anbietest', 'plakette' ), 'value' => '', 'max' => 80, 'placeholder' => __( 'z. B. Handgemachte Torten aus Freiburg', 'plakette' ) ) )
			);
		}
		return $this->pass( '' === $tagline ? __( 'Kein Untertitel gesetzt – das ist in Ordnung.', 'plakette' ) : sprintf( __( 'Untertitel: „%s“', 'plakette' ), $tagline ) );
	}

	public function fix( array $req, array &$undo ) {
		$value = sanitize_text_field( isset( $req['value'] ) ? $req['value'] : '' );
		if ( '' === $value ) {
			return new WP_Error( 'plakette_empty', __( 'Bitte gib zuerst einen Untertitel ein.', 'plakette' ) );
		}
		Plakette_Log::set_option( 'blogdescription', $value, $undo );
		return __( 'Untertitel gespeichert.', 'plakette' );
	}
}

class Plakette_Check_Sitemap extends Plakette_Check {
	public $id        = 'seo_sitemap';
	public $group     = 'Grundeinstellungen';
	public $weight    = 6;
	public $title     = 'Inhaltsverzeichnis für Google (XML-Sitemap)';
	public $problem   = 'Es gibt keine XML-Sitemap';
	public $explain   = 'Eine Sitemap ist eine Liste aller deiner Seiten speziell für Suchmaschinen. Damit findet Google auch Seiten, die nirgends verlinkt sind, und bemerkt neue Beiträge schneller.';
	public $fix_label = 'Sitemap einschalten';
	public $fix_does  = 'Schaltet die in WordPress eingebaute Sitemap wieder ein, die ein anderes Plugin oder Theme abgeschaltet hat.';

	public function run( Plakette_Context $ctx ) {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return $this->skip( __( 'Wird geprüft, sobald Suchmaschinen erlaubt sind.', 'plakette' ) );
		}
		$urls = array();
		if ( Plakette_Env::seo_plugin() ) {
			$urls[] = home_url( '/sitemap_index.xml' );
			$urls[] = home_url( '/sitemap.xml' );
		}
		$urls[] = get_sitemap_url( 'index' );
		foreach ( array_filter( $urls ) as $url ) {
			$res = Plakette_Crawler::fetch( $url );
			if ( $res['ok'] && 200 === $res['status'] && preg_match( '/<(sitemapindex|urlset)\b/', $res['body'] ) ) {
				return $this->pass( sprintf( __( 'Gefunden: %s', 'plakette' ), $url ), array( 'url' => $url ) );
			}
		}
		if ( Plakette_Env::seo_plugin() ) {
			return $this->fail(
				sprintf( __( 'Weder %s noch WordPress liefern eine Sitemap aus.', 'plakette' ), Plakette_Env::seo_plugin() ),
				array( 'blocked' => sprintf( __( 'Schalte die Sitemap in den Einstellungen von %s ein.', 'plakette' ), Plakette_Env::seo_plugin() ) )
			);
		}
		return $this->fail( sprintf( __( 'Unter %s kommt keine Sitemap.', 'plakette' ), get_sitemap_url( 'index' ) ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'sitemap', true, $undo );
		return __( 'Die Sitemap ist eingeschaltet.', 'plakette' );
	}
}

class Plakette_Check_Robots extends Plakette_Check {
	public $id        = 'seo_robots';
	public $group     = 'Grundeinstellungen';
	public $weight    = 5;
	public $title     = 'robots.txt lässt Google herein und nennt die Sitemap';
	public $problem   = 'Die robots.txt ist nicht optimal';
	public $explain   = 'Die Datei robots.txt ist das erste, was Suchmaschinen lesen. Sie sagt, welche Bereiche tabu sind und wo die Sitemap liegt. Ein falsches „Disallow: /“ sperrt die ganze Website aus.';
	public $fix_label = 'robots.txt korrigieren';
	public $fix_does  = 'Entfernt eine Sperre der ganzen Website und ergänzt den Hinweis auf die Sitemap. Eine vorhandene Datei wird vorher gesichert.';

	public function run( Plakette_Context $ctx ) {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return $this->skip( __( 'Wird geprüft, sobald Suchmaschinen erlaubt sind.', 'plakette' ) );
		}
		$res = Plakette_Crawler::fetch( home_url( '/robots.txt' ) );
		if ( ! $res['ok'] || 200 !== $res['status'] ) {
			return $this->skip( __( 'Die robots.txt konnte nicht abgerufen werden.', 'plakette' ) );
		}
		$issues = array();
		if ( self::blocks_all( $res['body'] ) ) {
			$issues[] = __( 'Sie sperrt mit „Disallow: /“ die ganze Website.', 'plakette' );
		}
		if ( false === stripos( $res['body'], 'sitemap:' ) ) {
			$issues[] = __( 'Sie nennt keine Sitemap.', 'plakette' );
		}
		if ( ! $issues ) {
			return $this->pass( __( 'Alles in Ordnung.', 'plakette' ) );
		}
		$file  = self::file();
		$extra = array();
		if ( file_exists( $file ) && ! wp_is_writable( $file ) ) {
			$extra['blocked'] = __( 'Die Datei robots.txt auf dem Server ist schreibgeschützt.', 'plakette' );
		}
		$detail = implode( ' ', $issues );
		return self::blocks_all( $res['body'] ) ? $this->fail( $detail, $extra ) : array_merge( $this->warn( $detail ), $extra, array( 'weight' => 2 ) );
	}

	private static function file() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . 'robots.txt';
	}

	public static function blocks_all( $txt ) {
		$agent_all = false;
		foreach ( preg_split( '/\R/', (string) $txt ) as $line ) {
			$line = trim( preg_replace( '/#.*/', '', $line ) );
			if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
				$agent_all = '*' === trim( $m[1] );
			} elseif ( $agent_all && preg_match( '#^disallow:\s*/\s*$#i', $line ) ) {
				return true;
			}
		}
		return false;
	}

	public function fix( array $req, array &$undo ) {
		$file    = self::file();
		$sitemap = get_sitemap_url( 'index' );
		if ( file_exists( $file ) ) {
			$txt    = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$backup = Plakette_Env::backup_dir() . '/robots-' . gmdate( 'YmdHis' ) . '.txt';
			copy( $file, $backup );
			$agent_all = false;
			$lines     = array();
			foreach ( preg_split( '/\R/', $txt ) as $line ) {
				$clean = trim( preg_replace( '/#.*/', '', $line ) );
				if ( preg_match( '/^user-agent:\s*(.+)$/i', $clean, $m ) ) {
					$agent_all = '*' === trim( $m[1] );
				} elseif ( $agent_all && preg_match( '#^disallow:\s*/\s*$#i', $clean ) ) {
					$line = 'Disallow:';
				}
				$lines[] = $line;
			}
			$txt = rtrim( implode( "\n", $lines ) ) . "\n";
			if ( $sitemap && false === stripos( $txt, 'sitemap:' ) ) {
				$txt .= "\nSitemap: " . $sitemap . "\n";
			}
			if ( false === file_put_contents( $file, $txt ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				wp_delete_file( $backup );
				return new WP_Error( 'plakette_robots', __( 'Die Datei robots.txt konnte nicht geschrieben werden.', 'plakette' ) );
			}
			$undo[] = array( 'file_restore', $file, $backup );
		} else {
			// WordPress generates robots.txt itself – add the sitemap line through a filter.
			Plakette_Log::set_module( 'robots_sitemap', true, $undo );
			if ( ! Plakette_Modules::on( 'sitemap' ) && ! Plakette_Env::seo_plugin() ) {
				Plakette_Log::set_module( 'sitemap', true, $undo );
			}
		}
		return __( 'robots.txt korrigiert.', 'plakette' );
	}
}

class Plakette_Check_Home_Title extends Plakette_Check {
	public $id        = 'seo_home_title';
	public $group     = 'Startseite';
	public $weight    = 6;
	public $title     = 'Seitentitel der Startseite';
	public $problem   = 'Der Seitentitel der Startseite fehlt oder hat die falsche Länge';
	public $explain   = 'Der Seitentitel ist die blaue Überschrift in den Google-Ergebnissen. Er sollte zwischen etwa 20 und 60 Zeichen lang sein und sagen, wer du bist und was du anbietest.';
	public $fix_label = 'Titel speichern';
	public $fix_does  = 'Legt einen eigenen Titel für die Startseite fest. Dein Theme und deine Einstellungen bleiben unverändert.';
	public $kind      = 'input';

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$f = $ctx->facts();
		if ( ! $f['title_count'] || '' === (string) $f['title'] ) {
			return $this->fail(
				__( 'Die Startseite hat gar keinen Titel. Dein Theme gibt ihn nicht aus.', 'plakette' ),
				array( 'kind' => 'auto', 'fix_label' => __( 'Titel einschalten', 'plakette' ), 'mode' => 'support' )
			);
		}
		$len = mb_strlen( $f['title'] );
		if ( $len >= 20 && $len <= 60 ) {
			return $this->pass( sprintf( __( '„%1$s“ (%2$d Zeichen)', 'plakette' ), $f['title'], $len ) );
		}
		$blocked = Plakette_Env::seo_plugin() ? sprintf( __( 'Ändere den Titel in den Einstellungen von %s.', 'plakette' ), Plakette_Env::seo_plugin() ) : '';
		return array_merge(
			$this->warn(
				sprintf( $len < 20 ? __( '„%1$s“ ist mit %2$d Zeichen zu kurz.', 'plakette' ) : __( '„%1$s“ ist mit %2$d Zeichen zu lang – Google schneidet ihn ab.', 'plakette' ), $f['title'], $len ),
				array(
					'input' => array(
						'label' => __( 'Titel der Startseite (20–60 Zeichen)', 'plakette' ),
						'value' => self::suggest( $len < 20 ),
						'max'   => 60,
					),
				)
			),
			$blocked ? array( 'blocked' => $blocked ) : array()
		);
	}

	private static function suggest( $short ) {
		$name    = get_bloginfo( 'name' );
		$tagline = get_bloginfo( 'description' );
		$title   = $tagline ? $name . ' – ' . $tagline : $name;
		return Plakette_Modules::shorten( $title, 60 );
	}

	public function fix( array $req, array &$undo ) {
		$current = $this->run( new Plakette_Context() );
		if ( isset( $current['mode'] ) && 'support' === $current['mode'] ) {
			Plakette_Log::set_module( 'title_tag', true, $undo );
			return __( 'Der Seitentitel wird jetzt ausgegeben.', 'plakette' );
		}
		$value = sanitize_text_field( isset( $req['value'] ) ? $req['value'] : '' );
		if ( '' === $value ) {
			return new WP_Error( 'plakette_empty', __( 'Bitte gib einen Titel ein.', 'plakette' ) );
		}
		Plakette_Log::set_module( 'home_title', $value, $undo );
		return __( 'Titel der Startseite gespeichert.', 'plakette' );
	}
}

class Plakette_Check_Home_Description extends Plakette_Check {
	public $id        = 'seo_home_description';
	public $group     = 'Startseite';
	public $weight    = 6;
	public $title     = 'Beschreibung der Startseite für Google';
	public $problem   = 'Die Startseite hat keine Beschreibung für Google';
	public $explain   = 'Die Meta-Beschreibung ist der kurze graue Text unter dem Titel in den Google-Ergebnissen. Fehlt sie, nimmt Google irgendeinen Textschnipsel. Eine gute Beschreibung (120–155 Zeichen) bringt mehr Klicks.';
	public $fix_label = 'Beschreibung speichern';
	public $fix_does  = 'Speichert die Beschreibung und gibt sie unsichtbar im Kopf der Startseite aus. Den Vorschlag kannst du vorher ändern.';
	public $kind      = 'input';

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$f       = $ctx->facts();
		$desc    = (string) $f['description'];
		$len     = mb_strlen( $desc );
		$blocked = Plakette_Env::seo_plugin() ? sprintf( __( 'Trage die Beschreibung in den Einstellungen von %s ein.', 'plakette' ), Plakette_Env::seo_plugin() ) : '';
		$extra   = array(
			'input' => array(
				'label'     => __( 'Beschreibung (120–155 Zeichen)', 'plakette' ),
				'value'     => $desc ? $desc : self::suggest(),
				'max'       => 160,
				'multiline' => true,
			),
		);
		if ( $blocked ) {
			$extra['blocked'] = $blocked;
		}
		if ( '' === $desc ) {
			return $this->fail( __( 'Keine Meta-Beschreibung gefunden.', 'plakette' ), $extra );
		}
		if ( $len < 70 || $len > 165 ) {
			return $this->warn( sprintf( __( 'Die Beschreibung hat %d Zeichen. Ideal sind 120–155.', 'plakette' ), $len ), $extra );
		}
		return $this->pass( sprintf( __( '„%s“', 'plakette' ), $desc ) );
	}

	private static function suggest() {
		$front = (int) get_option( 'page_on_front' );
		$text  = $front ? Plakette_Modules::generate_description( $front ) : '';
		if ( mb_strlen( $text ) < 70 ) {
			$tagline = get_bloginfo( 'description' );
			$text    = trim( get_bloginfo( 'name' ) . ( $tagline ? ': ' . $tagline . '.' : '' ) . ' ' . $text );
		}
		return Plakette_Modules::shorten( $text );
	}

	public function fix( array $req, array &$undo ) {
		$value = sanitize_textarea_field( isset( $req['value'] ) ? $req['value'] : '' );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		if ( '' === $value ) {
			return new WP_Error( 'plakette_empty', __( 'Bitte gib eine Beschreibung ein.', 'plakette' ) );
		}
		Plakette_Log::set_module( 'home_description', $value, $undo );
		if ( ! Plakette_Modules::on( 'meta_desc' ) ) {
			Plakette_Log::set_module( 'meta_desc', true, $undo );
		}
		return __( 'Beschreibung der Startseite gespeichert.', 'plakette' );
	}
}

/** Shared base for "switch on a module when a tag is missing on the home page". */
abstract class Plakette_Check_Head_Module extends Plakette_Check {
	public $group  = 'Startseite';
	protected $module = '';

	abstract protected function present( array $facts );

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		if ( $this->present( $ctx->facts() ) ) {
			return $this->pass( __( 'Vorhanden.', 'plakette' ) );
		}
		$extra = array();
		if ( Plakette_Env::seo_plugin() ) {
			$extra['blocked'] = sprintf( __( 'Dein SEO-Plugin %s ist dafür zuständig. Schalte die Funktion dort ein.', 'plakette' ), Plakette_Env::seo_plugin() );
		}
		return $this->fail( __( 'Auf der Startseite nicht gefunden.', 'plakette' ), $extra );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( $this->module, true, $undo );
		return __( 'Eingeschaltet.', 'plakette' );
	}
}

class Plakette_Check_Social extends Plakette_Check_Head_Module {
	public $id        = 'seo_social';
	public $weight    = 3;
	public $title     = 'Vorschau beim Teilen (Open Graph)';
	public $problem   = 'Beim Teilen fehlt eine schöne Vorschau';
	public $explain   = 'Wenn jemand deine Seite bei WhatsApp, Facebook oder LinkedIn teilt, wird eine Vorschau mit Bild, Titel und Text angezeigt. Ohne Open-Graph-Angaben sieht das oft leer oder zufällig aus.';
	public $fix_label = 'Vorschau-Angaben einschalten';
	public $fix_does  = 'Gibt auf jeder Seite Titel, Beschreibung und Bild (Beitragsbild, Logo oder Website-Icon) für soziale Netzwerke aus.';
	protected $module = 'og';

	protected function present( array $f ) {
		return ! empty( $f['og']['og:title'] );
	}
}

class Plakette_Check_Schema extends Plakette_Check_Head_Module {
	public $id        = 'seo_schema';
	public $weight    = 3;
	public $title     = 'Strukturierte Daten (Schema.org)';
	public $problem   = 'Google bekommt keine strukturierten Daten';
	public $explain   = 'Strukturierte Daten sind eine maschinenlesbare Visitenkarte: Name, Logo, Art der Seite, Datum und Autor von Beiträgen. Google nutzt sie für reichhaltigere Suchergebnisse.';
	public $fix_label = 'Strukturierte Daten einschalten';
	public $fix_does  = 'Gibt unsichtbar Angaben zu deiner Website, deiner Organisation und zu jedem Beitrag im JSON-LD-Format aus.';
	protected $module = 'schema';

	protected function present( array $f ) {
		return ! empty( $f['jsonld'] );
	}
}

class Plakette_Check_Canonical extends Plakette_Check_Head_Module {
	public $id        = 'seo_canonical';
	public $weight    = 3;
	public $title     = 'Eindeutige Adresse der Startseite (Canonical)';
	public $problem   = 'Die Startseite nennt keine eindeutige Adresse';
	public $explain   = 'Deine Startseite ist oft unter mehreren Adressen erreichbar, zum Beispiel mit angehängten Werbe-Parametern. Der Canonical-Hinweis sagt Google, welche Adresse die richtige ist – so wird nichts doppelt gezählt.';
	public $fix_label = 'Canonical-Hinweis einschalten';
	public $fix_does  = 'Ergänzt auf Startseite, Kategorien und Schlagwörtern den Hinweis auf die richtige Adresse. Einzelne Beiträge haben ihn in WordPress bereits.';
	protected $module = 'canonical';

	protected function present( array $f ) {
		return ! empty( $f['canonical'] );
	}
}

class Plakette_Check_Attachment_Pages extends Plakette_Check {
	public $id        = 'seo_attachment_pages';
	public $group     = 'Grundeinstellungen';
	public $weight    = 3;
	public $title     = 'Keine leeren Bild-Seiten';
	public $problem   = 'Jedes hochgeladene Bild hat eine eigene, fast leere Seite';
	public $explain   = 'WordPress legt für jedes Bild eine eigene Seite an, auf der nur das Bild zu sehen ist. Google sieht darin hunderte dünne Seiten, die deine guten Seiten schwächen.';
	public $fix_label = 'Bild-Seiten abschalten';
	public $fix_does  = 'Schaltet die Bild-Seiten ab. Wer eine alte Adresse aufruft, landet direkt beim Bild.';

	public function run( Plakette_Context $ctx ) {
		$enabled = get_option( 'wp_attachment_pages_enabled', null );
		if ( null === $enabled ) {
			return $this->skip( __( 'Diese WordPress-Version kennt die Einstellung nicht.', 'plakette' ) );
		}
		if ( '1' !== (string) $enabled ) {
			return $this->pass( __( 'Bild-Seiten sind abgeschaltet.', 'plakette' ) );
		}
		$count = (int) wp_count_posts( 'attachment' )->inherit;
		if ( ! $count ) {
			return $this->pass( __( 'Noch keine Medien hochgeladen.', 'plakette' ) );
		}
		if ( Plakette_Env::seo_plugin() ) {
			return $this->warn( sprintf( __( '%1$d Medien haben eine eigene Seite. Prüfe, ob %2$s sie weiterleitet.', 'plakette' ), $count, Plakette_Env::seo_plugin() ) );
		}
		return $this->fail( sprintf( _n( '%d Medium hat eine eigene Seite.', '%d Medien haben eine eigene Seite.', $count, 'plakette' ), $count ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_option( 'wp_attachment_pages_enabled', '0', $undo );
		return __( 'Bild-Seiten sind abgeschaltet.', 'plakette' );
	}
}

class Plakette_Check_Sample_Content extends Plakette_Check {
	public $id        = 'seo_sample_content';
	public $group     = 'Inhalte';
	public $weight    = 4;
	public $title     = 'Keine Beispielinhalte von WordPress';
	public $problem   = 'Die Beispielinhalte von WordPress sind noch online';
	public $explain   = 'Bei der Installation legt WordPress „Hallo Welt!“, eine Beispiel-Seite und einen Beispiel-Kommentar an. Für Besucher und Google wirkt die Website dadurch unfertig.';
	public $fix_label = 'Ausgewählte in den Papierkorb';
	public $fix_does  = 'Verschiebt die ausgewählten Beispielinhalte in den Papierkorb. Dort bleiben sie 30 Tage und lassen sich wiederherstellen.';
	public $kind      = 'items';

	private function find() {
		$items = array();
		$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => -1, 'post_name__in' => array( 'hello-world', 'hallo-welt', 'sample-page', 'beispiel-seite' ) ) );
		foreach ( $posts as $post ) {
			if ( preg_match( '/(This is your first post|Dies ist dein erster Beitrag|This is an example page|Dies ist eine Beispiel-Seite)/i', $post->post_content ) ) {
				$items[] = array(
					'id'    => 'post:' . $post->ID,
					'label' => get_the_title( $post ),
					'sub'   => 'page' === $post->post_type ? __( 'Beispiel-Seite', 'plakette' ) : __( 'Beispiel-Beitrag', 'plakette' ),
					'link'  => get_permalink( $post ),
				);
			}
		}
		$comments = get_comments( array( 'status' => 'approve', 'author_email' => 'wapuu@wordpress.example', 'number' => 10 ) );
		foreach ( $comments as $c ) {
			$items[] = array(
				'id'    => 'comment:' . $c->comment_ID,
				'label' => sprintf( __( 'Kommentar von „%s“', 'plakette' ), $c->comment_author ),
				'sub'   => __( 'Beispiel-Kommentar', 'plakette' ),
			);
		}
		return $items;
	}

	public function run( Plakette_Context $ctx ) {
		$items = $this->find();
		if ( ! $items ) {
			return $this->pass( __( 'Keine Beispielinhalte gefunden.', 'plakette' ) );
		}
		return $this->fail( sprintf( _n( '%d Beispielinhalt ist noch veröffentlicht.', '%d Beispielinhalte sind noch veröffentlicht.', count( $items ), 'plakette' ), count( $items ) ), array( 'items' => $items ) );
	}

	public function fix( array $req, array &$undo ) {
		$allowed = wp_list_pluck( $this->find(), 'id' );
		$done    = 0;
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( ! in_array( $id, $allowed, true ) ) {
				continue;
			}
			list( $type, $num ) = explode( ':', $id );
			if ( 'post' === $type ) {
				$post = get_post( (int) $num );
				update_post_meta( $post->ID, '_plakette_prev_status', $post->post_status );
				if ( wp_trash_post( $post->ID ) ) {
					$undo[] = array( 'untrash', $post->ID );
					++$done;
				}
			} elseif ( wp_trash_comment( (int) $num ) ) {
				$undo[] = array( 'untrash_comment', (int) $num );
				++$done;
			}
		}
		return sprintf( _n( '%d Beispielinhalt im Papierkorb.', '%d Beispielinhalte im Papierkorb.', $done, 'plakette' ), $done );
	}
}

class Plakette_Check_Site_Icon extends Plakette_Check {
	public $id        = 'seo_site_icon';
	public $group     = 'Grundeinstellungen';
	public $weight    = 2;
	public $title     = 'Website-Icon (Favicon)';
	public $problem   = 'Die Website hat kein Icon';
	public $explain   = 'Das kleine Bild im Browser-Tab und neben deinem Eintrag in den Google-Ergebnissen auf dem Handy. Ohne Icon zeigt Google ein graues Standardsymbol – das wirkt weniger vertrauenswürdig.';
	public $fix_label = 'Icon aus dem Anfangsbuchstaben erstellen';
	public $fix_does  = 'Erstellt ein schlichtes Icon mit dem ersten Buchstaben deines Website-Namens. Du kannst es jederzeit im Customizer durch dein Logo ersetzen.';

	public function run( Plakette_Context $ctx ) {
		if ( has_site_icon() ) {
			return $this->pass( __( 'Ein Icon ist eingerichtet.', 'plakette' ) );
		}
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return $this->fail( __( 'Kein Icon eingerichtet.', 'plakette' ), array( 'blocked' => __( 'Lade unter Design › Customizer › Website-Informationen ein quadratisches Bild hoch (mindestens 512 × 512 Pixel).', 'plakette' ) ) );
		}
		return $this->fail( __( 'Kein Icon eingerichtet.', 'plakette' ) );
	}

	public function fix( array $req, array &$undo ) {
		$name   = trim( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
		$letter = $name ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) : 'W';
		$size   = 512;
		$img    = imagecreatetruecolor( $size, $size );
		imagesavealpha( $img, true );
		imagefill( $img, 0, 0, imagecolorallocatealpha( $img, 0, 0, 0, 127 ) );
		$bg = imagecolorallocate( $img, 0x13, 0x3B, 0x5C );
		$fg = imagecolorallocate( $img, 0xFF, 0xFF, 0xFF );
		imagefilledellipse( $img, $size / 2, $size / 2, $size, $size, $bg );
		$font     = PLAKETTE_DIR . 'admin/fonts/BarlowSemiCondensed-Bold.ttf';
		$gd       = function_exists( 'gd_info' ) ? gd_info() : array();
		$freetype = ! empty( $gd['FreeType Support'] ) && function_exists( 'imagettftext' ) && file_exists( $font );
		$box      = $freetype ? @imagettfbbox( 300, 0, $font, $letter ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $box ) {
			$w = $box[2] - $box[0];
			$h = $box[1] - $box[7];
			imagettftext( $img, 300, 0, (int) ( ( $size - $w ) / 2 - $box[0] ), (int) ( ( $size + $h ) / 2 - $box[1] ), $fg, $font, $letter );
		} else {
			// No FreeType: draw the letter with GD's built-in font and scale it up (crisp pixel look).
			$ascii = preg_match( '/^[A-Z0-9]$/', $letter ) ? $letter : strtoupper( remove_accents( $letter ) );
			$ascii = preg_match( '/^[A-Z0-9]$/', $ascii ) ? $ascii : 'W';
			$small = imagecreatetruecolor( 9, 15 );
			imagesavealpha( $small, true );
			imagealphablending( $small, false );
			imagefill( $small, 0, 0, imagecolorallocatealpha( $small, 0, 0, 0, 127 ) );
			imagestring( $small, 5, 0, 0, $ascii, imagecolorallocate( $small, 0xFF, 0xFF, 0xFF ) );
			imagealphablending( $img, true );
			imagecopyresized( $img, $small, (int) ( ( $size - 9 * 24 ) / 2 ), (int) ( ( $size - 15 * 24 ) / 2 ) + 10, 0, 0, 9 * 24, 15 * 24, 9, 15 );
		}
		$up   = wp_upload_dir();
		$file = trailingslashit( $up['path'] ) . 'site-icon-' . sanitize_title( $name ? $name : 'website' ) . '.png';
		imagepng( $img, $file );

		$id = wp_insert_attachment(
			array( 'post_mime_type' => 'image/png', 'post_title' => sprintf( __( 'Website-Icon %s', 'plakette' ), $name ), 'post_status' => 'inherit' ),
			$file
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return new WP_Error( 'plakette_icon', __( 'Das Icon konnte nicht gespeichert werden.', 'plakette' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
		update_post_meta( $id, '_wp_attachment_context', 'site-icon' );
		$undo[] = array( 'delete_attachment', $id );
		Plakette_Log::set_option( 'site_icon', $id, $undo );
		return __( 'Das Website-Icon ist eingerichtet.', 'plakette' );
	}
}

add_filter(
	'plakette_checks',
	function ( $classes ) {
		return array_merge(
			$classes,
			array(
				'Plakette_Check_Visibility',
				'Plakette_Check_Https',
				'Plakette_Check_Permalinks',
				'Plakette_Check_Sitemap',
				'Plakette_Check_Robots',
				'Plakette_Check_Tagline',
				'Plakette_Check_Site_Icon',
				'Plakette_Check_Attachment_Pages',
				'Plakette_Check_Home_Title',
				'Plakette_Check_Home_Description',
				'Plakette_Check_Social',
				'Plakette_Check_Schema',
				'Plakette_Check_Canonical',
				'Plakette_Check_Sample_Content',
			)
		);
	},
	10
);
