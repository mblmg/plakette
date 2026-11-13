<?php
/**
 * Security checks ("Sicherheit › Schutz vor Angriffen").
 * Runtime parts live in includes/modules/security.php (class Plakette_Security).
 */

defined( 'ABSPATH' ) || exit;

abstract class Plakette_Security_Check extends Plakette_Check {
	public $area  = 'security';
	public $group = 'Schutz vor Angriffen';

	/** Loads the admin include files the update/delete functions need (REST requests do not load them). */
	protected static function admin_includes() {
		foreach ( array( 'file', 'misc', 'plugin', 'theme', 'update', 'class-wp-upgrader' ) as $file ) {
			require_once ABSPATH . 'wp-admin/includes/' . $file . '.php';
		}
	}

	/** Can WordPress write its own files directly (no FTP credentials needed)? */
	protected static function can_write_files( $context = '' ) {
		self::admin_includes();
		return 'direct' === get_filesystem_method( array(), $context ? $context : WP_CONTENT_DIR, true );
	}

	protected static function updates_link() {
		return array( array( 'label' => __( 'Dashboard › Aktualisierungen öffnen', 'plakette' ), 'url' => self_admin_url( 'update-core.php' ) ) );
	}

	/** Comma list with at most $max entries. */
	protected static function short_list( array $names, $max = 6 ) {
		$names = array_values( array_unique( $names ) );
		$more  = count( $names ) - $max;
		$text  = implode( ', ', array_slice( $names, 0, $max ) );
		return $more > 0 ? sprintf( __( '%1$s und %2$d weitere', 'plakette' ), $text, $more ) : $text;
	}
}

/* ====================================================================== registration */

class Plakette_Check_Sec_Registration extends Plakette_Security_Check {
	public $id        = 'security_registration';
	public $weight    = 9;
	public $title     = 'Registrierung ist sicher eingestellt';
	public $problem   = 'Jeder kann sich registrieren';
	public $explain   = 'In den WordPress-Einstellungen kann man erlauben, dass sich Besucher selbst ein Benutzerkonto anlegen. Bekommen neue Konten automatisch eine Rolle mit Schreibrechten (z. B. Redakteur), kann jeder Fremde Inhalte auf deiner Website ändern. Und ohne Shop oder Mitgliederbereich braucht kaum eine Website offene Registrierung – sie lockt nur Spam-Konten an.';
	public $fix_label = 'Registrierung absichern';
	public $fix_does  = 'Neue Konten bekommen nur noch die Rolle „Abonnent“ (darf nichts ändern). Ohne Shop- oder Mitglieder-Plugin wird die freie Registrierung außerdem abgeschaltet.';
	public $risk      = 'review';

	/** Does the role allow more than reading? */
	public static function role_is_dangerous( $role ) {
		$obj = get_role( $role );
		if ( ! $obj ) {
			return 'subscriber' !== $role;
		}
		$caps = array( 'edit_posts', 'edit_pages', 'upload_files', 'publish_posts', 'moderate_comments', 'manage_options', 'edit_users', 'create_users', 'promote_users', 'list_users', 'edit_theme_options', 'activate_plugins', 'install_plugins', 'unfiltered_html', 'delete_posts', 'edit_others_posts' );
		foreach ( $caps as $cap ) {
			if ( $obj->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	private static function role_name( $role ) {
		$names = wp_roles()->get_names();
		return isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role;
	}

	public function run( Plakette_Context $ctx ) {
		if ( is_multisite() ) {
			return $this->skip( __( 'In einem Multisite-Netzwerk wird die Registrierung in der Netzwerkverwaltung eingestellt.', 'plakette' ) );
		}
		if ( ! get_option( 'users_can_register' ) ) {
			return $this->pass( __( 'Nur du legst neue Benutzerkonten an.', 'plakette' ) );
		}
		$role       = (string) get_option( 'default_role', 'subscriber' );
		$membership = Plakette_Security::membership_plugin();
		if ( self::role_is_dangerous( $role ) ) {
			return $this->fail(
				sprintf( __( 'Jeder Besucher kann sich ein Konto mit der Rolle „%s“ anlegen und damit Inhalte ändern.', 'plakette' ), self::role_name( $role ) ),
				array(
					'fix_does' => $membership
						? sprintf( __( 'Neue Konten bekommen nur noch die Rolle „Abonnent“. Die Registrierung bleibt offen, weil %s sie braucht.', 'plakette' ), $membership )
						: __( 'Neue Konten bekommen nur noch die Rolle „Abonnent“, und die freie Registrierung wird abgeschaltet (es gibt keinen Shop und keinen Mitgliederbereich, der sie braucht). Neue Benutzer legst du unter Benutzer › Neu hinzufügen an.', 'plakette' ),
				)
			);
		}
		if ( $membership ) {
			return $this->pass( sprintf( __( 'Registrierung ist offen (für %s), neue Konten dürfen nichts ändern.', 'plakette' ), $membership ) );
		}
		return $this->warn(
			__( 'Die freie Registrierung ist eingeschaltet, obwohl kein Shop und kein Mitgliederbereich sie braucht. Das lockt Spam-Konten an.', 'plakette' ),
			array(
				'weight'    => 4,
				'fix_label' => __( 'Registrierung abschalten', 'plakette' ),
				'fix_does'  => __( 'Entfernt das Häkchen „Jeder kann sich registrieren“ unter Einstellungen › Allgemein. Bestehende Konten bleiben erhalten.', 'plakette' ),
			)
		);
	}

	public function fix( array $req, array &$undo ) {
		$done = array();
		if ( self::role_is_dangerous( (string) get_option( 'default_role', 'subscriber' ) ) ) {
			Plakette_Log::set_option( 'default_role', 'subscriber', $undo );
			$done[] = __( 'Neue Konten sind jetzt Abonnenten.', 'plakette' );
		}
		if ( get_option( 'users_can_register' ) && ! Plakette_Security::membership_plugin() ) {
			Plakette_Log::set_option( 'users_can_register', '0', $undo );
			$done[] = __( 'Die freie Registrierung ist abgeschaltet.', 'plakette' );
		}
		return $done ? implode( ' ', $done ) : __( 'Es war nichts zu ändern.', 'plakette' );
	}
}

/* ====================================================================== core updates */

class Plakette_Check_Sec_Core_Updates extends Plakette_Security_Check {
	public $id        = 'security_core_updates';
	public $weight    = 8;
	public $title     = 'WordPress ist aktuell';
	public $problem   = 'WordPress ist veraltet';
	public $explain   = 'Jede WordPress-Version schließt Sicherheitslücken, die danach öffentlich bekannt sind. Angreifer suchen gezielt nach Websites mit alten Versionen. Kleine Sicherheits-Updates (z. B. 6.8.1 → 6.8.2) sollte WordPress deshalb automatisch einspielen.';
	public $fix_label = 'WordPress aktualisieren';
	public $fix_does  = 'Lädt die neueste WordPress-Version von wordpress.org und spielt sie ein – wie der Knopf unter Dashboard › Aktualisierungen. Deine Inhalte, Plugins und Themes bleiben erhalten. Ein Update lässt sich nicht rückgängig machen; lege vorher eine Sicherung an.';
	public $risk      = 'review';
	public $undoable  = false;
	public $undo_note = 'Achtung: Ein Update lässt sich nicht rückgängig machen. Lege vorher eine Sicherung an, wenn du unsicher bist.';

	/** @return object|null newest offer when an update is available */
	public static function offer( $refresh = true ) {
		global $wp_version;
		self::admin_includes();
		$t = get_site_transient( 'update_core' );
		if ( $refresh && ( ! is_object( $t ) || empty( $t->last_checked ) || time() - (int) $t->last_checked > 6 * HOUR_IN_SECONDS || ( isset( $t->version_checked ) && $t->version_checked !== $wp_version ) ) ) {
			wp_version_check( array(), true );
			$t = get_site_transient( 'update_core' );
		}
		if ( ! is_object( $t ) || empty( $t->updates ) ) {
			return null;
		}
		foreach ( (array) get_core_updates( array( 'dismissed' => true ) ) as $update ) {
			if ( isset( $update->response ) && 'upgrade' === $update->response && version_compare( $update->current, $wp_version, '>' ) ) {
				return $update;
			}
		}
		return false;
	}

	/** '' when automatic minor updates are on, otherwise the reason (option = fixable). */
	public static function minor_blocker() {
		if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
			return 'constant';
		}
		if ( defined( 'WP_AUTO_UPDATE_CORE' ) && false === WP_AUTO_UPDATE_CORE ) {
			return 'constant';
		}
		if ( ! defined( 'WP_AUTO_UPDATE_CORE' ) && 'enabled' !== get_site_option( 'auto_update_core_minor', 'enabled' ) ) {
			return 'option';
		}
		if ( apply_filters( 'automatic_updater_disabled', false ) || ! apply_filters( 'allow_minor_auto_core_updates', true ) ) {
			return 'filter';
		}
		return '';
	}

	public function run( Plakette_Context $ctx ) {
		global $wp_version;
		$offer   = self::offer();
		$blocker = self::minor_blocker();
		$notes   = array();
		if ( 'constant' === $blocker ) {
			$notes[] = __( 'Automatische Sicherheits-Updates sind in wp-config.php abgeschaltet (AUTOMATIC_UPDATER_DISABLED oder WP_AUTO_UPDATE_CORE = false). Entferne diese Zeile, damit WordPress kleine Updates selbst einspielt.', 'plakette' );
		} elseif ( 'filter' === $blocker ) {
			$notes[] = __( 'Ein Plugin oder das Theme schaltet die automatischen Sicherheits-Updates ab. Prüfe, ob das gewollt ist (z. B. weil dein Hoster die Updates übernimmt).', 'plakette' );
		}
		if ( null === $offer ) {
			return $this->skip( sprintf( __( 'WordPress %s ist installiert. Ob es eine neuere Version gibt, konnte nicht geprüft werden – wordpress.org war nicht erreichbar.', 'plakette' ), $wp_version ) );
		}
		if ( $offer ) {
			$extra = array( 'notes' => $notes, 'links' => self::updates_link() );
			if ( ! current_user_can( 'update_core' ) || ! wp_is_file_mod_allowed( 'capability_update_core' ) ) {
				$extra['blocked'] = __( 'Auf dieser Website sind Updates über WordPress abgeschaltet (DISALLOW_FILE_MODS oder fehlende Berechtigung). Dein Hoster oder deine Agentur muss das Update einspielen.', 'plakette' );
			} elseif ( ! self::can_write_files( ABSPATH ) ) {
				$extra['blocked'] = __( 'WordPress darf seine eigenen Dateien nicht direkt schreiben (es fragt nach FTP-Zugangsdaten). Spiele das Update unter Dashboard › Aktualisierungen ein und gib dort die Zugangsdaten an.', 'plakette' );
			}
			return $this->fail( sprintf( __( 'Installiert ist WordPress %1$s, aktuell ist %2$s.', 'plakette' ), $wp_version, $offer->current ), $extra );
		}
		if ( 'option' === $blocker ) {
			return $this->warn(
				sprintf( __( 'WordPress %s ist aktuell, aber automatische Sicherheits-Updates sind abgeschaltet.', 'plakette' ), $wp_version ),
				array(
					'weight'    => 4,
					'undoable'  => true,
					'risk'      => 'safe',
					'fix_label' => __( 'Automatische Sicherheits-Updates einschalten', 'plakette' ),
					'fix_does'  => __( 'Erlaubt WordPress, kleine Sicherheits-Updates selbst einzuspielen (wie unter Dashboard › Aktualisierungen). Große Versionssprünge bleiben deine Entscheidung.', 'plakette' ),
				)
			);
		}
		$detail = sprintf( __( 'WordPress %s ist die neueste Version.', 'plakette' ), $wp_version );
		if ( '' === $blocker ) {
			$detail .= ' ' . __( 'Sicherheits-Updates spielt WordPress automatisch ein.', 'plakette' );
		}
		return $this->pass( $detail, array( 'notes' => $notes ) );
	}

	public function fix( array $req, array &$undo ) {
		$done = array();
		if ( 'option' === self::minor_blocker() ) {
			Plakette_Log::set_site_option( 'auto_update_core_minor', 'enabled', $undo );
			$done[] = __( 'Automatische Sicherheits-Updates sind eingeschaltet.', 'plakette' );
		}
		$offer = self::offer( false );
		if ( $offer ) {
			if ( ! current_user_can( 'update_core' ) || ! wp_is_file_mod_allowed( 'capability_update_core' ) || ! self::can_write_files( ABSPATH ) ) {
				return new WP_Error( 'plakette_core', __( 'WordPress darf sich hier nicht selbst aktualisieren. Bitte nutze Dashboard › Aktualisierungen.', 'plakette' ) );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			require_once ABSPATH . 'wp-admin/includes/admin.php';
			$upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade( $offer );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'plakette_core', sprintf( __( 'Das Update ist fehlgeschlagen: %s Deine Website läuft weiter mit der bisherigen Version.', 'plakette' ), $result->get_error_message() ) );
			}
			if ( ! $result ) {
				return new WP_Error( 'plakette_core', __( 'Das Update ist fehlgeschlagen. Deine Website läuft weiter mit der bisherigen Version.', 'plakette' ) );
			}
			delete_site_transient( 'update_core' );
			$done[] = sprintf( __( 'WordPress wurde auf Version %s aktualisiert.', 'plakette' ), is_string( $result ) ? $result : $offer->current );
		}
		return $done ? implode( ' ', $done ) : __( 'Es war nichts zu tun.', 'plakette' );
	}
}

/* ====================================================================== plugin + theme updates */

class Plakette_Check_Sec_Plugin_Updates extends Plakette_Security_Check {
	public $id        = 'security_plugin_updates';
	public $weight    = 8;
	public $title     = 'Plugins und Themes sind aktuell';
	public $problem   = 'Updates für Plugins oder Themes warten';
	public $explain   = 'Die meisten gehackten WordPress-Websites werden über veraltete Plugins angegriffen. Sobald eine Lücke bekannt ist, probieren Angreifer sie automatisch bei Tausenden Websites aus. Updates schließen diese Lücken.';
	public $fix_label = 'Ausgewählte aktualisieren';
	public $fix_does  = 'Spielt die Updates für die ausgewählten Plugins und Themes ein – genau wie unter Dashboard › Aktualisierungen. Während des Updates ist die Website wenige Sekunden im Wartungsmodus. Updates lassen sich nicht rückgängig machen; lege vorher eine Sicherung an.';
	public $risk      = 'review';
	public $kind      = 'items';
	public $undoable  = false;
	public $undo_note = 'Achtung: Ein Update lässt sich nicht rückgängig machen. Lege vorher eine Sicherung an, wenn du unsicher bist.';
	public $batch     = 5;

