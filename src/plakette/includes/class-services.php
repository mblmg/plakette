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
			'microsoft-ads' => array(
				'name'     => 'Microsoft Advertising (Bing UET)',
				'category' => 'marketing',
				'hosts'    => array( 'bat.bing.com', 'bat.bing.net' ),
				'patterns' => array( '#bat\.bing\.com/bat\.js#', '#uetq\s*=#' ),
				'provider' => 'Microsoft Ireland Operations Limited, One Microsoft Place, South County Business Park, Leopardstown, Dublin 18, Irland',
				'policy'   => 'Diese Website nutzt das Conversion-Tracking „Universal Event Tracking“ von Microsoft Advertising, um den Erfolg von Anzeigen in der Suchmaschine Bing zu messen. Dabei werden Cookies gesetzt und Daten ggf. in die USA übertragen (EU-US Data Privacy Framework). Die Nutzung erfolgt nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG).',
				'consent'  => true,
				'aliases'  => array( 'Bing', 'Microsoft Advertising', 'UET' ),
			),
			// VG Wort counting pixel (METIS). Classification as of 09.11.2026, sources in self::vgwort_note().
			// VG Wort's own documentation (METIS integration description v2.28, 15.09.2026) still names a "METIS Session
			// Cookie" (or alternatively a browser signature); VG Wort and Dr. Schwenke consider it exempt from consent,
			// IT-Recht Kanzlei (2019) and eRecht24 (2022) recommend consent. No court decision. → 'consent' => false,
			// 'disputed' => true: it does not trigger the banner, the report explains the open question.
			'vg-wort' => array(
				'name'     => 'VG-Wort-Zählpixel (METIS)',
				'category' => 'statistics',
				'hosts'    => array( 'met.vgwort.de' ),
				'patterns' => array( '#[a-z0-9-]+\.met\.vgwort\.de/na/#' ),
				'provider' => 'Verwertungsgesellschaft WORT, Untere Weidenstraße 5, 81543 München (Messung durch Fifty5Blue Deutschland GmbH, Halbergstr. 4, 66121 Saarbrücken)',
				'policy'   => 'Wir nutzen die METIS-Zugriffszählung der VG WORT (Verwertungsgesellschaft WORT, Untere Weidenstraße 5, 81543 München), durchgeführt von der Fifty5Blue Deutschland GmbH, Halbergstr. 4, 66121 Saarbrücken. Dabei lädt Ihr Browser eine „Zählmarke“ (ein 1×1-Pixel) von einem Server unter met.vgwort.de. Um Mehrfachzählungen zu vermeiden, wird nach Angaben der VG WORT ein Session-Cookie gesetzt oder alternativ eine Signatur aus automatisch übertragenen Browserinformationen gebildet; IP-Adressen werden nur anonymisiert verarbeitet, einzelne Nutzer werden nicht identifiziert. Zweck ist die Ermittlung der Zugriffszahlen unserer Texte als Grundlage der gesetzlichen Vergütung nach dem Urheberrechtsgesetz. Soweit dabei personenbezogene Daten verarbeitet werden, ist Rechtsgrundlage unser berechtigtes Interesse an der urheberrechtlichen Vergütung (Art. 6 Abs. 1 lit. f DSGVO); sofern wir Ihre Einwilligung einholen, ist Rechtsgrundlage Art. 6 Abs. 1 lit. a DSGVO in Verbindung mit § 25 Abs. 1 TDDDG.',
				'consent'  => false,
				'disputed' => true,
				'aliases'  => array( 'VG Wort', 'VG WORT', 'METIS', 'Zählmarke', 'Zählpixel' ),
			),
			'cloudflare-insights' => array(
				'name'     => 'Cloudflare Web Analytics',
				'category' => 'statistics',
				'hosts'    => array( 'static.cloudflareinsights.com', 'cloudflareinsights.com' ),
				'patterns' => array( '#static\.cloudflareinsights\.com/beacon#' ),
				'provider' => 'Cloudflare, Inc., 101 Townsend St., San Francisco, CA 94107, USA',
				'policy'   => 'Diese Website nutzt Cloudflare Web Analytics für eine Reichweitenmessung ohne Cookies. Dabei wird Ihre IP-Adresse an Cloudflare (USA) übertragen. Die Nutzung erfolgt nur mit Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO).',
				'consent'  => true,
				'aliases'  => array( 'Cloudflare Web Analytics', 'Cloudflare Insights' ),
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

	/* ------------------------------------------------------------------ cookies and browser storage */

	/**
	 * What a cookie or storage key is, whether it is technically necessary (§ 25 Abs. 2 Nr. 2 TDDDG) and how to avoid it.
	 *
	 * Each entry:
	 *   re         regex on the name (cookie name or localStorage/sessionStorage key)
	 *   label      what it is (service)
	 *   service    catalogue id (if any) – the measure for the service applies
	 *   purpose    what it does
	 *   necessary  yes | no | depends
	 *   reason     why (for "depends": when it is necessary)
	 *   plugin     which plugin typically sets it
	 *   measure    what avoids it (plain text, du-form)
	 *
	 * @return array[]
	 */
	public static function storage_kb() {
		$kb = array(
			array( 're' => '/^(_ga|_ga_[A-Z0-9]+|_gid|_gat.*|__utm[a-z])$/', 'label' => 'Google Analytics', 'service' => 'google-analytics', 'purpose' => __( 'Wiedererkennung von Besuchern für Statistiken', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Statistik ist für das Anzeigen der Seite nicht nötig.', 'plakette' ), 'plugin' => 'Site Kit, MonsterInsights, ExactMetrics, GA Google Analytics oder Code im Theme', 'measure' => __( 'Google Analytics entfernen und durch die Plakette-Statistik (ohne Cookies) ersetzen.', 'plakette' ) ),
			array( 're' => '/^(_gcl_.*|_gac_.*|IDE|test_cookie|NID|1P_JAR|AEC)$/', 'label' => 'Google Ads / DoubleClick', 'service' => 'google-ads', 'purpose' => __( 'Erfolgsmessung von Anzeigen, Werbe-Profile', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Werbung ist nicht technisch notwendig.', 'plakette' ), 'plugin' => 'Site Kit, Google Tag Manager, Code im Theme', 'measure' => __( 'Nur mit Banner möglich – brauchst du das Conversion-Tracking wirklich?', 'plakette' ) ),
			array( 're' => '/^(_fbp|_fbc|fr)$/', 'label' => 'Meta Pixel (Facebook)', 'service' => 'facebook-pixel', 'purpose' => __( 'Erfolgsmessung von Facebook-/Instagram-Werbung, Zielgruppen', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Werbung ist nicht technisch notwendig.', 'plakette' ), 'plugin' => 'PixelYourSite, Meta for WordPress (Facebook for WordPress), Code im Theme', 'measure' => __( 'Nur mit Banner möglich – brauchst du den Pixel wirklich?', 'plakette' ) ),
			array( 're' => '/^(_hj.*|hjActiveViewportIds|hjViewportId)$/', 'label' => 'Hotjar', 'service' => 'hotjar', 'purpose' => __( 'Aufzeichnung von Klicks und Mausbewegungen', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Verhaltensanalyse ist nicht technisch notwendig.', 'plakette' ), 'plugin' => 'Hotjar-Plugin oder Code im Theme', 'measure' => __( 'Hotjar entfernen; für Besucherzahlen reicht die Plakette-Statistik.', 'plakette' ) ),
			array( 're' => '/^(_clck|_clsk|CLID|ANONCHK)$/', 'label' => 'Microsoft Clarity', 'service' => 'clarity', 'purpose' => __( 'Sitzungsaufzeichnung und Heatmaps', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Verhaltensanalyse ist nicht technisch notwendig.', 'plakette' ), 'plugin' => 'Microsoft Clarity-Plugin oder Code im Theme', 'measure' => __( 'Clarity entfernen; für Besucherzahlen reicht die Plakette-Statistik.', 'plakette' ) ),
			array( 're' => '/^(_uet.*|MUID)$/', 'label' => 'Microsoft Advertising', 'service' => 'microsoft-ads', 'purpose' => __( 'Erfolgsmessung von Bing-Anzeigen', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Werbung ist nicht technisch notwendig.', 'plakette' ), 'plugin' => 'Code im Theme oder Tag Manager', 'measure' => __( 'Nur mit Banner möglich – brauchst du das Tracking wirklich?', 'plakette' ) ),
			array( 're' => '/^(_pk_.*|MATOMO_.*|mtm_.*|PIWIK_.*)$/', 'label' => 'Matomo', 'service' => 'matomo-cloud', 'purpose' => __( 'Wiedererkennung von Besuchern für Statistiken', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Mit Cookies braucht Matomo eine Einwilligung; ohne Cookies nicht.', 'plakette' ), 'plugin' => 'Matomo / WP-Matomo (WP-Piwik)', 'measure' => __( 'Matomo ohne Cookies betreiben (Einstellung „Cookies deaktivieren“, im Code _paq.push([\'disableCookies\'])) oder die Plakette-Statistik nutzen.', 'plakette' ) ),
			array( 're' => '/^(li_.*|lidc|bcookie|bscookie|AnalyticsSyncHistory|UserMatchHistory|lms_.*)$/', 'label' => 'LinkedIn Insight Tag', 'service' => 'linkedin-insight', 'purpose' => __( 'Erfolgsmessung von LinkedIn-Anzeigen', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Werbung ist nicht technisch notwendig.', 'plakette' ), 'plugin' => 'Code im Theme oder Tag Manager', 'measure' => __( 'Nur mit Banner möglich – brauchst du das Insight Tag wirklich?', 'plakette' ) ),
			array( 're' => '/^(_tt_.*|_ttp|tt_.*|ttwid)$/', 'label' => 'TikTok Pixel', 'service' => 'tiktok-pixel', 'purpose' => __( 'Erfolgsmessung von TikTok-Anzeigen', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Werbung ist nicht technisch notwendig.', 'plakette' ), 'plugin' => 'TikTok-Plugin, PixelYourSite oder Code im Theme', 'measure' => __( 'Nur mit Banner möglich – brauchst du den Pixel wirklich?', 'plakette' ) ),
			array( 're' => '/^(_grecaptcha|rc::[a-z])$/', 'label' => 'Google reCAPTCHA', 'service' => 'recaptcha', 'purpose' => __( 'Spamschutz mit Verhaltensanalyse durch Google', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Die Aufsichtsbehörden sehen reCAPTCHA nicht als technisch notwendig an.', 'plakette' ), 'plugin' => 'Contact Form 7, WPForms, Elementor-Formulare u. a.', 'measure' => __( 'Spamschutz ohne Google: Honigtopf/Zeitfalle (Contact Form 7: „Honeypot for Contact Form 7“, WPForms: eingebauter Spamschutz) oder Friendly Captcha (EU-gehostet).', 'plakette' ) ),
			array( 're' => '/^(yt-.*|ytidb::.*|VISITOR_INFO1_LIVE|VISITOR_PRIVACY_METADATA|YSC|PREF|__Secure-YEC|LOGIN_INFO)$/', 'label' => 'YouTube', 'service' => 'youtube', 'purpose' => __( 'Wiedererkennung durch YouTube/Google', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Ein eingebettetes Video ist erst nötig, wenn der Besucher es abspielen will.', 'plakette' ), 'plugin' => __( 'Eingebettete Videos', 'plakette' ), 'measure' => __( 'Zwei-Klick-Lösung: das Video lädt erst nach Klick.', 'plakette' ) ),
			array( 're' => '/^(vuid|__cf_bm_vimeo)$/', 'label' => 'Vimeo', 'service' => 'vimeo', 'purpose' => __( 'Wiedererkennung durch Vimeo', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Ein eingebettetes Video ist erst nötig, wenn der Besucher es abspielen will.', 'plakette' ), 'plugin' => __( 'Eingebettete Videos', 'plakette' ), 'measure' => __( 'Zwei-Klick-Lösung: das Video lädt erst nach Klick.', 'plakette' ) ),
			array( 're' => '/^wpEmojiSettingsSupports$/', 'label' => __( 'WordPress-Emoji-Skript', 'plakette' ), 'service' => '', 'fix' => 'speed_emoji', 'purpose' => __( 'Merkt sich, ob der Browser Emojis selbst darstellen kann', 'plakette' ), 'necessary' => 'no', 'reason' => __( 'Heutige Browser zeigen Emojis selbst an – der Eintrag ist nicht nötig, aber ein Speicherzugriff auf dem Gerät.', 'plakette' ), 'plugin' => 'WordPress', 'measure' => __( 'Emoji-Skript abschalten.', 'plakette' ) ),
			array( 're' => '/^PHPSESSID$/', 'label' => __( 'PHP-Sitzung', 'plakette' ), 'service' => '', 'purpose' => __( 'Ein Plugin startet für jeden Besucher eine Sitzung auf dem Server', 'plakette' ), 'necessary' => 'depends', 'reason' => __( 'Nur nötig, wenn der Besucher eine Funktion nutzt, die sie braucht (Login, Warenkorb, Formular mit mehreren Schritten) – nicht schon beim ersten Aufruf.', 'plakette' ), 'plugin' => '', 'measure' => __( 'Das Plugin so einstellen, dass es die Sitzung erst bei Bedarf startet, oder es ersetzen.', 'plakette' ) ),
			array( 're' => '/^wordpress_test_cookie$/', 'label' => 'WordPress', 'service' => '', 'purpose' => __( 'Prüft, ob der Browser Cookies annimmt – für die Anmeldung', 'plakette' ), 'necessary' => 'yes', 'reason' => __( 'Nur für die Anmeldung; normalerweise nur auf der Login-Seite.', 'plakette' ), 'plugin' => 'WordPress', 'measure' => '' ),
			array( 're' => '/^(wp-settings-.*|wp-settings-time-.*|wordpress_logged_in_.*|wordpress_sec_.*|wordpress_[0-9a-f]{32}|wp_lang|wp-postpass_.*)$/', 'label' => 'WordPress', 'service' => '', 'purpose' => __( 'Anmeldung, Sprache der Login-Seite, passwortgeschützte Beiträge', 'plakette' ), 'necessary' => 'yes', 'reason' => __( 'Nur für angemeldete Nutzer bzw. nach einer Eingabe des Besuchers.', 'plakette' ), 'plugin' => 'WordPress', 'measure' => '' ),
			array( 're' => '/^comment_author_.*$/', 'label' => __( 'WordPress-Kommentare', 'plakette' ), 'service' => '', 'purpose' => __( 'Merkt Name und E-Mail für den nächsten Kommentar', 'plakette' ), 'necessary' => 'yes', 'reason' => __( 'Nur nach ausdrücklicher Zustimmung im Kommentarformular.', 'plakette' ), 'plugin' => 'WordPress', 'measure' => '' ),
			array( 're' => '/^(woocommerce_cart_hash|woocommerce_items_in_cart|wp_woocommerce_session_.*|wc_cart_hash_.*|wc_fragments_.*|wc_cart_created|woocommerce_dismissed_.*|sbjs_.*)$/', 'label' => 'WooCommerce', 'service' => '', 'purpose' => __( 'Warenkorb', 'plakette' ), 'necessary' => 'yes', 'reason' => __( 'Nötig, sobald jemand einkauft; WooCommerce setzt die Cookies normalerweise erst, wenn etwas im Warenkorb liegt.', 'plakette' ), 'plugin' => 'WooCommerce', 'measure' => '' ),
			array( 're' => '/^(woocommerce_recently_viewed)$/', 'label' => 'WooCommerce', 'service' => '', 'purpose' => __( 'Zuletzt angesehene Produkte', 'plakette' ), 'necessary' => 'depends', 'reason' => __( 'Komfortfunktion, nicht notwendig.', 'plakette' ), 'plugin' => 'WooCommerce (Widget „Zuletzt angesehene Produkte“)', 'measure' => __( 'Das Widget „Zuletzt angesehene Produkte“ entfernen.', 'plakette' ) ),
			array( 're' => '/^(pll_language|wp-wpml_current_language|_icl_current_language|wp-wpml_current_admin_language_.*|trp_language)$/', 'label' => __( 'Sprachwahl', 'plakette' ), 'service' => '', 'purpose' => __( 'Merkt die Sprache der Website', 'plakette' ), 'necessary' => 'depends', 'reason' => __( 'Notwendig, wenn der Besucher die Sprache selbst gewählt hat. Schon beim ersten Besuch gesetzt, ist es umstritten.', 'plakette' ), 'plugin' => 'Polylang, WPML, TranslatePress', 'measure' => __( 'Das Cookie erst nach einer Sprachwahl setzen: Polylang → in wp-config.php „define( \'PLL_COOKIE\', false );“; WPML → Sprachen › „Cookie für Sprachwahl“ abschalten (die Sprache steht ohnehin in der Adresse).', 'plakette' ) ),
			array( 're' => '/^(cf_clearance|__cf_bm|__cflb|_cfuvid|cf_chl_.*)$/', 'label' => 'Cloudflare', 'service' => '', 'purpose' => __( 'Schutz vor Bots und Angriffen', 'plakette' ), 'necessary' => 'yes', 'reason' => __( 'Sicherheit des Angebots – technisch notwendig.', 'plakette' ), 'plugin' => __( 'Cloudflare (beim Hoster bzw. DNS)', 'plakette' ), 'measure' => '' ),
			array( 're' => '/^(__stripe_mid|__stripe_sid)$/', 'label' => 'Stripe', 'service' => 'stripe', 'purpose' => __( 'Betrugsschutz bei Zahlungen', 'plakette' ), 'necessary' => 'yes', 'reason' => __( 'Für eine sichere Zahlung notwendig.', 'plakette' ), 'plugin' => 'WooCommerce Stripe, WP Simple Pay u. a.', 'measure' => '' ),
			array( 're' => '/^(plakette_consent|borlabs-cookie|borlabs-cookie-.*|cmplz_.*|complianz_.*|CookieConsent|CookieConsentBulkSetting-.*|real_cookie_banner.*|rcb-.*|cookieyes-consent|cky-consent|cky-action|OptanonConsent|OptanonAlertBoxClosed|uc_settings|uc_user_interaction|ucData|euconsent-v2|moove_gdpr_popup|cookielawinfo-.*|viewed_cookie_policy|cookie_notice_accepted|gdpr\[.*|iubenda.*|_iub_cs-.*|cookie-agreed.*|klaro|cmapi_.*|__cmpcc.*)$/', 'label' => __( 'Cookie-Banner', 'plakette' ), 'service' => '', 'purpose' => __( 'Speichert die Einwilligung des Besuchers', 'plakette' ), 'necessary' => 'yes', 'reason' => __( 'Der Nachweis der Einwilligung ist technisch notwendig.', 'plakette' ), 'plugin' => 'Plakette, Borlabs Cookie, Complianz, Real Cookie Banner, CookieYes, Cookiebot, Usercentrics …', 'measure' => '' ),
		);
		return (array) apply_filters( 'plakette_storage_kb', $kb );
	}

	/** Knowledge-base entry for a cookie or storage key; unknown names get a "depends" entry. */
	public static function classify_storage( $name ) {
		$name = (string) $name;
		foreach ( self::storage_kb() as $e ) {
			if ( @preg_match( $e['re'], $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return $e;
			}
		}
		return array(
			're'        => '',
			'label'     => __( 'unbekannt', 'plakette' ),
			'service'   => '',
			'purpose'   => __( 'unbekannt', 'plakette' ),
			'necessary' => 'depends',
			'reason'    => __( 'Plakette kennt diesen Namen nicht.', 'plakette' ),
			'plugin'    => '',
			'measure'   => __( 'Prüfen, welches Plugin es setzt (Plugins einzeln deaktivieren und neu messen), und ob es technisch nötig ist.', 'plakette' ),
		);
	}

	/** Regex (JavaScript syntax) matching the cookies of consent tools – the browser probe removes them for a "first visit". */
	public static function consent_cookie_pattern() {
		return '^(plakette_consent|borlabs-cookie.*|cmplz_.*|complianz_.*|CookieConsent.*|real_cookie_banner.*|rcb-.*|cookieyes-consent|cky-.*|OptanonConsent|OptanonAlertBoxClosed|uc_.*|euconsent-v2|moove_gdpr_popup|cookielawinfo-.*|viewed_cookie_policy|cookie_notice_accepted|_iub_cs-.*|klaro|cmapi_.*|__cmpcc.*)$';
	}

	/**
	 * Plugins that typically add a service: basename => [ name, service ids ]. Used to offer "deactivate the plugin" instead
	 * of stripping its output.
	 */
	public static function plugins() {
		return (array) apply_filters(
			'plakette_service_plugins',
			array(
				'google-site-kit/google-site-kit.php'                         => array( 'Site Kit by Google', array( 'google-analytics', 'google-tag-manager', 'google-ads' ) ),
				'google-analytics-for-wordpress/googleanalytics.php'          => array( 'MonsterInsights', array( 'google-analytics', 'google-tag-manager' ) ),
				'google-analytics-premium/googleanalytics-premium.php'        => array( 'MonsterInsights Pro', array( 'google-analytics', 'google-tag-manager' ) ),
				'google-analytics-dashboard-for-wp/gadwp.php'                 => array( 'ExactMetrics', array( 'google-analytics', 'google-tag-manager' ) ),
				'ga-google-analytics/ga-google-analytics.php'                 => array( 'GA Google Analytics', array( 'google-analytics', 'google-tag-manager' ) ),
				'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' => array( 'GTM4WP', array( 'google-tag-manager' ) ),
				'pixelyoursite/pixelyoursite.php'                             => array( 'PixelYourSite', array( 'facebook-pixel', 'google-analytics', 'tiktok-pixel' ) ),
				'official-facebook-pixel/facebook-for-wordpress.php'          => array( 'Meta for WordPress', array( 'facebook-pixel' ) ),
				'facebook-for-woocommerce/facebook-for-woocommerce.php'       => array( 'Facebook for WooCommerce', array( 'facebook-pixel' ) ),
				'hotjar/hotjar.php'                                           => array( 'Hotjar', array( 'hotjar' ) ),
				'microsoft-clarity/clarity.php'                               => array( 'Microsoft Clarity', array( 'clarity' ) ),
				'insert-headers-and-footers/ihaf.php'                         => array( 'WPCode (Kopf- und Fußzeilen-Code)', array() ),
			)
		);
	}

	/** Active plugin that delivers a service: [ basename, name ] or null. */
	public static function plugin_for( $service ) {
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( self::plugins() as $base => $p ) {
			if ( in_array( $service, $p[1], true ) && in_array( $base, $active, true ) ) {
				return array( $base, $p[0] );
			}
		}
		return null;
	}

	/** What the plugin user should know about the VG Wort pixel: [ text, links ]. Stand: 09.11.2026. */
	public static function vgwort_note() {
		return array(
			__( 'VG-Wort-Zählpixel: Laut VG Wort (Integrationsbeschreibung METIS, Version 2.28 vom 15.09.2026) setzt der Pixel ein „METIS Session Cookie“ oder bildet ersatzweise eine Signatur aus Browserdaten; IP-Adressen werden nur gekürzt verarbeitet. VG Wort und Dr. Schwenke (Stand 13.08.2025) halten das für einwilligungsfrei, IT-Recht Kanzlei (2019) und eRecht24 (2022) raten zur Einwilligung; ein Gerichtsurteil gibt es nicht. Plakette wertet den Pixel deshalb nicht als Grund für einen Cookie-Banner – er gehört aber in die Datenschutzerklärung (berechtigtes Interesse an der Vergütung nach dem Urheberrecht). Wer ganz sicher gehen will, lädt ihn nur nach Einwilligung. (Eine eigene Messung der Cookies war von hier aus nicht möglich. Stand der Prüfung: 09.11.2026.)', 'plakette' ),
			array(
				array( 'label' => __( 'VG Wort: METIS-Integrationsbeschreibung (PDF, Datenschutz-Abschnitt)', 'plakette' ), 'url' => 'https://tom.vgwort.de/Documents/pdfs/dokumentation/metis/DOC_Verlagsmeldung.pdf' ),
				array( 'label' => __( 'Dr. Schwenke: TDDDG und Cookies', 'plakette' ), 'url' => 'https://datenschutz-generator.de/tdddg-cookies/' ),
				array( 'label' => __( 'eRecht24: VG-Wort-Zählpixel', 'plakette' ), 'url' => 'https://www.e-recht24.de/dsg/12967-vg-wort-zaehlpixel.html' ),
				array( 'label' => __( 'IT-Recht Kanzlei: Cookies der VG Wort', 'plakette' ), 'url' => 'https://www.it-recht-kanzlei.de/cookies-vg-wort-einwilligungspflicht.html' ),
			),
		);
	}

	/** Service id of one script element (src or inline code), or ''. */
	public static function for_script( $open_tag, $code ) {
		$src = Plakette_Html::attr( $open_tag, 'src' );
		if ( '' === $src ) {
			$src = Plakette_Html::attr( $open_tag, 'data-plakette-src' );
		}
		if ( '' !== $src ) {
			return preg_match( '#^(https?:)?//#i', $src ) ? self::for_url( $src ) : '';
		}
		if ( '' === trim( (string) $code ) ) {
			return '';
		}
		foreach ( self::all() as $id => $s ) {
			foreach ( $s['patterns'] as $re ) {
				if ( @preg_match( $re, $code ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return $id;
				}
			}
		}
		return '';
	}
}
