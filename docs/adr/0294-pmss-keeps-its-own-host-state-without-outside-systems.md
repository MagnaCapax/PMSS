# ADR 0294: PMSS keeps its own host state without outside systems

Date: 2026-11-17
Category: architecture

## Status

Accepted

## Context

PMSS manages host state on every server: the `/proc` process-visibility
mount, permissions on `/home`, account homes and login records, sysctl
settings, system services and certificates. PMSS already sets this state in
the update run and at boot. Between those events nothing in PMSS re-checked
the settings that keep one account from seeing another, so a manual remount
or chmod could weaken them until the next update or reboot.

PMSS also offers interfaces to outside callers: account commands, read-only
statistics scripts and input files such as the fleet comparison figures.
Those interfaces must never become a way for an outside system to keep PMSS
working.

## Options Considered

- Let an outside system re-apply PMSS settings on a schedule: rejected. PMSS
  would then depend on a system it does not control, and that system's copy
  of the settings drifts from PMSS's own values.
- Re-check every PMSS setting from cron: rejected. Settings that are installed
  from templates converge in the update run (ADR 0293).
- Re-check from PMSS's own cron only the state whose loss exposes one account
  to another, and repair it (chosen, following ADR 0104).

## Decision

PMSS alone sets, keeps and restores the host state it manages, through the
update run (ADR 0272), boot and its own root cron. No PMSS function requires
an outside system to apply, re-apply or finish it, and PMSS behaves
correctly when no outside caller exists.

The existing services guard cron re-checks, between updates, the state whose
loss exposes one account to another:

- `/proc` is mounted with `hidepid=2` and with no group exemption (ADR 0027).
  A missing option or a `gid=` exemption is repaired by a remount.
- `/home` grants no read or write to other users.
- Login records grant no read to other users.

The check skips while the update holds its lock and logs every repair.
Account home modes stay with the per-account permission sweep. All other
managed state converges in the update run and at boot.

Outside callers may use PMSS's documented interfaces. Any input they supply
is optional, and PMSS works without it. PMSS documentation and code describe
an interface by what it accepts and returns. They never name an outside
system, its hosts or its internal components.

## Consequences

A server keeps its isolation settings between updates without help from
outside PMSS. A manual weakening is undone within minutes and leaves a log
line. Outside systems can stop re-applying PMSS settings without any loss.
Servers receive the re-check at the normal update pace.

## References

- [ADR 0027: Rootless Docker decoupled from the per-user systemd manager](0027-rootless-docker-decoupled-from-per-user-systemd-manager.md)
- [ADR 0104: Periodic systemd control-channel recovery](0104-periodic-systemd-control-channel-recovery.md)
- [ADR 0272: PMSS changes reach a server only through update](0272-pmss-changes-reach-a-server-only-through-update.md)
- [ADR 0293: Observe log-policy drift without remediation](0293-log-policy-drift-observation.md)
