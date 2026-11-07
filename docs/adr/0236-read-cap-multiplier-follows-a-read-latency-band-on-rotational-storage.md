# ADR 0236: Read-cap multiplier follows a read-latency band on rotational storage

Date: 2026-11-07
Category: architecture

## Status
Accepted. Replaces the pressure-to-multiplier mapping of ADR 0230 and ADR 0231 on
rotational `/home`; reverses ADR 0231's rejection of "replace PSI with device
latency". Unknown storage keeps the pressure mapping; solid state follows
ADR 0237.

## Context
With read caps enforced (ADR 0231), four hosts whose caps bind were compared
with seven hosts without caps over the same clock hours (each host's change
divided by the controls' change). Read throughput fell 50-77%, `/home`
small-read latency fell 93-96% (on three; the fourth was already at 0.5 ms)
and disk utilization fell 25-52%, while host full-I/O PSI did not fall. Tasks waiting in the throttle count as stall, so PSI
stays high and keeps the multiplier at its busy value with spare disk capacity.
The `/home` ioping probe mostly reads drive cache, so its latency tracks queue
depth. Over 28 days before caps, hard-disk-backed virtual machines delivered
more read throughput as that latency rose to about 15-20 ms and no more beyond
it up to 35 ms; 20-25 ms sits at the start of that plateau.

## Options Considered
- Keep PSI and refine which stall counts (ADR 0231, #1249): each refinement
  leaves the input fed by the caps themselves; rejected for rotational disks.
- Map latency to a multiplier directly: under caps latency is low, so the
  multiplier jumps to idle, latency rises, it jumps to busy; the loop
  oscillates; rejected.
- Step the previous multiplier toward a latency band: chosen. Below the band
  the multiplier rises by a fixed factor each cycle, above it falls by the same
  factor, inside it holds. The latency it reads lags the multiplier by up to 15
  minutes; a factor of 1.25 is as wide as the band and cycled 18-29 ms in a
  model of that lag, a factor of 1.1 settled inside it. A saturated or degraded
  disk still pushes the multiplier to the busy value.
- A solid-state target from the service group's median latency: the node has
  no group latency data, and the fleet's solid-state hosts show no
  throughput-against-latency curve to place a band on; solid-state keeps the
  pressure mapping.

## Decision
- Settings `latencyLowMs` (20), `latencyHighMs` (25) and `latencyStep` (1.1)
  in `cgroup.dynamicIops`. Low below high, high at most 1000, step above 1 and
  at most 2; anything else rejects the settings as before.
- Latency is the mean of the valid `iopingHomeMs` probes in the last three
  five-minute samples of `iostat-history.log` (the O_DIRECT probe); a missed
  probe is skipped (about 1-3% of probes on two capped hosts over a day), and
  none in all three gives no latency.
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
  about 85 minutes (17 cycles); under real saturation it falls as fast.
- A virtual disk that reports itself rotational while backed by solid state
  is steered as a hard disk: its latency stays below the band, so its caps sit
  at the idle multiplier, which is the pre-cap idle behaviour.
- Judge the result by read throughput and latency against the band, not by PSI.
