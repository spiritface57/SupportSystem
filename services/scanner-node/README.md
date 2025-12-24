# Scanner Service – Streaming Malware Detection

This service is responsible for malware scanning of uploaded files using a
streaming, time-bounded approach.

It is intentionally isolated from the API service to prevent scanning failures
from impacting file ingestion or request handling.

This service is implemented using Node.js and integrates with ClamAV.

---

## Responsibilities

The scanner service is responsible for:

- Receiving file data from upstream services
- Performing streaming malware scans
- Enforcing scan timeouts and resource limits
- Returning explicit scan results

The scanner service does **not**:
- Store files permanently
- Coordinate upload state
- Retry failed scans implicitly

---

## Design Rationale

### Why a Separate Service

Malware scanning is:
- CPU intensive
- IO bound
- Potentially unstable

Isolating scanning into a dedicated service ensures:
- API responsiveness is preserved
- Scanner failures do not cascade
- Resource limits are enforced independently

---

### Why Node.js

Node.js is used because:
- It supports efficient stream handling
- Backpressure is explicit and controllable
- Integration with Unix sockets is straightforward

The choice is pragmatic, not ideological.

---

### Why Streaming

This service never loads a full file into memory.

Scanning is performed:
- Incrementally
- Chunk by chunk
- With bounded buffers

This allows the system to:
- Handle large files safely
- Operate under constrained memory
- Fail early on malicious content

---

## Scan Flow

1. Scanner receives a stream from the API
2. Data is forwarded incrementally to ClamAV
3. Scan execution is time-bounded
4. A definitive result is returned:
   - clean
   - infected
   - failed

No partial or ambiguous states are exposed upstream.

---

## Failure Handling

Failures are treated as explicit outcomes.

The scanner service may return:
- `clean`
- `infected`
- `timeout`
- `scanner_unavailable`
- `internal_error`

Failures do **not**:
- Trigger retries
- Block upstream services
- Leave partial state behind

Upstream services are responsible for handling scan failures.

---

## Resource Assumptions

This service operates under the following assumptions:

- Memory usage is bounded and predictable
- Scan duration is capped
- No shared filesystem access is required
- Scanner availability is not guaranteed

All assumptions are explicit and enforced.

---

## What This Service Does Not Do

This service intentionally avoids:

- Managing upload lifecycle
- Persisting scan results
- Performing retries or recovery
- Coordinating with worker services

It provides a single, well-defined capability.

---

## Operational Notes

- ClamAV is accessed via a local socket
- Timeouts are enforced at the service boundary
- Scanner health is assumed to be transient

Operational scripts and configuration live outside this service.

---

## Version Alignment

Scanner behavior is aligned with repository-level git tags.

Any change that affects:
- Scan guarantees
- Timeout behavior
- Failure semantics

must introduce a new version and be documented explicitly.