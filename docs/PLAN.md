# Plan (be-ambitious) – „Prüfstand – SEO & Ladezeit“

**Das Ziel hinter dem Ziel:** Eine Website, die bei Google gefunden wird und auf dem Handy schnell lädt –
und ein Betreiber ohne Vorkenntnisse, der versteht, warum, und jede Änderung sicher wieder zurücknehmen kann.

## Passende Ambitions-Typen
- **Altitude** – passt: Der Bericht ist nur Mittel; das Ergebnis zählt (gefunden werden, schnell sein).
- **Depth** – passt: Ein Audit, das nur Einstellungen abfragt, liegt oft falsch. Profis prüfen das echte HTML und messen.
- **Range** – passt: Das Ergebnis muss bei Anfängern ankommen, und manches lässt sich nur durch den Hoster beheben.

## Altitude – Leiter
1. **Verstehen statt auflisten:** Jede Prüfung liest die tatsächlich ausgelieferte Seite (Loopback-Crawl + DOM-Parser) und
   misst im echten Browser (LCP, CLS, TTFB, Seitengewicht) – nicht nur WordPress-Optionen.
2. **Zu Entscheidungen führen:** Jeder Mangel hat genau einen Knopf, eine Anfänger-Erklärung, eine Risikostufe und
   „Rückgängig“. Was das Plugin technisch nicht kann (PHP-Version, Server-Kompression auf nginx), bekommt einen
   fertigen Brief an den Hoster – auch das ist eine Behebung.
3. **Dauerhaft gut bleiben:** Behebungen laufen weiter (Meta-Beschreibungen, WebP für neue Uploads, Cache-Leerung beim
   Speichern), und ein erneuter Prüflauf zeigt sofort, ob es gewirkt hat.

## Depth – was ein Top-Profi täte
1. *Was er zusätzlich täte:* das LCP-Bild der Startseite finden und vorladen, Google Fonts lokal hosten (Tempo **und**
   DSGVO, LG München 2022), Seiten-Cache mit sauberer Invalidierung statt „Query-Strings entfernen“ (Mythos – bewusst nicht drin).
2. *Rohmaterial:* ausgeliefertes HTML, HTTP-Header, Performance-Einträge des Browsers, Mediendateien auf der Platte, Datenbank.
3. *Methoden/Werkzeuge:* DOMDocument-Crawl, PerformanceObserver (LCP/CLS) im sichtbaren Smartphone-Rahmen,
   Google PageSpeed Insights (Lighthouse) als Zweitmeinung, WP_HTML_Tag_Processor für sichere Inhaltsänderungen.
4. *Äußerer Prüfer:* echtes WordPress (Playground) + Lighthouse: Testseite absichtlich verschlechtern → Audit findet
   alles → „Alle sicheren Korrekturen“ → erneutes Audit + Lighthouse vorher/nachher → alle Behebungen rückgängig → Ursprungszustand.
   **Harte Ziele:** Lighthouse-SEO 100 nach Korrekturen, 0 PHP-Fehler/Warnungen, jede Behebung vollständig umkehrbar.

## Range – Crew (Verben: „finden, erklären, sicher ändern, beweisen“)
| Disziplin | Was sie tatsächlich tut | Werkzeug |
|---|---|---|
| Technischer SEO-Auditor | crawlt die echte Ausgabe statt Einstellungen zu glauben | Loopback + DOM-Parser |
| Web-Performance-Ingenieur | misst Core Web Vitals, findet LCP-Element | PerformanceObserver, PSI-API |
| Lehrer/Texter für Einsteiger | eine Erklärung pro Mangel: was, warum, was der Knopf ändert | Klartext, keine Fachwörter ohne Erklärung |
| Sicherheitsingenieur | jede Änderung protokolliert und umkehrbar, riskante Änderungen markiert | Undo-Protokoll, Backups |
| Support-Profi | schreibt den Brief an den Hoster, wenn nur der helfen kann | Textvorlage mit Seitendaten |

## Was es braucht
- WordPress-Laufzeit zum Testen: **vorhanden** (@wp-playground/cli). Lighthouse + Chromium: **vorhanden**.
- Apache/.htaccess-Test: **fehlt** (Playground hat keinen Apache) → Regeln sind in `<IfModule>` gekapselt, nur Syntax geprüft.
- Echte öffentliche URL für PageSpeed Insights: **fehlt** → Funktion eingebaut, im Test nur Fehlerpfad prüfbar.
