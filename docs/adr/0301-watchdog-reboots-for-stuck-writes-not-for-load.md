# ADR 0301: The watchdog reboots for stuck writes, not for load

Date: 2026-11-19
Category: architecture

## Status
Accepted. Supersedes the load-policy sentence of ADR 0075 ("this decision does not
change the load-reboot policy") and the rejection of "deleting the watchdog's load
rule" in the options of ADR 0296.

## Context

The managed watchdog configuration (ADR 0075) rebooted a host when its load average
passed 300 (1 minute), 225 (5 minutes) or 150 (15 minutes). The daemon applies its
retry timer to that check (`watchdog.c`, `attempt_repair` is called with `loadtimer`;
the man page's "load averages are exempt" is wrong for 5.16), so a breach that holds
for `retry-timeout` = 3600 seconds ends in an orderly reboot.

Load average counts every thread that is runnable or blocked on I/O. A host whose
disks are saturated by tenant reads and writes, or whose guest is thrashing on memory,
has a high load while still completing work. The daemon's journal on rebooted
hosts shows the cause as `loadavg <a> <b> <c> is higher than the given threshold
300 225 150!` on hosts that were busy, not dead. The rule reboots every tenant on the
host for what the tenants themselves do.

What the rule was kept for is a `/home` whose write path is wedged: the load stays
high and nothing else reboots the host. That condition can be observed directly. The
block layer counts writes completed and writes in flight per device. A wedged write
path has writes in flight and completes none; a busy one completes writes continuously
whatever its load.

A second cause of unwanted resets is the hardware heartbeat. The daemon must write the
watchdog device inside the device timeout (60 s). Under memory pressure an unlocked
daemon can be paged out and miss the write, so the hardware resets the guest with no
clean shutdown. The daemon has `realtime = yes` for this: it locks its memory
(`mlockall`) and runs at real-time priority (`lock_mem.c`).

The daemon reads its configuration and its test directory only at start, and the
installer ran `systemctl enable --now`, which leaves a running daemon untouched. A
change to either never reached a live host before its next boot.

## Options Considered

- Raise the load thresholds: the same signal with a larger number. Rejected, it
  tunes a measure that is not what the rule is for.
- Delete the load rule with no replacement: stops the harm, loses the reboot for a
  wedged write path. Rejected in ADR 0296 for that reason.
- Replace it with a test of the thing the rule stood in for (chosen): reboot on no
  write progress while writes are pending, sustained.
- A probe that writes a file under `/home`: it blocks on exactly the condition it
  tests, and writes tenant data. Rejected.
- Pressure-stall information (PSI) or idle-disk heuristics: they also fire on a host
  that is busy with reads. Rejected; see Consequences for what this leaves uncovered.

## Decision

1. `template.watchdog.conf` drops `max-load-1`, `max-load-5` and `max-load-15`.
   No load threshold remains. It adds `realtime = yes` (priority stays at the daemon
   default of 1). `watchdog-timeout`, `interval`, `retry-timeout` and `min-memory` are
   unchanged.
2. A new test, `/etc/watchdog.d/home-write-check.sh`, installed from
   `template.watchdog.home-write-check.sh`. It is a short shell shim around the PHP
   logic in `template.watchdog.home-write-check.php` (run in place from
   `/etc/seedbox/config` with `php -n`). The shim exists because the daemon reads a
   test's exit status literally and 255 means "reboot now", which is also the status of
   a PHP fatal error or parse error (a half-copied script during an update is one). The
   shim passes only status 5 through and turns every other status, a missing
   interpreter's 127 included, into 0.
3. The logic exits 5 only when, for the device that `/home` is mounted from (the
   last `/proc/mounts` entry for `/home`; `/` when `/home` is not a mount):
   - `/sys/class/block/<device>/inflight` shows at least one write in flight, and
   - the writes-completed counter in `/proc/diskstats` has not changed for 300 seconds
     of samples.
   A sample with a completion, with no write in flight, or with a changed counter
   closes the window. State is two numbers and a flag in `/run`. The top device's own
   counters are used: a write held up on any member below an md or dm device keeps that
   device's writes from completing, so no walk of the members is needed.
4. The window is 300 seconds. It is five times the `/home` journal commit interval
   (60 s on the production mounts), long enough to ride out a member link reset or a
   long journal commit. The daemon then keeps the failure for its `retry-timeout` of
   3600 seconds before acting, so a wedged write path is rebooted after about 65
   minutes, as the load rule did, but for the right condition.
5. The test never reads or writes anything under `/home`. Anything it cannot read or
   decide (no `/dev` node behind the mount, unreadable counters, an unwritable state
   file, any exception) is "not stuck", exit 0, and is logged once per episode to
   `/var/log/pmss/checkHomeWriteWatchdog.log` and syslog. Entering and leaving the
   stuck state is logged with the device, counters, load average and I/O pressure, so
   the reason for a reboot survives it.
6. The installer installs the shim with mode 0755, and runs `systemctl stop
   watchdog.service` then `systemctl start watchdog.service` after `enable --now`
   only when the unit was already active and `/etc/watchdog.conf` or an installed test
   changed. A run with nothing new does not touch the daemon. Not `systemctl restart`:
   Debian's unit fails its `ExecStopPost` on purpose so that `OnFailure=` starts
   `wd_keepalive`, which `Conflicts=` with `watchdog.service` and cancels the start half
   of a restart, leaving the daemon dead with only the heartbeat keeper running (seen
   on a Debian 12 guest, watchdog 5.16-1+b2). The start after a stop replaces the keeper.

## Consequences

- Positive: a host that is busy or thrashing and still completing writes is not
  rebooted by PMSS. The reboot that remains for a wedged write path keys on the wedge.
  The daemon is locked in memory, so memory pressure alone does not starve the
  heartbeat through paging. A watchdog change now reaches running hosts at the next
  update instead of the next boot.
- Negative: a wedge in which nothing is in flight at the block layer (writes queued
  above it, for example by a per-account I/O throttle) is not detected; the load rule
  rebooted such a host, though it did not clear the cause, which returned after boot.
  A failure that stops every process from starting for the full retry period still
  fails the test (the daemon's 60 second test timeout) and ends in a reboot. Daemon
  children (the tests) inherit the real-time scheduling policy; both tests are short
  and are killed at the daemon's test timeout. `realtime` does not help when the
  daemon's own `fork()` for a test blocks in memory reclaim; bounding tenant memory
  (ADR 0296) is the answer to that.
- Follow-ups: restart behaviour is verified on a host with a watchdog device at
  rollout, not by the repository tests. A reset-count check per host after rollout
  shows whether `realtime` removed the heartbeat resets.
- Follow-ups: the QA proof of the stuck-write test used a `dm-delay` device. Whether
  `/sys/class/block/<md device>/inflight` and the `/proc/diskstats` write counter
  move with real load on the md array behind `/home` is checked on the first rollout
  host (read both under tenant load before the rest follow). If the in-flight count
  stays 0 there, the test never fires and the host has no stuck-write reboot at all.
  The test and the shim fail open by design, so that state is silent: the shim maps an
  interpreter failure to "not stuck" without a log line.

## References

- ADR 0075 (watchdog service follows device and operator mask), ADR 0296 (aggregate
  memory bound on the user slice).
- watchdog 5.16 source: `watchdog.c` (`attempt_repair`), `test_binary.c`
  (`check_bin`, `update_process`), `lock_mem.c`, `configfile.c`; `watchdog.conf(5)`,
  `watchdog(8)`, `sched(7)`.
