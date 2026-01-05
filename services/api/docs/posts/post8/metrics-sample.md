# Post 8 Metrics (Measured)

- Generated at: 2026-01-04T22:00:39.743212Z
- DB driver: sqlite

## Event Counts

| Event | Count |
|---|---:|
| `upload.chunk.received` | 10 |
| `upload.scan.started` | 10 |
| `upload.finalized` | 5 |
| `upload.initiated` | 5 |
| `upload.published` | 5 |
| `upload.scan.completed` | 5 |
| `upload.scan.failed` | 5 |

## Finalize Latency (ms) by Status

| Status | N | avg | p50 | p95 | max |
|---|---:|---:|---:|---:|---:|
| `pending_scan` | 5 | 5293 | 5290 | 5335 | 5464 |

## Overall Finalize Latency (ms)

- N: 5
- avg: 5293
- p50: 5290
- p95: 5335
- max: 5464

## Rescan Publish

- upload.published count: 5

