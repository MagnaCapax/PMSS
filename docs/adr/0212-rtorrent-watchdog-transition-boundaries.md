# ADR 0212: rTorrent watchdog transition boundaries

Date: 2026-10-30
Category: architecture

## Status
Accepted

## Context
The rTorrent watchdog spread escalation persistence and recovery across its
state and transition files. SCGI grace sizing, queue decisions and their effects
were split across state, lifecycle and transition files. The transition module
also carried two pending-response paths and two restart-completion paths.

## Options Considered
- Keep the mixed responsibilities and duplicate transition effects.
- Introduce a generic state-machine dispatcher with new action records.
- Group each existing recovery policy with its effects and share common paths.

## Decision
Keep all existing function names, signatures and include paths. The stable
`watchdogProcessFlow.php` include loads three definition-only modules:

- `watchdogMissing.php`: absent-process recovery and executor grace.
- `watchdogEscalation.php`: escalation encoding, retry eligibility and recovery.
- `watchdogScgi.php`: recent-restart grace, live-process decisions and recovery.

`watchdogState.php` retains path validation, complete writes, counter updates,
marker naming and removal. It loads the relocated state helpers to preserve
direct includes. `processLifecycle.php` retains signals, starts and restarts and
loads the relocated SCGI decision for the same reason. Dependencies define
helpers only; including a module performs no recovery.

Use one pending-response path for grace extension and wedge observation, with
wedge clearing only for grace extension. Use one restart-completion path for
confirmed wedges and processes lost during grace. Resolved-marker cleanup
applies an ordered eligibility table directly instead of building a second list.

## Consequences
- Three duplicated mechanisms disappear: pending effects, restart completion,
  and the intermediate resolved-marker removal list. No generic dispatcher,
  new action schema, parallel controller or public API is introduced.
- Existing diagnostics, return values, marker bytes, counter thresholds and
  account/PID validation remain intact. Unavailable state and D-state processes
  retain the ADR 0020 no-restart behavior; only consecutive saturated queues
  authorize a live-process restart.
- Failed marker writes and restart results retain their previous treatment.
  Observation retains wedge evidence; the lost-process path retains its
  pre-restart socket cleanup and does not gain a wedge-marker removal.
- Characterization tests record complete SCGI event traces, including healthy,
  vanished, waiting, D-state, unavailable-state, pending and restarting cases,
  plus all four resolved-marker policies with missing/null entries. Existing
  escalation fault fixtures inline the relocated helper at its new location.
- This internal change requires no deployment migration or production dry run.
  Local checks are changed-file PHP syntax and deleted-symbol searches; the
  lane and CI execute behavior tests on a testing machine.

## References
- [SCGI restart gate](0020-rtorrent-watchdog-accept-queue-wedge-gate.md)
- [Process facade](0077-rtorrent-process-facade-and-snapshot-flow.md)
- [Script contracts](../contracts.md#utilities-script-contracts)
