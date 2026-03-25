# Load Tests

This directory contains k6-based load tests for the upload pipeline.

---

## Current script

- `k6_upload_pipeline.js`

This script exercises the upload flow end-to-end:

- init
- chunk upload
- finalize

---

## Purpose

The goal is to observe runtime behavior of the upload pipeline under bounded concurrent traffic.

This test is used as a smoke-load signal after infrastructure or storage changes.

It is not treated as a complete correctness proof for storage policy or distributed durability.

---

## Metrics

The current script records:

- `upload_init_ms`
- `upload_chunk_ms`
- `upload_finalize_ms`
- `upload_total_ms`
- `upload_errors`

---

## Environment variables

- `API_BASE`
- `FILE_PATH`
- `CHUNK_SIZE`
- `PAUSE_BETWEEN_CHUNKS_MS`
- `VUS`
- `DURATION`
- `ITERATIONS`

### Optional SLO thresholds

- `SLO_ERROR_RATE`
- `SLO_P95_INIT_MS`
- `SLO_P95_CHUNK_MS`
- `SLO_P95_FINALIZE_MS`
- `SLO_P95_TOTAL_MS`

---

## Example

### Direct run

```bash
k6 run scripts/load-tests/k6_upload_pipeline.js
```

### Using staged shell runner

```bash
bash scripts/load-tests/run_k6_upload_pipeline.sh
```

---

## Interpretation limits

### This test is useful for

- latency visibility
- error-rate visibility
- smoke-load behavior after refactors

### This test is NOT sufficient to prove

- final/quarantine storage correctness
- rescan correctness
- distributed system properties
