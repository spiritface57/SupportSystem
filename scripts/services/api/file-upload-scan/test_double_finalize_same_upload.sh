#!/bin/bash
set -euo pipefail

API_BASE="${API_BASE:-http://localhost:8000/api/upload}"
API_CONTAINER="$(docker compose ps -q php | xargs docker inspect -f '{{.Name}}' | sed 's#^/##')"
FILE="${1:-}"
CHUNK_SIZE="${CHUNK_SIZE:-1048576}"

# --- tuning knobs ---
BARRIER_LOG_PATTERN_PREFIX="${BARRIER_LOG_PATTERN_PREFIX:-finalize_barrier_wait}"
BARRIER_WAIT_MAX_TRIES="${BARRIER_WAIT_MAX_TRIES:-120}"   # 120 * 0.1s = 12s
BARRIER_WAIT_SLEEP_SEC="${BARRIER_WAIT_SLEEP_SEC:-0.1}"

if [ -z "${FILE}" ] || [ ! -f "${FILE}" ]; then
  echo "Usage: $0 <file>"
  exit 1
fi

FILENAME="$(basename "$FILE")"
TOTAL_BYTES="$(stat -c%s "$FILE" 2>/dev/null || stat -f%z "$FILE")"

echo "Preparing upload for $FILENAME ($TOTAL_BYTES bytes)"
echo "API_BASE=$API_BASE"
echo "CHUNK_SIZE=$CHUNK_SIZE"
echo "API_CONTAINER=$API_CONTAINER"

# ---------- 0) Preflight: ensure laravel.log is readable ----------
# (You said you reset logs yourself; still, we guard against missing file)
docker exec "$API_CONTAINER" sh -lc 'mkdir -p storage/logs && touch storage/logs/laravel.log'

# ---------- 1) INIT ----------
INIT="$(curl -sS -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$API_BASE/init" \
  -H "Content-Type: application/json" \
  -d "{\"filename\":\"$FILENAME\",\"total_bytes\":$TOTAL_BYTES,\"chunk_bytes\":$CHUNK_SIZE}")"

INIT_STATUS="$(echo "$INIT" | sed -n 's/.*HTTP_STATUS:\([0-9]\{3\}\).*/\1/p')"
INIT_BODY="$(echo "$INIT" | sed 's/HTTP_STATUS:.*//')"

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
echo "local chunks: $(ls -1 "$TMP_DIR"/chunk_* | wc -l | tr -d ' ')"

INDEX=0
for CHUNK in "$TMP_DIR"/chunk_*; do
  RESP="$(curl -sS -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$API_BASE/chunk" \
    -F "upload_id=$UPLOAD_ID" \
    -F "index=$INDEX" \
    -F "chunk=@$CHUNK")"

  STATUS="$(echo "$RESP" | sed -n 's/.*HTTP_STATUS:\([0-9]\{3\}\).*/\1/p')"
  BODY="$(echo "$RESP" | sed 's/HTTP_STATUS:.*//')"

  echo "chunk index=$INDEX status=$STATUS body=$BODY"
  [ "$STATUS" = "200" ] || { echo "❌ chunk failed"; rm -rf "$TMP_DIR"; exit 1; }
  INDEX=$((INDEX+1))
done
rm -rf "$TMP_DIR"
echo "Uploaded $INDEX chunks"

# ---------- 3) BARRIER PATH INSIDE CONTAINER ----------
BARRIER_FILE="storage/app/tmp/barriers/finalize-${UPLOAD_ID}.release"
echo "Barrier file (in container)=$BARRIER_FILE"

# make sure barrier does NOT already exist
docker exec "$API_CONTAINER" sh -lc "rm -f '$BARRIER_FILE' || true"

OUT_DIR="$(mktemp -d)"
OUT1="$OUT_DIR/f1.txt"
OUT2="$OUT_DIR/f2.txt"

finalize_call() {
  local out="$1"
  curl -sS -w "\nHTTP_STATUS:%{http_code}\n" -X POST "$API_BASE/finalize" \
    -H "Content-Type: application/json" \
    -d "{\"upload_id\":\"$UPLOAD_ID\",\"filename\":\"$FILENAME\",\"total_bytes\":$TOTAL_BYTES}" > "$out"
}

