#!/bin/bash
set -euo pipefail

API="${API_BASE:-http://localhost:8000/api/upload}"

META="${1:-}"
UPLOAD_ID=$(echo "$META" | cut -d'|' -f1)
FILENAME=$(echo "$META" | cut -d'|' -f2)
TOTAL_BYTES=$(echo "$META" | cut -d'|' -f3)

if [ -z "$UPLOAD_ID" ] || [ -z "$FILENAME" ] || [ -z "$TOTAL_BYTES" ]; then
  echo "Usage: $0 '<upload_id>|<filename>|<total_bytes>'"
  exit 1
fi

call_finalize() {
  curl -sS -X POST "$API/finalize" \
    -H "Content-Type: application/json" \
    -d "{
      \"upload_id\": \"$UPLOAD_ID\",
      \"filename\": \"$FILENAME\",
      \"total_bytes\": $TOTAL_BYTES
    }"
}

call_finalize & PID1=$!
call_finalize & PID2=$!

wait $PID1
wait $PID2

echo "Double finalize done for $UPLOAD_ID"