	public static function pending() {
		self::admin_includes();
		$stale = function ( $t ) {
			return ! is_object( $t ) || empty( $t->last_checked ) || time() - (int) $t->last_checked > 6 * HOUR_IN_SECONDS;
		};
		if ( $stale( get_site_transient( 'update_plugins' ) ) ) {
			wp_update_plugins();
		}
		if ( $stale( get_site_transient( 'update_themes' ) ) ) {
			wp_update_themes();
		}
		$plugins_t = get_site_transient( 'update_plugins' );
		$themes_t  = get_site_transient( 'update_themes' );
		$checked   = is_object( $plugins_t ) && ! empty( $plugins_t->last_checked );
		$items     = array();
		$installed = get_plugins();
		$self      = plugin_basename( PLAKETTE_FILE );
		foreach ( ( is_object( $plugins_t ) && ! empty( $plugins_t->response ) ) ? (array) $plugins_t->response : array() as $file => $info ) {
			if ( $file === $self || ! isset( $installed[ $file ] ) || ! is_object( $info ) || empty( $info->new_version ) ) {
				continue;
			}
			if ( version_compare( $installed[ $file ]['Version'], $info->new_version, '>=' ) ) {
				continue;
			}
			$items[] = array(
				'id'    => 'plugin:' . $file,
				'label' => wp_strip_all_tags( $installed[ $file ]['Name'] ),
				'sub'   => sprintf( __( 'Plugin · %1$s → %2$s', 'plakette' ), $installed[ $file ]['Version'], $info->new_version ) . ( empty( $info->package ) ? ' · ' . __( 'kein Download verfügbar (Lizenz abgelaufen?)', 'plakette' ) : '' ),
			);
		}
		foreach ( ( is_object( $themes_t ) && ! empty( $themes_t->response ) ) ? (array) $themes_t->response : array() as $slug => $info ) {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() || empty( $info['new_version'] ) || version_compare( $theme->get( 'Version' ), $info['new_version'], '>=' ) ) {
				continue;
			}
			$items[] = array(
				'id'    => 'theme:' . $slug,
				'label' => wp_strip_all_tags( $theme->get( 'Name' ) ),
				'sub'   => sprintf( __( 'Theme · %1$s → %2$s', 'plakette' ), $theme->get( 'Version' ), $info['new_version'] ) . ( empty( $info['package'] ) ? ' · ' . __( 'kein Download verfügbar (Lizenz abgelaufen?)', 'plakette' ) : '' ),
			);
		}
		return array( $checked, $items );
	}

	public function run( Plakette_Context $ctx ) {
		list( $checked, $items ) = self::pending();
		if ( ! $items ) {
			return $checked
				? $this->pass( __( 'Alle Plugins und Themes sind auf dem neuesten Stand.', 'plakette' ) )
				: $this->skip( __( 'Ob es Updates gibt, konnte nicht geprüft werden – wordpress.org war nicht erreichbar.', 'plakette' ) );
		}
		$extra = array( 'items' => $items, 'links' => self::updates_link() );
		if ( ! current_user_can( 'update_plugins' ) || ! wp_is_file_mod_allowed( 'capability_update_plugins' ) ) {
			$extra['blocked'] = __( 'Auf dieser Website sind Updates über WordPress abgeschaltet. Dein Hoster oder deine Agentur muss sie einspielen.', 'plakette' );
		} elseif ( ! self::can_write_files( WP_PLUGIN_DIR ) ) {
			$extra['blocked'] = __( 'WordPress darf seine Dateien nicht direkt schreiben (es fragt nach FTP-Zugangsdaten). Spiele die Updates unter Dashboard › Aktualisierungen ein.', 'plakette' );
		}
		return $this->fail( sprintf( _n( '%d Update wartet.', '%d Updates warten.', count( $items ), 'plakette' ), count( $items ) ), $extra );
	}

	public function fix( array $req, array &$undo ) {
		if ( ! current_user_can( 'update_plugins' ) || ! wp_is_file_mod_allowed( 'capability_update_plugins' ) || ! self::can_write_files( WP_PLUGIN_DIR ) ) {
			return new WP_Error( 'plakette_updates', __( 'WordPress darf hier keine Updates einspielen. Bitte nutze Dashboard › Aktualisierungen.', 'plakette' ) );
		}
		$plugins = array();
		$themes  = array();
		$self    = plugin_basename( PLAKETTE_FILE );
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( 0 === strpos( $id, 'plugin:' ) && substr( $id, 7 ) !== $self && validate_file( substr( $id, 7 ) ) === 0 ) {
				$plugins[] = substr( $id, 7 );
			} elseif ( 0 === strpos( $id, 'theme:' ) && validate_file( substr( $id, 6 ) ) === 0 ) {
				$themes[] = substr( $id, 6 );
			}
		}
		if ( ! $plugins && ! $themes ) {
			return new WP_Error( 'plakette_updates', __( 'Nichts ausgewählt.', 'plakette' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$ok     = array();
		$failed = array();
		$names  = get_plugins();
		if ( $plugins ) {
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$results  = (array) $upgrader->bulk_upgrade( $plugins );
			foreach ( $plugins as $file ) {
				$name = isset( $names[ $file ] ) ? $names[ $file ]['Name'] : $file;
				if ( ! empty( $results[ $file ] ) && ! is_wp_error( $results[ $file ] ) ) {
					$ok[] = $name;
				} else {
					$failed[] = $name;
				}
			}
			wp_clean_plugins_cache();
		}
		if ( $themes ) {
			$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
			$results  = (array) $upgrader->bulk_upgrade( $themes );
			foreach ( $themes as $slug ) {
				$name = wp_get_theme( $slug )->get( 'Name' );
				if ( ! empty( $results[ $slug ] ) && ! is_wp_error( $results[ $slug ] ) ) {
					$ok[] = $name;
				} else {
					$failed[] = $name;
				}
			}
			wp_clean_themes_cache();
		}
		if ( ! $ok ) {
			return new WP_Error( 'plakette_updates', sprintf( __( 'Die Updates sind fehlgeschlagen (%s). Versuche es unter Dashboard › Aktualisierungen.', 'plakette' ), self::short_list( $failed ) ) );
		}
		$msg = sprintf( _n( '%d Update eingespielt.', '%d Updates eingespielt.', count( $ok ), 'plakette' ), count( $ok ) );
		if ( $failed ) {
			$msg .= ' ' . sprintf( __( 'Nicht geklappt: %s.', 'plakette' ), self::short_list( $failed ) );
		}
		return $msg . ' ' . __( 'Schau dir deine Website kurz an.', 'plakette' );
	}
}

/* ====================================================================== secret keys */

class Plakette_Check_Sec_Salts extends Plakette_Security_Check {
	public $id        = 'security_salts';
	public $weight    = 7;
	public $title     = 'Eigene Sicherheitsschlüssel';
	public $problem   = 'Sicherheitsschlüssel fehlen oder sind schwach';
	public $explain   = 'In der Datei wp-config.php stehen acht geheime Schlüssel (AUTH_KEY, SECURE_AUTH_SALT …). Mit ihnen verschlüsselt WordPress die Anmelde-Cookies. Fehlen sie, sind sie kurz oder noch der Platzhalter aus der Vorlage, lassen sich Anmeldungen leichter fälschen.';
	public $fix_label = 'Neue Schlüssel erzeugen';
	public $fix_does  = 'Sichert wp-config.php und trägt acht neue, zufällige Schlüssel ein. Danach sind alle abgemeldet – auch du. Melde dich einfach neu an.';
	public $risk      = 'review';

	const KEYS        = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
	const PLACEHOLDER = 'put your unique phrase here';

	/** Regex for one define with a quoted string value. Group 2 = quoted value. */
	private static function define_re( $key ) {
		return '/define\s*\(\s*([\'"])' . $key . '\1\s*,\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*\)\s*;/';
	}

	private static function unquote( $quoted ) {
		$inner = substr( $quoted, 1, -1 );
		return "'" === $quoted[0] ? str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $inner ) : stripcslashes( $inner );
	}

	/** Current values: key => value|null, read from wp-config.php when possible (the constants of this request may be outdated). */
	public static function current( $src ) {
		$values = array();
		foreach ( self::KEYS as $key ) {
			$values[ $key ] = null;
			if ( is_string( $src ) && preg_match( self::define_re( $key ), $src, $m ) ) {
				$values[ $key ] = self::unquote( $m[2] );
			} elseif ( defined( $key ) ) {
				$values[ $key ] = (string) constant( $key );
			}
		}
		return $values;
	}

	/** @return string[] problems ('' = all good) */
	public static function problems( array $values ) {
		$out    = array();
		$counts = array_count_values( array_map( 'strval', array_filter( $values, 'is_string' ) ) );
		foreach ( $values as $key => $value ) {
			if ( null === $value || '' === $value ) {
				$out[ $key ] = sprintf( __( '%s fehlt', 'plakette' ), $key );
			} elseif ( self::PLACEHOLDER === $value ) {
				$out[ $key ] = sprintf( __( '%s ist noch der Platzhalter', 'plakette' ), $key );
			} elseif ( strlen( $value ) < 32 ) {
				$out[ $key ] = sprintf( __( '%s ist zu kurz', 'plakette' ), $key );
			} elseif ( $counts[ $value ] > 1 ) {
				$out[ $key ] = sprintf( __( '%s ist doppelt vergeben', 'plakette' ), $key );
			}
		}
		return $out;
	}

	private static function read_config() {
		$path = Plakette_Env::wpconfig_path();
		if ( ! $path || ! is_readable( $path ) ) {
			return array( '', null );
		}
		$src = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array( $path, false === $src ? null : $src );
	}

	/** Reason why the fix is not possible, or ''. */
	private static function blocker( $path, $src ) {
		if ( ! $path || null === $src ) {
			return __( 'Plakette findet die Datei wp-config.php nicht oder darf sie nicht lesen.', 'plakette' );
		}
		if ( ! wp_is_writable( $path ) ) {
			return __( 'Die Datei wp-config.php ist schreibgeschützt.', 'plakette' );
		}
		foreach ( self::KEYS as $key ) {
			$in_file = (bool) preg_match( "/['\"]" . $key . "['\"]/", $src );
			$parsed  = (bool) preg_match( self::define_re( $key ), $src );
			if ( ( $in_file && ! $parsed ) || ( defined( $key ) && ! $in_file ) || ( $parsed && ! defined( $key ) ) ) {
				return sprintf( __( 'Der Schlüssel %s ist auf eine ungewöhnliche Art gesetzt (z. B. aus einer anderen Datei oder Umgebungsvariable). Das ändert Plakette nicht automatisch.', 'plakette' ), $key );
			}
		}
		return '';
	}

	public function run( Plakette_Context $ctx ) {
		list( $path, $src ) = self::read_config();
		$problems = self::problems( self::current( $src ) );
		if ( ! $problems ) {
			return $this->pass( __( 'Alle acht Schlüssel sind gesetzt, lang und verschieden.', 'plakette' ) );
		}
		$extra   = array( 'notes' => array_values( $problems ) );
		$blocked = self::blocker( $path, $src );
		if ( $blocked ) {
			$extra['blocked'] = $blocked . ' ' . __( 'Erzeuge neue Schlüssel unter https://api.wordpress.org/secret-key/1.1/salt/ und ersetze damit die acht Zeilen in wp-config.php (per FTP oder im Dateimanager deines Hosters).', 'plakette' );
			$extra['links']   = array( array( 'label' => __( 'Neue Schlüssel erzeugen (wordpress.org)', 'plakette' ), 'url' => 'https://api.wordpress.org/secret-key/1.1/salt/' ) );
		}
		return $this->fail( sprintf( _n( '%d Schlüssel ist unsicher.', '%d Schlüssel sind unsicher.', count( $problems ), 'plakette' ), count( $problems ) ), $extra );
	}

	/** Single-quoted PHP string literal. */
	private static function php_string( $value ) {
		return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value ) . "'";
	}

