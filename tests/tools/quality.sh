#!/bin/bash
# Outside judge for the encoder quality: SSIM (ffmpeg "ssim" filter, Y channel + overall) of every WebP/AVIF copy
# against the lossless reference (ref.png) – the JPEG (q82, what WordPress stores) is listed as baseline.
# Input: tests/out/quality, produced inside Playground by tests/tools/quality.php (same GD encoder as the plugin).
cd "$(dirname "$0")/../out/quality" || exit 1
printf "%-30s %7s %8s %8s\n" file bytes SSIM_all SSIM_dB
for ref in *.ref.png; do
  n=${ref%.ref.png}
  for f in "$n.jpg" $(ls "$n".*.webp "$n".*.avif 2>/dev/null | sort -t. -k2 -n); do
    convert "$f" -strip PNG24:/tmp/plk-q.png
    line=$(ffmpeg -hide_banner -loglevel info -i "$ref" -i /tmp/plk-q.png -lavfi "[0:v]format=yuv444p[a];[1:v]format=yuv444p[b];[a][b]ssim" -f null - 2>&1 | grep -o "All:[0-9.]* ([0-9.inf]*)")
    all=$(sed -E 's/All:([0-9.]+).*/\1/' <<<"$line"); db=$(sed -E 's/.*\(([0-9.inf]+)\)/\1/' <<<"$line")
    printf "%-30s %7d %8s %8s\n" "$f" "$(stat -c %s "$f")" "$all" "$db"
  done
done
