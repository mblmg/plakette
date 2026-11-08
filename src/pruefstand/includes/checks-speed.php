<?php
/**
 * Page speed checks: server, files, images and code that slows the first view down.
 */

defined( 'ABSPATH' ) || exit;

abstract class Pruefstand_Speed_Check extends Pruefstand_Check {
	public $area = 'speed';
}

class Pruefstand_Check_Page_Cache extends Pruefstand_Speed_Check {
	public $id        = 'speed_page_cache';
	public $group     = 'Server';
	public $weight    = 9;
	public $title     = 'Seiten werden zwischengespeichert (Cache)';
	public $problem   = 'Jede Seite wird bei jedem Besuch neu gebaut';
	public $explain   = 'Ohne Cache setzt WordPress jede Seite bei jedem Besuch neu aus der Datenbank zusammen. Mit Cache wird die fertige Seite gespeichert und sofort ausgeliefert – meist 5- bis 20-mal schneller.';
	public $fix_label = 'Seiten-Cache einschalten';
	public $fix_does  = 'Speichert fertige Seiten für Besucher, die nicht angemeldet sind. Der Cache leert sich automatisch, wenn du etwas änderst. Angemeldete Nutzer, Warenkorb und Kommentare sind ausgenommen.';
	public $risk      = 'review';

	public function run( Pruefstand_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$home    = $ctx->home();
		$h       = $home['headers'];
		$time    = sprintf( __( 'Antwortzeit des Servers: %d ms', 'pruefstand' ), $home['time_ms'] );
		$plugin  = Pruefstand_Env::cache_plugin();
		$headers = array( 'x-cache', 'cf-cache-status', 'x-litespeed-cache', 'x-proxy-cache', 'x-sucuri-cache', 'x-nginx-cache', 'x-fastcgi-cache', 'x-srcache-fetch-status', 'x-pruefstand-cache', 'x-wp-super-cache', 'x-rocket-nginx-serving-static' );
		foreach ( $headers as $name ) {
			if ( isset( $h[ $name ] ) && preg_match( '/hit/i', $h[ $name ] ) ) {
				return $this->pass( $time . '. ' . sprintf( __( 'Cache aktiv (%s).', 'pruefstand' ), $name ) );
			}
		}
		if ( Pruefstand_Page_Cache::enabled() ) {
			return $this->warn( $time . '. ' . __( 'Der Prüfstand-Cache ist eingeschaltet, hat diese Seite aber nicht ausgeliefert. Prüfe, ob ein Plugin Cookies setzt.', 'pruefstand' ), array( 'kind' => 'none' ) );
		}
		if ( $plugin ) {
			return $this->pass( $time . '. ' . sprintf( __( '%s ist aktiv.', 'pruefstand' ), $plugin ) );
		}
		$extra = array();
		if ( ! wp_is_writable( WP_CONTENT_DIR ) || ( ! ( defined( 'WP_CACHE' ) && WP_CACHE ) && ! wp_is_writable( (string) Pruefstand_Env::wpconfig_path() ) ) ) {
			$extra['blocked'] = __( 'Prüfstand darf die nötigen Dateien (wp-content, wp-config.php) nicht schreiben.', 'pruefstand' );
		}
		return $home['time_ms'] > 400 ? $this->fail( $time . '.', $extra ) : $this->warn( $time . '. ' . __( 'Schon flott – ein Cache hält das auch bei vielen Besuchern so.', 'pruefstand' ), $extra );
	}

	public function fix( array $req, array &$undo ) {
		$ops = Pruefstand_Page_Cache::enable();
		if ( is_wp_error( $ops ) ) {
			return $ops;
		}
		$undo = array_merge( $undo, $ops );
		return __( 'Der Seiten-Cache ist eingeschaltet. Ab dem zweiten Aufruf werden Seiten aus dem Cache geliefert.', 'pruefstand' );
	}
}

class Pruefstand_Check_Compression extends Pruefstand_Speed_Check {
	public $id        = 'speed_compression';
	public $group     = 'Server';
	public $weight    = 6;
	public $title     = 'Daten werden komprimiert übertragen';
	public $problem   = 'Seiten werden unkomprimiert übertragen';
	public $explain   = 'Webserver können Seiten wie eine ZIP-Datei verkleinert verschicken (gzip oder Brotli). Der Browser packt sie blitzschnell wieder aus. Text wird dadurch etwa 70 % kleiner – gerade im Mobilfunknetz spürbar.';
	public $fix_label = 'Komprimierung einschalten';
	public $fix_does  = 'Ergänzt in der Datei .htaccess Regeln für gzip/Brotli (bei Apache-Servern) und lässt WordPress seine Seiten selbst komprimieren, wenn der Server es nicht tut.';

