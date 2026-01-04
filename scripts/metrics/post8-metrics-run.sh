#!/usr/bin/env bash
set -euo pipefail

API_BASE="${API_BASE:-http://localhost:8000/api}"
RUNS_CLEAN="${RUNS_CLEAN:-10}"
RUNS_PENDING="${RUNS_PENDING:-10}"
FILE_BYTES="${FILE_BYTES:-52428800}"   # 50MB
CHUNK_BYTES="${CHUNK_BYTES:-1048576}"  # 1MB
SCANNER_SERVICE="${SCANNER_SERVICE:-scanner}"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="${ROOT_DIR}/files/metrics_tmp"
FILE_PATH="${TMP_DIR}/metric.bin"

mkdir -p "${TMP_DIR}"

echo "==> migrate:fresh (metrics run database)"
docker compose exec api php artisan migrate:fresh >/dev/null

make_file() {
  local bytes="$1"
  python - <<PY
import os
p="${FILE_PATH}"
os.makedirs(os.path.dirname(p), exist_ok=True)
with open(p,"wb") as f:
    f.write(os.urandom(${bytes}))
print(p)
PY
}

init_upload() {
  local filename="$1"
  local total="$2"
  local chunk="$3"
  curl -s -X POST "${API_BASE}/upload/init" \
    -H "Content-Type: application/json" \
    -d "{\"filename\":\"${filename}\",\"total_bytes\":${total},\"chunk_bytes\":${chunk}}"
}

upload_chunks() {
  local upload_id="$1"
  local total="$2"
  local chunk="$3"

  local expected=$(( (total + chunk - 1) / chunk ))

  for ((i=0; i<expected; i++)); do
    local offset=$(( i * chunk ))
    local remaining=$(( total - offset ))
    local size="$chunk"
    if (( remaining < chunk )); then size="$remaining"; fi

    local part="${TMP_DIR}/chunk_${i}.bin"
    dd if="${FILE_PATH}" of="${part}" bs=1 skip="${offset}" count="${size}" status=none

    curl -s -X POST "${API_BASE}/upload/chunk" \
      -F "upload_id=${upload_id}" \
      -F "index=${i}" \
      -F "chunk=@${part}" >/dev/null
  done
}

finalize_upload() {
  local upload_id="$1"
  local filename="$2"
  local total="$3"
  curl -s -X POST "${API_BASE}/upload/finalize" \
    -H "Content-Type: application/json" \
    -d "{\"upload_id\":\"${upload_id}\",\"filename\":\"${filename}\",\"total_bytes\":${total}}"
}

run_batch() {
  local batch="$1"
  local runs="$2"

  echo "==> Batch: ${batch} (runs=${runs}, file=${FILE_BYTES} bytes, chunk=${CHUNK_BYTES} bytes)"
  for ((n=1; n<=runs; n++)); do
    make_file "${FILE_BYTES}" >/dev/null

    local fname="metric_${batch}_${n}.bin"
    local init
    init="$(init_upload "${fname}" "${FILE_BYTES}" "${CHUNK_BYTES}")"
    local upload_id
    upload_id="$(python - <<PY
import json
print(json.loads('''${init}''')["upload_id"])
PY
)"
    upload_chunks "${upload_id}" "${FILE_BYTES}" "${CHUNK_BYTES}"

    local fin
    fin="$(finalize_upload "${upload_id}" "${fname}" "${FILE_BYTES}")"
    local status
    status="$(python - <<PY
import json
print(json.loads('''${fin}''').get("status"))
PY
)"
    echo "  - ${batch}#${n}: status=${status}"
  done
}

echo "==> Ensure scanner is UP"
docker compose up -d "${SCANNER_SERVICE}" >/dev/null || true

run_batch "clean" "${RUNS_CLEAN}"

echo "==> Stop scanner to force pending_scan"
docker compose stop "${SCANNER_SERVICE}" >/dev/null || true

run_batch "pending" "${RUNS_PENDING}"

echo "==> Start scanner again"
docker compose up -d "${SCANNER_SERVICE}" >/dev/null || true

echo "==> Run rescan worker"
docker compose exec api php artisan upload:rescan-pending --limit=500 >/dev/null || true

echo "==> Generate metrics report"
docker compose exec api php artisan upload:metrics-report --out=docs/posts/post8/metrics-output.md

echo "==> Done. Output: docs/posts/post8/metrics-output.md"
