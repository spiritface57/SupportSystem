# Changelog

All notable changes to this system are documented here.

This project evolves through explicit system guarantees, not just feature additions.

---

## [post10-v0.2.1]

### Fixed
- Rescan published marker now written to quarantine storage (consistent domain enforcement)
- Transient chunk data cleaned after finalize commit

### Notes
- Ensures storage domains remain the single source of truth for publish state
- Prevents stale filesystem artifacts from affecting rescan behavior

---

## [post10-v0.2.0]

### Added
- Validation scenarios for storage-aware pipeline behavior
- Interpretation boundaries for failure vs domain outcomes
- k6 load testing documentation for storage-separated pipeline
- Architecture decision record for storage separation

### Changed
- Finalized storage separation documentation
- Clarified contract between finalize decision and storage domains

### Notes
- Focus shifts from infrastructure correctness → behavioral correctness under failure
- Storage domains are now part of the system contract

---

## [post10-v0.1.0]

### Added
- MinIO object storage integration
- Dedicated storage domains:
  - `final` (publishable)
  - `quarantine` (unsafe / deferred)
- Bucket bootstrap (creation, versioning, lifecycle policy)
- Storage abstraction layer for finalize and rescan flows
- Routing based on domain policy instead of local filesystem

### Changed
- Finalize and rescan flows no longer depend on local disk semantics
- Data visibility now defined by storage domain, not metadata flags

### Notes
- Introduces **storage as an enforcement layer**
- Removes implicit trust in application state
- Enables deterministic behavior under scanner failure

---

## [post9-v0.1.0]

### Added
- Full infrastructure baseline using Docker Compose:
  - MySQL 8.0
  - Redis 7
  - RabbitMQ 3.12
  - Nginx + PHP-FPM
  - Scanner service
- Health checks across core services
- k6 load testing for runtime validation

### Notes
- First transition from logical correctness → real runtime conditions
- Validates system behavior under concurrent traffic
- No guarantees beyond single-node runtime realism

---

## [post8-v0.4.1]

### Changed
- Infected files treated as terminal finalize state
- Event contract aligned with deterministic finalize guarantees
- Finalize logic hardened for consistency under failure

---

## [v0.4]

### Added
- `upload_events` table for full observability
- Deterministic finalize locking to prevent race conditions
- Structured event emission:
  - upload lifecycle
  - scan lifecycle
- SQL-based metric derivation

### Notes
- System becomes **auditable**
- Metrics derived from events, not assumptions

---

## [v0.3]

### Added
- External scanner service (Node.js + ClamAV)
- Streaming scan via INSTREAM protocol
- Backpressure control via concurrency limits
- Timeout enforcement for scan operations

### Failure Behavior
- Scanner unavailable → finalize fails (`scanner_unavailable`)
- Timeout → finalize fails (`scan_timeout`)
- Backpressure → immediate rejection (`scanner_busy`)

### Notes
- Introduces failure containment boundary
- Rejects unsafe conditions instead of masking them

---

## [v0.2]

### Added
- Finalize endpoint
- Chunk assembly into single file
- File size validation against declared metadata
- Cleanup of temporary chunk files

### Notes
- Ensures correctness of file assembly
- No safety guarantees yet

---

## [v0.1]

### Added
- Upload session initialization
- Chunked file ingestion
- Temporary storage handling

### Notes
- Baseline request lifecycle only
- No guarantees for integrity, safety, or reliability
