# ADR 0154: Rootless Docker lifecycle module boundaries

Date: 2026-10-23
Category: architecture
Status: Accepted

## Context

`scripts/util/userDocker.php` combined CLI validation, account-shell execution,
two kinds of process observation, daemon startup, shutdown, status rendering,
and transferred-container startup in one 559-line executable. Five shutdown
call sites repeated the same scan-failure decision. Process discovery repeated
its bounded debug formatter, and two local functions only forwarded to shared
runtime helpers.

## Options Considered

- Keep the executable and extract only its process parser: lifecycle ownership
  and repeated failure decisions would remain mixed together.
- Add a lifecycle class or configurable state machine: neither is needed to
  express the existing sequential actions.
- Keep one CLI facade and move each lifecycle responsibility into a focused
  function module, consolidating repeated decisions (chosen).

## Decision

The executable loads `scripts/lib/user/docker/cli.php` and returns its status.
The facade retains validation, account resolution and dispatch. Sibling modules
own account commands, process observations/signals, startup/config fallback,
checked shutdown, status presentation and transferred-container startup.

Shutdown's process scan now owns its failure decision; the first scan retains
its diagnostic before failure. Restart can start only after shutdown returns.
One formatter owns bounded pgrep/ps diagnostics. Shared label and cgroup-mode
helpers are used directly, and container-state counting uses PHP's existing
array operations instead of a second counting loop.

The initial process observation before dispatch and the subsequent startup
recheck remain in their original order, including debug diagnostics. Command
strings, launch identity, timeouts, ownership checks, output, exit codes and
the existing defensive cgroup v2 config path retain their previous behavior.
This does not add a new launch mode or new v2 behavior. ADRs 0019, 0027, 0066
and 0072 continue to govern the runtime and transferred-container model.

## Consequences

All runtime code follows the single module path; no legacy implementation is
retained. Each module is below 150 lines. The original CLI's behavior is locked
by 27 hermetic snapshots captured on a testing machine before decomposition.
They cover output, exit status, logs, exact shell commands, signals and scan
counts with injected identity, filesystem, process and clock observations.
Existing account/config/linger source-contract checks follow their new owners.

This is an internal restructuring: it introduces no deployed configuration,
credential storage, listeners or migration. Rollback restores the previous
executable along with the matching tests and removes the new internal modules.
