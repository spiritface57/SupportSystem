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