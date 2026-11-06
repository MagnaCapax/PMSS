# ADR 0231: Read caps enforced by default; contention excludes the stall caps cause

Date: 2026-11-06
Category: architecture

## Status
Accepted. Supersedes ADR 0230's host opt-in and the shadow mode of ADR 0177;
changes ADR 0176's placement default.

## Context
ADR 0230 enforced read caps on hosts that opted in through local policy. In
production that left three problems:

- The product enforced nothing by itself. Mode, floor and placement lived in
  per-host local policy files, so every host needed hand configuration and the
  shipped default stayed `off`.
- Tasks waiting in the blkio throttle count as I/O stall. One account reading
  at its own cap raised host full-I/O PSI and iowait on a mostly idle disk, the
  pressure multiplier fell from idle to busy, and every cap on the host fell
  with it. Caps fed their own input. Measured: host PSI full avg300 above 70
  while member disks were 30-40% busy with normal read latency; the pool
  swung fivefold with no change in disk load.
- A floor of 15 delivers 10 reads per second, because the throttle delivers
  whole tens per 100 ms slice. Account web servers restarted under such a cap
  could miss their watchdog start limit.

## Options Considered
- Keep host opt-in: every host stays hand-configured; rejected.
- Gate PSI on device `%util`: utilization is not a saturation signal on RAID
  or multi-queue devices, and low utilization with high PSI can be real
  latency-bound load; rejected.
- Replace PSI with device latency: changes the allocator's named input;
  rejected.
- Measure PSI per account slice in the hybrid unified hierarchy and leave out
  accounts held at their own cap: chosen. With no account held the value is
  the host value exactly, so the allocator's behaviour before any cap binds is
  unchanged.
- Raise the passive ceiling to the maximum witnessed: md consistency checks
  read every member for hours each month, so a 31-day maximum would size the
  pool from sequential check reads; rejected. With contention measured
  correctly the idle multiplier already applies on quiet hosts.

## Decision
- `cgroup.dynamicIops.mode` defaults to `enforce`. `shadow` is removed; a host
  still set to `shadow` enforces. `off` remains the stop switch.
- `cgroup.ioSlicePlacement` defaults to `true` (ADR 0176 procedure unchanged:
  running tasks move at the next user-manager restart or reboot).
- Every read cap rounds up to the next multiple of ten, which is what the
  kernel delivers. The floor default stays 15 and is therefore applied as 20.
- Each enforce cycle reads `/proc/pressure/io` and the unified-hierarchy
  `io.pressure` of `system.slice` and every account slice. An account is held
  when its read rate over the last cycle (from `blkio.throttle.io_serviced`)
  was at least 90% of what its cap delivers. Contention is the host full avg300,
  or, when any account is held, the smaller of that and the largest full avg300
  outside held accounts. Each five-minute host PSI and iowait sample is scaled
  by contention / host from the cycle measured within 150 s of it before the
  existing 12/4/1 blends and the iowait curb. No unified hierarchy, no host PSI
  or no matching cycle leaves a sample unscaled.
- Cycle state (per-account read counters and an hour of contention rows) is
  `/var/run/pmss/dynamic-iops-contention.json`, mode 0600. Dry runs read it but
  never write it. Cycle log records carry `contention` (`host`, `contention`,
  `held` count).
- `off` clears the read cap of any account with no configured or derived cap
  (`MAJ:MIN 0`), audited with source `dynamic-iops-off`, so stopping
  enforcement no longer leaves dynamic caps behind on md-backed `/home`.
- The BFQ weight cron no longer runs an allocation cycle.

## Consequences
- Every host enforces after its next update. Placement reaches running tasks
  only after a user-manager restart or reboot.
- A host where only capped accounts stall keeps idle-level pools; genuine
  contention still shrinks the pool as before.
- If every active account on a saturated host is held at once, nothing
  unheld carries the stall and the pool grows until an account is released;
  watch the cycle log's `contention.held` and `pool` for oscillation.
- The panel pressure banner and host-level iowait figures still include
  throttle waits; they are unchanged here.
- Write caps and buffered-write behaviour are unchanged.
