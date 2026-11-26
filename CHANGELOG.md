# Changelog

## [1.6.2] - 2026-11-26

### Behoben
- **Erklärungen bei bestandenen Prüfpunkten klangen nach einem Problem**, z. B. „Deine Website lädt Schriften von
  Google-Servern“ beim grünen Punkt „Schriften kommen vom eigenen Server“. Alle Erklärtexte sind jetzt allgemein
  formuliert („Viele Themes laden …“, „Von Haus aus …“) und stimmen bei „in Ordnung“ genauso wie bei einem Befund.

## [1.6.1] - 2026-11-25

### Behoben
- **Prüfung brach mit „kritischer Fehler“ ab**, wenn ein Link im Inhalt auf eine große Datei führte (z. B. eine
  Weiterleitung auf einen 12,8-MB-Download): Plakette lud die Datei komplett in den Speicher. Beim Abrufen von Seiten
  und Links liest Plakette jetzt höchstens 3 MB – für Status und HTML reicht das.

## [1.6.0] - 2026-11-25

### Neu
- **Breakdance-Artikel und das VG-Wort-Plugin Worthy:** Worthy sah bei Breakdance-Seiten bisher 0 Zeichen, weil es nur den
  WordPress-Editor liest. Plakette reicht Worthy jetzt den Text aus Breakdance weiter – für die Zeichenzahl (ab 1.800 Zeichen
  meldefähig) und für den Text, den Worthy an VG Wort meldet. Nach dem Speichern im Breakdance-Editor zählt Worthy neu;
  bestehende Breakdance-Artikel werden einmalig im Hintergrund nachgezählt, ohne dass du sie neu speichern musst.
  Auch der Zeichenzähler von Worthy im WordPress-Editor zählt den Breakdance-Text mit.
  Schalter: Einstellungen › Breakdance › „Breakdance-Inhalte an Yoast SEO, Rank Math und Worthy übergeben“ (Standard an).

## [1.5.1] - 2026-11-22

### Behoben
- Auf dem Handy überlagerte die Plakette während der Prüfung die Überschrift „Prüfung läuft …“ (ebenso beim ersten
  Aufruf ohne Prüfbericht). Die Überschrift steht jetzt neben der Plakette, der Text darunter.

## [1.5.0] - 2026-11-19

### Neu
- **Übersichtlichere Oberfläche:** statt einer langen Seite gibt es jetzt Ansichten – Mängel, Messungen, Statistik,
  Protokoll und Einstellungen. Die Leiste bleibt beim Scrollen oben stehen und lässt sich auf dem Handy wischen.
  Im Prüfbericht führt ein Knopf direkt zu den Einstellungen; jede Ansicht hat eine eigene Adresse
  (`#einstellungen-consent` öffnet z. B. gleich den Cookie-Banner), der Zurück-Knopf des Browsers funktioniert.
  Auf dem Handy steht die Plakette neben dem Ergebnis, die Bereichswerte zweispaltig, die Filter in einer wischbaren Zeile.
  Das Protokoll zeigt zuerst die letzten 15 Einträge.
- **Zusammenhängende Mängel werden gemeinsam aktualisiert:** Schaltet ein Knopf etwas ein, das auch andere Prüfpunkte
  betrifft (z. B. „Dateien von öffentlichen CDNs lokal ausliefern“ beim Cookie-Banner-Mangel), prüft Plakette diese
  Punkte sofort mit und meldet „Damit auch aktualisiert: …“. Gilt auch für Rückgängig und für gespeicherte Einstellungen
  (z. B. Firmendaten → Impressum).
- **Große Bilder: Falschbefunde ausnehmen und Fundstellen sehen.** Jedes bemängelte Bild hat einen Knopf „Ignorieren“
  (zurücknehmbar unter „Ausgenommen“) und zeigt, auf welchen Seiten, Vorlagen oder Beaver-Builder-/Breakdance-Teilen es
  verwendet wird – mit Links zum Ansehen und Bearbeiten.
