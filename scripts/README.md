## chaos/

This directory contains scripts used to intentionally introduce failures.

### Typical use cases
- Killing or restarting services
- Injecting latency or timeouts
- Simulating partial unavailability

### Used to validate
- Failure isolation boundaries
- Deterministic cleanup behavior
- Resilience assumptions

These scripts are **never** used in production environments.

---

## load-tests/

This directory contains scripts for applying controlled load to the system.

### Typical use cases
- Upload concurrency testing
- Large file stress scenarios
- Resource saturation experiments

### Used to validate
- Memory assumptions
- Latency behavior
- System breaking points

Results are observational, not contractual.

---

## local-tools/

This directory contains local development utilities.

### Examples
- Environment setup helpers
- Inspection and cleanup tools
- One-off debugging scripts

These tools are intentionally ad-hoc and environment-specific.

---

## services/

This directory contains helper scripts scoped to specific services.

### Examples
- Service startup helpers
- Health check scripts
- Service-specific diagnostics

These scripts must not leak assumptions across service boundaries.

---

## Usage Guidelines

- Scripts may be destructive
- Scripts may assume a local or controlled environment
- Scripts should be executed intentionally and explicitly

### Do **not**
- Run scripts blindly
- Depend on scripts for correctness
- Commit generated artifacts
