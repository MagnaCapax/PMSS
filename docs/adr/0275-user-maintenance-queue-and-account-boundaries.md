# ADR 0275: User maintenance queue and account boundaries

Date: 2026-11-12
Category: architecture

## Status
Accepted for the operator-approved architectural simplification run.

## Context
`scripts/lib/update/userMaintenance.php` combined the single account queue,
resume-marker storage, legacy CPU-quota repair and account convergence. Optional
checks maintained separate phase labels and command paths, then inferred accepted
exit codes from a human-readable label. Queue counters were projected separately
into both the JSON event and the returned summary.

ADR 0006 requires one user enumeration and one maintenance queue. Resume gates,
exception boundaries, partial-lighttpd accounting and the soft-failure contract
must remain stable, including existing short-circuit behavior.

## Options Considered
- Keep the combined controller: unrelated state storage and account execution
  remain coupled to queue reporting.
- Introduce a generic maintenance-stage runner: adds a dispatch abstraction to
  the existing explicit sequence and risks changing failure boundaries.
- Retain the queue and include surface, separating existing responsibilities
  while consuming check metadata and summary fields directly.

## Decision
Keep `userMaintenance.php` and all existing public helper names. It owns account
enumeration, validation, resume selection, presentation, per-account catching and
the final summary. `users/refreshState.php` owns signatures and guarded markers;
`users/legacyCpuQuota.php` owns the existing repair; and
`users/maintenanceAccount.php` owns the explicit convergence/check/profile/marker
sequence. The new account helper returns only the existing lighttpd-incomplete
boolean; it does not create another account-result schema or user traversal.

One ordered optional-check table pairs each path with its display phase, command
label and accepted exit codes. The returned summary is accumulated directly and
merged into the existing JSON envelope without changing key order. Invalid
entries share reporting after the same short-circuit validation. Completed-pass
profiling derives status and return code from the existing lighttpd result.

## Consequences
- Four duplicated mechanisms disappear: separate invalid-entry reporting paths,
  label-based exit-code dispatch, separate completed-pass profile calls and
  separate event/return summary projections. One account-execution boundary is
  introduced: net three fewer mechanisms. File boundaries describe ownership;
  they introduce no alternate execution flow.
- Runtime code lines, excluding comments and blank lines, decrease from 189 to
  177 across the original facade and its three new modules. All six existing
  marker and legacy-quota helpers retain their bodies and signatures.
- Only the boolean presence of an incomplete account is retained; its former
  numeric count was consumed solely as a greater-than-zero summary predicate.
- The marker reset remains outside the per-account catch. Resume, cgroup,
  environment, linger, optional checks, timing, profiling and marker publication
  retain their ordering. A resumed account with changed Docker packages still
  reaches the existing cgroup-policy failure; this refactor does not alter it.
- `UserMaintenanceFlowCharacterizationTest` records deterministic queue traces
  for sorted accounts, invalid entries, resume/package gates, optional checks,
  busy exits, incomplete lighttpd, exceptions, environment reasons, prune gates,
  empty runs and TTY output. Existing marker and source contracts follow their
  new locations. No test harness changes are required.
- No updater or dry run is executed on the devbox. Changed PHP files receive
  syntax checks locally; the lane executes characterization tests on the
  testing machine before publication, with the full suite left to CI.

## References
- [Single user loop and observability](0006-update-step2-user-loop-and-observability.md)
- [Phase-2 recovery boundaries](0165-phase2-recovery-boundaries.md)
- `scripts/lib/tests/development/UserMaintenanceFlowCharacterizationTest.php`
- `scripts/lib/tests/development/LighttpdRefreshMarkerTest.php`
