# v0.4 Testing

This document defines reproducible test scenarios for Post 8 v0.4.

If test expectations and implementation diverge,
the implementation is wrong.

---

## Core Invariant

Upload finalization must not depend on scanner availability.

Scanner failures may degrade file availability.
Scanner failures must not block ingestion or finalization.

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
upload.initiated event is emitted.
upload.scan.started event is emitted.
upload.scan.completed event is emitted.
upload.finalized event is emitted.
Finalize response status is clean.

---

## Scanner Down Simulation

This scenario validates the core v0.4 guarantee.

Commands:
docker stop scanner-service
./scripts/services/api/file-upload-scan/upload_folder.sh ./test-files
docker start scanner-service

Expected behavior:
upload.initiated event is emitted.
upload.scan.failed event is emitted with reason scanner_unavailable.
upload.finalized event is emitted.
Finalize response status is pending_scan.
No upload is rejected due to scanner unavailability.

If upload.finalized is missing, this is a bug.

---

## Double Finalize for the Same upload_id

Commands:
META=$(./scripts/prepare_upload_no_finalize.sh files/a.png)
./scripts/test_double_finalize_same_upload.sh "$META"

Expected behavior:
Exactly one finalize attempt succeeds.
The second finalize attempt fails deterministically.
Failure reason is finalize_in_progress.
Upload events show one upload.finalized and one upload.failed.

---

## Event Log Completeness

After any test run:

Every upload_id has at least one upload.initiated event.
Every finalize attempt results in exactly one outcome.
The outcome is either upload.finalized or upload.failed.
No finalize attempt produces both.

---

## Notes

Any nondeterministic result indicates a concurrency or locking bug.
Scanner availability must not influence finalize determinism.
A passing run must be reproducible across multiple executions.
