# ADR 0078: Separate resource counter reads from locked state

Date: 2026-09-29
Category: architecture

## Status

Accepted

## Context

`resources/log.php` combined managed-user lookup, locked counter-state updates,
cgroup reads, and resource-log delta preparation. The independent performance
metrics collector loaded that whole entrypoint only to use the cgroup readers.
Traffic ingress also uses the counter-state updater.

## Options Considered

- Keep all helpers in the log entrypoint, retaining the unrelated metrics dependency.
- Duplicate the readers in the metrics collector, creating a second source policy.
- Keep the log entrypoint and move the existing readers and state writer into
  separate modules (chosen).

## Decision

`resources/counters.php` owns the existing systemd and cgroup v1 readers and
loads their prerequisites. `resources/counterState.php` owns the existing lock,
delta, and persistence functions. `resources/log.php` keeps the user-facing log
operations and loads both modules for existing callers. `resources/metrics.php`
loads the counter readers directly. The v1 I/O group remains atomic and the
hierarchical memory fields keep their first-valid fallback order.

## Consequences

No public function, output format, counter threshold, or deployed state changes.
The metrics collector no longer loads the state writer. Existing resource and
traffic characterization tests retain their snapshots; a loader test locks the
new dependency boundary. No migration is needed.

## References

- [Resource statistics contracts](../contracts.md#resource-statistics)
- [Per-user blkio accounting](0045-per-user-blkio-accounting-bfq-first-with-enforcement-reseed-guard.md)
