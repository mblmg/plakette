<?php
/**
 * Dashboard widget „Plakette“, the vulnerability warnings on every admin screen (notice + red menu bubble)
 * and the optional weekly automatic audit (setting dashboard › auto_audit).
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Dashboard {

	const AUDIT_CRON = 'plakette_weekly_audit';

	public static function boot() {
		add_filter( 'plakette_settings_sections', array( __CLASS__, 'settings' ) );
		add_action( self::AUDIT_CRON, array( __CLASS__, 'auto_audit' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_menu', array( __CLASS__, 'bubble' ), 99 );
		add_action( 'admin_post_plakette_vulns_dismiss', array( __CLASS__, 'dismiss' ) );
	}

	/* ------------------------------------------------------------------ weekly audit */

	public static function schedule() {
		if ( ! wp_next_scheduled( self::AUDIT_CRON ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::AUDIT_CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::AUDIT_CRON );
	}

	/** Runs the full audit in WP-Cron (makes loopback requests to the own site) so the widget stays fresh. */
	public static function auto_audit() {
		if ( ! Plakette_Settings::get( 'dashboard', 'auto_audit' ) ) {
			return;
		}
		$report = get_option( Plakette_Auditor::OPTION );
		if ( is_array( $report ) && ! empty( $report['time'] ) && time() - (int) $report['time'] < 3 * DAY_IN_SECONDS ) {
			return; // checked by hand recently
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// Checks ask current_user_can() to tell whether a fix is possible – run them as an administrator (read only).
		$before = get_current_user_id();
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) );
		if ( $admins ) {
			wp_set_current_user( (int) $admins[0] );
		}
		try {
			Plakette_Auditor::run();
		} finally {
			wp_set_current_user( $before );
		}
	}

	public static function settings( $sections ) {
		$sections['dashboard'] = array(
			'title'  => __( 'Übersicht im Dashboard', 'plakette' ),
			'desc'   => __( 'Das Kästchen „Plakette“ auf der Startseite des Admin-Bereichs zeigt Ergebnis, wichtigste Mängel und Sicherheitslücken auf einen Blick.', 'plakette' ),
			'fields' => array(
				array(
					'key'     => 'auto_audit',
					'label'   => __( 'Einmal pro Woche automatisch prüfen', 'plakette' ),
					'type'    => 'toggle',
					'default' => true,
					'help'    => __( 'Plakette prüft die Website dann im Hintergrund (ändert dabei nichts), damit die Übersicht aktuell bleibt.', 'plakette' ),
				),
			),
		);
		return $sections;
	}

	/* ------------------------------------------------------------------ vulnerability warnings */

	private static function can() {
		return current_user_can( 'manage_options' );
	}

	private static function report_url( $row = '' ) {
		return admin_url( 'admin.php?page=plakette' . ( $row ? '#plk-row-' . $row : '' ) );
	}

	public static function bubble() {
		global $menu;
		$n = count( Plakette_Vulns::found() );
		if ( ! $n || ! self::can() || ! is_array( $menu ) ) {
			return;
		}
		foreach ( $menu as $i => $item ) {
			if ( isset( $item[2] ) && 'plakette' === $item[2] ) {
				/* translators: %d: number of components */
				$sr            = sprintf( _n( '%d Komponente mit Sicherheitslücke', '%d Komponenten mit Sicherheitslücken', $n, 'plakette' ), $n );
				$menu[ $i ][0] .= ' <span class="awaiting-mod count-' . $n . '"><span class="pending-count" aria-hidden="true">' . $n . '</span></span><span class="screen-reader-text">' . esc_html( $sr ) . '</span>';
			}
		}
	}

	public static function notice() {
		if ( ! self::can() ) {
			return;
		}
		$found = Plakette_Vulns::found();
		$sig   = Plakette_Vulns::signature();
		if ( ! $found || get_user_meta( get_current_user_id(), Plakette_Vulns::DISMISS, true ) === $sig ) {
			return;
		}
		$n    = count( $found );
		$hide = wp_nonce_url( admin_url( 'admin-post.php?action=plakette_vulns_dismiss' ), 'plakette_vulns_dismiss' );
		echo '<div class="notice notice-error plk-vuln-notice"><p><strong>' . esc_html( sprintf( _n( 'Plakette: %d Komponente deiner Website hat eine öffentlich bekannte Sicherheitslücke.', 'Plakette: %d Komponenten deiner Website haben öffentlich bekannte Sicherheitslücken.', $n, 'plakette' ), $n ) ) . '</strong></p><ul style="list-style:disc;margin-left:1.5em">';
		foreach ( array_slice( $found, 0, 3 ) as $f ) {
			$ids = array();
			foreach ( $f['vulns'] as $v ) {
				$ids = array_merge( $ids, $v['cves'] );
			}
			$ids = array_slice( array_unique( $ids ), 0, 2 );
			echo '<li>' . esc_html( sprintf( '%1$s %2$s (%3$s, %4$s)%5$s – ', $f['name'], $f['version'], Plakette_Vulns::type_label( $f ), Plakette_Vulns::sev_label( $f['sev'] ), $ids ? ': ' . implode( ', ', $ids ) : '' ) . Plakette_Vulns::advice( $f ) ) . '</li>';
		}
		if ( $n > 3 ) {
			echo '<li>' . esc_html( sprintf( __( 'und %d weitere', 'plakette' ), $n - 3 ) ) . '</li>';
		}
		echo '</ul><p><a class="button button-primary" href="' . esc_url( self::report_url( 'security_vulnerabilities' ) ) . '">' . esc_html__( 'Jetzt ansehen und beheben', 'plakette' ) . '</a> <a class="button-link" href="' . esc_url( $hide ) . '">' . esc_html__( 'Ausblenden, bis sich etwas ändert', 'plakette' ) . '</a></p></div>';
	}

	public static function dismiss() {
		check_admin_referer( 'plakette_vulns_dismiss' );
		if ( self::can() ) {
			update_user_meta( get_current_user_id(), Plakette_Vulns::DISMISS, Plakette_Vulns::signature() );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/* ------------------------------------------------------------------ widget */

	public static function register() {
		if ( self::can() ) {
			wp_add_dashboard_widget( 'plakette_dashboard', __( 'Plakette', 'plakette' ), array( __CLASS__, 'render' ), null, null, 'normal', 'high' );
		}
	}

	public static function assets( $hook ) {
		if ( 'index.php' === $hook && self::can() ) {
			wp_enqueue_style( 'plakette-dashboard', PLAKETTE_URL . 'admin/css/dashboard.css', array(), PLAKETTE_VERSION . '-' . filemtime( PLAKETTE_DIR . 'admin/css/dashboard.css' ) );
		}
	}

	/** Previous history entry (before the stored report). */
	private static function previous( array $report ) {
		$prev = null;
		foreach ( (array) get_option( 'plakette_history', array() ) as $h ) {
			if ( is_array( $h ) && isset( $h['t'] ) && $h['t'] < $report['time'] ) {
				$prev = $h;
			}
		}
		return $prev;
	}

	private static function trend( $now, $prev ) {
		if ( null === $prev ) {
			return '';
		}
		$d = (int) $now - (int) $prev;
		if ( 0 === $d ) {
			return '<span class="plk-dash-trend is-same">' . esc_html__( '± 0', 'plakette' ) . '<span class="screen-reader-text"> ' . esc_html__( 'gegenüber der letzten Prüfung', 'plakette' ) . '</span></span>';
		}
		$label = $d > 0 ? sprintf( __( '▲ +%d', 'plakette' ), $d ) : sprintf( __( '▼ %d', 'plakette' ), $d );
		$sr    = $d > 0 ? __( 'besser als bei der letzten Prüfung', 'plakette' ) : __( 'schlechter als bei der letzten Prüfung', 'plakette' );
		return '<span class="plk-dash-trend ' . ( $d > 0 ? 'is-up' : 'is-down' ) . '"><span aria-hidden="true">' . esc_html( $label ) . '</span><span class="screen-reader-text">' . esc_html( sprintf( '%+d, %s', $d, $sr ) ) . '</span></span>';
	}

	private static function ring( $score ) {
		$c     = 2 * M_PI * 42;
		$fill  = max( 0, min( 100, (int) $score ) ) / 100 * $c;
		$class = $score >= 90 ? 'is-good' : ( $score >= 60 ? 'is-mid' : 'is-bad' );
		return '<svg class="plk-dash-ring ' . $class . '" viewBox="0 0 100 100" width="104" height="104" role="img" aria-label="' . esc_attr( sprintf( __( 'Gesamtergebnis: %d von 100 Punkten', 'plakette' ), $score ) ) . '">'
			. '<circle cx="50" cy="50" r="42" class="plk-dash-ring-track"/>'
			. '<circle cx="50" cy="50" r="42" class="plk-dash-ring-fill" stroke-dasharray="' . round( $fill, 2 ) . ' ' . round( $c, 2 ) . '" transform="rotate(-90 50 50)"/>'
			. '<text x="50" y="54" text-anchor="middle" class="plk-dash-ring-num">' . (int) $score . '</text>'
			. '<text x="50" y="70" text-anchor="middle" class="plk-dash-ring-unit">' . esc_html__( 'von 100', 'plakette' ) . '</text></svg>';
	}

	public static function render() {
		$report = get_option( Plakette_Auditor::OPTION );
		$found  = Plakette_Vulns::found();
		$vstate = Plakette_Vulns::state();
		echo '<div class="plk-dash">';

		// Vulnerabilities first – the most urgent information.
		if ( $found ) {
			$n = count( $found );
			echo '<div class="plk-dash-alert"><h3><span aria-hidden="true">⚠ </span>' . esc_html( sprintf( _n( 'Sicherheitslücke in %d Komponente', 'Sicherheitslücken in %d Komponenten', $n, 'plakette' ), $n ) ) . '</h3><ul>';
			foreach ( array_slice( $found, 0, 3 ) as $f ) {
				echo '<li><strong>' . esc_html( $f['name'] . ' ' . $f['version'] ) . '</strong> (' . esc_html( Plakette_Vulns::sev_label( $f['sev'] ) ) . ') – ' . esc_html( Plakette_Vulns::advice( $f ) ) . '</li>';
			}
			echo '</ul><p><a class="button button-primary" href="' . esc_url( self::report_url( 'security_vulnerabilities' ) ) . '">' . esc_html__( 'Lücken ansehen und schließen', 'plakette' ) . '</a></p></div>';
		}

		if ( ! is_array( $report ) || empty( $report['scores'] ) ) {
			echo '<div class="plk-dash-empty"><h3>' . esc_html__( 'Noch keine Hauptuntersuchung', 'plakette' ) . '</h3><p>' . esc_html__( 'Plakette prüft Datenschutz, Sicherheit, Geschwindigkeit und SEO deiner Website und erklärt jeden Mangel in einfachen Worten. Die Prüfung ändert nichts und dauert etwa eine halbe Minute.', 'plakette' ) . '</p><p><a class="button button-primary" href="' . esc_url( self::report_url() ) . '">' . esc_html__( 'Website jetzt prüfen', 'plakette' ) . '</a></p></div>';
			self::vuln_status( $found, $vstate );
			echo '</div>';
			return;
		}

		$scores = $report['scores'];
		$prev   = self::previous( $report );
		echo '<div class="plk-dash-top">' . self::ring( (int) $scores['total'] ) . '<div class="plk-dash-areas"><p class="plk-dash-total">' . esc_html__( 'Gesamt', 'plakette' ) . ' <strong>' . (int) $scores['total'] . '</strong> ' . self::trend( $scores['total'], $prev ? ( $prev['total'] ?? null ) : null ) . '</p><ul>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
		foreach ( Plakette_Auditor::areas() as $key => $label ) {
			if ( ! isset( $scores[ $key ] ) ) {
				continue;
			}
			$v = (int) $scores[ $key ];
			echo '<li><span class="plk-dash-area">' . esc_html( $label ) . '</span><span class="plk-dash-bar" aria-hidden="true"><span style="width:' . $v . '%"></span></span><span class="plk-dash-val">' . $v . '</span>' . self::trend( $v, $prev && isset( $prev[ $key ] ) ? $prev[ $key ] : null ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></div></div>';

		// Top 3 open problems: major first, then minor, then notes; heavier first.
		$open = array();
		foreach ( (array) $report['checks'] as $c ) {
			$st = $c['status'] ?? '';
			if ( 'fail' === $st || 'warn' === $st ) {
				$rank   = 'fail' === $st ? ( (int) $c['weight'] >= 6 ? 0 : 1 ) : 2;
				$open[] = array( $rank, -(int) $c['weight'], $c );
			}
		}
		usort( $open, fn( $a, $b ) => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );
		$labels = array( __( 'Erheblich', 'plakette' ), __( 'Gering', 'plakette' ), __( 'Hinweis', 'plakette' ) );
		$cls    = array( 'is-major', 'is-minor', 'is-note' );
		echo '<h3 class="plk-dash-h">' . esc_html__( 'Wichtigste offene Punkte', 'plakette' ) . '</h3>';
		if ( $open ) {
			echo '<ol class="plk-dash-list">';
			foreach ( array_slice( $open, 0, 3 ) as $o ) {
				echo '<li><span class="plk-dash-tag ' . esc_attr( $cls[ $o[0] ] ) . '">' . esc_html( $labels[ $o[0] ] ) . '</span> <a href="' . esc_url( self::report_url( $o[2]['id'] ) ) . '">' . esc_html( $o[2]['problem'] ) . '</a></li>';
			}
			echo '</ol>';
			if ( count( $open ) > 3 ) {
				echo '<p class="plk-dash-more">' . esc_html( sprintf( __( 'Insgesamt %d offene Punkte.', 'plakette' ), count( $open ) ) ) . '</p>';
			}
		} else {
			echo '<p>' . esc_html__( 'Keine offenen Mängel – sehr gut!', 'plakette' ) . '</p>';
		}

		self::vuln_status( $found, $vstate );
		self::stats();

		echo '<p class="plk-dash-foot"><span>' . esc_html( sprintf( __( 'Letzte Prüfung: %1$s (%2$s)', 'plakette' ), wp_date( get_option( 'date_format' ) . ' H:i', (int) $report['time'] ), Plakette_Vulns::age( $report['time'] ) ) ) . '</span> <a class="button" href="' . esc_url( self::report_url() ) . '">' . esc_html__( 'Neu prüfen', 'plakette' ) . '</a></p>';
		echo '</div>';
	}

	private static function vuln_status( array $found, $state ) {
		echo '<h3 class="plk-dash-h">' . esc_html__( 'Sicherheitslücken', 'plakette' ) . '</h3><p class="plk-dash-vuln">';
		if ( ! $state ) {
			esc_html_e( 'Noch nicht abgeglichen – das passiert automatisch in den nächsten Stunden.', 'plakette' );
		} elseif ( $found ) {
			echo '<span class="plk-dash-tag is-major">' . esc_html__( 'Achtung', 'plakette' ) . '</span> ' . esc_html( sprintf( _n( '%d Komponente betroffen (siehe oben).', '%d Komponenten betroffen (siehe oben).', count( $found ), 'plakette' ), count( $found ) ) );
		} else {
			echo '<span class="plk-dash-tag is-pass">' . esc_html__( 'OK', 'plakette' ) . '</span> ' . esc_html( sprintf( __( 'Keine bekannten Lücken in %1$d Komponenten (abgeglichen %2$s mit WPVulnerability).', 'plakette' ), (int) $state['checked'], Plakette_Vulns::age( $state['time'] ) ) );
		}
		echo '</p>';
	}

	/** Page views of the last 7 days from the cookieless statistics module, if it is there and switched on. */
	private static function stats() {
		if ( ! class_exists( 'Plakette_Stats' ) || ! method_exists( 'Plakette_Stats', 'summary' ) ) {
			return;
		}
		$s = Plakette_Stats::summary( 7 );
		if ( ! is_array( $s ) || empty( $s['daily'] ) ) {
			return;
		}
		$max = max( 1, max( $s['daily'] ) );
		echo '<h3 class="plk-dash-h">' . esc_html( sprintf( __( 'Besucher: %s Seitenaufrufe in 7 Tagen', 'plakette' ), number_format_i18n( (int) $s['total'] ) ) ) . '</h3><ul class="plk-dash-days">';
		foreach ( $s['daily'] as $day => $n ) {
			$t = strtotime( $day . ' 12:00:00' );
			echo '<li><span class="plk-dash-day-n">' . esc_html( number_format_i18n( (int) $n ) ) . '</span><span class="plk-dash-day-bar" aria-hidden="true" style="height:' . (int) round( 40 * $n / $max ) . 'px"></span><span class="plk-dash-day-l">' . esc_html( date_i18n( 'D', $t ) ) . '</span></li>';
		}
		echo '</ul>';
	}
}
