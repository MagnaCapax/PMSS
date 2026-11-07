# ADR 0235: Periodic deletion of orphan-owned cron spools

Date: 2026-11-06
Category: security

## Status
Accepted

## Context
The addUser preflight prevents newly provisioned accounts from inheriting a
regular cron spool whose UID no longer belongs to an account. Existing accounts
with that older mismatch have no automatic repair path (issue #1008).

## Options Considered
- Keep relying on manual cleanup, leaving existing accounts affected.
- Reassign cron files to the account named by the filename, exposing a previous
  account's private jobs and potentially executing them as another account.
- Periodically apply the existing deletion-only policy from root cron.

## Decision
Run `cronSpoolCleanup.php` hourly at minute 23 with a 35-second offset. Share the
metadata-only removal helper with addUser while retaining its create-name guard.
Delete only regular files with no owning account; never read cron content or
change ownership. Preserve any locally listed UID and any UID resolved by NSS,
regardless of the spool filename. Refuse symlinks, unsafe ancestors and entries
whose inode, owner or mode changes during inspection. Abort if the local passwd
file or root identity cannot be read. Use the canonical in-script cron lock.

Audit username, path, former UID and result to the existing PMSS cron log
directory, with log rotation. `--dry-run` and `PMSS_DRY_RUN=1` report candidates
without removing them. Errors return nonzero while other entries remain eligible.

## Consequences
Existing orphan-owned spools are revisited without a coordinated host operation.
Deletion deliberately discards the former account's jobs; the current account
can create its own crontab afterward. Files belonging to any live UID remain
untouched and mismatches involving such UIDs still need operator inspection.
No credential files, permissions, listening ports or public endpoints change.
Hermetic fixtures cover cleanup, preservation, unsafe entries, replacement races,
audit fields and dry runs; execution belongs to the testing machine.

## References
- Issue #1008; account-creation guard #1000.
- [Cron locking](0049-in-script-lock-is-canonical-for-cron-concurrency.md).
- [Debian CVE-2017-9525](https://security-tracker.debian.org/tracker/CVE-2017-9525):
  privileged symlink-following ownership changes; no such operation is used here.
- [Debian CVE-2019-9705](https://security-tracker.debian.org/tracker/CVE-2019-9705):
  excessive cron content can exhaust memory; cleanup never loads that content.
