<?php
/**
 * Change log with undo.
 *
 * Every fix records a list of "undo operations". Undoing a run replays them in reverse order.
 * Supported operations (all plain arrays, stored as JSON):
 *   ['option', name, existed, old_value]
 *   ['module', key, old_value|null]
 *   ['postmeta', post_id, meta_key, existed, old_value]
 *   ['post_field', post_id, field, old_value]
 *   ['untrash', post_id]
 *   ['untrash_comment', comment_id]
 *   ['markers', file, marker]            – remove an insert_with_markers() block
 *   ['wpconfig_define', constant]        – remove a define() line Plakette added
 *   ['file_restore', target, backup]     – copy backup over target, delete backup
 *   ['file_delete', path]
 *   ['attachment_meta', id, old_meta]
 *   ['delete_attachment', id]
 *   ['callback', 'Class::method']        – static cleanup routine of this plugin
 *   ['site_option', name, existed, old_value]
 *   ['comment_field', comment_id, field, old_value]
 *   ['user_login', user_id, old_login, old_nicename]
 *   ['delete_post', post_id]              – remove a page/menu item Plakette created
 *   ['file_replace', path, [[new, old], …], backup] – put old text back; whole backup only if the new text is gone
 *   ['activate_plugin', basename]         – re-activate a plugin Plakette deactivated
 *   ['deactivate_plugin', basename]
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Log {

	const DB_VERSION = '2';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'plakette_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id varchar(40) NOT NULL,
			check_id varchar(60) NOT NULL,
			label text NOT NULL,
			undo_ops longtext NOT NULL,
			created datetime NOT NULL,
			undone tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY run_id (run_id)
		) {$charset};" );
		// Only remember the version when the table really exists – otherwise try again next time.
		if ( self::table_exists() ) {
			update_option( 'plakette_db_version', self::DB_VERSION, false );
		}
	}

	public static function table_exists() {
		global $wpdb;
		$table = self::table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'plakette_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/** @return bool Whether the entry was stored. */
	public static function add( $run_id, $check_id, $label, array $undo ) {
		global $wpdb;
		return (bool) $wpdb->insert(
			self::table(),
			array(
				'run_id'   => substr( sanitize_key( $run_id ), 0, 40 ),
				'check_id' => $check_id,
				'label'    => $label,
				'undo_ops' => wp_json_encode( $undo ),
				'created'  => current_time( 'mysql' ),
			)
		);
	}

	/** Runs grouped for the UI, newest first. */
	public static function runs( $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit * 10 ), ARRAY_A );
		$runs  = array();
		foreach ( (array) $rows as $row ) {
			$key = $row['run_id'];
			if ( ! isset( $runs[ $key ] ) ) {
				if ( count( $runs ) >= $limit ) {
					break;
				}
				$runs[ $key ] = array(
					'run_id'    => $key,
					'check_id'  => $row['check_id'],
					'labels'    => array(),
					'created'   => $row['created'],
					'undone'    => (bool) $row['undone'],
					'undoable'  => false,
				);
			}
			array_unshift( $runs[ $key ]['labels'], $row['label'] );
			$ops = json_decode( $row['undo_ops'], true );
			if ( ! empty( $ops ) ) {
				$runs[ $key ]['undoable'] = true;
			}
			$runs[ $key ]['created'] = $row['created'];
		}
		foreach ( $runs as &$run ) {
			$run['labels'] = array_values( array_unique( $run['labels'] ) );
		}
		return array_values( $runs );
	}

	public static function undo_run( $run_id ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s AND undone = 0 ORDER BY id DESC", $run_id ), ARRAY_A );
		if ( empty( $rows ) ) {
			return new WP_Error( 'plakette_nothing', __( 'Diese Änderung wurde schon rückgängig gemacht.', 'plakette' ) );
		}
		$errors = array();
		foreach ( $rows as $row ) {
			$errors = array_merge( $errors, self::apply_ops( (array) json_decode( $row['undo_ops'], true ) ) );
			$wpdb->update( $table, array( 'undone' => 1 ), array( 'id' => $row['id'] ) );
		}
		Plakette_Page_Cache::purge_all();
		return $errors ? new WP_Error( 'plakette_partial', implode( ' ', $errors ) ) : true;
	}

	/** Replays undo operations in reverse order. @return string[] error messages */
	public static function apply_ops( array $ops ) {
		$errors = array();
		foreach ( array_reverse( $ops ) as $op ) {
			$result = self::apply_op( $op );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			}
		}
		return $errors;
	}

	private static function apply_op( $op ) {
		if ( ! is_array( $op ) || empty( $op[0] ) ) {
			return true;
		}
		switch ( $op[0] ) {
			case 'option':
				if ( $op[2] ) {
					update_option( $op[1], $op[3] );
				} else {
					delete_option( $op[1] );
				}
				if ( 'permalink_structure' === $op[1] ) {
					flush_rewrite_rules( true );
				}
				return true;

			case 'module':
				Plakette_Modules::set( $op[1], $op[2] );
				return true;

			case 'postmeta':
				if ( $op[3] ) {
					update_post_meta( $op[1], $op[2], $op[4] );
				} else {
					delete_post_meta( $op[1], $op[2] );
				}
				return true;

			case 'post_field':
				$res = wp_update_post( wp_slash( array( 'ID' => (int) $op[1], $op[2] => $op[3] ) ), true );
				return is_wp_error( $res ) ? $res : true;

			case 'untrash':
				wp_untrash_post( (int) $op[1] );
				// wp_untrash_post() restores to draft since WP 5.6 – restore the previous status explicitly.
				$prev = get_post_meta( (int) $op[1], '_plakette_prev_status', true );
				if ( $prev ) {
					wp_update_post( array( 'ID' => (int) $op[1], 'post_status' => $prev ) );
					delete_post_meta( (int) $op[1], '_plakette_prev_status' );
				}
				return true;

			case 'untrash_comment':
				wp_untrash_comment( (int) $op[1] );
				return true;

			case 'markers':
				if ( ! function_exists( 'insert_with_markers' ) ) {
					require_once ABSPATH . 'wp-admin/includes/misc.php';
				}
				if ( file_exists( $op[1] ) ) {
					insert_with_markers( $op[1], $op[2], array() );
				}
				return true;

			case 'wpconfig_define':
				return Plakette_Env::remove_wpconfig_define( $op[1] );

			case 'file_restore':
				if ( file_exists( $op[2] ) ) {
					if ( ! @copy( $op[2], $op[1] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
						return new WP_Error( 'plakette_restore', sprintf( __( 'Datei konnte nicht wiederhergestellt werden: %s', 'plakette' ), basename( $op[1] ) ) );
					}
					wp_delete_file( $op[2] );
				}
				return true;

			case 'file_replace':
				$src = file_exists( $op[1] ) ? (string) file_get_contents( $op[1] ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
				$out = $src;
				foreach ( (array) $op[2] as $pair ) {
					if ( '' === $pair[0] || false === strpos( $out, $pair[0] ) ) {
						return new WP_Error( 'plakette_restore', sprintf( __( '%s wurde inzwischen anders geändert. Die Sicherung liegt in wp-content/plakette-backup.', 'plakette' ), basename( $op[1] ) ) );
					}
					$out = str_replace( $pair[0], $pair[1], $out );
				}
				if ( false === file_put_contents( $op[1], $out, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					return new WP_Error( 'plakette_restore', sprintf( __( 'Datei konnte nicht wiederhergestellt werden: %s', 'plakette' ), basename( $op[1] ) ) );
				}
				if ( ! empty( $op[3] ) && file_exists( $op[3] ) ) {
					wp_delete_file( $op[3] );
				}
				return true;

			case 'file_delete':
				if ( is_dir( $op[1] ) ) {
					self::rrmdir( $op[1] );
				} elseif ( file_exists( $op[1] ) ) {
					wp_delete_file( $op[1] );
				}
				return true;

			case 'attachment_meta':
				wp_update_attachment_metadata( (int) $op[1], $op[2] );
				return true;

			case 'delete_attachment':
				wp_delete_attachment( (int) $op[1], true );
				return true;

			case 'site_option':
				if ( $op[2] ) {
					update_site_option( $op[1], $op[3] );
				} else {
					delete_site_option( $op[1] );
				}
				return true;

			case 'comment_field':
				global $wpdb;
				if ( in_array( $op[2], array( 'comment_author_IP', 'comment_author_email', 'comment_agent', 'comment_author_url' ), true ) ) {
					$wpdb->update( $wpdb->comments, array( $op[2] => (string) $op[3] ), array( 'comment_ID' => (int) $op[1] ) ); // phpcs:ignore WordPress.DB
					clean_comment_cache( (int) $op[1] );
				}
				return true;

			case 'user_login':
				global $wpdb;
				$wpdb->update( $wpdb->users, array( 'user_login' => (string) $op[2], 'user_nicename' => (string) $op[3] ), array( 'ID' => (int) $op[1] ) ); // phpcs:ignore WordPress.DB
				clean_user_cache( (int) $op[1] );
				return true;

			case 'delete_post':
				if ( get_post( (int) $op[1] ) ) {
					wp_delete_post( (int) $op[1], true );
				}
				return true;

			case 'activate_plugin':
				if ( ! function_exists( 'activate_plugin' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$res = activate_plugin( (string) $op[1], '', false, true );
				return is_wp_error( $res ) ? $res : true;

			case 'deactivate_plugin':
				if ( ! function_exists( 'deactivate_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				deactivate_plugins( (string) $op[1], true );
				return true;

			case 'callback':
				if ( is_string( $op[1] ) && 0 === strpos( $op[1], 'Plakette_' ) && is_callable( $op[1] ) ) {
					call_user_func( $op[1] );
				}
				return true;
		}
		return true;
	}

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Helpers to build undo ops while changing things. */
	public static function set_option( $name, $value, array &$undo, $autoload = null ) {
		$sentinel = new stdClass();
		$old      = get_option( $name, $sentinel );
		$existed  = $old !== $sentinel;
		$undo[]   = array( 'option', $name, $existed, $existed ? $old : null );
		update_option( $name, $value, $autoload );
	}

	public static function set_postmeta( $post_id, $key, $value, array &$undo ) {
		$existed = metadata_exists( 'post', $post_id, $key );
		$undo[]  = array( 'postmeta', (int) $post_id, $key, $existed, $existed ? get_post_meta( $post_id, $key, true ) : null );
		update_post_meta( $post_id, $key, $value );
	}

	public static function set_site_option( $name, $value, array &$undo ) {
		$sentinel = new stdClass();
		$old      = get_site_option( $name, $sentinel );
		$existed  = $old !== $sentinel;
		$undo[]   = array( 'site_option', $name, $existed, $existed ? $old : null );
		update_site_option( $name, $value );
	}

	/** Creates a page (or any post) and records how to remove it again. @return int|WP_Error */
	public static function insert_post( array $postarr, array &$undo ) {
		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( ! is_wp_error( $id ) ) {
			$undo[] = array( 'delete_post', (int) $id );
		}
		return $id;
	}

	/** Changes one field of a post and records the old value. @return true|WP_Error */
	public static function set_post_field( $post_id, $field, $value, array &$undo ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'plakette_post', __( 'Beitrag nicht gefunden.', 'plakette' ) );
		}
		$undo[] = array( 'post_field', (int) $post_id, $field, $post->$field );
		$res    = wp_update_post( wp_slash( array( 'ID' => (int) $post_id, $field => $value ) ), true );
		return is_wp_error( $res ) ? $res : true;
	}

	public static function set_module( $key, $value, array &$undo ) {
		$undo[] = array( 'module', $key, Plakette_Modules::get( $key ) );
		Plakette_Modules::set( $key, $value );
	}
}
