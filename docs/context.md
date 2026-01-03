# Project Context
## On-Prem Support Platform

Version: v1.0  
Status: Frozen  
Last Updated: 2026-01-03

---

## 1. Purpose

Design and implement a **production-grade, on-prem customer support platform**
capable of operating under real-world constraints:
network latency, partial outages, untrusted file uploads, and limited operations teams.

This repository represents a **reference architecture and implementation**,
not a demo or toy project.

---

## 2. Non-Negotiable Constraints

- **On-Prem Deployment**
  - No managed cloud services
  - All infrastructure is self-hosted
- **Network Reality**
  - Remote branches with high latency and unstable connectivity
  - All ingress through DMZ / UTM
- **Availability**
  - Target: 99.98% uptime
  - Database outages up to 10 minutes must not break user experience
- **Security**
  - All uploaded files are untrusted until scanned
  - Bulk file traffic must not degrade API control paths

---

## 3. Architectural Principles (Invariants)

- Architecture before code
- Failure-aware design
- Explicit separation of:
  - Commands vs Queries
  - Core vs Reactive processing
- Core services must never block on external dependencies
- External integrations are async and isolated

---

## 4. Flow Model (Frozen)

### Command Flow
REST / gRPC → Core Domain → Transactional Database

### Event Flow
Core Domain → Event Bus → Workers / Automation / Analytics

### Query Flow
GraphQL → Read Models / Cache / Search

### Realtime Flow
WebSocket Gateway ← Event Stream / Read Models

---

## 5. Failure Domains

- **Core Write Path**
  - Critical
  - If unavailable, writes must stop
- **Processing / Automation**
  - Failure tolerated
- **Read Models / Search / Analytics**
  - Can degrade independently
- **Integrations**
  - Fully isolated via async boundaries

---

## 6. File Handling Rules

- Chunked, resumable uploads
- Asynchronous processing
- Users never wait for scanning, conversion, or optimization
- Deterministic, observable file pipeline

---

## 7. Explicitly Out of Scope

- UI/UX polish
- Authentication provider implementation
- Multi-tenant architecture
- Cloud-native optimizations

---

## 8. Context Change Policy

This document changes **only** if:
- Core constraints change
- Architectural invariants are revised
- A major version migration is introduced

All other updates belong to `project-position.md`.
