# ADR 0075: Watchdog service follows device and operator mask

Date: 2026-09-28
Category: architecture
Status: Accepted

## Context

The watchdog installer used `is_file()` for `/dev/watchdog`, a character device.
It therefore never reached its enable step. Debian can enable its watchdog unit
independently, leaving the daemon active on a host without a watchdog device
while PMSS has already installed its load-reboot configuration.

## Options Considered

- Correct device detection alone. This would activate the formerly unreachable
  enable path but leave deviceless hosts exposed to package enablement.
- Always disable the unit. This would remove hardware watchdog protection.
- Reconcile service state with device availability, respecting an operator mask.

## Decision

On update, leave a masked `watchdog.service` untouched. Otherwise, select the
first character device at `/dev/watchdog` or `/dev/watchdog0`. Without one,
disable and stop an enabled watchdog unit before installing PMSS configuration.
With one, install the configuration and network check, then enable and start the
unit. A failed required install step still prevents activation.

State the existing load thresholds explicitly: 300 for one minute, 225 for five
minutes, and 150 for fifteen minutes. These match the daemon's existing default
ratios; this decision does not change the load-reboot policy.

## Consequences

- Device hosts receive watchdog protection on their next update.
- Deviceless hosts no longer run a package-enabled watchdog under PMSS policy.
- Operators can mask the unit during maintenance without an update undoing it.
- Live host behavior still requires verification during rollout.

## References

- PMSS issue #907.