	public function run( Pruefstand_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$home = $ctx->home();
		$enc  = isset( $home['headers']['content-encoding'] ) ? strtolower( $home['headers']['content-encoding'] ) : '';
		if ( preg_match( '/gzip|br|zstd|deflate/', $enc ) ) {
			return $this->pass( sprintf( __( 'Komprimierung: %s', 'pruefstand' ), $enc ) );
		}
		if ( ! function_exists( 'ob_gzhandler' ) && ! Pruefstand_Env::htaccess_writable() ) {
			return $this->fail(
				sprintf( __( 'Die Startseite (%s) wird unkomprimiert übertragen.', 'pruefstand' ), self::size( $home['facts']['bytes'] ) ),
				array( 'kind' => 'letter', 'letter' => $this->letter( __( 'Bitte aktivieren Sie die gzip- oder Brotli-Komprimierung für HTML, CSS, JavaScript, SVG und JSON.', 'pruefstand' ) ) )
			);
		}
		return $this->fail( sprintf( __( 'Die Startseite (%s) wird unkomprimiert übertragen.', 'pruefstand' ), self::size( $home['facts']['bytes'] ) ) );
	}

	public function fix( array $req, array &$undo ) {
		if ( Pruefstand_Env::htaccess_writable() ) {
			$op = Pruefstand_Env::write_htaccess_block(
				'Pruefstand Kompression',
				array(
					'<IfModule mod_brotli.c>',
					'  AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/xml text/css text/javascript application/javascript application/json application/xml image/svg+xml font/ttf font/otf',
					'</IfModule>',
					'<IfModule mod_deflate.c>',
					'  AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json application/xml image/svg+xml font/ttf font/otf',
					'</IfModule>',
				)
			);
			if ( ! is_wp_error( $op ) ) {
				$undo[] = $op;
			}
		}
		if ( function_exists( 'ob_gzhandler' ) ) {
			Pruefstand_Log::set_module( 'html_gzip', true, $undo );
			$flag = WP_CONTENT_DIR . '/pruefstand-gzip.flag';
			if ( ! file_exists( $flag ) && false !== @file_put_contents( $flag, '1' ) ) { // phpcs:ignore
				$undo[] = array( 'file_delete', $flag );
			}
		}
		Pruefstand_Page_Cache::purge_all();
		// Did it work? Ask the server again.
		$res = Pruefstand_Crawler::fetch( home_url( '/' ) );
		$enc = $res['ok'] && isset( $res['headers']['content-encoding'] ) ? $res['headers']['content-encoding'] : '';
		if ( $enc ) {
			return sprintf( __( 'Komprimierung aktiv (%s).', 'pruefstand' ), $enc );
		}
		return __( 'Regeln gespeichert. Der Server komprimiert trotzdem noch nicht – bitte frag deinen Hoster (Vorlage erscheint nach der nächsten Prüfung).', 'pruefstand' );
	}
}

class Pruefstand_Check_Browser_Cache extends Pruefstand_Speed_Check {
	public $id        = 'speed_browser_cache';
	public $group     = 'Server';
	public $weight    = 4;
	public $title     = 'Browser speichern Bilder, Schriften und Skripte';
	public $problem   = 'Browser laden dieselben Dateien bei jedem Besuch neu';
	public $explain   = 'Logo, Schriften und Design-Dateien ändern sich selten. Der Server kann Browsern sagen: „Behalte das ein paar Monate.“ Wiederkehrende Besucher und jede weitere Unterseite laden dann viel schneller.';
	public $fix_label = 'Browser-Cache einschalten';
	public $fix_does  = 'Ergänzt in der Datei .htaccess Regeln, wie lange Browser Bilder, Schriften, CSS und JavaScript aufheben dürfen. WordPress hängt bei Änderungen eine neue Versionsnummer an, Besucher sehen also trotzdem immer den aktuellen Stand.';

	public function run( Pruefstand_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$asset = '';
		foreach ( $ctx->facts()['styles'] as $href ) {
			if ( Pruefstand_Crawler::is_local( $href ) ) {
				$asset = Pruefstand_Crawler::absolutize( $href );
				break;
			}
		}
		if ( ! $asset ) {
			return $this->skip( __( 'Keine eigene CSS-Datei auf der Startseite gefunden.', 'pruefstand' ) );
		}
		$res = Pruefstand_Crawler::fetch( $asset, 'HEAD' );
		if ( ! $res['ok'] ) {
			return $this->skip( __( 'Die Testdatei konnte nicht abgerufen werden.', 'pruefstand' ) );
		}
		$cc  = isset( $res['headers']['cache-control'] ) ? $res['headers']['cache-control'] : '';
		$age = preg_match( '/max-age=(\d+)/', $cc, $m ) ? (int) $m[1] : 0;
		if ( ! $age && ! empty( $res['headers']['expires'] ) ) {
			$age = max( 0, strtotime( $res['headers']['expires'] ) - time() );
		}
		if ( $age >= WEEK_IN_SECONDS ) {
			return $this->pass( sprintf( __( 'CSS-Dateien werden %d Tage aufbewahrt.', 'pruefstand' ), floor( $age / DAY_IN_SECONDS ) ) );
		}
		$detail = $age ? sprintf( __( 'CSS-Dateien werden nur %s aufbewahrt.', 'pruefstand' ), human_time_diff( 0, $age ) ) : __( 'Der Server sagt Browsern nicht, wie lange sie Dateien aufheben dürfen.', 'pruefstand' );
		if ( ! Pruefstand_Env::htaccess_writable() ) {
			return $this->fail(
				$detail,
				array( 'kind' => 'letter', 'letter' => $this->letter( __( 'Bitte setzen Sie für statische Dateien (Bilder, Schriften, CSS, JavaScript) einen Cache-Control-Header mit langer Gültigkeit, z. B. „Cache-Control: public, max-age=31536000“ für Schriften und Bilder sowie mindestens 30 Tage für CSS und JavaScript.', 'pruefstand' ) ) )
			);
		}
		return $this->fail( $detail );
	}

