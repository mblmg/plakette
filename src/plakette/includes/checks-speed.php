<?php
/**
 * Page speed checks: server, files, images and code that slows the first view down.
 */

defined( 'ABSPATH' ) || exit;

abstract class Plakette_Speed_Check extends Plakette_Check {
	public $area = 'speed';
}

class Plakette_Check_Page_Cache extends Plakette_Speed_Check {
	public $id        = 'speed_page_cache';
	public $group     = 'Server';
	public $weight    = 9;
	public $title     = 'Seiten werden zwischengespeichert (Cache)';
	public $problem   = 'Jede Seite wird bei jedem Besuch neu gebaut';
	public $explain   = 'Ohne Cache setzt WordPress jede Seite bei jedem Besuch neu aus der Datenbank zusammen. Mit Cache wird die fertige Seite gespeichert und sofort ausgeliefert – meist 5- bis 20-mal schneller.';
	public $fix_label = 'Seiten-Cache einschalten';
	public $fix_does  = 'Speichert fertige Seiten für Besucher, die nicht angemeldet sind. Der Cache leert sich automatisch, wenn du etwas änderst. Angemeldete Nutzer, Warenkorb und Kommentare sind ausgenommen.';
	public $risk      = 'review';

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$home    = $ctx->home();
		$h       = $home['headers'];
		$time    = sprintf( __( 'Antwortzeit des Servers: %d ms', 'plakette' ), $home['time_ms'] );
		$plugin  = Plakette_Env::cache_plugin();
		$headers = array( 'x-cache', 'cf-cache-status', 'x-litespeed-cache', 'x-proxy-cache', 'x-sucuri-cache', 'x-nginx-cache', 'x-fastcgi-cache', 'x-srcache-fetch-status', 'x-plakette-cache', 'x-wp-super-cache', 'x-rocket-nginx-serving-static' );
		foreach ( $headers as $name ) {
			if ( isset( $h[ $name ] ) && preg_match( '/hit/i', $h[ $name ] ) ) {
				return $this->pass( $time . '. ' . sprintf( __( 'Cache aktiv (%s).', 'plakette' ), $name ) );
			}
		}
		if ( Plakette_Page_Cache::enabled() ) {
			return $this->warn( $time . '. ' . __( 'Der Seiten-Cache von Plakette ist eingeschaltet, hat diese Seite aber nicht ausgeliefert. Prüfe, ob ein Plugin Cookies setzt.', 'plakette' ), array( 'kind' => 'none' ) );
		}
		if ( $plugin ) {
			return $this->pass( $time . '. ' . sprintf( __( '%s ist aktiv.', 'plakette' ), $plugin ) );
		}
		$extra = array();
		if ( ! wp_is_writable( WP_CONTENT_DIR ) || ( ! ( defined( 'WP_CACHE' ) && WP_CACHE ) && ! wp_is_writable( (string) Plakette_Env::wpconfig_path() ) ) ) {
			$extra['blocked'] = __( 'Plakette darf die nötigen Dateien (wp-content, wp-config.php) nicht schreiben.', 'plakette' );
		}
		return $home['time_ms'] > 400 ? $this->fail( $time . '.', $extra ) : $this->warn( $time . '. ' . __( 'Schon flott – ein Cache hält das auch bei vielen Besuchern so.', 'plakette' ), $extra );
	}

	public function fix( array $req, array &$undo ) {
		$ops = Plakette_Page_Cache::enable();
		if ( is_wp_error( $ops ) ) {
			return $ops;
		}
		$undo = array_merge( $undo, $ops );
		return __( 'Der Seiten-Cache ist eingeschaltet. Ab dem zweiten Aufruf werden Seiten aus dem Cache geliefert.', 'plakette' );
	}
}

class Plakette_Check_Compression extends Plakette_Speed_Check {
	public $id        = 'speed_compression';
	public $group     = 'Server';
	public $weight    = 6;
	public $title     = 'Daten werden komprimiert übertragen';
	public $problem   = 'Seiten werden unkomprimiert übertragen';
	public $explain   = 'Webserver können Seiten wie eine ZIP-Datei verkleinert verschicken (gzip oder Brotli). Der Browser packt sie blitzschnell wieder aus. Text wird dadurch etwa 70 % kleiner – gerade im Mobilfunknetz spürbar.';
	public $fix_label = 'Komprimierung einschalten';
	public $fix_does  = 'Ergänzt in der Datei .htaccess Regeln für gzip/Brotli (bei Apache-Servern) und lässt WordPress seine Seiten selbst komprimieren, wenn der Server es nicht tut.';

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$home = $ctx->home();
		$enc  = isset( $home['headers']['content-encoding'] ) ? strtolower( $home['headers']['content-encoding'] ) : '';
		if ( preg_match( '/gzip|br|zstd|deflate/', $enc ) ) {
			return $this->pass( sprintf( __( 'Komprimierung: %s', 'plakette' ), $enc ) );
		}
		if ( ! function_exists( 'ob_gzhandler' ) && ! Plakette_Env::htaccess_writable() ) {
			return $this->fail(
				sprintf( __( 'Die Startseite (%s) wird unkomprimiert übertragen.', 'plakette' ), self::size( $home['facts']['bytes'] ) ),
				array( 'kind' => 'letter', 'letter' => $this->letter( __( 'Bitte aktivieren Sie die gzip- oder Brotli-Komprimierung für HTML, CSS, JavaScript, SVG und JSON.', 'plakette' ) ) )
			);
		}
		return $this->fail( sprintf( __( 'Die Startseite (%s) wird unkomprimiert übertragen.', 'plakette' ), self::size( $home['facts']['bytes'] ) ) );
	}

	public function fix( array $req, array &$undo ) {
		if ( Plakette_Env::htaccess_writable() ) {
			$op = Plakette_Env::write_htaccess_block(
				'Plakette Kompression',
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
			Plakette_Log::set_module( 'html_gzip', true, $undo );
			$flag = WP_CONTENT_DIR . '/plakette-gzip.flag';
			if ( ! file_exists( $flag ) && false !== @file_put_contents( $flag, '1' ) ) { // phpcs:ignore
				$undo[] = array( 'file_delete', $flag );
			}
		}
		Plakette_Page_Cache::purge_all();
		// Did it work? Ask the server again.
		$res = Plakette_Crawler::fetch( home_url( '/' ) );
		$enc = $res['ok'] && isset( $res['headers']['content-encoding'] ) ? $res['headers']['content-encoding'] : '';
		if ( $enc ) {
			return sprintf( __( 'Komprimierung aktiv (%s).', 'plakette' ), $enc );
		}
		return __( 'Regeln gespeichert. Der Server komprimiert trotzdem noch nicht – bitte frag deinen Hoster (Vorlage erscheint nach der nächsten Prüfung).', 'plakette' );
	}
}

