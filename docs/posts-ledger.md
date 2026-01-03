# Posts Ledger
## On-Prem Support Platform – LinkedIn Series

Version: v0.1  
Status: Active  
Last Updated: 2026-01-03

---

## Purpose

This ledger provides a **single source of truth** mapping between:
- Published LinkedIn posts
- Repository documentation
- Concrete implementation milestones

Each post is treated as a **versioned architectural or implementation step**,
not standalone content.

---

## Post Index

### Post 1
**Title:** From Code to Infrastructure, Lessons I’ve Learned in Real-World Systems Engineering  
**Type:** Narrative / Experience  
**Status:** Published  
**Implementation:** N/A  
**Repo Tag:** N/A  
**Notes:** Series introduction and mindset framing

---

### Post 2
**Title:** Architecture Before Code  
**Type:** Architectural Principle  
**Status:** Published  
**Implementation:** N/A  
**Repo Tag:** N/A  
**Notes:** Defines architecture-first approach

---

### Post 3
**Title:** I'm Building a Support System from Scratch  
**Type:** System Scope & Constraints  
**Status:** Published  
**Implementation:** N/A  
**Repo Tag:** N/A  
**Notes:** Introduces on-prem constraint and system goals

---

### Post 4
**Title:** The Hybrid API Flow  
**Type:** API Architecture  
**Status:** Published  
**Implementation:** Conceptual  
**Repo Tag:** N/A  
**Notes:** REST / GraphQL / WebSocket / gRPC orchestration

---

### Post 5
**Title:** The Data Flow Map  
**Type:** Data & Flow Modeling  
**Status:** Published  
**Implementation:** Conceptual  
**Repo Tag:** N/A  
**Notes:** Command, Event, Query, Realtime flows

---

### Post 6
**Title:** Data-Flow Accurate, No Contradictions  
**Type:** Failure-Aware Architecture  
**Status:** Published  
**Implementation:** Conceptual  
**Repo Tag:** N/A  
**Notes:** Failure domains and isolation strategy

---

### Post 7
**Title:** Why Our On-Prem Ticketing System Starts With Limits, Not Features  
**Type:** Constraints & Capacity  
**Status:** Published  
**Implementation:** Conceptual  
**Repo Tag:** N/A  
**Notes:** Capacity targets, network reality, file pipeline constraints

---

### Post 8
**Title:** Deterministic File Finalization Under Scanner Failure  
**Type:** Core Implementation  
**Status:** Published  
**Implementation:** File Upload & Processing Pipeline (v0.4)  
**Repo Tag:** post8-v0.4.1  
**Documentation:** docs/posts/post-08-file-pipeline.md  
**Notes:** First production-grade implementation milestone

---

### Post 9
**Title:** Infrastructure Baseline for On-Prem Systems  
**Type:** Infrastructure  
**Status:** Planned  
**Implementation:** Infrastructure Baseline (DB, Cache, Queue, Storage)  
**Repo Tag:** post9-v0.1.0 (planned)  
**Documentation:** docs/posts/post-09-infra-baseline.md (planned)

---

## Ledger Rules

- Every published post **must** have an entry here
- Implementation posts **must** reference a repository tag
- Conceptual posts may not have code, but must map to context or roadmap
- This file is updated **only** when a post is published or planned

---

## Versioning Policy

- Ledger version increments when:
  - New post is added
  - Post status changes (planned → published)
- Ledger version does **not** change for content edits inside posts
