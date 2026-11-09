<?php
/**
 * Privacy runtime features that fixes switch on:
 *
 *  embeds         Two-click placeholders for iframes of media services (YouTube, Vimeo, Maps, Spotify …).
 *                 The iframe stays in the page without src (data-plakette-src); assets/consent.js loads it on click
 *                 or automatically once the visitor accepted the category "media". youtube.com → youtube-nocookie.com.
 *                 YouTube thumbnails are downloaded once on the server (uploads/plakette-embeds/), never by visitors.
 *  local_avatars  Generated initials avatars (SVG in uploads/plakette-avatars/) instead of Gravatar.
 *  comment_ip     New comments store a shortened IP address (IPv4: last octet 0, IPv6: first three groups).
 *  comment_note   Privacy notice with a link to the privacy policy above the comment form's submit button.
 *  remove_services  List of service ids whose scripts, iframes, stylesheets and tracking pixels are stripped from every
 *                 page („Dienst entfernen“, e.g. Google Analytics after the switch to the Plakette-Statistik).
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Embeds {

	const DIR_NAME = 'plakette-embeds';

	/** @var string[] YouTube video ids whose thumbnail should be downloaded after this request */
	private static $thumbs = array();

	public static function boot() {
		if ( ! Plakette_Modules::on( 'embeds' ) ) {
			return;
		}
		add_filter( 'embed_oembed_html', array( __CLASS__, 'oembed' ), 99, 2 );
		Plakette_Html::add( 'embeds', array( __CLASS__, 'transform' ), 15 );
	}

	/** @return array [ path, url ] */
	public static function storage() {
		$u = wp_get_upload_dir();
		return array( trailingslashit( $u['basedir'] ) . self::DIR_NAME, trailingslashit( $u['baseurl'] ) . self::DIR_NAME );
	}

	/** Media service id for an iframe src that needs a placeholder, or ''. */
	public static function service_for( $src ) {
		if ( '' === $src || ! preg_match( '#^(https?:)?//#i', $src ) ) {
			return '';
		}
		$id = Plakette_Services::for_url( $src );
		if ( ! $id ) {
			return '';
		}
		$s = Plakette_Services::get( $id );
		return ( 'media' === $s['category'] && ! empty( $s['consent'] ) ) ? $id : '';
	}

	public static function oembed( $html, $url = '' ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<iframe' ) || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return $html;
		}
		return self::wrap_iframes( $html );
	}

	public static function transform( $html ) {
		if ( false === stripos( $html, '<iframe' ) ) {
			return $html;
		}
		$changed = false;
		$html    = Plakette_Html::outside_code(
			$html,
			function ( $part ) use ( &$changed ) {
				$new = Plakette_Embeds::wrap_iframes( $part );
				if ( $new !== $part ) {
					$changed = true;
				}
				return $new;
			}
		);
		if ( $changed || false !== strpos( $html, 'class="plk-embed' ) ) {
			$html = Plakette_Consent::assets( $html );
		}
		return $html;
	}

	public static function wrap_iframes( $html ) {
		$out = preg_replace_callback(
			'#<iframe\b[^>]*>(.*?)</iframe>#is',
			function ( $m ) {
				preg_match( '#^<iframe\b[^>]*>#i', $m[0], $open );
				$tag = $open[0];
				if ( Plakette_Services::is_held_back( $tag ) ) {
					return $m[0];
				}
				$src = Plakette_Html::attr( $tag, 'src' );
				$id  = Plakette_Embeds::service_for( $src );
				return $id ? Plakette_Embeds::placeholder( $tag, $src, $id ) : $m[0];
			},
			$html
		);
		return is_string( $out ) ? $out : $html;
	}

	private static function kind( $id ) {
		if ( in_array( $id, array( 'youtube', 'vimeo' ), true ) ) {
			return 'video';
		}
		if ( in_array( $id, array( 'google-maps', 'openstreetmap' ), true ) ) {
			return 'map';
		}
		if ( in_array( $id, array( 'spotify', 'soundcloud' ), true ) ) {
			return 'audio';
		}
		return 'content';
	}

	/** Short provider name for "Daten werden an … übertragen". */
	private static function provider( array $s ) {
		foreach ( array( 'Google', 'Meta', 'Microsoft' ) as $p ) {
			if ( 0 === strpos( (string) $s['provider'], $p ) ) {
				return $p;
			}
		}
		return $s['name'];
	}

	public static function placeholder( $tag, $src, $id ) {
		$s    = Plakette_Services::get( $id );
		$kind = self::kind( $id );
		$name = $s['name'];
		$prov = self::provider( $s );

		$src = html_entity_decode( $src, ENT_QUOTES, 'UTF-8' );
		if ( 0 === strpos( $src, '//' ) ) {
			$src = 'https:' . $src;
		}
		$src = preg_replace( '#^https?://(www\.)?youtube\.com/embed/#i', 'https://www.youtube-nocookie.com/embed/', $src );

		$texts = array(
			/* translators: 1: service, 2: provider */
			'video'   => array( __( 'Hier ist ein Video von %1$s eingebettet. Beim Abspielen werden Daten an %2$s übertragen.', 'plakette' ), __( 'Video laden', 'plakette' ) ),
			/* translators: 1: service, 2: provider */
			'map'     => array( __( 'Hier ist eine Karte von %1$s eingebettet. Beim Laden werden Daten an %2$s übertragen.', 'plakette' ), __( 'Karte laden', 'plakette' ) ),
			/* translators: 1: service, 2: provider */
			'audio'   => array( __( 'Hier ist ein Inhalt von %1$s eingebettet. Beim Abspielen werden Daten an %2$s übertragen.', 'plakette' ), __( 'Inhalt laden', 'plakette' ) ),
			/* translators: 1: service, 2: provider */
			'content' => array( __( 'Hier ist ein Inhalt von %1$s eingebettet. Beim Laden werden Daten an %2$s übertragen.', 'plakette' ), __( 'Inhalt laden', 'plakette' ) ),
		);
		$text = sprintf( $texts[ $kind ][0], $name, $prov );

		// Same size as the iframe.
		$w     = Plakette_Html::attr( $tag, 'width' );
		$h     = Plakette_Html::attr( $tag, 'height' );
		$style = '';
		if ( ctype_digit( $w ) && ctype_digit( $h ) && (int) $w > 0 && (int) $h > 0 ) {
			$style = 'aspect-ratio:' . (int) $w . '/' . (int) $h . ';max-width:' . (int) $w . 'px';
		} elseif ( ctype_digit( $h ) && (int) $h > 0 ) {
			$style = 'height:' . (int) $h . 'px;aspect-ratio:auto';
		}

		$thumb = '';
		if ( 'youtube' === $id && preg_match( '#/embed/([A-Za-z0-9_-]{11})#', $src, $vm ) ) {
			$thumb = self::youtube_thumb( $vm[1] );
		}

		$frame = Plakette_Html::remove_attr( $tag, 'src' );
		$frame = Plakette_Html::remove_attr( $frame, 'loading' );
		$frame = Plakette_Html::set_attr( $frame, 'data-plakette-src', $src );
		$frame = Plakette_Html::set_attr( $frame, 'data-plakette-consent', 'media' );
		if ( ! Plakette_Html::has_attr( $frame, 'title' ) ) {
			$frame = Plakette_Html::set_attr( $frame, 'title', $name );
		}
		$frame = preg_replace( '#^<iframe\b#i', '<iframe hidden', $frame );

		$policy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';

		$o  = '<div class="plk-embed plk-embed--' . esc_attr( $kind ) . ( $thumb ? ' has-thumb' : '' ) . '" data-plakette-embed="' . esc_attr( $id ) . '"' . ( $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';
		if ( $thumb ) {
			$o .= '<img class="plk-embed__thumb" src="' . esc_url( $thumb ) . '" alt="" loading="lazy" decoding="async">';
		}
		$o .= '<div class="plk-embed__box">';
		$o .= '<p class="plk-embed__text">' . esc_html( $text ) . '</p>';
		$o .= '<button type="button" class="plk-embed__load">' . esc_html( $texts[ $kind ][1] ) . '</button>';
		/* translators: %s: service name */
		$o .= '<label class="plk-embed__always"><input type="checkbox"> ' . esc_html( sprintf( __( 'Inhalte von %s immer laden', 'plakette' ), $name ) ) . '</label>';
		if ( $policy ) {
			$o .= '<a class="plk-embed__policy" href="' . esc_url( $policy ) . '">' . esc_html__( 'Datenschutzerklärung', 'plakette' ) . '</a>';
		}
		$o .= '</div>' . $frame . '</iframe></div>';
		return $o;
	}

	/** URL of the local thumbnail, '' while it does not exist yet (download is queued for the end of the request). */
	public static function youtube_thumb( $video ) {
		list( $dir, $url ) = self::storage();
		$file              = 'yt-' . $video . '.jpg';
		if ( file_exists( $dir . '/' . $file ) ) {
			return $url . '/' . $file;
		}
		if ( ! get_transient( 'plakette_yt_' . $video ) && count( self::$thumbs ) < 3 && ! in_array( $video, self::$thumbs, true ) ) {
			self::$thumbs[] = $video;
			if ( 1 === count( self::$thumbs ) ) {
				add_action( 'shutdown', array( __CLASS__, 'fetch_thumbs' ), 60 );
			}
		}
		return '';
	}

	/** Downloads queued thumbnails once (lock: one try per video per day). */
	public static function fetch_thumbs() {
		list( $dir ) = self::storage();
		$got         = false;
		foreach ( self::$thumbs as $video ) {
			if ( get_transient( 'plakette_yt_' . $video ) ) {
				continue;
			}
			set_transient( 'plakette_yt_' . $video, 1, DAY_IN_SECONDS );
			$res = wp_remote_get( 'https://i.ytimg.com/vi/' . rawurlencode( $video ) . '/hqdefault.jpg', array( 'timeout' => 3, 'redirection' => 1 ) );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				continue;
			}
			$body = (string) wp_remote_retrieve_body( $res );
			if ( strlen( $body ) < 500 || "\xFF\xD8" !== substr( $body, 0, 2 ) || ! wp_mkdir_p( $dir ) ) {
				continue;
			}
			if ( false !== file_put_contents( $dir . '/yt-' . $video . '.jpg', $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$got = true;
			}
		}
		self::$thumbs = array();
		if ( $got && class_exists( 'Plakette_Page_Cache' ) ) {
			Plakette_Page_Cache::purge_all();
		}
	}
}

