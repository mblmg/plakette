# Plakette – Aufbau für Entwickler

Plugin-Quelle: `src/plakette/` (Präfix `Plakette_`, Optionen `plakette_*`, CSS `plk-`, Textdomain `plakette`).
Mindestens **PHP 8.2** (bis 8.5) – nur Versionen mit Sicherheitsupdates: `str_contains`, `match`, Enums, `readonly`-Klassen usw. sind erlaubt. Oberfläche und alle Texte auf **Deutsch, Du-Form, einfach**
(für Einsteiger: was ist das, warum wichtig). Rechtstexte für Besucher (Impressum, Datenschutzerklärung) in **Sie-Form**.

## Ablauf
1. `Plakette_Auditor::run()` instanziert alle Prüfungen (`Plakette_Check`-Unterklassen) und ruft `run( $ctx )`.
2. Die Admin-Oberfläche (`admin/js/admin.js`, kein Build) zeigt den Bericht, je Mangel ein Knopf → REST `/fix`.
3. `fix( $req, &$undo )` ändert etwas und füllt `$undo` mit Rückgängig-Operationen (siehe `class-log.php`).
   Das Protokoll speichert sie; „Rückgängig“ spielt sie rückwärts ab. **Jede Änderung muss rückgängig machbar
   sein** – sonst `$undoable = false` setzen (die Oberfläche warnt dann).
4. Dauerhafte Laufzeit-Funktionen („Module“) schaltet ein Fix nur per Flag ein:
   `Plakette_Log::set_module( 'mein_key', true|array, $undo )`. Die Modul-Datei prüft beim Start
   `Plakette_Modules::on( 'mein_key' )` bzw. `::get()` und hängt ihre Filter ein.

## Eine Prüfung schreiben (`includes/checks-<thema>.php`)
```php
class Plakette_Check_Xyz extends Plakette_Check {
	public $id        = 'security_xyz';      // eindeutig, Präfix = Bereich
	public $area      = 'security';          // legal | security | speed | seo (Plakette_Auditor::areas())
	public $group     = 'Schutz vor Angriffen';
	public $weight    = 6;                   // 1–10; fail mit weight ≥ 6 = „erheblicher Mangel“
	public $title     = 'Zielzustand (wenn alles gut ist)';
	public $problem   = 'Kurz: was falsch ist';
	public $explain   = 'Für Einsteiger: was ist das, warum wichtig (2–3 Sätze).';
	public $fix_label = 'Knopftext';
	public $fix_does  = 'Was der Knopf genau ändert.';
	public $risk      = 'safe';              // safe = darf in „Sichere Korrekturen anwenden“; review = erst ansehen
	public $kind      = 'auto';              // auto | input | items | form | letter | none
	public function run( Plakette_Context $ctx ) { return $this->pass( 'Detail' ); /* fail(), warn(), skip() */ }
	public function fix( array $req, array &$undo ) { /* … */ return 'Erfolgsmeldung'; /* oder WP_Error */ }
}
add_filter( 'plakette_checks', function ( $c ) { return array_merge( $c, array( 'Plakette_Check_Xyz' ) ); }, 20 );
```
Ergebnis-Extras (zweites Argument von `pass/fail/warn`): `items` (Liste zum Auswählen: `id,label,sub,value,link,thumb`),
`item_field` (`label,max,multiline`), `input` (`label,value,max,multiline,placeholder`), `form`
(`Plakette_Settings::form( 'company', array( 'name', … ) )` + optional `intro`), `letter` (Text an den Hoster),
`blocked` (Grund, warum der Fix hier nicht geht), `notes` (Liste kurzer Zusatzzeilen), `links` (`[ ['label'=>,'url'=>] ]`),
und Überschreibungen wie `kind`, `fix_label`, `fix_does`, `weight`.
`kind => 'none'`: nur Hinweis ohne Knopf. Ein Fix bekommt `$req['value']` (input: String, form: Array key→Wert)
bzw. `$req['items']` (`[ ['id'=>…,'value'=>…], … ]`).

## Kontext (`Plakette_Context`)
`home()` (status, headers, cookies, time_ms, facts), `home_ok()`, `facts()`, `html()` (rohes HTML der Startseite),
`all_html()` (Startseite + bis zu 4 Unterseiten: id ⇒ html), `cookies()` (Namen der Cookies, die ein Erstbesucher
bekommt), `samples()` (Fakten der Unterseiten), `posts()` (bis 500 veröffentlichte Beiträge/Seiten).
Fakten (`class-crawler.php › analyse`): title, description, robots, canonical, lang, og, jsonld, h1, imgs, scripts_head,
scripts, styles, iframes, links (href,text,label), viewport, generator, hosts (fremder Host ⇒ Typen), google_fonts, …

