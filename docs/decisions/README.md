# Architecture Decision Records (ADR)

This directory contains **Architecture Decision Records** for the On-Prem Support Platform.

Each ADR documents:
- Context / problem statement
- Options considered
- The chosen decision
- Trade-offs and consequences
- Follow-ups (if any)

## Rules

- **Immutable filenames (after Accepted):** once an ADR is **Accepted**, its filename must never change.
- **Drafts may be renamed:** while an ADR is **Draft**, it can be renamed to fix typos or improve clarity.
- **One decision per ADR:** keep scope tight.
- **Internal versioning:** changes to an accepted ADR must be recorded inside the file via:
  - `Revision: <n>` in the header, and
  - a `Revision History` section at the end.

## Status values

- Draft
- Accepted
- Superseded

## Index

- ADR-001 — On-Prem Constraints and Non-Negotiables (Accepted)
- ADR-002 — Hybrid API Flow (REST + GraphQL + WebSocket + gRPC) (Accepted)
- ADR-003 — File Upload & Processing Pipeline (v0.4) (Accepted)
- ADR-004 — Scanner Backend Strategy (Pluggable Engines, Stable Verdict Contract) (Draft)
