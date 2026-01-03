# v0.4 Testing

This document defines reproducible test scenarios for Post 8 v0.4.

If test expectations and implementation diverge,
the implementation is wrong.

---

## Core Invariants

Upload finalization must not depend on scanner availability.

Scanner failures may degrade file availability.
Scanner failures must not block ingestion or finalization.

Infected files are finalized deterministically but never published.

---

## Contracts

### Finalize Status

Finalize MUST result in exactly one of the following statuses:

- clean
- pending_scan
- infected

Finalize failure is represented by an upload.failed event
and never by finalize status.

### Failure Reason Codes

Failure reasons MUST be one of:

- finalize_size_mismatch
- finalize_missing_chunks
- finalize_fs_race
- finalize_in_progress
- scanner_unavailable
- invalid_filename
- finalize_internal_error

Unknown reasons are a bug.

---

## Preconditions

Docker Compose is running for the API service.
Scanner service may be running or stopped.
Test files exist under ./test-files.
Test scripts are executable.

---

## Batch Upload With Scanner Available

Command:
./scripts/services/api/file-upload-scan/upload_folder.sh ./test-files

Expected behavior:
- upload.initiated event is emitted.
- upload.scan.started event is emitted.
- upload.scan.completed event is emitted with verdict=clean.
- upload.finalized event is emitted.
- Finalize response status is clean.
- File is accessible via download endpoint.

---

## Scanner Down Simulation

This scenario validates the core v0.4 guarantee.

Commands:
docker stop scanner-service
./scripts/services/api/file-upload-scan/upload_folder.sh ./test-files
docker start scanner-service

Expected behavior:
- upload.initiated event is emitted.
- upload.scan.failed event is emitted with reason scanner_unavailable.
- upload.finalized event is emitted.
- Finalize response status is pending_scan.
- No upload is rejected due to scanner unavailability.

If upload.finalized is missing, this is a bug.

---

## Infected File Upload

This scenario validates deterministic handling of malware.

Command:
./scripts/services/api/file-upload-scan/upload_file.sh ./test-files/eicar.txt

Expected behavior:
- upload.initiated event is emitted.
- upload.scan.started event is emitted.
- upload.scan.completed event is emitted with verdict=infected.
- upload.finalized event is emitted.
- Finalize response status is infected.
- File is NOT accessible via any download or preview endpoint.
- File is NOT published to final storage.

If finalize fails instead of producing status=infected, this is a bug.

---

## Double Finalize for the Same upload_id

Commands:
META=$(./scripts/prepare_upload_no_finalize.sh files/a.png)
./scripts/test_double_finalize_same_upload.sh "$META"

Expected behavior:
- Exactly one finalize attempt succeeds.
- The second finalize attempt fails deterministically.
- Failure reason is finalize_in_progress.
- Upload events show:
  - one upload.finalized
  - one upload.failed

---

## Finalize With Missing Chunks

Commands:
META=$(./scripts/prepare_upload_incomplete_chunks.sh files/a.png)
./scripts/finalize_upload.sh "$META"

Expected behavior:
- upload.failed event is emitted.
- Failure reason is finalize_missing_chunks.
- No upload.finalized event is emitted.

---

## Finalize With Size Mismatch

Commands:
META=$(./scripts/prepare_upload_size_mismatch.sh files/a.png)
./scripts/finalize_upload.sh "$META"

Expected behavior:
- upload.failed event is emitted.
- Failure reason is finalize_size_mismatch.
- No upload.finalized event is emitted.

---

## Scanner Timeout Simulation

Command:
./scripts/services/scanner/simulate_timeout.sh

Expected behavior:
- upload.scan.failed event is emitted.
- Failure reason is scanner_unavailable.
- upload.finalized event is emitted.
- Finalize response status is pending_scan.

---

## Event Log Completeness

After any test run:

- Every upload_id has exactly one upload.initiated event.
- Every finalize attempt results in exactly one outcome.
- The outcome is either upload.finalized or upload.failed.
- No finalize attempt produces both.
- Infected uploads NEVER produce upload.failed.

---

## Notes

Any nondeterministic result indicates a concurrency or locking bug.

Scanner availability must not influence finalize determinism.

Infected files are a state, not an error.

A passing run must be reproducible across multiple executions.