	public function fix( array $req, array &$undo ) {
		$op = Pruefstand_Env::write_htaccess_block(
			'Pruefstand Browser-Cache',
			array(
				'<IfModule mod_expires.c>',
				'  ExpiresActive On',
				'  ExpiresByType image/jpeg "access plus 1 year"',
				'  ExpiresByType image/png "access plus 1 year"',
				'  ExpiresByType image/gif "access plus 1 year"',
				'  ExpiresByType image/webp "access plus 1 year"',
				'  ExpiresByType image/avif "access plus 1 year"',
				'  ExpiresByType image/svg+xml "access plus 1 year"',
				'  ExpiresByType image/x-icon "access plus 1 year"',
				'  ExpiresByType font/woff2 "access plus 1 year"',
				'  ExpiresByType font/woff "access plus 1 year"',
				'  ExpiresByType font/ttf "access plus 1 year"',
				'  ExpiresByType text/css "access plus 1 month"',
				'  ExpiresByType text/javascript "access plus 1 month"',
				'  ExpiresByType application/javascript "access plus 1 month"',
				'</IfModule>',
			)
		);
		if ( is_wp_error( $op ) ) {
			return $op;
		}
		$undo[] = $op;
		return __( 'Regeln für den Browser-Cache gespeichert.', 'pruefstand' );
	}
}

class Pruefstand_Check_Large_Images extends Pruefstand_Speed_Check {
	public $id        = 'speed_images';
	public $group     = 'Bilder';
	public $weight    = 7;
	public $title     = 'Bilder sind nicht unnötig groß';
	public $problem   = 'Sehr große Bilddateien';
	public $explain   = 'Fotos direkt von Kamera oder Handy sind oft mehrere Megabyte groß und viel breiter als jeder Bildschirm. Das ist meist der größte einzelne Grund für langsame Seiten.';
	public $fix_label = 'Ausgewählte Bilder verkleinern';
	public $fix_does  = 'Verkleinert die Bilder auf höchstens 2048 Pixel Breite und speichert sie platzsparend (Qualität 82 %). Der Unterschied ist mit bloßem Auge kaum zu sehen. Die Originale werden gesichert und lassen sich zurückholen.';
	public $kind      = 'items';
	public $batch     = 2;

	const MAX_BYTES = 350000;
	const MAX_SIDE  = 2048;