class Plakette_Local_Avatars {

	const DIR_NAME = 'plakette-avatars';

	public static function boot() {
		if ( Plakette_Modules::on( 'local_avatars' ) ) {
			add_filter( 'pre_get_avatar_data', array( __CLASS__, 'avatar' ), 20, 2 );
		}
	}

	/** @return array [ path, url ] */
	public static function storage() {
		$u = wp_get_upload_dir();
		return array( trailingslashit( $u['basedir'] ) . self::DIR_NAME, trailingslashit( $u['baseurl'] ) . self::DIR_NAME );
	}

	public static function avatar( $args, $id_or_email ) {
		if ( ! is_array( $args ) || ! empty( $args['url'] ) ) {
			return $args;
		}
		$name  = '';
		$email = '';
		if ( $id_or_email instanceof WP_Comment ) {
			$name  = $id_or_email->comment_author;
			$email = $id_or_email->comment_author_email;
			if ( $id_or_email->user_id ) {
				$id_or_email = (int) $id_or_email->user_id;
			}
		} elseif ( $id_or_email instanceof WP_Post ) {
			$id_or_email = (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof WP_User ) {
			$name  = $id_or_email->display_name;
			$email = $id_or_email->user_email;
		} elseif ( is_numeric( $id_or_email ) ) {
			$user = get_user_by( 'id', (int) $id_or_email );
			if ( $user ) {
				$name  = $user->display_name;
				$email = $user->user_email;
			}
		} elseif ( is_string( $id_or_email ) ) {
			$email = $id_or_email;
			if ( '' === $name ) {
				$name = (string) strtok( $id_or_email, '@' );
			}
		}
		$initials = self::initials( $name );
		$key      = substr( wp_hash( strtolower( trim( (string) $email ) ) . '|' . $name ), 0, 16 );
		$args['url']          = self::file( $key, $initials, strtolower( trim( (string) $email ) ) . $name );
		$args['found_avatar'] = true;
		return $args;
	}

	public static function initials( $name ) {
		$name  = trim( preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', (string) $name ) );
		$parts = preg_split( '/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $parts ) {
			return '?';
		}
		$i = mb_substr( $parts[0], 0, 1 );
		if ( count( $parts ) > 1 ) {
			$i .= mb_substr( end( $parts ), 0, 1 );
		}
		return mb_strtoupper( $i );
	}

	/** URL of the SVG for this person (created on first use). */
	private static function file( $key, $initials, $seed ) {
		list( $dir, $url ) = self::storage();
		$path              = $dir . '/' . $key . '.svg';
		if ( ! file_exists( $path ) ) {
			$hue = hexdec( substr( md5( $seed ), 0, 4 ) ) % 360;
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
				. '<rect width="96" height="96" fill="hsl(' . $hue . ',45%,36%)"/>'
				. '<text x="48" y="48" dy=".35em" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif" font-size="' . ( mb_strlen( $initials ) > 1 ? 38 : 44 ) . '" font-weight="600" fill="#fff">' . esc_html( $initials ) . '</text></svg>';
			if ( ! wp_mkdir_p( $dir ) || false === @file_put_contents( $path, $svg ) ) { // phpcs:ignore
				return includes_url( 'images/blank.gif' );
			}
		}
		return $url . '/' . $key . '.svg';
	}
}

class Plakette_Comment_Privacy {

	public static function boot() {
		if ( Plakette_Modules::on( 'comment_ip' ) ) {
			add_filter( 'pre_comment_user_ip', array( __CLASS__, 'anonymize' ), 99 );
		}
		if ( Plakette_Modules::on( 'comment_note' ) ) {
			add_filter( 'comment_form_submit_field', array( __CLASS__, 'note' ), 20 );
		}
	}

	/** IPv4: last octet 0. IPv6: first three groups, rest zero. */
	public static function anonymize( $ip ) {
		$ip = trim( (string) $ip );
		if ( '' === $ip ) {
			return $ip;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $bin && 16 === strlen( $bin ) ) {
				$out = @inet_ntop( substr( $bin, 0, 6 ) . str_repeat( "\0", 10 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return false !== $out ? $out : '::';
			}
			return '::';
		}
		return '';
	}

	public static function is_anonymous( $ip ) {
		$ip = trim( (string) $ip );
		return '' === $ip || self::anonymize( $ip ) === $ip;
	}

	public static function note( $field ) {
		if ( false !== strpos( (string) $field, 'plk-comment-note' ) ) {
			return $field;
		}
		$text   = __( 'Mit dem Absenden werden Ihr Name, Ihre E-Mail-Adresse und Ihr Kommentar gespeichert, damit wir den Kommentar anzeigen und Sie bei Rückfragen erreichen können. Ihre E-Mail-Adresse wird nicht veröffentlicht.', 'plakette' );
		$policy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		$html   = esc_html( $text );
		if ( $policy ) {
			$html .= ' ' . sprintf(
				/* translators: %s: link to the privacy policy */
				esc_html__( 'Mehr dazu in unserer %s.', 'plakette' ),
				'<a href="' . esc_url( $policy ) . '">' . esc_html__( 'Datenschutzerklärung', 'plakette' ) . '</a>'
			);
		}
		return '<p class="comment-notes plk-comment-note" style="font-size:.875em">' . $html . '</p>' . $field;
	}
}

class Plakette_Remove_Services {

	public static function boot() {
		if ( self::ids() ) {
			Plakette_Html::add( 'remove_services', array( __CLASS__, 'transform' ), 12 );
		}
	}

	/** @return string[] service ids to strip */
	public static function ids() {
		$v = Plakette_Modules::get( 'remove_services' );
		return is_array( $v ) ? array_values( array_filter( array_map( 'strval', $v ) ) ) : array();
	}

	/** Adds services to the list (with undo). */
	public static function add( array $ids, array &$undo ) {
		$list = array_values( array_unique( array_merge( self::ids(), $ids ) ) );
		sort( $list );
		Plakette_Log::set_module( 'remove_services', $list, $undo );
	}

	public static function transform( $html ) {
		$ids = self::ids();
		if ( ! $ids ) {
			return $html;
		}
		$is = function ( $url ) use ( $ids ) {
			return '' !== $url && preg_match( '#^(https?:)?//#i', $url ) && in_array( Plakette_Services::for_url( $url ), $ids, true );
		};
		// Scripts (external and inline), keeping our own.
		$out = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>\s*#is',
			function ( $m ) use ( $ids ) {
				$open = '<script' . $m[1] . '>';
				if ( 0 === strpos( strtolower( Plakette_Html::attr( $open, 'id' ) ), 'plakette' ) ) {
					return $m[0];
				}
				$type = strtolower( Plakette_Html::attr( $open, 'type' ) );
				if ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module', 'text/plain' ), true ) ) {
					return $m[0];
				}
				$id = Plakette_Services::for_script( $open, $m[2] );
				return ( $id && in_array( $id, $ids, true ) ) ? '' : $m[0];
			},
			$html
		);
		$html = is_string( $out ) ? $out : $html;
		// Iframes, stylesheets/preconnects, pixels (also inside <noscript>).
		$out = preg_replace_callback(
			'#<iframe\b[^>]*>.*?</iframe>|<(?:link|img)\b[^>]*>#is',
			function ( $m ) use ( $is ) {
				preg_match( '#^<[a-z]+\b[^>]*>#i', $m[0], $open );
				$tag = $open[0];
				foreach ( array( 'src', 'href', 'data-src', 'data-plakette-src' ) as $a ) {
					if ( $is( Plakette_Html::attr( $tag, $a ) ) ) {
						return '';
					}
				}
				return $m[0];
			},
			$html
		);
		$html = is_string( $out ) ? $out : $html;
		$out  = preg_replace( '#<noscript>\s*</noscript>#i', '', $html );
		return is_string( $out ) ? $out : $html;
	}
}

add_action( 'plakette_boot', array( 'Plakette_Embeds', 'boot' ) );
add_action( 'plakette_boot', array( 'Plakette_Remove_Services', 'boot' ) );
add_action( 'plakette_boot', array( 'Plakette_Local_Avatars', 'boot' ) );
add_action( 'plakette_boot', array( 'Plakette_Comment_Privacy', 'boot' ) );
