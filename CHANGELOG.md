# Changelog

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
