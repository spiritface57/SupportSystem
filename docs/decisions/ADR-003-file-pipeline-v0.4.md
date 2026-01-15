# ADR-003 — File Upload & Processing Pipeline (v0.4)

- **Status:** Accepted
- **Date:** 2026-01-15
- **Revision:** 1
- **Decision Version:** v0.4
- **Owner:** Mahmoudreza Abbasi
- **Scope:** File ingest, quarantine, scanning, deterministic finalization
- **Related Posts:** Post 8 — File Upload & Processing Pipeline (v1.0 doc)

## Context

On-prem file ingest must survive:
- WAN disconnects and partial uploads,
- UTM inspection delays,
- scanner availability issues,
- concurrent finalize attempts and race conditions.

Security constraint: all files are untrusted until scanned.
Reliability constraint: finalize must be deterministic and auditable.

## Notes on Versioning

This ADR captures the **implementation decision set (v0.4)** for the file pipeline.
The referenced Post 8 document is **v1.0 documentation maturity**, not a statement that the implementation has changed versions.

## Decision

- Use **chunked resumable uploads** with a fixed contract:
  - `chunk_bytes`, `total_bytes` locked at init time.
- Persist chunks to object storage; never to DB.
- Implement **deterministic finalization**:
  - finalize validates size + missing chunks,
  - finalize uses per-upload locking to avoid double-finalize races,
  - finalize emits a stable terminal outcome.

- Scanner is treated as an external dependency:
  - if scanner succeeds and file is clean → publish safe artifact
  - if scanner detects infection → quarantine + block
  - if scanner is unavailable/timeouts → **degrade to pending_scan quarantine** (no publish)

- Maintain a stable failure taxonomy for observability and automation:
  - `finalize_size_mismatch`, `finalize_missing_chunks`,
  - `finalize_in_progress`, `finalize_fs_race`,
  - `scanner_unavailable`, `infected_file`, `invalid_filename`, etc.

## Options Considered

### Option A — Block finalize until scan completes
- Pros: simpler states
- Cons: scanner outage breaks user uploads; violates availability goals
- **Rejected**.

### Option B — Finalize publishes immediately, scan later
- Pros: fast UX
- Cons: violates threat prevention; unsafe exposure window
- **Rejected**.

## Consequences

- Requires quarantine semantics and clear UX (“processing / pending scan”).
- Pipeline becomes testable via deterministic scripts and measurable metrics.
- Sets up clean separation for future queue-based scan jobs without rewriting finalize.

## Revision History

- **Rev 1 (2026-01-15):** Initial accepted version.
