# Project Position
## On-Prem Support Platform

Version: v0.1  
Status: Active  
Last Updated: 2026-01-03  
Based on: Project Context v1.0

---

## 1. Current Phase

**Implementation Phase – Starting with File Upload & Processing Pipeline**

Posts 1–7 focused on architecture and constraints.
Hands-on implementation begins from **Post 8**.

---

## 2. Completed

### Architecture & Design
- Context and system boundaries defined
- Failure-aware layered architecture
- Data-flow separation (command / event / query / realtime)
- Capacity, network, and availability constraints

### File Pipeline (v0.4)
- Chunked, resumable uploads
- Persistent upload metadata
- Deterministic finalize behavior
- Scanner decoupled from upload completion
- Domain-level failure taxonomy
- Atomic and idempotent file finalization
- Failure scenario test coverage

---

## 3. Technology Stack (Current – Implementation Level)

These technologies reflect **current implementation choices**.
They do not define architectural constraints.

### Application
- Frontend: React
- Backend/API: Laravel

### Data & State
- Primary Database: MySQL
- Cache / Sessions: Redis

### Messaging & Async
- Message Broker: RabbitMQ

### File & Storage
- Current: Local filesystem
- Planned: MinIO (S3-compatible)

### Realtime
- Transport: WebSocket
- Gateway (planned): Node.js

### Infrastructure
- Containerization: Docker
- Local orchestration: docker-compose
- Target OS: Linux

---

## 4. In Progress

- Documentation of Post 8
- Repository structure hardening
- Infrastructure baseline planning

---

## 5. Not Started

- Infrastructure baseline (DB, cache, queue, object storage)
- Event bus + outbox pattern
- Worker services
- Read models & query layer
- Realtime gateway
- Observability stack
- Integration adapters

---

## 6. Known Risks (Accepted)

- File finalization currently synchronous in API
- No background worker or retry daemon yet
- Local filesystem dependency
- Limited observability

---

## 7. Next Step

**Post 9 – Infrastructure Baseline**
