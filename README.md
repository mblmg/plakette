# Plakette – Website-Hauptuntersuchung (WordPress-Plugin)

Von **i-make.media | Magnus Brühl**. Plakette prüft eine WordPress-Website wie bei der Hauptuntersuchung.
Sie hat **79 Prüfpunkte** in vier Bereichen: **Rechtliches** (Impressum, Datenschutz/DSGVO, Cookie-Banner und
Barrierefreiheit nach BFSG), **Sicherheit**, **Geschwindigkeit** und **SEO**. Jeden Mangel erklärt sie in einfachen Worten, und fast
jeden behebt sie auf Knopfdruck. Jede Änderung steht im Protokoll und lässt sich rückgängig machen.

Plakette enthält auch alle Funktionen der Plugins „i-make.media Toolkit for Breakdance“ und „SEO-Helfer für
Breakdance“. Sind die alten Plugins noch aktiv, übernimmt eine Prüfung deren Einstellungen und schaltet sie ab.

![Prüfbericht](docs/screenshots/bericht.png)

Weitere Ansichten: [Cookie-Banner](docs/screenshots/cookie-banner.png) · [Zwei-Klick-Platzhalter](docs/screenshots/zwei-klick.png) · [Impressum-Formular](docs/screenshots/impressum-formular.png) · [erzeugtes Impressum](docs/screenshots/impressum.png) · [Einstellungen](docs/screenshots/einstellungen.png)

