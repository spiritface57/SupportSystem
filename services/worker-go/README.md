# Worker Service – Asynchronous Background Processing

This service is responsible for executing asynchronous and long-running tasks
that must not block the API or scanning services.

It operates as an isolated background worker with strict resource boundaries.

This service is implemented in Go.

---

## Responsibilities

The worker service is responsible for:

- Executing background and asynchronous tasks
- Processing work that exceeds API time budgets
- Handling retryable and delayed operations
- Enforcing execution time and resource limits

The worker service does **not**:
- Accept direct client requests
- Participate in upload coordination
- Perform malware scanning
- Share runtime state with other services

---

## Design Rationale

### Why a Separate Worker Service

Certain operations are:
- Long-running
- Retry-oriented
- Not suitable for request-response lifecycles

Isolating these concerns into a worker service ensures:
- API latency remains predictable
- Failures do not impact ingestion
- Background work can be scaled independently

---

### Why Go

Go is used because:
- It provides predictable memory usage
- Concurrency is explicit and controlled
- Binary distribution simplifies deployment

The choice prioritizes operational stability over rapid iteration.

---

## Execution Model

- Workers consume tasks from defined inputs
- Each task is processed in isolation
- Execution time is explicitly bounded
- Failures are surfaced as explicit outcomes

No task is allowed to block the worker indefinitely.

---

## Failure Handling

Failures are treated as expected outcomes.

The worker service:
- Explicitly reports task failures
- Supports controlled retries when applicable
- Avoids implicit or infinite retry loops

Failures do **not** propagate to the API or scanner services.

---

## Resource Assumptions

This service operates under the following assumptions:

- Memory usage is predictable and bounded
- CPU-intensive tasks are isolated
- Tasks may be retried or discarded safely
- Worker availability is not guaranteed

All assumptions are explicit and enforced.

---

## What This Service Does Not Do

This service intentionally avoids:

- Coordinating upload lifecycle
- Managing shared state
- Performing synchronous request handling
- Making architectural decisions for other services

It exists solely to execute background work.

---

## Operational Notes

- Workers are designed to be stateless
- Scaling is horizontal
- Shutdowns must be graceful and bounded

Operational scripts and orchestration live outside this service.

---

## Version Alignment

Worker behavior is aligned with repository-level git tags.

Any change that affects:
- Task semantics
- Retry behavior
- Execution guarantees

must introduce a new version and be documented explicitly.
