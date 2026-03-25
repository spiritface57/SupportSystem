# Post 10 - Storage Separation (MinIO)
**Topic:** Object storage separation, versioning, lifecycle  
**Status:** Technical documentation (repository)  
**Series:** On-Prem Support Platform (LinkedIn Posts)

> This post moves final/quarantine binaries into S3-compatible storage while keeping chunk assembly local.

---

## 1) Why Post 10 exists

Post 9 established a runnable infra baseline.  
Post 10 separates durable file storage from local disk to make lifecycle and retention explicit.

---

## 2) Goals and non-goals

### Goals
1. Separate durable storage into two buckets: `final` and `quarantine`.
2. Enable versioning for both buckets.
3. Add lifecycle policy for noncurrent versions.
4. Keep chunk uploads and assembly local (no MinIO for chunks).

### Non-goals (for this post)
- Real HA or replication on a single physical disk
- Production security hardening, TLS, or backup procedures
- Queue-driven scanners or outbox delivery

---

## 3) Storage layout (local vs MinIO)

Local (still on disk):
- `storage/app/uploads/<uploadId>/` (chunks)
- `storage/app/uploads-meta/<uploadId>/meta.json`
- `storage/app/tmp/<uploadId>/assembled.bin`

MinIO (object storage):
- `final` bucket: `uploads/<uploadId>/<filename>`
- `quarantine` bucket: `uploads/<uploadId>/<filename>`

---

## 4) MinIO service (local)

`docker-compose.yml` adds:
- `minio` on ports `9000` (S3 API) and `9001` (console)
- `minio-init` that creates buckets, enables versioning, and applies lifecycle

Default credentials (local only):
- user: `support`
- pass: `support123`

Console: `http://localhost:9001`

---

## 5) Lifecycle policy

The init job applies a noncurrent-expire rule (default 30 days).  
Adjust in `docker-compose.yml` if needed.

---

## 6) Environment defaults

`services/api/.env.example`:
- `S3_ENDPOINT=http://minio:9000`
- `S3_USE_PATH_STYLE_ENDPOINT=true`
- `S3_FINAL_BUCKET=final`
- `S3_QUARANTINE_BUCKET=quarantine`
- `UPLOAD_FINAL_DISK=s3_final`
- `UPLOAD_QUARANTINE_DISK=s3_quarantine`
- `UPLOAD_FINAL_PREFIX=uploads`
- `UPLOAD_QUARANTINE_PREFIX=uploads`

Dependency note:
Laravel needs the Flysystem S3 adapter (`league/flysystem-aws-s3-v3`) installed.

---

## 7) Bootstrap steps (local)

1. Start the stack:
`docker compose up -d --build`
2. Ensure buckets exist:
`docker compose run --rm minio-init`
3. Verify MinIO:
`curl http://localhost:9000/minio/health/ready`

---

## 8) Upload smoke test (optional)

Command:
`bash scripts/services/api/file-upload-scan/upload_one_file.sh scripts/services/api/file-upload-scan/files/a.png`

If your shell treats the script as CRLF, run:
`tr -d '\r' < scripts/services/api/file-upload-scan/upload_one_file.sh | bash -s -- scripts/services/api/file-upload-scan/files/a.png`

Expected:
Response includes `status:"clean"` and a `path` like `s3://final/uploads/<upload_id>/a.png`.

---

## 9) Quarantine smoke test (optional)

Steps:
1. Stop scanner: `docker compose stop scanner`
2. Upload a file (same command as above)
3. Start scanner: `docker compose start scanner`

Expected:
- Finalize returns `status:"pending_scan"`.
- File is stored under `s3://quarantine/uploads/<upload_id>/<filename>`.

---

## 10) Rescan promotion (optional)

Command:
`docker compose exec -T php php artisan upload:rescan-pending`

Expected:
- Quarantine object is promoted to `final`.
- A `.published` marker remains under the quarantine prefix.

---

## 11) Single-disk limitation (explicit)

With a single physical disk, HA and replication are **not meaningful**.  
Any multi-node setup on one disk still shares the same failure domain.  
This post documents the separation and policies, but replication is deferred.

---

## 12) Diagram (storage separation)

Diagram source: `docs/posts/post10/diagrams/01-storage-separation.mmd`

---

## 13) What comes next (Post 11+)

- Event bus + outbox (RabbitMQ integration)
- Worker services, retries, DLQ
