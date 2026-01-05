# Post 08 — File Upload & Processing Pipeline (v1.0)
**Topic:** Deterministic file finalization under scanner failure  
**Status:** Technical documentation (repository)  
**Series:** On-Prem Support Platform (LinkedIn Posts)  

> This post is the first “implementation-grade” milestone in the series: a chunked, resumable upload pipeline that stays correct under WAN instability, partial outages, and scanner unavailability. fileciteturn0file1

---

## 1) Why Post 08 exists

On-prem support platforms live in hostile conditions:

- remote branches with unstable links
- all ingress through DMZ/UTM inspection
- large scanned documents (often the majority of payload bytes)
- strict security: **every file is untrusted until scanned**
- strict reliability: DB outage tolerance and failure containment

So the file pipeline is not a “feature”; it’s a reliability and security boundary. fileciteturn0file0

This post documents a pipeline that guarantees:

- **resumable uploads** (chunk-based)
- **deterministic finalize** (correctness over convenience)
- **scanner decoupling** (scanner availability never blocks upload completion)
- **explicit failure taxonomy** (stable, debuggable, user-safe errors)

---

## 2) Goals and non-goals

### Goals
1. **Chunked upload contract**
   - strict chunk index and chunk size rules
   - idempotent chunk re-uploads
2. **Finalize correctness**
   - never produce a corrupted “final” file
   - never finalize with missing chunks
   - atomic assembly/commit
3. **Scanner decoupling**
   - upload completion does not depend on scanner uptime
   - pipeline can enter **pending_scan** and continue later
4. **Security gating**
   - files cannot become “trusted/usable” until scan passes
5. **Observable failure modes**
   - stable, explicit error codes that map to remediation paths

### Non-goals (for this post)
- object storage integration (MinIO comes in later posts)
- background retry daemon and DLQ (worker stack is a later post)
- UI/UX polish for upload progress
- full auth/tenancy model

These are consistent with project scope and roadmap. fileciteturn0file0turn0file3

---

## 3) Architectural placement (flows + failure domains)

This pipeline sits at the boundary between:

- **Command Flow:** “accept bytes safely”
- **Event/Processing Flow:** “scan/convert/optimize later”
- **Failure Domains:** file processing is allowed to degrade; **core write path must stay predictable**

The invariant: **Core services must never block on external dependencies** (scanner, converters, downstream). fileciteturn0file0turn0file2

---

## 4) High-level pipeline

### Stages
1. **Init (create upload session)**
2. **Upload chunks (resumable)**
3. **Finalize (deterministic assembly)**
4. **Scan (async / decoupled)**
5. **Quarantine resolution**
   - passed → file becomes usable
   - infected → blocked + retained per policy
   - scanner unavailable → pending_scan

### “User never waits” rule
Users never wait for scanning or conversion; they get a completion token and the system transitions the file through deterministic states. fileciteturn0file0turn0file2

---

## 5) Data model and state machine

### Core persisted metadata (minimum viable)
An upload session persists:

- `upload_id` (UUID)
- `filename` (validated)
- `total_bytes`
- `chunk_bytes` (frozen contract)
- `created_at`
- `status` (state machine)
- optional: `received_chunks_count`, `received_bytes`, `checksum` (future hardening)

### States (canonical)
- `initiated`
- `uploading`
- `finalizing`
- `pending_scan`
- `finalized` *(assembled but still untrusted until scan passes, depending on policy)*
- `clean` *(trusted/usable)*
- `infected`
- `failed`

> Practical note: implementations often collapse `finalized/clean` depending on whether “finalized” implies “clean”. In on-prem security contexts, treat them as separate. fileciteturn0file0

### State transitions (rules)
- `initiated → uploading` on first accepted chunk
- `uploading → finalizing` on finalize request
- `finalizing → pending_scan` if scanner unavailable / timeout
- `finalizing → infected` if scan result indicates malware
- `finalizing → clean` if scan passed (or `finalized → clean` if scan happens after assembly)
- any state → `failed` on unrecoverable storage corruption or invalid contract

