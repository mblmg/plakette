<?php
/**
 * Fetches pages the way a visitor (not logged in) sees them and extracts SEO and speed facts from the HTML.
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Crawler {

	const UA = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36 Plakette/1.0';

	/** @return array{ok:bool,error?:string,status?:int,time_ms?:int,headers?:array,body?:string,url?:string} */
	public static function fetch( $url, $method = 'GET' ) {
		$start = microtime( true );
		$res   = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => 20,
				'redirection' => 3,
				'sslverify'   => false,
				'user-agent'  => self::UA,
				'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8' ),
				'cookies'     => array(),
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'error' => $res->get_error_message(), 'time_ms' => $ms );
		}
		$headers = array();
		foreach ( wp_remote_retrieve_headers( $res ) as $name => $value ) {
			$headers[ strtolower( $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		$cookies = array();
		foreach ( (array) wp_remote_retrieve_cookies( $res ) as $cookie ) {
			if ( is_object( $cookie ) && ! empty( $cookie->name ) ) {
				$cookies[] = (string) $cookie->name;
			}
		}
		return array(
			'ok'      => true,
			'status'  => (int) wp_remote_retrieve_response_code( $res ),
			'time_ms' => $ms,
			'headers' => $headers,
			'cookies' => array_values( array_unique( $cookies ) ),
			'body'    => (string) wp_remote_retrieve_body( $res ),
			'url'     => $url,
		);
	}

	/** Fetch + analyse one page. */
	public static function page( $url ) {
		$res = self::fetch( $url );
		if ( ! $res['ok'] ) {
			return $res;
		}
		// A second request shows the speed a returning visitor gets (a page cache needs one request to fill).
		$second = self::fetch( $url );
		if ( $second['ok'] ) {
			$res['time_ms_first'] = $res['time_ms'];
			$res['time_ms']       = min( $res['time_ms'], $second['time_ms'] );
			$res['headers']       = $second['headers'];
		}
		$res['facts'] = self::analyse( $res['body'], $url );
		// Keep the raw HTML for checks that need more than the facts (consent, trackers, links …).
		$res['html'] = $res['body'];
		unset( $res['body'] );
		return $res;
	}

	public static function analyse( $html, $url ) {
		$facts = array(
			'bytes'        => strlen( $html ),
			'title'        => null,
			'title_count'  => 0,
			'description'  => null,
			'robots'       => '',
			'canonical'    => '',
			'lang'         => '',
			'og'           => array(),
			'jsonld'       => 0,
			'h1'           => array(),
			'imgs'         => array(),
			'scripts_head' => array(),
			'styles'       => array(),
			'google_fonts' => array(),
			'emoji'        => false !== strpos( $html, '_wpemojiSettings' ) || false !== strpos( $html, 'wp-emoji-release' ),
			'jq_migrate'   => (bool) preg_match( '#jquery-migrate(\.min)?\.js#', $html ),
			'dashicons'    => (bool) preg_match( '#/dashicons(\.min)?\.css#', $html ),
			'dom_nodes'    => 0,
			'scripts'      => array(), // every external script src (head and body)
			'iframes'      => array(), // iframe src (also data-src for lazy iframes)
			'links'        => array(), // [ href, text, label ] – max 400; label = accessible name from aria/title/img alt
			'viewport'     => '',
			'generator'    => array(),
			'hosts'        => array(), // external host => list of resource types (script, style, img, iframe, css = url() in CSS)
		);
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return $facts;
		}
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp = new DOMXPath( $doc );

		$titles               = $xp->query( '//head/title|//title' );
		$facts['title_count'] = $titles->length;
		if ( $titles->length ) {
			$facts['title'] = trim( preg_replace( '/\s+/u', ' ', $titles->item( 0 )->textContent ) );
		}
		$html_el = $xp->query( '//html' )->item( 0 );
		if ( $html_el ) {
			$facts['lang'] = $html_el->getAttribute( 'lang' );
		}
		foreach ( $xp->query( '//meta' ) as $meta ) {
			$name     = strtolower( $meta->getAttribute( 'name' ) );
			$property = strtolower( $meta->getAttribute( 'property' ) );
			$content  = $meta->getAttribute( 'content' );
			if ( 'viewport' === $name ) {
				$facts['viewport'] = $content;
			} elseif ( 'generator' === $name ) {
				$facts['generator'][] = $content;
			}
			if ( 'description' === $name && null === $facts['description'] ) {
				$facts['description'] = trim( $content );
			} elseif ( 'robots' === $name ) {
				$facts['robots'] .= strtolower( $content ) . ',';
			} elseif ( 0 === strpos( $property, 'og:' ) ) {
				$facts['og'][ $property ] = $content;
			}
		}
		foreach ( $xp->query( '//link' ) as $link ) {
			$rel  = strtolower( $link->getAttribute( 'rel' ) );
			$href = $link->getAttribute( 'href' );
			if ( 'canonical' === $rel ) {
				$facts['canonical'] = $href;
			} elseif ( 'stylesheet' === $rel ) {
				$facts['styles'][] = $href;
				if ( false !== strpos( $href, 'fonts.googleapis.com' ) ) {
					$facts['google_fonts'][] = $href;
				}
			}
		}
		if ( preg_match_all( '#@import\s+url\(["\']?(https?:)?//fonts\.googleapis\.com[^)"\']+#', $html, $m ) ) {
			foreach ( $m[0] as $import ) {
				$facts['google_fonts'][] = preg_replace( '#^@import\s+url\(["\']?#', '', $import );
			}
		}
		$facts['jsonld'] = $xp->query( '//script[@type="application/ld+json"]' )->length;

		foreach ( $xp->query( '//h1' ) as $h1 ) {
			$facts['h1'][] = trim( preg_replace( '/\s+/u', ' ', $h1->textContent ) );
		}

		$position = 0;
		foreach ( $xp->query( '//body//img' ) as $img ) {
			if ( $position >= 60 ) {
				break;
			}
			$facts['imgs'][] = array(
				'src'      => $img->getAttribute( 'src' ),
				'has_alt'  => $img->hasAttribute( 'alt' ),
				'alt'      => $img->getAttribute( 'alt' ),
				'loading'  => $img->getAttribute( 'loading' ),
				'priority' => $img->getAttribute( 'fetchpriority' ),
				'sized'    => $img->hasAttribute( 'width' ) && $img->hasAttribute( 'height' ),
				'pos'      => $position++,
			);
		}

		foreach ( $xp->query( '//head/script[@src]' ) as $script ) {
			$type = strtolower( $script->getAttribute( 'type' ) );
			if ( $script->hasAttribute( 'defer' ) || $script->hasAttribute( 'async' ) || 'module' === $type ) {
				continue;
			}
			$facts['scripts_head'][] = $script->getAttribute( 'src' );
		}
		foreach ( $xp->query( '//script[@src]' ) as $script ) {
			$facts['scripts'][] = $script->getAttribute( 'src' );
		}
		foreach ( $xp->query( '//iframe' ) as $frame ) {
			$src = $frame->getAttribute( 'src' );
			if ( '' === $src || 0 === strpos( $src, 'about:' ) ) {
				$src = $frame->getAttribute( 'data-src' );
			}
			if ( '' !== $src ) {
				$facts['iframes'][] = $src;
			}
		}
		foreach ( $xp->query( '//body//a[@href]' ) as $a ) {
			if ( count( $facts['links'] ) >= 400 ) {
				break;
			}
			$label = trim( $a->getAttribute( 'aria-label' ) . ' ' . $a->getAttribute( 'title' ) );
			if ( '' === $label ) {
				foreach ( $a->getElementsByTagName( 'img' ) as $img ) {
					$label .= $img->getAttribute( 'alt' );
				}
			}
			$facts['links'][] = array(
				'href'  => $a->getAttribute( 'href' ),
				'text'  => trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $a->textContent ) ),
				'label' => trim( $label ),
			);
		}
		$self  = wp_parse_url( home_url(), PHP_URL_HOST );
		$found = array(
			'script' => $facts['scripts'],
			'style'  => $facts['styles'],
			'iframe' => $facts['iframes'],
			'img'    => wp_list_pluck( $facts['imgs'], 'src' ),
		);
		if ( preg_match_all( '#url\(\s*["\']?(https?:)?//([^/"\')\s]+)#i', $html, $m ) ) {
			$found['css'] = array_map(
				function ( $host ) {
					return '//' . $host . '/';
				},
				$m[2]
			);
		}
		foreach ( $found as $type => $urls ) {
			foreach ( $urls as $u ) {
				$u    = html_entity_decode( (string) $u );
				$host = 0 === strpos( $u, '//' ) || preg_match( '#^https?://#i', $u ) ? wp_parse_url( ( 0 === strpos( $u, '//' ) ? 'https:' : '' ) . $u, PHP_URL_HOST ) : '';
				if ( $host && strtolower( $host ) !== strtolower( (string) $self ) ) {
					$host                      = strtolower( $host );
					$facts['hosts'][ $host ]   = isset( $facts['hosts'][ $host ] ) ? $facts['hosts'][ $host ] : array();
					$facts['hosts'][ $host ][] = $type;
					$facts['hosts'][ $host ]   = array_values( array_unique( $facts['hosts'][ $host ] ) );
				}
			}
		}
		$facts['dom_nodes'] = $xp->query( '//*' )->length;
		return $facts;
	}

	/** Absolute URL for a possibly relative asset reference. */
	public static function absolutize( $src ) {
		$src = html_entity_decode( (string) $src );
		if ( 0 === strpos( $src, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $src;
		}
		if ( 0 === strpos( $src, '/' ) ) {
			return home_url( $src );
		}
		return $src;
	}

	public static function is_local( $url ) {
		$host = wp_parse_url( self::absolutize( $url ), PHP_URL_HOST );
		return ! $host || wp_parse_url( home_url(), PHP_URL_HOST ) === $host;
	}
}
