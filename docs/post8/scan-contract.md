# scan-contract.md
# Post 8 v0.3 Scan Contract between Laravel API and Node Scanner

## Goal

Provide a stable scan contract where:
• API sends file bytes to scanner as a stream  
• scanner returns a semantic result  
• transport failures and overload are surfaced explicitly  
• scanner does not access API filesystem paths

## Endpoint

POST /scan

Content Type: application/octet-stream

Query params:
• upload_id: string  
• filename: string  
• total_bytes: integer

Example:
POST /scan?upload_id=<id>&filename=<name>&total_bytes=123

## Request Body

Raw bytes stream of the file.

Rules:
• API must stream the exact file bytes in order  
• total bytes sent should match total_bytes  
• scanner must not require any shared disk access  
• scanner must not require any local_path or file reference
• No filesystem path is shared


## Response (JSON)

### clean

HTTP 200
{ "status": "clean" }

### infected

HTTP 200
{ "status": "infected", "signature": "string" }

### error

HTTP 5xx or 4xx depending on class
{ "status": "error", "reason": "timeout|unavailable|busy|bad_request|internal" }

Reason meaning:
• timeout: scan did not complete within enforced timeout  
• unavailable: scanner could not reach clamd or required dependencies  
• busy: scanner is overloaded and rejected the request immediately  
• bad_request: missing params or invalid total_bytes or invalid body  
• internal: unexpected scanner failure after request accepted

## Timeouts

• API enforces a hard timeout for the scan request  
• scanner enforces its own timeout for clamd operations  
• timeout must result in status error with reason timeout

## Backpressure

• scanner enforces a concurrency limit  
• when capacity is exceeded scanner returns status error with reason busy  
• v0.3 behavior: API fails finalize immediately on busy  
• future behavior may retry or queue, but that is out of scope for v0.3

## Notes

• clean and infected are domain outcomes and must not be represented as transport errors  
• only error represents an incomplete scan outcome  
• this contract intentionally stays small and stable
