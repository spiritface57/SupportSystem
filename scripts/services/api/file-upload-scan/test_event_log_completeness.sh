#!/bin/bash
set -euo pipefail

META="${1:-}"
UPLOAD_ID=$(echo "$META" | cut -d'|' -f1)

if [ -z "$UPLOAD_ID" ]; then
  echo "Usage: $0 '<upload_id>|<filename>|<total_bytes>'"
  exit 1
fi

echo "Checking upload_events for upload_id=$UPLOAD_ID"

# This assumes your Laravel container is named 'api' in docker-compose
docker compose exec -T api php artisan tinker --execute="
use App\\Models\\UploadEvent;
\$c = UploadEvent::where('upload_id', '$UPLOAD_ID')->count();
echo \"count=\$c\\n\";
" || { echo "❌ FAIL: unable to query upload_events via tinker"; exit 1; }

echo "✅ PASS (basic): upload_events query executed"
