<?php
/**
 * Updates from GitHub releases (same mechanism as the i-make.media Toolkit).
 *
 * The plugin header "Update URI: https://github.com/mblmg/plakette" makes WordPress call the filter
 * update_plugins_github.com when it looks for updates (WP ≥ 5.8) – and it never installs a plugin with the
 * same name from wordpress.org over this one. Each release needs an asset "plakette.zip", which the GitHub
 * Action (.github/workflows/release.yml) builds when a tag like v1.2.3 is pushed.
 *
 * Private repository or rate limits: define( 'PLAKETTE_GITHUB_TOKEN', '…' ); in wp-config.php.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Updater {

	const TRANSIENT = 'plakette_release';

	public static function boot() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'update_info' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
		add_filter( 'http_request_args', array( __CLASS__, 'auth_download' ), 10, 2 );
	}

	/** Private repositories: the asset download needs the token and the binary Accept header. */
	public static function auth_download( $args, $url ) {
		if ( defined( 'PLAKETTE_GITHUB_TOKEN' ) && PLAKETTE_GITHUB_TOKEN && 0 === strpos( (string) $url, 'https://api.github.com/repos/' . PLAKETTE_REPO . '/releases/assets/' ) ) {
			$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
			$args['headers']['Authorization'] = 'Bearer ' . PLAKETTE_GITHUB_TOKEN;
			$args['headers']['Accept']        = 'application/octet-stream';
		}
		return $args;
	}

	public static function basename() {
		return plugin_basename( PLAKETTE_FILE );
	}

	/** Newest release on GitHub (cached for 6 h, failures for 1 h). @return array|null */
	public static function latest( $force = false ) {
		$cached = get_site_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) ) {
			return ! empty( $cached['version'] ) ? $cached : null;
		}
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => PLAKETTE_SLUG . '/' . PLAKETTE_VERSION,
		);
		if ( defined( 'PLAKETTE_GITHUB_TOKEN' ) && PLAKETTE_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . PLAKETTE_GITHUB_TOKEN;
		}
		$res     = wp_remote_get( 'https://api.github.com/repos/' . PLAKETTE_REPO . '/releases/latest', array( 'timeout' => 10, 'headers' => $headers ) );
		$release = null;
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$j   = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$zip = null;
			foreach ( (array) ( isset( $j['assets'] ) ? $j['assets'] : array() ) as $a ) {
				if ( isset( $a['name'] ) && PLAKETTE_SLUG . '.zip' === $a['name'] ) {
					// Private repositories need the API URL (with token); public ones the direct download.
					$zip = ( defined( 'PLAKETTE_GITHUB_TOKEN' ) && PLAKETTE_GITHUB_TOKEN && ! empty( $a['url'] ) ) ? $a['url'] : $a['browser_download_url'];
				}
			}
			if ( $zip && ! empty( $j['tag_name'] ) ) {
				$release = array(
					'version' => ltrim( (string) $j['tag_name'], 'vV' ),
					'zip'     => $zip,
					'notes'   => isset( $j['body'] ) ? (string) $j['body'] : '',
					'date'    => isset( $j['published_at'] ) ? (string) $j['published_at'] : '',
					'url'     => isset( $j['html_url'] ) ? (string) $j['html_url'] : '',
				);
			}
		}
		set_site_transient( self::TRANSIENT, $release ? $release : array( 'version' => '' ), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	public static function update_info( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$r = self::latest();
		if ( ! $r ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . PLAKETTE_REPO,
			'slug'         => PLAKETTE_SLUG,
			'plugin'       => self::basename(),
			'version'      => $r['version'],
			'url'          => 'https://github.com/' . PLAKETTE_REPO,
			'package'      => $r['zip'],
			'requires'     => '6.5',
			'requires_php' => '8.2',
			'tested'       => get_bloginfo( 'version' ),
			'icons'        => array( 'svg' => PLAKETTE_URL . 'admin/icon.svg' ),
		);
	}

	/** "View details" in the update notice. */
	public static function details( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || PLAKETTE_SLUG !== $args->slug ) {
			return $res;
		}
		$r = self::latest();
		return (object) array(
			'name'          => 'Plakette – Website-Hauptuntersuchung',
			'slug'          => PLAKETTE_SLUG,
			'version'       => $r ? $r['version'] : PLAKETTE_VERSION,
			'author'        => '<a href="https://i-make.media">i-make.media | Magnus Brühl</a>',
			'homepage'      => 'https://github.com/' . PLAKETTE_REPO,
			'requires'      => '6.5',
			'requires_php'  => '8.2',
			'download_link' => $r ? $r['zip'] : '',
			'last_updated'  => $r ? $r['date'] : '',
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Prüft deine Website auf Datenschutz, Sicherheit, Geschwindigkeit und SEO – und behebt jeden gefundenen Mangel auf Knopfdruck, jederzeit rückgängig.', 'plakette' ) . '</p>',
				'changelog'   => $r ? wpautop( esc_html( $r['notes'] ) ) : '<p>' . esc_html__( 'Keine Release-Informationen verfügbar.', 'plakette' ) . '</p>',
			),
		);
	}

	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && self::basename() === $item->plugin ) {
			return (bool) Plakette_Settings::get( 'general', 'auto_update' );
		}
		return $update;
	}

	public static function forget() {
		delete_site_transient( self::TRANSIENT );
	}

	/** For the admin screen. */
	public static function state() {
		$cached = get_site_transient( self::TRANSIENT );
		return array(
			'installed'  => PLAKETTE_VERSION,
			'latest'     => is_array( $cached ) && ! empty( $cached['version'] ) ? $cached['version'] : '',
			'checked'    => is_array( $cached ),
			'autoUpdate' => (bool) Plakette_Settings::get( 'general', 'auto_update' ),
			'repo'       => 'https://github.com/' . PLAKETTE_REPO,
		);
	}
}
