# ADR 0160: One cgroup-v1 acquisition path for resource collectors

Date: 2026-10-23
Category: architecture

## Status

Accepted. Refines the counter-reader boundary in ADR 0078.

## Context

Billing counters and performance telemetry independently assembled CPU, memory,
PID, and block-IO readings. They shared primitive parsers but maintained separate
source traversal and bytes-to-ops family selection. Their distinct projections
are intentional: billing needs an atomic IO baseline and hierarchical memory;
telemetry retains partial IO and local memory fields.

## Options Considered

- Keep the separate acquisition flows and coordinate changes manually.
- Use telemetry directly for billing, reading unrelated performance sources.
- Share source acquisition with a field selection and preserve consumer projections
  (chosen).

## Decision

`resources/v1.php` owns the existing scalar, key/value, and blkio parsers, one
ordered source catalog, and one acquisition traversal. Billing selects its seven
non-IO fields before reads; telemetry uses the full catalog. Both acquire IO
bytes and matching-family ops through that traversal. `resources/counters.php`
retains the public hierarchy facade and billing projection; `resources/metrics.php`
retains the telemetry projection. The old acquisition blocks and telemetry-only
pair-merging helper are removed rather than kept alongside the shared path.

## Consequences

The source catalog replaces separate CPU ticks, CFS, memory scalar, memory stat,
PID, and OOM acquisition blocks with scalar/stat traversal. Consumer schemas,
key order, zero retention, sentinel rejection, duplicate-row policy, BFQ-first
selection, billing source reseeding, and the systemd fallback stay unchanged.
No persistence, CLI, cgroup-v2 policy, or deployed state migration changes.
The moved primitive readers retain their existing function names.

`UserMetricsCollectTest` characterizes both projections together, including
partial IO, zero-valued hierarchical totals, duplicate/sentinel rows, alternate
UIDs, malformed sources, and zero/BFQ-to-throttle selection. Existing resource
reader and state tests continue to lock the billing boundaries.

## References

- [Resource statistics contracts](../contracts.md#resource-statistics)
- [Counter/state separation](0078-resource-counter-and-state-boundaries.md)
- [BFQ-first accounting and source reseeding](0045-per-user-blkio-accounting-bfq-first-with-enforcement-reseed-guard.md)
