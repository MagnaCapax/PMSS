# ADR 0251: rTorrent process observation boundaries

Date: 2026-11-10
Category: architecture

## Status
Accepted

## Context
Process inspection mixed PID validation, executor classification, state decoding,
startup polling and three independent `ps` acquisition paths. Process lifecycle
also owned reboot grace and deterministic staggering. Watchdog and maintenance
callers depend on the existing helpers, including raw executor output references,
failure diagnostics and the requirement that an originally observed PID survives.

## Options Considered
- Retain the mixed inspection and lifecycle responsibilities.
- Introduce a generic process framework with backend or callback dispatch.
- Separate row interpretation and readiness from observation and signals, sharing
  only the existing `ps` acquisition sequence.

## Decision
Keep `process.php`, `processInspection.php` and every existing public helper.
`processRows.php` owns PID validation, executor classification and state decoding;
`processReadiness.php` owns stability polling, reboot grace and staggering.
`processInspection.php` acquires rows through one `ps` helper. Failed commands
retain their raw output and exit status for executor callers but supply no rows
to either decoder. Snapshot failures retain their warning row.

Executor classification populates the returned ordered map directly. Startup
polling stops through its loop condition once a valid PID appears. The state row
decoder has one rejection path for malformed rows and invalid PIDs. Signal and
launch policy remains in `processLifecycle.php`; no replacement implementation
or compatibility alias is added.

## Consequences
- Three acquisition implementations become one, removing two redundant paths.
- Command bytes, argument quoting, result types/key order, duplicate executor
  PIDs, last-state-row precedence and raw output references retain their contracts.
- Stability timing and callback order, PID constraints, reboot boundaries, signed
  hash handling and all signal/launch guards remain unchanged.
- Characterization fixtures substitute `ps`, clocks, sleeps and uptime reads.
  Reflection follows the public helpers across the move and permits comparison
  with the original implementation on the testing machine.
- The obsolete account-operation line exception is removed: command capture no
  longer embeds an account selector. Characterization tests retain explicit
  coverage of shell quoting at both account-selector callers.
- Existing signal fault injection reads the moved row helpers. The protected
  runner, common helpers and testing scripts are unchanged.

## References
- [Architecture](../architecture.md)
- [Watchdog process boundaries](0211-user-watchdog-process-and-start-policy-boundaries.md)