## Gemeinsame Bausteine
- `Plakette_Html::add( 'name', $callback, $prio )` – Umbau des fertigen HTML (eine Pufferstufe für alle).
  Helfer: `insert_in_head`, `append_to_head`, `append_to_body`, `outside_code`, `attr`, `has_attr`, `set_attr`, `remove_attr`.
  Vergleichsansicht ohne Umbau: `?plakette_aus=1`.
- `Plakette_Services` – Katalog fremder Dienste (Tracking, Einbettungen, CDNs) mit Kategorie, Anbieter und
  Absatz für die Datenschutzerklärung. `detect( $html )`, `for_url()`, `consent_tool()`, `is_held_back( $tag )`.
- `Plakette_Settings` – Einstellungen (Panel „Einstellungen“) + **Firmendaten** (`Plakette_Settings::company()`),
  eigene Abschnitte über den Filter `plakette_settings_sections`. `save( $section, $values, $undo )`.
- `Plakette_Log` – Helfer `set_option`, `set_site_option`, `set_postmeta`, `set_module`, `insert_post`, `set_post_field`.
- `Plakette_Env` – `.htaccess`-Blöcke (`write_htaccess_block( 'Plakette …', $zeilen )`), `wp-config.php`-Defines,
  erkannte SEO-/Cache-/Optimierungs-Plugins, Sicherungsordner.
- `Plakette_Page_Cache::purge_all()` leert den eigenen Cache und die gängiger Cache-Plugins (nach jedem Fix automatisch).
- Hooks: `plakette_boot` (Module einhängen), `plakette_daily` (täglicher Cron), `plakette_activate`, `plakette_deactivate`.

## Bereiche
`legal` „Rechtliches“ (Gruppen: Impressum & Pflichtangaben, DSGVO & Cookies, Fremde Server, Kommentare, Barrierefreiheit (BFSG)),
`security` „Sicherheit“, `speed` „Geschwindigkeit“, `seo` „SEO“. Die IDs der Prüfungen behalten ihr altes Präfix
(`privacy_…`, `legal_…`, `a11y_…`) – sie stehen im Protokoll und in gespeicherten Berichten. Berichte von vor 1.2.0
sortiert `Plakette_Auditor::migrate()` einmalig um.

## Feste Funktionen (dürfen bei Umbauten nicht verloren gehen)
Vom Auftraggeber ausdrücklich verlangt. `tests/run.php?step=features` (läuft in `tests/e2e.sh`, Zeile „Feste Funktionen
fehlen“) prüft, dass es sie gibt und dass sie wirken – der Test muss „keine“ melden.
- **Vorladen (Preload)** – `includes/modules/preload.php`, Einstellungen › „Bilder und lokale Kopien“:
  `preload_fonts` (+ `preload_fonts_max`), `preload_cdn`, `preload_extra`. Nur Dateien der eigenen Domain.
- **Besucherstatistik einschalten** – Einstellungen › „Besucherstatistik“ (`stats.enabled`), zusätzlich zum Modul `stats`.

## Regeln für Prüfungen
- **Beleg statt Behauptung:** Ein Befund nennt, was genau gefunden wurde (abgerufene Adresse, Antwort, Datei) – in
  `notes`. Vorbild: `Plakette_Check_Https::probe_http()`.
- **Nur was Besucher betrifft:** Dateien, die nur in der Mediathek liegen, sind kein Mangel
  (`Plakette_Image_Usage::is_used()`); rechtliche Seiten und noindex-Seiten brauchen keine SEO-Texte
  (`Plakette_Legal::needs_seo_texts()`).
- Server-Regeln, die vor WordPress und vor jedem Seiten-Cache greifen müssen (Weiterleitungen), gehören an den Anfang
  der `.htaccess`: `Plakette_Env::write_htaccess_block( $marker, $zeilen, true )` – danach immer selbst testen und
  bei Fehler sofort zurücknehmen.

