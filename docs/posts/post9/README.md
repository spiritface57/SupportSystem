# Post 9 - Infrastructure Baseline (v0.1)

Post 9 establishes the first infrastructure baseline for the on-prem support platform.

Scope:
- MySQL, Redis, RabbitMQ services
- Local filesystem storage (pre-MinIO)
- Docker Compose topology and env defaults
- Smoke checks for basic connectivity
- Smoke script: `scripts/local-tools/post9-smoke-run.sh`

Notes:
- Redis is active for cache/session/queue in this baseline.
- RabbitMQ is provisioned only (integration deferred).

Non-goals:
- MinIO integration
- HA, backups, TLS, or production hardening
- Event bus, outbox, or worker fleet
