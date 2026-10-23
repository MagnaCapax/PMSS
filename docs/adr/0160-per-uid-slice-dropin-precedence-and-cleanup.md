# ADR 0160: Per-UID slice drop-in precedence and cleanup

Date: 2026-10-23
Category: architecture
Status: Accepted

## Context

Legacy `90-pmss-user.conf` and `99-pmss.conf` files in
`/etc/systemd/system/user-<uid>.slice.d/` sort after the canonical `50-*`
property files created by `systemctl set-property` in `/etc/systemd/system.control/`.
A manager reload therefore restores legacy limits over the current account plan.
Directories left after account deletion can also apply a former account's limits
when its UID is reused. The existing bare `MemoryMax` unit repair corrects values
but does not correct their precedence.

## Options Considered

- Delete legacy files during update: loses accounting and other keys not set by
  the canonical property writer.
- Keep high-priority files and rewrite their resource values: creates a second
  owner for account limits and leaves the UID reuse problem unresolved.
- Preserve the files as lower-priority defaults, and remove both per-UID
  namespaces after account deletion.

## Decision

During update-step2 systemd preparation, scan exact numeric UID slice directories
under `/etc/systemd/system/`, including UIDs without an account. Back up each
legacy file with the existing `.pmss-backup-<timestamp>` convention, then rename
`90-pmss-user.conf` to `40-pmss-user-legacy.conf` and `99-pmss.conf` to
`41-pmss-legacy.conf`. Preserve bytes, ownership, modes and relative legacy order;
keys supplied by `50-*` files now win. Refuse occupied destinations and failed
backups. Reload the manager once after the repair batch, only if files changed.
The existing bare-memory-unit repair remains applicable to the renamed files.

After termination's final account deletion attempt, require both the account name
and its captured positive UID to be absent. Remove that UID's exact directories
from `/etc/systemd/system/` and `/etc/systemd/system.control/`, then reload once
if anything was removed. A failed cleanup or reload reports incomplete termination.
Dry runs perform neither mutations nor reloads.

Both operations require root-owned directories and regular files without group
or world write permission and reject symlinked paths. Cleanup preflights each
flat directory before deletion and refuses nested directories or special files.
The host-wide `user-.slice.d/99-pmss.conf` and unrelated UID directories are outside
cleanup scope. Host-wide TasksMax shadow reconciliation remains unchanged.

## Consequences

- Repeated successful runs create no further backups or repair reloads.
- Failed or unsafe files stay in place and are logged for operator repair;
  successfully changed files still receive a batch reload.
- Update retains legacy-only defaults. Termination intentionally removes all
  regular drop-ins and local backups for the departed UID to prevent inheritance.
- No credentials, network listeners or customer-facing include paths are added.
- Rollback: restore a timestamped backup to its original name, remove its renamed
  counterpart and reload systemd. This restores the former precedence too.

## References

- `scripts/lib/user/userCgroupSliceDropinRepair.php`: existing backup/unit repair.
- `docs/cgroup.md`: slice policy and lifecycle behavior.
- ADR 0115: verified termination completion.
- [systemd unit drop-in ordering](https://www.freedesktop.org/software/systemd/man/248/systemd.unit.html).
