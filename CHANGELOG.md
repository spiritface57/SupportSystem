Post 08 -- File Upload & Chunking
--------------------------------

### v0.1 -- Baseline Upload Flow (No Security Claims)

**Scope**

-   Laravel API bootstrapped as the upload entry point.

-   Upload session initialization endpoint.

-   Chunked file upload supported.

-   Chunks are accepted and written to temporary storage.

-   Upload flow is functional but incomplete.

**Explicit Non-Goals**

-   ❌ No virus or malware scanning.

-   ❌ No streaming scan.

-   ❌ No finalize guarantees.

-   ❌ No integrity verification beyond basic size checks.

-   ❌ No authentication or authorization.

-   ❌ No encryption or security claims.

-   ❌ No retry guarantees.

-   ❌ No production hardening.

**Notes**

-   This version exists to validate the upload flow and request lifecycle only.

-   Behavior is intentionally unsafe and incomplete.

-   Any reliability or security properties are out of scope for this version.
  

## v0.2 -- Finalize & Integrity (Still No Scanning)

**Scope**

-   Finalize endpoint added to complete an upload session.

-   Chunks are assembled into a single file in order.

-   Total file size is verified against `total_bytes` declared at init.

-   Basic collision handling for duplicate `upload_id` or existing output file.

-   Upload session finalize result is returned as completed or failed.

-   Temporary chunk files are cleaned up after finalize.

**Explicit Non-Goals**

-   ❌ No virus or malware scanning.

-   ❌ No streaming scan.

-   ❌ No content validation.

-   ❌ No authentication or authorization.

-   ❌ No retry or resume guarantees.

-   ❌ No checksum or cryptographic integrity validation.

-   ❌ No concurrency safety across multiple finalize attempts.

-   ❌ No production hardening.

**Failure Behavior**

-   Missing chunks result in finalize failure.

-   Size mismatch results in finalize failure.

-   Partial files may exist on disk after failure.

-   Failure does not automatically roll back state.

**Notes**

-   This version focuses on correctness of file assembly, not safety.

-   File integrity is limited to byte-count verification only.

-   Security properties are explicitly out of scope.



## v0.3 -- Streaming Scan (Node.js + ClamAV) + Backpressure + Timeouts

**Scope**

-   A dedicated **Node.js scanner service** is introduced as a separate process boundary from the Laravel API.

-   The upload **finalize** flow triggers a **streaming virus scan** via the scanner service.

-   The scanner uses **ClamAV INSTREAM protocol** to scan incoming bytes as a stream.

-   The API **does not scan files locally** and does not rely on a shared filesystem for scanning.

-   **No shared disk exists between services**:

    -   The API may assemble chunks into a local temporary file.

    -   The scanner never mounts or reads API filesystem paths.

    -   All scan data is transferred over a socket stream.

-   Scan results are returned as **semantic outcomes**, not transport errors:

    -   `clean` -- scan completed successfully, file is safe

    -   `infected` -- scan completed successfully, malware detected

    -   `error` -- scan did not complete successfully

-   **Timeouts** are enforced on scan requests to prevent hanging finalize operations.

-   **Backpressure** is enforced via scanner concurrency limits:

    -   When scanner capacity is exceeded, new scan requests are rejected immediately.

    -   This prevents latency collapse and resource exhaustion.

* * * * *

**Explicit Non-Goals**

-   ❌ No advanced threat detection beyond ClamAV signature scanning.

-   ❌ No file-type validation, magic byte inspection, or content parsing.

-   ❌ No cryptographic integrity checks (checksums, hashes).

-   ❌ No exactly-once guarantees for scan requests.

-   ❌ No strict idempotency or full concurrency safety for duplicate finalize calls.

-   ❌ No distributed transactions or rollback guarantees.

-   ❌ No persistent scan state machine.

-   ❌ No production-grade observability (metrics dashboards, tracing, alerting).

* * * * *

**Failure Behavior**

-   **Scanner unavailable**

    -   Finalize returns a failure response with reason `scanner_unavailable`.

    -   The file is **not promoted** to a final state.

-   **Scan timeout**

    -   Finalize returns a failure response with reason `scan_timeout`.

    -   The file is **not promoted**.

-   **Backpressure triggered**

    -   Scanner rejects the request with reason `scanner_busy`.

    -   Finalize fails fast instead of blocking or queueing.

-   **Unexpected scanner response**

    -   Finalize returns failure with an explicit error reason.

    -   The file remains untrusted.

-   Temporary files **may exist locally** on the API during or after failure,\
    but **promotion to a final state only occurs after a clean scan result**.

* * * * *

**Notes**

-   The primary goal of this version is **failure containment**:

    -   Scanner failures must not crash, block, or destabilize the API process.

-   Scan outcomes (`clean`, `infected`) are **domain results**, not errors.\
    Transport-level failures are handled separately.

-   File locking and concurrent write protection are **intentionally out of scope**:

    -   Finalize assumes all chunks have been fully written before scanning begins.

    -   Concurrent writes during finalize are considered undefined behavior.

-   This version prioritizes **controlled degradation over throughput**.\
    Rejecting work safely is preferred to accepting work unsafely.