	public function run( Pruefstand_Context $ctx ) {
		global $wpdb;
		$ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp') ORDER BY ID DESC LIMIT 2000" );
		$items = array();
		$total = 0;
		foreach ( $ids as $id ) {
			$file = get_attached_file( (int) $id );
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}
			$bytes = filesize( $file );
			$meta  = wp_get_attachment_metadata( (int) $id );
			$w     = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
			$h     = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
			if ( $bytes > self::MAX_BYTES || max( $w, $h ) > self::MAX_SIDE ) {
				$total  += $bytes;
				$items[] = array(
					'id'    => 'att:' . $id,
					'label' => wp_basename( $file ),
					'sub'   => sprintf( '%s, %d × %d px', self::size( $bytes ), $w, $h ),
					'thumb' => wp_get_attachment_image_url( (int) $id, 'thumbnail' ),
				);
			}
		}
		if ( ! $items ) {
			return $this->pass( __( 'Alle Bilder haben eine vernünftige Größe.', 'pruefstand' ) );
		}
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ) ) {
			return $this->fail( __( 'Große Bilder gefunden.', 'pruefstand' ), array( 'items' => $items, 'kind' => 'letter', 'letter' => $this->letter( __( 'Bitte aktivieren Sie die PHP-Erweiterung GD oder Imagick, damit WordPress Bilder bearbeiten kann.', 'pruefstand' ) ) ) );
		}
		return $this->fail( sprintf( __( '%1$d Bilder sind zusammen %2$s groß.', 'pruefstand' ), count( $items ), self::size( $total ) ), array( 'items' => $items ) );
	}

	public function fix( array $req, array &$undo ) {
		$saved = 0;
		$done  = 0;
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			if ( ! preg_match( '/^att:(\d+)$/', isset( $item['id'] ) ? (string) $item['id'] : '', $m ) || ! current_user_can( 'edit_post', (int) $m[1] ) ) {
				continue;
			}
			$id   = (int) $m[1];
			$file = get_attached_file( $id );
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}
			$before = filesize( $file );
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) {
				continue;
			}
			$size = $editor->get_size();
			if ( max( $size['width'], $size['height'] ) > self::MAX_SIDE ) {
				$editor->resize( self::MAX_SIDE, self::MAX_SIDE, false );
			}
			$editor->set_quality( 82 );
			$tmp    = $file . '.pruefstand-tmp.' . pathinfo( $file, PATHINFO_EXTENSION );
			$result = $editor->save( $tmp, get_post_mime_type( $id ) );
			if ( is_wp_error( $result ) || ! file_exists( $tmp ) ) {
				continue;
			}
			clearstatcache();
			$after = filesize( $tmp );
			if ( $after >= $before ) {
				wp_delete_file( $tmp );
				continue;
			}
			$backup = Pruefstand_Env::backup_dir() . '/images/' . $id . '-' . wp_basename( $file );
			wp_mkdir_p( dirname( $backup ) );
			if ( ! copy( $file, $backup ) ) {
				wp_delete_file( $tmp );
				continue;
			}
			rename( $tmp, $file );
			$meta   = wp_get_attachment_metadata( $id );
			$undo[] = array( 'attachment_meta', $id, $meta );
			$undo[] = array( 'file_restore', $file, $backup );
			if ( is_array( $meta ) ) {
				$meta['width']    = $result['width'];
				$meta['height']   = $result['height'];
				$meta['filesize'] = $after;
				wp_update_attachment_metadata( $id, $meta );
			}
			$saved += $before - $after;
			++$done;
		}
		Pruefstand_Page_Cache::purge_all();
		return sprintf( __( '%1$d Bilder verkleinert, %2$s gespart.', 'pruefstand' ), $done, self::size( $saved ) );
	}
}

class Pruefstand_Check_Webp extends Pruefstand_Speed_Check {
	public $id        = 'speed_webp';
	public $group     = 'Bilder';
	public $weight    = 3;
	public $title     = 'Neue Bilder im modernen WebP-Format';
	public $problem   = 'Neue Bilder werden im alten JPEG/PNG-Format gespeichert';
	public $explain   = 'WebP ist ein Bildformat, das bei gleicher Qualität etwa 25–35 % kleiner ist als JPEG. Alle aktuellen Browser können es anzeigen.';
	public $fix_label = 'WebP für neue Uploads einschalten';
	public $fix_does  = 'Ab jetzt erzeugt WordPress beim Hochladen die verkleinerten Bildversionen als WebP. Deine bisherigen Bilder bleiben unverändert.';

	public function run( Pruefstand_Context $ctx ) {
		if ( Pruefstand_Modules::on( 'webp_uploads' ) ) {
			return $this->pass( __( 'Neue Uploads werden als WebP gespeichert.', 'pruefstand' ) );
		}
		$formats = apply_filters( 'image_editor_output_format', array(), '', 'image/jpeg' );
		if ( isset( $formats['image/jpeg'] ) && in_array( $formats['image/jpeg'], array( 'image/webp', 'image/avif' ), true ) ) {
			return $this->pass( __( 'Ein anderes Plugin speichert neue Bilder bereits in einem modernen Format.', 'pruefstand' ) );
		}
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return $this->warn(
				__( 'Der Server kann noch keine WebP-Bilder erzeugen.', 'pruefstand' ),
				array( 'kind' => 'letter', 'letter' => $this->letter( __( 'Bitte aktivieren Sie WebP-Unterstützung in der PHP-Erweiterung GD oder Imagick, damit WordPress Bilder im WebP-Format speichern kann.', 'pruefstand' ) ) )
			);
		}
		return $this->warn( __( 'Der Server kann WebP – es wird nur noch nicht genutzt.', 'pruefstand' ) );
	}

	public function fix( array $req, array &$undo ) {
		Pruefstand_Log::set_module( 'webp_uploads', true, $undo );
		return __( 'Neue Uploads werden als WebP gespeichert.', 'pruefstand' );
	}
}

class Pruefstand_Check_Lcp_Image extends Pruefstand_Speed_Check {
	public $id        = 'speed_lcp';
	public $group     = 'Bilder';
	public $weight    = 6;
	public $title     = 'Das wichtigste Bild lädt zuerst';
	public $problem   = 'Das größte Bild oben auf der Startseite lädt zu spät';
	public $explain   = 'Google misst, wann das größte sichtbare Element erscheint (LCP – „größter sichtbarer Inhalt“). Ist das ein Bild, sollte der Browser es sofort laden. Oft ist es aber auf „später laden“ gestellt, weil WordPress nicht weiß, welches Bild oben steht.';
	public $fix_label = 'Bild vorrangig laden';
	public $fix_does  = 'Sagt dem Browser im Kopf der Startseite, dieses Bild als Erstes zu laden, und nimmt bei diesem einen Bild „später laden“ heraus.';

