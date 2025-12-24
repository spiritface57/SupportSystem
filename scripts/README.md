# Scripts – Operational and Testing Utilities

This directory contains operational, testing, and experimentation scripts
used to validate system behavior under non-ideal conditions.

Scripts in this directory are **not production code**.
They exist to support development, testing, and architectural validation.

---

## Scope

Scripts in this directory may:

- Simulate failure scenarios
- Apply load and stress to services
- Support local development and inspection
- Assist with operational validation

They must **never**:
- Be required for normal system operation
- Contain business logic
- Define system guarantees

All guarantees are enforced by services, not scripts.

---

## Directory Overview

```text
scripts/
├── chaos/
│   └── Failure and disruption experiments
│
├── load-tests/
│   └── Load, stress, and concurrency testing tools
│
├── local-tools/
│   └── Local development and inspection utilities
│
└── services/
    └── Service-specific helper scripts
