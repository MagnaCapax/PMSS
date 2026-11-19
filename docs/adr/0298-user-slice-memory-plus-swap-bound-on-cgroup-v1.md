# ADR 0298: Count swap inside the user.slice memory bound on cgroup v1

Date: 2026-11-19
Category: architecture

## Status
Accepted

## Context

ADR 0296 puts one `MemoryMax` on `user.slice` so the kernel stops a runaway tenant inside the
slice before it exhausts the guest. On cgroup v1 (ADR 0019) that works, but late. systemd writes
only `memory.limit_in_bytes` there (v252 `core/cgroup.c`, legacy branch; `MemorySwapMax=` is applied
on the unified hierarchy only, `MemoryHigh=` is not applied on v1, `systemd-oomd` requires the
unified hierarchy). The guest's own swap-out keeps moving the runaway's pages to swap, so the
slice's RAM figure sits at the bound while the runaway keeps growing. The kernel reclaims for
minutes before it kills, load climbs and new root logins stall (a QA VM, 2026-11-18, ADR 0296
experiments).

The kernel decides this in `try_charge_memcg` (`mm/memcontrol.c`, v6.1): a charge that overflows
the memory+swap counter (`memory.memsw.*`) reclaims without swap (`MEMCG_RECLAIM_MAY_SWAP`
cleared) and goes on to the OOM kill; an overflow of the memory counter alone may swap.

## Options Considered

- `memory.swappiness=0` on `user.slice` and every descendant: measured below, no useful effect
  (the kernel still swaps tenant pages out under guest-wide pressure, and the write must reach
  every existing descendant).
- `MemorySwapMax=`, `MemoryHigh=`, `systemd-oomd`: not available on v1 (source above).
- A PSI-driven user-space killer: a new daemon or a watchdog test (the watchdog is a separate
  change); not needed, because the one kernel counter below reaches seconds.
- `memory.memsw.limit_in_bytes` on `user.slice` equal to its memory limit (chosen).

## Decision

`scripts/cron/cgroupUserSliceMemswApply.php` copies systemd's configured `MemoryMax` of
`user.slice` to `memory.memsw.limit_in_bytes`. It runs at boot, every five minutes (the same
self-heal slots as `cgroupBfqWeightApply.php`) and once after the slice step of `update.php`.

- The kernel refuses a memory limit above the memsw limit, so when the bound is raised systemd's
  own write is refused (`Failed to set 'memory.limit_in_bytes' ... Invalid argument`, seen on a
  QA VM). The script therefore writes both files from systemd's configured value: memsw first
  when raising, memory first when lowering, `-1` on both for `infinity` (rollback of ADR 0296).
- A lowering of the memsw limit below what the tenants hold as anonymous memory, shared memory and
  swap is not written (the kernel would purge all page cache and then fail); the script logs it and
  retries on the next run.
- The write goes through `timeout 30 tee`. A host without `memory.memsw.limit_in_bytes` (cgroup v2,
  kernel without the v1 memory controller) exits 0 with an INFO line. Output goes to
  `/var/log/pmss/cgroupUserSliceMemswApply.log` (rotated).
- No limit is raised or removed. `pmssEnsureSystemdSlices` and the per-account limits are unchanged.

## Measurements (a QA VM: Debian 12 kernel 6.1.0-50, 7940 MiB RAM, 975 MiB swap, cgroup v1, ADR 0296 bound 6404 MiB, vm.min_free_kbytes 1 GiB)

Slow leak, one account allocating about 200 MiB/s, another holding 300 MiB; root ssh login probed every 2 s:

| Arm | Hog killed after start | Root logins failed |
|---|---|---|
| ADR 0296 only (negative control) | 176.5 s, 218.5 s, 278.8 s | 15/40, 20/39, 25/56 |
| memory.swappiness 0 on the slice tree | 154.0 s (one run) | 14/30 |
| memsw limit = memory limit, written by hand | 23.3 s, 29.3 s, 22.1 s (5-7 s after the bound was hit) | 0/19, 1/18, 0/17 |
| memsw limit written by the shipped script | 35.5 s, 20.8 s, 31.0 s | 2/19, 0/17, 1/19 |

As fast as possible: 6.3 s without, 5.3 s with. A tenant holding about 4 GB (below the bound) for
90 s was not touched (0 of 54 probes failed). The other account survived every run.

## Consequences

- Swap now counts inside the bound: tenants whose anonymous memory plus swap exceeds it are killed
  inside `user.slice` instead of thrashing on swap. This is ADR 0296's intent made timely; it also
  means a guest that used to limp along on swap beyond the bound loses the processes the kernel picks.
- A guest whose tenants already hold more than the bound when the script runs stays on today's
  behaviour until that falls below the bound (the log line says so).
- The cron window after boot (about a minute) runs without the memsw limit, as the BFQ weights do.
- Not measured: guests above 7.9 GiB, real HDD arrays under tenant load, v2 hosts (no effect, no
  error expected). The `update.php` install path was run on a QA VM (2026-11-19): the step
  logged the memsw write and rc 0, the cron and logrotate entries were installed, and both limits
  matched `MemoryMax`.
- Rollback: `echo -1 > /sys/fs/cgroup/memory/user.slice/memory.memsw.limit_in_bytes`, remove the
  cron lines; a lasting rollback is reverting this change.

## References
- [Aggregate memory bound on the shared user slice](0296-aggregate-memory-bound-on-the-user-slice.md)
- [Production cgroup v1 pin](0019-production-cgroup-v1-pin.md)
- [Aggregate cgroup I/O placement](0176-opt-in-aggregate-cgroup-v1-io-placement.md)
