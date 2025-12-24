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
• exactly one finalize attempt succeeds  
• the second finalize attempt fails deterministically  
• failure reason is finalize_locked  
• upload events show one upload.finalized and one upload.failed


## Event log completeness

After any test run:

Expected:
• every upload_id has at least one upload.initiated event  
• every finalize attempt results in either upload.finalized or upload.failed  
• no upload_id has both upload.finalized and upload.failed for the same attempt


## Notes

• if any test result is nondeterministic, treat it as a bug in concurrency control or locking  
• a passing run must be reproducible across multiple runs
