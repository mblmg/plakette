#!/bin/bash
# Baut exports/plakette.zip aus src/plakette (gleicher Inhalt wie das ZIP der GitHub-Action).
set -e
cd "$(dirname "$0")/.."
mkdir -p exports
rm -f exports/plakette.zip
(cd src && zip -qr ../exports/plakette.zip plakette -x '*.DS_Store')
echo "exports/plakette.zip ($(du -h exports/plakette.zip | cut -f1))"
