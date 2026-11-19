# ADR 0296: Aggregate memory bound on the shared user slice

Date: 2026-11-18
Category: architecture

## Status
Accepted

## Context

PMSS writes a `MemoryMax` per account (`user-<uid>.slice`, ADR 0192, sized in
`systemdSliceSizing.php`). Nothing bounds the sum of the accounts. When the limits
sold to accounts add up to more than the guest's RAM, or one account is sold a limit
above the guest's RAM, that account's cgroup can never reach its own limit before
the guest runs out of memory. The guest then evicts page cache, including the code
of running programs, and thrashes: load climbs past the watchdog's `max-load`
rules, sshd stops answering and the watchdog resets the guest. One tenant's memory
use becomes a node outage for every tenant on it.

All tenant services run under `user.slice`: `serviceLaunch.php` starts them with
`systemd-run --scope --slice=user-<uid>.slice`, and systemd places
`user-<uid>.slice` under `user.slice` by name. Existing readers already rely on
that path (`scripts/cron/metricsLog.php`, `scripts/cron/cgroupBfqWeightApply.php`).
`system.slice` (sshd, journald, cron, the watchdog, PMSS root jobs) is a sibling,
so a limit on `user.slice` is the one place where the kernel can stop tenants
before it touches the services that keep the node reachable.

## Options Considered

- A per-account clamp to guest RAM: would cap an account below what it was sold.
  Rejected, per-account limits are not changed by this ADR.
- A user-space OOM daemon (earlyoom, systemd-oomd): a new runtime component with
  its own policy and failure modes, for a result the kernel gives with one line.
- Deleting the watchdog's load rule: it recovers a guest that is really wedged;
  with the bound the thrash does not start in the first place. (ADR 0301 later
  replaced the load rule with a test for a stuck write path, so a busy guest is no
  longer rebooted and a wedged one still is.)
- One `MemoryMax` on `user.slice`, rendered by the existing slice step (chosen),
  following ADR 0176's pattern of one aggregate slice as the single enforcement
  point.

## Decision

`pmssEnsureSystemdSlices` renders one more drop-in, from the template
`etc/seedbox/config/template.cgroup.user-slice.aggregate.conf`, into
`/etc/systemd/system/user.slice.d/15-pmss-aggregate.conf`:

```
[Slice]
MemoryAccounting=yes
MemoryMax=<bound>M
```

`<bound>` = full RAM minus a reserve, in MiB:

- **Full RAM** is MemTotal plus what the virtio balloon holds now
  (`balloon_inflate` minus `balloon_deflate` in `/proc/vmstat`, 4 KiB pages). A
  ballooned guest's MemTotal shrinks with the balloon; a bound taken from it at the
  floor would cap the tenants far below the RAM the host gives back later, and would
  stay there until the next update run. On a guest without a balloon both counters are
  0 and full RAM is MemTotal.
- **Reserve** = `max(1536, min(ceil(10% of full RAM), ceil(1.25 x min_free) + 512))`,
  with `min_free` the live `vm.min_free_kbytes` in MiB (1024, the PMSS floor, when it
  cannot be read). `1.25 x min_free` is about the kernel's high watermark, where
  global reclaim already holds the tenants; 512 MiB is room for `system.slice` and the
  kernel above it. A reserve much larger than that puts the bound below where the
  tenants sit in normal operation, so the bound caps their page cache all the time;
  a flat 10% does that on every guest above about 18 GiB (6.4 GiB reserved on 64 GiB
  against a natural reserve near 2 GiB).

The reserve is rounded up, so the bound is rounded down. With the 1 GiB min_free floor:
24576 MiB gives 22784 MiB, 131072 MiB gives 129280 MiB, 8192 MiB gives 6656 MiB (the
1536 MiB floor). When the result is below 1024 MiB (hosts under 2.5 GiB) or MemTotal is
undetected, no bound is written, a warning is logged and an existing drop-in is left as
it is. A missing or unreadable template never replaces a working drop-in. The rendered
value is logged: `user.slice aggregate MemoryMax=<bound>M (MemTotal=..., balloon=...,
min_free=..., mode=...)`.

Both cgroup modes render the same bytes. On v1 (the production pin, ADR 0019) systemd
applies `MemoryMax` to the memory controller's limit; on v2 it writes `memory.max`.
This follows the per-account drop-ins, which also use `MemoryMax` on v1 hosts. v2 gets
no new feature work here beyond the mode being accepted.

