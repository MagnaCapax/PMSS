# ADR 0231: Read caps enforced by default; contention excludes the stall caps cause

Date: 2026-11-06
Category: architecture

## Status
Accepted; on rotational storage the multiplier follows ADR 0236. Supersedes ADR 0230's host opt-in and the shadow mode of ADR 0177;
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

## Amendment 2026-11-06: held at the rate the throttle actually delivers (#1249)
- Measured in production on two hosts: readers the throttle keeps waiting do
  not get their whole cap; per cycle they read 76-90% of it. The 90% test
  missed them in most cycles, so with no account held contention fell back to
  the host value and the multiplier stayed at its busy floor while the disk was
  about 14% busy.
- An account is now held at 75% of what its cap delivers. The panel notice
  (ADR 0232) follows the same test.
- An account held in the previous cycle (within 15 minutes) stays out of
  contention one cycle more, because full avg300 still carries about a third
  of that cycle after a cap rise or release. Cycle state records the held
  UIDs (`held`); cycle log rows add `excluded`, the slices left out of
  contention, beside `held`.
- Not changed: stall that is not an account's own cap still counts. On one
  measured host, account tasks also waited on page locks, ext4 metadata reads
  and journal access, and system services on root-disk journal commits, with
  reads far under their caps. PSI does not attribute stall to a device, so
  that host keeps a busy-level multiplier until that stall is explained.

## Amendment 2026-11-06 (2): contention leaves out readers at half their cap (#1249)
- Measured on three busy hosts after the first amendment, pairing each capped
  account's share of its cap over a cycle with its own full avg300: every
  account at 60-100% of its cap stalled 35-92%; every account at 36% or less
  stalled 23% or less. On one host the only account near its cap read at 60% of
  it and carried the whole host stall, so the 75% test never left it out and
  its own cap stall pinned the pool at the busy floor, shrinking every cap
  including its own. The throttle delivers whole tens per 100 ms, so a bursty
  reader waits on its cap well below its average limit.
- Contention now leaves out accounts that read at half or more of what their
  cap delivers (and, as before, accounts left out in the previous cycle).
  Cycle state `held` lists those UIDs; cycle rows keep `held` (the 75% test)
  and `excluded` (slices left out).
- The panel notice (ADR 0232) keeps the 75% test: its text says the account
  is reading at its limit now, which a reader at half its cap is not.
- Stall of accounts below half their cap and of system services still counts.
  The light readers on the measured hosts kept contention at about 10-23, so
  disk stall that is not an account's own cap still reaches the pool. The
  system term stays: when left-out readers saturate the disk themselves, it is
  the only remaining brake, and different block devices for / and /home do not
  prove different disks (md members, VM backing storage).
