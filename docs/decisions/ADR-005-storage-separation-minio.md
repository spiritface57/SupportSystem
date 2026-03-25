# ADR-005: Storage Separation With MinIO-Backed Durable Domains

## Status
Accepted

## Context

Earlier upload pipeline work established deterministic finalize behavior and degradation rules under scanner failure.

However, storage responsibilities were still not explicit enough.

The upload pipeline contains at least two different storage concerns:

1. transient processing state
   - chunks
   - temporary assembly
   - upload metadata

2. durable storage outcomes
   - clean finalized artifacts
   - quarantined artifacts

Treating all of these as local folders makes retention, policy, and promotion boundaries too implicit.

## Decision

The system will:

- keep chunk upload and temporary assembly on local disk
- move durable finalized artifacts into an S3-compatible `final` bucket
- move durable quarantined artifacts into an S3-compatible `quarantine` bucket
- enable bucket versioning
- apply lifecycle rules for noncurrent versions
- route finalize and rescan flows through configured storage disks rather than direct local final/quarantine paths

MinIO is used as the local S3-compatible object storage implementation for the workstation baseline.

## Consequences

### Positive
- storage responsibilities become explicit
- durable outcomes gain clearer policy boundaries
- final vs quarantine behavior becomes easier to reason about
- retention/versioning move closer to infrastructure policy
- later worker/event-driven stages have a cleaner storage boundary to build on

### Negative
- backend semantics are not identical across all drivers
- this does not create HA or real replication
- object storage introduces additional operational surface area
- a single workstation still has a single physical failure domain

## Out of scope

This ADR does not claim:

- production hardening
- multi-node durability
- disaster recovery
- backup strategy
- secure IAM/TLS setup
- distributed storage guarantees