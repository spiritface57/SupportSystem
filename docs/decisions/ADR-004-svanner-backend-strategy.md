# ADR-004 — Scanner Backend Strategy (Pluggable Engines, Stable Verdict Contract)

- **Status:** Draft (target: Accepted before Post 09)
- **Date:** 2025-12
- **Owner:** Mahmoudreza Abbasi
- **Scope:** Malware scanning backend abstraction and future queue transport
- **Related Posts:** Post 8 → Post 9 (planned)

## Context

Scanner availability is not guaranteed in on-prem environments:
- engine may be down, overloaded, or misconfigured,
- signature updates may fail,
- protocol/timeouts happen.

We have a hard security rule:
- **No untrusted file is published unless scanned clean.**

We also have a hard reliability rule:
- **Upload finalization must be deterministic and must not depend on scanner availability.**

We want future evolution:
- queue-based scan jobs (outbox/queue),
- ability to swap scan engines **without changing upload/finalize semantics**.

## Decision

1) Define a **ScannerBackend interface**:
- `submit(scan_request) -> receipt` (optional)
- `scan(file_ref) -> ScanVerdict` (sync implementation allowed initially)
- `health() -> status` (optional but recommended)

2) Define a stable **ScanVerdict schema v1** (versioned):
- `schema_version: 1`
- `verdict: clean | infected | unknown | error`
- `engine: { name, version }`
- `evidence: { signatures?, reason?, meta? }` (optional)
- `scanned_at`, `latency_ms`

3) Define delivery semantics (even before queues exist):
- Scan jobs are **at-least-once**.
- Consumers must be **idempotent**.
- Dedupe key suggestion: `(upload_id, content_hash, schema_version)`.

4) Keep Post 08 decision intact:
- **No scanner replacement in Post 08.**
- Current scanner implementation becomes just one `ScannerBackend` adapter.

## Options Considered

### Option A — Hardcode one scanner engine into pipeline
- Pros: fastest implementation
- Cons: engine swap becomes pipeline rewrite; brittle ops
- **Rejected**.

### Option B — Abstract backend + versioned verdict schema (this ADR)
- Pros: engine swap becomes adapter change; pipeline stays stable
- Cons: requires contract discipline and schema ownership
- **Accepted**.

### Option C — Full queue/outbox implementation now
- Pros: production-style async processing early
- Cons: shifts Post 09 into infra; breaks narrative and scope
- **Deferred** (planned next step).

## Consequences

- Establishes a long-lived contract that protects your pipeline from engine churn.
- Enables queue-based transport later without changing finalize logic.
- Requires strict versioning and careful observability (metrics + error codes).