	public function fix( array $req, array &$undo ) {
		list( $path, $src ) = self::read_config();
		$blocked            = self::blocker( $path, $src );
		if ( $blocked ) {
			return new WP_Error( 'plakette_salts', $blocked );
		}
		$new_values = array();
		foreach ( self::KEYS as $key ) {
			do {
				$value = wp_generate_password( 64, true, true );
			} while ( in_array( $value, $new_values, true ) );
			$new_values[ $key ] = $value;
		}

		// 1. Replace existing defines, collect the missing ones.
		$new     = $src;
		$missing = array();
		$pairs   = array(); // [ new text, old text ] – lets undo restore just these lines
		foreach ( self::KEYS as $key ) {
			$literal = self::php_string( $new_values[ $key ] );
			$count   = 0;
			$new     = preg_replace_callback(
				self::define_re( $key ),
				function ( $m ) use ( $key, $literal, &$pairs ) {
					$line    = "define( '" . $key . "', " . $literal . ' );';
					$pairs[] = array( $line, $m[0] );
					return $line;
				},
				$new,
				-1,
				$count
			);
			if ( null === $new ) {
				return new WP_Error( 'plakette_salts', __( 'wp-config.php konnte nicht verarbeitet werden. Es wurde nichts verändert.', 'plakette' ) );
			}
			if ( ! $count ) {
				$missing[] = "define( '" . $key . "', " . $literal . ' );';
			}
		}
		// 2. Insert missing ones before "That's all, stop editing" or the wp-settings.php line.
		if ( $missing ) {
			$block = "/* Sicherheitsschlüssel – Added by Plakette */\n" . implode( "\n", $missing ) . "\n\n";
			$pairs[] = array( $block, '' );
			if ( preg_match( "/^[^\n]*That's all, stop editing[^\n]*$/m", $new, $m, PREG_OFFSET_CAPTURE ) || preg_match( "/^[^\n]*require_once[^\n]*wp-settings\.php[^\n]*$/m", $new, $m, PREG_OFFSET_CAPTURE ) ) {
				$new = substr( $new, 0, $m[0][1] ) . $block . substr( $new, $m[0][1] );
			} else {
				return new WP_Error( 'plakette_salts', __( 'wp-config.php hat ein unerwartetes Format. Es wurde nichts verändert.', 'plakette' ) );
			}
		}
		// 3. Validate the result before touching the real file.
		$check = self::validate( $new, $new_values );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		// 4. Backup (random name, folder protected) and write.
		$dir    = Plakette_Env::backup_dir();
		$backup = $dir . '/wp-config-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 12, false ) . '.php';
		if ( ! @copy( $path, $backup ) || md5_file( $backup ) !== md5( $src ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'plakette_salts', __( 'Die Sicherungskopie von wp-config.php konnte nicht angelegt werden. Es wurde nichts verändert.', 'plakette' ) );
		}
		$tmp = $dir . '/wp-config-neu-' . wp_generate_password( 12, false ) . '.php';
		if ( false === file_put_contents( $tmp, $new ) || file_get_contents( $tmp ) !== $new ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_delete_file( $tmp );
			wp_delete_file( $backup );
			return new WP_Error( 'plakette_salts', __( 'Die neue wp-config.php konnte nicht vorbereitet werden. Es wurde nichts verändert.', 'plakette' ) );
		}
		wp_delete_file( $tmp );
		$written = file_put_contents( $path, $new, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		clearstatcache( true, $path );
		if ( false === $written || file_get_contents( $path ) !== $new ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@copy( $backup, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $backup );
			return new WP_Error( 'plakette_salts', __( 'wp-config.php konnte nicht geschrieben werden. Die alte Fassung wurde wiederhergestellt.', 'plakette' ) );
		}
		$undo[] = array( 'file_replace', $path, $pairs, $backup );
		return __( 'Neue Schlüssel sind eingetragen (Sicherung im Ordner wp-content/plakette-backup). Ab dem nächsten Seitenaufruf sind alle abgemeldet – melde dich einfach neu an.', 'plakette' );
	}

	/** The new wp-config must parse and contain each key exactly once with the new value. */
	private static function validate( $code, array $values ) {
		if ( 0 !== strpos( ltrim( $code ), '<?php' ) || false === strpos( $code, 'wp-settings.php' ) ) {
			return new WP_Error( 'plakette_salts', __( 'Prüfung der neuen wp-config.php fehlgeschlagen. Es wurde nichts verändert.', 'plakette' ) );
		}
		foreach ( $values as $key => $value ) {
			if ( 1 !== preg_match_all( self::define_re( $key ), $code, $m ) || self::unquote( $m[2][0] ) !== $value ) {
				return new WP_Error( 'plakette_salts', sprintf( __( 'Prüfung der neuen wp-config.php fehlgeschlagen (%s). Es wurde nichts verändert.', 'plakette' ), $key ) );
			}
		}
		if ( function_exists( 'token_get_all' ) && defined( 'TOKEN_PARSE' ) ) {
			try {
				token_get_all( $code, TOKEN_PARSE );
			} catch ( Throwable $e ) {
				return new WP_Error( 'plakette_salts', __( 'Die neue wp-config.php wäre fehlerhaft. Es wurde nichts verändert.', 'plakette' ) );
			}
		}
		return true;
	}
}

/* ====================================================================== login protection */

class Plakette_Check_Sec_Login_Limit extends Plakette_Security_Check {
	public $id        = 'security_login_limit';
	public $weight    = 6;
	public $title     = 'Schutz gegen Passwort-Raten';
	public $problem   = 'Passwörter dürfen beliebig oft geraten werden';
	public $explain   = 'Automatische Programme probieren rund um die Uhr Tausende Passwörter an der Anmeldeseite aus. Ohne Bremse kann so ein Programm unbegrenzt weiterraten. Eine Sperre nach wenigen Fehlversuchen macht das praktisch aussichtslos.';
	public $fix_label = 'Anmeldeschutz einschalten';
	public $fix_does  = 'Nach 5 falschen Anmeldungen innerhalb von 15 Minuten wird der Anschluss (IP-Adresse) für 15 Minuten gesperrt. Fehlermeldungen verraten außerdem nicht mehr, ob es einen Benutzernamen gibt. Angemeldete Benutzer merken davon nichts.';

	public function run( Plakette_Context $ctx ) {
		$notes = array();
		if ( Plakette_Security::behind_proxy() ) {
			$notes[] = __( 'Deine Website läuft hinter einem Proxy oder CDN – alle Besucher kommen scheinbar von derselben Adresse. Ein Entwickler sollte die echte Besucher-Adresse über den Filter „plakette_client_ip“ liefern, sonst sperrt der Schutz bei Angriffen alle aus.', 'plakette' );
		}
		$plugin = Plakette_Security::login_protection();
		if ( $plugin ) {
			return $this->pass( sprintf( __( '%s schützt die Anmeldung.', 'plakette' ), $plugin ) );
		}
		if ( Plakette_Modules::on( 'login_limit' ) ) {
			return $this->pass( __( 'Plakette sperrt nach 5 Fehlversuchen für 15 Minuten.', 'plakette' ), array( 'notes' => $notes ) );
		}
		return $this->fail( __( 'Kein Sicherheits-Plugin begrenzt die Anmeldeversuche.', 'plakette' ), array( 'notes' => $notes ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'login_limit', true, $undo );
		return __( 'Der Anmeldeschutz ist eingeschaltet.', 'plakette' );
	}
}

/* ====================================================================== admin user name */

class Plakette_Check_Sec_Admin_User extends Plakette_Security_Check {
	public $id        = 'security_admin_user';
	public $weight    = 6;
	public $title     = 'Kein leicht zu erratender Admin-Name';
	public $problem   = 'Ein Administrator heißt „admin“ oder ähnlich';
	public $explain   = 'Wer sich anmelden will, braucht Benutzername und Passwort. Angreifer probieren zuerst Namen wie „admin“, „administrator“ oder den Namen der Website – damit haben sie schon die Hälfte. Ein eigener, nicht öffentlicher Anmeldename macht Passwort-Raten deutlich schwerer.';
	public $fix_label = 'Anmeldenamen ändern';
	public $fix_does  = 'Ändert den Anmeldenamen der ausgewählten Administratoren (Passwort, Beiträge und Einstellungen bleiben). Wichtig: Merke dir den neuen Namen! Betrifft es dein eigenes Konto, wirst du abgemeldet und meldest dich mit dem neuen Namen (oder deiner E-Mail-Adresse) wieder an.';
	public $risk      = 'review';
	public $kind      = 'items';

	/** Login names that are tried first. */
	public static function guessable() {
		$names = array( 'admin', 'administrator', 'administrador', 'administrateur', 'admin1', 'webmaster', 'root', 'test', 'demo', 'user', 'wordpress', 'wp', 'sysadmin', 'adm', 'admin123', 'superadmin' );
		$site  = strtolower( (string) get_bloginfo( 'name' ) );
		if ( '' !== $site ) {
			$names[] = sanitize_user( $site, true );
			$names[] = str_replace( array( ' ', '-' ), '', sanitize_user( $site, true ) );
			$names[] = sanitize_title( $site );
		}
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host ) {
			$host    = preg_replace( '/^www\./', '', $host );
			$names[] = $host;
			$names[] = strtok( $host, '.' );
		}
		return array_values( array_unique( array_filter( $names ) ) );
	}

	/** Why this login is risky ('' = fine). */
	public static function reason( WP_User $user ) {
		$login = strtolower( $user->user_login );
		if ( in_array( $login, self::guessable(), true ) ) {
			return __( 'Name wird von Angreifern zuerst ausprobiert', 'plakette' );
		}
		if ( strtolower( $user->display_name ) === $login && count_user_posts( $user->ID, 'post', true ) + count_user_posts( $user->ID, 'page', true ) > 0 ) {
			return __( 'Anmeldename steht als Autorenname öffentlich auf der Website', 'plakette' );
		}
		return '';
	}

	/** @return true|WP_Error */
	public static function validate_login( $login, $user_id ) {
		if ( strlen( $login ) < 4 || strlen( $login ) > 60 ) {
			return new WP_Error( 'plakette_login', __( 'Der neue Name muss 4 bis 60 Zeichen lang sein.', 'plakette' ) );
		}
		if ( ! validate_username( $login ) || sanitize_user( $login, true ) !== $login ) {
			return new WP_Error( 'plakette_login', __( 'Der neue Name darf nur Buchstaben (a–z), Ziffern, Leerzeichen und . - _ @ enthalten.', 'plakette' ) );
		}
		$taken = username_exists( $login );
		if ( $taken && (int) $taken !== (int) $user_id ) {
			return new WP_Error( 'plakette_login', sprintf( __( 'Den Namen „%s“ gibt es schon.', 'plakette' ), $login ) );
		}
		$illegal = array_map( 'strtolower', (array) apply_filters( 'illegal_user_logins', array() ) );
		if ( in_array( strtolower( $login ), array_merge( $illegal, self::guessable() ), true ) ) {
			return new WP_Error( 'plakette_login', sprintf( __( '„%s“ ist ebenfalls leicht zu erraten.', 'plakette' ), $login ) );
		}
		$other = get_user_by( 'email', $login );
		if ( $other && (int) $other->ID !== (int) $user_id ) {
			return new WP_Error( 'plakette_login', __( 'Der Name ist die E-Mail-Adresse eines anderen Kontos.', 'plakette' ) );
		}
		return true;
	}

