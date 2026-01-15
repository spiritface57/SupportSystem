# ADR-002 — Hybrid API Flow (REST + GraphQL + WebSocket + gRPC)

- **Status:** Accepted
- **Date:** 2025-11
- **Owner:** Mahmoudreza Abbasi
- **Scope:** On-Prem Support Platform
- **Related Posts:** Post 4 — The Hybrid API Flow

## Context

The platform spans multiple interaction types:
- state-changing workflows (commands),
- optimized reads (query-heavy dashboards),
- real-time bidirectional updates,
- high-throughput internal service calls.

A single protocol choice forces bad compromises (overfetching, chat latency, internal overhead, or messy coupling).

## Decision

- Use a **hybrid communication backbone**:
  - **REST** for stable command workflows (state transitions, validations).
  - **GraphQL** for optimized read aggregation (UI queries, dashboards).
  - **WebSocket** for realtime bidirectional channels (presence, live updates, chat).
  - **gRPC** for high-speed internal service-to-service communication.

- Introduce an API Gateway boundary that provides:
  - auth, validation, rate limiting,
  - routing/mediation per protocol,
  - consistent transformation and observability.

- Enforce boundaries:
  - UI talks only to the gateway.
  - Internal services do not leak directly to the public surface.

## Options Considered

### Option A — REST-only
- Pros: simple tooling, common patterns
- Cons: inefficient for read aggregation; realtime becomes bolted-on; internal calls noisy/slow
- **Rejected**.

### Option B — GraphQL-only
- Pros: great UI query model
- Cons: commands and realtime semantics get messy; internal performance not guaranteed
- **Rejected**.

## Consequences

- More operational components (gateway routing, multiple protocol stacks).
- Cleaner long-term separation: commands ≠ queries ≠ realtime.
- Easier to scale each lane independently and contain failures by interaction type.
