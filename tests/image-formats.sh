#!/bin/bash
# Image-format scenario test with an outside judge (Chromium via Playwright):
#   A plain site: Plakette check fails → fix (AVIF/WebP copies + <picture>) → Chromium downloads fewer bytes, nothing broken,
#     also when the browser cannot show AVIF/WebP (--fallback);
#   B EWWW-like markup (picture + lazy data-srcset + noscript, JS-WebP mode) → checks recognise EWWW, no own wrapping;
#   C server negotiation by Accept header (URL stays .jpg) → checks recognise it.
# Usage: PORT=9416 tests/image-formats.sh [php-version]      Results: tests/out/images/
set -u
P=$(cd "$(dirname "$0")/.." && pwd)
PHPV=${1:-8.4}
PORT=${PORT:-9416}
OUT=$P/tests/out/images
mkdir -p "$OUT"
echo '{ "steps": [ { "step": "activatePlugin", "pluginPath": "plakette/plakette.php" } ] }' > "$OUT/bp.json"
npx -y @wp-playground/cli@latest server --port=$PORT --php=$PHPV --workers=2 --login \
  --mount="$P/src/plakette:/wordpress/wp-content/plugins/plakette" \
  --mount="$P/tests/mu:/wordpress/wp-content/mu-plugins" \
  --mount="$P/tests:/wordpress/plk-tests" \
  --define-bool WP_DEBUG true --define-bool WP_DEBUG_LOG true --define-bool WP_DEBUG_DISPLAY false \
  --blueprint="$OUT/bp.json" > "$OUT/server.log" 2>&1 &
SERVER=$!
stop() { pkill -P $SERVER 2>/dev/null; kill $SERVER 2>/dev/null; ps -eo pid,args | awk '/wp-playground-cli server --port='$PORT'/ && !/awk/ {print $1}' | xargs -r kill 2>/dev/null; }
trap stop EXIT
for i in $(seq 1 120); do [ "$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$PORT/)" = "200" ] && break; sleep 2; done
H=http://127.0.0.1:$PORT
U=$H/plk-tests
RECIPE=$(curl -s $U/seed.php | sed -n 's/.*recipe=\([0-9]*\).*/\1/p')
PAGES="$H/ $H/?p=${RECIPE:-10}"
judge() { NODE_PATH=/usr/local/lib/node_modules node "$P/tests/image-formats.mjs" "$@"; }
rows() { python3 -c "import json,sys; [print('   ', r[:300]) for r in json.load(open(sys.argv[1]))['rows'] if r.split()[1] in ('speed_webp','speed_images','speed_lcp')]" "$1"; }

echo "== A: plain JPEGs, before"
curl -s --max-time 600 "$U/run.php?step=audit" > "$OUT/A1-audit.json"; rows "$OUT/A1-audit.json"
judge --out "$OUT/A1-chromium.json" $PAGES
curl -s --max-time 900 "$U/run.php?step=audit" > /dev/null
for c in legacy_imm_plugins speed_lcp speed_webp; do curl -s --max-time 900 "$U/run.php?step=fixall&only=$c" | grep -o "\"$c\": \"[^\"]*\""; done
echo "== A: after fix"
curl -s --max-time 600 "$U/run.php?step=audit" > "$OUT/A2-audit.json"; rows "$OUT/A2-audit.json"
judge --out "$OUT/A2-chromium.json" $PAGES
judge --fallback --out "$OUT/A2-fallback.json" $PAGES
curl -s "$H/?plakette_measure=1" | grep -o '<picture[^>]*>\(<source[^>]*>\)*' | head -3
curl -s --max-time 600 "$U/run.php?step=undoall" | grep -c '"ok"'

echo "== B: EWWW-like markup"
curl -s --max-time 600 "$U/tools/mimic.php?mode=ewww"
curl -s --max-time 600 "$U/run.php?step=audit" > "$OUT/B-audit.json"; rows "$OUT/B-audit.json"
judge --out "$OUT/B-chromium.json" $PAGES
judge --fallback --out "$OUT/B-fallback.json" $PAGES

echo "== C: server negotiation (Accept header, URL stays .jpg)"
curl -s --max-time 600 "$U/tools/mimic.php?mode=accept"
curl -s --max-time 600 "$U/run.php?step=audit" > "$OUT/C-audit.json"; rows "$OUT/C-audit.json"
judge --out "$OUT/C-chromium.json" $PAGES
curl -s --max-time 600 "$U/tools/mimic.php?mode=off" > /dev/null
curl -s $H/wp-content/debug.log > "$OUT/debug.log"
echo "PHP-Meldungen aus dem Plugin: $(grep -E 'PHP (Warning|Notice|Fatal|Deprecated)' "$OUT/debug.log" | grep -c plakette)"
