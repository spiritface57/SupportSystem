#!/bin/bash
set -euo pipefail

API_BASE="${API_BASE:-http://localhost:8000/api/upload}"
FILE="${1:-}"
CHUNK_SIZE="${CHUNK_SIZE:-1048576}"

if [ -z "${FILE}" ] || [ ! -f "${FILE}" ]; then
  echo "Usage: $0 <file>"
  exit 1
fi

FILENAME="$(basename "$FILE")"
TOTAL_BYTES="$(stat -c%s "$FILE" 2>/dev/null || stat -f%z "$FILE")"

echo "Preparing upload for $FILENAME ($TOTAL_BYTES bytes)"
echo "API_BASE=$API_BASE"
echo "CHUNK_SIZE=$CHUNK_SIZE"

# ---------- 1) INIT ----------
INIT_RESPONSE="$(curl -sS -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$API_BASE/init" \
  -H "Content-Type: application/json" \
  -d "{
    \"filename\": \"$FILENAME\",
    \"total_bytes\": $TOTAL_BYTES,
    \"chunk_bytes\": $CHUNK_SIZE
  }")"

INIT_STATUS="$(echo "$INIT_RESPONSE" | sed -n 's/.*HTTP_STATUS:\([0-9][0-9][0-9]\).*/\1/p')"
INIT_BODY="$(echo "$INIT_RESPONSE" | sed 's/HTTP_STATUS:.*//')"

echo "init status=$INIT_STATUS body=$INIT_BODY"

if [ "$INIT_STATUS" != "201" ] && [ "$INIT_STATUS" != "200" ]; then
  echo "❌ Init failed"
  exit 1
fi

UPLOAD_ID="$(echo "$INIT_BODY" | sed -n 's/.*"upload_id"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')"
if [ -z "$UPLOAD_ID" ]; then
  echo "❌ Init failed: upload_id missing"
  exit 1
fi

echo "upload_id=$UPLOAD_ID"

# ---------- 2) CHUNK ----------
TMP_DIR="$(mktemp -d)"
split -b "$CHUNK_SIZE" -d "$FILE" "$TMP_DIR/chunk_"
echo "local chunks: $(ls -1 "$TMP_DIR"/chunk_* | wc -l)"

INDEX=0
for CHUNK in "$TMP_DIR"/chunk_*; do
  RESP="$(curl -sS -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$API_BASE/chunk" \
    -F "upload_id=$UPLOAD_ID" \
    -F "index=$INDEX" \
    -F "chunk=@$CHUNK")"

  STATUS="$(echo "$RESP" | sed -n 's/.*HTTP_STATUS:\([0-9][0-9][0-9]\).*/\1/p')"
  BODY="$(echo "$RESP" | sed 's/HTTP_STATUS:.*//')"

  echo "chunk index=$INDEX status=$STATUS body=$BODY"

  if [ "$STATUS" != "200" ]; then
    echo "❌ Chunk upload failed at index=$INDEX"
    rm -rf "$TMP_DIR"
    exit 1
  fi

  INDEX=$((INDEX + 1))
done

rm -rf "$TMP_DIR"
echo "Uploaded $INDEX chunks"

# ---------- 3) DOUBLE FINALIZE (concurrent) ----------
OUT_DIR="$(mktemp -d)"
OUT1="$OUT_DIR/finalize1.txt"
OUT2="$OUT_DIR/finalize2.txt"

call_finalize() {
  local out="$1"
  curl -sS -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$API_BASE/finalize" \
    -H "Content-Type: application/json" \
    -d "{
      \"upload_id\": \"$UPLOAD_ID\",
      \"filename\": \"$FILENAME\",
      \"total_bytes\": $TOTAL_BYTES
    }" > "$out"
}

call_finalize "$OUT1" & PID1=$!
call_finalize "$OUT2" & PID2=$!

wait $PID1 || true
wait $PID2 || true

echo "---- FINALIZE RESPONSE 1 ----"
cat "$OUT1"
echo "---- FINALIZE RESPONSE 2 ----"
cat "$OUT2"

# Parse finalize responses
F1_STATUS="$(sed -n 's/.*HTTP_STATUS:\([0-9][0-9][0-9]\).*/\1/p' "$OUT1" | tail -n 1 || true)"
F2_STATUS="$(sed -n 's/.*HTTP_STATUS:\([0-9][0-9][0-9]\).*/\1/p' "$OUT2" | tail -n 1 || true)"

# Detect obvious misroute (302 redirect HTML)
if [ "$F1_STATUS" = "302" ] && [ "$F2_STATUS" = "302" ]; then
  echo "❌ Both finalize calls returned 302 redirect. Wrong API_BASE or route behind web middleware."
  rm -rf "$OUT_DIR"
  exit 1
fi

OK_COUNT="$(grep -h '"finalized"[[:space:]]*:[[:space:]]*true' "$OUT1" "$OUT2" 2>/dev/null | wc -l | tr -d ' ')"
# LOCK_COUNT="$(grep -h -c '"reason"[[:space:]]*:[[:space:]]*"finalize_in_progress"\|"reason"[[:space:]]*:[[:space:]]*"finalize_locked"' "$OUT1" "$OUT2" 2>/dev/null || true)"

if [ "${OK_COUNT:-0}" -ge 1 ] && \
   [ "$F1_STATUS" = "200" ] && \
   [ "$F2_STATUS" = "200" ] && \
   ! grep -q '"reason"[[:space:]]*:' "$OUT1" "$OUT2"; then
  echo "✅ PASS: duplicate finalize is idempotent"
  rm -rf "$OUT_DIR"
  exit 0
fi


echo "❌ FAIL: expected (>=1 finalized:true) + (>=1 finalize_in_progress/finalize_locked)"
echo "Details:"
echo "OK_COUNT=$OK_COUNT"
# echo "LOCK_COUNT=$LOCK_COUNT"
echo "F1_STATUS=$F1_STATUS"
echo "F2_STATUS=$F2_STATUS"
rm -rf "$OUT_DIR"
exit 1