	public function run( Pruefstand_Context $ctx ) {
		$m = $ctx->measured();
		if ( empty( $m['lcp'] ) ) {
			return $this->skip( __( 'Wird bei der Messung im Browser ermittelt. Starte oben „Ladezeit messen“.', 'pruefstand' ) );
		}
		if ( empty( $m['lcpImage'] ) ) {
			return $this->pass( sprintf( __( 'Größtes Element ist Text (%s) – kein Bild, das vorgeladen werden müsste.', 'pruefstand' ), isset( $m['lcpTag'] ) ? strtolower( $m['lcpTag'] ) : 'Text' ) );
		}
		$img = $m['lcpImage'];
		$set = Pruefstand_Modules::get( 'lcp' );
		if ( ! empty( $set['url'] ) && $set['url'] === $img['url'] ) {
			return $this->pass( sprintf( __( '%s wird vorrangig geladen.', 'pruefstand' ), wp_basename( $img['url'] ) ) );
		}
		if ( 'lazy' !== $img['loading'] && 'high' === $img['priority'] ) {
			return $this->pass( sprintf( __( '%s wird bereits vorrangig geladen.', 'pruefstand' ), wp_basename( $img['url'] ) ) );
		}
		$why = 'lazy' === $img['loading'] ? __( 'ist auf „später laden“ gestellt', 'pruefstand' ) : __( 'hat keine hohe Priorität', 'pruefstand' );
		return $this->fail( sprintf( __( 'Das größte Element ist das Bild %1$s und %2$s.', 'pruefstand' ), wp_basename( $img['url'] ), $why ) );
	}

	public function fix( array $req, array &$undo ) {
		$m = ( new Pruefstand_Context() )->measured();
		if ( empty( $m['lcpImage']['url'] ) ) {
			return new WP_Error( 'pruefstand_lcp', __( 'Bitte zuerst die Ladezeit messen.', 'pruefstand' ) );
		}
		$img  = $m['lcpImage'];
		$path = (string) wp_parse_url( $img['url'], PHP_URL_PATH );
		// Match all size variants of the same upload: /2026/11/hero-1024x683.jpg → /2026/11/hero
		$file = preg_replace( '/(-\d+x\d+|-scaled)?\.\w+$/', '', $path );
		$file = preg_replace( '#^.*?/uploads/#', '/', $file );
		Pruefstand_Log::set_module(
			'lcp',
			array(
				'url'    => esc_url_raw( $img['url'] ),
				'srcset' => isset( $img['srcset'] ) ? sanitize_text_field( $img['srcset'] ) : '',
				'sizes'  => isset( $img['sizes'] ) ? sanitize_text_field( $img['sizes'] ) : '',
				'file'   => $file,
			),
			$undo
		);
		Pruefstand_Page_Cache::purge_all();
		return __( 'Das Bild wird jetzt vorrangig geladen. Miss die Ladezeit erneut, um den Unterschied zu sehen.', 'pruefstand' );
	}
}

class Pruefstand_Check_Render_Blocking extends Pruefstand_Speed_Check {
	public $id        = 'speed_render_blocking';
	public $group     = 'Code';
	public $weight    = 5;
	public $title     = 'Skripte blockieren die Anzeige nicht';
	public $problem   = 'Skripte halten die Anzeige der Seite auf';
	public $explain   = 'Skripte im Kopf der Seite müssen erst geladen und ausgeführt werden, bevor der Browser irgendetwas anzeigt. Mit „defer“ lädt der Browser sie nebenbei und führt sie aus, wenn die Seite schon steht.';
	public $fix_label = 'Skripte verzögert laden';
	public $fix_does  = 'Versieht die Skripte deiner Plugins und deines Themes mit „defer“. jQuery selbst und Skripte, von denen eingebetteter Code abhängt, lässt WordPress automatisch wie bisher. Prüfe danach kurz Menü, Slider und Formulare.';
	public $risk      = 'review';

