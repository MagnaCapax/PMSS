# ADR 0291: GUI watchdog responsibility boundaries

Date: 2026-11-16
Category: architecture

## Status
Accepted

## Context

`scripts/cron/checkGui.php` combined account traversal with path and ownership
checks, sentinel reconciliation, directory preparation and managed-file repair.
Its public helpers are used by development fixtures through the cron include.
ADR 0041 already defines web-root recovery; this change organizes its watchdog
caller without introducing a second recovery implementation.

## Options Considered

- Keep every responsibility in the cron file: preserves coupling and repeated state.
- Extract a new watchdog framework: adds interfaces and migration work without
  changing the repair policy.
- Keep traversal in the cron and group the existing checks and repairs into
  two operator-side modules: preserves the existing public surface and flow.

## Decision

Use the third option. `gui/watchdogPaths.php` owns account normalization, path
and ownership checks, sentinel health and gated reconciliation.
`gui/watchdogRepair.php` owns directory and managed-file recovery, including the
existing index compatibility helper. The cron loads both and retains traversal,
logger identity, exit behavior and its direct-execution guard.

Derive the data path and skeleton source at their use sites, retain one logging
callable and trim the home root once. Ownership refusal shares one diagnostic
after the symlink check; account lookup and inode inspection retain their order,
including inode inspection when the account lookup fails. Directory creation
still checks ownership before its final directory check.

## Consequences

- No new public functions, settings, customer dependencies or recovery modes.
- The original include loads all nine helpers; no parallel implementation remains.
- Paths, source/read guards, file modes, account-writer calls, diagnostic bytes,
  catalog order and failure/exception behavior retain their contracts.
- Two small modules replace the mixed helper block. Three temporary aliases
  and one duplicate ownership-refusal branch disappear.
- Characterization fixtures intercept probes and repairs in memory. Existing
  watchdog and skeleton-delivery tests remain applicable. Execution belongs on
  testing machines; devbox verification is changed-file PHP syntax only.

## References

- [ADR 0041](0041-user-web-root-managed-vs-customer-owned-boundary-and-recovery-convergence.md)
- `scripts/lib/tests/development/GuiWatchdogCharacterizationTest.php`
- `scripts/lib/tests/development/checkGuiCronTest.php`
- `scripts/lib/tests/development/UserSkeletonDeliveryCharacterizationTest.php`
