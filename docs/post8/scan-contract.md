# Post 8 v0.3 -- Scan Contract (Laravel ↔ Node Scanner)

## Endpoint

POST /scan

## Request (JSON)

{

  "upload_id": "string",

  "filename": "string",

  "total_bytes": 123,

  "source": {

    "type": "local_path",

    "path": "/var/data/uploads_tmp/<upload_id>/<file>"

  }

}

## Response (JSON)

### clean

{ "status": "clean" }

### infected

{ "status": "infected", "signature": "string" }

### error

{ "status": "error", "reason": "timeout|unavailable|internal" }

## Timeouts

- API enforces a hard timeout on scan request.

- Scanner also enforces its own timeout for clamd operations.

## Backpressure

- Scanner returns HTTP 429 when overloaded.

- API treats 429 as a retryable failure (future version), but v0.3 returns error.