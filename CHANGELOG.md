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

-   Upload session is marked as completed or failed.

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



### v0.3 -- Streaming Scan (Node.js + ClamAV) + Backpressure + Timeouts

**Scope**

-   A dedicated **Node.js scanning service** is introduced as a separate process boundary.

-   Upload finalize flow triggers **streaming scan** through the scanner service.

-   Scanner performs **stream-based scanning** (no "write full file then scan" in the API service).

-   **No shared disk** is required between Laravel API and scanner service.

-   **Timeouts** are enforced for scan requests to prevent indefinite hangs.

-   **Backpressure** is applied so the system slows down safely under scanner load.

-   Clear scan outcomes are returned:

    -   `clean`

    -   `infected`

    -   `error` (timeout, scanner unavailable, internal error)

**Explicit Non-Goals**

-   ❌ No advanced threat detection beyond ClamAV.

-   ❌ No full content validation (file-type sniffing, magic bytes rules, PDF parsing, etc.).

-   ❌ No "exactly-once" guarantees for scan requests.

-   ❌ No perfect concurrency safety across duplicate finalize calls.

-   ❌ No distributed transactions or rollback guarantees.

-   ❌ No production-grade observability (full metrics dashboards) yet.

**Failure Behavior**

-   If scanner is unavailable, finalize may return a failure state (or "scan_pending" if implemented later).

-   If scanning times out, finalize returns failure with an explicit timeout reason.

-   Backpressure may reject or delay requests under high load to protect the system.

-   A partial file may exist temporarily, but should not be promoted to "final" without a clean scan.

**Notes**

-   The primary goal is **failure containment**: scanner failure must not crash the API process.

-   "Streaming" means the API does not depend on shared local filesystems to scan content.

-   This version prioritizes controlled degradation over throughput.