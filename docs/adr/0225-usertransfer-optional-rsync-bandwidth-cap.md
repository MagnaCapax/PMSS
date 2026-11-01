# ADR 0225: User transfer takes an optional rsync bandwidth cap

Date: 2026-11-01
Category: architecture

## Status
Accepted

## Context
A large migration onto a busy shared host can saturate the target's disks: an
unthrottled multi-terabyte pull at roughly 45 MB/s has driven a target's io PSI
full avg60 from about 10 to 50-60 within minutes, so such copies get stopped
and run in short windows. The host could not throttle it: production
pins cgroup v1 (ADR 0019), where blkio throttling does not cover buffered
writeback, and `ionice` reaches only the I/O a process issues itself, not the
kernel flush threads that write rsync's data. `userTransfer.php` offered pass
counts and sleeps but no rate control, so the only remaining lever was the copy
itself.

## Options Considered
- Host-level limits (`systemd-run -p IOWriteBandwidthMax=`, blkio, ionice):
  ineffective for buffered writes on cgroup v1.
- Stop-start windows driven by a PSI watcher outside PMSS: works, but moves
  about 26 GB per window and needs an external scheduler for every large copy.
- An optional `--bwlimit` CLI option passed to every rsync pass: rsync forwards
  the cap to the remote sender (`server_options()` in rsync's `options.c`), so
  the data rate, and therefore the target's average write rate, is bounded at
  the source of the data.

## Decision
`userTransfer.php` accepts `--bwlimit KIBPS` (`--bwlimit=KIBPS`), an integer
1..10000000 in KiB/s, rsync's own default unit. When given, both the main and
the final rsync builders add `--bwlimit=KIBPS`; nothing else changes. When
omitted, the configuration keeps its legacy shape and rsync runs unlimited as
before. Non-integer, suffixed, zero or out-of-range values are refused before
any network access.

## Consequences
- Operators can run a large migration continuously at a rate the target host
  tolerates, instead of in stop-start windows.
- The cap bounds the average network rate. rsync sends in bursts and the target
  still flushes in bursts, so short PSI spikes remain possible; the operator
  still watches the target and picks the rate.
- Restarting with a different cap is a normal resume: the copy is target-pull
  rsync without delete.
- The default is unchanged, so existing callers and automation are unaffected.

## References
- ADR 0019 (production cgroup v1 pin), ADR 0214 (completed or newer targets).
- `scripts/lib/userTransfer/cliParse.php`, `scripts/lib/userTransfer/remoteScripts.php`.
