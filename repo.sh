#!/usr/bin/env bash
set -euo pipefail

OUT="review_bundle_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$OUT"

echo "## TREE" | tee "$OUT/00_tree.txt"
( command -v tree >/dev/null 2>&1 && tree -a -L 6 ) || find . -maxdepth 6 -print | sed 's|^\./||' | sort | tee -a "$OUT/00_tree.txt"

echo "## COMPOSE" | tee "$OUT/01_compose.txt"
ls -al docker-compose* *.yml *.yaml 2>/dev/null || true
for f in docker-compose*.yml docker-compose*.yaml; do
  [ -f "$f" ] && { echo "### $f" >> "$OUT/01_compose.txt"; cat "$f" >> "$OUT/01_compose.txt"; }
done

echo "## ENV" | tee "$OUT/02_env.txt"
for f in .env .env.example env.example; do
  [ -f "$f" ] && { echo "### $f" >> "$OUT/02_env.txt"; sed 's/=.*/=REDACTED/' "$f" >> "$OUT/02_env.txt"; }
done

echo "## API ROUTES" | tee "$OUT/03_routes.txt"
find . -path "*routes*" -type f -maxdepth 4 2>/dev/null | tee -a "$OUT/03_routes.txt" || true
grep -RIn "upload" routes 2>/dev/null | tee -a "$OUT/03_routes.txt" || true

echo "## UPLOAD CONTROLLERS" | tee "$OUT/04_upload_code.txt"
grep -RIn "UploadInitController\|UploadChunk\|UploadFinalize\|finalize\|chunk" services api app 2>/dev/null | head -n 400 | tee -a "$OUT/04_upload_code.txt" || true

echo "## SCANNER SERVICE" | tee "$OUT/05_scanner.txt"
grep -RIn "clam\|INSTREAM\|clamd\|scan" services 2>/dev/null | head -n 400 | tee -a "$OUT/05_scanner.txt" || true

echo "## REDIS QUEUE OR WORKER" | tee "$OUT/06_workers.txt"
grep -RIn "queue\|Redis\|BRPOP\|LPUSH\|HSET\|idempot" services 2>/dev/null | head -n 400 | tee -a "$OUT/06_workers.txt" || true

echo "## RABBITMQ MINIO GRAPHQL WEBSOCKET" | tee "$OUT/07_presence.txt"
grep -RIn "rabbit\|amqp\|minio\|s3\|graphql\|websocket\|socket.io\|ws" . 2>/dev/null | head -n 600 | tee -a "$OUT/07_presence.txt" || true

echo "## TESTS SCRIPTS DOCS" | tee "$OUT/08_tests.txt"
find . -maxdepth 4 -type d -iname "test*" -o -iname "scripts" -o -iname "docs" 2>/dev/null | tee -a "$OUT/08_tests.txt" || true
find . -maxdepth 4 -type f -iname "*post*8*" -o -iname "*changelog*" -o -iname "*readme*" 2>/dev/null | tee -a "$OUT/08_tests.txt" || true

tar -czf "${OUT}.tar.gz" "$OUT"
echo "DONE: ${OUT}.tar.gz"