- **Einstellungen › Cookie-Banner zeigt den Status:** ob der Plakette-Banner aktiv ist, ein anderes Tool läuft
  (z. B. CCM19, Borlabs, Complianz …), beide gleichzeitig (Warnung) oder gar keiner. Auch „Besucherstatistik“ zeigt,
  ob sie zählt.
- CCM19 wird als Cookie-Banner erkannt (Cloud und selbst gehostet, zurückgehaltene Skripte `text/x-ccm-loader`),
  steht mit Anbieter und Absatz für die Datenschutzerklärung im Dienste-Katalog.

### Behoben
- **„Ausgewählte Bilder verkleinern“ tat bei PNG-Fotos nichts** („0 Bilder verkleinert“): Fotos mit transparenten
  Stellen werden als PNG nicht kleiner. Plakette ersetzt sie jetzt durch eine WebP-Fassung (oft ein Zehntel der Größe)
  und trägt die neue Adresse überall ein, wo das Bild verwendet wird – im Inhalt und in Page-Builder-Daten (Beaver
  Builder, Elementor, Breakdance); der Builder-CSS-Cache wird neu erzeugt. Die alten Dateien bleiben liegen, alles ist
  rückgängig machbar. Was nicht geht, nennt die Meldung jetzt mit Grund.
- **CDN-Stylesheets mit Schriften** (z. B. `foundation-icons.css` von cdnjs): Plakette lädt die Schrift- und
  Bilddateien hinter den `url()`-Verweisen mit herunter und liefert alles lokal aus. Dateien, die grundsätzlich nicht
  lokal gehen (404, falscher Inhaltstyp, `@import` …), werden mit Grund vermerkt und nicht mehr stündlich neu versucht.
- Mehrteilige Korrekturen (Bilder in Paketen) brechen nicht mehr beim ersten fehlgeschlagenen Paket ab.

## [1.4.0] - 2026-11-13

### Neu
- **OpenStreetMap genauso gut versorgt wie Google:** neue Prüfung „Firmendaten stimmen mit OpenStreetMap überein“ –
  ohne API-Schlüssel und ohne Kosten. Plakette findet das Unternehmen selbst (Nominatim), verknüpft den Eintrag
  (`sameAs`/`hasMap` und neu `geo`-Koordinaten im Firmenprofil) und vergleicht Name, Adresse, Telefon, Öffnungszeiten
  und Website mit den Rohdaten der OSM-API. Öffnungszeiten werden zwischen OSM-Syntax (`Mo-Fr 09:00-18:00; Sa,PH off`)
  und den Firmendaten übersetzt. Abweichungen lassen sich übernehmen (rückgängig machbar) oder als Kartenhinweis an die
  OpenStreetMap-Community melden – fehlt das Unternehmen ganz, meldet Plakette es mit allen Angaben als fertige OSM-Tags.
  Ohne verbundenes OSM-Konto ändert Plakette die Karte nie selbst und zeigt an, ob ein Hinweis noch offen ist.
  Neues Feld „OpenStreetMap-Eintrag (Link)“ in den Firmendaten (freiwillig).
- **Korrekturen direkt in OpenStreetMap eintragen:** Unter Einstellungen › OpenStreetMap verbindest du Plakette mit
  deinem eigenen OSM-Konto (OAuth 2.0 mit PKCE, ohne Passwort in WordPress). Danach trägt Plakette die Korrekturen,
  die du auswählst, selbst in die Karte ein, statt Freiwillige per Hinweis darum zu bitten. Pro Korrektur entsteht
  ein Änderungssatz mit Beschreibung und Quelle; geändert werden nur die gezeigten Angaben deines eigenen Eintrags.
  Regeln aus OSM, die die Firmendaten nicht kennen (Feiertage `PH`, Saisonzeiten), bleiben erhalten,
  `check_date:opening_hours` wird gesetzt, Abkürzungen wie „Str.“ werden ausgeschrieben. Hat jemand den Eintrag
  inzwischen geändert, bricht Plakette ab. „Rückgängig“ im Protokoll stellt die alten Werte wieder her – solange
  niemand den Eintrag weiter bearbeitet hat. Den Namen ändert Plakette nie (in OSM steht der Name vom Schild).
  Getestet gegen den OSM-Testserver (`tests/osm-live.php`, Testmodus `define( 'PLAKETTE_OSM_DEV', true )`).

