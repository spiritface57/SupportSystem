# ADR-001 — On-Prem Constraints and Non-Negotiables

- **Status:** Accepted
- **Date:** 2026-01-15
- **Revision:** 1
- **Owner:** Mahmoudreza Abbasi
- **Scope:** On-Prem Support Platform
- **Related Posts:** Post 7 — Why Our On-Prem Ticketing System Starts With Limits, Not Features

## Context

This system targets enterprise on-prem deployments where:
- WAN links are variable and often slow (remote branches).
- All ingress passes through UTM inspection.
- Managed cloud services are not available.
- Threat model assumes all uploaded binaries are untrusted.
- Small IT teams operate the stack; operational complexity must be bounded.

We must design for physics: storage behavior, network variability, and failure modes.

## Constraints Snapshot (Targets)

- **Capacity:** ~3M identities, 100k+ DAU, ~5k tickets/day, ~40% include large scanned documents.
- **Storage ingest:** raw ~15–160 GB/day depending on scan source; optimized artifacts typically ~40–60% smaller.
- **Network:** remote branches ~20–100ms latency, ~1–40 Mbps; disconnects are expected.
- **Security:** every file is untrusted until quarantined + scanned.
- **Availability:** target 99.98% uptime; tolerate ~10 minutes DB outage without UX collapse.

## Decision

1) **Storage**
- Use **S3-compatible on-prem object storage (MinIO)** for file binaries.
- **No binaries in the relational database**.
- Apply lifecycle/versioning policies for retention and recovery.

2) **Network Segmentation**
- Separate **application network** from **file-storage network**.
- Bulk file transfer must not compete with API/control-plane paths.
- All external ingress goes through a single gateway/DMZ boundary.

3) **Uploads**
- Use **chunked, resumable uploads** with transactional completion/finalization.
- Upload correctness is defined by a fixed contract (chunk sizing/total bytes) rather than best-effort streaming.

4) **Availability**
- Target uptime: **99.98%**.
- DB outage tolerance: **~10 minutes** without UX collapse.
- During outages: buffer/retry with idempotency; UI degrades to “processing” rather than failing.

5) **Security**
- **All files are untrusted until scanned**.
- Quarantine is a hard containment boundary: non-clean files are not published/served.

6) **Scope Discipline**
- Single-tenant architecture.
- Decouple identity, ticketing, realtime, file pipeline.
- External systems only via Integration Layer with async + circuit breakers/outbox patterns.

## Options Considered

### Option A — Store binaries in DB
- Pros: simpler backup story, fewer moving parts
- Cons: DB bloat, performance collapse, replication pain, poor lifecycle control
- **Rejected**.

### Option B — Single flat network for API + file traffic
- Pros: simpler ops
- Cons: file uploads can kill API latency; blast radius too large
- **Rejected**.

## Consequences

- Requires operating object storage (MinIO) and thinking in object lifecycle policies.
- Adds more explicit pipeline contracts (idempotency, finalize tokens).
- Enables predictable control-plane performance and smaller failure blast radius.

## Revision History

- **Rev 1 (2026-01-15):** Initial accepted version.
