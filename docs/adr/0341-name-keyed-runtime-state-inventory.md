# ADR 0341: Inventory name-keyed runtime residue and usage history

Date: 2026-11-24
Category: architecture
Status: Proposed

## Context

Account names can be reused. Cron spools, screen sockets, monthly IOPS budget
state and usage history have each survived termination and affected a later
account. Separate path lists let the same failure recur (#1310).

## Options Considered

- Keep independent cleanup lists and add checks after each incident.
- Centralize exact paths and retirement policy while retaining guarded operations.
- Key future state by UID; this leaves existing name-keyed state uncovered.

## Decision

Use `scripts/lib/user/nameState.php` for the recurring runtime residue classes:
cron spools (both layouts), screen sockets, IOPS budget files, resource/traffic
logs, derived statistics caches and linger. Each group declares remove or
archive and supplies the exact paths to its existing termination operation.
Adding a bucket to an existing group requires only an inventory entry; both
retirement and creation preflight see it. New operation types must also supply
an appropriately guarded consumer; the inventory is not an arbitrary executor.

Creation retains the orphan-cron cleanup and its ownership checks. Afterwards
it reports every occupied inventory path and refuses username reuse until the
residue is retired. It neither reads former account data nor automatically
removes account-owned sockets or discards historical logs. Directories and
dangling links count as residue too.

The inventory covers external runtime residue, not UID-keyed slices, home
purges, configuration-store rows or service port reservations. Those retain
their dedicated lifecycle APIs. Feature authors must register new name-keyed
runtime buckets in the appropriate group alongside their writer.

## Consequences

- Existing account-presence, dry-run, cron ownership and account-UID socket
  guards remain in their operation-specific helpers (ADR 0191).
- Usage logs remain archived per ADR 0044; derived caches are removed.
- An incomplete termination can now block creation instead of silently passing
  former state to a new account. Operators must resolve reported residue.
- `UserNameStateInventoryTest` adds a private bucket to inventory data alone
  and checks creation refusal, live-account retention, dry-run retention,
  termination removal, successful reuse and neighboring-account isolation.
  Execution belongs on a testing machine; devbox verification uses PHP lint.

## References

- Issue #1310; ADRs 0044, 0115, 0121, 0191 and 0325.
