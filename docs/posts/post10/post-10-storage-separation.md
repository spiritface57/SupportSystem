# Post 10 - Storage Separation (MinIO)

**Topic:** Storage responsibility separation, object storage domains, versioning, lifecycle
**Status:** Technical documentation (repository)
**Series:** On-Prem Support Platform (LinkedIn Posts)

> This post separates transient upload handling from durable object retention. Chunk uploads and temporary assembly remain local, while finalized and quarantined artifacts move into S3-compatible object storage.

---

## 1) Why Post 10 exists

Post 8 established a deterministic finalize flow under scanner degradation.
Post 9 introduced a runnable infrastructure baseline with real dependencies.

At that point, the pipeline was functional, but storage responsibilities were still not explicit enough.

Not every file in the upload pipeline has the same role:

* chunks are transient
* temporary assembly is transient
* finalized artifacts are durable
* quarantined artifacts are durable, but policy-distinct

Treating all of these as local folders hides an architectural distinction that should be explicit.

Post 10 exists to separate those responsibilities and make storage behavior part of the system contract.

---

## 2) What changes in Post 10

Post 10 introduces an S3-compatible object storage layer for durable artifacts while keeping transient upload assembly local.

### Changes introduced

1. Finalized uploads are written to a dedicated `final` object-storage bucket.
2. Quarantined uploads are written to a dedicated `quarantine` object-storage bucket.
3. Both buckets have versioning enabled.
4. Both buckets receive lifecycle rules for noncurrent object versions.
5. Finalize and rescan logic now target storage disks instead of hardcoded local final/quarantine paths.
6. Chunk upload and temporary assembly remain on local disk.

This is not a full storage-platform implementation.
It is a storage responsibility split inside a runnable single-node baseline.

---

## 3) Goals and non-goals

### Goals

1. Separate transient file handling from durable artifact retention.
2. Split durable artifacts into two explicit storage domains:

   * `final`
   * `quarantine`
3. Add versioning support to both durable storage domains.
4. Add lifecycle control for noncurrent object versions.
5. Preserve the correctness expectations established earlier in the upload pipeline.

### Non-goals

* High availability
* Multi-node replication
* Cross-node durability guarantees
* Production security hardening
* TLS, IAM, backup strategy, or disaster recovery procedures
* Queue-driven scanning or outbox delivery

---

## 4) Storage model

### Local disk (transient)

These remain on local storage:

* `storage/app/uploads/<uploadId>/` for incoming chunk files
* `storage/app/uploads-meta/<uploadId>/meta.json` for upload contract metadata
* `storage/app/tmp/<uploadId>/assembled.bin` for temporary file assembly

### Object storage (durable)

These move to MinIO-backed object storage:

* `final` bucket → clean finalized uploads
* `quarantine` bucket → deferred or infected artifacts that must not be promoted

### Object paths

* `final/uploads/<uploadId>/<filename>`
* `quarantine/uploads/<uploadId>/<filename>`

This model keeps temporary processing close to the application while moving durable artifacts into policy-aware storage.
Durability here refers to logical separation and policy control, not physical multi-node redundancy in this single-node baseline.
---

## 5) Why local assembly still stays local

Post 10 does **not** move chunk upload or temporary assembly into object storage.

That is intentional.

Chunk upload and assembly are short-lived, write-heavy, and tightly coupled to request-side processing.
Their purpose is temporary construction, not durable retention.

Moving them into object storage at this stage would increase complexity without improving the architectural boundary this post is trying to establish.

The separation introduced here is:

* local for transient work
* object storage for durable outcomes

---

## 6) Durable storage domains: final vs quarantine

A major part of this post is not simply “using object storage,” but separating durable outcomes by policy.

### `final`

The `final` bucket stores clean, publishable artifacts.

### `quarantine`

The `quarantine` bucket stores artifacts that are not safe to publish yet, including:

* uploads finalized while scanner was unavailable
* uploads that remain quarantined by design
* files awaiting later rescan/promotion

This turns quarantine from a folder convention into an explicit storage domain.

That matters because retention, promotion, cleanup, and audit behavior can now be reasoned about independently.

---

## 7) MinIO service (local baseline)

The local compose stack adds:

* `minio` for S3-compatible object storage
* `minio-init` for bucket bootstrap
* bucket creation for:

  * `final`
  * `quarantine`
* bucket versioning enablement
* lifecycle rules for noncurrent object versions

Default local credentials:

* user: `support`
* pass: `support123`

Local console:

* `http://localhost:9001`

This setup is intended for a constrained local baseline, not a hardened production deployment.

---

## 8) Versioning and lifecycle

