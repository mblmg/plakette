# Plakette – Aufbau für Entwickler

Plugin-Quelle: `src/plakette/` (Präfix `Plakette_`, Optionen `plakette_*`, CSS `plk-`, Textdomain `plakette`).
PHP 7.4 bis 8.5 kompatibel: **kein** `str_starts_with`, `match`, Named Arguments, Nullsafe `?->`, Union-Types,
`readonly`, Enums. Pfeilfunktionen `fn()` sind erlaubt. Oberfläche und alle Texte auf **Deutsch, Du-Form, einfach**
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
	public $area      = 'security';          // privacy | security | speed | seo
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

## Testen
- `tools/lint.sh` – PHP-Syntax aller Dateien (PHP-WASM).
- `tests/e2e.sh 8.3` bzw. `7.4` – echtes WordPress (Playground): Testseite mit Mängeln anlegen, prüfen, alles beheben,
  prüfen, alles rückgängig machen, vergleichen. Ergebnisse in `tests/out/`.

## Dateien und Modul-Schlüssel
| Datei | Prüfungen | Module (`Plakette_Modules`) |
|---|---|---|
| `checks-privacy.php`, `modules/consent.php`, `modules/privacy.php`, `assets/consent.*` | privacy_third_party, privacy_embeds, privacy_gravatar, privacy_comment_ip, privacy_comment_consent, legal_consent | consent, embeds, local_avatars, comment_ip, comment_note |
| `checks-security.php`, `modules/security.php` | security_* | sec_headers, no_xmlrpc, no_user_enum, login_limit, hide_errors, no_generator, mixed_content |
| `checks-legal.php`, `modules/legal.php` | legal_*, a11y_lang, a11y_zoom, a11y_form_labels | legal_links, shop_button, a11y_lang, a11y_zoom, a11y_form_labels |
| `checks-toolkit.php`, `modules/performance.php`, `modules/fonts.php` | speed_image_dims, privacy_cdn, a11y_animations, a11y_link_names (+ speed_lcp, privacy_google_fonts) | local_fonts, lcp, lcp_auto, lcp_webp, cdn_local, img_dims, a11y_anim, link_names |
| `checks-seo-helper.php`, `modules/seo-helper.php`, `modules/breakdance.php` | seo_company_schema, seo_image_sitemap, seo_featured_images, legacy_imm_plugins | company_schema, image_sitemap (+ Einstellungen „breakdance“) |

- Cookie-Einstellungen wieder öffnen: `[plakette_cookie_settings]`, `a[href="#plakette-consent"]` oder `[data-plakette-consent-open]`.
- Rechtliche Seiten: `Plakette_Legal::imprint_page()`, `privacy_page()`, `legal_pages()`; Option `plakette_imprint_page`.
- Alte i-make.media-Plugins: `Plakette_Legacy::handles( $modul )` – Module laufen nicht doppelt, solange das alte Plugin aktiv ist.
