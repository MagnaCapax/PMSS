# ADR 0131: Moved state for a migrated account's old copy

Date: 2026-10-21
Category: architecture

## Status
Accepted

## Context
A verified server move leaves an old account copy available for retrieving
remaining files. Continuing its managed applications wastes resources and can
duplicate work on the new server. Suspension is unsuitable: `suspend.php`
locks the Unix account and changes its expiry, preventing SSH/SFTP retrieval.

## Options Considered
- Suspend the account: reuses a lifecycle but blocks the required file access.
- Rename its web root or place home markers: allows account-owned state to
  control parking and requires changes to customer files.
- Store a root-owned moved date and reuse watchdog stops: reversible without
  changing account authentication, expiry, or customer files.

## Decision
Root runs `userMoved.php <username> --until=YYYY-MM-DD` only after verifying
that the moved service works on its new server. The command stores the validated
calendar date as `movedUntil` in `/etc/seedbox/config/users/<user>.json`, using
the canonical-only store writer, then stops managed native/toggle applications,
rTorrent and its executor, managed media-stack tmux sessions, and rootless Docker.
Docker lifecycle work uses the existing `userDocker.php stop` wrapper; Docker
commands run as the account, never as root (ADR 0072).

Watchdogs share `pmssUserServicesParked()`: unavailable web root OR a stored
moved date. A passed date does not restart applications or remove data.
Media-stack cron is observe-only and starts nothing; its app sessions are
stopped by the command. No cron schedule changes are needed.

The command refreshes nginx through the suspension lifecycle helper. Suspended
routes take precedence over moved routes, which take precedence over active
routes. Moved primary and public-subdomain routes serve static HTML directly
without allocating a lighttpd port. Additional account UI proxies are omitted
from moved subdomain configs.

`userMoved.php <username> --clear` removes only `movedUntil`, refreshes nginx,
and lets existing watchdogs resume applications on their normal cadence. Clearing
an account without this state is a successful no-op. Repeating `--until` updates
the date and reasserts parking. Existing app enable/disable preferences survive.
Observe-only media-stack applications may need their normal manual start.

Removal remains an explicit operation through the existing termination paths.
This state does not schedule deletion, change billing, or modify user transfer.

## Security Notes
The new lifecycle writes no home marker, web-root rename, cache, or customer
page. State uses the existing atomic root:root 0640 config writer in its
root-controlled directory. It neither locks nor expires the account and does
not alter credentials, sessions, or SSH/SFTP configuration (see ADR 0053).
Managed-process stops preserve SSH/SFTP processes.

Dates require the exact four-two-two digit shape and `checkdate()`. The store
normalizes invalid dates away. Nginx bakes the validated date into a quoted
HTML response at render time; no query input or JavaScript is involved. The
notice contains neither the username nor the old/new server name. Shell
arguments use existing quoted command builders. The date is a public notice,
not a credential. An operational failure retains the saved parking marker,
returns nonzero, and can be retried; it does not silently reactivate services.

## Consequences
Old copies retain SSH/SFTP access while PMSS watchdogs keep managed services
parked. This is an operational state, not a sandbox: customers retain shell
access and can launch their own processes. Native managed watchdogs reassert
stops when run. No automatic deadline deletion is introduced.

## References
- PMSS #1136.
- ADR 0072: user transfer hands over rootless Docker containers.
- ADR 0053: panel session-cookie login.