class Plakette_Check_Browser_Cache extends Plakette_Speed_Check {
	public $id        = 'speed_browser_cache';
	public $group     = 'Server';
	public $weight    = 4;
	public $title     = 'Browser speichern Bilder, Schriften und Skripte';
	public $problem   = 'Browser laden dieselben Dateien bei jedem Besuch neu';
	public $explain   = 'Logo, Schriften und Design-Dateien ändern sich selten. Der Server kann Browsern sagen: „Behalte das ein paar Monate.“ Wiederkehrende Besucher und jede weitere Unterseite laden dann viel schneller.';
	public $fix_label = 'Browser-Cache einschalten';
	public $fix_does  = 'Ergänzt in der Datei .htaccess Regeln, wie lange Browser Bilder, Schriften, CSS und JavaScript aufheben dürfen. WordPress hängt bei Änderungen eine neue Versionsnummer an, Besucher sehen also trotzdem immer den aktuellen Stand.';

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$asset = '';
		foreach ( $ctx->facts()['styles'] as $href ) {
			if ( Plakette_Crawler::is_local( $href ) ) {
				$asset = Plakette_Crawler::absolutize( $href );
				break;
			}
		}
		if ( ! $asset ) {
			return $this->skip( __( 'Keine eigene CSS-Datei auf der Startseite gefunden.', 'plakette' ) );
		}
		$res = Plakette_Crawler::fetch( $asset, 'HEAD' );
		if ( ! $res['ok'] ) {
			return $this->skip( __( 'Die Testdatei konnte nicht abgerufen werden.', 'plakette' ) );
		}
		$cc  = isset( $res['headers']['cache-control'] ) ? $res['headers']['cache-control'] : '';
		$age = preg_match( '/max-age=(\d+)/', $cc, $m ) ? (int) $m[1] : 0;
		if ( ! $age && ! empty( $res['headers']['expires'] ) ) {
			$age = max( 0, strtotime( $res['headers']['expires'] ) - time() );
		}
		if ( $age >= WEEK_IN_SECONDS ) {
			return $this->pass( sprintf( __( 'CSS-Dateien werden %d Tage aufbewahrt.', 'plakette' ), floor( $age / DAY_IN_SECONDS ) ) );
		}
		$detail = $age ? sprintf( __( 'CSS-Dateien werden nur %s aufbewahrt.', 'plakette' ), human_time_diff( 0, $age ) ) : __( 'Der Server sagt Browsern nicht, wie lange sie Dateien aufheben dürfen.', 'plakette' );
		if ( ! Plakette_Env::htaccess_writable() ) {
			return $this->fail(
				$detail,
				array( 'kind' => 'letter', 'letter' => $this->letter( __( 'Bitte setzen Sie für statische Dateien (Bilder, Schriften, CSS, JavaScript) einen Cache-Control-Header mit langer Gültigkeit, z. B. „Cache-Control: public, max-age=31536000“ für Schriften und Bilder sowie mindestens 30 Tage für CSS und JavaScript.', 'plakette' ) ) )
			);
		}
		return $this->fail( $detail );
	}

	public function fix( array $req, array &$undo ) {
		$op = Plakette_Env::write_htaccess_block(
			'Plakette Browser-Cache',
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
		return __( 'Regeln für den Browser-Cache gespeichert.', 'plakette' );
	}
}

class Plakette_Check_Large_Images extends Plakette_Speed_Check {
	public $id        = 'speed_images';
	public $group     = 'Bilder';
	public $weight    = 7;
	public $title     = 'Bilder sind nicht unnötig groß';
	public $problem   = 'Sehr große Bilddateien';
	public $explain   = 'Fotos direkt von Kamera oder Handy sind oft mehrere Megabyte groß und viel breiter als jeder Bildschirm. Das ist meist der größte einzelne Grund für langsame Seiten. Plakette zählt nur Bilder, die auf deiner Website wirklich verwendet werden – was nur in der Mediathek liegt, lädt kein Besucher.';
	public $fix_label = 'Ausgewählte Bilder verkleinern';
	public $fix_does  = 'Verkleinert die Bilder auf höchstens 2048 Pixel Breite und speichert sie platzsparend (Qualität 82 %). Der Unterschied ist mit bloßem Auge kaum zu sehen. Die Originale werden gesichert und lassen sich zurückholen.';
	public $kind      = 'items';
	public $batch     = 2;

	const MAX_BYTES = 350000;
	const MAX_SIDE  = 2048;

	/** Delivered images heavier than this (at the candidate a browser picks) are flagged. */
	const MAX_DELIVERED = 250000;

	/** Max. extra pages fetched per audit to see how images that are only used elsewhere really get delivered. */
	const MAX_EXTRA_PAGES = 6;