echo "NOTE: finalize#1 must BLOCK at barrier, finalize#2 should hit while lock is held."

# Optional: marker line in laravel.log so our grep window is reliable even if log isn't empty.
MARK="TEST_DOUBLE_FINALIZE_MARKER upload_id=${UPLOAD_ID} ts=$(date +%s%3N)"
docker exec "$API_CONTAINER" sh -lc "echo '$MARK' >> storage/logs/laravel.log"

# Start finalize #1 (should BLOCK at barrier)
finalize_call "$OUT1" & PID1=$!

echo "Waiting for finalize#1 to enter barrier (by checking storage/logs/laravel.log)..."

FOUND=0
for i in $(seq 1 "$BARRIER_WAIT_MAX_TRIES"); do
  # We look for the barrier_wait log line for this upload_id in the last 600 lines.
  # (If you reset logs, this is perfect. If you don't, it's still pretty safe.)
  if docker exec "$API_CONTAINER" sh -lc \
    "tail -n 600 storage/logs/laravel.log 2>/dev/null | grep -q \"${BARRIER_LOG_PATTERN_PREFIX}.*${UPLOAD_ID}\""; then
    FOUND=1
    break
  fi
  sleep "$BARRIER_WAIT_SLEEP_SEC"
done

if [ "$FOUND" = "0" ]; then
  echo "❌ finalize#1 did NOT enter barrier_wait (no matching log line found)."
  echo "   Possible causes:"
  echo "   - barrier is not enabled (FINALIZE_BARRIER not set / code path not wired)"
  echo "   - log pattern differs (set BARRIER_LOG_PATTERN_PREFIX=...)"
  echo "   - Laravel is not writing to storage/logs/laravel.log"
  echo "Continuing anyway with a fixed sleep..."
  sleep 1
else
  echo "✅ Observed barrier_wait for upload_id=$UPLOAD_ID"
fi

# Start finalize #2 (should fail with finalize_in_progress IF concurrency+lock is real)
finalize_call "$OUT2" & PID2=$!

# Give finalize #2 a moment to hit the server
sleep 0.3

# Release barrier for finalize #1 INSIDE container
echo "Releasing barrier by creating file inside container..."
docker exec "$API_CONTAINER" sh -lc "mkdir -p $(dirname "$BARRIER_FILE") && date > '$BARRIER_FILE' && ls -l '$BARRIER_FILE'"

wait "$PID2" || true
wait "$PID1" || true

echo "---- FINALIZE 1 ----"
cat "$OUT1"
echo
echo "---- FINALIZE 2 ----"
cat "$OUT2"
echo

F2_HAS_INPROG="$(grep -c '"reason"[[:space:]]*:[[:space:]]*"finalize_in_progress"' "$OUT2" 2>/dev/null || true)"
F2_HAS_LOCKED="$(grep -c '"reason"[[:space:]]*:[[:space:]]*"finalize_locked"' "$OUT2" 2>/dev/null || true)"
F2_HTTP="$(sed -n 's/.*HTTP_STATUS:\([0-9]\{3\}\).*/\1/p' "$OUT2" | tail -n 1 || true)"

if [ "${F2_HAS_INPROG:-0}" -ge 1 ]; then
  echo "✅ PASS: finalize#2 got finalize_in_progress (real concurrency + lock works)"
  rm -rf "$OUT_DIR"
  exit 0
fi

# If barrier wasn't observed, it's possible finalize#2 hits after commit and gets finalize_locked.
if [ "${F2_HAS_LOCKED:-0}" -ge 1 ]; then
  echo "⚠️  Got finalize_locked instead of finalize_in_progress."
  echo "   This usually means finalize#2 arrived after commit (race window missed), or barrier didn't actually block."
  echo "   Check barrier wiring and/or increase delay/window on finalize#1."
  rm -rf "$OUT_DIR"
  exit 1
fi

echo "❌ FAIL: finalize#2 did NOT get finalize_in_progress (HTTP_STATUS=${F2_HTTP:-unknown})"
echo "   Next debug steps:"
echo "   - confirm barrier logs exist in storage/logs/laravel.log"
echo "   - confirm FINALIZE_BARRIER is enabled in the app runtime"
echo "   - instrument PID/microtime around lock acquisition"
rm -rf "$OUT_DIR"
exit 1
