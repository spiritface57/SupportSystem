#!/bin/bash
set -euo pipefail

API_BASE="${API_BASE:-http://localhost:8000/api/upload}"
FILE_PATH="${FILE_PATH:-../services/api/file-upload-scan/files/a.png}"
CHUNK_SIZE="${CHUNK_SIZE:-1048576}"
PAUSE_BETWEEN_CHUNKS_MS="${PAUSE_BETWEEN_CHUNKS_MS:-0}"
DURATION="${DURATION:-30s}"

OUT_DIR="${OUT_DIR:-artifacts}"
SCRIPT_PATH="${SCRIPT_PATH:-scripts/load-tests/k6_upload_pipeline.js}"

mkdir -p "$OUT_DIR"

run_stage() {
  local vus="$1"
  local tag="$2"

  local summary="$OUT_DIR/k6_summary_${tag}.json"
  local metrics="$OUT_DIR/k6_metrics_${tag}.json"

  echo "=== Running stage: VUS=$vus DURATION=$DURATION tag=$tag ==="
  k6 run \
    -e API_BASE="$API_BASE" \
    -e FILE_PATH="$FILE_PATH" \
    -e CHUNK_SIZE="$CHUNK_SIZE" \
    -e PAUSE_BETWEEN_CHUNKS_MS="$PAUSE_BETWEEN_CHUNKS_MS" \
    -e VUS="$vus" \
    -e DURATION="$DURATION" \
    --summary-export="$summary" \
    --out "json=$metrics" \
    "$SCRIPT_PATH"
}

run_stage 10 "vus10"
run_stage 20 "vus20"
run_stage 40 "vus40"

echo "Done. Summaries and metrics in: $OUT_DIR"
