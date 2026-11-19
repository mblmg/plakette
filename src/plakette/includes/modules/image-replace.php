<?php
/**
 * Replaces a heavy library image by a WebP version of itself – for images that cannot get smaller in their own
 * format (a PNG photo with transparent parts stays big as PNG, as WebP it is a tenth of it).
 *
 * The attachment keeps its id; its file, sizes and MIME type change, and every address of the old files in
 * post content and post meta (page builder data: Beaver Builder, Elementor, Breakdance JSON …) is rewritten.
 * The old files stay on disk, so an address Plakette did not find keeps working. Everything is undoable.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Image_Replace {

	/** Max. rows rewritten per image (post content + post meta). */
	const MAX_ROWS = 400;

	/** Can this server write WebP? */
	public static function supported() {
		return wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	}

	/**
	 * @return array|WP_Error [ before bytes, after bytes, rewritten places ]
	 */
	public static function to_webp( $id, $max_side, array &$undo ) {
		$id   = (int) $id;
		$file = get_attached_file( $id );
		$mime = (string) get_post_mime_type( $id );
		if ( ! $file || ! is_file( $file ) || ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) ) {
			return new WP_Error( 'plakette_webp', __( 'Datei nicht gefunden.', 'plakette' ) );
		}
		if ( ! self::supported() ) {
			return new WP_Error( 'plakette_webp', __( 'Der Server kann keine WebP-Bilder schreiben.', 'plakette' ) );
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$size = $editor->get_size();
		if ( max( $size['width'], $size['height'] ) > $max_side ) {
			$editor->resize( $max_side, $max_side, false );
		}
		$editor->set_quality( 82 );
		$dir    = dirname( $file );
		$target = $dir . '/' . wp_unique_filename( $dir, pathinfo( $file, PATHINFO_FILENAME ) . '.webp' );
		$saved  = $editor->save( $target, 'image/webp' );
		if ( is_wp_error( $saved ) || ! is_file( $target ) ) {
			return is_wp_error( $saved ) ? $saved : new WP_Error( 'plakette_webp', __( 'WebP-Datei konnte nicht gespeichert werden.', 'plakette' ) );
		}
		clearstatcache();
		$before = (int) filesize( $file );
		$after  = (int) filesize( $target );
		if ( $after > $before * 0.8 ) {
			wp_delete_file( $target );
			return new WP_Error( 'plakette_webp', __( 'Auch als WebP nicht deutlich kleiner.', 'plakette' ) );
		}

		// Old addresses: the file itself, its sizes and the original before WordPress scaled it.
		$old_meta = wp_get_attachment_metadata( $id );
		$old_rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
		$sub      = dirname( $old_rel );
		$sub      = '.' === $sub ? '' : $sub . '/';
		$old      = array( 'full' => $old_rel );
		foreach ( (array) ( $old_meta['sizes'] ?? array() ) as $name => $s ) {
			if ( ! empty( $s['file'] ) ) {
				$old[ $name ] = $sub . $s['file'];
			}
		}
		if ( ! empty( $old_meta['original_image'] ) ) {
			$old['original'] = $sub . $old_meta['original_image'];
		}

		// Switch the attachment over to the new file (undo: the old meta, file name and type come back).
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$undo[]  = array( 'attachment_meta', $id, $old_meta );
		$new_rel = $sub . wp_basename( $target );
		Plakette_Log::set_postmeta( $id, '_wp_attached_file', $new_rel, $undo );
		$res = Plakette_Log::set_post_field( $id, 'post_mime_type', 'image/webp', $undo );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$new_meta = wp_generate_attachment_metadata( $id, $target );
		wp_update_attachment_metadata( $id, $new_meta );
		$created = array( $target );
		foreach ( (array) ( $new_meta['sizes'] ?? array() ) as $s ) {
			if ( ! empty( $s['file'] ) ) {
				$created[] = $dir . '/' . $s['file'];
			}
		}
		foreach ( array_unique( $created ) as $f ) {
			$undo[] = array( 'file_delete', $f );
		}
		$new = array( 'full' => $new_rel );
		foreach ( (array) ( $new_meta['sizes'] ?? array() ) as $name => $s ) {
			if ( ! empty( $s['file'] ) ) {
				$new[ $name ] = $sub . $s['file'];
			}
		}
		$map = array();
		foreach ( $old as $name => $rel ) {
			$map[ $rel ] = $new[ $name ] ?? $new_rel;
		}
		$places   = self::rewrite( $map, $undo );
		$undo[]   = array( 'callback', 'Plakette_Image_Replace::flush_builders' );
		self::flush_builders();
		return array( $before, $after, $places );
	}

	/**
	 * Rewrites upload addresses (relative to the upload folder) in post content and post meta.
	 *
	 * @param array $map old relative path => new relative path
	 * @return int rows changed
	 */
	public static function rewrite( array $map, array &$undo ) {
		global $wpdb;
		$path = (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH );
		$path = trailingslashit( '' !== $path ? $path : '/wp-content/uploads' );
		$from = array();
		foreach ( $map as $old => $new ) {
			if ( $old === $new || '' === $old ) {
				continue;
			}
			// Plain and JSON-escaped (Breakdance, Elementor) – not inside longer names like bild.png.webp.
			$from[ '#' . preg_quote( $path . $old, '#' ) . '(?![\w.\-])#' ]                                       = $path . $new;
			$from[ '#' . preg_quote( str_replace( '/', '\/', $path . $old ), '#' ) . '(?![\w.\-])#' ] = str_replace( '/', '\/', $path . $new );
		}
		if ( ! $from ) {
			return 0;
		}
		$apply = function ( $text ) use ( $from ) {
			$out = preg_replace( array_keys( $from ), array_values( $from ), $text );
			return null === $out ? $text : $out;
		};
		$like  = '%' . $wpdb->esc_like( pathinfo( (string) array_key_first( $map ), PATHINFO_FILENAME ) ) . '%';
		$count = 0;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision') AND post_content LIKE %s LIMIT %d", $like, self::MAX_ROWS ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$new = $apply( $row->post_content );
			if ( $new !== $row->post_content ) {
				$undo[] = array( 'post_raw', (int) $row->ID, $row->post_content );
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => (int) $row->ID ) ); // phpcs:ignore WordPress.DB
				clean_post_cache( (int) $row->ID );
				++$count;
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_id, pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type NOT IN ('attachment','revision') AND pm.meta_value LIKE %s LIMIT %d", $like, self::MAX_ROWS ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$value = (string) $row->meta_value;
			$new   = is_serialized( $value ) ? self::serialized_replace( $value, $apply ) : $apply( $value );
			if ( null !== $new && $new !== $value ) {
				$undo[] = array( 'postmeta_raw', (int) $row->meta_id, (int) $row->post_id, $value );
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $new ), array( 'meta_id' => (int) $row->meta_id ) ); // phpcs:ignore WordPress.DB
				wp_cache_delete( (int) $row->post_id, 'post_meta' );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Applies $fn to every string inside serialized PHP data and fixes the string lengths. Objects of classes that
	 * are not loaded stay intact (no unserialize). @return string|null null = could not be read safely
	 */
	public static function serialized_replace( $data, callable $fn ) {
		$i = 0;
		try {
			$out = self::ser_walk( $data, $i, $fn );
		} catch ( Exception $e ) {
			return null;
		}
		if ( strlen( $data ) !== $i ) {
			return null;
		}
		// Must still be valid serialized data (no classes are created).
		return 'b:0;' === $out || false !== @unserialize( $out, array( 'allowed_classes' => false ) ) ? $out : null; // phpcs:ignore
	}

	private static function ser_walk( $s, &$i, callable $fn ) {
		$t = $s[ $i ] ?? '';
		switch ( $t ) {
			case 'N':
				$i += 2;
				return 'N;';
			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $i );
				if ( false === $end ) {
					throw new Exception( 'end' );
				}
				$tok = substr( $s, $i, $end - $i + 1 );
				$i   = $end + 1;
				return $tok;
			case 's':
			case 'E':
				$colon = strpos( $s, ':', $i + 2 );
				$len   = (int) substr( $s, $i + 2, $colon - $i - 2 );
				if ( false === $colon || '"' !== ( $s[ $colon + 1 ] ?? '' ) || substr( $s, $colon + 2 + $len, 2 ) !== '";' ) {
					throw new Exception( 'string' );
				}
				$str = substr( $s, $colon + 2, $len );
				$i   = $colon + 2 + $len + 2;
				if ( 'E' === $t ) {
					return 'E:' . $len . ':"' . $str . '";';
				}
				$new = is_serialized( $str ) ? self::serialized_replace( $str, $fn ) : $fn( $str );
				$new = null === $new ? $str : $new;
				return 's:' . strlen( $new ) . ':"' . $new . '";';
			case 'a':
			case 'O':
				$head = '';
				if ( 'O' === $t ) {
					$colon = strpos( $s, ':', $i + 2 );
					$len   = (int) substr( $s, $i + 2, $colon - $i - 2 );
					$head  = substr( $s, $i, $colon - $i + 2 + $len + 1 ); // O:5:"Class"
					$i     = $colon + 2 + $len + 1;
					$count_start = $i + 1;
				} else {
					$head        = 'a';
					$count_start = $i + 2;
				}
				$brace = strpos( $s, ':{', $count_start );
				if ( false === $brace ) {
					throw new Exception( 'array' );
				}
				$n   = (int) substr( $s, $count_start, $brace - $count_start );
				$out = $head . ':' . $n . ':{';
				$i   = $brace + 2;
				for ( $k = 0; $k < 2 * $n; $k++ ) {
					$out .= self::ser_walk( $s, $i, $fn );
				}
				if ( '}' !== ( $s[ $i ] ?? '' ) ) {
					throw new Exception( 'close' );
				}
				++$i;
				return $out . '}';
		}
		throw new Exception( 'type' );
	}

	/** Page builders keep generated CSS with image addresses (Beaver Builder backgrounds …): rebuild it. */
	public static function flush_builders() {
		if ( class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'delete_asset_cache_for_all_posts' ) ) {
			FLBuilderModel::delete_asset_cache_for_all_posts();
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		Plakette_Page_Cache::purge_all();
	}
}