	public function run( Pruefstand_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$blocking = array_filter(
			$ctx->facts()['scripts_head'],
			function ( $src ) {
				return ! preg_match( '#/jquery(\.min)?\.js|jquery-migrate#', $src );
			}
		);
		if ( ! $blocking ) {
			return $this->pass( __( 'Keine blockierenden Skripte im Kopf der Seite (außer jQuery).', 'pruefstand' ) );
		}
		$names = array_map(
			function ( $src ) {
				return wp_basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
			},
			$blocking
		);
		$extra = array();
		if ( Pruefstand_Modules::on( 'defer_js' ) ) {
			return $this->warn( sprintf( __( 'Bleiben blockierend, weil eingebetteter Code sie sofort braucht: %s', 'pruefstand' ), implode( ', ', $names ) ), array( 'kind' => 'none' ) );
		}
		if ( Pruefstand_Env::optimize_plugin() ) {
			$extra['blocked'] = sprintf( __( '%s ist aktiv und sollte das übernehmen. Prüfe dort die Einstellung für JavaScript.', 'pruefstand' ), Pruefstand_Env::optimize_plugin() );
		}
		return $this->fail( sprintf( _n( '%1$d blockierendes Skript: %2$s', '%1$d blockierende Skripte: %2$s', count( $names ), 'pruefstand' ), count( $names ), implode( ', ', $names ) ), $extra );
	}

	public function fix( array $req, array &$undo ) {
		Pruefstand_Log::set_module( 'defer_js', true, $undo );
		Pruefstand_Page_Cache::purge_all();
		return __( 'Skripte werden jetzt verzögert geladen. Prüfe kurz Menü, Slider und Formulare deiner Seite.', 'pruefstand' );
	}
}

/** "Something unnecessary is loaded on every page" → switch a module on. */
abstract class Pruefstand_Check_Bloat extends Pruefstand_Speed_Check {
	public $group     = 'Code';
	protected $module = '';
	protected $fact   = '';

	public function run( Pruefstand_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		if ( empty( $ctx->facts()[ $this->fact ] ) ) {
			return $this->pass( __( 'Wird nicht geladen.', 'pruefstand' ) );
		}
		return $this->fail( __( 'Wird auf jeder Seite geladen.', 'pruefstand' ) );
	}

	public function fix( array $req, array &$undo ) {
		Pruefstand_Log::set_module( $this->module, true, $undo );
		Pruefstand_Page_Cache::purge_all();
		return __( 'Erledigt.', 'pruefstand' );
	}
}

class Pruefstand_Check_Emoji extends Pruefstand_Check_Bloat {
	public $id        = 'speed_emoji';
	public $weight    = 2;
	public $title     = 'Kein überflüssiges Emoji-Skript';
	public $problem   = 'Ein Emoji-Skript wird auf jeder Seite geladen';
	public $explain   = 'WordPress lädt auf jeder Seite ein Skript, das Emojis für sehr alte Browser nachrüstet. Heutige Browser zeigen Emojis selbst an – das Skript kostet nur Zeit.';
	public $fix_label = 'Emoji-Skript abschalten';
	public $fix_does  = 'Entfernt das Skript und die zugehörigen Styles aus deinen Seiten. Emojis werden weiterhin angezeigt – vom Browser selbst.';
	protected $module = 'no_emoji';
	protected $fact   = 'emoji';
}

class Pruefstand_Check_Jquery_Migrate extends Pruefstand_Check_Bloat {
	public $id        = 'speed_jquery_migrate';
	public $weight    = 2;
	public $title     = 'Kein jQuery Migrate';
	public $problem   = 'jQuery Migrate wird geladen';
	public $explain   = 'jQuery Migrate ist eine Übergangshilfe für sehr alten Code, der mit neuem jQuery nicht mehr funktioniert. Aktuelle Themes und Plugins brauchen es nicht.';
	public $fix_label = 'jQuery Migrate abschalten';
	public $fix_does  = 'Lädt jQuery ohne die Übergangshilfe. Sollte danach etwas nicht mehr funktionieren (z. B. ein Slider), mach es im Protokoll mit einem Klick rückgängig.';
	public $risk      = 'review';
	protected $module = 'no_migrate';
	protected $fact   = 'jq_migrate';
}

class Pruefstand_Check_Dashicons extends Pruefstand_Check_Bloat {
	public $id        = 'speed_dashicons';
	public $weight    = 2;
	public $title     = 'Keine Admin-Symbole für Besucher';
	public $problem   = 'Die Symbolschrift des Admin-Bereichs wird an Besucher geschickt';
	public $explain   = 'Die „Dashicons“ sind die Symbole des WordPress-Admin-Bereichs. Normale Besucher brauchen sie nicht – trotzdem lädt dein Theme oder ein Plugin sie (rund 60 KB).';
	public $fix_label = 'Dashicons für Besucher abschalten';
	public $fix_does  = 'Lädt die Symbolschrift nur noch für angemeldete Nutzer. Braucht ein Plugin sie ausdrücklich, bleibt sie für dieses Plugin erhalten.';
	protected $module = 'no_dashicons';
	protected $fact   = 'dashicons';
}

