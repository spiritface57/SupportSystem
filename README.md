# Post 10 — Storage as a Contract

## On-Prem Support Platform

This branch introduces storage-domain separation for the upload pipeline.

Post 9 established a real infrastructure baseline.

Post 10 makes storage behavior explicit.

The goal of this branch is simple:

> Storage is not where data lives.  
> Storage is where system behavior is enforced.

---

## Core Claim

Storage is part of the system contract.

Clean files, unsafe files, pending files, and transient files must not share the same storage responsibility.

This branch enforces behavior through explicit storage domains:

- transient (local only)
- final (trusted)
- quarantine (untrusted / deferred)

---

## What This Branch Adds

- MinIO (S3-compatible object storage)
- Separate `final` and `quarantine` buckets
- Versioning enabled for durable storage
- Lifecycle rules for noncurrent versions
- Storage-aware finalize routing
- Storage-aware rescan promotion
- Marker-based replay protection
- Transient cleanup after finalize commit

---

## Storage Domains

### Transient (Local)

Used for short-lived processing only:

- chunk uploads
- temporary assembly
- upload metadata

This layer is **not durable policy storage**.

After finalize commit, transient data is cleaned up.

---

### Final (Object Storage)

```
s3://final/uploads/<uploadId>/<filename>
```

- Only clean files are written here
- Files in this domain are publishable

---

### Quarantine (Object Storage)

```
s3://quarantine/uploads/<uploadId>/<filename>
```

Used when:

- scanner is unavailable
- file is infected
- status is `pending_scan`

Files here are **not publishable**

---

## Finalize Decision Boundary

Finalize is the system’s main control point.

```
clean        → final
infected     → quarantine
unavailable  → quarantine (pending_scan)
```

Finalize does NOT depend on scanner availability.

---

## Main Invariants

- finalize never blocks  
- unsafe files never enter final  
- storage enforces behavior (not code flags)  
- promotion is controlled  
- replay does not break correctness  

---

## Rescan Promotion

Flow:

```
quarantine → rescan → clean → promote → final
```

Controlled by:

- per-upload lock
- `.published` marker
- event check (`upload.published`)
- idempotent logic

Marker is stored in **quarantine storage**, not local disk.

---

## MinIO Setup

### Access

```
http://localhost:9001
```

### Credentials (local only)

```
user: support
pass: support123
```

### Buckets

```
final
quarantine
```

---

## Environment Configuration

```
S3_ENDPOINT=http://minio:9000
S3_USE_PATH_STYLE_ENDPOINT=true

S3_FINAL_BUCKET=final
S3_QUARANTINE_BUCKET=quarantine

UPLOAD_FINAL_DISK=s3_final
UPLOAD_QUARANTINE_DISK=s3_quarantine

UPLOAD_FINAL_PREFIX=uploads
UPLOAD_QUARANTINE_PREFIX=uploads
```

---

## Validation

### Smoke Test

```
docker compose up -d --build
```

---

### Scenario 1 — Clean

- scanner available  
- file → final  

---

### Scenario 2 — Scanner Down

- scanner unavailable  
- finalize → `pending_scan`  
- file → quarantine  

---

### Scenario 3 — Rescan

- file rescanned  
- clean → promoted to final  
- marker prevents duplicate publish  

---

### Scenario 4 — Load

k6 used to test:

- upload init  
- chunk upload  
- finalize  

Goal: validate pipeline behavior under concurrent load.

---

## What This Branch Does Not Claim

- high availability  
- distributed durability  
- production security  
- multi-node storage guarantees  
- event delivery guarantees  

These are handled in later posts.

---

## Summary

Post 10 is not about adding MinIO.

It is about enforcing behavior through storage.

- Final → publishable  
- Quarantine → isolated  
- Transient → disposable  

This separation turns storage into part of the system contract.

---

## Folder Structure

```
.
├── docker
│   ├── nginx
│   │   └── default.conf
│   └── php-fpm
│       └── PHP-FPM runtime configuration
│
├── services
│   ├── api
│   │   ├── app
│   │   │   ├── Http/Controllers/UploadFinalizeController.php
│   │   │   ├── Console/Commands/RescanPendingUploads.php
│   │   │   └── Support/UploadStorage.php
│   │   ├── config
│   │   │   ├── filesystems.php
│   │   │   └── upload.php
│   │   └── .env.example
│   │
│   └── scanner-node
│
├── scripts
│   ├── load-tests
│   │   └── k6_upload_pipeline.js
│   └── services/api/file-upload-scan
│
├── docs
│   ├── decisions
│   │   └── ADR-005-storage-separation-minio.md
│   └── posts/post10
│
├── docker-compose.yml
├── CHANGELOG.md
└── README.md
```
