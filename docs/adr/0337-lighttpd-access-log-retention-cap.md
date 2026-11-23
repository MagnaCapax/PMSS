# ADR 0337: Per-user lighttpd access log retention cap

Date: 2026-11-23
Category: privacy

## Status
Accepted

## Context
Each account's `~/.lighttpd/access.log` records the visitor address, the panel
user name and the full request line. It is personal data with no value once the
account owner has finished debugging their web setup (ADR 0097 keeps it for that
purpose). The hourly `lighttpdAccessLogTrim.php` cron only truncates a log that
passes 100 MiB, so a low-traffic account keeps its visitors' addresses for
months or years. Storage limitation means keeping personal data only as long as
its purpose needs; the purpose here is recent-window debugging.

## Options Considered
- Truncate once the file mtime passes the cap: rejected as the only rule. A log
  that is still appended to always has a fresh mtime, so it would only ever
  clear idle logs.
- Rotate with compression and several generations: rejected. It lengthens
  retention of personal data, the wrong direction for this log class.
- Rewrite the log keeping only recent lines: rejected. The logger appends
  through a separate process; a rewrite races with it and loses lines.
- Truncate once the oldest entry passes the cap, using the mtime only when the
  first line has no timestamp: chosen.

## Decision
`pmssLighttpdAccessLogTrimFile()` takes an age cap (default
`PMSS_LIGHTTPD_ACCESS_LOG_MAX_AGE_SECONDS`, 30 days). Under the same exclusive
lock, identity and single-link checks as the size cap, it reads the first line
(the oldest entry, the log being append-only) and truncates the whole file in
place when that entry's `[dd/Mon/yyyy:HH:MM:SS +zzzz]` stamp is older than the
cap. A first line without that stamp falls back to the file mtime. The size cap
keeps its precedence and result. A symlink or multiply-linked log is still
skipped, never followed. The cron logs an age trim separately from a size trim.
No compression and no extra generations are added.

## Consequences
- Retention of visitor addresses is bounded by the cap plus one hourly run. The
  debugging window is 0 to 30 days of entries and restarts after each trim.
- The first run after the update clears every log whose oldest entry is already
  past the cap, so accounts see their older entries disappear once.
- The account owns the file and can write its first line, so an owner can keep
  their own log past the cap; the size cap still applies. The cap protects
  visitors from indefinite server-side retention, not from the account owner.
- The 30-day figure is a retention-policy choice; it is the one constant to
  change if the operator wants a shorter window.
- The central nginx access log is a separate retention question and is not
  changed here.

## References
- #1018; ADR 0097 (per-user lighttpd logs); ADR 0044 (numeric logs, no privacy
  content).