class Pruefstand_Check_Google_Fonts extends Pruefstand_Speed_Check {
	public $id        = 'speed_google_fonts';
	public $group     = 'Code';
	public $weight    = 5;
	public $title     = 'Schriften kommen vom eigenen Server';
	public $problem   = 'Schriften werden bei Google geladen';
	public $explain   = 'Dein Theme lädt Schriften von Google-Servern. Das kostet eine zusätzliche Verbindung zu einem fremden Server und überträgt dabei die IP-Adresse deiner Besucher an Google – laut Landgericht München (2022) ein Datenschutzverstoß.';
	public $fix_label = 'Schriften lokal speichern';
	public $fix_does  = 'Lädt die Schriftdateien einmal herunter, legt sie in deinen Upload-Ordner und bindet sie von dort ein. Das Aussehen deiner Seite bleibt gleich.';

	public function run( Pruefstand_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$fonts = array_unique( $ctx->facts()['google_fonts'] );
		if ( ! $fonts ) {
			return $this->pass( __( 'Keine Schriften von Google gefunden.', 'pruefstand' ) );
		}
		return $this->fail( sprintf( _n( '%d Schrift-Datei von fonts.googleapis.com', '%d Schrift-Dateien von fonts.googleapis.com', count( $fonts ), 'pruefstand' ), count( $fonts ) ), array( 'fonts' => array_values( $fonts ) ) );
	}

	public function fix( array $req, array &$undo ) {
		$ctx    = new Pruefstand_Context();
		$fonts  = $ctx->home_ok() ? array_unique( $ctx->facts()['google_fonts'] ) : array();
		$map    = (array) Pruefstand_Modules::get( 'local_fonts', array() );
		$errors = array();
		foreach ( $fonts as $css ) {
			$local = Pruefstand_Fonts::localize( $css );
			if ( is_wp_error( $local ) ) {
				$errors[] = $local->get_error_message();
				continue;
			}
			$map[ Pruefstand_Fonts::key( $css ) ] = $local;
		}
		if ( ! $map ) {
			return new WP_Error( 'pruefstand_fonts', implode( ' ', array_unique( $errors ) ) );
		}
		$dir = Pruefstand_Fonts::dir();
		if ( ! Pruefstand_Modules::get( 'local_fonts' ) ) {
			$undo[] = array( 'file_delete', $dir[0] );
		}
		Pruefstand_Log::set_module( 'local_fonts', $map, $undo );
		Pruefstand_Page_Cache::purge_all();

		// Fonts written directly into the theme (not loaded the WordPress way) cannot be swapped.
		$check = new Pruefstand_Context();
		if ( $check->home_ok() && $check->facts()['google_fonts'] ) {
			return __( 'Schriften lokal gespeichert. Ein Teil wird aber direkt vom Theme eingebunden und kommt weiter von Google – frag den Theme-Anbieter nach einer Option „Google Fonts lokal laden“.', 'pruefstand' );
		}
		return __( 'Die Schriften kommen jetzt von deinem eigenen Server.', 'pruefstand' );
	}
}

class Pruefstand_Check_Database extends Pruefstand_Speed_Check {
	public $id        = 'speed_database';
	public $group     = 'Server';
	public $weight    = 3;
	public $title     = 'Aufgeräumte Datenbank';
	public $problem   = 'In der Datenbank hat sich Ballast angesammelt';
	public $explain   = 'Mit der Zeit sammeln sich alte Entwurfsversionen, Spam-Kommentare und abgelaufene Zwischenspeicher. Das bremst Abfragen und macht Sicherungen größer.';
	public $fix_label = 'Datenbank aufräumen';
	public $undoable  = false;
	public $fix_does  = 'Löscht abgelaufene Zwischenspeicher, Spam- und Papierkorb-Kommentare, automatische Entwürfe älter als 7 Tage und alte Versionen von Beiträgen (die letzten 5 je Beitrag bleiben). Ab jetzt hebt WordPress höchstens 10 Versionen auf. Gelöschtes lässt sich nicht zurückholen.';
	public $risk      = 'review';

