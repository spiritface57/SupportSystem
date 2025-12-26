# Scan Contract (Post 8 v0.4)

This document defines the contract between the upload pipeline
and the malware scanning subsystem.

The contract is behavioral, not implementation-specific.

---

## Inputs

- Binary file stream
- Upload identifier
- Immutable metadata

---

## Outputs

The scanner may return one of the following results:

- clean
- infected
- unavailable

The scanner must not:
- Block upload finalization
- Mutate upload state directly
- Retry internally without bounds

---

## Versioned Behavior

v0.3 behavior:
- Scanner failure caused upload finalization to fail

v0.4 behavior:
- Upload is finalized regardless of scanner availability
- Scanner failure results in a degraded scan state

---

## Degraded Behavior

When the scanner is unavailable:

- Upload finalization proceeds
- Scan result is recorded as pending
- State transitions to PENDING_SCAN

This behavior is mandatory in v0.4.

---

## Invariants

- Scanner availability must not affect ingestion
- Scanner failure must be explicit
- Scanner failure must be observable
- Scanner failure must not cascade
