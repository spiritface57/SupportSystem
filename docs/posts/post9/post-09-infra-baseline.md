# Post 09 - Infrastructure Baseline (v0.1)
**Topic:** DB, cache, queue, storage baseline  
**Status:** Technical documentation (repository)  
**Series:** On-Prem Support Platform (LinkedIn Posts)

> This post establishes the first runnable infrastructure baseline for the platform.

---

## 1) Why Post 09 exists

Post 08 proved the file pipeline can be deterministic under scanner failure.
Post 09 establishes the minimal infrastructure layer required for reliable runs:

- a real primary database (MySQL)
- a shared cache and queue backend (Redis)
- a message broker baseline (RabbitMQ)
- a local storage boundary (pre-MinIO)

This baseline is intentionally small but repeatable and fully documented.

---

## 2) Goals and non-goals

### Goals
1. Provide a concrete DB, cache, and queue backend for local runs.
2. Standardize Docker Compose topology and data persistence.
3. Capture required environment defaults.
4. Define simple smoke checks to verify connectivity.

### Non-goals (for this post)
- MinIO integration (Post 10)
- Event bus + outbox transport (Post 11+)
- HA, backups, TLS, or production security hardening
- Tuning and performance optimization

---

## 3) Baseline stack (versions)

- MySQL 8.0 (primary DB)
- Redis 7 (cache, sessions, queues)
- RabbitMQ 3.12 (management enabled)
- Local filesystem storage (pre-MinIO)
- PHP runtime extensions: `pdo_mysql`, `redis` (see `docker/php-fpm/Dockerfile`)

Redis is **active** for cache/session/queue in this baseline.
RabbitMQ is **provisioned only** (reserved for Post 11+ integration).

---

## 4) Topology (local)

Diagram source: `docs/posts/post9/diagrams/01-infra-baseline.mmd`

Runtime/deployment view: `docs/posts/post9/diagrams/02-runtime-deployment.mmd`

Key ports:
- Web: 8000
- MySQL: 3306
- Redis: 6379
- RabbitMQ: 5672 (AMQP), 15672 (management)
- Scanner: 3001

---

## 5) Environment defaults

`services/api/.env.example` defines the baseline defaults:

- `DB_CONNECTION=mysql`
- `DB_HOST=mysql`
- `DB_PORT=3306`
- `DB_DATABASE=support`
- `DB_USERNAME=support`
- `DB_PASSWORD=support`
- `CACHE_STORE=redis`
- `SESSION_DRIVER=redis`
- `QUEUE_CONNECTION=redis`
- `REDIS_HOST=redis`

RabbitMQ is provisioned as baseline infrastructure, but Laravel queue
integration is deferred to a later post to avoid extra dependencies.

---

## 6) Bootstrap steps (local)

1. Start the stack:
   - `docker compose up -d --build`
2. Run migrations:
   - `docker compose exec -T php php artisan migrate --force`
3. Verify the app responds:
   - `curl http://localhost:8000`

Alternative:
- `bash scripts/local-tools/post9-smoke-run.sh`

---

## 7) Smoke checks

DB:
- `docker compose exec -T php php artisan tinker --execute="DB::select('select 1');"`

Redis:
- `docker compose exec -T redis redis-cli ping`

RabbitMQ:
- `docker compose exec -T rabbitmq rabbitmq-diagnostics -q ping`

---

## 8) Performance comparison (k6)

Baseline test: `scripts/load-tests/k6_upload_pipeline.js`

Results (p95 in ms):

| VUs | Version | p95 init | p95 chunk | p95 finalize | p95 total | iterations |
| --- | --- | --- | --- | --- | --- | --- |
| 10 | post9 | 8,556 | 2,895 | 11,509 | 22,406 | 27 |
| 10 | post8 | 23,748 | 18,755 | 14,593 | 54,634 | 2 |
| 20 | post9 | 10,371 | 5,847 | 14,750 | 19,468 | 54 |
| 40 | post9 | 29,360 | 3,651 | 29,735 | 41,336 | 56 |

Notes:
- Post 8 at VUS 20/40 did not complete any iterations within 30s (k6 warning).
- Raw outputs stored locally under `files/k6` (post9) and `files/k6_post8` (post8), not committed.

---

## 9) Known limitations (explicit, accepted)

- No HA, backups, or TLS in this baseline.
- RabbitMQ is provisioned but not yet used by Laravel queues.
- Storage is local filesystem; MinIO comes in Post 10.

---

## 10) What comes next (Post 10+)

- **Post 10:** MinIO + lifecycle policies
- **Post 11+:** Outbox + workers + retries + DLQ (RabbitMQ integration)

---

## Appendix A - Implementation checklist (copy/paste)

- [ ] Add MySQL, Redis, RabbitMQ services to Compose
- [ ] Add persistent volumes and health checks
- [ ] Update `.env.example` defaults for DB/Redis/Queue
- [ ] Document bootstrap steps and smoke checks
- [ ] Add baseline diagram
- [ ] Update ledger, roadmap, project position, and changelog
