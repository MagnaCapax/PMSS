# ADR 0232: Account read-limit status in the panel; slice directories private to their account

Date: 2026-11-06
Category: architecture

## Status
Accepted. Closes the panel parts of ADR 0230 (issues #1243, #1244) and the cgroupfs
read path of #1245.

## Context
Read caps (ADRs 0230, 0231) change with load, and an account at its cap only sees slow
reads. The panel showed neither the limit nor that the account was held at it.

The account could read its cap from cgroupfs, but so could every other account: slice
directories are created 0755 and their files 0444 or 0644, so any account can list
`user-UID.slice` in every hierarchy and read another account's counters, memory figures
and read cap. The D-Bus path to the same figures was already closed (ADR 0118).

## Options Considered
- Panel reads its own cap from cgroupfs: works only while cgroupfs stays world-readable,
  and gives no held signal without a second sample.
- Panel samples counters across page loads: nothing on a first visit, and still needs
  cgroupfs.
- The enforce cycle publishes a per-account status file the account can read: chosen.
  The cycle already has the delivered cap and, from ADR 0231, whether the account was
  held during the last cycle.
- For cgroupfs: chmod once at slice creation in systemd configuration (no hook exists
  for v1 slice directories), or a mount option (none limits per-directory reads);
  re-applying modes from the existing IOPS cron: chosen.

## Decision
- Each enforce cycle writes `~/.readLimit` (JSON: `read_limit`, `held`, `updated`) for
  every account with a real cap, root-owned 0640 with the account's group, through the
  guarded root-stage writer used for `~/.quota`. `read_limit` is the delivered rate,
  `10 * floor(cap / 10)`; caps at or above 1,000,000 are placeholders and publish nothing.
- The Info tab shows the current limit as a neutral row, plus a short explanation while
  `held` is true. A missing, malformed or stale status (older than 15 minutes) shows
  nothing, so stopped or unenforced hosts show no row.
- Each IOPS cron run sets every account's `user-UID.slice` directory, in every mounted
  hierarchy, to root:<account group> 0750 and each `user.slice` to 0711. The owner and
  root keep their reads; other accounts can neither list slices nor read them. Accounts
  or groups below 1000 and directories not owned by root are never changed.

## Consequences
- A slice systemd recreates is world-readable until the next IOPS run (up to five
  minutes).
- Readers running as other non-root users lose access to account slices; PMSS has none.
- The status file is one more root write into each account home per cycle, through the
  same guarded writer as the quota snapshot.
