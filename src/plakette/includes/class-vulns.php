<?php
/**
 * Known vulnerabilities (CVE) of WordPress core, every installed plugin (active and inactive) and every theme.
 *
 * Source: WPVulnerability (https://www.wpvulnerability.com/, API https://www.wpvulnerability.net/) – free, no key,
 * one request per plugin/theme slug, data under CC0. It aggregates CVE/NVD, EUVD, JVN, Patchstack, WPScan and Wordfence.
 *   GET /plugin/{slug}/  /theme/{slug}/   → data.vulnerability[] with operator {min_version, min_operator,
 *                                          max_version, max_operator, unfixed, closed}, source[] (CVE ids + links), impact (CVSS)
 *   GET /core/{version}/                  → data.vulnerability[] already matched to exactly this version (no operator)
 * Operators follow PHP version_compare() (lt, le, eq, ne, gt, ge); a missing bound means "no bound";
 * unfixed = "1" means no fixed release exists (the range still applies; no bounds + unfixed = all versions).
 *
 * Storage (all autoload = no):
 *   plakette_vulns_feed   cache per component: "plugin:slug" | "theme:slug" | "core:6.8.3" => t, v, name, list[]
 *   plakette_vulns        last evaluation: time, checked, total, pending, error, found[key => component]
 *   plakette_vulns_mailed keys "component|vuln" that were already sent by e-mail
 *
 * The matcher (affects(), fix_version(), compact()) is pure PHP without WordPress – tests/vulns-matcher.php runs it via PHP-WASM.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PLAKETTE_VULNS_TEST' ) ) {
	exit;
}

class Plakette_Vulns {

	const API     = 'https://www.wpvulnerability.net/';
	const SITE    = 'https://www.wpvulnerability.com/';
	const SOURCE  = 'WPVulnerability';
	const OPTION  = 'plakette_vulns';
	const FEED    = 'plakette_vulns_feed';
	const MAILED  = 'plakette_vulns_mailed';
	const DISMISS = 'plakette_vulns_dismissed';
	const CRON    = 'plakette_vulns_cron';
	const NEXT    = 'plakette_vulns_next';
	const TTL     = 72000;  // 20 h: with two runs a day every component is compared at least once a day.
	const BATCH   = 30;     // components per run
	const TIMEOUT = 8;      // seconds per request
	const OPS     = array( 'lt', 'le', 'eq', 'ne', 'gt', 'ge' );

	/* ================================================================== pure matcher (no WordPress) */

	/**
	 * Normalises a version for comparison: trims, drops a leading "v", and makes "6.0" and "6.0.0" equal by padding the
	 * shorter of two purely numeric versions with ".0" (version_compare() would call "6.0" < "6.0.0").
	 *
	 * @return string[] [a, b]
	 */
	public static function pad( $a, $b ) {
		$a = ltrim( trim( (string) $a ), 'vV' );
		$b = ltrim( trim( (string) $b ), 'vV' );
		if ( preg_match( '/^\d+(\.\d+)*$/', $a ) && preg_match( '/^\d+(\.\d+)*$/', $b ) ) {
			$na = substr_count( $a, '.' );
			$nb = substr_count( $b, '.' );
			if ( $na < $nb ) {
				$a .= str_repeat( '.0', $nb - $na );
			} elseif ( $nb < $na ) {
				$b .= str_repeat( '.0', $na - $nb );
			}
		}
		return array( $a, $b );
	}

	/** version_compare() with an operator of the feed (lt, le, eq, ne, gt, ge). */
	public static function cmp( $version, $other, $op ) {
		list( $a, $b ) = self::pad( $version, $other );
		return (bool) version_compare( $a, $b, $op );
	}

	/** @return array operator with normalised types (null for missing parts, unfixed as bool). */
	public static function operator( $op ) {
		$op  = is_array( $op ) ? $op : array();
		$get = function ( $k ) use ( $op ) {
			return isset( $op[ $k ] ) && '' !== trim( (string) $op[ $k ] ) ? trim( (string) $op[ $k ] ) : null;
		};
		$min_o = $get( 'min_operator' );
		$max_o = $get( 'max_operator' );
		return array(
			'min_version'  => $get( 'min_version' ),
			'min_operator' => null === $min_o ? null : strtolower( $min_o ),
			'max_version'  => $get( 'max_version' ),
			'max_operator' => null === $max_o ? null : strtolower( $max_o ),
			'unfixed'      => isset( $op['unfixed'] ) && '1' === (string) $op['unfixed'],
		);
	}

	/**
	 * Is $version inside the affected range? Exactly the feed's semantics; never guesses:
	 * - empty installed version or an unknown operator → false (cannot be decided)
	 * - each given bound must hold (version_compare with that operator)
	 * - no bound at all: affected only when the entry is marked unfixed (= all versions)
	 */
	public static function affects( $version, $op ) {
		if ( '' === trim( (string) $version ) ) {
			return false;
		}
		$op      = self::operator( $op );
		$has_min = null !== $op['min_version'] || null !== $op['min_operator'];
		$has_max = null !== $op['max_version'] || null !== $op['max_operator'];
		if ( ! $has_min && ! $has_max ) {
			return $op['unfixed'];
		}
		foreach ( array( 'min', 'max' ) as $side ) {
			if ( ! ( 'min' === $side ? $has_min : $has_max ) ) {
				continue;
			}
			$v = $op[ $side . '_version' ];
			$o = $op[ $side . '_operator' ];
			if ( null === $v || ! in_array( $o, self::OPS, true ) ) {
				return false; // Incomplete bound – cannot be evaluated.
			}
			if ( ! self::cmp( $version, $v, $o ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * First safe version according to the entry.
	 *
	 * @return array|null ['version' => '5.3.2', 'exact' => true]   lt  → fixed in exactly this version
	 *                    ['version' => '9.1.1.1', 'exact' => false] le/eq → fixed in a release after this one
	 *                    null                                        unfixed or no upper bound → no fix known
	 */
	public static function fix_version( $op ) {
		$op = self::operator( $op );
		if ( $op['unfixed'] || null === $op['max_version'] ) {
			return null;
		}
		if ( 'lt' === $op['max_operator'] ) {
			return array( 'version' => $op['max_version'], 'exact' => true );
		}
		if ( in_array( $op['max_operator'], array( 'le', 'eq' ), true ) ) {
			return array( 'version' => $op['max_version'], 'exact' => false );
		}
		return null;
	}

	/** Highest of several fix_version() results; null when one of them has no fix. */
	public static function needed_fix( array $ops ) {
		$best = false;
		foreach ( $ops as $op ) {
			$f = self::fix_version( $op );
			if ( null === $f ) {
				return null;
			}
			if ( false === $best || self::cmp( $f['version'], $best['version'], 'gt' ) || ( self::cmp( $f['version'], $best['version'], 'eq' ) && ! $f['exact'] ) ) {
				$best = $f;
			}
		}
		return false === $best ? null : $best;
	}

	/** CVSS score → severity key (CVSS v3/v4 bands). */
	public static function severity( $score ) {
		if ( null === $score ) {
			return '';
		}
		$score = (float) $score;
		return $score >= 9 ? 'critical' : ( $score >= 7 ? 'high' : ( $score >= 4 ? 'medium' : ( $score > 0 ? 'low' : '' ) ) );
	}

	/** One feed entry → compact array: u, title, op, cves[], ref [label, url], score, sev, kev. */
	public static function compact( array $e, $core = false ) {
		$cves  = array();
		$title = '';
		$ref   = null;
		foreach ( isset( $e['source'] ) && is_array( $e['source'] ) ? $e['source'] : array() as $s ) {
			$id   = isset( $s['id'] ) ? (string) $s['id'] : '';
			$name = isset( $s['name'] ) ? trim( html_entity_decode( (string) $s['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
			if ( preg_match( '/^CVE-\d{4}-\d{4,}$/', $id ) ) {
				$cves[] = $id;
				continue;
			}
			if ( '' === $title && '' !== $name && 0 !== strpos( $name, 'CVE-' ) && $name !== $id ) {
				$title = $name;
			}
			if ( null === $ref && ! empty( $s['link'] ) && preg_match( '#^https://#', (string) $s['link'] ) ) {
				$host = (string) parse_url( (string) $s['link'], PHP_URL_HOST );
				$ref  = array( self::source_label( $host ), (string) $s['link'] );
			}
		}
		if ( '' === $title ) {
			// CVE-only entries: first sentence of the CVE description, without the "[en] " prefix.
			foreach ( isset( $e['source'] ) && is_array( $e['source'] ) ? $e['source'] : array() as $s ) {
				if ( ! empty( $s['description'] ) ) {
					$d     = preg_replace( '/^\[[a-z]{2}\]\s*/', '', trim( html_entity_decode( (string) $s['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
					$title = preg_match( '/^(.{20,200}?[.!?])(\s|$)/u', $d, $m ) ? $m[1] : ( function_exists( 'mb_substr' ) ? mb_substr( $d, 0, 160 ) : substr( $d, 0, 160 ) );
					break;
				}
			}
		}
		if ( '' === $title ) {
			$title = isset( $e['name'] ) ? trim( html_entity_decode( (string) $e['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
		}
		$score = null;
		$imp   = isset( $e['impact'] ) && is_array( $e['impact'] ) ? $e['impact'] : array();
		foreach ( array( 'cvss3', 'cvss4', 'cvss', 'cvss2' ) as $k ) {
			if ( isset( $imp[ $k ]['score'] ) && is_numeric( $imp[ $k ]['score'] ) ) {
				$score = (float) $imp[ $k ]['score'];
				break;
			}
		}
		$out = array(
			'u'     => $core ? ( $cves ? $cves[0] : ( isset( $e['source'][0]['id'] ) ? (string) $e['source'][0]['id'] : md5( $title ) ) ) : ( isset( $e['uuid'] ) ? (string) $e['uuid'] : md5( serialize( $e ) ) ),
			'title' => $title,
			'cves'  => array_values( array_unique( $cves ) ),
			'ref'   => $ref,
			'score' => $score,
			'sev'   => self::severity( $score ),
			'kev'   => ! empty( $imp['kev'] ),
		);
		if ( ! $core ) {
			$out['op'] = self::operator( isset( $e['operator'] ) ? $e['operator'] : array() );
		}
		return $out;
	}

	public static function source_label( $host ) {
		$host = strtolower( (string) $host );
		foreach ( array( 'wordfence' => 'Wordfence', 'wpscan' => 'WPScan', 'patchstack' => 'Patchstack', 'jvn' => 'JVN', 'euvd' => 'EUVD', 'enisa' => 'EUVD', 'nvd.nist' => 'NVD' ) as $k => $label ) {
			if ( false !== strpos( $host, $k ) ) {
				return $label;
			}
		}
		return $host;
	}

	/**
	 * Decides what to do about one vulnerable component.
	 *
	 * @param array       $matched compact entries that affect the installed version
	 * @param string|null $offer   version WordPress can install right now (update offer) or null
	 * @param bool        $package the offer has a download package
	 * @return array action (update | manual | nofix), fix (needed_fix() result or null), offer_clean (bool)
	 */
	public static function decide( array $matched, $offer, $package ) {
		$ops         = array_map( fn( $v ) => isset( $v['op'] ) ? $v['op'] : array(), $matched );
		$fix         = self::needed_fix( $ops );
		$offer_clean = false;
		if ( null !== $offer && '' !== (string) $offer ) {
			$offer_clean = true;
			foreach ( $matched as $v ) {
				if ( self::affects( $offer, isset( $v['op'] ) ? $v['op'] : array() ) ) {
					$offer_clean = false;
					break;
				}
			}
		}
		if ( $offer_clean ) {
			$action = $package ? 'update' : 'manual';
		} elseif ( null !== $fix ) {
			$action = 'manual';
		} else {
			$action = 'nofix';
		}
		return array( 'action' => $action, 'fix' => $fix, 'offer_clean' => $offer_clean );
	}

	/* ================================================================== WordPress part */

	public static function boot() {
		add_action( 'plakette_daily', array( __CLASS__, 'cron' ) );
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		add_action( self::NEXT, array( __CLASS__, 'cron' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'soon' ), 20, 0 );
		add_action( 'activated_plugin', array( __CLASS__, 'soon' ), 20, 0 );
		add_action( '_core_updated_successfully', array( __CLASS__, 'soon' ), 20, 0 );
		add_filter( 'plakette_settings_sections', array( __CLASS__, 'settings' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'twicedaily', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
		wp_clear_scheduled_hook( self::NEXT );
	}

	/** After an update or activation: evaluate again with the new versions, fetch new components in a minute. */
	public static function soon() {
		if ( get_option( self::OPTION ) ) {
			self::refresh( 0 ); // no network: just re-match the cached feed against the new versions
		}
		if ( ! wp_next_scheduled( self::NEXT ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::NEXT );
		}
	}

	public static function cron() {
		$before = self::signature();
		$state  = self::refresh( 45 );
		if ( $state['pending'] > 0 && empty( $state['error'] ) && ! wp_next_scheduled( self::NEXT ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::NEXT );
		}
		// Keep the stored audit report in step, so the report and the dashboard show the same.
		if ( self::signature() !== $before && is_array( get_option( 'plakette_report' ) ) && class_exists( 'Plakette_Auditor' ) ) {
			Plakette_Auditor::refresh( 'security_vulnerabilities' );
		}
	}

	/** Loads the admin include files (cron and REST requests do not load them). */
	public static function admin_includes() {
		foreach ( array( 'file', 'misc', 'plugin', 'theme', 'update', 'class-wp-upgrader' ) as $file ) {
			require_once ABSPATH . 'wp-admin/includes/' . $file . '.php';
		}
	}

	/** Is the plugin/theme the one from wordpress.org? (Same rule as WordPress' own update check.) */
	private static function from_wporg( $update_uri ) {
		$update_uri = trim( (string) $update_uri );
		if ( '' === $update_uri ) {
			return true;
		}
		$host = strtolower( (string) wp_parse_url( $update_uri, PHP_URL_HOST ) );
		return in_array( $host, array( 'wordpress.org', 'w.org' ), true );
	}

	/**
	 * Everything installed: key => type, slug, file, name, version, active, offer, package.
	 * Plugins/themes with an "Update URI" outside wordpress.org are listed in 'external' (same slug ≠ same software).
	 */
	public static function components( $refresh_offers = false ) {
		global $wp_version;
		self::admin_includes();
		if ( $refresh_offers ) {
			$stale = fn( $t ) => ! is_object( $t ) || empty( $t->last_checked ) || time() - (int) $t->last_checked > 12 * HOUR_IN_SECONDS;
			if ( $stale( get_site_transient( 'update_plugins' ) ) ) {
				wp_update_plugins();
			}
			if ( $stale( get_site_transient( 'update_themes' ) ) ) {
				wp_update_themes();
			}
			if ( $stale( get_site_transient( 'update_core' ) ) ) {
				wp_version_check();
			}
		}
		$pt    = get_site_transient( 'update_plugins' );
		$tt    = get_site_transient( 'update_themes' );
		$list  = array();
		$ext   = array();
		$list['core'] = array(
			'type'    => 'core',
			'slug'    => 'wordpress',
			'file'    => '',
			'name'    => 'WordPress',
			'version' => (string) $wp_version,
			'active'  => true,
			'offer'   => null,
			'package' => false,
			'offers'  => self::core_offers(),
		);
		foreach ( get_plugins() as $file => $data ) {
			$slug = '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file );
			$name = wp_strip_all_tags( (string) $data['Name'] );
			if ( ! self::from_wporg( isset( $data['UpdateURI'] ) ? $data['UpdateURI'] : '' ) ) {
				$ext[] = $name;
				continue;
			}
			$r     = is_object( $pt ) && isset( $pt->response[ $file ] ) && is_object( $pt->response[ $file ] ) ? $pt->response[ $file ] : null;
			$offer = $r && ! empty( $r->new_version ) && self::cmp( $r->new_version, $data['Version'], 'gt' ) ? (string) $r->new_version : null;
			$list[ 'plugin:' . $file ] = array(
				'type'    => 'plugin',
				'slug'    => sanitize_title( $slug ),
				'file'    => $file,
				'name'    => $name,
				'version' => (string) $data['Version'],
				'active'  => is_plugin_active( $file ),
				'network' => is_multisite() && is_plugin_active_for_network( $file ),
				'offer'   => $offer,
				'package' => $offer && ! empty( $r->package ),
			);
		}
		foreach ( wp_get_themes() as $slug => $theme ) {
			$name = wp_strip_all_tags( (string) $theme->get( 'Name' ) );
			if ( ! self::from_wporg( $theme->get( 'UpdateURI' ) ) ) {
				$ext[] = $name;
				continue;
			}
			$r     = is_object( $tt ) && isset( $tt->response[ $slug ] ) ? (array) $tt->response[ $slug ] : null;
			$offer = $r && ! empty( $r['new_version'] ) && self::cmp( $r['new_version'], $theme->get( 'Version' ), 'gt' ) ? (string) $r['new_version'] : null;
			$list[ 'theme:' . $slug ] = array(
				'type'    => 'theme',
				'slug'    => (string) $slug,
				'file'    => (string) $slug,
				'name'    => $name,
				'version' => (string) $theme->get( 'Version' ),
				'active'  => get_stylesheet() === $slug || get_template() === $slug,
				'offer'   => $offer,
				'package' => $offer && ! empty( $r['package'] ),
			);
		}
		return array( $list, $ext );
	}

	/** Core versions WordPress offers right now (newest last), only newer than the installed one. */
	private static function core_offers() {
		global $wp_version;
		$out = array();
		$t   = get_site_transient( 'update_core' );
		if ( ! is_object( $t ) || empty( $t->updates ) ) {
			return $out;
		}
		foreach ( (array) $t->updates as $u ) {
			if ( is_object( $u ) && ! empty( $u->current ) && in_array( $u->response ?? '', array( 'upgrade', 'autoupdate' ), true ) && self::cmp( $u->current, $wp_version, 'gt' ) && ! empty( $u->packages ) ) {
				$out[ (string) $u->current ] = (string) $u->current;
			}
		}
		uksort( $out, fn( $a, $b ) => version_compare( $a, $b ) );
		return array_values( $out );
	}

	/** Feed cache key of a component. */
	private static function feed_key( array $c, $version = null ) {
		return 'core' === $c['type'] ? 'core:' . ( null === $version ? $c['version'] : $version ) : $c['type'] . ':' . $c['slug'];
	}

	/** Does this cache entry need a new request? */
	private static function stale( $entry, array $c ) {
		if ( ! is_array( $entry ) || empty( $entry['t'] ) ) {
			return true;
		}
		if ( time() - (int) $entry['t'] > self::TTL ) {
			return true;
		}
		// Entries older than the then installed version were dropped – a downgrade needs the full list again.
		return 'core' !== $c['type'] && isset( $entry['v'] ) && '' !== $entry['v'] && self::cmp( $c['version'], $entry['v'], 'lt' );
	}

	/** @return array|WP_Error ['name' => …, 'list' => compact entries] */
	public static function fetch( $type, $slug ) {
		$url = self::API . $type . '/' . rawurlencode( (string) $slug ) . '/';
		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'Plakette/' . ( defined( 'PLAKETTE_VERSION' ) ? PLAKETTE_VERSION : '1' ) . ' (WordPress plugin)', // no site address
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $body ) || ! array_key_exists( 'data', $body ) || ! empty( $body['error'] ) ) {
			return new WP_Error( 'plakette_vulns', sprintf( 'HTTP %d%s', $code, is_array( $body ) && ! empty( $body['message'] ) ? ' – ' . $body['message'] : '' ) );
		}
		$data = is_array( $body['data'] ) ? $body['data'] : array();
		$list = array();
		foreach ( isset( $data['vulnerability'] ) && is_array( $data['vulnerability'] ) ? $data['vulnerability'] : array() as $e ) {
			if ( is_array( $e ) ) {
				$list[] = self::compact( $e, 'core' === $type );
			}
		}
		if ( 'core' === $type ) {
			// One entry per source record: a CVE and its Wordfence/Patchstack copy are separate entries – keep each id once.
			$seen = array();
			$list = array_values( array_filter( $list, function ( $v ) use ( &$seen ) {
				if ( isset( $seen[ $v['u'] ] ) ) {
					return false;
				}
				return $seen[ $v['u'] ] = true;
			} ) );
		}
		return array(
			'name'   => isset( $data['name'] ) ? html_entity_decode( (string) $data['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '',
			'closed' => ! empty( $data['closed'] ),
			'list'   => $list,
		);
	}

	/**
	 * Fetches stale components (≤ BATCH, within $budget seconds; 0 = no network) and evaluates everything.
	 *
	 * @return array state (see option plakette_vulns)
	 */
	public static function refresh( $budget = 30, $max = self::BATCH ) {
		if ( function_exists( 'set_time_limit' ) && $budget > 20 ) {
			@set_time_limit( 60 + (int) $budget ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		list( $comps, $ext ) = self::components( $budget > 0 );
		$feed  = get_option( self::FEED, array() );
		$feed  = is_array( $feed ) ? $feed : array();
		$prev  = self::state();
		$error = '';
		$todo  = array();
		foreach ( $comps as $key => $c ) {
			$fk = self::feed_key( $c );
			if ( self::stale( isset( $feed[ $fk ] ) ? $feed[ $fk ] : null, $c ) ) {
				$todo[ $fk ] = array( $c, $c['version'] );
			}
			// Core: also the offered versions, to know which update fixes what.
			foreach ( 'core' === $c['type'] ? $c['offers'] : array() as $ov ) {
				$ok = self::feed_key( $c, $ov );
				if ( self::stale( isset( $feed[ $ok ] ) ? $feed[ $ok ] : null, $c ) ) {
					$todo[ $ok ] = array( $c, $ov );
				}
			}
		}
		// Never fetched first, then the oldest.
		uksort( $todo, fn( $a, $b ) => ( isset( $feed[ $a ]['t'] ) ? $feed[ $a ]['t'] : 0 ) <=> ( isset( $feed[ $b ]['t'] ) ? $feed[ $b ]['t'] : 0 ) );
		$start = microtime( true );
		$done  = 0;
		$fails = 0;
		if ( $budget > 0 ) {
			foreach ( $todo as $fk => $job ) {
				if ( $done >= $max || microtime( true ) - $start > $budget || $fails >= 2 ) {
					break;
				}
				list( $c, $version ) = $job;
				$res = self::fetch( $c['type'], 'core' === $c['type'] ? $version : $c['slug'] );
				if ( is_wp_error( $res ) ) {
					++$fails;
					$error = $res->get_error_message();
					continue;
				}
				$fails = 0;
				++$done;
				if ( 'core' !== $c['type'] ) {
					// Only entries that can affect the installed or a newer version are kept (keeps the option small).
					$res['list'] = array_values( array_filter( $res['list'], fn( $v ) => null === $v['op']['max_version'] || ! self::cmp( $c['version'], $v['op']['max_version'], 'gt' ) ) );
				}
				$feed[ $fk ] = array( 't' => time(), 'v' => $version ) + $res;
				unset( $todo[ $fk ] );
			}
			if ( $done || $error ) {
				// Drop cache entries of removed components.
				$keep = array();
				foreach ( $comps as $c ) {
					$keep[ self::feed_key( $c ) ] = true;
					foreach ( 'core' === $c['type'] ? $c['offers'] : array() as $ov ) {
						$keep[ self::feed_key( $c, $ov ) ] = true;
					}
				}
				$feed = array_intersect_key( $feed, $keep );
				update_option( self::FEED, $feed, false );
			}
		}
		$state = self::evaluate( $comps, $feed );
		$state['external'] = $ext;
		$state['time']     = $error && $prev ? (int) $prev['time'] : time();
		$state['error']    = $error;
		$state['error_at'] = $error ? time() : 0;
		$state['ok_at']    = $done ? time() : ( $prev && ! empty( $prev['ok_at'] ) ? (int) $prev['ok_at'] : 0 );
		update_option( self::OPTION, $state, false );
		self::mail( $state );
		return $state;
	}

	/** Matches all components against the cached feed. */
	public static function evaluate( array $comps, array $feed ) {
		$found   = array();
		$checked = 0;
		$pending = 0;
		$oldest  = 0;
		foreach ( $comps as $key => $c ) {
			$entry = isset( $feed[ self::feed_key( $c ) ] ) ? $feed[ self::feed_key( $c ) ] : null;
			if ( ! is_array( $entry ) || ! isset( $entry['list'] ) || ( 'core' !== $c['type'] && isset( $entry['v'] ) && self::cmp( $c['version'], $entry['v'], 'lt' ) ) ) {
				++$pending;
				continue;
			}
			++$checked;
			$oldest = $oldest ? min( $oldest, (int) $entry['t'] ) : (int) $entry['t'];
			if ( 'core' === $c['type'] ) {
				$matched = $entry['list'];
				$res     = self::decide_core( $matched, $c, $feed );
			} else {
				$matched = array_values( array_filter( $entry['list'], fn( $v ) => self::affects( $c['version'], $v['op'] ) ) );
				$res     = $matched ? self::decide( $matched, $c['offer'], $c['package'] ) : null;
			}
			if ( ! $matched ) {
				continue;
			}
			usort( $matched, fn( $a, $b ) => ( $b['score'] ?? -1 ) <=> ( $a['score'] ?? -1 ) );
			$found[ $key ] = array(
				'key'     => $key,
				'type'    => $c['type'],
				'slug'    => $c['slug'],
				'file'    => $c['file'],
				'name'    => $c['name'],
				'version' => $c['version'],
				'active'  => $c['active'],
				'network' => ! empty( $c['network'] ),
				'offer'   => $res['offer'] ?? $c['offer'],
				'action'  => $res['action'],
				'fix'     => $res['fix'],
				'closed'  => ! empty( $entry['closed'] ),
				'score'   => $matched[0]['score'],
				'sev'     => $matched[0]['sev'],
				'vulns'   => $matched,
			);
		}
		uasort( $found, fn( $a, $b ) => ( $b['score'] ?? -1 ) <=> ( $a['score'] ?? -1 ) );
		return array(
			'source'  => self::SOURCE,
			'total'   => count( $comps ),
			'checked' => $checked,
			'pending' => $pending,
			'oldest'  => $oldest,
			'found'   => $found,
		);
	}

	/** Core: the feed lists what affects exactly this version; an offered version is clean when its list has none of these ids. */
	private static function decide_core( array $matched, array $c, array $feed ) {
		if ( ! $matched ) {
			return null;
		}
		$ids = array_column( $matched, 'u' );
		foreach ( $c['offers'] as $ov ) { // oldest first: the smallest update that fixes everything
			$e = isset( $feed[ 'core:' . $ov ] ) ? $feed[ 'core:' . $ov ] : null;
			if ( is_array( $e ) && isset( $e['list'] ) && ! array_intersect( $ids, array_column( $e['list'], 'u' ) ) ) {
				return array( 'action' => 'update', 'fix' => array( 'version' => $ov, 'exact' => true ), 'offer' => $ov );
			}
		}
		$last = $c['offers'] ? end( $c['offers'] ) : null;
		return array( 'action' => $last ? 'manual' : 'nofix', 'fix' => null, 'offer' => $last );
	}

	/** Last stored state or null. */
	public static function state() {
		$s = get_option( self::OPTION );
		return is_array( $s ) && isset( $s['found'] ) ? $s : null;
	}

	/** Vulnerable components right now. */
	public static function found() {
		$s = self::state();
		return $s ? (array) $s['found'] : array();
	}

	/** Changes whenever the set of (component, version, vulnerability) changes. */
	public static function signature() {
		$parts = array();
		foreach ( self::found() as $key => $f ) {
			$parts[] = $key . '@' . $f['version'] . ':' . implode( ',', array_column( $f['vulns'], 'u' ) );
		}
		sort( $parts );
		return $parts ? md5( implode( '|', $parts ) ) : '';
	}

	/* ================================================================== texts */

	public static function sev_label( $sev ) {
		$labels = array(
			'critical' => __( 'kritisch', 'plakette' ),
			'high'     => __( 'hoch', 'plakette' ),
			'medium'   => __( 'mittel', 'plakette' ),
			'low'      => __( 'niedrig', 'plakette' ),
		);
		return isset( $labels[ $sev ] ) ? $labels[ $sev ] : __( 'Schwere unbekannt', 'plakette' );
	}

	public static function type_label( array $f ) {
		if ( 'core' === $f['type'] ) {
			return __( 'WordPress selbst', 'plakette' );
		}
		if ( 'plugin' === $f['type'] ) {
			return $f['active'] ? __( 'Plugin, aktiv', 'plakette' ) : __( 'Plugin, deaktiviert', 'plakette' );
		}
		return $f['active'] ? __( 'Theme, in Benutzung', 'plakette' ) : __( 'Theme, nicht in Benutzung', 'plakette' );
	}

	/** "CVE-2020-35489" or "ohne CVE-Nummer (Wordfence)". */
	public static function vuln_ids( array $v ) {
		if ( $v['cves'] ) {
			return implode( ', ', $v['cves'] );
		}
		return $v['ref'] ? sprintf( __( 'ohne CVE-Nummer (%s)', 'plakette' ), $v['ref'][0] ) : __( 'ohne CVE-Nummer', 'plakette' );
	}

	public static function cve_url( $cve ) {
		return 'https://www.cve.org/CVERecord?id=' . rawurlencode( $cve );
	}

	/** Best link of an entry: the official CVE record, else the source record. */
	public static function vuln_url( array $v ) {
		return $v['cves'] ? self::cve_url( $v['cves'][0] ) : ( $v['ref'] ? $v['ref'][1] : self::SITE );
	}

	/** One line about a vulnerability: "CVE-… (kritisch, CVSS 9.8): Titel – behoben in 5.3.2". */
	public static function vuln_line( array $v ) {
		$sev = $v['score'] !== null ? sprintf( '%s, CVSS %s', self::sev_label( $v['sev'] ), number_format_i18n( (float) $v['score'], 1 ) ) : self::sev_label( '' );
		$fix = isset( $v['op'] ) ? self::fix_version( $v['op'] ) : null;
		$fix = isset( $v['op'] ) ? ( null === $fix ? __( 'noch kein Update', 'plakette' ) : ( $fix['exact'] ? sprintf( __( 'behoben in %s', 'plakette' ), $fix['version'] ) : sprintf( __( 'behoben nach %s', 'plakette' ), $fix['version'] ) ) ) : '';
		return sprintf( '%1$s (%2$s)%3$s: %4$s%5$s', self::vuln_ids( $v ), $sev, ! empty( $v['kev'] ) ? ' – ' . __( 'wird nachweislich ausgenutzt', 'plakette' ) : '', $v['title'], $fix ? ' – ' . $fix : '' );
	}

	/** What the owner should do, in one sentence. */
	public static function advice( array $f ) {
		$fix = $f['fix'] ? ( $f['fix']['exact'] ? $f['fix']['version'] : sprintf( __( 'einer Version nach %s', 'plakette' ), $f['fix']['version'] ) ) : '';
		switch ( $f['action'] ) {
			case 'update':
				return sprintf( __( 'Lösung: Update auf %s – Plakette spielt es ein.', 'plakette' ), $f['offer'] );
			case 'manual':
				if ( 'core' === $f['type'] ) {
					return __( 'Ein Update ist verfügbar, behebt aber nicht alle Lücken. Spiele es trotzdem ein und prüfe bald wieder.', 'plakette' );
				}
				return sprintf( __( 'Lösung: Update auf %s – WordPress bietet es hier nicht an (z. B. Bezahl-Plugin). Hol es beim Anbieter oder deaktiviere es bis dahin.', 'plakette' ), $fix );
			default:
				if ( 'core' === $f['type'] ) {
					return __( 'Noch kein Update. WordPress liefert Sicherheits-Updates meist schnell nach – lass die automatischen Updates eingeschaltet.', 'plakette' );
				}
				if ( 'theme' === $f['type'] ) {
					return $f['active'] ? __( 'Noch kein Update. Wechsle möglichst auf ein anderes Theme.', 'plakette' ) : __( 'Noch kein Update. Lösche das Theme unter Design › Themes.', 'plakette' );
				}
				if ( ! $f['active'] ) {
					return __( 'Noch kein Update. Das Plugin ist schon deaktiviert – lösche es unter Plugins, dann ist die Lücke weg.', 'plakette' );
				}
				return $f['closed'] ? __( 'Kein Update – das Plugin wurde auf wordpress.org geschlossen. Deaktiviere es und such dir einen Ersatz.', 'plakette' ) : __( 'Noch kein Update. Deaktiviere das Plugin, bis der Hersteller die Lücke schließt.', 'plakette' );
		}
	}

	/** Age of the data in words ("vor 3 Stunden"). */
	public static function age( $time ) {
		return $time ? sprintf( __( 'vor %s', 'plakette' ), human_time_diff( (int) $time, time() ) ) : __( 'noch nie', 'plakette' );
	}

	/* ================================================================== e-mail */

	/** Sends one plain-text e-mail about vulnerabilities that were not reported before. */
	private static function mail( array $state ) {
		$mailed = get_option( self::MAILED, null );
		$now    = array();
		$new    = array();
		foreach ( $state['found'] as $key => $f ) {
			foreach ( $f['vulns'] as $v ) {
				$id         = $key . '@' . $f['version'] . '|' . $v['u'];
				$now[ $id ] = true;
				if ( ! is_array( $mailed ) || ! isset( $mailed[ $id ] ) ) {
					$new[ $key ] = $f;
				}
			}
		}
		if ( is_array( $mailed ) && array_keys( $mailed ) === array_keys( $now ) ) {
			return;
		}
		update_option( self::MAILED, $now, false );
		if ( ! $new || ! class_exists( 'Plakette_Settings' ) || ! Plakette_Settings::get( 'vulns', 'mail' ) ) {
			return;
		}
		$to = get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array();
		foreach ( $new as $f ) {
			$lines[] = sprintf( '• %1$s %2$s (%3$s)', $f['name'], $f['version'], self::type_label( $f ) );
			foreach ( array_slice( $f['vulns'], 0, 5 ) as $v ) {
				$lines[] = '  – ' . self::vuln_line( $v );
				if ( $v['cves'] ) {
					$lines[] = '    ' . self::cve_url( $v['cves'][0] );
				}
			}
			if ( count( $f['vulns'] ) > 5 ) {
				$lines[] = '  – ' . sprintf( __( 'und %d weitere', 'plakette' ), count( $f['vulns'] ) - 5 );
			}
			$lines[] = '  ' . self::advice( $f );
			$lines[] = '';
		}
		$subject = sprintf( __( '[%s] Sicherheitslücke in deiner Website gefunden', 'plakette' ), $site );
		$body    = sprintf( __( "Hallo,\n\nPlakette hat auf %1\$s (%2\$s) Software mit einer öffentlich bekannten Sicherheitslücke gefunden:\n\n%3\$s\nSo gehst du vor: Öffne Plakette und nutze den Knopf beim Punkt „Keine bekannten Sicherheitslücken“:\n%4\$s\n\nAngreifer suchen gezielt nach Websites mit solchen Lücken. Kümmere dich bitte heute darum.\n\nDie Daten stammen aus der freien Datenbank WPVulnerability (wpvulnerability.com), die CVE, Wordfence, WPScan und Patchstack zusammenführt.\nDiese E-Mail kommt nur, wenn eine neue Lücke bekannt wird. Abschalten kannst du sie in Plakette › Einstellungen › Sicherheitslücken.\n\nPlakette", 'plakette' ), $site, home_url( '/' ), implode( "\n", $lines ), admin_url( 'admin.php?page=plakette#plk-row-security_vulnerabilities' ) );
		wp_mail( $to, $subject, $body );
	}

	/* ================================================================== settings */

	public static function settings( $sections ) {
		$s    = self::state();
		$info = $s
			? sprintf(
				/* translators: 1: time, 2: source, 3: checked, 4: total, 5: found */
				__( 'Zuletzt abgeglichen: %1$s mit %2$s – %3$d von %4$d Komponenten, %5$s.', 'plakette' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $s['time'] ) ),
				'<a href="' . esc_url( self::SITE ) . '" target="_blank" rel="noopener">' . esc_html( self::SOURCE ) . '</a>',
				(int) $s['checked'],
				(int) $s['total'],
				$s['found'] ? '<strong>' . esc_html( sprintf( _n( '%d mit bekannter Lücke', '%d mit bekannten Lücken', count( $s['found'] ), 'plakette' ), count( $s['found'] ) ) ) . '</strong>' : esc_html__( 'keine bekannten Lücken', 'plakette' )
			) . ( ! empty( $s['error'] ) ? ' ' . esc_html( sprintf( __( 'Letzter Abruf fehlgeschlagen (%s) – angezeigt wird der letzte bekannte Stand.', 'plakette' ), $s['error'] ) ) : '' )
			: esc_html__( 'Noch nicht abgeglichen. Das passiert bei der nächsten Prüfung oder automatisch zweimal am Tag.', 'plakette' );
		$sections['vulns'] = array(
			'title'  => __( 'Sicherheitslücken', 'plakette' ),
			'desc'   => __( 'Plakette gleicht zweimal am Tag WordPress, alle Plugins und Themes mit der freien Datenbank WPVulnerability ab (sie sammelt CVE, Wordfence, WPScan und Patchstack). Dabei wird nur der Name jedes Plugins bzw. Themes und die WordPress-Version abgefragt – nicht die Adresse deiner Website.', 'plakette' ),
			'fields' => array(
				array(
					'key'     => 'mail',
					'label'   => __( 'Bei neuer Lücke eine E-Mail schicken', 'plakette' ),
					'type'    => 'toggle',
					'default' => true,
					'help'    => sprintf( __( 'Geht einmal pro neu bekannter Lücke an die Adresse aus Einstellungen › Allgemein (%s).', 'plakette' ), get_option( 'admin_email' ) ),
				),
				array(
					'key'   => 'status',
					'label' => __( 'Stand', 'plakette' ),
					'type'  => 'info',
					'html'  => $info,
				),
			),
		);
		return $sections;
	}
}