## Installieren
1. Unter [Releases](https://github.com/mblmg/plakette/releases) `plakette.zip` herunterladen (oder `exports/plakette.zip`).
2. In WordPress: *Plugins › Installieren › Plugin hochladen* → ZIP wählen → aktivieren.
3. Im Menü links **Plakette** öffnen → **Website jetzt prüfen**.

Per WP-CLI: `wp plugin install https://github.com/mblmg/plakette/releases/latest/download/plakette.zip --activate`

Voraussetzungen: WordPress 6.5 oder neuer, PHP 8.2 oder neuer (ältere Versionen bekommen keine Sicherheitsupdates mehr).

## Updates
Plakette sieht alle 6 Stunden auf GitHub nach neuen Releases. Sie erscheinen unter *Plugins* und werden automatisch
installiert. Abschalten lässt sich das unter *Plakette › Einstellungen › Plugin*. Bei einem privaten Repository oder
bei Rate-Limits einen Token in die `wp-config.php` schreiben:

```php
define( 'PLAKETTE_GITHUB_TOKEN', 'github_pat_…' ); // Fine-grained Token, nur „Contents: Read“ für mblmg/plakette
```

## Was geprüft und behoben wird
| Bereich | Prüfungen (Auswahl) | Behebung |
|---|---|---|
| **Rechtliches** – Datenschutz | fremde Server im Überblick, YouTube/Vimeo/Maps ohne Einwilligung, Google Fonts, CDN-Dateien (unpkg, jsDelivr …), Gravatar, volle IP-Adressen in Kommentaren, Datenschutzhinweis am Kommentarformular | Zwei-Klick-Platzhalter, Schriften und Skripte lokal, lokale Profilbilder, IPs kürzen – ein Klick |
| **Sicherheit** | Updates (WordPress, Plugins, Themes, automatisch), Sicherheitsschlüssel, Login-Sperre, Sicherheits-Header, gemischte Inhalte, Benutzernamen-Ausspähung, XML-RPC, „admin“-Benutzer, Datei-Editor, Fehlerausgaben, öffentliches debug.log, Verzeichnislisten, offene Registrierung, Versionsangaben, ungenutzte Plugins, Backups, HTTPS, PHP-Version | ein Klick; Server-Themen als fertige Nachricht an den Hoster |
| **Rechtliches** – Impressum & DSGVO | Cookie-Banner nötig?, Impressum (§ 5 DDG), Datenschutzerklärung, fehlende Dienste darin, Rechtliches auf jeder Seite verlinkt, veraltete OS-Plattform-Links (seit 20.07.2025), alte Gesetzesnamen (TMG, RStV, TTDSG), Shop: Bestellknopf, AGB/Widerruf | eigener Cookie-Banner (blockiert Tracking bis zur Einwilligung), Impressum und Datenschutzerklärung aus Firmendaten und erkannten Diensten, Link-Leiste im Footer |
| **Rechtliches** – Barrierefreiheit (BFSG) | Sprache der Seite, Zoom gesperrt, Formularfelder ohne Beschriftung, Links ohne Text (auch Breakdance „custom“), unsichtbare Einblendanimationen, Barrierefreiheitserklärung für Shops | ein Klick |
| **Geschwindigkeit** | Seiten-Cache, Komprimierung, Browser-Cache, große Bilder, LCP-Bild (auch bei Page-Buildern, mit WebP), Bildmaße, blockierende Skripte, Emoji, jQuery Migrate, Dashicons, Datenbank, Objekt-Cache | ein Klick |
| **SEO** | Sichtbarkeit, Permalinks, Sitemap, robots.txt, Titel, Beschreibungen, Open Graph, strukturierte Daten, Firmenprofil (LocalBusiness), Beitragsbilder, Bild-Sitemap (Yoast/Rank Math), Alternativtexte, H1, kaputte Links, Beispielinhalte | Vorschläge zum Bearbeiten, dann ein Klick |

Außerdem:
- **Bannerfrei statt Banner-Pflicht:** Plakette misst im Browser, was beim ersten Besuch gespeichert und
  verschickt wird. Danach sagt sie klar, ob ein Cookie-Banner nötig ist und mit welchen Maßnahmen er wegfällt.
  Dazu gehört eine eigene Statistik ohne Cookies als Ersatz für Google Analytics. Ein überflüssiger Banner wird
  erkannt. Wo ein Banner nötig ist, erscheint er nur auf den betroffenen Seiten.
- **Sicherheitslücken:** täglicher Abgleich von WordPress, Plugins und Themes mit der CVE-Datenbank (WPVulnerability),
  mit roter Warnung im Admin-Bereich und E-Mail.
- **Dashboard-Widget** mit Punktzahl, wichtigsten Mängeln, Sicherheitslücken und Besucherzahlen.
- **AVIF und WebP** mit Rückfall auf das Original. Bilder, die EWWW, CompressX & Co. schon optimiert ausliefern,
  werden erkannt und nicht bemängelt.
- **Firmendaten einmal eintragen:** Daraus entstehen Impressum, Datenschutzerklärung und das Firmenprofil für Google
  (Adresse, Öffnungszeiten, Angebote).
- **Cookie-Banner:** „Alle akzeptieren“ und „Nur notwendige“ sind gleich groß, Einstellungen gibt es je Kategorie.
  Die Einwilligung wird nur im Browser gespeichert, deshalb funktioniert der Banner auch mit Seiten-Cache.
  Kommt ein neuer Dienst hinzu, fragt der Banner erneut. Ist kein Tracking da, sagt Plakette: „Kein Banner nötig.“
- **Messung im Browser** (LCP, CLS, Serverantwort, Seitengewicht) und auf Wunsch eine Zweitmeinung von Google PageSpeed.
- **Vergleichsansicht ohne Umbauten:** an eine beliebige Seiten-URL `?plakette_aus=1` anhängen.
- **Nach jeder Änderung** leert Plakette die Caches von WP-Optimize, WP Super Cache, Autoptimize, WP Rocket, W3TC und LiteSpeed.
- **Breakdance:** Kapitel-Navigation und Autoren-Bio als dynamische Felder, GSAP lokal, Breakdance-Bilder in der Sitemap.

Die Rechtstexte erzeugt Plakette aus dem, was sie auf der Website findet. Sie ersetzen keine Rechtsberatung.
Bitte lies sie nach dem Erstellen einmal durch.

## Getestet
Testseite mit absichtlich eingebauten Mängeln, echtes WordPress (Playground), PHP 8.2 und 8.4 (`tests/e2e.sh`):

| | vorher | nach „alles beheben“ |
|---|---|---|
| Gesamt | 12 | **89** |
| Datenschutz (bis 1.1.x eigener Bereich, jetzt Teil von „Rechtliches“) | 7 | 100 |
| Sicherheit | 10 | 77 (offen: absichtlich eingebaute Lücke ohne Download, HTTPS/Apache/Backups – im Testsystem nicht möglich) |
| Geschwindigkeit | 18 | 93 |
| SEO | 16 | 100 |
| Serverantwort | 876 ms | 54 ms |

- Nach „alles rückgängig“ ist das Prüfergebnis identisch mit dem Ursprung (außer Löschen/Updates). 0 PHP-Warnungen aus dem Plugin.
- Unabhängige Prüfer: Ein frischer Chromium misst Cookies, Speicher und fremde Server (`tests/consent-probe.mjs`,
  7 Szenarien) sowie Bildformate und Bytes (`tests/image-formats.mjs`: −55 bis −58 %, 0 kaputte Bilder, auch ohne AVIF/WebP-Unterstützung).
- Der Abgleich der Sicherheitslücken ist mit 59 Fällen getestet (`tests/vulns-matcher.php`) und live gegen bekannte CVEs geprüft.

## Entwickeln und veröffentlichen
- Aufbau und Konventionen: [`docs/DEVELOPING.md`](docs/DEVELOPING.md)
- `tools/lint.sh`: PHP-Syntax aller Dateien
- `tests/e2e.sh 8.3`: Ende-zu-Ende-Test in echtem WordPress
- `tools/build.sh`: baut `exports/plakette.zip`

**Neue Version:**
1. Abschnitt `## [1.1.0] - Datum` in `CHANGELOG.md` schreiben.
2. `tools/release.sh 1.1.0 --push` ausführen. Das Skript setzt die Versionsnummer an allen Stellen, prüft, baut, committet und taggt.
3. Die GitHub-Action (`.github/workflows/release.yml`) prüft Version und PHP-Syntax (7.4 und 8.3), baut `plakette.zip`
   und legt das Release an. Alle installierten Plakette-Plugins aktualisieren sich danach selbst.

## Lizenz
GPL-2.0-or-later, siehe [LICENSE](LICENSE). Schriften im Admin: Atkinson Hyperlegible und Barlow Semi Condensed (SIL OFL 1.1).
