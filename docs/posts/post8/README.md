# Post 8 – File Upload Finalization and Scan Decoupling (v0.4)

Post 8 documents the evolution of the file upload pipeline under
real-world on-prem constraints.

The focus of v0.4 is a single guarantee:

Upload finalization must not depend on scanner availability.

This post does not introduce new features.
It introduces explicit behavior under failure.

---

## Scope

This document describes system-level behavior only.

It defines:
- Behavioral guarantees
- Explicit failure modes
- Intentional omissions

It does not describe:
- Internal service implementations
- UI behavior
- Optimization strategies

---

## Core Change in v0.4

Prior to v0.4, scanner availability implicitly gated upload finalization.

In v0.4:
- Uploads are finalized independently
- Scan execution is best-effort
- Scanner failure produces a degraded state, not a failed upload

This change introduces a first-class state.

---

## Degraded Scan State

v0.4 introduces an explicit non-failure state:

PENDING_SCAN

This state represents:
- A successfully finalized upload
- A scan that could not be completed due to scanner unavailability

Files in this state:
- Are quarantined
- Must not be exposed to users
- Must be eligible for later re-scan

PENDING_SCAN is a state, not a failure.

---

## Guarantees

Post 8 v0.4 guarantees the following:

- Upload finalization is deterministic
- Scanner outages do not block ingestion
- All failure modes are explicit and machine-readable
- No upload is silently dropped
- No finalize attempt produces ambiguous outcomes

---

## What Is Explicitly Not Guaranteed

The following are intentionally not guaranteed in v0.4:

- Exactly-once scan execution
- Immediate scan completion
- Automatic scan retry
- Persistent pre-scan queues

These omissions are intentional and derived from
on-prem operational constraints.

---

## Related Documents

- scan-contract.md
- failure-reasons.md
- testing-v0.4.md

## Implementation PR
- Post 8 hardening (chunk contracts, finalize determinism, pending scan worker): https://github.com/spiritface57/SupportSystem/pull/1
