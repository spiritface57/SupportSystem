# Production-Grade On-Prem File Upload and Scanning Architecture

This repository contains a constraint-driven, on-prem system for handling large file ingestion,
validation, and malware scanning under strict resource and failure boundaries.

The project is designed as a multi-service, multi-language architecture and is implemented
incrementally, with each version documenting explicit behavioral guarantees.

---

## Scope of This Repository

This is a **system-level repository**.

It defines:
- Overall architecture and operational constraints
- Service boundaries and responsibilities
- Versioned system behavior and guarantees

It treats services as replaceable components behind stable contracts.

It does **not** document internal implementation details of individual services.
Each service maintains its own README.

---

## Core Constraints

The system is designed under the following non-negotiable constraints:

- Fully on-prem deployment
- No external cloud dependencies
- No shared filesystem between services
- Bounded memory usage for large files
- Malware scanning must not block ingestion
- Partial failures must not cascade

All architectural and implementation decisions are derived from these constraints.

---

## High-Level Architecture

The system is composed of multiple isolated, language-agnostic services:

- **API Service (Laravel)**
  - Upload initialization and coordination
  - Chunked file ingestion
  - Finalization and integrity validation

- **Scanning Service (Node.js + ClamAV)**
  - Streaming malware scanning
  - Time-bounded execution
  - Failure containment and isolation

- **Worker Services (Go)**
  - Asynchronous and background processing
  - Isolated execution and resource boundaries

- **Supporting Components**
  - Temporary storage and deterministic cleanup
  - Infrastructure and orchestration assets
  - Operational, chaos, and load-testing scripts

See service-level READMEs for service-specific implementation details.

---

## Repository Structure

```text
.
├── docker
│   └── (container and orchestration assets)
│
├── docs
│   └── post8
│       └── (versioned architecture and implementation notes)
│
├── scripts
│   ├── chaos
│   │   └── (failure and disruption experiments)
│   ├── load-tests
│   │   └── (load and stress testing tools)
│   ├── local-tools
│   │   └── (local operational utilities)
│   └── services
│       └── (service-specific helper scripts)
│
├── services
│   ├── api
│   │   └── (core API service, Laravel)
│   ├── scanner-node
│   │   └── (streaming malware scanner, Node.js + ClamAV)
│   └── worker-go
│       └── (background worker service, Go)
│
├── CHANGELOG.md
└── README.md
