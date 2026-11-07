# ADR 0236: Read-cap multiplier follows a read-latency band on rotational storage

Date: 2026-11-07
Category: architecture

## Status
Accepted. Replaces the pressure-to-multiplier mapping of ADR 0230 and ADR 0231 on
rotational `/home`; reverses ADR 0231's rejection of "replace PSI with device
latency". Solid-state and unknown storage keep the pressure mapping.

## Context
With read caps enforced (ADR 0231), hosts whose caps bind were measured against
seven hosts without caps over the same clock hours. Where caps bind, total read
throughput fell 40-70%, `/home` small-read latency fell from about 11-25 ms to
1-8 ms and disk utilization fell, while host full-I/O PSI did not fall. Tasks
waiting in the throttle count as stall, so PSI stays high and keeps the
multiplier at its busy value even when the disk has spare capacity. Hard disks
without caps run at about 20-25 ms small-read latency at about half
utilization; that band is where they deliver the most work at an acceptable
wait.

## Options Considered
- Keep PSI and refine which stall counts (ADR 0231, #1249): each refinement
  leaves the input fed by the caps themselves; rejected for rotational disks.
- Map latency to a multiplier directly: under caps latency is low, so the
  multiplier jumps to idle, latency rises, it jumps to busy; the loop
  oscillates; rejected.
- Step the previous multiplier toward a latency band: chosen. Below the band
  the multiplier rises by a fixed factor each cycle, above it falls by the same
  factor, inside it holds. It settles where the disk's own latency sits in the
  band, and a saturated or degraded disk still pushes it to the busy value.
- A solid-state target from the service group's median latency: the node has
  no group data today; solid-state keeps the pressure mapping until a group
  target is delivered.

## Decision
- Settings `latencyLowMs` (20), `latencyHighMs` (25) and `latencyStep` (1.25)
  in `cgroup.dynamicIops`. Low below high, high at most 1000, step above 1 and
  at most 2; anything else rejects the settings as before.
- Latency is the mean of the last three five-minute `iopingHomeMs` samples in
  `iostat-history.log` (the O_DIRECT probe); fewer than three contiguous
  samples gives no latency.
- When `/home` resolves to `hdd` (md arrays by their members) and latency is
  present, the multiplier is the previous cycle's multiplier times the step
  below the band, divided by it above the band, unchanged inside, bounded by
  `busyMultiplier` and `idleMultiplier`. With no previous multiplier from the
  last 15 minutes it starts from the pressure multiplier.
- The previous multiplier is `/var/run/pmss/dynamic-iops-steer.json`, mode
  0600, written only when the cycle applied caps. Records carry
  `steer` (`latency` or `pressure`) and the latency in `pressure.latency_ms`.
- Missing pressure, the iowait curb, floors, ceilings and fallbacks are
  unchanged.

## Consequences
- On a hard disk with spare capacity the multiplier rises from busy to idle in
  about 40 minutes (eight cycles); under real saturation it falls as fast.
- A virtual disk that reports itself rotational while backed by solid state
  is steered as a hard disk: its latency stays below the band, so its caps sit
  at the idle multiplier, which is the pre-cap idle behaviour.
- Judge the result by read throughput and latency against the band, not by PSI.
