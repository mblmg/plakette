#!/bin/bash
# PHP syntax check for every plugin file (PHP-WASM, no local PHP needed). Usage: tools/lint.sh [files…]
cd "$(dirname "$0")/.." || exit 1
FILES=("$@")
[ ${#FILES[@]} -eq 0 ] && mapfile -t FILES < <(find src/plakette -name '*.php' | sort)
fail=0
for f in "${FILES[@]}"; do
  out=$(npx -y @php-wasm/cli@latest -l "$f" 2>&1)
  if ! grep -q "No syntax errors" <<<"$out"; then echo "$out" | grep -v '^$' | head -5; fail=1; fi
done
[ $fail -eq 0 ] && echo "PHP-Syntax OK (${#FILES[@]} Dateien)"
exit $fail
