# ADR 0237: Read-cap multiplier on solid state follows a band tied to the group median latency

Date: 2026-11-07
Category: architecture

## Status
Accepted. Supersedes the solid-state clause of ADR 0236 ("solid-state keeps the
pressure mapping"). Unknown storage keeps the pressure mapping.

## Context
Throttle wait counts as I/O stall on solid state as it does on hard disks
(ADR 0236). On one solid-state host with caps applied, over a day the pool
multiplier sat at its busy value 2 at the median and PSI blend was 26 at the
median, against 1.1 before caps, while the disk was under 1% utilized and
answered `/home` reads in 0.2 ms. The caps there hold reads back from a disk
with spare capacity.

Solid-state read latency has no fixed good value the way 20-25 ms has on hard
disks: in one solid-state service group the median weekly latency is 0.24 ms,
and two of its nodes answer in 2-5 ms with PSI under 0.1 before caps, the same
at the 50th and the 90th percentile. That latency belongs to their hardware,
not to load. Fleet data shows no solid-state throughput-against-latency curve
to place a band on.

## Options Considered
- Keep the pressure mapping: the multiplier stays pinned at busy under its own
  throttle wait; rejected.
- A fixed millisecond band for all solid state: groups differ about three-fold in
  median latency, so one band is too loose for some and too tight for others;
  rejected.
- The brain's placement-gate reference (group median floored at the fleet
  median): on every solid-state group the floor wins, which is the fixed band
  above; rejected.
- A multiple of the group median alone: the slow-hardware nodes sit far above
  any multiple and would be held at the busy multiplier while idle; rejected.
- The node's own quiet latency alone: ignores the service group; rejected.
- A multiple of the higher of the group median and the node's own quiet
  floor: chosen. The group median sets the band; a disk that is slower even
  when idle is judged against its own floor.

## Decision
- Settings `ssdLatencyLowX` (5) and `ssdLatencyHighX` (10) in
  `cgroup.dynamicIops`; low at least 1, below high, high at most 1000.
- Group median: the brain sends the median `iopingWeek` of the enabled nodes in
  the node's service group as an optional fifth argument of the daily
  `/scripts/util/remote/fleetCompare.php` row; it is stored as `groupIopingMs`
  in `/var/lib/pmss/public/fleetCompare.json` and used for three days. Four
  arguments stay valid.
- Own quiet floor: `cgroupPolicyRefresh` stores `ioping_floor_ms` in
  `/var/run/pmss/io-ceiling.json`, the median over the accepted days of the
  window of each day's 10th-percentile `iopingHomeMs` (days with fewer probes
  than `minSamplesPerDay` do not count). A missed probe never removes a row
  from the read ceiling. It is used while the published ceiling is current.
- When `/home` resolves to `ssd` or `nvme` and a reference exists, the band is
  `ssdLatencyLowX` to `ssdLatencyHighX` times the reference, with the step,
  bounds, state file and first-cycle seed of ADR 0236. Without a reference the
  pressure mapping stays. Records carry `pressure.latency_reference_ms`.

## Consequences
- An idle solid-state disk climbs from busy to idle in about 85 minutes, as a
  hard disk does; one whose latency passes ten times its reference is pulled
  back.
- The factors 5 and 10 are not measured against throughput; they sit above the
  spread of quiet solid-state nodes around their group median. Revisit them
  with solid-state hosts under load.
- A node that is saturated nearly all week raises its own floor and loosens
  its band; the group median does not prevent that.
- The brain does not know a node's media: a solid-state node in a group of
  mostly hard disks gets the hard-disk median, a loose band, and caps at the
  idle multiplier, the pre-cap behaviour.
- Until the brain sends the fifth argument, solid-state nodes steer against
  their own floor.
