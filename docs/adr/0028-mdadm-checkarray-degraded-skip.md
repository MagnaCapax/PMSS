# ADR 0028: Skip mdadm checkarray on degraded arrays

Date: 2026-07-02
Category: architecture

## Status
Accepted

## Context
PMSS replaces Debian's default monthly mdadm check cron with a quarterly,
hostname-staggered root cron entry. That schedule reduces fleet-wide I/O storms
while still running parity checks on healthy arrays.

Running a redundancy data-check on a degraded md array has poor risk/reward: the
array is already missing redundancy, so the check cannot validate full parity,
and the extra md activity can aggravate an already fragile storage state. PMSS
already records degraded md arrays through the storage-health parser, so the
cron check can reuse that state instead of invoking `checkarray --all`.

## Options Considered
- Option A - keep `checkarray --all`. This preserves Debian behavior but still
  queues checks on degraded arrays.
- Option B - disable scheduled md checks entirely. This avoids degraded-array
  risk but also removes healthy-array parity scrubs, creating silent bitrot
  exposure.
- Option C - keep the existing quarterly schedule and pass only non-degraded md
  arrays to Debian `checkarray`.

## Decision
Choose Option C.

PMSS keeps the existing first-Sunday, quarterly, hostname-staggered cron gate and
replaces the `--all` invocation with a wrapper that enumerates md arrays, skips
degraded or per-array-unknown state with a log line, and runs Debian checkarray
only for non-degraded arrays. If mdstat cannot be read or the parser cannot
enumerate arrays from mdstat content that clearly contains md records, the
wrapper preserves the previous `checkarray --all` behavior and logs the fallback
so a parser failure does not silently disable scrubs fleet-wide.

## Consequences
- Positive: degraded arrays no longer receive a scheduled data-check that cannot
  restore redundancy and may worsen storage pressure.
- Positive: healthy arrays remain covered by the established quarterly staggered
  scrub policy.
- Positive: the wrapper reuses the existing storage-health mdstat parser instead
  of adding a second degraded-state interpretation.
- Negative: a degraded array will not receive scheduled parity checks until the
  failed member is replaced and the array returns to non-degraded state.
- Follow-up: production verification should confirm one degraded test array is
  skipped and one healthy test array still queues the check under the normal
  quarterly gate.

## References
- PMSS issue #678
- `etc/seedbox/config/root.cron`
- `scripts/lib/storageHealth/raid.php`
- `scripts/lib/mdadmCheckarray.php`
