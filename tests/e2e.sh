#!/bin/bash
# End-to-end test against a real WordPress (WordPress Playground, PHP-WASM + SQLite).
# Usage: tests/e2e.sh [php-version]     Results: tests/out/
set -u
P=$(cd "$(dirname "$0")/.." && pwd)
PHPV=${1:-8.3}
# PORT and TAG allow several runs side by side: PORT=9411 TAG=privacy tests/e2e.sh 8.3
OUT=$P/tests/out/${TAG:-php$PHPV}
PORT=${PORT:-9400}
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
U=http://127.0.0.1:$PORT/plk-tests
curl -s $U/seed.php
curl -s $U/tools/pagetext.php | tee "$OUT/pagetext.txt"
step() { curl -s --max-time 600 "$U/run.php?step=$1" > "$OUT/$2.json"; }
step audit 1-before
step fixall 2-fix
step audit 3-after
step undoall 4-undo
step state 5-state
step features 7-features
step audit 6-after-undo
curl -s http://127.0.0.1:$PORT/wp-content/debug.log > "$OUT/debug.log"
python3 - "$OUT" <<'PY'
import json, sys, re
out = sys.argv[1]
load = lambda n: json.load(open(f"{out}/{n}.json"))
a, f, b, u, c = load("1-before"), load("2-fix"), load("3-after"), load("4-undo"), load("6-after-undo")
st = lambda d: {r.split()[1]: r.split()[0] for r in d["rows"]}
areas = lambda d: " | ".join(f"{k} {v}" for k, v in d["scores"].items() if k not in ("total", "count"))
print("Prüfpunkte:", len(a["rows"]))
print("Feste Funktionen fehlen:", load("7-features").get("missing", "TEST LIEF NICHT") or "keine")
print("Score vorher :", a["scores"]["total"], "|", areas(a), "| Server", a["home"]["time_ms"], "ms")
print("Score nachher:", b["scores"]["total"], "|", areas(b), "| Server", b["home"]["time_ms"], "ms")
errs = {k: v for k, v in f.get("fixes", {}).items() if v.startswith(("ERROR", "LOG"))}
print("Behebungen:", len(f.get("fixes", {})), "davon Fehler:", errs or "keine")
left = {k: v for k, v in st(b).items() if v in ("fail", "warn")}
print("Nach Behebung noch offen:", left)
print("Rückgängig gemacht:", len(u.get("undo", {})), "Fehler:", {k: v for k, v in u.get("undo", {}).items() if v != "ok"} or "keine")
final = {"speed_database", "security_inactive", "security_core_updates", "security_plugin_updates", "security_vulnerabilities"}  # deletions and updates cannot be undone by design
diff = {k: (st(a)[k], st(c)[k]) for k in st(a) if st(a)[k] != st(c)[k] and k not in final}
print("Abweichung vom Ursprung nach Undo:", diff or "keine")
log = open(f"{out}/debug.log").read()
ours = [l for l in log.splitlines() if "plakette" in l and re.search(r"PHP (Warning|Notice|Fatal|Deprecated|Parse)", l)]
print("PHP-Meldungen aus dem Plugin:", len(ours)); [print("  ", l[:220]) for l in ours[:15]]
PY
