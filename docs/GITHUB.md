# GitHub verbinden – Schritt für Schritt (für Einsteiger)

Am Ende ist Folgendes eingerichtet: Claude schreibt eine neue Version und schiebt („pusht“) sie zu GitHub.
GitHub baut daraus automatisch ein Release mit `plakette.zip`. Jede Website mit Plakette sieht das Release und
aktualisiert sich selbst.

```
Claude (dieses Projekt) ──push──▶ GitHub-Repo mblmg/plakette ──Tag v1.2.3──▶ GitHub-Action baut Release
                                                                                     │
                       alle WordPress-Seiten mit Plakette ◀── fragen alle 6 h nach ──┘
```

Du brauchst etwa 10 Minuten und machst das **einmal**.

## 1. Repository auf GitHub anlegen
1. Auf https://github.com anmelden (Konto `mblmg`).
2. Oben rechts auf **+** klicken, dann **New repository**.
3. **Repository name:** `plakette` (genau so, der Updater sucht nach `mblmg/plakette`).
4. **Public** wählen. Dann brauchen deine Websites keinen Schlüssel, um Updates zu laden.
   Bei **Private** muss jede Website einen Token in der `wp-config.php` haben (siehe unten).
5. **Nichts** ankreuzen (kein README, keine .gitignore, keine Lizenz – das bringt das Projekt schon mit).
6. **Create repository** klicken.

## 2. Einen Zugangsschlüssel (Token) für Claude erstellen
Mit dem Token darf das Projekt in genau dieses eine Repository schreiben, sonst nirgends.

1. Auf GitHub oben rechts auf dein Profilbild klicken, dann **Settings**, ganz unten links **Developer settings**,
   dann **Personal access tokens** und **Fine-grained tokens**, dann **Generate new token**.
   Direktlink: https://github.com/settings/personal-access-tokens/new
2. **Token name:** `Plakette Studio`. **Expiration:** z. B. 1 Jahr. Danach einfach einen neuen erstellen.
3. **Repository access:** *Only select repositories* und dann `mblmg/plakette` wählen.
4. **Permissions › Repository permissions:**
   - **Contents:** *Read and write* (Code und Releases hochladen)
   - **Workflows:** *Read and write* (nötig, weil das Projekt die Datei `.github/workflows/release.yml` mitbringt)
   - **Metadata:** *Read-only* (setzt GitHub automatisch)
5. **Generate token** klicken und den Schlüssel (beginnt mit `github_pat_…`) kopieren. GitHub zeigt ihn nur einmal an.

> Schreib den Token **nicht in den Chat**. Du gibst ihn gleich selbst im Terminal ein.

## 3. Das Projekt mit GitHub verbinden (im Terminal des Studios)
Öffne im Studio den Reiter **Terminal** und füge diese Zeilen ein (Enter nach jeder Zeile):

```bash
cd /data/projects/1/8
git config --global credential.helper store
git remote add origin https://github.com/mblmg/plakette.git
git push -u origin main
```

Beim `git push` fragt das Terminal zwei Dinge:
- **Username:** `mblmg`
- **Password:** hier den **Token** einfügen (nicht dein GitHub-Passwort). Beim Einfügen siehst du keine Zeichen – das ist normal. Dann Enter.

Git speichert den Token in deinem Benutzerordner (`~/.git-credentials`). Ab jetzt kann Claude ohne Nachfrage pushen.
Prüfen: Auf https://github.com/mblmg/plakette sind die Projektdateien jetzt zu sehen.

## 4. Claude Bescheid sagen
Schreib im Chat z. B. „GitHub ist verbunden, veröffentliche Version 1.0.0“. Claude führt dann aus:

```bash
tools/release.sh 1.0.0 --push
```

Das Skript setzt die Versionsnummer, prüft den Code, baut das ZIP, setzt den Tag `v1.0.0` und pusht. Auf GitHub
startet dann unter **Actions** der Ablauf „Release“. Ist er nach 1–2 Minuten grün, liegt unter **Releases** die
Datei `plakette.zip`.

## 5. Plakette einmal auf jeder Website installieren
Websites, auf denen Plakette noch nicht läuft, bekommen sie einmal von Hand:
- WordPress › Plugins › Installieren › Plugin hochladen: `plakette.zip` aus dem Release
  (Direktlink: https://github.com/mblmg/plakette/releases/latest/download/plakette.zip), oder
- per WP-CLI: `wp plugin install https://github.com/mblmg/plakette/releases/latest/download/plakette.zip --activate`

Ab dann kommt jede neue Version von selbst.

## So laufen künftige Updates
1. Du sagst Claude, was es ändern soll.
2. Claude ändert, testet, schreibt den Changelog und führt `tools/release.sh 1.1.0 --push` aus.
3. GitHub baut das Release.
4. Jede Website fragt spätestens nach 6 Stunden bei GitHub nach. WordPress installiert automatische Updates
   etwa zweimal am Tag. Die neue Version ist also meist innerhalb eines halben Tages überall.
   Sofort geht es in WordPress unter **Plakette › Einstellungen › Plugin › Jetzt nachsehen**, danach unter
   **Plugins** auf „Jetzt aktualisieren“.
5. Automatische Updates lassen sich je Website abschalten: **Plakette › Einstellungen › Plugin**.

## Häufige Fragen
- **„Authentication failed“ beim Push:** Token falsch kopiert, abgelaufen oder ohne *Contents: Read and write*.
  Einen neuen Token erstellen und gespeicherte Daten löschen: `rm ~/.git-credentials`, dann noch einmal `git push`.
- **„refusing to allow a Personal Access Token to create or update workflow“:** Beim Token fehlt *Workflows: Read and write*.
- **„remote origin already exists“:** `git remote set-url origin https://github.com/mblmg/plakette.git`
- **Privates Repository:** In die `wp-config.php` jeder Website gehört dann
  `define( 'PLAKETTE_GITHUB_TOKEN', 'github_pat_…' );`. Dafür einen eigenen Token erstellen, der nur *Contents: Read-only* hat.
- **Token widerrufen:** GitHub › Settings › Developer settings › Fine-grained tokens › Token › *Revoke*.
