# ADR 0102: Reconcile running qBittorrent WebUI settings

Date: 2026-10-16
Category: security

## Status

Accepted for default-off delivery; per-host enablement pending canary review.

## Context

The managed qBittorrent writer can update a file while its daemon still holds
older values in memory. Some existing configs also lack an owner-set WebUI
password line. A file change alone does not update a running daemon.

## Options Considered

- Keep stopped-only convergence: leaves running daemons with older values until
  a later independent restart.
- Stop every matching process after a write: includes same-named container
  processes and creates unbounded restarts on repeated failures.
- Gate a native-daemon reconciliation per host and retain a bounded stop state:
  selected because it can be enabled in small, observable batches.

## Decision

The marker `/etc/seedbox/config/qbittorrentWebuiEnforce.enabled` defaults off.
Without it, the new reconciler returns before reading or writing a config.
With it, the reconciler checks the writer's path predicate, forces every copy
of the two managed WebUI keys in each Preferences section, and inserts a fixed
PBKDF2-shaped sentinel only when the password line is absent. Existing password
lines are preserved. The customer panel treats that sentinel as a request to
collect a replacement password on its next Start or Restart action.

After a successful file write, only a process whose executable names a known
native qBittorrent install path and shares the host mount context may be stopped.
The executable check accepts the proc suffix left by an on-disk replacement
and runs again immediately before each signal. A root-owned state file
under `/run/pmss` limits the action to once per account per 24 hours; a stopped
pass clears the pending state after the written file can be loaded. A write or
state failure never issues a stop. A failed signal restores the prior interval
so a later pass can retry. The marker also records the last reported status to
avoid repeated cron lines for an unchanged result. The watchdog follows its existing start path.
Update-step2 calls the same reconciler before its managed-config writer. The
two WebUI keys also enter the existing managed set only while the marker exists.

The writer retains the config file's owner and mode. The new path logs only
the reconciliation result; it does not log a password or hash. It adds no WebUI
listener or panel endpoint. A stored WebUI hash grants control of that WebUI
when paired with its password; it does not itself provide shell access. The
panel asks for a separate WebUI password and hashes the supplied value. Older
provisioning may already have synchronized the account password into this
hash; changing that established provisioning contract is a separate decision.

## Consequences

Rollout requires a count-only census of configs without a password line and a
prepared support response before the first host. Enable no more than 2.5% of
hosts per batch; verify one prompted account recovers on one panel visit and
that no account is repeatedly stopped before expanding. Removing the marker
turns off future reconciliation. The network-level design remains separate.
