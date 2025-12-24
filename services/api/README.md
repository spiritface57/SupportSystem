# API Service – File Upload Coordination

This service is the primary ingestion and coordination layer of the system.
It is responsible for receiving client uploads, enforcing constraints,
and orchestrating downstream processing without violating failure boundaries.

This service is implemented using Laravel.

---

## Responsibilities

The API service is responsible for:

- Upload session initialization
- Chunked file ingestion
- Ordering and tracking of upload chunks
- Finalization and integrity validation
- Coordination with downstream scanning and worker services

The API service does **not** perform malware scanning or heavy background work directly.

---

## Design Principles

This service is designed with the following principles:

- Stateless request handling
- Explicit lifecycle transitions
- Bounded memory usage
- No shared filesystem dependencies
- Failure isolation from downstream services

All operations are designed to fail fast and explicitly.

---

## Upload Lifecycle

The upload flow is intentionally split into distinct phases:

1. **Initialization**
   - Client declares intent to upload
   - File metadata and constraints are validated
   - A unique upload identifier is issued

2. **Chunk Ingestion**
   - File data is sent in ordered chunks
   - Each chunk is validated independently
   - Chunks are written to local temporary storage

3. **Finalization**
   - Chunk completeness is verified
   - File size and integrity checks are enforced
   - Downstream processing is triggered

Each phase is idempotent where possible.

---

## Guarantees

At the API boundary, the service guarantees:

- No full file buffering in memory
- Deterministic behavior on retries
- Explicit error signaling on invalid state
- No cascade failures from scanner or worker unavailability

If downstream services are unavailable, uploads fail explicitly without corrupting state.

---

## What This Service Does Not Do

This service intentionally avoids:

- Malware scanning
- Long-running background processing
- Asynchronous job execution
- Cross-service state sharing

These concerns are delegated to isolated services.

---