## [1.3.0] - 2026-11-13

### Neu
- **Google-Unternehmensprofil verknüpfen und abgleichen:** neues Feld „Google-Unternehmensprofil (Link)“ in den
  Firmendaten – der Link landet als `sameAs`/`hasMap` im Firmenprofil für Google. Mit einem eigenen API-Schlüssel
  (Einstellungen › „Google-Unternehmensprofil“, Places API (New)) liest Plakette das Profil und vergleicht Name,
  Adresse, Telefon und Öffnungszeiten mit den Firmendaten. Abweichungen lassen sich mit einem Klick übernehmen
  (rückgängig machbar). Plakette liest nur – am Profil bei Google ändert sich nichts.
- **Breakdance-Inhalte für Yoast SEO und Rank Math:** Beim Bearbeiten einer Breakdance-Seite reicht Plakette den Text
  aus Breakdance an die Inhaltsanalyse weiter (statt „0 Wörter“) und ergänzt die Wortzahl im Article-Schema von Yoast.
  Abschaltbar unter Einstellungen › Breakdance.
- **Sicherungen als erfüllt markieren:** Läuft die Sicherung über den Server, lässt sich der Punkt abhaken
  (rückgängig im Protokoll). Hoster können `define( 'PLAKETTE_BACKUPS', 'Name' );` setzen.

### Behoben
- **Breakdance-Seiten werden richtig gelesen:** „Sehr kurze Beiträge“ (bisher 0 Wörter), Bilder ohne Alternativtext,
  mehrere H1, kaputte Links, Titel- und Beschreibungsvorschläge, OS-Plattform-Link, veraltete Gesetzesnamen, Einbettungen
  (YouTube, Karten, iframes) und VG-Wort-Pixel berücksichtigen jetzt den Breakdance-Inhalt – auch in Breakdance-Headern,
  -Footern und -Vorlagen. Korrekturen werden direkt im Breakdance-Layout gespeichert und lassen sich rückgängig machen.
- **Cookie-Banner:** Cookies und Speicher-Einträge von Browser-Erweiterungen (z. B. Dark Reader, Passwortmanager,
  Grammarly, Werbeblocker) zählen nicht mehr als Fund der Website.
- **Protokoll:** Rückgängig machen von Werten mit Backslashes (z. B. JSON) beschädigt sie nicht mehr.

## [1.2.0] - 2026-11-13

### Neu
- **Bereich „Rechtliches“:** Impressum und Pflichtangaben, Datenschutz/DSGVO, Cookie-Banner, fremde Server,
  Kommentare und Barrierefreiheit (BFSG) stehen jetzt zusammen in einem eigenen Bereich. „Sicherheit“ enthält nur
  noch den Schutz vor Angriffen. Die vier Bereiche heißen: Rechtliches, Sicherheit, Geschwindigkeit, SEO.
  Gespeicherte Berichte werden beim Update einmalig umsortiert; der Verlauf der Bereichswerte beginnt neu.
- **Vorladen von Schriften und CDN-Dateien** (Einstellungen › „Bilder und lokale Kopien“): Schriften vorladen
  (mit Anzahl der Dateien), CDN-Dateien vorladen und ein Feld für weitere eigene Dateien.
- **Besucherstatistik direkt einschalten** (Einstellungen › „Besucherstatistik“) – bisher ging das nur als Ersatz
  beim Entfernen eines Tracking-Dienstes.
- **HTTPS-Weiterleitung auf Server-Ebene:** Die Behebung trägt die Weiterleitung an den Anfang der `.htaccess`
  ein (mit Selbsttest und automatischer Rücknahme), damit sie auch vor Seiten-Caches greift.

### Behoben
- **HTTPS-Befund mit Beleg:** Der Bericht zeigt genau, welche Adresse abgerufen wurde, was der Server geantwortet
  hat und – per Gegenprobe am Cache vorbei – ob ein Seiten-Cache die Ursache ist (z. B. WP-Optimize liefert die
  gespeicherte Seite auch über http aus, obwohl WordPress richtig weiterleitet).
