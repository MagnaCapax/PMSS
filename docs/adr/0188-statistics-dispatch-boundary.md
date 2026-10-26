# ADR 0188: Shared statistics processor dispatch boundary

Date: 2026-10-26
Category: architecture

## Status

Accepted

## Context

Resource statistics used the shared processor's CLI scheduler, while traffic
statistics overrode it with a second scheduler. Both accepted single-account
requests, discovered batch accounts and reported empty runs. Their execution
policies differ: resource workers detach, while traffic must aggregate inside
the cron process holding its lock (ADR 0049).

The shared loader also returned both raw text and split records, although each
consumer used only one representation. Both processors separately declared the
statistics object, and resource repeated the base account-validation policy.

## Options Considered

- Retain two CLI controllers and the dual-representation loader result.
- Make all statistics workers detach, violating traffic's lock lifetime.
- Share dispatch with an internal execution policy and return checked log text.

## Decision

Use `PmssUserStatsProcessor::runCli()` for resource, egress and ingress
statistics. Resource retains the default detached-worker policy; traffic selects
inline processing. Keep the existing public constructors and methods, cron
entrypoints, locks, parsers, accumulators and persistence implementations.

Preparation still runs before every resource CLI invocation and every explicit
traffic account request. Only detached batches call the spawn-lock hook. Inline
batches filter all discovered entries before processing, then calculate one set
of window boundaries for the entire batch. Resource discovery remains unfiltered
before spawning, so workers retain their existing validation responsibility.

The shared loader retains validation, trimming, line-count checks and diagnostics
but returns nullable log text. Traffic splits that text with its original
`array_filter()` policy; resource passes it to its existing collector. The base
owns the statistics reference and default account validator; traffic retains
its localnet-to-base-account mapping.

## Consequences

- Two CLI scheduler implementations become one, with no parallel traffic override.
- The two-field loader result becomes one checked text value. Traffic splits
  records after the common preflight rather than receiving its temporary array.
- CLI output, exit codes, account order, worker commands, lock lifetimes,
  diagnostic sinks and serialized data remain unchanged.
- `StatsDispatchCharacterizationTest` locks event order, validation, busy and
  empty batches, explicit-account normalization, output bytes and shared windows
  against the concrete processors. Existing processor tests cover persistence.
- Static checks run on the control host; runtime tests run only on a testing
  machine. No provisioning or production dry run is needed for this internal
  dispatch change.

Resource layouts compose shared field groups, defaults are built directly, and
diagnostics invoke the configured logger without a private pass-through method.
Record lengths, field order, messages and persistence ordering stay unchanged.

## References

- [Cron lock policy](0049-in-script-lock-is-canonical-for-cron-concurrency.md)
- [Resource statistics contracts](../contracts.md#resource-statistics)
