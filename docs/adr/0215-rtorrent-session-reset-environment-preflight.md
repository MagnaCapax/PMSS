# ADR 0215: Preflight the environment before automatic rTorrent session reset

Date: 2026-10-30
Category: architecture

## Status
Accepted

## Context
The missing-process watchdog resets an account's session after three failed
starts. A failure count cannot distinguish corrupt session data from exhausted
storage or unavailable startup prerequisites. Renaming a healthy session in
those conditions leaves the account with an empty torrent list after recovery.
The existing quarantine message reaches cron output but lacks an account log.

## Options Considered
- Keep using the failure count alone: environmental failures can move healthy data.
- Add conservative environmental gates before the existing recovery action.
- Automatically merge quarantined data on a later start: the same data may be
  corrupt, and a successful empty-session start does not establish its health.

## Decision
Add a preflight only to the watchdog's automatic reset branch. Retain all
existing path guards, failure thresholds, reset-marker behavior and escalation.
Check free space for the home and existing session directory, rejecting failed
measurements as well as exhausted space. Use the established bounded account
path runner to check startup prerequisites and write one byte to a private
temporary file in each directory. This catches account quota, reserved-block,
inode, permission and read-only filesystem failures that root access checks
alone cannot establish. Temporary files are removed on completion or trapped
shell exits; a forced kill may leave a private probe file behind.

The account probe checks the main rc, system fallback binary and screen. When
present, it also checks the localnet filter and executor's startup files and PHP.
The system binary is required conservatively even for a selected store build,
because the executor can fall back to it. The probe has a ten-second deadline;
a timeout records the existing rTorrent watchdog backoff. Any preflight failure
leaves the session in place and proceeds through the existing retry ladder.
Mirror reset diagnostics to the account PMSS log so a successful quarantine's
backup path is recorded there as well as in cron output.

## Consequences
- This addresses the identifiable environmental failure classes in #1223.
  Passing the preflight does not prove session corruption: invalid custom
  directives, binary loading errors and transient failures can still occur.
- Existing quarantines are preserved; automatic restoration and a panel notice
  remain outside this change. No sessions are merged or processes restarted by
  the preflight. It adds no dependencies or host configuration migration.
- Hermetic cases cover storage failures, rejected paths, account-probe failures,
  timeouts, missing optional files, retry thresholds and backup-path logging.
  Run behavior tests on the testing machine. Local verification is limited to
  changed-file PHP syntax; no live recovery or destructive dry run is performed.

## References
- Refs #1223
- [Watchdog transition boundaries](0212-rtorrent-watchdog-transition-boundaries.md)
- [Script contracts](../contracts.md)