## Testen
- `tools/lint.sh` – PHP-Syntax aller Dateien (PHP-WASM).
- `tests/e2e.sh 8.4` bzw. `8.2` – echtes WordPress (Playground): Testseite mit Mängeln anlegen, prüfen, alles beheben,
  prüfen, alles rückgängig machen, vergleichen. Ergebnisse in `tests/out/`.

## Dateien und Modul-Schlüssel
| Datei | Prüfungen | Module (`Plakette_Modules`) |
|---|---|---|
| `checks-privacy.php`, `modules/consent.php`, `modules/privacy.php`, `modules/statistics.php`, `assets/consent.*`, `assets/stats.js` | privacy_consent (bannerfrei?), privacy_third_party, privacy_embeds, privacy_gravatar, privacy_comment_ip, privacy_comment_consent | consent, embeds, local_avatars, comment_ip, comment_note, remove_services (Liste von Dienst-IDs), stats (Plakette-Statistik, Tabelle `plakette_stats`) |
| `checks-security.php`, `modules/security.php` | security_* | sec_headers, no_xmlrpc, no_user_enum, login_limit, hide_errors, no_generator, mixed_content |
| `checks-legal.php`, `modules/legal.php` | legal_*, a11y_lang, a11y_zoom, a11y_form_labels | legal_links, shop_button, a11y_lang, a11y_zoom, a11y_form_labels |
| `checks-toolkit.php`, `modules/performance.php`, `modules/fonts.php`, `modules/preload.php` | speed_image_dims, privacy_cdn, a11y_animations, a11y_link_names (+ speed_lcp, privacy_google_fonts) | local_fonts, lcp, lcp_auto, lcp_webp, cdn_local, img_dims, a11y_anim, link_names |
| `class-vulns.php`, `checks-vulns.php`, `modules/vulns-boot.php`, `class-dashboard.php` | security_vulnerabilities (Abgleich mit WPVulnerability/CVE), Dashboard-Widget, Admin-Hinweis | – (Einstellungen „vulns“, „dashboard“) |
| `checks-seo-helper.php`, `modules/seo-helper.php`, `modules/breakdance.php` | seo_company_schema, seo_image_sitemap, seo_featured_images, legacy_imm_plugins | company_schema, image_sitemap (+ Einstellungen „breakdance“) |

- Breakdance-Inhalte: `Plakette_Breakdance::html( $id )` baut aus dem Elementbaum (`_breakdance_data`) einfaches HTML,
  `::content_for( $post )` liefert es statt des (leeren) Beitragsinhalts – für Wortzählungen u. Ä. immer das nehmen.
  Dieselbe HTML geht im Beitragseditor an Yoast SEO (`registerModification('content')`) und Rank Math (`rank_math_content`)
  sowie als `wordCount` ins Yoast-Article-Schema (Einstellung „breakdance › seo_bridge“, Standard an).
  **Jede Prüfung, die Beitragsinhalte liest oder ändert, muss Breakdance mitdenken:** lesen mit `content_for()` (Text/HTML)
  bzw. `scan_source()` (roh, mit iframes, VG-Wort-Pixeln und Video-/Karten-Adressen), ändern mit `Plakette_Breakdance::visit()`
  / `::transform()` (Baum als Objekte, `{}` bleibt `{}`, speichert rückgängig machbar über `Plakette_Log::set_postmeta`).
  Bilder im Baum: `map_images()` (Bild = `{url, id|filename|mime}`, z. B. `content.controls.image` bei ImageWithZoom).
  Breakdance-Header/-Footer/-Vorlagen/-Blöcke: `Plakette_Breakdance::part_types()` (in `Plakette_Legal_Pages::content_posts()`).
  `Plakette_Log::set_postmeta()` und das Rückgängig-Op `postmeta` schreiben mit `wp_slash()` (sonst zerbricht JSON).
- Browser-Erweiterungen (Dark Reader, Passwortmanager, Grammarly …) schreiben bei der Datenschutz-Messung eigene Cookies/Speicher:
  `Plakette_Services::is_extension_storage()` (Filter `plakette_extension_storage`) blendet sie aus.
- Google-Unternehmensprofil: `modules/google-profile.php` (`Plakette_Google_Profile`: Kurzlink auflösen → Places API
  `places:searchText` → Place ID in Option `plakette_gbp_place`; Details 12 h im Transient), Prüfung `seo_google_profile`
  in `checks-seo-helper.php`. Felder: `company.gbp_url`, `google.places_key` (Feldtyp `password`). Nur lesen, nie schreiben.
