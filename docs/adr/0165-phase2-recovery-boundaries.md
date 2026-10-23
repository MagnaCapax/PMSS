# ADR 0165: Phase-2 recovery boundaries

Date: 2026-10-23
Category: architecture

## Status
Accepted

## Context

The phase-2 entrypoint mixed normal orchestration with abnormal-exit recovery.
Its three recovery commands used separate launch closures through a generic
callback adapter and an event adapter. Generic step classification also owned
the durable incomplete-user marker and its summary policy.

## Options Considered

- Keep recovery embedded in the entrypoint and marker state in step classification.
- Add a general recovery-action registry with callback dispatch.
- Separate emergency commands and incomplete-user state into focused modules,
  retaining the existing entrypoints and registration order.

## Decision

Choose the focused modules. `update/runtime/shutdown.php` owns abnormal-exit
recovery and one command executor; `userMaintenanceState.php` owns incomplete-user
state and summary handling, loaded through the existing `stepPolicy.php` facade.
The two generic rescue adapters are replaced by one command boundary. The
one-use summary-reason adapter is folded into its caller. No old implementation
is retained and no new recovery registry or state representation is introduced.

Shutdown registration remains before lock acquisition. Web recovery retains its
direct subprocess execution and inherited-lock-FD closure; permission recovery
retains `runStep()` profiling. Completion flags, dry-run behavior, command bytes,
event field order, exception propagation, fallback order and diagnostic strings
remain unchanged. Permission recovery still re-reads the last error after web
recovery. Lock release and root-cron restoration keep their original registration
sites and execution order.

Incomplete-user state retains its path guards, JSON bytes, complete-write check,
full persisted skip list and ten-reason diagnostic limit. Mismatches remain
soft failures, and complete runs still clear the marker.

## Consequences

- Normal orchestration, emergency recovery, step classification and durable
  partial-refresh state have separate owners.
- Three named adapters become one command boundary: two fewer internal concepts.
- The new modules rely on the runtime loaded by their existing callers.
- Characterization tests freeze thirteen shutdown traces without executing the
  updater, plus marker and classified-event payloads for partial summaries.
  Testing runs on the designated sandbox; syntax and static checks may run on
  the control node under its local rules.

## References

- [Phase-2 failure classification](0014-update-step2-step-error-classification-policy.md)
- [User-loop observability](0006-update-step2-user-loop-and-observability.md)
- `scripts/lib/tests/fixtures/updateStep2ShutdownTrace.php`
- `scripts/lib/tests/development/UpdateStep2IncompleteUserMaintenanceTest.php`
