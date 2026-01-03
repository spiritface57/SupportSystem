#!/bin/bash
set -euo pipefail

FILE="${1:-}"
if [ -z "$FILE" ] || [ ! -f "$FILE" ]; then
  echo "Usage: $0 <file>"
  exit 1
fi

echo "Stopping scanner..."
docker compose stop scanner-node >/dev/null 2>&1 || true

echo "Running upload..."
./upload_one_file.sh "$FILE" | tee /tmp/upload_out.txt

echo "Starting scanner back..."
docker compose start scanner-node >/dev/null 2>&1 || true

echo ""
echo "---- Check ----"
if grep -q "pending_scan" /tmp/upload_out.txt; then
  echo "✅ PASS: finalize returned pending_scan while scanner was down"
  exit 0
fi

echo "❌ FAIL: pending_scan not observed"
exit 1