---

## 6) Storage layout (local filesystem baseline)

Current implementation uses local filesystem (object storage planned later). fileciteturn0file2turn0file3

Recommended deterministic layout:

```
storage/app/uploads/<upload_id>/
  meta.json
  chunks/
    0.part
    1.part
    ...
  locks/
    finalize.lock
  assembled/
    file.tmp
  final/
    <safe_filename>
```

Key rules:
- chunks are written **only** under `chunks/`
- assembly writes to `assembled/file.tmp`
- commit is an atomic rename/move into `final/`
- `final/` is never written incrementally

---

## 7) API contracts (minimal)

### 7.1 Init upload
**Request**
- filename
- total_bytes
- chunk_bytes

**Response**
- upload_id
- frozen contract echo (total_bytes, chunk_bytes)

Contract invariants:
- `chunk_bytes` fixed for the session
- chunk indices are 0-based
- last chunk may be smaller; all others must equal `chunk_bytes`

### 7.2 Upload chunk
**Request**
- upload_id
- index
- bytes (body)

**Server rules**
- validate index range
- validate size (`index < last` => exactly `chunk_bytes`)
- write chunk atomically
- idempotent: re-upload same index overwrites safely

### 7.3 Finalize
**Request**
- upload_id

**Server rules (deterministic)**
1. acquire finalize lock
2. verify all required chunk files exist
3. verify assembled size equals `total_bytes`
4. attempt scan (best effort)
5. if scan blocked/unavailable → mark `pending_scan`
6. if scan says infected → mark `infected` and block
7. if scan ok (or deferred by policy) → atomically commit assembled file

---

## 8) Deterministic finalize algorithm (reference)

This is the core of Post 08.

### 8.1 Locking and idempotency
Finalize must be:
- **mutually exclusive** per upload_id
- **safe to retry**
- **safe under concurrent requests**

Lock strategy:
- filesystem lock file OR DB row lock  
- must survive multi-process concurrency  
- must fail fast with explicit error code if lock cannot be acquired

### 8.2 Required chunk set
Compute:
- `expected_chunks = ceil(total_bytes / chunk_bytes)`
- required indices: `0..expected_chunks-1`

Validation:
- every `i.part` must exist  
If any missing → fail with `finalize_missing_chunks`.

### 8.3 Assembly
- open `file.tmp` for write
- append chunks in order
- fsync (or equivalent durability step in your runtime)
- verify `filesize(file.tmp) == total_bytes`
If mismatch → `finalize_size_mismatch`

### 8.4 Scan decision
- if scanner is reachable and responds in time:
  - infected → `infected_file`
  - clean → proceed
- if scanner times out/unavailable/protocol error:
  - **do not fail finalize**
  - transition to `pending_scan` (and return a stable status for the client)

This is the “decoupling” principle: scanner failure becomes a recoverable processing failure, not an upload failure. fileciteturn0file2

### 8.5 Commit (atomic)
- atomically move `file.tmp` into `final/<filename>` (same filesystem)
- set state `finalized` or `clean` (depending on whether scan already passed)

---

## 9) Failure taxonomy (stable error codes)

This post explicitly avoids leaking internal exceptions.  
All failures map to stable codes.

Suggested mapping (example taxonomy used in implementation):
- `infected_file` → malware detected
- `finalize_size_mismatch` → assembled bytes != total_bytes
- `finalize_missing_chunks` → one or more `*.part` missing
- `finalize_fs_race` → lock/rename/write race condition
- `finalize_in_progress` → lock already held
- `scanner_unavailable` → scanner timeout/protocol error
- `invalid_filename` → rejected by filename policy
- `finalize_internal_error` → default catch-all

This taxonomy is part of the “production-grade” promise: clients can build correct UX and retries around it. fileciteturn0file2

---

## 10) What happens to infected files?

Policy must be explicit, not implied.

