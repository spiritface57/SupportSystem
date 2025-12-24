# Post 8 – File Upload, Chunking, and Streaming Scan

This document describes **Post 8** of the architecture and implementation series.

Post 8 focuses on building a production-grade, on-prem file upload pipeline
under strict resource constraints, with explicit guarantees and failure isolation.

---

## Scope of Post 8

Post 8 covers the following concerns:

- Chunked file upload lifecycle
- Upload finalization and integrity guarantees
- Streaming malware scanning
- Isolation of scanning failures from ingestion
- Explicit handling of partial and degraded states

It intentionally avoids:
- Queue-based processing
- Distributed job orchestration
- Advanced observability and metrics

Those concerns are deferred to later posts.

---

## Constraints Addressed

Post 8 is implemented under the following constraints:

- Fully on-prem deployment
- No shared filesystem between services
- Bounded memory usage for large files
- Scanner instability must not cascade
- API latency must remain predictable

All design decisions in this post are derived from these constraints.

---

## Architecture Overview

At a high level, Post 8 introduces a three-phase upload flow:

1. **Initialization**
   - Client declares upload intent
   - Metadata and constraints are validated
   - A unique upload identifier is issued

2. **Chunk Ingestion**
   - File data is uploaded in ordered chunks
   - Each chunk is validated independently
   - No full file buffering occurs in memory

3. **Finalization and Scan**
   - Chunk completeness and size integrity are verified
   - A streaming malware scan is triggered
   - A definitive outcome is returned

Each phase is designed to fail explicitly and independently.

---

## Streaming Scan Model

Malware scanning is performed using a dedicated scanner service.

Key properties:
- Data is streamed incrementally
- No full file is loaded into memory
- Scan execution is time-bounded
- Scanner failures are isolated

Possible scan outcomes:
- clean
- infected
- timeout
- scanner_unavailable
- internal_error

No ambiguous or partial results are exposed.

---

## Failure Scenarios

Post 8 explicitly defines behavior for failure cases:

- Missing or out-of-order chunks
- Size mismatch on finalization
- Scanner timeouts
- Scanner unavailability
- Partial upload retries

In all cases:
- State corruption is prevented
- Failures do not cascade
- Cleanup is deterministic

Silent recovery is intentionally avoided.

---

## Version Mapping

Post 8 is implemented incrementally and mapped to git tags:

- **v0.1**
  - Upload initialization
  - Chunk reception
  - No scanning or final guarantees

- **v0.2**
  - Upload finalization
  - Size and integrity checks
  - Collision handling

- **v0.3**
  - Streaming malware scanning
  - Scanner isolation and timeouts
  - No shared disk between services

- **v1.0**
  - Consolidated guarantees
  - Failure scenarios documented
  - Architecture stabilized

Refer to the repository `CHANGELOG.md` for detailed changes.

---

## What Post 8 Does Not Guarantee

Post 8 explicitly does **not** guarantee:

- Exactly-once processing
- Asynchronous job durability
- Cross-service transactional semantics
- Automatic retries or recovery

These trade-of
