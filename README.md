# SupportSystem — On-Prem File Upload & Scanning Architecture

A constraint-driven backend system for handling large file ingestion, validation,
and malware scanning under real failure conditions.

This project is built incrementally. Each stage introduces a new architectural
guarantee and is validated under actual runtime constraints.

---

## System Evolution

This repository is structured as an evolving system, not a single static implementation.

- **Post 8 — Deterministic Finalization**
  - Finalize does NOT depend on scanner availability
  - Scanner failure degrades state, but never blocks ingestion

- **Post 9 — Infrastructure as a Contract**
  - Introduced real runtime dependencies (MySQL, Redis, RabbitMQ)
  - Validated behavior under actual load (k6)

- **Post 10 — Storage as a Contract**
  - Separated storage domains:
    - final (trusted)
    - quarantine (untrusted)
    - transient (local)
  - Storage enforces system behavior

---

## Branches

Each branch represents a specific architectural milestone:

```
feature/file-pipeline-hardening      → Post 8
feature/post9-infra-baseline         → Post 9
feature/post10-storage-separation    → Post 10
```

Switch branches to explore each stage:

```bash
git checkout feature/post10-storage-separation
```

---

## Architecture Overview

The system is composed of independent services:

- **API (Laravel)**
  - Upload orchestration
  - Chunk handling
  - Finalization logic

- **Scanner (Node.js + ClamAV)**
  - Streaming malware detection
  - Failure isolation

- **Worker (Go)**
  - Background processing (future stages)

- **Infrastructure**
  - MySQL (persistence)
  - Redis (cache / queue)
  - RabbitMQ (future event guarantees)
  - MinIO (object storage)

---

## Core Principles

This system is designed around strict constraints:

- fully on-prem deployment  
- no external cloud dependencies  
- bounded resource usage  
- failure isolation  
- deterministic behavior under failure  

---

## Guarantees

Across all stages, the system evolves toward:

- non-blocking upload pipeline  
- safe handling of untrusted files  
- deterministic finalization behavior  
- explicit storage boundaries  
- measurable runtime behavior  

---

## Getting Started

Start the full system:

```bash
docker compose up -d --build
```

Verify services:

```bash
docker compose ps
```

Run a sample upload:

```bash
bash scripts/services/api/file-upload-scan/upload_one_file.sh scripts/services/api/file-upload-scan/files/a.png
```

---

## Technical Deep Dive

Full architecture, implementation details, and validation scenarios are available in:

```
docs/posts/
```

---

## Related Articles

This repository is paired with a LinkedIn series:

- Post 8 — Deterministic Finalization
- Post 9 — Infrastructure as a Contract
- Post 10 — Storage as a Contract

---

## Summary

This project is not about building features.

It is about enforcing guarantees.

Each stage removes implicit assumptions and replaces them with
explicit, testable system behavior.

---

## License

For educational and demonstration purposes.
