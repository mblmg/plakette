<?php
/**
 * Preloading ("Vorladen") of fonts and CDN files: <link rel="preload"> in <head>, so the browser fetches these files
 * at once instead of discovering them late (fonts only after the stylesheet is parsed, footer scripts only at the
 * end of the page).
 *
 * User-facing options in Einstellungen › „Bilder und lokale Kopien“ (section "performance" of Plakette_Settings):
 *   preload_fonts – local copies of the Google fonts (module local_fonts): the latin files of the fonts in use.
 *                   Default: on. Number of files: preload_fonts_max (default 2).
 *   preload_cdn   – local copies of CDN scripts/stylesheets (module cdn_local) that the page uses. Default: off.
 *   preload_extra – further files of the own domain, one address per line (own fonts, a slider script …).
 *
 * KEEP THIS FEATURE: these options are an explicit requirement of the plugin's owner (they were lost once in a
 * rewrite). Do not remove or hide them when refactoring – tests/run.php checks that they exist and work
 * (see "preload options" there) and docs/DEVELOPING.md lists them under "Feste Funktionen".
 *
 * Only files of the own website are preloaded: a preload to a foreign server would contact that server on every
 * page view (privacy) – such lines in preload_extra are ignored.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Preload {

	const DEFAULTS = array(
		'preload_fonts'     => true,
		'preload_fonts_max' => 2,
		'preload_cdn'       => false,
		'preload_extra'     => '',
	);

	/** Setting fields, shown at the top of the section „Bilder und lokale Kopien“. */
	public static function fields() {
		return array(
			array(
				'key'     => 'preload_fonts',
				'label'   => __( 'Schriften vorladen', 'plakette' ),
				'type'    => 'toggle',
				'default' => self::DEFAULTS['preload_fonts'],
				'help'    => __( 'Der Browser lädt die wichtigsten Schriftdateien sofort – nicht erst, wenn er das Stylesheet gelesen hat. Text erscheint schneller in der richtigen Schrift und springt nicht mehr um. Gilt für die lokalen Kopien der Google-Schriften.', 'plakette' ),
			),
			array(
				'key'     => 'preload_fonts_max',
				'label'   => __( 'Wie viele Schriftdateien vorladen', 'plakette' ),
				'type'    => 'number',
				'default' => self::DEFAULTS['preload_fonts_max'],
				'help'    => __( 'Empfohlen: 2 (z. B. Fließtext und Überschrift). Jede vorgeladene Datei konkurriert mit dem wichtigsten Bild – mehr als 4 bremst eher.', 'plakette' ),
			),
			array(
				'key'     => 'preload_cdn',
				'label'   => __( 'CDN-Dateien vorladen', 'plakette' ),
				'type'    => 'toggle',
				'default' => self::DEFAULTS['preload_cdn'],
				'help'    => __( 'Lädt die lokalen Kopien der CDN-Skripte und -Stylesheets (z. B. GSAP für Animationen) sofort, auch wenn sie erst am Seitenende eingebunden sind. Sinnvoll, wenn Animationen oder Slider sonst spürbar spät starten.', 'plakette' ),
			),
			array(
				'key'         => 'preload_extra',
				'label'       => __( 'Weitere Dateien vorladen', 'plakette' ),
				'type'        => 'textarea',
				'default'     => self::DEFAULTS['preload_extra'],
				'placeholder' => "/wp-content/uploads/fonts/meine-schrift.woff2\n/wp-content/themes/mein-theme/slider.js",
				'help'        => __( 'Eine Adresse pro Zeile – z. B. selbst hochgeladene Schriften (.woff2), Skripte (.js), Stylesheets (.css) oder ein Bild. Nur Dateien deiner eigenen Website; Adressen fremder Server werden ignoriert.', 'plakette' ),
			),
		);
	}

	/** Stored values with defaults. Reads the option directly: the settings sections are expensive to build. */
	public static function options() {
		$all = get_option( Plakette_Settings::OPTION, array() );
		$v   = is_array( $all ) && isset( $all['performance'] ) && is_array( $all['performance'] ) ? $all['performance'] : array();
		$o   = array_merge( self::DEFAULTS, array_intersect_key( $v, self::DEFAULTS ) );
		return array(
			'preload_fonts'     => (bool) $o['preload_fonts'],
			'preload_fonts_max' => max( 0, min( 8, (int) $o['preload_fonts_max'] ) ),
			'preload_cdn'       => (bool) $o['preload_cdn'],
			'preload_extra'     => (string) $o['preload_extra'],
		);
	}

	public static function boot() {
		$o = self::options();
		add_filter(
			'plakette_fonts_preload',
			function ( $on ) use ( $o ) {
				return $on && $o['preload_fonts'] && $o['preload_fonts_max'] > 0;
			}
		);
		add_filter(
			'plakette_fonts_max_preload',
			function () use ( $o ) {
				return $o['preload_fonts_max'];
			}
		);
		if ( $o['preload_cdn'] || '' !== trim( $o['preload_extra'] ) ) {
			// After the CDN files were made local (priority 5), before everything else.
			Plakette_Html::add( 'preload', array( __CLASS__, 'transform' ), 7 );
		}
	}

	/** Type of a file for the preload: [ as, type attribute, crossorigin ] or null (unknown – not preloaded). */
	public static function kind( $url ) {
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$ext  = pathinfo( $path, PATHINFO_EXTENSION );
		$map  = array(
			'woff2' => array( 'font', 'font/woff2', true ),
			'woff'  => array( 'font', 'font/woff', true ),
			'ttf'   => array( 'font', 'font/ttf', true ),
			'otf'   => array( 'font', 'font/otf', true ),
			'css'   => array( 'style', '', false ),
			'js'    => array( 'script', '', false ),
			'mjs'   => array( 'script', '', false ),
			'avif'  => array( 'image', 'image/avif', false ),
			'webp'  => array( 'image', 'image/webp', false ),
			'jpg'   => array( 'image', '', false ),
			'jpeg'  => array( 'image', '', false ),
			'png'   => array( 'image', '', false ),
			'svg'   => array( 'image', 'image/svg+xml', false ),
		);
		return isset( $map[ $ext ] ) ? $map[ $ext ] : null;
	}

	/** The lines of "Weitere Dateien vorladen" as absolute addresses of the own website. */
	public static function extra_urls( $text ) {
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$out  = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || ! self::kind( $line ) ) {
				continue;
			}
			if ( 0 === strpos( $line, '//' ) ) {
				$line = 'https:' . $line;
			} elseif ( '/' === $line[0] ) {
				$line = home_url( $line );
			}
			$host = strtolower( (string) wp_parse_url( $line, PHP_URL_HOST ) );
			if ( ! preg_match( '#^https?://#i', $line ) || preg_replace( '#^www\.#', '', $host ) !== preg_replace( '#^www\.#', '', $home ) ) {
				continue;
			}
			$out[ $line ] = true;
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/** One <link rel="preload"> tag ('' when the file type is unknown). */
	public static function tag( $url, $module = false ) {
		$k = self::kind( $url );
		if ( ! $k ) {
			return '';
		}
		if ( $module && 'script' === $k[0] ) {
			return '<link rel="modulepreload" href="' . esc_url( $url ) . '" data-plk-preload>' . "\n";
		}
		return '<link rel="preload" href="' . esc_url( $url ) . '" as="' . $k[0] . '"' . ( '' !== $k[1] ? ' type="' . $k[1] . '"' : '' ) . ( $k[2] ? ' crossorigin' : '' ) . ' data-plk-preload>' . "\n";
	}

	/** Transform for Plakette_Html: adds the preloads to <head>. */
	public static function transform( $html ) {
		$o    = self::options();
		$urls = array();
		foreach ( self::extra_urls( $o['preload_extra'] ) as $url ) {
			$urls[ $url ] = false;
		}
		if ( $o['preload_cdn'] && class_exists( 'Plakette_Performance' ) && class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$u      = wp_get_upload_dir();
			$needle = '/' . Plakette_Performance::DIR_NAME . '/';
			if ( false !== strpos( $html, $needle ) ) {
				$p = new WP_HTML_Tag_Processor( $html );
				while ( $p->next_tag() ) {
					$tag = $p->get_tag();
					$url = null;
					if ( 'SCRIPT' === $tag ) {
						$url = $p->get_attribute( 'src' );
					} elseif ( 'LINK' === $tag && 'stylesheet' === strtolower( (string) $p->get_attribute( 'rel' ) ) ) {
						$url = $p->get_attribute( 'href' );
					}
					if ( is_string( $url ) && false !== strpos( $url, $needle ) && 0 === strpos( set_url_scheme( $url, 'https' ), set_url_scheme( $u['baseurl'], 'https' ) ) ) {
						$urls[ $url ] = 'SCRIPT' === $tag && 'module' === strtolower( (string) $p->get_attribute( 'type' ) );
					}
				}
			}
		}
		$tags = '';
		foreach ( $urls as $url => $module ) {
			// Already preloaded by the theme or another plugin? Then leave it.
			if ( preg_match( '#<link\b[^>]*\brel=["\']?(module)?preload\b[^>]*' . preg_quote( esc_url( $url ), '#' ) . '#i', $html ) ) {
				continue;
			}
			$tags .= self::tag( $url, $module );
		}
		return '' === $tags ? $html : Plakette_Html::append_to_head( $html, rtrim( $tags, "\n" ) );
	}
}

add_action( 'plakette_boot', array( 'Plakette_Preload', 'boot' ) );
