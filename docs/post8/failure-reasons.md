# Post 8 v0.4 Failure Reasons

This document defines the only allowed machine-readable failure reasons
emitted by the upload pipeline.

Failure reasons are strict and enumerable.
Free-form values are not allowed.

---

## Scanner Failures

scanner_unavailable
scanner_busy
scan_timeout
scan_protocol_error

---

## Finalize Failures

finalize_in_progress
finalize_locked
finalize_missing_chunks
finalize_size_mismatch
finalize_internal_error 

---

## Rules

- Failure reason must be one of the values listed above
- Failure reason must be machine-readable
- Human-readable context must be stored separately

---

## Non-Failure States

The following values are states, not failures:

pending_scan
clean
infected

States must not be emitted as failure reasons.

## Notes on Version Alignment

Some failure reasons present in earlier implementations
(e.g. integrity_mismatch, orphan_upload, internal_error)
are considered legacy and must be mapped to the v0.4 reasons
listed above before emitting upload.failed events.

The schema is the enforcement mechanism, not the source of truth.