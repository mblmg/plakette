#!/bin/bash
# Neue Version vorbereiten:  tools/release.sh 1.1.0
#  1. setzt die Versionsnummer an allen drei Stellen (Plugin-Header, PLAKETTE_VERSION, readme.txt Stable tag)
#  2. prüft, dass CHANGELOG.md einen Abschnitt „## [1.1.0]“ hat, und die PHP-Syntax
#  3. baut exports/plakette.zip
#  4. committet und setzt den Tag v1.1.0 – mit --push auch gleich zu GitHub (dann baut die Action das Release)
set -e
cd "$(dirname "$0")/.."
V="${1:?Version angeben, z. B. tools/release.sh 1.1.0}"
[[ "$V" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-.][0-9A-Za-z.]+)?$ ]] || { echo "Ungültige Version: $V"; exit 1; }
grep -q "^## \[$V\]" CHANGELOG.md || { echo "CHANGELOG.md braucht zuerst einen Abschnitt: ## [$V] - $(date +%F)"; exit 1; }
F=src/plakette/plakette.php
sed -i -E "s/^( \* Version:\s+).*/\1$V/" $F
sed -i -E "s/define\( 'PLAKETTE_VERSION', '[^']+' \)/define( 'PLAKETTE_VERSION', '$V' )/" $F
sed -i -E "s/^Stable tag: .*/Stable tag: $V/" src/plakette/readme.txt
grep -n "Version:\|PLAKETTE_VERSION'" $F | head -2
tools/lint.sh
tools/build.sh
git add -A
git commit -qm "Version $V" || true
git tag -a "v$V" -m "Plakette $V"
echo "Tag v$V gesetzt."
if [ "${2:-}" = "--push" ]; then
  git push origin HEAD && git push origin "v$V"
  echo "Gepusht. Die GitHub-Action baut jetzt das Release: https://github.com/mblmg/plakette/actions"
else
  echo "Zum Veröffentlichen: git push origin HEAD && git push origin v$V"
fi