	/** Suggestion for a new login name. */
	public static function suggest( WP_User $user ) {
		$candidates = array();
		if ( $user->first_name ) {
			$candidates[] = sanitize_user( remove_accents( strtolower( $user->first_name . ( $user->last_name ? '.' . $user->last_name : '' ) ) ), true );
		}
		$local = strtolower( (string) strtok( (string) $user->user_email, '@' ) );
		if ( $local ) {
			$candidates[] = sanitize_user( $local, true );
		}
		$candidates[] = 'verwaltung-' . substr( md5( $user->ID . $user->user_registered . wp_salt( 'auth' ) ), 0, 5 );
		foreach ( $candidates as $c ) {
			$c = str_replace( ' ', '', $c );
			if ( true === self::validate_login( $c, $user->ID ) && strtolower( $user->display_name ) !== $c ) {
				return $c;
			}
		}
		return end( $candidates );
	}

	public function run( Plakette_Context $ctx ) {
		if ( is_multisite() ) {
			return $this->skip( __( 'In einem Multisite-Netzwerk ändert Plakette keine Anmeldenamen (Netzwerk-Administratoren hängen am Namen).', 'plakette' ) );
		}
		$items = array();
		$me    = get_current_user_id();
		foreach ( get_users( array( 'role' => 'administrator', 'number' => 50 ) ) as $user ) {
			$why = self::reason( $user );
			if ( '' === $why ) {
				continue;
			}
			$items[] = array(
				'id'    => 'user:' . $user->ID,
				'label' => $user->user_login . ( $user->user_email ? ' (' . $user->user_email . ')' : '' ),
				'sub'   => $why . ( (int) $user->ID === $me ? ' · ' . __( 'Das bist du – danach meldest du dich mit dem neuen Namen an.', 'plakette' ) : '' ),
				'value' => self::suggest( $user ),
				'link'  => get_edit_user_link( $user->ID ),
			);
		}
		if ( ! $items ) {
			return $this->pass( __( 'Kein Administrator hat einen leicht zu erratenden Anmeldenamen.', 'plakette' ) );
		}
		return $this->fail(
			sprintf( _n( '%d Administrator hat einen leicht zu erratenden Anmeldenamen.', '%d Administratoren haben leicht zu erratende Anmeldenamen.', count( $items ), 'plakette' ), count( $items ) ),
			array(
				'items'      => $items,
				'item_field' => array( 'label' => __( 'Neuer Anmeldename', 'plakette' ), 'max' => 60 ),
				'notes'      => array( __( 'Tipp: Stelle im Profil einen Anzeigenamen ein, der sich vom Anmeldenamen unterscheidet (z. B. deinen Vornamen).', 'plakette' ) ),
			)
		);
	}

	public function fix( array $req, array &$undo ) {
		global $wpdb;
		if ( is_multisite() ) {
			return new WP_Error( 'plakette_login', __( 'In einem Multisite-Netzwerk nicht möglich.', 'plakette' ) );
		}
		if ( ! current_user_can( 'edit_users' ) ) {
			return new WP_Error( 'plakette_login', __( 'Dir fehlt die Berechtigung, Benutzer zu bearbeiten.', 'plakette' ) );
		}
		$done   = array();
		$errors = array();
		$me     = false;
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			if ( ! preg_match( '/^user:(\d+)$/', isset( $item['id'] ) ? (string) $item['id'] : '', $m ) ) {
				continue;
			}
			$user = get_userdata( (int) $m[1] );
			if ( ! $user ) {
				continue;
			}
			$login = trim( (string) ( isset( $item['value'] ) ? $item['value'] : '' ) );
			$valid = self::validate_login( $login, $user->ID );
			if ( is_wp_error( $valid ) ) {
				$errors[] = $user->user_login . ': ' . $valid->get_error_message();
				continue;
			}
			if ( $login === $user->user_login ) {
				continue;
			}
			$nicename = $user->user_nicename;
			if ( strtolower( $nicename ) === strtolower( sanitize_title( $user->user_login ) ) || in_array( strtolower( $nicename ), self::guessable(), true ) ) {
				// The public author slug showed the old login – derive it from the new one (unique).
				$base     = mb_substr( sanitize_title( $login ), 0, 45 );
				$nicename = $base;
				$n        = 2;
				while ( ( $other = get_user_by( 'slug', $nicename ) ) && (int) $other->ID !== (int) $user->ID ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
					$nicename = $base . '-' . $n++;
				}
			}
			$undo[] = array( 'user_login', (int) $user->ID, $user->user_login, $user->user_nicename );
			$wpdb->update( $wpdb->users, array( 'user_login' => $login, 'user_nicename' => $nicename ), array( 'ID' => $user->ID ) ); // phpcs:ignore WordPress.DB
			clean_user_cache( $user );
			$done[] = $user->user_login . ' → ' . $login;
			if ( (int) $user->ID === get_current_user_id() ) {
				$me = true;
			}
		}
		if ( ! $done ) {
			return new WP_Error( 'plakette_login', $errors ? implode( ' ', $errors ) : __( 'Nichts geändert.', 'plakette' ) );
		}
		$msg = sprintf( __( 'Neue Anmeldenamen: %s.', 'plakette' ), implode( ', ', $done ) );
		if ( $me ) {
			$msg .= ' ' . __( 'Du wirst gleich abgemeldet – melde dich mit dem neuen Namen wieder an.', 'plakette' );
		}
		if ( $errors ) {
			$msg .= ' ' . implode( ' ', $errors );
		}
		return $msg;
	}
}

/* ====================================================================== error display */

class Plakette_Check_Sec_Debug extends Plakette_Security_Check {
	public $id        = 'security_debug';
	public $weight    = 6;
	public $title     = 'Fehlermeldungen bleiben verborgen';
	public $problem   = 'PHP-Fehlermeldungen sind für Besucher sichtbar';
	public $explain   = 'Wenn etwas schiefgeht, schreibt PHP eine Fehlermeldung – mit Dateipfaden und manchmal Datenbank-Details. Besucher sollten das nie sehen: Angreifer lernen daraus, wie dein Server aufgebaut ist. Fehler gehören in eine Protokolldatei, nicht auf die Seite.';
	public $fix_label = 'Fehlermeldungen ausblenden';
	public $fix_does  = 'Plakette schaltet die Anzeige von Fehlern für alle Seiten außerhalb des Admin-Bereichs ab. Fehler, die passieren, bevor Plugins geladen sind, kann nur eine Änderung in wp-config.php (WP_DEBUG_DISPLAY auf false) verbergen.';

	public static function displaying() {
		$ini = strtolower( trim( (string) ini_get( 'display_errors' ) ) );
		return in_array( $ini, array( '1', 'on', 'yes', 'true', 'stdout' ), true );
	}

	public function run( Plakette_Context $ctx ) {
		$wp_display = defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY );
		if ( Plakette_Modules::on( 'hide_errors' ) ) {
			$notes = $wp_display ? array( __( 'In wp-config.php ist WP_DEBUG mit Anzeige eingeschaltet. Setze WP_DEBUG_DISPLAY auf false, damit auch ganz frühe Fehler verborgen bleiben.', 'plakette' ) ) : array();
			return $this->pass( __( 'Plakette blendet Fehlermeldungen für Besucher aus.', 'plakette' ), array( 'notes' => $notes ) );
		}
		if ( $wp_display ) {
			return $this->fail( __( 'WP_DEBUG ist eingeschaltet und zeigt Fehler direkt auf der Seite an.', 'plakette' ) );
		}
		if ( self::displaying() ) {
			return $this->fail( __( 'Der Server ist so eingestellt, dass PHP-Fehler auf der Seite erscheinen (display_errors).', 'plakette' ) );
		}
		return $this->pass( __( 'Fehler werden nicht auf der Seite angezeigt.', 'plakette' ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'hide_errors', true, $undo );
		return __( 'Fehlermeldungen sind für Besucher ausgeblendet.', 'plakette' );
	}
}

/* ====================================================================== public debug.log */

class Plakette_Check_Sec_Debug_Log extends Plakette_Security_Check {
	public $id        = 'security_debug_log';
	public $weight    = 6;
	public $title     = 'Fehlerprotokoll ist nicht öffentlich';
	public $problem   = 'Das Fehlerprotokoll (debug.log) kann jeder herunterladen';
	public $explain   = 'WordPress schreibt Fehler in die Datei debug.log. Liegt sie im Ordner wp-content, kann jeder sie über die Adresse /wp-content/debug.log lesen – samt Dateipfaden, Plugin-Namen und manchmal E-Mail-Adressen oder Zugangsdaten. Angreifer suchen gezielt danach.';
	public $fix_label = 'Zugriff sperren';
	public $fix_does  = 'Ergänzt im Ordner der Datei eine .htaccess-Regel, die den Abruf über das Internet verbietet. Die Datei selbst bleibt für dich (per FTP) lesbar. Plakette prüft danach, ob die Sperre wirkt, und nimmt die Regel sonst zurück.';
	public $risk      = 'review';

	const MARKER = 'Plakette Debug-Log';

	/** Log files that exist: path => url. */
	public static function files() {
		$paths = array( WP_CONTENT_DIR . '/debug.log' );
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG && ! in_array( strtolower( WP_DEBUG_LOG ), array( '1', 'true' ), true ) ) {
			$paths[] = WP_DEBUG_LOG;
		}
		$out = array();
		foreach ( array_unique( $paths ) as $path ) {
			$path = wp_normalize_path( $path );
			if ( ! @is_file( $path ) || ! @filesize( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				continue;
			}
			$content = wp_normalize_path( WP_CONTENT_DIR );
			$root    = wp_normalize_path( ABSPATH );
			if ( 0 === strpos( $path, $content . '/' ) ) {
				$out[ $path ] = content_url( substr( $path, strlen( $content ) + 1 ) );
			} elseif ( 0 === strpos( $path, $root ) ) {
				$out[ $path ] = site_url( substr( $path, strlen( $root ) ) );
			}
		}
		return $out;
	}

	/** Does the URL deliver the log? */
	public static function public_log( $path, $url ) {
		$res = Plakette_Security::request( $url, array( 'limit_response_size' => 4096, 'headers' => array( 'Range' => 'bytes=0-2047' ) ) );
		if ( ! $res['ok'] || ! in_array( $res['status'], array( 200, 206 ), true ) || '' === trim( $res['body'] ) ) {
			return false;
		}
		if ( false !== stripos( $res['body'], '<html' ) || false !== stripos( $res['body'], '<!doctype' ) ) {
			return false;
		}
		$head = (string) @file_get_contents( $path, false, null, 0, 60 ); // phpcs:ignore
		return ( '' !== $head && 0 === strpos( $res['body'], substr( $head, 0, 30 ) ) ) || (bool) preg_match( '/^\[\d{2}-\w{3}-\d{4}/', $res['body'] ) || false !== strpos( $res['body'], 'PHP ' );
	}

	/** Public log files: path => url. */
	public static function exposed() {
		$out = array();
		foreach ( self::files() as $path => $url ) {
			if ( self::public_log( $path, $url ) ) {
				$out[ $path ] = $url;
			}
		}
		return $out;
	}