- **Große Bilder:** Bilder, die nur in der Mediathek liegen und auf keiner Seite verwendet werden, zählen nicht
  mehr als Mangel. Plakette durchsucht dafür Inhalte, Page-Builder-Daten, Beitragsbilder, Galerien und
  Theme-Einstellungen.
- **Beschreibungen für Google:** Impressum, Datenschutzerklärung, AGB, Widerruf, Warenkorb/Kasse und Seiten, die
  für Suchmaschinen ausgeblendet sind, brauchen keine Meta-Beschreibung und werden nicht mehr bemängelt.
- **Einstellungen › Moderne Bildformate:** zeigt jetzt, wer die modernen Formate wirklich ausliefert (Plakette,
  EWWW, CompressX …) statt nur „Ausgeschaltet“.
- **Mobilansicht des Prüfberichts:** Titel der Prüfpunkte werden nicht mehr in eine schmale Spalte gequetscht,
  Bereichs-Reiter und Einstellungen laufen nicht mehr aus dem Bildschirm, lange Adressen und Dateinamen umbrechen.

## [1.1.1] - 2026-11-10

### Behoben
- Impressum und Datenschutzerklärung werden so geprüft, wie Besucher sie sehen. Plakette ruft die Seite im
  Frontend ab, Shortcodes wie von eRecht24, Page-Builder und Blöcke werden also mitgelesen. E-Mail-Adressen nur
  als `mailto:`-Link oder hinter Cloudflare-Schutz zählen ebenfalls. Damit gibt es keine Fehlbefunde mehr wie
  „E-Mail und PLZ fehlen“, obwohl sie im eingebundenen Impressum stehen.

### Geändert
- Mindestens PHP 8.2: Versionen ohne Sicherheitsupdates (8.1 und älter) werden nicht mehr unterstützt.

## [1.1.0] - 2026-11-10

### Neu
- **Bannerfreie Website als Ziel:** Die Prüfung „Cookie-Banner nötig?“ (Bereich Datenschutz) arbeitet mit Beweisen.
  Eine Messung im Browser zeigt, was beim ersten Besuch gespeichert und an fremde Server geschickt wird. Plakette
  sagt dann eindeutig: kein Banner nötig, Banner nötig und vermeidbar (mit Maßnahmen zum Anklicken) oder Banner
  überflüssig (lässt sich abschalten).
- **Plakette-Statistik:** Besucherzahlen ohne Cookies und ohne Einwilligung, als Ersatz für Google Analytics.
- **„Dienst entfernen“:** nimmt Tracking-Skripte aus der Seite oder deaktiviert das Plugin, das sie einfügt.
- **Cookie-Banner nur dort, wo er nötig ist:** Seiten ohne einwilligungspflichtige Inhalte bleiben bannerfrei.
- **VG-Wort-Zählpixel** wird erkannt und mit Quellenangabe bewertet.
- **Sicherheitslücken:** täglicher Abgleich von WordPress, allen Plugins und Themes mit der CVE-Datenbank von
  WPVulnerability (CVE/NVD, Wordfence, Patchstack, WPScan). Bei Treffern gibt es einen roten Hinweis im ganzen
  Admin-Bereich, eine Zahl am Menüpunkt und eine E-Mail. Plakette behebt Lücken per Update oder deaktiviert das
  Plugin, wenn es kein Update gibt.
- **Dashboard-Widget:** Punktzahl je Bereich, die wichtigsten offenen Punkte, Sicherheitslücken und Besucher.
  Dazu eine wöchentliche automatische Prüfung.
- **AVIF:** AVIF- und WebP-Kopien aller Bilder. Sie werden mit Rückfall auf das Original ausgeliefert, damit
  ältere Browser nie ein kaputtes Bild sehen. Im Test waren die Bilder 55–58 % kleiner.

### Geändert
- Bildprüfungen bewerten, was Besucher wirklich herunterladen: EWWW, CompressX, ShortPixel, Imagify und andere
  werden erkannt, auch Umschreibungen auf dem Server. Keine Fehlalarme mehr bei Seiten, die längst WebP/AVIF ausliefern.
