# Post 10 - Validation Notes

This document records the validation scope for Post 10.

The purpose here is to verify storage-separation behavior in a constrained but real local runtime, not to claim production-grade distributed guarantees.

---

## 1) Validation scope

Post 10 validation focuses on these questions:

1. Does finalize still complete when storage is routed through object-storage-backed disks?
2. Do clean uploads land in the `final` storage domain?
3. Do degraded scanner outcomes land in the `quarantine` storage domain?
4. Does rescan promotion behave like a guarded publish path?
5. Does the upload pipeline remain runnable under concurrent traffic after storage separation?

---

## 2) Scenario: clean upload

### Setup
- stack is running
- MinIO is reachable
- scanner service is available

### Expected behavior
- upload init succeeds
- chunk upload succeeds
- finalize succeeds
- finalize response returns:
  - `finalized: true`
  - `status: "clean"`
- durable object is stored in:
  - `s3://final/uploads/<upload_id>/<filename>`

### Purpose
Verifies that clean finalize outcomes are routed into the final durable storage domain.

---

## 3) Scenario: degraded scanner path

### Setup
- stack is running
- MinIO is reachable
- scanner service is intentionally unavailable or stopped

### Expected behavior
- upload init succeeds
- chunk upload succeeds
- finalize still completes
- finalize response returns:
  - `finalized: true`
  - `status: "pending_scan"`
- durable object is stored under quarantine storage, not final storage

### Purpose
Verifies that scanner availability does not block deterministic finalize completion and that deferred files remain quarantined.

---

## 4) Scenario: rescan promotion

### Setup
- at least one upload exists in quarantine
- scanner service is available again

### Command
```bash
docker compose exec -T php php artisan upload:rescan-pending
```

### Expected behavior
- quarantined object is re-read
- scanner is invoked
- clean object is promoted into final storage
- replay/publish re-entry is guarded by marker and event checks

### Purpose
Verifies that deferred objects can later be promoted through a controlled publish path.

---

## 5) Scenario: duplicate finalize expectations

### Expected behavior
- finalize remains one-shot for a given upload
- duplicate finalize attempts are rejected
- storage abstraction does not weaken finalize guarantees

### Purpose
Verifies that the storage refactor does not break earlier finalize invariants.

---

## 6) Scenario: k6 pipeline smoke-load

### Scope
A k6 script exercises:
- upload init
- chunk upload
- finalize

under concurrent traffic.

### Metrics observed
- `upload_init_ms`
- `upload_chunk_ms`
- `upload_finalize_ms`
- `upload_total_ms`
- `upload_errors`

### What this validates
- pipeline remains runnable after storage separation
- latency and error trends remain observable
- integration works against real services

### What this does NOT validate
- full storage correctness
- quarantine policy correctness in all cases
- distributed durability
- production-scale behavior

### Purpose
Provides a runtime signal, not a standalone correctness proof.

---

## 7) Interpretation boundary

This validation document is intentionally conservative.

### Supported claims
- real integration
- storage responsibility separation
- preserved pipeline behavior under bounded local runtime conditions

### Not supported claims
- HA readiness
- multi-node durability
- production deployment guarantees

---

## 8) Summary

Post 10 validation confirms that the system behaves correctly after storage separation under real constraints.

It does not attempt to represent a single workstation as a production-grade distributed system.
