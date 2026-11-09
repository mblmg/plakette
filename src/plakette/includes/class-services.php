<?php
/**
 * Catalogue of third-party services a website can send visitor data to.
 *
 * Used by
 *  - the privacy checks (what does the site load from where?),
 *  - the consent banner (which scripts/iframes to hold back until the visitor agrees),
 *  - the privacy-policy generator and the coverage check (which services must the policy mention?).
 *
 * Each service:
 *   name      shown to users and searched for in the privacy policy
 *   category  necessary | functional | statistics | marketing | media
 *   hosts     hostnames (suffix match: "google-analytics.com" also matches "www.google-analytics.com")
 *   patterns  regexes on inline scripts / HTML that reveal the service without a host (e.g. "gtag(")
 *   provider  company + seat (for the privacy policy)
 *   policy    paragraph for the privacy policy (German, du-free formal tone as legal texts require)
 *   consent   true if loading it requires prior consent (§ 25 TDDDG / Art. 6 (1) a DSGVO)
 *   aliases   other words that count as "mentioned" in a privacy policy
 */

defined( 'ABSPATH' ) || exit;

class Plakette_Services {

	public static function all() {
		$s = array(
			'google-analytics' => array(
				'name'     => 'Google Analytics',
				'category' => 'statistics',
				'hosts'    => array( 'google-analytics.com', 'analytics.google.com' ),
				'patterns' => array( '#gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"](G|UA)-#', '#google-analytics\.com/(analytics|ga)\.js#', '#googletagmanager\.com/gtag/js#' ),
				'provider' => 'Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland',
				'policy'   => 'Diese Website nutzt Google Analytics, einen Webanalysedienst der Google Ireland Limited. Google Analytics verwendet Cookies und ähnliche Technologien, um die Nutzung der Website auszuwerten (z. B. aufgerufene Seiten, Verweildauer, ungefährer Standort, Gerät). Die Daten können an Server der Google LLC in den USA übertragen werden; Google ist nach dem EU-US Data Privacy Framework zertifiziert. Die Nutzung erfolgt nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG), die Sie jederzeit über die Cookie-Einstellungen widerrufen können.',
				'consent'  => true,
				'aliases'  => array( 'Google Analytics', 'Analytics' ),
			),
			'google-tag-manager' => array(
				'name'     => 'Google Tag Manager',
				'category' => 'statistics',
				'hosts'    => array( 'googletagmanager.com' ),
				'patterns' => array( '#googletagmanager\.com/gtm\.js#', '#GTM-[A-Z0-9]{4,}#' ),
				'provider' => 'Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland',
				'policy'   => 'Diese Website nutzt den Google Tag Manager der Google Ireland Limited. Der Tag Manager selbst setzt keine Cookies, lädt aber weitere Dienste nach und überträgt dabei Ihre IP-Adresse an Google. Er wird nur mit Ihrer Einwilligung geladen (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'Tag Manager' ),
			),
			'google-ads' => array(
				'name'     => 'Google Ads',
				'category' => 'marketing',
				'hosts'    => array( 'googleadservices.com', 'googlesyndication.com', 'doubleclick.net', 'adservice.google.com' ),
				'patterns' => array( '#gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"]AW-#' ),
				'provider' => 'Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland',
				'policy'   => 'Diese Website nutzt Google Ads und das Conversion-Tracking der Google Ireland Limited, um den Erfolg von Werbeanzeigen zu messen. Dabei werden Cookies gesetzt und Daten ggf. in die USA übertragen (EU-US Data Privacy Framework). Die Nutzung erfolgt nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'Google Ads', 'AdWords', 'DoubleClick', 'Conversion' ),
			),
			'facebook-pixel' => array(
				'name'     => 'Meta Pixel (Facebook)',
				'category' => 'marketing',
				'hosts'    => array( 'connect.facebook.net', 'facebook.com/tr' ),
				'patterns' => array( '#fbq\(\s*[\'"]init[\'"]#', '#connect\.facebook\.net/[^"\']+/fbevents\.js#' ),
				'provider' => 'Meta Platforms Ireland Limited, Merrion Road, Dublin 4, D04 X2K5, Irland',
				'policy'   => 'Diese Website nutzt das Meta-Pixel der Meta Platforms Ireland Limited, um die Wirkung von Werbeanzeigen auf Facebook und Instagram zu messen und Zielgruppen zu bilden. Dabei werden Cookies gesetzt und Daten ggf. in die USA übertragen (EU-US Data Privacy Framework). Für die Erhebung sind wir gemeinsam mit Meta verantwortlich (Art. 26 DSGVO). Die Nutzung erfolgt nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'Meta Pixel', 'Facebook Pixel', 'Facebook-Pixel', 'Meta-Pixel', 'Pixel' ),
			),
			'linkedin-insight' => array(
				'name'     => 'LinkedIn Insight Tag',
				'category' => 'marketing',
				'hosts'    => array( 'snap.licdn.com', 'px.ads.linkedin.com' ),
				'patterns' => array( '#_linkedin_partner_id#' ),
				'provider' => 'LinkedIn Ireland Unlimited Company, Wilton Place, Dublin 2, Irland',
				'policy'   => 'Diese Website nutzt das LinkedIn Insight Tag, um die Wirkung von Anzeigen auf LinkedIn zu messen. Die Nutzung erfolgt nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'LinkedIn' ),
			),
			'tiktok-pixel' => array(
				'name'     => 'TikTok Pixel',
				'category' => 'marketing',
				'hosts'    => array( 'analytics.tiktok.com' ),
				'patterns' => array( '#ttq\.load\(#' ),
				'provider' => 'TikTok Technology Limited, 10 Earlsfort Terrace, Dublin, D02 T380, Irland',
				'policy'   => 'Diese Website nutzt das TikTok-Pixel, um die Wirkung von Anzeigen auf TikTok zu messen. Die Nutzung erfolgt nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'TikTok' ),
			),
			'hotjar' => array(
				'name'     => 'Hotjar',
				'category' => 'statistics',
				'hosts'    => array( 'hotjar.com', 'hotjar.io' ),
				'patterns' => array( '#hjid\s*:#', '#static\.hotjar\.com#' ),
				'provider' => 'Hotjar Ltd., Dragonara Business Centre, 5th Floor, Dragonara Road, Paceville St Julian’s STJ 3141, Malta',
				'policy'   => 'Diese Website nutzt Hotjar, um das Nutzungsverhalten (z. B. Mausbewegungen, Klicks, Scrolltiefe) auszuwerten. Die Nutzung erfolgt nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'Hotjar' ),
			),
			'clarity' => array(
				'name'     => 'Microsoft Clarity',
				'category' => 'statistics',
				'hosts'    => array( 'clarity.ms' ),
				'patterns' => array( '#clarity\.ms/tag#' ),
				'provider' => 'Microsoft Ireland Operations Limited, One Microsoft Place, South County Business Park, Leopardstown, Dublin 18, Irland',
				'policy'   => 'Diese Website nutzt Microsoft Clarity, um das Nutzungsverhalten auszuwerten (z. B. Klicks, Scrollverhalten, Sitzungsaufzeichnungen). Daten können in die USA übertragen werden (EU-US Data Privacy Framework). Die Nutzung erfolgt nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'Clarity' ),
			),
			'matomo-cloud' => array(
				'name'     => 'Matomo',
				'category' => 'statistics',
				'hosts'    => array( 'matomo.cloud', 'innocraft.cloud' ),
				'patterns' => array( '#_paq\.push\(#' ),
				'provider' => 'je nach Installation: eigener Server oder InnoCraft Ltd., 7 Waterloo Quay PO625, 6140 Wellington, Neuseeland',
				'policy'   => 'Diese Website nutzt die Webanalyse Matomo, um die Nutzung der Website statistisch auszuwerten. Sofern dabei Cookies oder ähnliche Technologien eingesetzt werden, erfolgt dies nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'Matomo', 'Piwik' ),
			),
			'youtube' => array(
				'name'     => 'YouTube',
				'category' => 'media',
				'hosts'    => array( 'youtube.com', 'youtube-nocookie.com', 'ytimg.com', 'youtu.be' ),
				'patterns' => array(),
				'provider' => 'Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland',
				'policy'   => 'Auf dieser Website sind Videos von YouTube eingebettet. Die Videos werden erst geladen, wenn Sie dies durch einen Klick ausdrücklich erlauben; erst dann wird eine Verbindung zu den Servern von YouTube hergestellt und u. a. Ihre IP-Adresse übertragen. Wir nutzen dabei den erweiterten Datenschutzmodus (youtube-nocookie.com). Rechtsgrundlage ist Ihre Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'YouTube' ),
			),
			'vimeo' => array(
				'name'     => 'Vimeo',
				'category' => 'media',
				'hosts'    => array( 'vimeo.com', 'vimeocdn.com' ),
				'patterns' => array(),
				'provider' => 'Vimeo.com, Inc., 330 West 34th Street, 5th Floor, New York, New York 10001, USA',
				'policy'   => 'Auf dieser Website sind Videos von Vimeo eingebettet. Die Videos werden erst geladen, wenn Sie dies durch einen Klick erlauben; erst dann werden Daten (u. a. Ihre IP-Adresse) an Vimeo in den USA übertragen. Rechtsgrundlage ist Ihre Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'Vimeo' ),
			),
			'google-maps' => array(
				'name'     => 'Google Maps',
				'category' => 'media',
				'hosts'    => array( 'maps.google.com', 'maps.googleapis.com', 'maps.gstatic.com', 'google.com/maps' ),
				'patterns' => array( '#google\.[a-z.]+/maps/embed#' ),
				'provider' => 'Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland',
				'policy'   => 'Auf dieser Website sind Karten von Google Maps eingebunden. Die Karte wird erst geladen, wenn Sie dies durch einen Klick erlauben; dann werden Daten (u. a. Ihre IP-Adresse) an Google übertragen und ggf. in den USA verarbeitet (EU-US Data Privacy Framework). Rechtsgrundlage ist Ihre Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'Google Maps', 'Maps' ),
			),
			'openstreetmap' => array(
				'name'     => 'OpenStreetMap',
				'category' => 'media',
				'hosts'    => array( 'openstreetmap.org', 'tile.openstreetmap.org' ),
				'patterns' => array(),
				'provider' => 'OpenStreetMap Foundation, St John’s Innovation Centre, Cowley Road, Cambridge, CB4 0WS, Vereinigtes Königreich',
				'policy'   => 'Auf dieser Website sind Karten von OpenStreetMap eingebunden. Beim Laden der Karte wird Ihre IP-Adresse an die OpenStreetMap Foundation übertragen. Die Karte wird erst nach Ihrer Einwilligung geladen (Art. 6 Abs. 1 lit. a DSGVO).',
				'consent'  => true,
				'aliases'  => array( 'OpenStreetMap' ),
			),
			'spotify' => array(
				'name'     => 'Spotify',
				'category' => 'media',
				'hosts'    => array( 'open.spotify.com', 'embed.spotify.com' ),
				'patterns' => array(),
				'provider' => 'Spotify AB, Regeringsgatan 19, 111 53 Stockholm, Schweden',
				'policy'   => 'Auf dieser Website sind Inhalte von Spotify eingebettet. Sie werden erst nach Ihrer Einwilligung geladen (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG); dann wird u. a. Ihre IP-Adresse an Spotify übertragen.',
				'consent'  => true,
				'aliases'  => array( 'Spotify' ),
			),
			'soundcloud' => array(
				'name'     => 'SoundCloud',
				'category' => 'media',
				'hosts'    => array( 'soundcloud.com', 'w.soundcloud.com' ),
				'patterns' => array(),
				'provider' => 'SoundCloud Global Limited & Co. KG, Rheinsberger Str. 76/77, 10115 Berlin',
				'policy'   => 'Auf dieser Website sind Inhalte von SoundCloud eingebettet. Sie werden erst nach Ihrer Einwilligung geladen (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'SoundCloud' ),
			),
			'instagram' => array(
				'name'     => 'Instagram',
				'category' => 'media',
				'hosts'    => array( 'instagram.com', 'cdninstagram.com' ),
				'patterns' => array( '#instagram\.com/embed\.js#' ),
				'provider' => 'Meta Platforms Ireland Limited, Merrion Road, Dublin 4, D04 X2K5, Irland',
				'policy'   => 'Auf dieser Website sind Beiträge von Instagram eingebettet. Sie werden erst nach Ihrer Einwilligung geladen (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'Instagram' ),
			),
			'x-twitter' => array(
				'name'     => 'X (Twitter)',
				'category' => 'media',
				'hosts'    => array( 'platform.twitter.com', 'twitter.com', 'x.com', 'twimg.com' ),
				'patterns' => array( '#platform\.twitter\.com/widgets\.js#' ),
				'provider' => 'Twitter International Unlimited Company, One Cumberland Place, Fenian Street, Dublin 2, D02 AX07, Irland',
				'policy'   => 'Auf dieser Website sind Beiträge von X (ehemals Twitter) eingebettet. Sie werden erst nach Ihrer Einwilligung geladen (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'Twitter', 'X (Twitter)', 'X Corp' ),
			),
			'recaptcha' => array(
				'name'     => 'Google reCAPTCHA',
				'category' => 'functional',
				'hosts'    => array( 'google.com/recaptcha', 'recaptcha.net', 'gstatic.com/recaptcha' ),
				'patterns' => array( '#grecaptcha#', '#google\.com/recaptcha/api\.js#' ),
				'provider' => 'Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland',
				'policy'   => 'Zum Schutz vor Spam nutzt diese Website Google reCAPTCHA. reCAPTCHA wertet Ihr Verhalten auf der Seite aus (u. a. IP-Adresse, Mausbewegungen, Verweildauer) und überträgt Daten ggf. in die USA. Die Nutzung erfolgt nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'reCAPTCHA', 'Recaptcha' ),
			),
			'google-fonts' => array(
				'name'     => 'Google Fonts',
				'category' => 'functional',
				'hosts'    => array( 'fonts.googleapis.com', 'fonts.gstatic.com' ),
				'patterns' => array(),
				'provider' => 'Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland',
				'policy'   => 'Diese Website bindet Schriftarten von Google Fonts ein. Beim Aufruf wird Ihre IP-Adresse an Google übertragen.',
				'consent'  => true,
				'aliases'  => array( 'Google Fonts', 'Web Fonts', 'Schriftarten' ),
			),
			'adobe-fonts' => array(
				'name'     => 'Adobe Fonts',
				'category' => 'functional',
				'hosts'    => array( 'use.typekit.net', 'p.typekit.net' ),
				'patterns' => array(),
				'provider' => 'Adobe Systems Software Ireland Limited, 4–6 Riverwalk, Citywest Business Campus, Dublin 24, Irland',
				'policy'   => 'Diese Website nutzt Schriftarten von Adobe Fonts (Typekit). Beim Aufruf wird Ihre IP-Adresse an Adobe übertragen.',
				'consent'  => true,
				'aliases'  => array( 'Adobe Fonts', 'Typekit' ),
			),
			'font-awesome' => array(
				'name'     => 'Font Awesome (CDN)',
				'category' => 'functional',
				'hosts'    => array( 'use.fontawesome.com', 'kit.fontawesome.com', 'ka-f.fontawesome.com' ),
				'patterns' => array(),
				'provider' => 'Fonticons, Inc., 6 Porter Road, Apartment 3R, Cambridge, MA 02140, USA',
				'policy'   => 'Diese Website lädt Symbole von Font Awesome. Dabei wird Ihre IP-Adresse an Fonticons, Inc. (USA) übertragen.',
				'consent'  => true,
				'aliases'  => array( 'Font Awesome', 'Fontawesome' ),
			),
			'public-cdn' => array(
				'name'     => 'Öffentliche CDNs (unpkg, jsDelivr, cdnjs …)',
				'category' => 'functional',
				'hosts'    => array( 'unpkg.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'code.jquery.com', 'ajax.googleapis.com', 'stackpath.bootstrapcdn.com', 'maxcdn.bootstrapcdn.com' ),
				'patterns' => array(),
				'provider' => 'verschiedene Anbieter',
				'policy'   => 'Diese Website lädt Programmbibliotheken von einem Content Delivery Network. Dabei wird Ihre IP-Adresse an den Betreiber übertragen.',
				'consent'  => true,
				'aliases'  => array( 'CDN', 'Content Delivery' ),
			),
			'gravatar' => array(
				'name'     => 'Gravatar',
				'category' => 'functional',
				'hosts'    => array( 'gravatar.com', 'secure.gravatar.com' ),
				'patterns' => array(),
				'provider' => 'Automattic Inc., 60 29th Street #343, San Francisco, CA 94110, USA',
				'policy'   => 'Für Profilbilder in Kommentaren nutzt diese Website den Dienst Gravatar. Dabei wird ein Hashwert Ihrer E-Mail-Adresse und Ihre IP-Adresse an Automattic Inc. (USA) übertragen.',
				'consent'  => true,
				'aliases'  => array( 'Gravatar' ),
			),
			'wp-emoji' => array(
				'name'     => 'WordPress-Emoji (s.w.org)',
				'category' => 'functional',
				'hosts'    => array( 's.w.org' ),
				'patterns' => array(),
				'provider' => 'WordPress.org / Automattic Inc., USA',
				'policy'   => 'Ältere Browser laden Emoji-Grafiken von s.w.org; dabei wird Ihre IP-Adresse übertragen.',
				'consent'  => false,
				'aliases'  => array( 'Emoji', 's.w.org' ),
			),
			'paypal' => array(
				'name'     => 'PayPal',
				'category' => 'necessary',
				'hosts'    => array( 'paypal.com', 'paypalobjects.com' ),
				'patterns' => array(),
				'provider' => 'PayPal (Europe) S.à r.l. et Cie, S.C.A., 22–24 Boulevard Royal, L-2449 Luxemburg',
				'policy'   => 'Für Zahlungen bieten wir PayPal an. Wenn Sie PayPal wählen, werden die für die Zahlung nötigen Daten an PayPal übermittelt (Art. 6 Abs. 1 lit. b DSGVO).',
				'consent'  => false,
				'aliases'  => array( 'PayPal' ),
			),
			'stripe' => array(
				'name'     => 'Stripe',
				'category' => 'necessary',
				'hosts'    => array( 'js.stripe.com', 'stripe.com', 'stripe.network' ),
				'patterns' => array(),
				'provider' => 'Stripe Payments Europe, Ltd., 1 Grand Canal Street Lower, Grand Canal Dock, Dublin, Irland',
				'policy'   => 'Für Zahlungen nutzen wir den Zahlungsdienst Stripe. Die für die Zahlung nötigen Daten werden an Stripe übermittelt (Art. 6 Abs. 1 lit. b DSGVO). Stripe setzt zur Betrugsprävention technisch notwendige Cookies.',
				'consent'  => false,
				'aliases'  => array( 'Stripe' ),
			),
			'mailchimp' => array(
				'name'     => 'Mailchimp',
				'category' => 'marketing',
				'hosts'    => array( 'list-manage.com', 'chimpstatic.com', 'mailchimp.com' ),
				'patterns' => array(),
				'provider' => 'The Rocket Science Group LLC d/b/a Mailchimp, 675 Ponce de Leon Ave NE, Suite 5000, Atlanta, GA 30308, USA',
				'policy'   => 'Für den Versand unseres Newsletters nutzen wir Mailchimp. Ihre Anmeldedaten werden an Mailchimp (USA) übertragen (Art. 6 Abs. 1 lit. a DSGVO).',
				'consent'  => true,
				'aliases'  => array( 'Mailchimp', 'Newsletter' ),
			),
			'calendly' => array(
				'name'     => 'Calendly',
				'category' => 'media',
				'hosts'    => array( 'calendly.com', 'assets.calendly.com' ),
				'patterns' => array(),
				'provider' => 'Calendly LLC, 271 17th St NW, Ste 1000, Atlanta, Georgia, 30363, USA',
				'policy'   => 'Für die Terminbuchung ist Calendly eingebunden. Das Buchungsfenster wird erst nach Ihrer Einwilligung geladen; dann werden Daten an Calendly (USA) übertragen.',
				'consent'  => true,
				'aliases'  => array( 'Calendly' ),
			),
		);
		return (array) apply_filters( 'plakette_services', $s );
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** Service id for a URL or host, or ''. */
	public static function for_url( $url ) {
		$url = html_entity_decode( (string) $url );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			$host = strtolower( $url ); // bare host
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		foreach ( self::all() as $id => $s ) {
			foreach ( $s['hosts'] as $h ) {
				$h_host = strtok( $h, '/' );
				$h_path = substr( $h, strlen( $h_host ) );
				if ( ( $host === $h_host || substr( $host, -strlen( '.' . $h_host ) ) === '.' . $h_host ) && ( '' === $h_path || 0 === strpos( $path, $h_path ) ) ) {
					return $id;
				}
			}
		}
		return '';
	}

	/**
	 * Services found in HTML (resources + inline code). Ignores markup the consent blocker already holds back.
	 *
	 * @return array id => [ 'blocked' => bool (true if every hit is held back by a consent tool), 'where' => [hosts/pattern hits] ]
	 */
	public static function detect( $html ) {
		$found = array();
		if ( '' === (string) $html ) {
			return $found;
		}
		$add = function ( $id, $where, $blocked ) use ( &$found ) {
			if ( ! isset( $found[ $id ] ) ) {
				$found[ $id ] = array( 'blocked' => true, 'where' => array() );
			}
			$found[ $id ]['blocked'] = $found[ $id ]['blocked'] && $blocked;
			if ( count( $found[ $id ]['where'] ) < 5 && ! in_array( $where, $found[ $id ]['where'], true ) ) {
				$found[ $id ]['where'][] = $where;
			}
		};
		// Resources: <script src>, <link href>, <iframe src/data-src>, <img src>.
		if ( preg_match_all( '#<(script|link|iframe|img|embed|object|source)\b[^>]*>#i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$blocked = self::is_held_back( $tag );
				foreach ( array( 'src', 'href', 'data-src', 'data-plakette-src' ) as $attr ) {
					$url = Plakette_Html::attr( $tag, $attr );
					if ( '' === $url || ! preg_match( '#^(https?:)?//#i', $url ) ) {
						continue;
					}
					$id = self::for_url( $url );
					if ( $id ) {
						$add( $id, (string) wp_parse_url( ( 0 === strpos( $url, '//' ) ? 'https:' : '' ) . $url, PHP_URL_HOST ), $blocked || 'data-plakette-src' === $attr );
					}
				}
			}
		}
		// Inline code.
		if ( preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER ) ) {
			foreach ( $scripts as $sc ) {
				if ( '' === trim( $sc[2] ) ) {
					continue;
				}
				$blocked = self::is_held_back( '<script' . $sc[1] . '>' );
				foreach ( self::all() as $id => $s ) {
					foreach ( $s['patterns'] as $re ) {
						if ( @preg_match( $re, $sc[2] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
							$add( $id, 'Inline-Skript', $blocked );
							break;
						}
					}
				}
			}
		}
		return $found;
	}

	/** Does a consent tool hold this tag back until the visitor agrees? (ours and common plugins) */
	public static function is_held_back( $tag ) {
		$type = strtolower( Plakette_Html::attr( $tag, 'type' ) );
		if ( 'text/plain' === $type || 'text/blocked' === $type || false !== strpos( $type, 'cookieconsent' ) ) {
			return true;
		}
		foreach ( array( 'data-plakette-consent', 'data-cookieconsent', 'data-borlabs-cookie', 'data-category', 'data-cookiecategory', 'data-service', 'data-cmp-src', 'data-usercentrics', 'data-cookie-consent', 'data-cli-script-type', 'data-cmplz-src', 'consent-original-src-_', 'data-rcb-src' ) as $attr ) {
			if ( Plakette_Html::has_attr( $tag, $attr ) ) {
				return true;
			}
		}
		// Iframes kept empty until consent.
		if ( preg_match( '#^<iframe#i', $tag ) && '' === Plakette_Html::attr( $tag, 'src' ) && '' !== Plakette_Html::attr( $tag, 'data-src' ) && preg_match( '#cmplz|borlabs|consent|plakette#i', $tag ) ) {
			return true;
		}
		return false;
	}

	/** Known consent management tools: name or '' (constants, classes and markers in the HTML). */
	public static function consent_tool( $html = '' ) {
		$known = array(
			'BORLABS_COOKIE_VERSION'      => 'Borlabs Cookie',
			'cmplz_version'               => 'Complianz',
			'CMPLZ_VERSION'               => 'Complianz',
			'RCB_VERSION'                 => 'Real Cookie Banner',
			'CKY_APP_VERSION'             => 'CookieYes',
			'CLI_VERSION'                 => 'CookieYes / GDPR Cookie Consent',
			'COOKIEBOT_PLUGIN_VERSION'    => 'Cookiebot',
			'MOOVE_GDPR_VERSION'          => 'GDPR Cookie Compliance',
			'CN_VERSION'                  => 'Cookie Notice',
			'IUBENDA_PLUGIN_VERSION'      => 'iubenda',
			'USERCENTRICS_PLUGIN_VERSION' => 'Usercentrics',
		);
		foreach ( $known as $const => $name ) {
			if ( defined( $const ) ) {
				return $name;
			}
		}
		if ( class_exists( 'Cookiebot_WP' ) ) {
			return 'Cookiebot';
		}
		$markers = array(
			'consent.cookiebot.com'       => 'Cookiebot',
			'app.usercentrics.eu'         => 'Usercentrics',
			'web.cmp.usercentrics.eu'     => 'Usercentrics',
			'cdn.cookielaw.org'           => 'OneTrust',
			'cdn.consentmanager.net'      => 'consentmanager',
			'cdn-cookieyes.com'           => 'CookieYes',
			'klaro.js'                    => 'Klaro',
			'cdn.iubenda.com'             => 'iubenda',
			'BorlabsCookie'               => 'Borlabs Cookie',
			'cmplz-cookiebanner'          => 'Complianz',
			'data-plakette-consent-root'  => 'Plakette',
		);
		foreach ( $markers as $needle => $name ) {
			if ( '' !== $html && false !== strpos( $html, $needle ) ) {
				return $name;
			}
		}
		return '';
	}

	public static function category_label( $cat ) {
		$labels = array(
			'necessary'  => __( 'Notwendig', 'plakette' ),
			'functional' => __( 'Funktional', 'plakette' ),
			'statistics' => __( 'Statistik', 'plakette' ),
			'marketing'  => __( 'Marketing', 'plakette' ),
			'media'      => __( 'Externe Medien', 'plakette' ),
		);
		return isset( $labels[ $cat ] ) ? $labels[ $cat ] : $cat;
	}
}
