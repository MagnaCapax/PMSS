# ADR 0252: Network observation boundaries

Date: 2026-11-10
Category: architecture

## Status
Accepted

## Context
Network information mixed configured shaping caps, physical observations and
include-time globals. Setup also mixed uplink discovery with the firewall rule
builder. Physical speed detection normalized the same interface through separate
transport helpers and interpreted the sysfs override twice. Runtime callers used
only the combined speed detector; three intermediary helpers added no policy.

## Options Considered
- Retain the mixed files and intermediary helpers: preserves duplicated dispatch.
- Share every route/speed reader with MOTD and template seeding: would conflate
  their different command bytes, fallback precedence and display contracts.
- Separate physical observation and setup selection, keeping the existing
  includes and configured-information facade.

## Decision
Use the third option. `network/linkSpeed.php` owns one sysfs-first physical
observation flow. It normalizes once and resolves the sysfs override once before
deciding whether ethtool may run. `network/uplink.php` owns setup's tunnel fallback
and drift diagnostics, loaded through the original `network/setup.php` include.
`networkInfo.php` retains configuration reads and its existing globals.

Remove the three intermediary helpers and use the existing interface normalizer
at every caller, including live statistics and vnStat. There is one implementation
of each observation; MOTD and template seeding keep their distinct contracts.

## Consequences
- The callable helper count decreases by three, with no replacement helpers.
  The duplicate sysfs override decision and transport dispatch layers disappear.
- Physical observations are available without initializing configured-interface
  globals. Setup's rule builder and uplink observations have distinct owners.
- Configured speeds, unknown-speed defaults, explicit override isolation,
  command bytes, interface validation, diagnostic text and firewall order stay
  unchanged. Include-time globals remain available through `networkInfo.php`.
- Characterization fixtures cover probe ordering and results, malformed names,
  overflow, tunnel selection and rule snapshots. Source assertions follow moved
  code to its new owner. The protected test harness remains unchanged.
- Verification here is syntax-only; characterization execution belongs on the
  testing VM. No network configuration or service action runs during this refactor.

## References
- [Update workflow](../update.md)
- [Networking contracts](../contracts.md#networking)
