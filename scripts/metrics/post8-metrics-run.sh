#!/usr/bin/env bash
set -euo pipefail

API_BASE="${API_BASE:-http://localhost:8000/api}"
RUNS_CLEAN="${RUNS_CLEAN:-10}"
RUNS_PENDING="${RUNS_PENDING:-10}"
FILE_BYTES="${FILE_BYTES:-10485760}"   # 10MB
CHUNK_BYTES="${CHUNK_BYTES:-1048576}"  # 1MB
SCANNER_SERVICE="${SCANNER_SERVICE:-scanner}"
TMP_DIR="${TMP_DIR:-files/metrics_tmp}"

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "ERROR: missing command: $1"; exit 1; }
}

# Run a local tool inside api container to avoid Windows tooling mismatch
php_json_get() {
  # $1 = json string, $2 = key
  docker compose exec -T api php -r '
    $j = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($j)) { fwrite(STDERR, "JSON_PARSE_ERROR\n"); exit(2); }
    $key = $argv[1];
    if (!array_key_exists($key, $j)) { fwrite(STDERR, "JSON_KEY_MISSING:$key\n"); exit(3); }
    echo $j[$key];
  ' "$2" <<<"$1"
}

curl_json() {
  # prints body; fails non-2xx with body printed to stderr
  local method="$1"; shift
  local url="$1"; shift

  local tmp_body
  tmp_body="$(mktemp)"

  local status
  status="$(curl -sS -o "$tmp_body" -w "%{http_code}" -X "$method" "$url" "$@")" || {
    echo "ERROR: curl failed for $method $url" >&2
    cat "$tmp_body" >&2 || true
    rm -f "$tmp_body"
    exit 1
  }

  if [[ "$status" != 2* ]]; then
    echo "ERROR: HTTP $status for $method $url" >&2
    echo "---- body ----" >&2
    cat "$tmp_body" >&2 || true
    echo "-------------" >&2
    rm -f "$tmp_body"
    exit 1
  fi

  cat "$tmp_body"
  rm -f "$tmp_body"
}

init_upload() {
  local filename="$1"
  local total="$2"
  local chunk="$3"

  curl_json POST "${API_BASE}/upload/init" \
    -H "Content-Type: application/json" \
    -d "{\"filename\":\"${filename}\",\"total_bytes\":${total},\"chunk_bytes\":${chunk}}"
}

upload_chunks() {
  local upload_id="$1"
  local total="$2"
  local chunk="$3"

  local expected=$(( (total + chunk - 1) / chunk ))
  mkdir -p "${TMP_DIR}"

  for ((i=0; i<expected; i++)); do
    local remaining=$(( total - (i * chunk) ))
    local size="$chunk"
    if (( remaining < chunk )); then size="$remaining"; fi

    local part="${TMP_DIR}/chunk_${upload_id}_${i}.bin"
    dd if=/dev/urandom of="${part}" bs=1 count="${size}" status=none

    curl_json POST "${API_BASE}/upload/chunk" \
      -F "upload_id=${upload_id}" \
      -F "index=${i}" \
      -F "chunk=@${part}" >/dev/null
  done
}

finalize_upload() {
  local upload_id="$1"
  local filename="$2"
  local total="$3"

  curl_json POST "${API_BASE}/upload/finalize" \
    -H "Content-Type: application/json" \
    -d "{\"upload_id\":\"${upload_id}\",\"filename\":\"${filename}\",\"total_bytes\":${total}}"
}

run_batch() {
  local batch="$1"
  local runs="$2"

  echo "==> Batch: ${batch} (runs=${runs}, file=${FILE_BYTES} bytes, chunk=${CHUNK_BYTES} bytes)"

  for ((n=1; n<=runs; n++)); do
    local fname="metric_${batch}_${n}.bin"

    local init_json
    init_json="$(init_upload "${fname}" "${FILE_BYTES}" "${CHUNK_BYTES}")"

    local upload_id
    upload_id="$(php_json_get "$init_json" "upload_id")"

    upload_chunks "${upload_id}" "${FILE_BYTES}" "${CHUNK_BYTES}"

    local fin_json
    fin_json="$(finalize_upload "${upload_id}" "${fname}" "${FILE_BYTES}")"

    local status
    status="$(php_json_get "$fin_json" "status")"

    echo "  - ${batch}#${n}: upload_id=${upload_id} status=${status}"
  done
}

main() {
  need_cmd docker
  need_cmd curl
  need_cmd dd

  echo "==> Reset DB for clean metrics run (migrate:fresh --force)"
  docker compose exec -T api php artisan migrate:fresh --force >/dev/null

  echo "==> Clean storage state (quarantine/final/tmp/uploads-meta/uploads)"
  docker compose exec -T api sh -lc '
  rm -rf storage/app/quarantine/uploads/* || true
  rm -rf storage/app/final/uploads/* || true
  rm -rf storage/app/tmp/* || true
  rm -rf storage/app/uploads/* || true
  rm -rf storage/app/uploads-meta/* || true
  ' >/dev/null

  echo "==> Ensure metrics output dir exists (inside api container)"
  docker compose exec -T api sh -lc 'mkdir -p docs/posts/post8' >/dev/null

  echo "==> Ensure scanner is UP (warmup 10s)"
  docker compose up -d "${SCANNER_SERVICE}" >/dev/null || true
  sleep 10

  run_batch "clean" "${RUNS_CLEAN}"

  echo "==> Stop scanner to force pending_scan"
  docker compose stop "${SCANNER_SERVICE}" >/dev/null || true

  run_batch "pending" "${RUNS_PENDING}"

  echo "==> Start scanner again (warmup 10s)"
  docker compose up -d "${SCANNER_SERVICE}" >/dev/null || true
  sleep 10

  echo "==> Run rescan worker"
  docker compose exec -T api php artisan upload:rescan-pending --limit=500 >/dev/null || true

  echo "==> Generate metrics report"
  docker compose exec -T api php artisan upload:metrics-report --out=docs/posts/post8/metrics-output.md >/dev/null

  echo "==> Done. Output: docs/posts/post8/metrics-output.md"
}

main "$@"