	public function run( Plakette_Context $ctx ) {
		$files = self::files();
		if ( ! $files ) {
			return $this->pass( __( 'Es gibt kein öffentlich erreichbares Fehlerprotokoll.', 'plakette' ) );
		}
		$exposed = self::exposed();
		if ( ! $exposed ) {
			return $this->pass( __( 'Das Fehlerprotokoll existiert, ist aber über das Internet gesperrt.', 'plakette' ) );
		}
		$size  = 0;
		$notes = array();
		foreach ( $exposed as $path => $url ) {
			$size   += (int) filesize( $path );
			$notes[] = $url;
		}
		$detail = sprintf( __( 'Das Fehlerprotokoll (%s) ist für jeden abrufbar.', 'plakette' ), self::size( $size ) );
		$extra  = array( 'notes' => $notes );
		$dirs   = array_unique( array_map( 'dirname', array_keys( $exposed ) ) );
		$write  = Plakette_Env::is_apache();
		foreach ( $dirs as $dir ) {
			$ht    = $dir . '/.htaccess';
			$write = $write && ( file_exists( $ht ) ? wp_is_writable( $ht ) : wp_is_writable( $dir ) );
		}
		if ( ! $write ) {
			$extra['kind']   = 'letter';
			$extra['letter'] = $this->letter(
				sprintf(
					__( "Bitte sperren Sie den Abruf der Datei %s über das Internet (z. B. bei nginx: location ~* /debug\\.log$ { deny all; }). Die Datei enthält das Fehlerprotokoll von WordPress und darf nicht öffentlich sein.", 'plakette' ),
					implode( ', ', array_values( $exposed ) )
				)
			);
			$extra['notes'][] = __( 'Schneller Ausweg: Lösche die Datei per FTP und setze in wp-config.php WP_DEBUG_LOG auf false, wenn du das Protokoll nicht brauchst.', 'plakette' );
		}
		return $this->fail( $detail, $extra );
	}

	public function fix( array $req, array &$undo ) {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$exposed = self::exposed();
		if ( ! $exposed ) {
			return __( 'Das Fehlerprotokoll ist bereits gesperrt.', 'plakette' );
		}
		if ( ! Plakette_Env::is_apache() ) {
			return new WP_Error( 'plakette_debuglog', __( 'Auf diesem Server (kein Apache) kann Plakette den Zugriff nicht sperren. Nutze die Nachricht an deinen Hoster.', 'plakette' ) );
		}
		$by_dir = array();
		foreach ( array_keys( $exposed ) as $path ) {
			$by_dir[ dirname( $path ) ][] = basename( $path );
		}
		foreach ( $by_dir as $dir => $names ) {
			$ht    = $dir . '/.htaccess';
			$lines = array();
			foreach ( array_unique( $names ) as $name ) {
				$lines = array_merge(
					$lines,
					array(
						'<Files "' . $name . '">',
						'	<IfModule mod_authz_core.c>',
						'		Require all denied',
						'	</IfModule>',
						'	<IfModule !mod_authz_core.c>',
						'		Order allow,deny',
						'		Deny from all',
						'	</IfModule>',
						'</Files>',
					)
				);
			}
			$existed = file_exists( $ht );
			if ( ! insert_with_markers( $ht, self::MARKER, $lines ) ) {
				return new WP_Error( 'plakette_debuglog', __( 'Die Datei .htaccess konnte nicht geschrieben werden.', 'plakette' ) );
			}
			$undo[] = $existed ? array( 'markers', $ht, self::MARKER ) : array( 'file_delete', $ht );
		}
		// Does the server respect the rule? (AllowOverride None ignores it, a disallowed directive gives 500.)
		foreach ( $exposed as $path => $url ) {
			$res = Plakette_Security::request( $url, array( 'limit_response_size' => 2048 ) );
			if ( ! $res['ok'] || $res['status'] >= 500 || self::public_log( $path, $url ) ) {
				Plakette_Log::apply_ops( $undo );
				$undo = array();
				return new WP_Error( 'plakette_debuglog', __( 'Der Server beachtet die Sperre nicht. Die Regel wurde wieder entfernt. Bitte deinen Hoster, den Zugriff auf debug.log zu sperren, oder lösche die Datei per FTP.', 'plakette' ) );
			}
		}
		return __( 'Das Fehlerprotokoll ist jetzt über das Internet gesperrt.', 'plakette' );
	}
}

/* ====================================================================== file editor */

class Plakette_Check_Sec_File_Edit extends Plakette_Security_Check {
	public $id        = 'security_file_edit';
	public $weight    = 5;
	public $title     = 'Datei-Editor im Admin-Bereich ist aus';
	public $problem   = 'Theme- und Plugin-Dateien lassen sich im Browser bearbeiten';
	public $explain   = 'WordPress hat einen eingebauten Editor für den Programmcode von Themes und Plugins. Gelangt ein Angreifer an ein Admin-Konto, kann er darüber sofort Schadcode einbauen. Ein Tippfehler darin legt außerdem die ganze Website lahm. Kaum jemand braucht diesen Editor.';
	public $fix_label = 'Datei-Editor abschalten';
	public $fix_does  = 'Ergänzt in wp-config.php die Zeile define( \'DISALLOW_FILE_EDIT\', true ). Die Menüpunkte „Theme-Datei-Editor“ und „Plugin-Datei-Editor“ verschwinden. Updates und der Customizer funktionieren weiter.';

	const LINE = "define( 'DISALLOW_FILE_EDIT', true );";

	public function run( Plakette_Context $ctx ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return $this->pass( __( 'Änderungen an Dateien sind über DISALLOW_FILE_MODS komplett abgeschaltet.', 'plakette' ) );
		}
		$path = Plakette_Env::wpconfig_path();
		$src  = $path && is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$in   = '' !== $src && preg_match( "/define\s*\(\s*['\"]DISALLOW_FILE_EDIT['\"]\s*,\s*([^)]+)\)/i", $src, $m );
		if ( $in ? preg_match( '/^\s*(true|1|\'1\'|"1")\s*$/i', $m[1] ) : ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) {
			return $this->pass( __( 'Der Datei-Editor ist abgeschaltet.', 'plakette' ) );
		}
		$extra = array();
		if ( $in ) {
			$extra['blocked'] = __( 'In wp-config.php steht DISALLOW_FILE_EDIT bereits, aber auf false. Ändere dort false in true.', 'plakette' );
		} elseif ( ! $path || ! wp_is_writable( $path ) ) {
			$extra['blocked'] = sprintf( __( 'Die Datei wp-config.php ist schreibgeschützt. Füge per FTP oder im Dateimanager deines Hosters über der Zeile „That\'s all, stop editing!“ diese Zeile ein: %s', 'plakette' ), self::LINE );
		}
		return $this->fail( __( 'Der Theme- und Plugin-Datei-Editor ist eingeschaltet.', 'plakette' ), $extra );
	}

	public function fix( array $req, array &$undo ) {
		$op = Plakette_Env::add_wpconfig_define( 'DISALLOW_FILE_EDIT' );
		if ( is_wp_error( $op ) ) {
			return $op;
		}
		$undo[] = $op;
		return __( 'Der Datei-Editor ist abgeschaltet.', 'plakette' );
	}
}

/* ====================================================================== backups */

class Plakette_Check_Sec_Backups extends Plakette_Security_Check {
	public $id        = 'security_backups';
	public $weight    = 5;
	public $title     = 'Regelmäßige Sicherungen';
	public $problem   = 'Keine Sicherung (Backup) erkennbar';
	public $explain   = 'Eine Sicherung ist deine Rettung nach einem Hack, einem missglückten Update oder einem Serverschaden. Bewährt ist die 3-2-1-Regel: 3 Kopien deiner Daten, auf 2 verschiedenen Speichern, 1 davon außer Haus (nicht auf demselben Server). Frag deinen Hoster, was er sichert – oder nutze ein Backup-Plugin wie UpdraftPlus mit Ablage in einer Cloud.';
	public $fix_label = 'Nachricht an den Hoster';
	public $fix_does  = 'Plakette hat eine Nachricht vorbereitet, in der du deinen Hoster nach täglichen Sicherungen fragst. Bietet er keine an, installiere ein Backup-Plugin (z. B. UpdraftPlus) und speichere die Sicherungen in einer Cloud. Weißt du sicher, dass dein Server die Website sichert, markiere den Punkt als erfüllt.';
	public $kind      = 'letter';
	public $risk      = 'review';

	/** Option: time the site owner confirmed that the server makes backups. */
	const CONFIRMED = 'plakette_backups_confirmed';

	/** Hosting of i-make.media – every site there is backed up automatically. */
	const IMM_URL = 'https://i-make.media/';

	/**
	 * Backups the host declares: constant PLAKETTE_BACKUPS (name of the provider, e.g. in wp-config.php of a
	 * managed server) or the filter plakette_host_backups.
	 */
	public static function host_backups() {
		$name = defined( 'PLAKETTE_BACKUPS' ) && is_string( PLAKETTE_BACKUPS ) ? PLAKETTE_BACKUPS : '';
		return trim( (string) apply_filters( 'plakette_host_backups', $name ) );
	}

	public function run( Plakette_Context $ctx ) {
		$host = self::host_backups();
		if ( '' !== $host ) {
			return $this->pass( sprintf( __( 'Dein Hosting bei %s sichert die Website automatisch.', 'plakette' ), $host ) );
		}
		$found = Plakette_Security::backup_solution();
		if ( $found ) {
			return $this->pass( sprintf( __( 'Sicherungen über %s erkannt. Probiere ab und zu aus, ob sich eine Sicherung auch zurückspielen lässt.', 'plakette' ), $found ) );
		}
		$confirmed = (int) get_option( self::CONFIRMED, 0 );
		if ( $confirmed ) {
			return $this->pass(
				sprintf( __( 'Du hast am %s bestätigt, dass der Server die Website sichert.', 'plakette' ), wp_date( get_option( 'date_format' ), $confirmed ) ),
				array( 'notes' => array( __( 'Stimmt das nicht mehr, nimm die Bestätigung im Protokoll zurück.', 'plakette' ) ) )
			);
		}
		return $this->warn(
			__( 'Plakette findet kein Backup-Plugin. Vielleicht sichert dein Hoster die Website – frag am besten nach.', 'plakette' ),
			array(
				'letter'        => $this->letter( __( "Bitte teilen Sie mir mit, ob und wie meine Website gesichert wird:\n- Werden Dateien UND Datenbank täglich gesichert?\n- Wie lange werden die Sicherungen aufbewahrt (ich wünsche mindestens 14 Tage)?\n- Liegen die Sicherungen auf einem anderen Server bzw. an einem anderen Standort?\n- Kann ich eine Sicherung selbst zurückspielen (z. B. im Kundenmenü), und was kostet eine Wiederherstellung?\n\nFalls es keine tägliche Sicherung gibt, bitte ich um ein Angebot dafür.", 'plakette' ) ),
				'confirm_label' => __( 'Server sichert – als erfüllt markieren', 'plakette' ),
				'notes'         => array( __( 'Tipp: Websites, die bei i-make.media gehostet sind, werden automatisch gesichert – darum musst du dich dort nicht kümmern.', 'plakette' ) ),
				'links'         => array( array( 'label' => __( 'Hosting mit Sicherung bei i-make.media', 'plakette' ), 'url' => self::IMM_URL ) ),
			)
		);
	}

	/** "Mark as fulfilled": the owner knows that the server backs the site up. Undo in the protocol. */
	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_option( self::CONFIRMED, time(), $undo, false );
		return __( 'Als erfüllt markiert: Der Server sichert die Website.', 'plakette' );
	}
}

/* ====================================================================== security headers */

class Plakette_Check_Sec_Headers extends Plakette_Security_Check {
	public $id        = 'security_headers';
	public $weight    = 5;
	public $title     = 'Sicherheits-Header werden gesendet';
	public $problem   = 'Wichtige Sicherheits-Header fehlen';
	public $explain   = 'Mit jeder Seite schickt der Server unsichtbare Zusatzangaben („Header“) an den Browser. Einige davon schützen deine Besucher: Sie verhindern, dass fremde Seiten deine Website unsichtbar einbetten (Klick-Betrug), dass der Browser Dateien falsch deutet, und dass verraten wird, von welcher Seite jemand kam.';
	public $fix_label = 'Sicherheits-Header einschalten';
	public $fix_does  = 'Plakette sendet die fehlenden Header bei jeder Seite deiner Website mit. Fremde Websites können deine Seiten danach nicht mehr einbetten; Einbettungen deiner Beiträge über WordPress (oEmbed) funktionieren weiter. Karten und Videos auf deiner Seite sind nicht betroffen.';
	public $risk      = 'review';

	/** Origins of iframes on the home page and the sample pages. */
	private static function embed_origins( Plakette_Context $ctx ) {
		$origins = array();
		$lists   = array( isset( $ctx->facts()['iframes'] ) ? (array) $ctx->facts()['iframes'] : array() );
		foreach ( $ctx->samples() as $facts ) {
			$lists[] = isset( $facts['iframes'] ) ? (array) $facts['iframes'] : array();
		}
		foreach ( $lists as $list ) {
			foreach ( $list as $src ) {
				$src    = is_array( $src ) ? ( isset( $src['src'] ) ? $src['src'] : '' ) : (string) $src;
				$scheme = wp_parse_url( $src, PHP_URL_SCHEME );
				$host   = wp_parse_url( $src, PHP_URL_HOST );
				if ( $host && preg_match( '/^[a-z0-9.-]+$/i', $host ) ) {
					$origins[] = ( $scheme ? strtolower( $scheme ) : 'https' ) . '://' . strtolower( $host );
				}
			}
		}
		return array_slice( array_values( array_unique( $origins ) ), 0, 10 );
	}

