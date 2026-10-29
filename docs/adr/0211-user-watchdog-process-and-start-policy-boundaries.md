# ADR 0211: User watchdog process and start-policy boundaries

Date: 2026-10-29
Category: architecture

## Status
Accepted

## Context
The shared user watchdog combines process observations and actions, opt-in
crash history, secured-marker policy, and service orchestration. All native
watchdog consumers load the same include. Splitting consumers into alternate
controllers would risk changing their start order and account isolation.

## Options Considered
- Keep the mixed module and repeated service-name and reset rules.
- Introduce service classes and migrate each cron consumer.
- Retain the include and orchestration, with focused process and policy modules.

## Decision
Keep `user/watchdog.php` as the stable include and service controller.
Move process probes, lock acquisition, guarded termination/restart and stopped
configuration application into `watchdogProcess.php`. Move crash history,
restart tokens and opt-in start decisions into `watchdogPolicy.php`.
The existing `watchdogTimeout.php` continues to own command deadlines and
backoff; it supplies the unchanged service-name pattern to both state users.

Build service specifications in one ordered schema, and use one crash reset
branch for running services and changed restart tokens. Process start-time
selection accepts successful metadata reads directly, without a skip branch.
Stopped configuration application uses one short-circuit predicate, retaining
validation before callback inspection and invocation. No public helpers or
consumer include paths change, and no parallel implementations remain.

## Consequences
- The main module owns orchestration; process actions and start policy can be
  reviewed independently, each in a module below 150 lines.
- Three copies of the service-name rule become one; two reset branches and
  two specification schemas each become one. Their accepted inputs and ordered
  output remain unchanged.
- Command builders still run after the first backoff check and before the gate,
  including for running services. The second backoff check, launch recording,
  dispatch, log order and pre-launch running-state result remain intact.
- Account selectors, signal selection, marker checks, root-side state paths,
  state permissions and timeout behavior retain their existing guards. These
  helpers do not store credentials or introduce network listeners. Exposed
  restart metadata remains metadata only; it does not supply shell commands.
- Characterization cases lock ordered specifications, crash transitions,
  service-name boundaries, dispatch callbacks and terminal output. Existing
  fault-injection fixtures inline the moved modules at their new locations.
  Local verification is syntax-only; the lane runs tests on a testing VM.

## References
- ADR 0131: Moved state for a migrated account's old copy.
- `docs/security/operational-safety.md` and `docs/security/testing.md`.