- „Beiträge und Seiten ohne Beschreibung“ listet alle betroffenen Beiträge mit Links zum Ansehen und Bearbeiten.
  Mit Yoast, Rank Math, SEOPress oder The SEO Framework speichert Plakette die Beschreibungen direkt dort.
- DSGVO, Cookie-Banner und Datenschutzerklärung gehören jetzt zum Bereich **Datenschutz**.
- Mindestens PHP 8.1 (PHP 7.4 und 8.0 bekommen keine Sicherheitsupdates mehr).
- Links aus Dashboard und Hinweisen öffnen direkt den passenden Prüfpunkt.

## [1.0.0] - 2026-11-09

Erste Version als „Plakette – Website-Hauptuntersuchung“. Entstanden aus „Prüfstand – SEO & Ladezeit“ und fasst
die Plugins „i-make.media Toolkit for Breakdance“ (1.1.1) und „SEO-Helfer für Breakdance“ (1.2.0) zusammen.

### Neu
- Vier Bereiche mit eigener Punktzahl: **Datenschutz**, **Sicherheit** (inkl. Recht & DSGVO, Cookie-Banner und
  Barrierefreiheit nach BFSG), **Geschwindigkeit** und **SEO** – über 80 Prüfpunkte, fast alle mit Ein-Klick-Behebung,
  jede Änderung im Protokoll rückgängig zu machen.
- Datenschutz: eigener Cookie-Banner nach TDDDG/DSGVO (blockiert Tracking bis zur Einwilligung, funktioniert mit
  Seiten-Cache), Zwei-Klick-Platzhalter für YouTube, Vimeo, Google Maps & Co., Google Fonts und CDN-Dateien lokal,
  lokale Profilbilder statt Gravatar, gekürzte Kommentar-IPs, Datenschutzhinweis am Kommentarformular,
  Übersicht aller fremden Server.
- Sicherheit: WordPress-, Plugin- und Theme-Updates, automatische Updates, Sicherheitsschlüssel, Login-Sperre nach
  Fehlversuchen, Sicherheits-Header, gemischte Inhalte, Benutzernamen-Ausspähung, XML-RPC, Admin-Benutzername,
  Datei-Editor, Fehlerausgaben, öffentliches debug.log, Verzeichnislisten, offene Registrierung, Versionsangaben,
  ungenutzte Plugins/Themes, Backups.
- Recht: Impressum nach § 5 DDG aus den Firmendaten, Datenschutzerklärung aus den erkannten Diensten,
  fehlende Dienste in der Datenschutzerklärung ergänzen, Rechtliches auf jeder Seite verlinkt, veraltete
  OS-Plattform-Links und Gesetzesnamen (TMG, RStV, TTDSG), Shop-Bestellknopf und -Pflichtseiten,
  Barrierefreiheitserklärung (BFSG).
- Barrierefreiheit: Sprache der Seite, Zoom erlaubt, Formularfelder beschriftet, Links ohne Text beschriftet,
  barrierefreie Breakdance-Einblendungen.
- Geschwindigkeit: LCP-Bild automatisch erkannt (auch bei Page-Buildern) mit Vorrang und WebP, fehlende Bildmaße.
- SEO: Firmenprofil für Google (LocalBusiness mit Adresse, Öffnungszeiten, Angeboten), Beitragsbilder aus dem
  Inhalt, Bilder in der Yoast-/Rank-Math-Sitemap (inkl. Breakdance-Bilder).
- Breakdance: Kapitel-Navigation und Autoren-Bio als dynamische Felder (wie im SEO-Helfer).
- Übernahme der alten Plugins: Einstellungen werden übernommen, die alten Plugins deaktiviert (rückgängig machbar).
- Einstellungen im Plugin: Firmendaten (einmal eintragen für Impressum, Datenschutzerklärung und Google),
  Cookie-Banner-Texte, Breakdance, automatische Updates.
- Updates aus GitHub-Releases, automatische Installation abschaltbar. Nach Änderungen werden die Caches von
  WP-Optimize, WP Super Cache, Autoptimize, WP Rocket, W3 Total Cache und LiteSpeed geleert.