This bound is a physical bound, not an account limit. No per-account value moves:
`systemdSliceSizing.php` and the per-user drop-ins are untouched, and an account keeps
every byte of the limit it was sold. The bound only takes effect when the tenants
together want more memory than the guest has, which no setting can deliver. Capacity
is the host's job (the sale path must not sell more than a guest can hold); this
bound makes the case where it does not non-fatal for the node. Inside `user.slice`
the kernel's memory-cgroup OOM killer picks a victim among tenant processes only.

The reserve is a documented choice, not a measurement of every host. The bound counts
everything under `user.slice`, including root login sessions (`user-0.slice` keeps
`MemoryMax=infinity` of its own, which only means it adds no limit of its own).

## Measurements (cgroup v1, Debian 12 kernel 6.1, 7.9 GiB test guest, swap on)

Runaway tenant (one account allocating past the guest's RAM, another account busy):

- Bound at the 1536 MiB floor, stock min_free: the memory-cgroup OOM killer stopped
  the runaway in 21 of 21 runs, 22 to 127 s after it started. The other account
  survived every run and the guest never reset.
- Bound at 90% of RAM: 3 of 3 runs, killed after 28, 29 and 87 s, other account
  survived, no reset. In the 87 s run new root logins failed for about 30 s.
- One earlier run on a differently prepared guest thrashed for more than 195 s before
  the kill; its cause was not found.
- Without the bound the runaway exhausted the guest.

Normal operation (page cache filled, one account writing sequentially, another reading
at random, 6 runs per arm, interleaved):

- Bound at 90% of RAM, just above where global reclaim held the tenants: the bound never
  engaged (`memory.failcnt` 0 in every run). Reader p99 and writer throughput matched
  the unbounded arm (1.07x and 1.11x). Reader p99.9 was higher with the bound in place
  (about 11x on the medians, p ~ 0.09 on 6 runs per arm). The bound did nothing in
  those runs, so the cause is not the bound, and it is unexplained.
- Bound 9% of RAM below where the tenants sat (min_free lowered for the test): the bound
  engaged about 80k times per run, reader p50 was 9x and p99 1.86x slower, and the
  reader lost its cache hits in half the runs. This is the cost the watermark cap on
  the reserve avoids.

Not measured: guests above 7.9 GiB, real HDD arrays under tenant load, and the
watermark-capped values on large guests. The rollout reads `user.slice`
`memory.failcnt` over a day on each updated host: near 0 means the bound costs nothing
in normal operation there.

## Consequences

- One template and one small module; the per-account limits, the legacy shadow and the
  root drop-in are unchanged. `SystemdSlicesCharacterizationTest` gains the aggregate
  step: the 16 traces that reach it were re-captured on a testing machine and the three
  that stop before it keep their original hashes.
- Idempotent: an unchanged drop-in is not rewritten. A guest resized in RAM follows
  on the next update; balloon movement does not change the value.
- A ballooned guest held below its full RAM gets no protection from the bound while it
  is held there: the bound sits above the RAM it has, as if there were no bound. The
  remedy for such a guest is host RAM, not a bound below what the guest can be given.
- Root login sessions sit inside `user.slice`, so while a runaway tenant holds it at the
  bound, new root logins can stall until the kernel kills the runaway (seen for about
  30 s). Logins stalled the same way without the bound. Moving root sessions out of
  `user.slice` is a separate change to PAM or the SSH stack and is not part of this ADR.
- An oversold account is stopped by the kernel inside `user.slice` before the guest
  thrashes, and `system.slice` keeps its share. The account still sees its own
  limit; the sale-path capacity check is the separate fix for the cause.
- Kernel OOM kills inside `user.slice` show up in the journal as
  `Memory cgroup out of memory`. Surfacing them to the account is a separate issue.
- Hosts on a kernel built without the v1 memory controller (stock Debian 6.12, see
  ADR 0019 context) get no effect from the bound, as they get none from the per-account
  limits.
- Rollback: delete `/etc/systemd/system/user.slice.d/15-pmss-aggregate.conf` and run
  `systemctl daemon-reload`. A later update renders it again; a lasting rollback is
  reverting this change. Hosts on code from before this ADR keep a file already written.

## References
- [Aggregate cgroup I/O placement](0176-opt-in-aggregate-cgroup-v1-io-placement.md)
- [Systemd slice setup policy boundaries](0192-systemd-slice-setup-policy-boundaries.md)
- [Per-UID slice drop-in precedence](0160-per-uid-slice-dropin-precedence-and-cleanup.md)
- [Production cgroup v1 pin](0019-production-cgroup-v1-pin.md)
