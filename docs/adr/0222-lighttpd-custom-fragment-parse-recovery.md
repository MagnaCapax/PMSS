# ADR 0222: Recover lighttpd from a repeatedly unparsable customer fragment

Date: 2026-10-31
Category: domain

## Status
Accepted

## Context
One invalid `~/.lighttpd/custom.d/*.conf` fragment prevents the entire account
panel from starting. The watchdog otherwise retries the same broken config
indefinitely. ADR 0032 reserves this area for customer configuration, so recovery
must preserve the file and explain how the customer can restore it.

## Options Considered

- Keep retrying indefinitely: preserves every include but leaves the panel down.
- Set aside every fragment on any launch failure: loses unrelated routes and
  mistakes quota, permission or service-launch problems for syntax failures.
- Require repeated file-specific parser evidence and set aside only that file.

## Decision
After an ordinary watchdog start leaves lighttpd absent, run a bounded
`lighttpd -t -f` against the complete config as the account, from its home.
Require three consecutive unsuccessful, non-timeout parses naming one exact
direct child of that account's `custom.d` directory. Use three as the fixed
recovery threshold; a different file, inode or content starts a new sequence.
Live lighttpd, timeout/backoff, successful parses and unattributed failures reset
the sequence. Keep the fingerprint and count in the validated private watchdog
runtime directory. A failed write cannot authorize recovery.

Only a single-link regular file owned by the account, at a path with no symlink
components, is eligible. Recheck its inode and content in a bounded account-UID
command before moving it to `.conf.disabled-<timestamp>-<pid>`. Preserve existing
destinations and file metadata. Log the source, destination, repeated syntax
failure and instruction to repair and rename the fragment back. Never log file
contents or raw parser text in that recovery message.

The cron retries the ordinary starter once after a successful move; it never
sets aside a second fragment in the same pass. The starter still parses the
full remaining configuration, and all existing validation, slice placement,
timeouts and restart gates stay active. Managed config and the legacy `custom`
file are never moved. No validator is relaxed.

## Consequences

- The panel can recover while the offending fragment's routes remain unavailable.
  Repair and restoration are manual; the original content is retained.
- Ambiguous diagnostics, unusual fragment names outside the accepted ASCII
  filename shape, unsafe ownership/paths and files over 1 MiB retain ordinary
  retries for operator or customer repair. Renaming failure is logged.
- An unsuccessful start adds one bounded syntax check. No deployment, new
  listener, certificate, package or customer-to-operator include is introduced.
- Hermetic fixtures cover evidence parsing, count/reset behavior, preserved
  metadata and siblings, restoration, unsafe paths, failed persistence, changed
  content during recovery and backup collisions. Devbox verification uses only
  changed-file PHP syntax checks; the lane and CI run the suite on a testing
  machine. No live service or host dry run is performed here.

## References

- Refs #1109; related #858 and #919.
- [ADR 0032](0032-system-managed-per-user-lighttpd-config-belongs-in-template.md).
- [Watchdog contract](../contracts.md#utilities-script-contracts).
