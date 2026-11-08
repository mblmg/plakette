# Prüfstand – SEO & Ladezeit (WordPress-Plugin)

Ein WordPress-Plugin, das eine Website wie bei der Hauptuntersuchung prüft: **34 Prüfpunkte** zu SEO und Ladezeit.
Für jeden gefundenen Mangel gibt es einen Knopf, der ihn behebt, dazu eine kurze Erklärung für Einsteiger.
Jede Änderung lässt sich im Protokoll rückgängig machen.

![Prüfbericht](docs/screenshots/bericht.png)

## Installieren
1. `exports/pruefstand.zip` herunterladen.
2. In WordPress: *Plugins › Installieren › Plugin hochladen* → ZIP wählen → aktivieren.
3. Im Menü links auf **Prüfstand** klicken, dann auf **Website jetzt prüfen**.

Voraussetzungen: WordPress 6.5 oder neuer, PHP 7.4 oder neuer.

## Was es kann
| Bereich | Prüfungen (Auswahl) | Behebung |
|---|---|---|
| Grundeinstellungen | Suchmaschinen ausgesperrt, „?p=123“-Adressen, HTTPS, Sitemap, robots.txt, Untertitel, Icon, leere Bild-Seiten | Ein Klick (Permalinks mit Selbsttest und automatischem Rückzug) |
| Startseite | Titel, Meta-Beschreibung, Open Graph, strukturierte Daten, Canonical | Vorschlag zum Bearbeiten, dann ein Klick |
| Inhalte | Alternativtexte, Beschreibungen je Beitrag, doppelte/zu lange Titel, mehrere H1, kaputte interne Links, Beispielinhalte, sehr kurze Beiträge | Liste mit Vorschlägen, einzeln an- und abwählbar |
| Ladezeit | Seiten-Cache, Komprimierung, Browser-Cache, große Bilder, LCP-Bild vorladen, blockierende Skripte, Google Fonts lokal (auch DSGVO), Emoji-Skript, jQuery Migrate, Dashicons, Datenbank | Ein Klick |
| Nur durch den Hoster lösbar | PHP-Version, SSL-Zertifikat, Objekt-Cache, Server-Header ohne Apache | Fertige Nachricht an den Hoster zum Kopieren |

Außerdem:
- **Messung im Browser:** Die Startseite wird sichtbar in einem Smartphone-Rahmen geladen. Gemessen werden der größte Inhalt (LCP), das Verrutschen beim Laden (CLS), die erste Serverantwort und das Seitengewicht.
- **Zweitmeinung von Google:** ein Link zu PageSpeed Insights. Mit einem kostenlosen API-Schlüssel erscheint das Ergebnis direkt im Plugin.
- **„Sichere Korrekturen anwenden“:** behebt in einem Durchgang alles, was für fast jede Website passt.

## Belegt mit unabhängigen Prüfern
Testseite mit absichtlich eingebauten Mängeln, echtes WordPress (Playground), siehe `tests/`:

| | vorher | nachher |
|---|---|---|
| Prüfstand-Punkte | 16 | 92 (offen: nur Hoster-Themen) |
| Lighthouse SEO | 61 | **100** |
| Lighthouse Leistung (Smartphone) | 90 | 97 |
| Größter Inhalt (LCP, Lighthouse) | 3,2 s | 2,6 s |
| Serverantwort | 553 ms | 24 ms |
| Seitengewicht | 412 KB | 264 KB |

- Alle 25 Behebungen rückgängig gemacht → Prüfergebnis identisch mit dem Ursprungszustand.
- PHP 7.4 und 8.3: gleiche Ergebnisse, 0 PHP-Warnungen aus dem Plugin.

Test wiederholen: `tests/e2e.sh 8.3` (braucht Node.js; startet WordPress Playground, legt die Mängel an,
prüft, behebt, prüft, macht alles rückgängig und vergleicht).

**Nicht getestet** (Playground hat keinen Apache): die `.htaccess`-Regeln für Komprimierung und Browser-Cache.
Sie stehen in `<IfModule>`-Blöcken, sodass ein Server ohne das Modul sie ignoriert.

## Aufbau
```
src/pruefstand/                Plugin-Quellcode
  pruefstand.php               Einstieg
  includes/checks-*.php        die Prüfungen (je eine Klasse: run() findet, fix() behebt)
  includes/class-log.php       Protokoll + Rückgängig
  includes/class-modules.php   Laufzeit-Funktionen, die Behebungen einschalten (Meta-Tags, LCP-Preload …)
  includes/class-page-cache.php + dropin/advanced-cache.php   Seiten-Cache
  admin/                       Oberfläche (JS ohne Build-Schritt, CSS, lokale Schriften)
docs/PLAN.md                   Plan nach dem „be-ambitious“-Skill
docs/DESIGN.md                 Design-Plan nach dem „frontend-design“-Skill
tests/                         End-to-End-Test gegen echtes WordPress
exports/pruefstand.zip         installierbares Plugin
```

Neue Prüfung hinzufügen: Klasse von `Pruefstand_Check` ableiten und über den Filter `pruefstand_checks` registrieren.