	/**
	 * Camera, microphone and location are switched off for the page itself. Embedded maps or videos keep
	 * them (their origin is allowed explicitly), so a map's "my location" button still works.
	 */
	public static function permissions_policy( array $origins ) {
		$allow = $origins ? '(' . implode( ' ', array_map( fn( $o ) => '"' . $o . '"', $origins ) ) . ')' : '()';
		return 'camera=' . $allow . ', microphone=' . $allow . ', geolocation=' . $allow;
	}

	/** Missing headers: name => value to send. */
	public static function missing( Plakette_Context $ctx ) {
		$home = $ctx->home();
		$h    = isset( $home['headers'] ) ? (array) $home['headers'] : array();
		$csp  = isset( $h['content-security-policy'] ) ? strtolower( $h['content-security-policy'] ) : '';
		$out  = array();
		if ( empty( $h['x-content-type-options'] ) || false === stripos( $h['x-content-type-options'], 'nosniff' ) ) {
			$out['X-Content-Type-Options'] = 'nosniff';
		}
		if ( empty( $h['x-frame-options'] ) && false === strpos( $csp, 'frame-ancestors' ) ) {
			$out['X-Frame-Options'] = 'SAMEORIGIN';
		}
		if ( empty( $h['referrer-policy'] ) ) {
			$out['Referrer-Policy'] = 'strict-origin-when-cross-origin';
		}
		if ( empty( $h['permissions-policy'] ) ) {
			$out['Permissions-Policy'] = self::permissions_policy( self::embed_origins( $ctx ) );
		}
		if ( 0 === strpos( home_url(), 'https://' ) && $ctx->home_ok() && empty( $h['strict-transport-security'] ) ) {
			$out['Strict-Transport-Security'] = 'max-age=31536000';
		}
		return $out;
	}

	public function run( Plakette_Context $ctx ) {
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$missing = self::missing( $ctx );
		if ( ! $missing ) {
			return $this->pass( __( 'Alle wichtigen Sicherheits-Header sind vorhanden.', 'plakette' ) );
		}
		$notes = array();
		foreach ( $missing as $name => $value ) {
			$notes[] = sprintf( __( 'Fehlt: %1$s (wird gesetzt auf: %2$s)', 'plakette' ), $name, $value );
		}
		$module = (array) Plakette_Modules::get( 'sec_headers', array() );
		if ( $module && ! array_diff_key( $missing, $module ) ) {
			$notes[] = __( 'Plakette sendet diese Header bereits, sie kommen aber nicht an. Wahrscheinlich liefert ein Cache-Plugin oder der Server die Seite aus, bevor WordPress startet. Leere den Cache oder richte die Header dort ein.', 'plakette' );
			return $this->warn( __( 'Die Sicherheits-Header kommen beim Besucher nicht an.', 'plakette' ), array( 'notes' => $notes, 'kind' => 'none' ) );
		}
		if ( 0 !== strpos( home_url(), 'https://' ) ) {
			$notes[] = __( 'Der Header für erzwungenes HTTPS (Strict-Transport-Security) folgt, sobald deine Website über HTTPS läuft.', 'plakette' );
		}
		return $this->fail( sprintf( _n( '%d Sicherheits-Header fehlt.', '%d Sicherheits-Header fehlen.', count( $missing ), 'plakette' ), count( $missing ) ), array( 'notes' => $notes ) );
	}

	public function fix( array $req, array &$undo ) {
		$missing = self::missing( new Plakette_Context() );
		if ( ! $missing ) {
			return __( 'Alle Header sind schon vorhanden.', 'plakette' );
		}
		$list = array_merge( (array) Plakette_Modules::get( 'sec_headers', array() ), $missing );
		Plakette_Log::set_module( 'sec_headers', $list, $undo );

		// The page-cache drop-in reads the same list for pages it serves before WordPress starts.
		$file = Plakette_Security::headers_file();
		if ( file_exists( $file ) ) {
			$backup = Plakette_Env::backup_dir() . '/plakette-headers-' . wp_generate_password( 8, false ) . '.json';
			if ( @copy( $file, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$undo[] = array( 'file_restore', $file, $backup );
			}
		} else {
			$undo[] = array( 'file_delete', $file );
		}
		$json = wp_json_encode( $list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$note = false === @file_put_contents( $file, $json ) // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			? ' ' . __( '(Die Datei für den Seiten-Cache konnte nicht geschrieben werden – zwischengespeicherte Seiten bekommen die Header erst nach dem nächsten Leeren des Caches.)', 'plakette' )
			: '';
		return sprintf( __( 'Gesendet werden jetzt: %s.', 'plakette' ), implode( ', ', array_keys( $missing ) ) ) . $note;
	}
}

/* ====================================================================== mixed content */

class Plakette_Check_Sec_Mixed_Content extends Plakette_Security_Check {
	public $id        = 'security_mixed_content';
	public $weight    = 5;
	public $title     = 'Alle Inhalte kommen verschlüsselt';
	public $problem   = 'Unsichere http-Adressen auf einer HTTPS-Seite';
	public $explain   = 'Deine Website läuft über HTTPS, bindet aber Bilder, Skripte oder Links noch mit „http://“ ein (gemischte Inhalte). Browser blockieren solche Dateien oder zeigen kein Schloss mehr an – Bilder fehlen dann, Funktionen brechen ab.';
	public $fix_label = 'Adressen auf HTTPS umstellen';
	public $fix_does  = 'Plakette ersetzt beim Ausliefern jeder Seite „http://“ durch „https://“ – nur für Adressen deiner eigenen Domain. Deine Inhalte in der Datenbank bleiben unverändert.';

	public function run( Plakette_Context $ctx ) {
		if ( 0 !== strpos( home_url(), 'https://' ) ) {
			return $this->skip( __( 'Deine Website läuft noch nicht über HTTPS – darum kümmert sich der Prüfpunkt „Sichere Verbindung (HTTPS)“.', 'plakette' ) );
		}
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$hits = array();
		foreach ( $ctx->all_html() as $html ) {
			$hits = array_merge( $hits, Plakette_Security::find_mixed( (string) $html ) );
		}
		$hits = array_slice( array_values( array_unique( $hits ) ), 0, 8 );
		if ( ! $hits ) {
			return $this->pass( Plakette_Modules::on( 'mixed_content' ) ? __( 'Plakette stellt unsichere Adressen beim Ausliefern auf HTTPS um.', 'plakette' ) : __( 'Keine unsicheren Adressen gefunden.', 'plakette' ) );
		}
		$extra = array( 'notes' => $hits );
		if ( Plakette_Modules::on( 'mixed_content' ) ) {
			$extra['kind'] = 'none';
			return $this->warn( __( 'Trotz Umstellung kommen noch http-Adressen an – vermutlich liefert ein Cache die Seite vorher aus. Leere den Cache.', 'plakette' ), $extra );
		}
		return $this->fail( sprintf( __( '%d unsichere Adressen der eigenen Domain gefunden.', 'plakette' ), count( $hits ) ), $extra );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'mixed_content', true, $undo );
		return __( 'Unsichere Adressen werden beim Ausliefern auf HTTPS umgestellt.', 'plakette' );
	}
}

/* ====================================================================== user enumeration */

class Plakette_Check_Sec_User_Enum extends Plakette_Security_Check {
	public $id        = 'security_user_enum';
	public $weight    = 5;
	public $title     = 'Anmeldenamen bleiben geheim';
	public $problem   = 'Fremde können Anmeldenamen auslesen';
	public $explain   = 'Über Adressen wie „/?author=1“ oder die Schnittstelle „/wp-json/wp/v2/users“ verrät WordPress jedem Besucher die Benutzernamen der Autoren. Damit haben Angreifer die Hälfte der Zugangsdaten und müssen nur noch das Passwort raten.';
	public $fix_label = 'Benutzernamen verbergen';
	public $fix_does  = 'Aufrufe von „/?author=…“ führen für Besucher zur Startseite, die Benutzerliste der Schnittstelle gibt es nur noch für angemeldete Benutzer (der Block-Editor braucht sie), die Autoren-Sitemap entfällt und eingebettete Beiträge nennen keinen Autor mehr.';
	public $risk      = 'review';

	public function run( Plakette_Context $ctx ) {
		$users = get_users(
			array(
				'has_published_posts' => true,
				'number'              => 3,
				'orderby'             => 'ID',
			)
		);
		$leaks = array();
		foreach ( $users as $user ) {
			$res      = Plakette_Security::request( add_query_arg( 'author', $user->ID, home_url( '/' ) ) );
			$nicename = strtolower( $user->user_nicename );
			$shown    = false;
			if ( $res['ok'] && $res['status'] >= 300 && $res['status'] < 400 && ! empty( $res['headers']['location'] ) ) {
				$shown = false !== stripos( $res['headers']['location'], '/author/' . $nicename );
			} elseif ( $res['ok'] && 200 === $res['status'] ) {
				$shown = (bool) preg_match( '/class="[^"]*\bauthor-' . preg_quote( $nicename, '/' ) . '\b/i', $res['body'] );
			}
			if ( $shown && strtolower( $user->user_login ) === $nicename ) {
				$leaks[] = sprintf( __( '/?author=%1$d verrät den Anmeldenamen „%2$s“', 'plakette' ), $user->ID, $user->user_login );
			}
		}
		$rest = Plakette_Security::request( rest_url( 'wp/v2/users' ) );
		if ( $rest['ok'] && 200 === $rest['status'] ) {
			$list = json_decode( $rest['body'], true );
			if ( is_array( $list ) && $list ) {
				$slugs = array();
				foreach ( $list as $u ) {
					if ( is_array( $u ) && isset( $u['slug'] ) ) {
						$slugs[] = (string) $u['slug'];
					}
				}
				if ( $slugs ) {
					$leaks[] = sprintf( __( 'Die Schnittstelle /wp-json/wp/v2/users listet: %s', 'plakette' ), self::short_list( $slugs, 5 ) );
				}
			}
		}
		if ( ! $leaks ) {
			return $this->pass( __( 'Weder die Autoren-Adressen noch die Schnittstelle verraten Anmeldenamen.', 'plakette' ) );
		}
		return $this->fail( __( 'Benutzernamen sind von außen abrufbar.', 'plakette' ), array( 'notes' => $leaks ) );
	}

	public function fix( array $req, array &$undo ) {
		Plakette_Log::set_module( 'no_user_enum', true, $undo );
		return __( 'Benutzernamen sind für Besucher nicht mehr abrufbar.', 'plakette' );
	}
}

/* ====================================================================== automatic updates */

class Plakette_Check_Sec_Auto_Updates extends Plakette_Security_Check {
	public $id        = 'security_auto_updates';
	public $weight    = 4;
	public $title     = 'Plugins und Themes aktualisieren sich selbst';
	public $problem   = 'Automatische Updates sind aus';
	public $explain   = 'Sicherheitslücken in Plugins werden oft innerhalb von Stunden ausgenutzt. Mit automatischen Updates spielt WordPress neue Versionen zweimal täglich selbst ein – auch wenn du gerade im Urlaub bist. Die meisten Plugin-Updates laufen problemlos.';
	public $fix_label = 'Automatische Updates einschalten';
	public $fix_does  = 'Schaltet unter Plugins und Design › Themes die automatischen Updates für alle installierten Plugins und Themes ein. Einzelne kannst du dort jederzeit wieder ausnehmen. WordPress schickt dir nach jedem Update eine E-Mail.';
	public $risk      = 'review';

	/** @return array [ plugins without auto-update (file => name), themes (slug => name) ] */
	public static function missing() {
		self::admin_includes();
		$plugins = array();
		$themes  = array();
		if ( wp_is_auto_update_enabled_for_type( 'plugin' ) ) {
			$on = (array) get_site_option( 'auto_update_plugins', array() );
			foreach ( get_plugins() as $file => $data ) {
				if ( ! in_array( $file, $on, true ) ) {
					$plugins[ $file ] = wp_strip_all_tags( $data['Name'] );
				}
			}
		}
		if ( wp_is_auto_update_enabled_for_type( 'theme' ) ) {
			$on = (array) get_site_option( 'auto_update_themes', array() );
			foreach ( wp_get_themes() as $slug => $theme ) {
				if ( ! in_array( $slug, $on, true ) ) {
					$themes[ $slug ] = wp_strip_all_tags( $theme->get( 'Name' ) );
				}
			}
		}
		return array( $plugins, $themes );
	}

