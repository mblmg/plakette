# SEO and page speed Wordpress plugin

Area: **Anything Else** · created in Everything Studio

> Wordpress plugin, that performs a complete SEO and page speed audit and then offers to fix every found flaw automatically.

## How this workspace works

- This folder is the whole project. Keep everything inside it.
- **Text first:** creative work is stored as human-readable text sources
  (scores, SVG, glTF JSON, timelines, generator scripts). Renders/binaries are
  produced from them. Never leave the only copy of something in a binary format
  when a text source is possible.
- `src/` — sources · `assets/` — inputs (uploads, imported library assets) ·
  `exports/` — finished deliverables the user downloads (not versioned in git).
- A git checkpoint is created after every Claude turn; the user can restore any
  version from the History tab.
- Asset library: search before creating (`studio lib search …`), import with
  `studio lib get <id>`, save reusable results with `studio lib add …`.
- Check visual work yourself: `studio-browser shot <file> --out .studio/shot.png`
  and read the image; for audio, inspect with `ffprobe`/`sox --info`.

## Area guide

### Anything else

A blank workspace with every toolchain of the image available (Node.js, Python with
numpy/pillow/…, ffmpeg, ImageMagick, .NET, Java, mingw-w64, headless Chromium).

- Put code in `src/`, inputs in `assets/`, results the user should download in `exports/`.
- Write a short README in the project root describing how to run what you built.
- Prefer scripts and text formats the user can inspect and re-run.

**Online features** (multiplayer, cloud saves, leaderboards, accounts, an API): write a Node.js
server in `server/` and let the Studio run it — `studio service new --template multiplayer|storage|basic`,
then `studio service deploy` after every change (prints the public URL + log), `studio service logs`.
The server listens on `$PORT` and keeps data in `$DATA_DIR`; clients use its URL (`…/srv/<name>/`).
The user controls it in the project's **Server** tab.

**Publish as a website** — `studio site publish` puts the game/app online as a real website on the
user's domains (`--root dist --build "npm run build"` for build tools, `--spa` for single-page apps).
On the website the project's servers share the origin: `fetch("/srv/<server>/…")`. The user adds
domains and takes it online/offline in the project's **Website** tab; `studio site status` shows the addresses.

## Project notes

- Plugin „Plakette – Website-Hauptuntersuchung“ (früher Prüfstand) in `src/plakette/` (German UI, du-form; legal texts for visitors Sie-form).
  Author „i-make.media | Magnus Brühl“. Areas: privacy (Datenschutz), security (Sicherheit incl. Recht/DSGVO/Cookie-Banner/BFSG), speed, seo.
- Architecture + conventions: `docs/DEVELOPING.md`. Requires PHP ≥ 8.2 (only versions with security support; raise when 8.2 hits EOL end of 2026). Lint: `tools/lint.sh` (PHP-WASM). Build zip: `tools/build.sh` → `exports/plakette.zip`.
- Distribution: GitHub repo `mblmg/plakette` (= this project), tag `vX.Y.Z` → `.github/workflows/release.yml` builds `plakette.zip` release asset;
  `includes/class-updater.php` (Update URI header) auto-updates installs. Prepare a version: `tools/release.sh X.Y.Z [--push]`.
- Test against real WordPress: `tests/e2e.sh 8.4` (also 8.2) (Playground; needs ≥2 workers for loopback). Parallel runs: `PORT=94xx TAG=name tests/e2e.sh 8.3`.
  Per-topic defects: `tests/seed-*.php`, `tests/mu/plk-*.php`.
- Never name a DB column `undo` (MySQL reserved word – broke the log table once).