	public function run( Plakette_Context $ctx ) {
		global $wpdb;
		// What visitors really download (home + sample pages): format and bytes of the picked candidate.
		$delivered = array();
		$heavy     = array();
		$covered   = 0;
		$who       = array();
		$seen      = array();
		$add_rows  = function ( array $rows, $page ) use ( &$delivered, &$heavy, &$who, &$seen ) {
			foreach ( Plakette_Image_Delivery::raster( $rows ) as $row ) {
				$k = $row['fallback'] . '|' . $row['via'];
				if ( isset( $seen[ $k ] ) ) {
					continue;
				}
				$seen[ $k ]  = true;
				$row['page'] = $row['page'] ?? $page;
				$bytes       = max( array_map( fn( $vp ) => (int) $row[ $vp ]['bytes'], self::viewports( $row ) ) );
				if ( $row['attachment'] ) {
					$delivered[ $row['attachment'] ] = max( $delivered[ $row['attachment'] ] ?? 0, $bytes );
				}
				if ( $bytes > self::MAX_DELIVERED ) {
					$heavy[] = $row;
				} elseif ( $row['modern'] && '' !== $row['who'] ) {
					$who[ $row['who'] ] = true;
				}
			}
		};
		if ( $ctx->home_ok() ) {
			$add_rows( Plakette_Image_Delivery::for_context( $ctx ), '' );
		}
		$ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp') ORDER BY ID DESC LIMIT 2000" );
		$big   = array();
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$file = get_attached_file( $id );
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}
			$bytes = filesize( $file );
			$meta  = wp_get_attachment_metadata( $id );
			$w     = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
			$h     = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
			if ( $bytes <= self::MAX_BYTES && max( $w, $h ) <= self::MAX_SIDE ) {
				continue;
			}
			$big[ $id ] = array( $file, $bytes, $w, $h );
		}

		// Big images used on pages outside the sample: open those pages and look at what a browser picks there –
		// a page usually shows a smaller size from the srcset, not the original.
		if ( $ctx->home_ok() ) {
			$fetched = array( home_url( '/' ) => true );
			foreach ( $ctx->all_html() as $pid => $html ) {
				if ( $pid ) {
					$fetched[ (string) get_permalink( $pid ) ] = true;
				}
			}
			$extra = 0;
			foreach ( $big as $id => $info ) {
				if ( isset( $delivered[ $id ] ) || $extra >= self::MAX_EXTRA_PAGES || ! Plakette_Image_Usage::is_used( $id, $info[0] ) ) {
					continue;
				}
				foreach ( Plakette_Image_Usage::pages( $id, $info[0] ) as $url ) {
					if ( isset( $delivered[ $id ] ) || $extra >= self::MAX_EXTRA_PAGES ) {
						break;
					}
					if ( isset( $fetched[ $url ] ) ) {
						continue;
					}
					$fetched[ $url ] = true;
					++$extra;
					$res = Plakette_Crawler::fetch( $url );
					if ( $res['ok'] && $res['status'] < 400 ) {
						$add_rows( Plakette_Image_Delivery::analyse( $res['body'], $res['url'] ), $res['url'] );
					}
				}
			}
		}

		$items        = array();
		$total        = 0;
		$notes        = array();
		$unused       = 0;
		$unused_bytes = 0;
		foreach ( $big as $id => $info ) {
			list( $file, $bytes, $w, $h ) = $info;
			// A big original only costs visitors when they get it: judge what is delivered, not the library.
			if ( isset( $delivered[ $id ] ) && $delivered[ $id ] <= self::MAX_DELIVERED ) {
				++$covered;
				continue;
			}
			// Only in the media library, on no page: nobody downloads it – no defect.
			if ( ! isset( $delivered[ $id ] ) && ! Plakette_Image_Usage::is_used( $id, $file ) ) {
				++$unused;
				$unused_bytes += $bytes;
				continue;
			}
			$copy = Plakette_Image_Delivery::modern_copy_bytes( $file );
			if ( ! isset( $delivered[ $id ] ) && $copy && $copy <= self::MAX_BYTES ) {
				++$covered;
				continue;
			}
			// Visitors get a size WordPress made from it, not the original: shrinking the original changes nothing.
			if ( isset( $delivered[ $id ] ) && ! self::gets_original( $heavy, $id, $file ) ) {
				continue;
			}
			$total  += $bytes;
			$items[] = array(
				'id'    => 'att:' . $id,
				'label' => wp_basename( $file ),
				'sub'   => isset( $delivered[ $id ] )
					? sprintf( __( '%1$s, %2$d × %3$d px – Besucher laden %4$s', 'plakette' ), self::size( $bytes ), $w, $h, self::size( $delivered[ $id ] ) )
					: sprintf( '%s, %d × %d px', self::size( $bytes ), $w, $h ),
				'thumb' => wp_get_attachment_image_url( $id, 'thumbnail' ),
			);
		}
		foreach ( $heavy as $row ) {
			if ( ! $row['attachment'] ) {
				$notes[] = Plakette_Image_Delivery::describe( $row ) . ' ' . __( '(nicht aus der Mediathek – im Theme/Page Builder verkleinern)', 'plakette' );
			} elseif ( isset( $big[ $row['attachment'] ] ) && ! self::gets_original( array( $row ), $row['attachment'], $big[ $row['attachment'] ][0] ) ) {
				$notes[] = sprintf( __( '%1$s – eine von WordPress erzeugte Bildgröße, nicht das Original. Prüfe, ob das Bild auf der Seite kleiner angezeigt werden kann.', 'plakette' ), self::delivered_names( $row ) );
			}
		}
		if ( $covered ) {
			$notes[] = sprintf(
				_n( '%d großes Original wird nicht ausgeliefert – Besucher bekommen eine kleinere Version%s.', '%d große Originale werden nicht ausgeliefert – Besucher bekommen kleinere Versionen%s.', $covered, 'plakette' ),
				$covered,
				$who ? ' (' . sprintf( __( '%s liefert moderne Formate aus', 'plakette' ), implode( ', ', array_keys( $who ) ) ) . ')' : ''
			);
		}
		$info = 0;
		if ( $covered ) {
			++$info;
		}
		if ( $unused ) {
			++$info;
			$notes[] = sprintf(
				_n( '%1$d großes Bild (%2$s) liegt nur in der Mediathek und wird auf keiner Seite verwendet – es bremst niemanden und zählt nicht als Mangel.', '%1$d große Bilder (zusammen %2$s) liegen nur in der Mediathek und werden auf keiner Seite verwendet – sie bremsen niemanden und zählen nicht als Mangel.', $unused, 'plakette' ),
				$unused,
				self::size( $unused_bytes )
			);
		}
		if ( ! $items ) {
			$detail = $notes && count( $notes ) > $info
				? __( 'Kein Original ist zu groß – aber manche Bilder kommen bei Besuchern noch recht schwer an.', 'plakette' )
				: __( 'Besucher laden keine unnötig großen Bilder.', 'plakette' );
			return $notes && count( $notes ) > $info
				? $this->warn( $detail, array( 'notes' => $notes, 'kind' => 'none' ) )
				: $this->pass( $detail, $notes ? array( 'notes' => $notes ) : array() );
		}
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ) ) {
			return $this->fail( __( 'Große Bilder gefunden.', 'plakette' ), array( 'items' => $items, 'notes' => $notes, 'kind' => 'letter', 'letter' => $this->letter( __( 'Bitte aktivieren Sie die PHP-Erweiterung GD oder Imagick, damit WordPress Bilder bearbeiten kann.', 'plakette' ) ) ) );
		}
		return $this->fail( sprintf( _n( '%1$d Bild, das auf deiner Website verwendet wird, ist %2$s groß.', '%1$d Bilder, die auf deiner Website verwendet werden, sind zusammen %2$s groß.', count( $items ), 'plakette' ), count( $items ), self::size( $total ) ), array( 'items' => $items, 'notes' => $notes ) );
	}

	/**
	 * Viewports whose pick can be trusted. With sizes="auto" (lazy images) the browser uses the width the image really
	 * has on the page – on a phone that is about the screen width, on a desktop usually a column we cannot see from here.
	 */
	private static function viewports( array $row ) {
		$auto = fn( $s ) => str_starts_with( strtolower( trim( (string) $s ) ), 'auto' );
		return ! empty( $row['width'] ) && ( $auto( $row['sizes'] ?? '' ) || $auto( $row['img_sizes'] ?? '' ) ) ? array( 'mobile' ) : array( 'desktop', 'mobile' );
	}

	/** Does any heavy delivery of attachment $id hand out the original file (or its WebP/AVIF copy)? */
	private static function gets_original( array $heavy, $id, $file ) {
		$orig = strtolower( wp_basename( $file ) );
		foreach ( $heavy as $row ) {
			if ( (int) $row['attachment'] !== (int) $id ) {
				continue;
			}
			foreach ( self::viewports( $row ) as $vp ) {
				if ( (int) $row[ $vp ]['bytes'] <= self::MAX_DELIVERED ) {
					continue;
				}
				$name = strtolower( rawurldecode( wp_basename( (string) wp_parse_url( $row[ $vp ]['url'], PHP_URL_PATH ) ) ) );
				$name = preg_replace( '/\.(jpe?g|png)\.(webp|avif)$/', '.$1', $name );
				if ( $name === $orig || preg_replace( '/\.\w+$/', '', $name ) === preg_replace( '/\.\w+$/', '', $orig ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** "bild-1536x1231.jpg.webp – 296 KB am Handy" for a heavy library image (the file really loaded). */
	private static function delivered_names( array $row ) {
		$name = fn( $u ) => rawurldecode( wp_basename( (string) wp_parse_url( $u, PHP_URL_PATH ) ) );
		$m    = $row['mobile'];
		$d    = $row['desktop'];
		$vps  = self::viewports( $row );
		if ( $m['url'] === $d['url'] && 2 === count( $vps ) ) {
			return sprintf( '%s (%s)', $name( $d['url'] ), self::size( max( $m['bytes'], $d['bytes'] ) ) );
		}
		$parts = array();
		if ( $m['bytes'] > self::MAX_DELIVERED ) {
			$parts[] = sprintf( __( '%1$s am Handy (%2$s)', 'plakette' ), $name( $m['url'] ), self::size( $m['bytes'] ) );
		}
		if ( in_array( 'desktop', $vps, true ) && $d['bytes'] > self::MAX_DELIVERED ) {
			$parts[] = sprintf( __( '%1$s am Desktop (%2$s)', 'plakette' ), $name( $d['url'] ), self::size( $d['bytes'] ) );
		}
		return implode( ', ', $parts );
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
			$tmp    = $file . '.plakette-tmp.' . pathinfo( $file, PATHINFO_EXTENSION );
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
			$backup = Plakette_Env::backup_dir() . '/images/' . $id . '-' . wp_basename( $file );
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
		Plakette_Page_Cache::purge_all();
		return sprintf( __( '%1$d Bilder verkleinert, %2$s gespart.', 'plakette' ), $done, self::size( $saved ) );
	}
}

class Plakette_Check_Webp extends Plakette_Speed_Check {
	public $id        = 'speed_webp';
	public $group     = 'Bilder';
	public $weight    = 5;
	public $title     = 'Moderne Bildformate (AVIF/WebP)';
	public $problem   = 'Bilder werden nur als JPEG/PNG ausgeliefert';
	public $explain   = 'AVIF und WebP sind moderne Bildformate: Bei gleicher Qualität ist WebP etwa die Hälfte, AVIF noch einmal 20–25 % kleiner als JPEG. Kleinere Bilder heißen schnellere Seiten – besonders am Handy. Ältere Browser können AVIF nicht anzeigen; deshalb gehört immer das Original als Rückfall dazu.';
	public $fix_label = 'Bilder umwandeln';
	public $fix_does  = 'Plakette legt neben jedes Bild und jede Bildgröße eine AVIF- und eine WebP-Kopie (nur, wenn sie wirklich kleiner ist). Beim Ausliefern bekommt jeder Browser das kleinste Format, das er kann – ältere Browser weiter das Original. Neue Uploads wandelt Plakette automatisch um. Deine Originale bleiben unverändert; „Rückgängig“ löscht genau die Kopien, die Plakette angelegt hat.';
	public $kind      = 'items';
	public $batch     = 3;

	public function run( Plakette_Context $ctx ) {
		$formats = Plakette_Modern_Images::formats();
		$which   = $formats['avif'] ? 'AVIF + WebP' : 'WebP';
		$imgs    = $ctx->home_ok() ? Plakette_Image_Delivery::raster( Plakette_Image_Delivery::for_context( $ctx ) ) : array();
		$foreign = Plakette_Image_Delivery::foreign();
		$legacy  = array_values( array_filter( $imgs, fn( $r ) => ! $r['modern'] ) );

		// Who delivers what – judged by what a browser really downloads.
		$by = array();
		foreach ( $imgs as $r ) {
			if ( $r['modern'] ) {
				$key        = $r['who'] . '|' . Plakette_Image_Delivery::label( $r['desktop']['format'] );
				$by[ $key ] = ( $by[ $key ] ?? 0 ) + 1;
			}
		}
		$per = array();
		foreach ( $by as $key => $n ) {
			list( $name, $fmt )    = explode( '|', $key );
			$per[ $name ][ $fmt ] = $n;
		}
		$who = array();
		foreach ( $per as $name => $fmts ) {
			$parts = array();
			foreach ( $fmts as $fmt => $n ) {
				$parts[] = sprintf( _n( '%1$d Bild als %2$s', '%1$d Bilder als %2$s', $n, 'plakette' ), $n, $fmt );
			}
			$who[] = sprintf( __( '%1$s liefert %2$s aus', 'plakette' ), $name, implode( __( ' und ', 'plakette' ), $parts ) );
		}
		$who_text = $who ? implode( ', ', $who ) . '.' : '';

		if ( ! $legacy ) {
			$todo = Plakette_Modern_Images::active() && ! $foreign ? Plakette_Modern_Images::todo() : array();
			if ( $todo ) {
				return $this->warn( sprintf( _n( '%d Bild der Mediathek ist noch nicht umgewandelt.', '%d Bilder der Mediathek sind noch nicht umgewandelt.', count( $todo ), 'plakette' ), count( $todo ) ), array( 'items' => $this->items( $todo ) ) );
			}
			if ( $imgs ) {
				return $this->pass( ucfirst( $who_text ) . ' ' . __( 'Jeder Browser bekommt ein modernes Format.', 'plakette' ) );
			}
			return $this->pass( __( 'Auf den geprüften Seiten gibt es keine Fotos.', 'plakette' ) );
		}

		$notes = array_map( array( 'Plakette_Image_Delivery', 'describe' ), array_slice( $legacy, 0, 10 ) );
		$detail = sprintf( _n( '%d Bild kommt bei Besuchern als JPEG/PNG an.', '%d Bilder kommen bei Besuchern als JPEG/PNG an.', count( $legacy ), 'plakette' ), count( $legacy ) );
		if ( $foreign ) {
			// Another optimiser is at work – Plakette does not run a second one in parallel.
			return $this->warn(
				$detail . ' ' . ucfirst( $who_text ) . ' ' . sprintf( __( 'Prüfe, warum %s diese Bilder nicht umwandelt (z. B. noch nicht optimiert, Ordner ausgeschlossen, Bild aus dem Theme). Plakette schaltet kein zweites Umwandeln parallel dazu ein.', 'plakette' ), implode( ', ', array_keys( $foreign ) ) ),
				array( 'notes' => $notes, 'kind' => 'none' )
			);
		}
		if ( ! $formats['webp'] && ! $formats['avif'] ) {
			return $this->warn(
				$detail . ' ' . __( 'Der Server kann weder WebP noch AVIF erzeugen.', 'plakette' ),
				array( 'notes' => $notes, 'kind' => 'letter', 'letter' => $this->letter( __( 'Bitte aktivieren Sie AVIF- und WebP-Unterstützung in der PHP-Erweiterung GD oder Imagick, damit WordPress Bilder in modernen Formaten speichern kann.', 'plakette' ) ) )
			);
		}
		// Library images of the page first, then everything else that is not converted yet.
		$ids = array();
		foreach ( $legacy as $r ) {
			if ( $r['attachment'] && preg_match( '#^image/(jpe?g|png)$#', (string) get_post_mime_type( $r['attachment'] ) ) ) {
				$ids[] = $r['attachment'];
			}
		}
		$ids = array_values( array_unique( array_merge( $ids, Plakette_Modern_Images::todo() ) ) );
		if ( ! $ids ) {
			return $this->warn( $detail . ' ' . __( 'Diese Bilder liegen nicht in der Mediathek (Theme, Page Builder oder fremder Server) – Plakette kann sie nicht umwandeln.', 'plakette' ), array( 'notes' => $notes, 'kind' => 'none' ) );
		}
		$extra = array( 'notes' => $notes, 'items' => $this->items( $ids ) );
		$detail .= ' ' . sprintf( __( 'Der Server kann %s.', 'plakette' ), $which );
		if ( Plakette_Modern_Images::active() ) {
			return $this->warn( $detail . ' ' . __( 'Moderne Formate sind eingeschaltet; diese Bilder sind noch nicht umgewandelt.', 'plakette' ), $extra );
		}
		return $this->fail( $detail, $extra );
	}

	private function items( array $ids ) {
		$items = array();
		foreach ( array_slice( $ids, 0, 300 ) as $id ) {
			$n       = count( Plakette_Modern_Images::attachment_files( $id ) );
			$items[] = array(
				'id'    => 'att:' . $id,
				'label' => wp_basename( (string) get_attached_file( $id ) ),
				'sub'   => sprintf( _n( '%d Bildgröße', '%d Bildgrößen', $n, 'plakette' ), $n ),
				'thumb' => wp_get_attachment_image_url( $id, 'thumbnail' ),
			);
		}
		return $items;
	}

	public function fix( array $req, array &$undo ) {
		if ( ! Plakette_Modules::on( 'modern_images' ) ) {
			Plakette_Log::set_module( 'modern_images', true, $undo );
			// Undo of the module also removes copies made later for new uploads (replayed before the module goes off).
			$undo[] = array( 'callback', 'Plakette_Modern_Images::clear_all' );
		}
		$deadline = microtime( true ) + Plakette_Modern_Images::BUDGET;
		$done     = 0;
		$left     = 0;
		$saved    = 0;
		$files    = 0;
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			if ( ! preg_match( '/^att:(\d+)$/', isset( $item['id'] ) ? (string) $item['id'] : '', $m ) || ! current_user_can( 'edit_post', (int) $m[1] ) ) {
				continue;
			}
			$id = (int) $m[1];
			if ( microtime( true ) > $deadline ) {
				++$left;
				continue;
			}
			$existed = metadata_exists( 'post', $id, Plakette_Modern_Images::META );
			$undo[]  = array( 'postmeta', $id, Plakette_Modern_Images::META, $existed, $existed ? get_post_meta( $id, Plakette_Modern_Images::META, true ) : null );
			$r       = Plakette_Modern_Images::convert_attachment( $id, $deadline );
			foreach ( array_keys( $r['created'] ) as $file ) {
				$undo[] = array( 'file_delete', $file );
				++$files;
			}
			$saved += $r['saved'];
			$r['done'] ? ++$done : ++$left;
		}
		// Everything else of the library that is not converted yet (as far as the time allows).
		if ( ! $left ) {
			foreach ( Plakette_Modern_Images::todo() as $id ) {
				if ( microtime( true ) > $deadline ) {
					++$left;
					continue;
				}
				$existed = metadata_exists( 'post', $id, Plakette_Modern_Images::META );
				$undo[]  = array( 'postmeta', $id, Plakette_Modern_Images::META, $existed, $existed ? get_post_meta( $id, Plakette_Modern_Images::META, true ) : null );
				$r       = Plakette_Modern_Images::convert_attachment( $id, $deadline );
				foreach ( array_keys( $r['created'] ) as $file ) {
					$undo[] = array( 'file_delete', $file );
					++$files;
				}
				$saved += $r['saved'];
				$r['done'] ? ++$done : ++$left;
			}
		}
		Plakette_Page_Cache::purge_all();
		$msg = sprintf( __( '%1$d Bilder umgewandelt (%2$d neue Dateien), %3$s kleiner.', 'plakette' ), $done, $files, self::size( $saved ) );
		if ( $left ) {
			$msg .= ' ' . sprintf( __( '%d Bilder sind noch offen – Plakette macht im Hintergrund weiter, oder starte die Prüfung erneut.', 'plakette' ), $left );
		}
		return $msg;
	}
}

class Plakette_Check_Lcp_Image extends Plakette_Speed_Check {
	public $id        = 'speed_lcp';
	public $group     = 'Bilder';
	public $weight    = 6;
	public $title     = 'Das wichtigste Bild lädt zuerst';
	public $problem   = 'Das größte Bild oben auf der Startseite lädt zu spät';
	public $explain   = 'Google misst, wann das größte sichtbare Element erscheint (LCP – „größter sichtbarer Inhalt“). Ist das ein Bild, sollte der Browser es sofort laden – und zwar in einem kleinen, modernen Format. Oft ist es aber auf „später laden“ gestellt, weil WordPress oder der Page Builder nicht weiß, welches Bild oben steht.';
	public $fix_label = 'Bild vorrangig laden';
	public $fix_does  = 'Plakette sucht auf jeder Seite das erste große Bild im Hauptbereich (bzw. das gemessene Bild der Startseite), nimmt dort „später laden“ heraus, gibt ihm höchste Priorität und meldet es dem Browser schon im Kopf der Seite an. Gibt es moderne Kopien (Prüfpunkt „Moderne Bildformate“), meldet Plakette gleich das AVIF- bzw. WebP-Bild an. Ein anderes Bild wählst du mit der CSS-Klasse plk-lcp.';

	/** @var Plakette_Context|null */
	private $ctx = null;

	/** "Besucher laden: AVIF, 48 KB am Handy …" for the image with this URL. */
	private function delivered( $url ) {
		if ( ! $this->ctx || ! $this->ctx->home_ok() ) {
			return '';
		}
		$att = Plakette_Image_Delivery::attachment_id( $url );
		foreach ( Plakette_Image_Delivery::for_context( $this->ctx ) as $row ) {
			if ( $row['fallback'] === $url || ( $att && $row['attachment'] === $att ) ) {
				return ' ' . sprintf( __( 'Besucher laden: %s.', 'plakette' ), Plakette_Image_Delivery::describe( $row ) );
			}
		}
		return '';
	}

	/** Pass with the format and size the browser gets. */
	private function good( $url, $detail ) {
		return $this->pass( $detail . $this->delivered( $url ) );
	}

	public function run( Plakette_Context $ctx ) {
		$this->ctx = $ctx;
		$m         = $ctx->measured();
		if ( ! empty( $m['lcp'] ) ) {
			if ( empty( $m['lcpImage'] ) ) {
				return $this->pass( sprintf( __( 'Größtes Element ist Text (%s) – kein Bild, das vorgeladen werden müsste.', 'plakette' ), isset( $m['lcpTag'] ) ? strtolower( $m['lcpTag'] ) : 'Text' ) );
			}
			$img = $m['lcpImage'];
			$set = Plakette_Modules::get( 'lcp' );
			if ( ! empty( $set['url'] ) && $set['url'] === $img['url'] && Plakette_Modules::on( 'lcp_auto' ) ) {
				// The measurement is older than the fix – look at the page as it is now.
				$now = $ctx->home_ok() ? Plakette_Performance::find_lcp( $ctx->html(), isset( $set['file'] ) ? $set['file'] : '' ) : null;
				return $this->good( $now ? $now['src'] : $img['url'], sprintf( __( '%s wird vorrangig geladen.', 'plakette' ), wp_basename( $img['url'] ) ) );
			}
			if ( 'lazy' !== $img['loading'] && 'high' === $img['priority'] ) {
				return $this->good( $img['url'], sprintf( __( '%s wird bereits vorrangig geladen.', 'plakette' ), wp_basename( $img['url'] ) ) );
			}
			$why = 'lazy' === $img['loading'] ? __( 'ist auf „später laden“ gestellt', 'plakette' ) : __( 'hat keine hohe Priorität', 'plakette' );
			return $this->fail( sprintf( __( 'Das größte Element ist das Bild %1$s und %2$s.', 'plakette' ), wp_basename( $img['url'] ), $why ) );
		}

		// Not measured yet: look at the HTML – the first big image in the main area is almost always the LCP image.
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$img = Plakette_Performance::find_lcp( $ctx->html() );
		if ( ! $img || '' === $img['src'] ) {
			return $this->pass( __( 'Kein großes Bild im Hauptbereich der Startseite gefunden. Genau weißt du es nach „Ladezeit messen“.', 'plakette' ) );
		}
		$name = wp_basename( (string) wp_parse_url( $img['src'], PHP_URL_PATH ) );
		if ( 'lazy' === $img['loading'] || $img['lazy_class'] ) {
			return $this->fail( sprintf( __( 'Das erste große Bild (%s) ist auf „später laden“ gestellt.', 'plakette' ), $name ) . $this->delivered( $img['src'] ), array( 'notes' => array( __( 'Ermittelt aus dem Seitenaufbau. Genauer wird es mit „Ladezeit messen“.', 'plakette' ) ) ) );
		}
		if ( 'high' !== $img['fetchpriority'] ) {
			return $this->fail( sprintf( __( 'Das erste große Bild (%s) hat keine hohe Priorität.', 'plakette' ), $name ) . $this->delivered( $img['src'] ), array( 'notes' => array( __( 'Ermittelt aus dem Seitenaufbau. Genauer wird es mit „Ladezeit messen“.', 'plakette' ) ) ) );
		}
		return $this->good( $img['src'], sprintf( __( '%s wird vorrangig geladen.', 'plakette' ), $name ) );
	}

	public function fix( array $req, array &$undo ) {
		$m = ( new Plakette_Context() )->measured();
		if ( ! empty( $m['lcpImage']['url'] ) ) {
			// Measured image of the front page: preload in wp_head + attributes through the image filters of WordPress.
			$img  = $m['lcpImage'];
			$path = (string) wp_parse_url( $img['url'], PHP_URL_PATH );
			// Match all size variants of the same upload: /2026/11/hero-1024x683.jpg → /2026/11/hero
			$file = preg_replace( '/(-\d+x\d+|-scaled)?\.\w+$/', '', $path );
			$file = preg_replace( '#^.*?/uploads/#', '/', $file );
			Plakette_Log::set_module(
				'lcp',
				array(
					'url'    => esc_url_raw( $img['url'] ),
					'srcset' => isset( $img['srcset'] ) ? sanitize_text_field( $img['srcset'] ) : '',
					'sizes'  => isset( $img['sizes'] ) ? sanitize_text_field( $img['sizes'] ) : '',
					'file'   => $file,
				),
				$undo
			);
		}
		// Through the HTML pipeline – also works for page builders (Breakdance …) whose images bypass those filters.
		if ( ! Plakette_Modules::on( 'lcp_auto' ) ) {
			Plakette_Log::set_module( 'lcp_auto', true, $undo );
		}
		Plakette_Page_Cache::purge_all();
		return __( 'Das wichtigste Bild wird jetzt vorrangig geladen.', 'plakette' ) . ' ' . __( 'Miss die Ladezeit erneut, um den Unterschied zu sehen.', 'plakette' );
	}
}

class Plakette_Check_Render_Blocking extends Plakette_Speed_Check {
	public $id        = 'speed_render_blocking';
	public $group     = 'Code';
	public $weight    = 5;
	public $title     = 'Skripte blockieren die Anzeige nicht';
	public $problem   = 'Skripte halten die Anzeige der Seite auf';
	public $explain   = 'Skripte im Kopf der Seite müssen erst geladen und ausgeführt werden, bevor der Browser irgendetwas anzeigt. Mit „defer“ lädt der Browser sie nebenbei und führt sie aus, wenn die Seite schon steht.';
	public $fix_label = 'Skripte verzögert laden';
	public $fix_does  = 'Versieht die Skripte deiner Plugins und deines Themes mit „defer“. jQuery selbst und Skripte, von denen eingebetteter Code abhängt, lässt WordPress automatisch wie bisher. Prüfe danach kurz Menü, Slider und Formulare.';
	public $risk      = 'review';

	public function run( Plakette_Context $ctx ) {
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
			return $this->pass( __( 'Keine blockierenden Skripte im Kopf der Seite (außer jQuery).', 'plakette' ) );
		}
		$names = array_map(
			function ( $src ) {
				return wp_basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
			},
			$blocking
		);
		$extra = array();
		if ( Plakette_Modules::on( 'defer_js' ) ) {
			return $this->warn( sprintf( __( 'Bleiben blockierend, weil eingebetteter Code sie sofort braucht: %s', 'plakette' ), implode( ', ', $names ) ), array( 'kind' => 'none' ) );
		}
		if ( Plakette_Env::optimize_plugin() ) {
			$extra['blocked'] = sprintf( __( '%s ist aktiv und sollte das übernehmen. Prüfe dort die Einstellung für JavaScript.', 'plakette' ), Plakette_Env::optimize_plugin() );
		}
		return $this->fail( sprintf( _n( '%1$d blockierendes Skript: %2$s', '%1$d blockierende Skripte: %2$s', count( $names ), 'plakette' ), count( $names ), implode( ', ', $names ) ), $extra );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'defer_js', true, $undo );
		Plakette_Page_Cache::purge_all();
		return __( 'Skripte werden jetzt verzögert geladen. Prüfe kurz Menü, Slider und Formulare deiner Seite.', 'plakette' );
	}
}

/** "Something unnecessary is loaded on every page" → switch a module on. */
abstract class Plakette_Check_Bloat extends Plakette_Speed_Check {
	public $group     = 'Code';
	protected $module = '';
	protected $fact   = '';

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		if ( empty( $ctx->facts()[ $this->fact ] ) ) {
			return $this->pass( __( 'Wird nicht geladen.', 'plakette' ) );
		}
		return $this->fail( __( 'Wird auf jeder Seite geladen.', 'plakette' ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( $this->module, true, $undo );
		Plakette_Page_Cache::purge_all();
		return __( 'Erledigt.', 'plakette' );
	}
}

class Plakette_Check_Emoji extends Plakette_Check_Bloat {
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

class Plakette_Check_Jquery_Migrate extends Plakette_Check_Bloat {
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

class Plakette_Check_Dashicons extends Plakette_Check_Bloat {
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

class Plakette_Check_Google_Fonts extends Plakette_Speed_Check {
	public $id        = 'privacy_google_fonts';
	public $area      = 'legal';
	public $group     = 'Fremde Server';
	public $weight    = 5;
	public $title     = 'Schriften kommen vom eigenen Server';
	public $problem   = 'Schriften werden bei Google geladen';
	public $explain   = 'Deine Website lädt Schriften von Google-Servern. Das kostet eine zusätzliche Verbindung zu einem fremden Server und überträgt dabei die IP-Adresse deiner Besucher an Google – laut Landgericht München (2022) ein Datenschutzverstoß.';
	public $fix_label = 'Schriften lokal speichern';
	public $fix_does  = 'Plakette lädt die Schriften einmal herunter (nur westeuropäische Zeichen, damit es klein bleibt), legt sie in deinen Upload-Ordner und bindet sie von dort ein – auch die Schriften aus Breakdance und Oxygen. Neue Schriften übernimmt Plakette automatisch. Das Aussehen deiner Seite bleibt gleich.';

	private static function fonts( Plakette_Context $ctx ) {
		$fonts = $ctx->home_ok() ? (array) $ctx->facts()['google_fonts'] : array();
		foreach ( $ctx->samples() as $facts ) {
			$fonts = array_merge( $fonts, isset( $facts['google_fonts'] ) ? (array) $facts['google_fonts'] : array() );
		}
		return array_values( array_unique( $fonts ) );
	}

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$fonts = self::fonts( $ctx );
		if ( ! $fonts ) {
			$sets = Plakette_Local_Fonts::data()['fonts'];
			if ( Plakette_Modules::on( 'local_fonts' ) && $sets ) {
				$families = array();
				foreach ( $sets as $set ) {
					$families = array_merge( $families, (array) $set['families'] );
				}
				return $this->pass( sprintf( __( 'Schriften kommen von deinem Server: %s.', 'plakette' ), implode( ', ', array_unique( $families ) ) ) );
			}
			return $this->pass( __( 'Keine Schriften von Google gefunden.', 'plakette' ) );
		}
		$detail = sprintf( _n( '%d Schrift-Datei von fonts.googleapis.com', '%d Schrift-Dateien von fonts.googleapis.com', count( $fonts ), 'plakette' ), count( $fonts ) );
		if ( ! Plakette_Modules::on( 'local_fonts' ) ) {
			return $this->fail( $detail, array( 'fonts' => $fonts ) );
		}
		// Module is on, but Google is still used: download failed – or the theme writes the link directly.
		$data   = Plakette_Local_Fonts::data();
		$failed = 0;
		foreach ( $fonts as $url ) {
			if ( ! empty( $data['fail'][ Plakette_Local_Fonts::key( $url ) ] ) ) {
				++$failed;
			}
		}
		if ( $failed ) {
			return $this->warn(
				$detail . '. ' . __( 'Lokales Speichern ist eingeschaltet, aber der Download von Google hat noch nicht geklappt. Plakette versucht es stündlich erneut – bis dahin kommen die Schriften weiter von Google.', 'plakette' ),
				array( 'fonts' => $fonts, 'fix_label' => __( 'Jetzt erneut versuchen', 'plakette' ) )
			);
		}
		return $this->warn(
			$detail . '. ' . __( 'Lokales Speichern ist eingeschaltet, aber diese Schriften bindet dein Theme oder ein Plugin direkt ein, an WordPress vorbei. Frag den Anbieter nach einer Option „Google Fonts lokal laden“ oder „Google Fonts abschalten“.', 'plakette' ),
			array( 'fonts' => $fonts, 'kind' => 'none' )
		);
	}

	public function fix( array $req, array &$undo ) {
		$ctx   = new Plakette_Context();
		$fonts = $ctx->home_ok() ? self::fonts( $ctx ) : array();
		$had_copies = (bool) Plakette_Local_Fonts::data()['fonts'];
		if ( ! Plakette_Modules::on( 'local_fonts' ) ) {
			Plakette_Log::set_module( 'local_fonts', true, $undo );
		}
		if ( ! $had_copies ) {
			// Undo removes the local copies again (replayed before the module is switched off).
			$undo[] = array( 'callback', 'Plakette_Local_Fonts::clear_all' );
		}
		// Download now what the pages use, so the next audit already sees the local files.
		$errors = array();
		foreach ( $fonts as $css ) {
			$local = Plakette_Local_Fonts::fetch( $css, true );
			if ( is_wp_error( $local ) ) {
				$errors[] = $local->get_error_message();
			}
		}
		Plakette_Page_Cache::purge_all();
		if ( $errors ) {
			return __( 'Eingeschaltet. Der Download von Google hat noch nicht geklappt – Plakette versucht es automatisch erneut, bis dahin bleibt alles wie bisher.', 'plakette' ) . ' (' . implode( ' ', array_unique( $errors ) ) . ')';
		}
		$check = new Plakette_Context();
		if ( $check->home_ok() && self::fonts( $check ) ) {
			return __( 'Schriften lokal gespeichert. Ein Teil wird aber direkt vom Theme eingebunden und kommt weiter von Google – frag den Theme-Anbieter nach einer Option „Google Fonts lokal laden“.', 'plakette' );
		}
		return __( 'Die Schriften kommen jetzt von deinem eigenen Server.', 'plakette' );
	}
}

class Plakette_Check_Database extends Plakette_Speed_Check {
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

	public function run( Plakette_Context $ctx ) {
		$c     = self::counts();
		$junk  = $c['transients'] + $c['comments'] + $c['drafts'] + max( 0, $c['revisions'] - 50 );
		$parts = sprintf(
			__( '%1$d alte Versionen, %2$d Spam/Papierkorb-Kommentare, %3$d abgelaufene Zwischenspeicher, %4$d automatische Entwürfe. Automatisch geladene Einstellungen: %5$s.', 'plakette' ),
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
		if ( ! Plakette_Modules::get( 'revisions_limit' ) ) {
			Plakette_Modules::set( 'revisions_limit', 10 );
		}
		$after = self::counts();
		return sprintf(
			__( 'Aufgeräumt: %1$d Versionen, %2$d Kommentare, %3$d Zwischenspeicher, %4$d Entwürfe.', 'plakette' ),
			$before['revisions'] - $after['revisions'],
			$before['comments'] - $after['comments'],
			$before['transients'] - $after['transients'],
			$before['drafts'] - $after['drafts']
		);
	}
}

class Plakette_Check_Php_Version extends Plakette_Speed_Check {
	public $id        = 'security_php';
	public $area      = 'security';
	public $group     = 'Schutz vor Angriffen';
	public $weight    = 5;
	public $title     = 'Aktuelle PHP-Version';
	public $problem   = 'Veraltete PHP-Version';
	public $explain   = 'PHP ist die Programmiersprache, in der WordPress läuft. Alte Versionen bekommen keine Sicherheitsupdates mehr – bekannte Lücken bleiben dann offen. Neuere Versionen sind außerdem deutlich schneller. Umstellen kann nur dein Hoster – oft reicht ein Klick in dessen Kundenmenü.';
	public $fix_label = 'Nachricht an den Hoster';
	public $fix_does  = 'Plakette hat eine fertige Nachricht vorbereitet. Kopiere sie und schicke sie an deinen Hoster. Viele Hoster bieten die Umstellung auch selbst im Kundenmenü an (Stichwort „PHP-Version“).';
	public $kind      = 'letter';

	public function run( Plakette_Context $ctx ) {
		$letter = $this->letter( __( 'Bitte stellen Sie die PHP-Version meiner Website auf PHP 8.3 oder neuer um. Die aktuelle Version erhält keine Sicherheitsupdates mehr bzw. nur noch für kurze Zeit.', 'plakette' ) );
		if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
			return $this->fail( sprintf( __( 'Deine Website läuft mit PHP %s. Diese Version bekommt keine Sicherheitsupdates mehr.', 'plakette' ), PHP_VERSION ), array( 'letter' => $letter ) );
		}
		if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
			return $this->warn( sprintf( __( 'PHP %s bekommt nur noch bis Ende 2026 Sicherheitsupdates.', 'plakette' ), PHP_VERSION ), array( 'letter' => $letter ) );
		}
		return $this->pass( sprintf( __( 'PHP %s', 'plakette' ), PHP_VERSION ) );
	}
}

class Plakette_Check_Object_Cache extends Plakette_Speed_Check {
	public $id        = 'speed_object_cache';
	public $group     = 'Server';
	public $weight    = 2;
	public $title     = 'Datenbank-Zwischenspeicher (Objekt-Cache)';
	public $problem   = 'Kein dauerhafter Objekt-Cache';
	public $explain   = 'Ein Objekt-Cache (Redis oder Memcached) merkt sich Ergebnisse von Datenbank-Abfragen. Das hilft vor allem bei Shops, Mitgliederbereichen und großen Websites, also überall, wo der Seiten-Cache nicht greift.';
	public $fix_label = 'Nachricht an den Hoster';
	public $fix_does  = 'Plakette hat eine fertige Nachricht vorbereitet, in der du deinen Hoster um Redis bittest. Viele Hoster bieten es kostenlos an.';
	public $kind      = 'letter';

	public function run( Plakette_Context $ctx ) {
		if ( wp_using_ext_object_cache() ) {
			return $this->pass( __( 'Ein Objekt-Cache ist aktiv.', 'plakette' ) );
		}
		$count = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'page' )->publish;
		if ( $count < 50 && ! class_exists( 'WooCommerce' ) ) {
			return $this->pass( __( 'Für eine Website dieser Größe nicht nötig.', 'plakette' ) );
		}
		return $this->warn(
			__( 'WordPress fragt bei jedem Aufruf die Datenbank neu ab.', 'plakette' ),
			array( 'letter' => $this->letter( __( 'Bitte richten Sie für meine Website einen Redis- oder Memcached-Objekt-Cache ein und teilen Sie mir die Verbindungsdaten mit (oder aktivieren Sie ihn direkt für WordPress).', 'plakette' ) ) )
		);
	}
}

add_filter(
	'plakette_checks',
	function ( $classes ) {
		return array_merge(
			$classes,
			array(
				'Plakette_Check_Page_Cache',
				'Plakette_Check_Compression',
				'Plakette_Check_Browser_Cache',
				'Plakette_Check_Php_Version',
				'Plakette_Check_Database',
				'Plakette_Check_Object_Cache',
				'Plakette_Check_Large_Images',
				'Plakette_Check_Lcp_Image',
				'Plakette_Check_Webp',
				'Plakette_Check_Render_Blocking',
				'Plakette_Check_Google_Fonts',
				'Plakette_Check_Emoji',
				'Plakette_Check_Jquery_Migrate',
				'Plakette_Check_Dashicons',
			)
		);
	},
	30
);
