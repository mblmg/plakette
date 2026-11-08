# Design-Plan (frontend-design) – Prüfstand

**Gegenstand:** Ein Prüfbericht für die eigene WordPress-Website, im WordPress-Admin.
**Publikum:** Website-Betreiber ohne Technikwissen (Handwerk, Vereine, Blogs, kleine Shops), deutschsprachig.
**Hauptaufgabe:** Auf einen Blick verstehen, wie es um die Seite steht – dann Mangel für Mangel beheben, ohne Angst etwas kaputt zu machen.

## Leitidee: die Hauptuntersuchung
Jeder in Deutschland kennt die HU beim TÜV: ein Prüfbericht mit „erheblichen Mängeln“, „geringen Mängeln“
und am Ende eine runde Plakette. Diese Sprache und Form übernehmen wir, weil Anfänger sie sofort verstehen.

## Tokens
| Name | Hex | Rolle |
|---|---|---|
| Prüfblau tief | `#13283F` | Text, Überschriften |
| Prüfblau | `#1F4E79` | Hauptaktion, Plakette |
| Blaugrau-Linie | `#D3DBE4` | Trennlinien |
| Prüfpapier | `#F3F6F9` | ruhige Flächen (bewusst kühl, kein Creme) |
| Mangel-Rot | `#B42318` | erheblicher Mangel |
| Hinweis-Bernstein | `#9A5B00` (Text) / `#F2B544` (Fläche) | geringer Mangel / Hinweis |
| Prüf-Grün | `#11734B` | in Ordnung |

**Schrift:** Atkinson Hyperlegible (Braille Institute) für alle Texte – gewählt, weil sie für Lesbarkeit gebaut ist
(eindeutige Formen von I/l/1, 0/O), passend für Einsteiger. Barlow Semi Condensed für Zahlen und Überschriften –
schmal und technisch wie Beschriftungen auf Messgeräten und Verkehrsschildern. Beide lokal eingebunden (kein Google-Server).
Schriftgrößen-Skala 13 / 15 / 18 / 24 / 34 / 64 px.

## Layout
```
┌──────────────────────────────────────────────────────────────────┐
│  ╭──────╮   Prüfbericht für Bäckerei Sonnenschein                 │
│ │ 72   │   geprüft am 8. November 2026 um 21:14                  │
│ │ ◔◔◔◔ │   3 erhebliche Mängel, 5 geringe, 24 Punkte in Ordnung   │
│  ╰──────╯   SEO ████████░░ 78     Ladezeit █████░░░░░ 54          │
│            [Neu prüfen]  [12 sichere Korrekturen anwenden]        │
├──────────────────────────────────────────────────────────────────┤
│ Messung auf dem Smartphone            [Ladezeit messen] [Google] │
│  größter Inhalt   Verschiebung   Serverantwort   Seitengewicht    │
│  2,1 s ▬▬▬|▬▬     0,02 ▬|▬▬▬▬    180 ms           1,4 MB / 38     │
├──────────────────────────────────────────────────────────────────┤
│ Mängelliste             (Alles) (SEO) (Ladezeit)                  │
│ Erhebliche Mängel                                                 │
│ ✕ Suchmaschinen werden ausgesperrt                    [Beheben]   │
│   Einstellung „Sichtbarkeit…“ ist aktiv.                          │
│   ┊ Was bedeutet das? … Was passiert beim Beheben? …             │
│ Geringe Mängel …                                                  │
│ In Ordnung (24)  ▸                                                │
├──────────────────────────────────────────────────────────────────┤
│ Protokoll  — jede Änderung mit [Rückgängig]                       │
└──────────────────────────────────────────────────────────────────┘
```
Alles linksbündig, eine Spalte, Zeilenlänge der Erklärtexte max. ~70 Zeichen.

## Prinzipien
1. **Ein mutiges Element: die Plakette.** Ihr Ring besteht aus einem Segment pro Prüfung, eingefärbt nach Ergebnis.
   Das ist Information, kein Schmuck: Klick auf ein Segment springt zum Mangel. Nach einer Prüfung füllt sich
   der Ring einmal im Uhrzeigersinn – die einzige automatische Bewegung.
2. **Liste statt Kachel-Raster.** Ein Prüfbericht ist eine Liste, sortiert nach Schwere.
3. **Jede Zeile beantwortet drei Fragen:** Was ist los? Warum ist das wichtig? Was ändert der Knopf?
4. **Sicherheit sichtbar machen:** Jede Behebung landet im Protokoll mit „Rückgängig“.
   Punkte, die man kurz prüfen sollte, sind als solche markiert.
5. **Knöpfe sagen, was passiert:** „Suchmaschinen erlauben“, nicht „Beheben“ oder „Absenden“.

## Abgleich mit generischen Mustern (und was ich geändert habe)
- Erster Entwurf hatte einen **Score-Donut mit Verlauf** und darunter ein **Raster gleicher Karten** pro Prüfung –
  genau das SaaS-Karten-Muster. Geändert zu: segmentierter Plakette (jedes Segment = eine Prüfung) und einer Mängelliste.
- Erster Entwurf hatte **Großbuchstaben-Labels** über jedem Abschnitt („SEO-AUDIT“) – gestrichen, Überschriften in Satzschreibung.
- Messwerte zunächst als „große Zahl + kleines Label + Akzentfarbe“-Kacheln. Geändert zu Messinstrument-Skalen mit
  den drei Google-Bereichen (gut / verbesserungswürdig / schlecht) und einer Markierung – zeigt, *wo* der Wert liegt.
- Keine Pfeile an Knöpfen, keine Mittelpunkt-Trenner, keine Monospace-Labels.