	public function run( Plakette_Context $ctx ) {
		if ( ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) || ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
			return $this->skip( __( 'Automatische Updates sind auf dieser Website grundsätzlich abgeschaltet (z. B. vom Hoster). Dann kümmert sich meist der Hoster oder eine Agentur um Updates.', 'plakette' ) );
		}
		self::admin_includes();
		if ( ! wp_is_auto_update_enabled_for_type( 'plugin' ) && ! wp_is_auto_update_enabled_for_type( 'theme' ) ) {
			return $this->skip( __( 'Die Funktion für automatische Updates ist von einem Plugin oder dem Hoster abgeschaltet.', 'plakette' ) );
		}
		list( $plugins, $themes ) = self::missing();
		if ( ! $plugins && ! $themes ) {
			return $this->pass( __( 'Alle Plugins und Themes aktualisieren sich automatisch.', 'plakette' ) );
		}
		$notes = array();
		if ( $plugins ) {
			$notes[] = sprintf( __( 'Plugins: %s', 'plakette' ), self::short_list( array_values( $plugins ), 8 ) );
		}
		if ( $themes ) {
			$notes[] = sprintf( __( 'Themes: %s', 'plakette' ), self::short_list( array_values( $themes ), 8 ) );
		}
		return $this->warn(
			sprintf( __( '%1$d Plugins und %2$d Themes werden nicht automatisch aktualisiert.', 'plakette' ), count( $plugins ), count( $themes ) ),
			array( 'notes' => $notes )
		);
	}

	public function fix( array $req, array &$undo ) {
		list( $plugins, $themes ) = self::missing();
		if ( $plugins ) {
			Plakette_Log::set_site_option( 'auto_update_plugins', array_values( array_unique( array_merge( (array) get_site_option( 'auto_update_plugins', array() ), array_keys( $plugins ) ) ) ), $undo );
		}
		if ( $themes ) {
			Plakette_Log::set_site_option( 'auto_update_themes', array_values( array_unique( array_merge( (array) get_site_option( 'auto_update_themes', array() ), array_map( 'strval', array_keys( $themes ) ) ) ) ), $undo );
		}
		return sprintf( __( 'Automatische Updates für %d Plugins und Themes eingeschaltet.', 'plakette' ), count( $plugins ) + count( $themes ) );
	}
}

/* ====================================================================== inactive plugins and themes */

class Plakette_Check_Sec_Inactive extends Plakette_Security_Check {
	public $id        = 'security_inactive';
	public $weight    = 4;
	public $title     = 'Keine ungenutzten Plugins und Themes';
	public $problem   = 'Ungenutzte Plugins oder Themes sind installiert';
	public $explain   = 'Auch deaktivierte Plugins und Themes liegen als Dateien auf dem Server und können über Sicherheitslücken angegriffen werden. Weil sie nicht genutzt werden, fallen fehlende Updates oft nicht auf. Was du nicht brauchst, solltest du löschen.';
	public $fix_label = 'Ausgewählte löschen';
	public $fix_does  = 'Löscht die ausgewählten Plugins und Themes vom Server (wie unter Plugins › Löschen). Manche Plugins entfernen dabei auch ihre Einstellungen. Das aktive Theme, sein Eltern-Theme und ein aktuelles Standard-Theme als Reserve bleiben immer erhalten. Gelöschtes lässt sich nur neu installieren.';
	public $risk      = 'review';
	public $kind      = 'items';
	public $undoable  = false;
	public $batch     = 10;

	/** Default themes from old to new. */
	const DEFAULT_THEMES = array( 'twentyten', 'twentyeleven', 'twentytwelve', 'twentythirteen', 'twentyfourteen', 'twentyfifteen', 'twentysixteen', 'twentyseventeen', 'twentynineteen', 'twentytwenty', 'twentytwentyone', 'twentytwentytwo', 'twentytwentythree', 'twentytwentyfour', 'twentytwentyfive', 'twentytwentysix', 'twentytwentyseven' );

	/** Themes that must stay: slug => true. */
	public static function keep_themes() {
		$keep   = array(
			get_stylesheet() => true,
			get_template()   => true,
		);
		$themes = wp_get_themes();
		if ( defined( 'WP_DEFAULT_THEME' ) && isset( $themes[ WP_DEFAULT_THEME ] ) ) {
			$keep[ WP_DEFAULT_THEME ] = true;
			return $keep;
		}
		$best  = '';
		$score = -1;
		foreach ( array_keys( $themes ) as $slug ) {
			if ( 0 !== strpos( (string) $slug, 'twenty' ) ) {
				continue;
			}
			$pos = array_search( $slug, self::DEFAULT_THEMES, true );
			$pos = false === $pos ? 100 : $pos; // Unknown "twenty…" theme: newer than this list.
			if ( $pos > $score ) {
				$score = $pos;
				$best  = $slug;
			}
		}
		if ( '' !== $best ) {
			$keep[ $best ] = true;
		}
		return $keep;
	}

	public static function unused() {
		self::admin_includes();
		$items = array();
		$self  = plugin_basename( PLAKETTE_FILE );
		foreach ( get_plugins() as $file => $data ) {
			if ( $file === $self || is_plugin_active( $file ) || is_plugin_active_for_network( $file ) ) {
				continue;
			}
			$items[] = array(
				'id'    => 'plugin:' . $file,
				'label' => wp_strip_all_tags( $data['Name'] ),
				'sub'   => sprintf( __( 'Plugin, deaktiviert · Version %s', 'plakette' ), $data['Version'] ),
			);
		}
		$keep = self::keep_themes();
		foreach ( wp_get_themes() as $slug => $theme ) {
			if ( isset( $keep[ $slug ] ) ) {
				continue;
			}
			$items[] = array(
				'id'    => 'theme:' . $slug,
				'label' => wp_strip_all_tags( $theme->get( 'Name' ) ),
				'sub'   => sprintf( __( 'Theme, nicht in Benutzung · Version %s', 'plakette' ), $theme->get( 'Version' ) ),
			);
		}
		return $items;
	}

	public function run( Plakette_Context $ctx ) {
		if ( is_multisite() ) {
			return $this->skip( __( 'In einem Multisite-Netzwerk können Plugins und Themes auf anderen Websites in Benutzung sein. Bitte in der Netzwerkverwaltung prüfen.', 'plakette' ) );
		}
		$items = self::unused();
		if ( ! $items ) {
			return $this->pass( __( 'Alle installierten Plugins und Themes werden benutzt.', 'plakette' ) );
		}
		$plugins = count( array_filter( $items, fn( $i ) => 0 === strpos( $i['id'], 'plugin:' ) ) );
		$extra   = array( 'items' => $items );
		if ( ! current_user_can( 'delete_plugins' ) || ! wp_is_file_mod_allowed( 'plakette_delete' ) ) {
			$extra['blocked'] = __( 'Auf dieser Website dürfen Plugins nicht über WordPress gelöscht werden. Lösche sie per FTP oder bitte deinen Hoster darum.', 'plakette' );
		} elseif ( ! self::can_write_files( WP_PLUGIN_DIR ) ) {
			$extra['blocked'] = __( 'WordPress darf seine Dateien nicht direkt löschen (es fragt nach FTP-Zugangsdaten). Lösche sie unter Plugins bzw. Design › Themes.', 'plakette' );
		}
		$detail = sprintf( __( '%1$d ungenutzte Plugins und %2$d ungenutzte Themes.', 'plakette' ), $plugins, count( $items ) - $plugins );
		return $plugins ? $this->fail( $detail, $extra ) : $this->warn( $detail, $extra );
	}

	public function fix( array $req, array &$undo ) {
		if ( is_multisite() || ! current_user_can( 'delete_plugins' ) || ! current_user_can( 'delete_themes' ) || ! wp_is_file_mod_allowed( 'plakette_delete' ) ) {
			return new WP_Error( 'plakette_delete', __( 'Löschen ist hier nicht erlaubt.', 'plakette' ) );
		}
		self::admin_includes();
		$allowed = array();
		foreach ( self::unused() as $item ) {
			$allowed[ $item['id'] ] = $item['label'];
		}
		$plugins = array();
		$themes  = array();
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( ! isset( $allowed[ $id ] ) ) {
				continue; // Only what is still unused right now.
			}
			if ( 0 === strpos( $id, 'plugin:' ) ) {
				$plugins[] = substr( $id, 7 );
			} else {
				$themes[] = substr( $id, 6 );
			}
		}
		if ( ! $plugins && ! $themes ) {
			return new WP_Error( 'plakette_delete', __( 'Nichts ausgewählt.', 'plakette' ) );
		}
		$done   = 0;
		$failed = array();
		if ( $plugins ) {
			$res = delete_plugins( $plugins );
			if ( true === $res ) {
				$done += count( $plugins );
			} else {
				$failed[] = is_wp_error( $res ) ? $res->get_error_message() : __( 'Plugins konnten nicht gelöscht werden.', 'plakette' );
			}
		}
		foreach ( $themes as $slug ) {
			$res = delete_theme( $slug );
			if ( true === $res ) {
				++$done;
			} else {
				$failed[] = sprintf( __( 'Theme %s konnte nicht gelöscht werden.', 'plakette' ), $allowed[ 'theme:' . $slug ] );
			}
		}
		if ( ! $done ) {
			return new WP_Error( 'plakette_delete', implode( ' ', $failed ) );
		}
		return sprintf( _n( '%d Eintrag gelöscht.', '%d Einträge gelöscht.', $done, 'plakette' ), $done ) . ( $failed ? ' ' . implode( ' ', $failed ) : '' );
	}
}

/* ====================================================================== XML-RPC */

class Plakette_Check_Sec_Xmlrpc extends Plakette_Security_Check {
	public $id        = 'security_xmlrpc';
	public $weight    = 4;
	public $title     = 'Alte Fernsteuerung (XML-RPC) ist aus';
	public $problem   = 'XML-RPC ist offen';
	public $explain   = 'Über die Datei xmlrpc.php konnten früher Programme Beiträge veröffentlichen. Heute nutzt das fast niemand mehr – Angreifer aber schon: Sie probieren darüber Hunderte Passwörter mit einer einzigen Anfrage aus oder missbrauchen deine Website für Angriffe auf andere (Pingback).';
	public $fix_label = 'XML-RPC abschalten';
	public $fix_does  = 'Die Datei xmlrpc.php antwortet nicht mehr, Pingbacks und der Hinweis darauf im Seitenkopf entfallen. Die WordPress-App für Smartphones verbindet sich danach über die neue Schnittstelle (REST) bzw. Anwendungspasswörter. Jetpack würde XML-RPC brauchen – Plakette bietet den Knopf dann nicht an.';

	public static function probe() {
		return Plakette_Security::request(
			site_url( 'xmlrpc.php' ),
			array(
				'method'  => 'POST',
				'headers' => array( 'Content-Type' => 'text/xml' ),
				'body'    => '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params></params></methodCall>',
			)
		);
	}

	public function run( Plakette_Context $ctx ) {
		if ( Plakette_Security::jetpack_active() ) {
			return $this->pass( __( 'XML-RPC bleibt an, weil Jetpack es für die Verbindung zu WordPress.com braucht.', 'plakette' ), array( 'kind' => 'none', 'notes' => array( __( 'Jetpack schützt die Anmeldung über XML-RPC selbst, wenn das Modul „Brute-Force-Schutz“ aktiv ist.', 'plakette' ) ) ) );
		}
		$res = self::probe();
		if ( ! $res['ok'] ) {
			return $this->skip( sprintf( __( 'xmlrpc.php konnte nicht abgerufen werden (%s).', 'plakette' ), $res['error'] ) );
		}
		if ( 200 !== $res['status'] || false === strpos( $res['body'], '<methodResponse' ) || false !== strpos( $res['body'], '<fault>' ) ) {
			return $this->pass( __( 'xmlrpc.php nimmt keine Befehle an.', 'plakette' ) );
		}
		$auth     = false !== strpos( $res['body'], 'wp.getUsersBlogs' );
		$pingback = false !== strpos( $res['body'], 'pingback.ping' );
		if ( ! $auth && ! $pingback ) {
			return $this->pass( __( 'xmlrpc.php antwortet, aber Anmeldung und Pingbacks sind darüber abgeschaltet.', 'plakette' ) );
		}
		$notes = array( sprintf( __( 'xmlrpc.php bietet %d Befehle an.', 'plakette' ), substr_count( $res['body'], '<string>' ) ) );
		if ( $auth ) {
			$notes[] = __( 'Anmeldung möglich – darüber lassen sich viele Passwörter auf einmal ausprobieren.', 'plakette' );
		}
		if ( $pingback ) {
			$notes[] = __( 'Pingbacks sind offen – deine Website kann für Angriffe auf andere missbraucht werden.', 'plakette' );
		}
		return $this->fail( __( 'xmlrpc.php nimmt Befehle von außen an.', 'plakette' ), array( 'notes' => $notes ) );
	}

