# ADR 0239: The enforced host read ceiling rises but never falls

Date: 2026-11-07
Category: architecture

## Status
Accepted. Amends the passive read ceiling used by ADR 0230 to ADR 0238. Applies
through normal rolling updates with the same software and policy on every host.

## Context
The read-cap pool is the published host read ceiling times the multiplier
(ADR 0230, ADR 0236 to ADR 0238). The ceiling is the highest daily 95th
percentile of host read IOPS over the last seven complete days
(`scripts/lib/cgroup/ioCeiling.php`).

Read caps cut the reads that this ceiling measures. On three production
hard-disk hosts, the first full day under enforced caps had a daily 95th
percentile 15 to 86 percent below every earlier day in the window. One of them
had been within five percent of its mean for six days and then fell 35
percent, and the next day stayed as low by mid-day.

Each day under caps therefore lowers the ceiling, and a lower ceiling tightens
the caps further. Once the last uncapped day leaves the seven-day window, the
pool falls with it. The multiplier cannot make up the difference, because it
is bounded between the busy and idle settings.

## Options Considered
- Exclude capped days from the window: rejected. Enforcement is permanent, so
  after seven days nothing is left.
- Exclude only the cycles where a cap was held: rejected. The cycles that
  remain are the quiet ones, so the estimate is biased low.
- A longer window or a slow decay: rejected. Both delay the ratchet without
  removing it.
- Let the multiplier range float to cover any ceiling: rejected for now. It
  changes the policy bounds every rollout so far was measured against.
- A persistent anchor that the published value may rise above but not fall
  below while caps are enforced: chosen. A capped day still proves at least
  its reads, so a higher value is valid evidence and a lower one is not.

## Decision
`pmssIoCeilingRefresh()` in `scripts/lib/cgroup/ioCeilingHistory.php` keeps
the published read IOPS in `/var/lib/pmss/io-ceiling-anchor.json` (root,
0600). The cache under `/var/run` does not survive a reboot; the anchor does.
When the dynamic IOPS mode is `enforce` and the computed value is below the
anchor, the cache publishes the anchor and its original day, and records
`read_iops_anchored: true`. Otherwise the cache publishes the computed value
and the anchor takes it. The first run anchors the value already published, so
the update itself changes no cap; it only stops later falls.

With the mode `off` the anchor follows the computed value down. Thin history
still removes the cache and leaves the anchor in place. The write, IOPS and
MB/s estimates, the sample gates and the quiet latency floor are unchanged.

## Consequences
- The pool keeps the host's highest proven read demand as its scale. The
  probe (ADR 0238) and the latency bands (ADR 0236, ADR 0237) adjust the
  multiplier against a base that the caps themselves cannot lower.
- If a host loses accounts, its caps stay looser than its new demand
  requires. That costs no throughput. It can let a heavy reader take more of
  a quiet array, and the probe's held rule tightens the multiplier if caps
  still bind.
- A host that already enforces caps must receive this change while an uncapped
  day is still in its seven-day window; otherwise the anchor starts at a value
  the caps already lowered and only stops it falling further. Enforcement became
  the default on 2026-11-06, so this concerns hosts updated since then. A
  31-day seed was tried and rejected: it loosened pools 24 to 53 percent on
  deployment.
- One unusually busy day raises the anchor for good. The highest daily 95th
  percentile, not the daily maximum, limits how far one burst can move it.
- Turning enforcement off lets the anchor return to measured demand.
