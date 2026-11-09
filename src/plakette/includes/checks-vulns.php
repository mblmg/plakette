<?php
/**
 * Check "security_vulnerabilities": known security holes (CVE) in WordPress, plugins and themes.
 * Data and matching: includes/class-vulns.php (Plakette_Vulns), loaded by includes/modules/vulns-boot.php.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Check_Sec_Vulnerabilities extends Plakette_Check {
	public $id        = 'security_vulnerabilities';
	public $area      = 'security';
	public $group     = 'Schutz vor Angriffen';
	public $weight    = 10;
	public $title     = 'Keine bekannten Sicherheitslücken';
	public $problem   = 'Software mit bekannter Sicherheitslücke ist installiert';
	public $explain   = 'Für viele Plugins, Themes und WordPress-Versionen sind Sicherheitslücken öffentlich bekannt – mit Nummer (CVE), Beschreibung und oft fertiger Anleitung zum Angreifen. Angreifer durchsuchen das Internet automatisch nach genau diesen Versionen. Auch deaktivierte Plugins zählen: Ihre Dateien liegen weiter auf dem Server. Plakette gleicht deine installierten Versionen zweimal am Tag mit der freien Datenbank WPVulnerability ab, die CVE, Wordfence, WPScan und Patchstack zusammenführt.';
	public $fix_label = 'Lücken schließen';
	public $fix_does  = 'Spielt für die ausgewählten Einträge das Update ein, das die Lücke schließt (wie unter Dashboard › Aktualisierungen). Gibt es noch kein Update, deaktiviert Plakette das betroffene Plugin – das kannst du im Protokoll rückgängig machen. Ein Update lässt sich nicht rückgängig machen; lege vorher eine Sicherung an.';
	public $risk      = 'review';
	public $kind      = 'items';
	public $undoable  = false;
	public $undo_note = 'Achtung: Ein Update lässt sich nicht rückgängig machen. Lege vorher eine Sicherung an, wenn du unsicher bist. Plugins, die Plakette deaktiviert hat, schaltest du im Protokoll mit „Rückgängig“ wieder ein.';
	public $batch     = 5;

	/** Can Plakette do something about this component here? */
	private static function actionable( array $f ) {
		if ( 'update' === $f['action'] ) {
			return true;
		}
		return 'plugin' === $f['type'] && $f['active'] && empty( $f['network'] );
	}

	public function run( Plakette_Context $ctx ) {
		if ( ! class_exists( 'Plakette_Vulns' ) ) {
			return $this->skip( __( 'Der Abgleich mit der Lücken-Datenbank ist nicht geladen.', 'plakette' ) );
		}
		// During an audit: fetch what is older than 20 hours (usually nothing – the cron keeps it fresh).
		$s = Plakette_Vulns::refresh( 30, 80 );
		if ( ! $s['checked'] ) {
			return $this->skip( sprintf( __( 'Die Lücken-Datenbank WPVulnerability war nicht erreichbar (%s). Plakette versucht es automatisch wieder.', 'plakette' ), $s['error'] ? $s['error'] : __( 'keine Antwort', 'plakette' ) ) );
		}
		$source = array( 'label' => __( 'Quelle: WPVulnerability (CVE, Wordfence, WPScan, Patchstack)', 'plakette' ), 'url' => Plakette_Vulns::SITE );
		$notes  = array();
		if ( $s['error'] ) {
			$notes[] = sprintf( __( 'Der letzte Abruf ist fehlgeschlagen (%1$s). Angezeigt wird der Stand von %2$s.', 'plakette' ), $s['error'], wp_date( get_option( 'date_format' ) . ' H:i', (int) ( $s['ok_at'] ? $s['ok_at'] : $s['oldest'] ) ) );
		}
		if ( $s['pending'] ) {
			$notes[] = sprintf( _n( '%d Komponente ist noch nicht abgeglichen – das holt Plakette in den nächsten Minuten nach.', '%d Komponenten sind noch nicht abgeglichen – das holt Plakette in den nächsten Minuten nach.', $s['pending'], 'plakette' ), $s['pending'] );
		}
		if ( ! empty( $s['external'] ) ) {
			$notes[] = sprintf( __( 'Nicht abgeglichen, weil sie nicht von wordpress.org stammen (eigene Update-Quelle): %s.', 'plakette' ), implode( ', ', array_slice( $s['external'], 0, 6 ) ) . ( count( $s['external'] ) > 6 ? ' …' : '' ) );
		}
		$stand = wp_date( get_option( 'date_format' ) . ' H:i', (int) ( $s['oldest'] ? $s['oldest'] : $s['time'] ) );
		if ( ! $s['found'] ) {
			return $this->pass(
				sprintf( __( 'Zuletzt abgeglichen: %1$s mit WPVulnerability (CVE-Datenbank) – %2$d Komponenten, keine bekannten Lücken.', 'plakette' ), $stand, $s['checked'] ),
				array( 'notes' => $notes, 'links' => array( $source ) )
			);
		}

		$items   = array();
		$links   = array();
		$vnotes  = array();
		$nofix   = 0;
		$actions = 0;
		foreach ( $s['found'] as $key => $f ) {
			$n      = count( $f['vulns'] );
			$worst  = $f['vulns'][0];
			$ids    = array();
			foreach ( $f['vulns'] as $v ) {
				$ids = array_merge( $ids, $v['cves'] ? $v['cves'] : array() );
			}
			$ids = array_values( array_unique( $ids ) );
			if ( 'update' !== $f['action'] ) {
				++$nofix;
			}
			if ( self::actionable( $f ) ) {
				++$actions;
			}
			$todo = 'update' === $f['action'] ? sprintf( __( 'Knopf: Update auf %s', 'plakette' ), $f['offer'] ) : ( self::actionable( $f ) ? __( 'Knopf: deaktivieren', 'plakette' ) : __( 'Knopf: nicht möglich – bitte selbst erledigen', 'plakette' ) );
			$items[] = array(
				'id'     => $key,
				'label'  => sprintf( '%1$s %2$s', $f['name'], $f['version'] ),
				'sub'    => sprintf(
					/* translators: 1: type, 2: count, 3: severity, 4: ids, 5: advice, 6: what the button does */
					__( '%1$s · %2$s, höchste Stufe: %3$s · %4$s · %5$s %6$s.', 'plakette' ),
					Plakette_Vulns::type_label( $f ),
					sprintf( _n( '%d bekannte Lücke', '%d bekannte Lücken', $n, 'plakette' ), $n ),
					Plakette_Vulns::sev_label( $f['sev'] ) . ( null !== $f['score'] ? ' (CVSS ' . number_format_i18n( (float) $f['score'], 1 ) . ')' : '' ),
					$ids ? implode( ', ', array_slice( $ids, 0, 3 ) ) . ( count( $ids ) > 3 ? sprintf( __( ' und %d weitere', 'plakette' ), count( $ids ) - 3 ) : '' ) : __( 'ohne CVE-Nummer', 'plakette' ),
					Plakette_Vulns::advice( $f ),
					$todo
				),
				'link'   => Plakette_Vulns::vuln_url( $worst ),
				'action' => self::actionable( $f ) ? $f['action'] : 'none',
				'nofix'  => 'update' !== $f['action'],
			);
			foreach ( array_slice( $f['vulns'], 0, 3 ) as $v ) {
				$vnotes[] = $f['name'] . ' ' . $f['version'] . ' – ' . Plakette_Vulns::vuln_line( $v );
				foreach ( $v['cves'] as $cve ) {
					$links[ $cve ] = array( 'label' => $cve . ' (cve.org)', 'url' => Plakette_Vulns::cve_url( $cve ) );
				}
				if ( ! $v['cves'] && $v['ref'] ) {
					$links[ $v['ref'][1] ] = array( 'label' => $f['name'] . ' (' . $v['ref'][0] . ')', 'url' => $v['ref'][1] );
				}
			}
			if ( $n > 3 ) {
				$vnotes[] = sprintf( __( '%1$s: %2$d weitere Lücken (alle mit demselben Update behoben bzw. offen).', 'plakette' ), $f['name'], $n - 3 );
			}
		}
		$count  = count( $items );
		$detail = sprintf( _n( '%d Komponente hat eine öffentlich bekannte Sicherheitslücke', '%d Komponenten haben öffentlich bekannte Sicherheitslücken', $count, 'plakette' ), $count );
		if ( $nofix ) {
			$detail .= ' ' . sprintf( _n( '(%d davon ohne Update über WordPress)', '(%d davon ohne Update über WordPress)', $nofix, 'plakette' ), $nofix );
		}
		$detail .= '. ' . sprintf( __( 'Stand: %s, Quelle: WPVulnerability.', 'plakette' ), $stand );
		$extra = array(
			'items' => $items,
			'notes' => array_merge( array_slice( $vnotes, 0, 15 ), count( $vnotes ) > 15 ? array( sprintf( __( '… und %d weitere Zeilen.', 'plakette' ), count( $vnotes ) - 15 ) ) : array(), $notes ),
			'links' => array_merge( array_slice( array_values( $links ), 0, 8 ), array( $source ) ),
		);
		if ( ! $actions ) {
			$extra['kind'] = 'none';
		}
		return $this->fail( $detail, $extra );
	}

	public function fix( array $req, array &$undo ) {
		if ( ! class_exists( 'Plakette_Vulns' ) ) {
			return new WP_Error( 'plakette_vulns', __( 'Der Abgleich mit der Lücken-Datenbank ist nicht geladen.', 'plakette' ) );
		}
		Plakette_Vulns::admin_includes();
		$found = Plakette_Vulns::refresh( 0 )['found']; // current versions, no network
		$want  = array();
		foreach ( (array) ( isset( $req['items'] ) ? $req['items'] : array() ) as $item ) {
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( isset( $found[ $id ] ) ) {
				$want[ $id ] = $found[ $id ];
			}
		}
		if ( ! $want ) {
			return new WP_Error( 'plakette_vulns', __( 'Für die Auswahl ist keine Lücke mehr bekannt – vermutlich ist sie schon behoben.', 'plakette' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$self    = plugin_basename( PLAKETTE_FILE );
		$done    = array();
		$failed  = array();
		$left    = array();
		$plugins = array();
		$themes  = array();
		$core    = null;
		$may     = function ( $cap ) {
			return current_user_can( 'update_' . $cap ) && wp_is_file_mod_allowed( 'capability_update_' . $cap );
		};
		$direct  = 'direct' === get_filesystem_method( array(), WP_PLUGIN_DIR, true );
		foreach ( $want as $f ) {
			if ( 'update' === $f['action'] ) {
				$cap = 'core' === $f['type'] ? 'core' : $f['type'] . 's';
				if ( ! $may( $cap ) || ! $direct ) {
					$failed[] = sprintf( __( '%s: WordPress darf hier keine Updates einspielen – bitte unter Dashboard › Aktualisierungen erledigen.', 'plakette' ), $f['name'] );
				} elseif ( 'plugin' === $f['type'] && $f['file'] !== $self ) {
					$plugins[ $f['file'] ] = $f;
				} elseif ( 'theme' === $f['type'] ) {
					$themes[ $f['file'] ] = $f;
				} elseif ( 'core' === $f['type'] ) {
					$core = $f;
				}
				continue;
			}
			if ( 'plugin' === $f['type'] && $f['active'] && empty( $f['network'] ) && $f['file'] !== $self ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					$failed[] = sprintf( __( '%s: Dir fehlt die Berechtigung, Plugins zu deaktivieren.', 'plakette' ), $f['name'] );
					continue;
				}
				deactivate_plugins( $f['file'], false, false );
				if ( is_plugin_active( $f['file'] ) ) {
					$failed[] = sprintf( __( '%s ließ sich nicht deaktivieren.', 'plakette' ), $f['name'] );
					continue;
				}
				$undo[] = array( 'activate_plugin', $f['file'] );
				$done[] = sprintf( __( '%s deaktiviert (es gibt noch kein Update, das die Lücke schließt).', 'plakette' ), $f['name'] );
				continue;
			}
			$left[] = $f['name'] . ': ' . Plakette_Vulns::advice( $f );
		}

		if ( $plugins ) {
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$results  = (array) $upgrader->bulk_upgrade( array_keys( $plugins ) );
			wp_clean_plugins_cache();
			$now = get_plugins();
			foreach ( $plugins as $file => $f ) {
				$version = isset( $now[ $file ]['Version'] ) ? $now[ $file ]['Version'] : '';
				// bulk_upgrade() also reports "true" when nothing was installed – trust only the new version number.
				if ( '' !== $version && Plakette_Vulns::cmp( $version, $f['version'], 'gt' ) ) {
					$done[] = sprintf( __( '%1$s auf %2$s aktualisiert.', 'plakette' ), $f['name'], $version );
				} else {
					$failed[] = sprintf( __( '%1$s: Update fehlgeschlagen (%2$s).', 'plakette' ), $f['name'], self::reason( isset( $results[ $file ] ) ? $results[ $file ] : null, $upgrader ) );
				}
			}
		}
		if ( $themes ) {
			$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
			$results  = (array) $upgrader->bulk_upgrade( array_keys( $themes ) );
			wp_clean_themes_cache();
			foreach ( $themes as $slug => $f ) {
				$version = (string) wp_get_theme( $slug )->get( 'Version' );
				if ( '' !== $version && Plakette_Vulns::cmp( $version, $f['version'], 'gt' ) ) {
					$done[] = sprintf( __( '%1$s auf %2$s aktualisiert.', 'plakette' ), $f['name'], $version );
				} else {
					$failed[] = sprintf( __( '%1$s: Update fehlgeschlagen (%2$s).', 'plakette' ), $f['name'], self::reason( isset( $results[ $slug ] ) ? $results[ $slug ] : null, $upgrader ) );
				}
			}
		}
		if ( $core ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
			$offer = find_core_update( $core['offer'], get_locale() );
			if ( ! $offer ) {
				$offer = find_core_update( $core['offer'], 'en_US' );
			}
			if ( ! $offer ) {
				$failed[] = sprintf( __( 'WordPress %s wird gerade nicht angeboten. Bitte unter Dashboard › Aktualisierungen nachsehen.', 'plakette' ), $core['offer'] );
			} else {
				$upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
				$result   = $upgrader->upgrade( $offer );
				if ( is_wp_error( $result ) || ! $result ) {
					$failed[] = sprintf( __( 'WordPress: Update fehlgeschlagen (%s). Deine Website läuft mit der bisherigen Version weiter.', 'plakette' ), self::reason( $result, $upgrader ) );
				} else {
					delete_site_transient( 'update_core' );
					$done[] = sprintf( __( 'WordPress auf %s aktualisiert.', 'plakette' ), is_string( $result ) ? $result : $core['offer'] );
				}
			}
		}

		Plakette_Vulns::refresh( 0 );
		if ( ! $done ) {
			return new WP_Error( 'plakette_vulns', implode( ' ', array_merge( $failed, $left ) ) );
		}
		$msg = implode( ' ', $done );
		if ( $failed ) {
			$msg .= ' ' . __( 'Nicht geklappt:', 'plakette' ) . ' ' . implode( ' ', $failed );
		}
		if ( $left ) {
			$msg .= ' ' . __( 'Bitte selbst erledigen:', 'plakette' ) . ' ' . implode( ' ', $left );
		}
		return $msg;
	}

	/** Human readable reason of a failed upgrade. */
	private static function reason( $result, $upgrader ) {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		if ( isset( $upgrader->skin ) && is_wp_error( $upgrader->skin->result ) ) {
			return $upgrader->skin->result->get_error_message();
		}
		if ( isset( $upgrader->skin ) && method_exists( $upgrader->skin, 'get_upgrade_messages' ) ) {
			$m = array_filter( array_map( 'wp_strip_all_tags', (array) $upgrader->skin->get_upgrade_messages() ) );
			if ( $m ) {
				return end( $m );
			}
		}
		return __( 'kein Download möglich', 'plakette' );
	}
}

add_filter(
	'plakette_checks',
	function ( $c ) {
		return array_merge( $c, array( 'Plakette_Check_Sec_Vulnerabilities' ) );
	},
	20
);
