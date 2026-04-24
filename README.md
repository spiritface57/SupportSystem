# Post 9 — Infrastructure as a Contract

## On-Prem Support Platform

This branch introduces the first runnable infrastructure baseline for the upload pipeline.

Post 8 proved that the upload finalization logic could behave deterministically under scanner failure.

Post 9 moves the system from logical correctness to runtime reality.

The goal of this branch is simple:

> If the infrastructure is not real, the guarantees are not real.

---

## Core Claim

Infrastructure is part of the system contract.

A system cannot claim reliability, latency behavior, retry safety, or operational predictability if it is only tested against mocks or placeholders.

This branch replaces assumptions with real runtime dependencies.

---

## What This Branch Adds

Post 9 introduces a Docker-based infrastructure baseline with real services:

- MySQL 8.0
- Redis 7
- RabbitMQ 3.12
- Local filesystem storage
- Nginx + PHP-FPM runtime
- Scanner service
- Health checks
- k6 load testing

RabbitMQ is introduced in the baseline, but it is not yet used as a source of delivery guarantees.

That belongs to later posts.

---

## Why This Matters

Post 8 focused on deterministic file finalization.

But correctness in code is not enough.

Once the system runs under real conditions, new questions appear:

- Does the API behave under concurrent traffic?
- Does PHP-FPM handle real overlapping requests?
- Do Redis-backed components work correctly?
- Are infrastructure dependencies observable?
- Can load tests complete full upload cycles?
- Can failures be reproduced instead of guessed?

Mocks cannot answer these questions.

Real infrastructure can.

---

## System Components

### API Service

Laravel API responsible for:

- upload initialization
- chunk ingestion
- finalization
- scanner interaction
- event emission
- failure handling

### Web Service

Nginx frontend for the API runtime.

Used to exercise the API through a more realistic request path instead of relying on the development server.

### MySQL

Primary persistence layer.

Used for application data and upload events.

### Redis

Used for:

- cache
- sessions
- queue configuration
- coordination support

### RabbitMQ

Provisioned as part of the infrastructure baseline.

At this stage, RabbitMQ exists as a real dependency, but message delivery semantics are not yet part of the system contract.

### Scanner Service

Node-based scanner service used by the upload pipeline.

Scanner failure must not block finalization.

### Local Filesystem

Used as the storage layer in this phase.

Object storage separation is introduced later in Post 10.

---

## Main Guarantees

This branch validates that the system can run with real infrastructure dependencies.

It does not claim production-grade availability.

It does guarantee that:

- the stack is runnable through Docker Compose
- infrastructure services are real, not mocked
- upload pipeline behavior can be measured
- health checks exist for core dependencies
- load tests can exercise the pipeline under concurrent traffic
- runtime behavior is observable enough to support later architectural decisions

---

## Validation

Validation for Post 9 focuses on runtime behavior.

### Smoke Validation

The stack should start successfully with:

```bash
docker compose up -d --build

## Load Validation

k6 is used to exercise the upload pipeline under staged traffic.

Example Stages:

- 20 virtual users
- 40 virtual users
- 30-second runtime windows

## Goal

The goal is not to claim internet-scale performance.

The goal is to prove that the pipeline can run end-to-end under real local infrastructure conditions.

## What This Branch Does Not Claim

This branch does not claim:

- high availability
- multi-node durability
- production security hardening
- distributed guarantees
- RabbitMQ-backed delivery guarantees
- object-storage lifecycle management

These are intentionally deferred.

## Summary

Post 9 is about establishing a real runtime baseline.


.
├── docker
│   ├── nginx
│   │   └── default.conf
│   └── php-fpm
│       └── PHP-FPM runtime configuration
│
├── services
│   ├── api
│   │   └── Laravel API service
│   └── scanner-node
│       └── Scanner service
│
├── scripts
│   ├── load-tests
│   │   └── k6 load tests
│   └── services
│       └── API upload test helpers
│
├── docs
│   ├── posts
│   │   └── post9
│   │       └── infrastructure baseline documentation
│   └── diagrams
│
├── docker-compose.yml
├── CHANGELOG.md
└── README.md