	public function fix( array $req, array &$undo ) {
		if ( Plakette_Security::jetpack_active() ) {
			return new WP_Error( 'plakette_xmlrpc', __( 'Jetpack braucht XML-RPC – es bleibt deshalb an.', 'plakette' ) );
		}
		Plakette_Log::set_module( 'no_xmlrpc', true, $undo );
		return __( 'XML-RPC ist abgeschaltet.', 'plakette' );
	}
}

/* ====================================================================== directory listing */

class Plakette_Check_Sec_Directory_Listing extends Plakette_Security_Check {
	public $id        = 'security_directory_listing';
	public $weight    = 4;
	public $title     = 'Ordnerinhalte sind nicht einsehbar';
	public $problem   = 'Der Upload-Ordner zeigt eine Dateiliste';
	public $explain   = 'Ruft man den Ordner wp-content/uploads im Browser auf, zeigt der Server eine Liste aller hochgeladenen Dateien – auch solcher, die du nirgends verlinkt hast (z. B. Rechnungen, Entwürfe oder Sicherungen von Plugins).';
	public $fix_label = 'Dateilisten verbergen';
	public $fix_does  = 'Legt in den Upload-Ordner und seine Jahres- und Monatsordner eine leere Datei „index.php“. Der Server zeigt dann eine leere Seite statt der Liste. Deine Bilder bleiben wie gewohnt erreichbar.';

	/** URLs that are checked: url => folder path. */
	public static function targets() {
		$up = wp_get_upload_dir();
		if ( ! empty( $up['error'] ) || ! is_dir( $up['basedir'] ) ) {
			return array();
		}
		$base = untrailingslashit( $up['basedir'] );
		$url  = untrailingslashit( $up['baseurl'] );
		$out  = array( $url . '/' => $base );
		$years = array_filter( (array) glob( $base . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR ) );
		if ( $years ) {
			rsort( $years );
			$year                                   = basename( $years[0] );
			$out[ $url . '/' . $year . '/' ]        = $years[0];
			$months                                 = array_filter( (array) glob( $years[0] . '/[0-9][0-9]', GLOB_ONLYDIR ) );
			if ( $months ) {
				rsort( $months );
				$out[ $url . '/' . $year . '/' . basename( $months[0] ) . '/' ] = $months[0];
			}
		}
		return $out;
	}

	public static function is_listing( $body ) {
		return (bool) preg_match( '#<title>\s*Index of\b|<h1>\s*Index of\b|Directory listing for\b|<title>\s*Verzeichnis#i', $body );
	}

	/** Listed URLs. */
	public static function listed() {
		$out = array();
		foreach ( self::targets() as $url => $dir ) {
			$res = Plakette_Security::request( $url );
			if ( $res['ok'] && 200 === $res['status'] && self::is_listing( $res['body'] ) ) {
				$out[] = $url;
			}
		}
		return $out;
	}

	public function run( Plakette_Context $ctx ) {
		if ( ! self::targets() ) {
			return $this->skip( __( 'Der Upload-Ordner wurde nicht gefunden.', 'plakette' ) );
		}
		$listed = self::listed();
		if ( ! $listed ) {
			return $this->pass( __( 'Der Upload-Ordner zeigt keine Dateiliste.', 'plakette' ) );
		}
		$extra = array( 'notes' => $listed );
		$up    = wp_get_upload_dir();
		if ( ! wp_is_writable( $up['basedir'] ) ) {
			$extra['kind']   = 'letter';
			$extra['letter'] = $this->letter( __( 'Bitte schalten Sie für meine Website die Anzeige von Verzeichnisinhalten ab (Apache: „Options -Indexes“, nginx: „autoindex off“).', 'plakette' ) );
		}
		return $this->fail( sprintf( __( '%d Ordner zeigen ihre Dateiliste.', 'plakette' ), count( $listed ) ), $extra );
	}

	public function fix( array $req, array &$undo ) {
		$up = wp_get_upload_dir();
		if ( ! empty( $up['error'] ) || ! is_dir( $up['basedir'] ) ) {
			return new WP_Error( 'plakette_listing', __( 'Der Upload-Ordner wurde nicht gefunden.', 'plakette' ) );
		}
		$base = untrailingslashit( $up['basedir'] );
		$dirs = array( $base );
		foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $level1 ) {
			$dirs[] = $level1;
			foreach ( (array) glob( $level1 . '/*', GLOB_ONLYDIR ) as $level2 ) {
				$dirs[] = $level2;
			}
		}
		$made = 0;
		foreach ( array_slice( array_filter( $dirs ), 0, 2000 ) as $dir ) {
			if ( file_exists( $dir . '/index.php' ) || file_exists( $dir . '/index.html' ) || file_exists( $dir . '/index.htm' ) || ! wp_is_writable( $dir ) ) {
				continue;
			}
			if ( false !== file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$undo[] = array( 'file_delete', $dir . '/index.php' );
				++$made;
			}
		}
		$still = self::listed();
		if ( $still ) {
			return sprintf( __( '%1$d leere index.php angelegt, aber der Server zeigt weiterhin Dateilisten (%2$s). Bitte deinen Hoster, „Options -Indexes“ bzw. „autoindex off“ einzustellen.', 'plakette' ), $made, implode( ', ', $still ) );
		}
		return sprintf( __( '%d Ordner geschützt – es erscheint keine Dateiliste mehr.', 'plakette' ), $made );
	}
}

/* ====================================================================== version leak */

class Plakette_Check_Sec_Version_Leak extends Plakette_Security_Check {
	public $id        = 'security_version_leak';
	public $weight    = 2;
	public $title     = 'WordPress-Version wird nicht verraten';
	public $problem   = 'Die WordPress-Version ist öffentlich sichtbar';
	public $explain   = 'Im Quelltext jeder Seite steht, welche WordPress-Version läuft. Angreifer suchen damit automatisch nach Websites mit bekannten Lücken. Das Verstecken ersetzt keine Updates, macht dich aber für solche Suchläufe unsichtbarer.';
	public $fix_label = 'Versionsangabe entfernen';
	public $fix_does  = 'Entfernt die Angabe „generator“ aus dem Seitenkopf und den Feeds. Bei Apache-Servern sperrt Plakette zusätzlich den Abruf von readme.html, license.txt und wp-config-sample.php. Die Versionsnummern an CSS- und JavaScript-Dateien bleiben – sie sorgen dafür, dass Besucher nach Updates die neuen Dateien bekommen.';

	const MARKER = 'Plakette Versionsdateien';
	const FILES  = array( 'readme.html', 'license.txt', 'wp-config-sample.php' );

	/** Does readme.html deliver the exact version? */
	private static function readme_leaks() {
		global $wp_version;
		$res = Plakette_Security::request( site_url( 'readme.html' ), array( 'limit_response_size' => 20000 ) );
		if ( ! $res['ok'] || 200 !== $res['status'] ) {
			return array( false, false );
		}
		$exact = (bool) preg_match( '/Version\s+' . preg_quote( $wp_version, '/' ) . '\b/i', $res['body'] );
		return array( true, $exact );
	}

	public function run( Plakette_Context $ctx ) {
		global $wp_version;
		if ( ! $ctx->home_ok() ) {
			return $this->no_html( $ctx );
		}
		$problems = array();
		$notes    = array();
		$facts    = $ctx->facts();
		foreach ( isset( $facts['generator'] ) ? (array) $facts['generator'] : array() as $gen ) {
			if ( false !== stripos( $gen, 'WordPress' ) && false !== strpos( $gen, $wp_version ) ) {
				$problems[] = sprintf( __( 'Im Seitenkopf steht „%s“.', 'plakette' ), $gen );
			}
		}
		list( $readme, $exact ) = self::readme_leaks();
		if ( $exact ) {
			$problems[] = __( 'Die Datei readme.html nennt die genaue Version.', 'plakette' );
		} elseif ( $readme ) {
			$notes[] = __( 'Die Datei readme.html ist abrufbar (ohne Versionsnummer) – sie verrät nur, dass hier WordPress läuft.', 'plakette' );
		}
		if ( preg_match( '#/wp-includes/[^"\'\s]+\?ver=' . preg_quote( $wp_version, '#' ) . '\b#', $ctx->html() ) ) {
			$notes[] = __( 'An einigen WordPress-Dateien hängt „?ver=…“ mit der Versionsnummer. Das bleibt bewusst so, damit Browser nach Updates die neuen Dateien laden.', 'plakette' );
		}
		if ( ! $problems ) {
			return $this->pass( __( 'Die genaue Version ist nicht auf den ersten Blick sichtbar.', 'plakette' ), array( 'notes' => $notes ) );
		}
		return $this->warn( implode( ' ', $problems ), array( 'notes' => $notes ) );
	}

	public function fix( array $req, array &$undo ) {
		$done = array();
		if ( ! Plakette_Modules::on( 'no_generator' ) ) {
			Plakette_Log::set_module( 'no_generator', true, $undo );
			$done[] = __( 'Die Versionsangabe im Seitenkopf ist entfernt.', 'plakette' );
		}
		list( $readme, $exact ) = self::readme_leaks();
		if ( $readme && Plakette_Env::htaccess_writable() ) {
			$lines = array( '<FilesMatch "^(readme\.html|license\.txt|wp-config-sample\.php)$">', '	<IfModule mod_authz_core.c>', '		Require all denied', '	</IfModule>', '	<IfModule !mod_authz_core.c>', '		Order allow,deny', '		Deny from all', '	</IfModule>', '</FilesMatch>' );
			$op    = Plakette_Env::write_htaccess_block( self::MARKER, $lines );
			if ( ! is_wp_error( $op ) ) {
				$undo[] = $op;
				$check  = Plakette_Security::request( home_url( '/' ) );
				if ( ! $check['ok'] || $check['status'] >= 500 ) {
					Plakette_Log::apply_ops( array( $op ) );
					array_pop( $undo );
				} else {
					$done[] = __( 'readme.html und license.txt sind gesperrt.', 'plakette' );
				}
			}
		} elseif ( $exact ) {
			// No .htaccess possible: move the files into the protected backup folder (undo moves them back).
			foreach ( self::FILES as $name ) {
				$path = ABSPATH . $name;
				if ( ! file_exists( $path ) || ! wp_is_writable( $path ) ) {
					continue;
				}
				$backup = Plakette_Env::backup_dir() . '/' . $name . '-' . wp_generate_password( 8, false ) . '.bak';
				if ( @copy( $path, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					wp_delete_file( $path );
					$undo[] = array( 'file_restore', $path, $backup );
				}
			}
			$done[] = __( 'readme.html und license.txt sind in den Sicherungsordner verschoben (WordPress legt sie bei Updates neu an).', 'plakette' );
		}
		return $done ? implode( ' ', $done ) : __( 'Es war nichts zu tun.', 'plakette' );
	}
}

add_filter(
	'plakette_checks',
	function ( $classes ) {
		return array_merge(
			$classes,
			array(
				'Plakette_Check_Sec_Registration',
				'Plakette_Check_Sec_Core_Updates',
				'Plakette_Check_Sec_Plugin_Updates',
				'Plakette_Check_Sec_Salts',
				'Plakette_Check_Sec_Login_Limit',
				'Plakette_Check_Sec_Admin_User',
				'Plakette_Check_Sec_Debug',
				'Plakette_Check_Sec_Debug_Log',
				'Plakette_Check_Sec_File_Edit',
				'Plakette_Check_Sec_Backups',
				'Plakette_Check_Sec_Headers',
				'Plakette_Check_Sec_Mixed_Content',
				'Plakette_Check_Sec_User_Enum',
				'Plakette_Check_Sec_Auto_Updates',
				'Plakette_Check_Sec_Inactive',
				'Plakette_Check_Sec_Xmlrpc',
				'Plakette_Check_Sec_Directory_Listing',
				'Plakette_Check_Sec_Version_Leak',
			)
		);
	},
	20
);
