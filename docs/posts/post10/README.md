# Post 10 - Storage Separation (MinIO)

This directory contains the documentation and diagram assets for Post 10 in the On-Prem Support Platform series.

## Purpose

Post 10 separates transient upload handling from durable storage outcomes.

It keeps chunk uploads and temporary assembly on local disk while moving finalized and quarantined artifacts into MinIO-backed object storage.

The goal is not to claim production-grade storage, but to make storage responsibilities explicit inside a real runnable baseline.

## Contents

- `post-10-storage-separation.md`  
  Main technical write-up for the milestone

- `validation.md`  
  Scenario-oriented validation notes for clean, degraded, rescan, and load behavior

- `diagrams/01-storage-separation.mmd`  
  Source diagram for storage separation

## Key ideas

- transient work remains local
- durable artifacts move to object storage
- `final` and `quarantine` become separate storage domains
- versioning and lifecycle become explicit infrastructure policy

## Limits

This post documents a single-node workstation baseline.

It does not claim:
- HA
- multi-node durability
- production hardening
- secure deployment controls
