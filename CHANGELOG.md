# CHANGELOG.md
Post 08 File Upload and Chunking
================================

## v0.1 Baseline Upload Flow no security claims

Scope
• Laravel API bootstrapped as the upload entry point  
• upload session initialization endpoint  
• chunked file upload supported  
• chunks accepted and written to temporary storage  
• flow functional but intentionally incomplete

Explicit non goals
• no malware scanning  
• no streaming scan  
• no finalize guarantees  
• no integrity verification beyond basic byte count acceptance  
• no auth  
• no encryption claims  
• no retry guarantees  
• no production hardening

Notes
• this version validates request lifecycle only  
• behavior is intentionally unsafe  
• any reliability or security properties are explicitly out of scope


## v0.2 Finalize and integrity still no scanning

Scope
• finalize endpoint added to complete an upload session  
• chunks assembled into a single file in order  
• total file size verified against total_bytes declared at init  
• basic collision handling for duplicate upload_id or existing output file  
• finalize returns completed or failed  
• temporary chunk files cleaned up after finalize

Explicit non goals
• no malware scanning  
• no streaming scan  
• no content validation  
• no auth  
• no retry or resume guarantees  
• no checksum or cryptographic integrity  
• no concurrency safety across multiple finalize attempts  
• no production hardening

Failure behavior
• missing chunks cause finalize failure  
• size mismatch causes finalize failure  
• partial files may remain on disk after failure  
• failure does not roll back state

Notes
• focus is correctness of file assembly not safety  
• integrity is byte count only  
• security properties remain out of scope


## v0.3 Streaming scan Node scanner with ClamAV plus backpressure and timeouts

Scope
• Node scanner service introduced as a separate process boundary  
• finalize triggers a streaming scan via scanner service  
• scanner uses ClamAV INSTREAM protocol  
• API does not scan files locally  
• no shared disk between services  
• scan results are semantic outcomes:
  • clean  
  • infected  
  • error
• timeouts enforced to prevent hanging finalize  
• backpressure enforced via scanner concurrency limits:
  • when capacity exceeded new scan requests are rejected immediately

Explicit non goals
• no advanced threat detection beyond ClamAV signature scanning  
• no file type validation or magic byte inspection  
• no cryptographic integrity checks  
• no exactly once guarantee for scan  
• no strict idempotency for duplicate finalize  
• no distributed transactions or rollback  
• no persistent scan state machine  
• no production observability stack

Failure behavior
• scanner unavailable:
  • finalize fails with reason scanner_unavailable  
  • file is not promoted to final trusted state
• scan timeout:
  • finalize fails with reason scan_timeout  
  • file is not promoted
• backpressure triggered:
  • scanner rejects with reason scanner_busy  
  • finalize fails fast
• unexpected scanner response:
  • finalize fails with explicit reason  
  • file remains untrusted
• temporary files may exist locally, but promotion only occurs after clean scan

Notes
• primary goal is failure containment  
• clean and infected are domain outcomes not transport errors  
• concurrency protection is out of scope in v0.3  
• controlled rejection is preferred over unsafe acceptance


## v0.4 Observability and finalize race safety

Scope
• persistent upload event log table upload_events for observability  
• events emitted using event_name field

Event names
• upload.initiated  
• upload.finalized  
• upload.failed  

Scan lifecycle events
• upload.scan.started  
• upload.scan.completed  
• upload.scan.failed  

Failure reasons
• failure reasons are restricted to the values defined in docs/failure-reasons.md
• free-form reason values are not allowed


• classify scanner network failures as scanner_unavailable:
  • DNS failure  
  • connection refused  
  • connect timeout  
  • read timeout

• finalize filesystem lock to prevent concurrent finalize races  
• reproducible scripts for batch uploads and failure simulations  
• SQL metric queries derived from upload_events table

Malware handling
• infected is a terminal finalize status (infected files are finalized deterministically but never published)
• infected is a state, not a failure reason

Explicit non goals
• no global upload state machine
• no retry or queueing of failed scans
• no aggregation tables or precomputed metrics

Metric query examples

Total uploads initiated per day:

SELECT
  DATE(created_at) AS day,
  COUNT(*) AS count
FROM upload_events
WHERE event_name = 'upload.initiated'
GROUP BY DATE(created_at)
ORDER BY day DESC;


Finalize success counts:
Counts are derived from persisted events and not pre-aggregated.

SELECT
  SUM(CASE WHEN event_name = 'upload.finalized' THEN 1 ELSE 0 END) AS success,
  SUM(CASE WHEN event_name = 'upload.failed' THEN 1 ELSE 0 END) AS failed
FROM upload_events;


Scanner failure reasons distribution:

SELECT
  reason,
  COUNT(*) AS count
FROM upload_events
WHERE event_name = 'upload.scan.failed'
GROUP BY reason
ORDER BY count DESC;



Notes
• v0.4 turns the upload flow into an auditable system  
• lock behavior must be deterministic under double finalize  
• metrics are derived only from persisted events
• for a given upload_id, exactly one finalize outcome must exist per finalize attempt

## post8-v0.4.1
- Implement infected files as terminal finalize state
- Enforced event contract alignment with v0.4 guarantees
- Hardened finalize determinism