# testing-v0.4.md
# v0.4 Testing

This file documents reproducible test scenarios for Post 8 v0.4.

## Preconditions

• docker compose is running for api and scanner  
• test files exist under ./test-files  
• scripts are executable

## Batch upload

Command:
./scripts/upload_folder.sh ./test-files

Expected:
• upload.initiated event emitted  
• upload.scan.started event emitted  
• upload.scan.completed event emitted  
• upload.finalized event emitted

## Scanner down simulation

Commands:
docker stop scanner-service
./scripts/upload_folder.sh ./test-files
docker start scanner-service

Expected:
• upload.initiated event emitted  
• upload.scan.failed event emitted with reason scanner_unavailable  
• upload.failed event emitted  
• no upload.finalized event emitted


## Double finalize for the same upload_id

Commands:
META=$(./scripts/prepare_upload_no_finalize.sh files/a.png)
./scripts/test_double_finalize_same_upload.sh "$META"

Expected:
• exactly one finalize attempt may succeed, the other must fail deterministically  
• failing finalize must return a safe error and must not corrupt the promoted file  
• if a filesystem lock is implemented in v0.4 then the second finalize should fail with a clear reason such as finalize_locked or finalize_in_progress  
• upload events must show one finalize winner and one failed attempt

## Notes

• if any test result is nondeterministic, treat it as a bug in concurrency control or locking  
• a passing run must be reproducible across multiple runs