- OpenStreetMap: `modules/osm-profile.php` (`Plakette_Osm_Profile`: Objekt über die OSM-API lesen – Way-Mittelpunkt aus
  `/full`, Relation über Nominatim `lookup`; Suche über Nominatim; Öffnungszeiten `parse_osm_hours()` ⇄ `text_days()` /
  `format_days()`; Kartenhinweise `send_note()` → Option `plakette_osm_notes`), Prüfung `seo_osm_profile` in `checks-osm.php`.
  Feld `company.osm_url`; Option `plakette_osm_place` merkt Objekt + Koordinaten fürs Schema (`geo`, ohne Abruf beim Seitenaufruf).
  Ohne verbundenes Konto nur Hinweise; direktes Schreiben nur über `Plakette_Osm_Edit` (siehe unten). Tests gegen den OSM-Testserver:
  Filter `plakette_osm_notes_api` → `https://master.apis.dev.openstreetmap.org/api/0.6/` (setzt `tests/run.php` immer; `plakette_osm_api` für Lesezugriffe, `plakette_osm_nominatim` für die Suche).
- OSM-Konto/Bearbeiten: `modules/osm-edit.php` (`Plakette_Osm_Edit`): OAuth 2.0 + PKCE „out of band“ (OSM zeigt einen Code,
  Nutzer fügt ihn unter Einstellungen › OpenStreetMap ein), öffentliche App ohne Secret, Client-ID `PLAKETTE_OSM_CLIENT_ID`
  bzw. Filter `plakette_osm_client_id` oder Feld `osm.client_id`; Token in `plakette_osm_token` (gilt nur für den Server,
  auf dem es ausgestellt wurde). `changes()` (Tags je Feld, behält PH/Saisonregeln, `contact:*`-Schlüssel, `check_date:opening_hours`),
  `write()` (frisches XML, Abbruch bei geänderten Tags, ein Änderungssatz je Fix), Rückgängig-Op `osm_tags` in `class-log.php`.
  **Nie** ohne Auswahl des Nutzers schreiben, nie mehrere Objekte, nie den Namen – sonst gilt der Automated-Edits-Kodex.
  `Plakette_Settings::get()` darf in einem `plakette_settings_sections`-Filter nicht aufgerufen werden (Endlosrekursion) –
  dort `get_option( Plakette_Settings::OPTION )` lesen.
  Client-ID der App „Plakette“ auf openstreetmap.org: `Plakette_Osm_Edit::CLIENT_ID_LIVE`. Testmodus:
  `define( 'PLAKETTE_OSM_DEV', true )` → alles gegen `master.apis.dev.openstreetmap.org` (eigene Client-ID ins Feld eintragen);
  Live-Test dort: `wp eval-file …/tests/osm-live.php` (legt ein Testobjekt an, bearbeitet, nimmt zurück, prüft Konflikte, löscht es).
  Externer Prüfer für erzeugte Öffnungszeiten: `opening_hours.js` (npm `opening_hours`, keine Warnungen, `prettifyValue()` = Wert).
- Sicherungen: Hoster können `define( 'PLAKETTE_BACKUPS', 'Name' )` setzen (oder Filter `plakette_host_backups`) – dann gilt
  security_backups als erfüllt. Sonst kann der Nutzer „Server sichert – als erfüllt markieren“ (Option `plakette_backups_confirmed`, rückgängig im Protokoll).

- Datenschutz-Messung im Browser (admin.js › privacyProbe) → REST `/privacy-probe` → Option `plakette_privacy_probe`; Wissensbasis Cookies/Speicher: `Plakette_Services::storage_kb()`, Plugins je Dienst: `::plugins()`. Externer Prüfer: `node tests/consent-probe.mjs judge [--php 8.4] [--ui]`.
- Cookie-Banner erscheint nur auf Seiten, die etwas zurückhalten; sonst liegt er als `<template>` bereit (Link „Cookie-Einstellungen“ lädt ihn nach).
- Cookie-Einstellungen wieder öffnen: `[plakette_cookie_settings]`, `a[href="#plakette-consent"]` oder `[data-plakette-consent-open]`.
- Rechtliche Seiten: `Plakette_Legal::imprint_page()`, `privacy_page()`, `legal_pages()`; Option `plakette_imprint_page`.
- Alte i-make.media-Plugins: `Plakette_Legacy::handles( $modul )` – Module laufen nicht doppelt, solange das alte Plugin aktiv ist.
