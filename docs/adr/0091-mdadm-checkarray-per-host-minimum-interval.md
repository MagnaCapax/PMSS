# ADR 0091: Per-host minimum interval for mdadm checkarray

Date: 2026-10-08
Category: data

## Status

Accepted

## Context

ADR 0028 retains a quarterly, hostname-staggered root cron gate for mdadm
checks and skips degraded arrays. On PCIe-bandwidth-limited storage hosts, a
full check can take two to three days and make storage appear I/O-saturated.
Those hosts need a longer interval without changing the default fleet schedule.

## Options Considered

- Change the quarterly cron gate for every host: affects hosts that do not need
  a longer interval.
- Disable checks on slow hosts: removes scheduled integrity coverage.
- Add an optional per-host minimum interval inside the checkarray wrapper.

## Decision

Choose the optional per-host minimum. A non-negative integer in
`/etc/seedbox/config/mdadmCheckarrayMinDays` sets the minimum number of days
since the last successful checkarray start. Missing, empty, malformed, or zero
means no additional limit. The wrapper records the invocation's start time in
`/var/lib/pmss/mdadm-checkarray-last` only when checkarray exits successfully.
The quarterly, hostname-staggered root cron gate remains unchanged.

## Consequences

- Hosts can opt into approximately annual checks by setting `365` while other
  hosts retain their quarterly opportunities.
- A failed checkarray run does not advance the interval. A failed state write
  is logged but does not change checkarray's exit code.
- A missing or invalid state lets the next scheduled check proceed.

## References

- ADR 0028: `0028-mdadm-checkarray-degraded-skip.md`
- `scripts/lib/mdadmCheckarray.php`
- `docs/cron.md`