Recommended default:
- keep the assembled file in **quarantine** (not in “final usable” namespace)
- mark metadata as `infected`
- emit an audit event (for security monitoring)
- deny any download/preview endpoints
- optional: automatic deletion after retention window (later lifecycle policy)

Rationale:
- security teams often require artifacts for incident response
- automatic deletion can destroy evidence
- but indefinite retention is a storage risk, so lifecycle policies belong to later infra posts

This aligns with “all files untrusted until scanned”. fileciteturn0file0

---

## 11) Observability (minimum viable)

Even before full observability stack, the file pipeline must log:

- upload_id, filename, total_bytes, chunk_bytes
- chunk index and size accepted
- finalize start/end, duration
- state transitions
- scanner outcome (clean/infected/unavailable)
- explicit error code on failure

Metrics to add later:
- finalize latency histogram
- scanner latency and error rate
- percent of sessions entering pending_scan
- missing-chunks rate (client/network issues)

---

## 12) Test scenarios (acceptance-level)

Post 08 is not “done” until these scenarios pass.

### Happy path
- upload N chunks, finalize → clean/finalized

### Resume / retry
- upload first half, disconnect, resume, finalize succeeds

### Idempotency
- upload chunk 5 twice (same bytes) → finalize unchanged
- call finalize twice concurrently → one succeeds, other gets `finalize_in_progress`

### Missing chunks
- omit chunk k, finalize → `finalize_missing_chunks`

### Size mismatch
- last chunk truncated, finalize → `finalize_size_mismatch`

### Scanner unavailable
- simulate timeout → finalize returns `pending_scan` (not failure)

### Infected file
- scanner reports infected → state `infected`, file blocked

---

## 13) Known limitations (explicit, accepted)

Current known risks in this phase:
- finalize is still synchronous in the API service
- no background worker to resolve `pending_scan` yet
- storage is local filesystem (MinIO separation planned)
- limited dashboards/metrics

These are tracked in project position and roadmap. fileciteturn0file2turn0file3

---

## 14) What comes next (Post 09+)

- **Post 09:** Infrastructure baseline (DB/Redis/RabbitMQ/Storage) fileciteturn0file3
- **Post 10:** MinIO + lifecycle policies
- **Post 11+:** Outbox + workers + retries + DLQ so `pending_scan` becomes a fully automated, resilient flow

---

## Appendix A — Implementation checklist (copy/paste)

- [ ] Upload session persistence (meta.json or DB)
- [ ] Chunk validation (index + size)
- [ ] Atomic chunk writes
- [ ] Finalize lock (fs lock or DB lock)
- [ ] Missing chunk detection
- [ ] Deterministic assembly to temp file
- [ ] Size verification
- [ ] Scanner call with bounded timeout
- [ ] `pending_scan` state on scanner failure
- [ ] `infected` state + quarantine policy
- [ ] Atomic commit (rename/move)
- [ ] Stable error taxonomy mapping
- [ ] Acceptance tests for failure scenarios


---

## Implementation Status (v1.0 – Measured)

Implements Post 8 file pipeline hardening and adds measured metrics.

- Scanner hardened (clamd socket + streaming limits + supervisord ordering)
- Finalize degrades safely to `pending_scan` + quarantine when scanner is down
- Idempotent rescan worker publishes clean files later (`upload.published`)
- Deterministic metrics runner: `scripts/metrics/post8-metrics-run.sh`
- Metrics sample tracked: `services/api/docs/posts/post8/metrics-sample.md`
- Generated output ignored: `services/api/docs/posts/post8/metrics-output.md`

### Reproduce Metrics (Deterministic)
This script resets the local environment, runs controlled upload batches,
forces scanner degradation, and regenerates metrics deterministically.

```bash
RUNS_CLEAN=0 RUNS_PENDING=5 FILE_BYTES=1242880 CHUNK_BYTES=1048576 \
  bash scripts/metrics/post8-metrics-run.sh
