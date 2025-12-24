# Post 8 v0.4 Failure Reasons

This document defines the only allowed machine-readable failure reasons
emitted by the upload and scan pipeline.

## Scanner failures

• scanner_unavailable  
• scanner_busy  
• scan_timeout  
• scan_protocol_error  

## Finalize failures

• finalize_locked  
• finalize_missing_chunks  
• finalize_size_mismatch  
• finalize_internal_error  

## Rules:
• failure reason must be one of the values listed above  
• free-form error messages must not be used as reason  
• human-readable context may be stored separately in metadata
