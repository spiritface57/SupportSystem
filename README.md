# SupportSystem --- On-Prem File Upload & Scanning Architecture

A constraint-driven backend system for handling large file ingestion,
validation, and malware scanning under real failure conditions.

This project evolves through explicit system guarantees --- not feature
accumulation.

------------------------------------------------------------------------

## System Evolution

This repository represents a staged architecture:

-   **Post 8 --- Deterministic Finalization**
    -   Finalize does NOT depend on scanner availability
    -   Scanner failure degrades state but never blocks ingestion
-   **Post 9 --- Infrastructure as a Contract**
    -   Real runtime dependencies (MySQL, Redis, RabbitMQ)
    -   Behavior validated under load (k6)
-   **Post 10 --- Storage as a Contract**
    -   Introduces storage-domain separation:
        -   final (trusted)
        -   quarantine (untrusted)
        -   transient (local)
    -   Storage enforces system behavior

------------------------------------------------------------------------

## Core Idea

> Storage is not where data lives.\
> Storage is where system behavior is enforced.

Post 10 introduces storage as a **first-class system boundary**, not an
implementation detail.

------------------------------------------------------------------------

## Architecture Overview

The system is composed of independent services:

-   **API (Laravel)**
    -   Upload orchestration
    -   Chunk handling
    -   Finalization logic
-   **Scanner (Node.js + ClamAV)**
    -   Streaming malware detection
    -   Failure isolation
-   **Infrastructure**
    -   MySQL (persistence)
    -   Redis (cache / queue)
    -   RabbitMQ (future guarantees)
    -   MinIO (object storage)

------------------------------------------------------------------------

## Core Guarantees

-   finalize never blocks
-   unsafe files never become publishable
-   storage domains enforce visibility
-   failure does not break determinism
-   behavior is observable and testable

------------------------------------------------------------------------

## Storage Model (Post 10)

-   **Transient (local)**
    -   temporary processing only
    -   cleaned after finalize
-   **Final (object storage)**
    -   clean, publishable files
-   **Quarantine (object storage)**
    -   infected / pending / unsafe files

### Finalize routing

clean → final infected → quarantine unavailable → quarantine
(pending_scan)

------------------------------------------------------------------------

## Getting Started

docker compose up -d --build docker compose ps

### Test upload

bash scripts/services/api/file-upload-scan/upload_one_file.sh\
scripts/services/api/file-upload-scan/files/a.png

------------------------------------------------------------------------

## Deep Dive

docs/posts/

------------------------------------------------------------------------

## Summary

This system is not built by adding features.

It is built by removing ambiguity and enforcing guarantees.

Each stage replaces assumptions with explicit, testable behavior.

------------------------------------------------------------------------

## License

For educational and demonstration purposes.