Both durable buckets have versioning enabled.

A noncurrent object expiration policy is also applied.

### Why this matters

This is not presented as a full compliance or retention framework.
Its purpose in Post 10 is narrower:

* make retention behavior explicit
* avoid treating overwrite history as invisible
* prevent unbounded growth of noncurrent object versions
* move durability policy into infrastructure, not ad hoc filesystem cleanup

Current local default:

* noncurrent versions expire after 30 days

This value is a baseline policy, not a production recommendation.

---

## 9) Storage-aware finalize and rescan flows

Before Post 10, finalize and rescan behavior were more tightly coupled to local final/quarantine paths.

Post 10 introduces storage-aware routing through configured disks and prefixes.

### Finalize behavior

* chunks are assembled locally
* scanner is called
* clean files are written to the `final` storage domain
* degraded or quarantined files are written to the `quarantine` storage domain

### Rescan behavior

* files are read from `quarantine`
* scanner is called again
* clean files are promoted into `final`
* promotion is guarded by per-upload exclusion and published-state checks

This keeps storage backend details out of higher-level flow decisions as much as possible.

---

## 10) Preserved invariants from earlier pipeline work

Post 10 does not replace the correctness expectations established in Post 8.
It extends them across a different durable storage backend.

The important invariants remain:

1. Upload finalization must not depend on scanner availability.
2. A scanner outage must still allow deterministic finalize completion.
3. Deferred uploads must land in quarantine, not be silently published.
4. Duplicate finalize attempts must still be rejected.
5. Rescan promotion must remain replay-resistant and idempotent in intent.

This post changes where durable artifacts live.
It does not change the correctness bar the pipeline is expected to meet.

---

## 11) Backend semantics and limits of the current implementation

This post separates storage responsibilities, but backend semantics are not identical across all drivers.

### Local-backed writes

For local storage, temporary write + move semantics can be used to approximate an atomic publish path.

### S3-backed writes

For object storage, publish behavior follows object-write semantics, not POSIX rename semantics.

### Practical implication

The current contract is:

* deterministic durable write target
* explicit final vs quarantine separation
* guarded rescan promotion
* idempotent-oriented behavior

The current contract is **not**:

* universal atomic rename semantics across all backends
* distributed transactional publish guarantees

This distinction is important and intentional.

---

## 12) Local baseline bootstrap

### Start the stack

```bash
docker compose up -d --build
```

### Ensure bucket bootstrap has run

```bash
docker compose run --rm minio-init
```

### Verify MinIO readiness

```bash
curl http://localhost:9000/minio/health/ready
```

--- 


## 13) Validation summary

The goal of validation in Post 10 is not to prove production-grade guarantees, but to confirm that storage separation behaves correctly under real runtime conditions.

These scenarios focus on observable behavior across clean, degraded, and recovery paths.

Validation for this post should focus on behavior, not exaggerated production claims.

Relevant scenarios include:

1. Clean upload path

   * scanner available
   * finalize returns `clean`
   * object lands in `final`

2. Degraded scanner path

   * scanner unavailable
   * finalize returns `pending_scan`
   * object lands in `quarantine`

3. Rescan promotion path

   * quarantined object is rescanned
   * clean object is promoted to `final`
   * publish re-entry is guarded

4. Pipeline smoke-load

   * init/chunk/finalize exercised under concurrent traffic using k6
   * used to observe runtime behavior and latency trends
   * not treated as proof of full storage correctness by itself

Detailed scenario notes can live in `validation.md`.

---

## 14) What this post does not claim

Post 10 is intentionally limited.

It does **not** claim:

* production readiness
* HA storage architecture
* real multi-node object durability
* cross-host failure tolerance
* secure production deployment practices
* data-center-grade performance characteristics

This is a real, runnable, single-node baseline on a workstation.
Its purpose is to validate architecture behavior under honest constraints.

---

## 15) Single-disk limitation

With a single physical disk, replication and redundancy claims would be misleading.

Even if multiple services run on the same workstation, they still share the same underlying hardware failure domain.

So Post 10 documents storage separation and policy boundaries, but does not present them as physical resilience.

---

## 16) Diagram

Diagram source:
`docs/posts/post10/diagrams/01-storage-separation.mmd`

---

## 17) What comes next

Post 10 makes durable storage domains explicit.

The next architectural steps naturally point toward:

* event bus integration
* outbox pattern
* worker-driven retries
* DLQ and replay handling

Those belong to later milestones.

---

## 18) Summary

Post 10 is not about “adding MinIO.”

It is about turning storage from a passive filesystem concern into an explicit part of the system contract.