	private static function counts() {
		global $wpdb;
		$now = time();
		return array(
			'transients' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', $now ) ),
			'revisions'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')" ),
			'drafts'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < %s", gmdate( 'Y-m-d H:i:s', $now - WEEK_IN_SECONDS ) ) ),
			'autoload'   => (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')" ),
		);
	}

	public function run( Pruefstand_Context $ctx ) {
		$c     = self::counts();
		$junk  = $c['transients'] + $c['comments'] + $c['drafts'] + max( 0, $c['revisions'] - 50 );
		$parts = sprintf(
			__( '%1$d alte Versionen, %2$d Spam/Papierkorb-Kommentare, %3$d abgelaufene Zwischenspeicher, %4$d automatische Entwürfe. Automatisch geladene Einstellungen: %5$s.', 'pruefstand' ),
			$c['revisions'],
			$c['comments'],
			$c['transients'],
			$c['drafts'],
			self::size( $c['autoload'] )
		);
		if ( $junk < 100 && $c['revisions'] <= 100 ) {
			return $this->pass( $parts );
		}
		return $this->warn( $parts );
	}

	public function fix( array $req, array &$undo ) {
		global $wpdb;
		$before = self::counts();
		delete_expired_transients( true );

		foreach ( $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash') LIMIT 5000" ) as $cid ) {
			wp_delete_comment( (int) $cid, true );
		}
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < %s LIMIT 2000", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) ) as $pid ) {
			wp_delete_post( (int) $pid, true );
		}
		// Keep the 5 newest revisions of every post.
		$parents = $wpdb->get_col( "SELECT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > 5" );
		foreach ( $parents as $parent ) {
			$old = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC, ID DESC LIMIT 1000 OFFSET 5", $parent ) );
			foreach ( $old as $rid ) {
				wp_delete_post_revision( (int) $rid );
			}
		}
		if ( ! Pruefstand_Modules::get( 'revisions_limit' ) ) {
			Pruefstand_Modules::set( 'revisions_limit', 10 );
		}
		$after = self::counts();
		return sprintf(
			__( 'Aufgeräumt: %1$d Versionen, %2$d Kommentare, %3$d Zwischenspeicher, %4$d Entwürfe.', 'pruefstand' ),
			$before['revisions'] - $after['revisions'],
			$before['comments'] - $after['comments'],
			$before['transients'] - $after['transients'],
			$before['drafts'] - $after['drafts']
		);
	}
}

class Pruefstand_Check_Php_Version extends Pruefstand_Speed_Check {
	public $id        = 'speed_php';
	public $group     = 'Server';
	public $weight    = 5;
	public $title     = 'Aktuelle PHP-Version';
	public $problem   = 'Veraltete PHP-Version';
	public $explain   = 'PHP ist die Programmiersprache, in der WordPress läuft. Neuere Versionen sind deutlich schneller und bekommen Sicherheitsupdates. Umstellen kann nur dein Hoster – oft reicht ein Klick in dessen Kundenmenü.';
	public $fix_label = 'Nachricht an den Hoster';
	public $fix_does  = 'Prüfstand hat eine fertige Nachricht vorbereitet. Kopiere sie und schicke sie an deinen Hoster. Viele Hoster bieten die Umstellung auch selbst im Kundenmenü an (Stichwort „PHP-Version“).';
	public $kind      = 'letter';

	public function run( Pruefstand_Context $ctx ) {
		$letter = $this->letter( __( 'Bitte stellen Sie die PHP-Version meiner Website auf PHP 8.3 oder neuer um. Die aktuelle Version erhält keine Sicherheitsupdates mehr bzw. nur noch für kurze Zeit.', 'pruefstand' ) );
		if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
			return $this->fail( sprintf( __( 'Deine Website läuft mit PHP %s. Diese Version bekommt keine Sicherheitsupdates mehr.', 'pruefstand' ), PHP_VERSION ), array( 'letter' => $letter ) );
		}
		if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
			return $this->warn( sprintf( __( 'PHP %s bekommt nur noch bis Ende 2026 Sicherheitsupdates.', 'pruefstand' ), PHP_VERSION ), array( 'letter' => $letter ) );
		}
		return $this->pass( sprintf( __( 'PHP %s', 'pruefstand' ), PHP_VERSION ) );
	}
}

class Pruefstand_Check_Object_Cache extends Pruefstand_Speed_Check {
	public $id        = 'speed_object_cache';
	public $group     = 'Server';
	public $weight    = 2;
	public $title     = 'Datenbank-Zwischenspeicher (Objekt-Cache)';
	public $problem   = 'Kein dauerhafter Objekt-Cache';
	public $explain   = 'Ein Objekt-Cache (Redis oder Memcached) merkt sich Ergebnisse von Datenbank-Abfragen. Das hilft vor allem bei Shops, Mitgliederbereichen und großen Websites, also überall, wo der Seiten-Cache nicht greift.';
	public $fix_label = 'Nachricht an den Hoster';
	public $fix_does  = 'Prüfstand hat eine fertige Nachricht vorbereitet, in der du deinen Hoster um Redis bittest. Viele Hoster bieten es kostenlos an.';
	public $kind      = 'letter';

	public function run( Pruefstand_Context $ctx ) {
		if ( wp_using_ext_object_cache() ) {
			return $this->pass( __( 'Ein Objekt-Cache ist aktiv.', 'pruefstand' ) );
		}
		$count = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'page' )->publish;
		if ( $count < 50 && ! class_exists( 'WooCommerce' ) ) {
			return $this->pass( __( 'Für eine Website dieser Größe nicht nötig.', 'pruefstand' ) );
		}
		return $this->warn(
			__( 'WordPress fragt bei jedem Aufruf die Datenbank neu ab.', 'pruefstand' ),
			array( 'letter' => $this->letter( __( 'Bitte richten Sie für meine Website einen Redis- oder Memcached-Objekt-Cache ein und teilen Sie mir die Verbindungsdaten mit (oder aktivieren Sie ihn direkt für WordPress).', 'pruefstand' ) ) )
		);
	}
}
