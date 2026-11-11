# ADR 0265: Observe RAID write-intent bitmaps in storage health

Date: 2026-11-11
Category: domain

## Status
Accepted

## Context

Storage-health snapshots derive RAID findings from mdstat without acquiring
write-intent bitmap state. A serving array can therefore appear healthy while
an unclean stop would require a full resync. Issue #1261 requests a warning
without changing the shared array's configuration.

## Options Considered

- Retain mdstat-only observations and omit the recovery risk.
- Enable missing bitmaps during collection, changing shared storage policy.
- Add a bounded read-only detail probe and report absent or unknown state.

## Decision

Use the existing storage probe lock and `pmssCommandCapture()` for each mdstat
array, running `mdadm --detail` in the C locale with a 30-second deadline.
The shared capture layer supplies process-group containment, kill-after
escalation and structured timeout logging under ADR 0035.

Acquisition stays in `raid.php`, detail interpretation in `raidParse.php`, and
the finding/status decision in `deviceMetrics.php` (ADRs 0173 and 0058).
Successful detail output must contain RAID level and state before an omitted
bitmap field means absence. Failed probes discard partial output. Empty or
unrecognized output means unknown, never a healthy bitmap verdict.

Persist the additive nullable boolean `write_intent_bitmap`. Absence adds
`no_write_intent_bitmap`; unknown adds `write_intent_bitmap_unknown`. Both warn
and carry an operator-readable message; existing degraded findings still fail.
The operator table prints the message. Bitmap warnings alone do not describe
current performance loss and do not trigger customer degradation notices.

## Consequences

No bitmap is enabled, resized or removed by a health read. There is no size
threshold. Missing mdadm, contention and deadline failures remain visible as
unknown. Existing mdstat-only parser payloads and duplicate activity flags stay
unchanged. Hermetic fixtures cover present/absent/unknown observations, probe
arguments, failure exit codes and notice classification; execution stays on
the testing machine. The existing shared command tests cover timeout containment.

## References

- [mdadm detail output](https://github.com/md-raid-utilities/mdadm/blob/main/Detail.c)
- [Storage findings](0058-storage-health-status-derived-from-findings.md)
- [Parsing boundaries](0173-storage-device-parsing-boundaries.md)
- [Process-group deadlines](0035-command-timeouts-must-signal-the-process-group.md)
