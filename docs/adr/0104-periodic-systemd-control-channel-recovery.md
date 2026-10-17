# ADR 0104: Periodic systemd control-channel recovery

Date: 2026-10-17
Category: reliability

## Status
Accepted

## Context
An active `dbus` or `systemd-logind` unit does not prove its D-Bus control
channel works. When that channel wedges between PMSS updates, PAM session
creation can fail for SSH and cron across a host. The existing service guard
checks unit state, so it cannot identify this failure.

## Options Considered
- Keep detection in update preflights: leaves the gap between maintenance runs.
- Restart on unit state: the reported failure presents active units.
- Probe the functional channel from root cron and recover only on failure: chosen.

## Decision
Root cron runs a host-level check every 15 minutes. It requires a nonempty
`systemctl show --property=Version` result and a successful `loginctl
list-sessions` call. Each probe has a 15-second deadline. A failed probe runs
`daemon-reexec`, then restarts D-Bus and logind in that order. Each action has a
45-second deadline and stops the sequence on failure. The check probes again
after recovery, logs every attempt and result, and uses a lock to avoid overlap.

## Consequences
- A detected wedge can recover between updates without relying on unit state.
- A persistent wedge can cause one recovery attempt per 15-minute run; the
  dedicated cron log exposes repeated attempts for investigation.
- An unavailable cron daemon cannot run this check; cron availability remains
  covered by the existing service setup and guardrails.

## References
- Issue #1078
- `etc/seedbox/config/root.cron`
- `scripts/lib/systemdControlChannel.php`
