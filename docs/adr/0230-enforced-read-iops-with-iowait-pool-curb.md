# ADR 0230: Enforced read IOPS with iowait pool curb

Date: 2026-11-05
Category: architecture

## Status
The "lone reader stays at its own share" property is replaced by [ADR 0256](0256-read-caps-are-work-conserving.md).
Accepted 2026-11-05: read caps are enforced in production. Enabling them host by host
was an implementation choice, superseded by ADR 0231 (enforced by default) and ADR 0233
(no host-local policy).

## Context
ADRs 0177 and 0229 measure quota/pressure allocation independently of the
throttle writer. Large explicit read limits can hide the computed allocation.
Read caps must be enforced in production (2026-11-05), with a conservative iowait
curb, with private evidence for later review.
Production uses cgroup v1; effective aggregate caps require the placement
and membership checks from ADR 0176.

## Options Considered
- Continue observation only: cannot demonstrate customer impact under a cap.
- Enable enforcement by default: would bypass the host-by-host rollout decision.
- Add an explicit enforcement mode to the existing allocator and writer: chosen;
  keeps a single writer, existing schedule, validators and rollback path.

## Decision
Keep the shipped `cgroup.dynamicIops.mode` default `off`; accept `enforce` in
host-local policy alongside `off` and `shadow`. Only enforce mode supersedes
ADR 0177's "No proposal reaches a writer", "Explicit IOPS settings are never
replaced" and md enforcement exclusion. Off/shadow cap calculations, default
output and write behavior remain unchanged. Additional cycle fields describe
the observational curb without changing their pools or proposals.

Enforce mode sizes the pool from the published ceiling alone and ignores the
storage benchmark: a page-cached fio figure can exceed real disk IOPS by orders
of magnitude, which would turn every cap into a value that limits nothing. With
no ceiling it falls back to `missing-ceiling`. Off and shadow keep the larger of
the two.

Blend iowait windows at 5/15/60 minutes with weights 12/4/1, independently of
full-I/O PSI. Multiply the existing pressure-dependent host pool by
`1 - curbMaxCut * clamp((iowait_blend - curbStartIowait) /
(curbFullIowait - curbStartIowait), 0, 1)` before floor charging and shares.
Defaults are 50%, 80%, and 0.25: no cut through 50%, 12.5% at 65%, and 25% at
80% or above. Missing iowait means factor 1 and does not cause fallback.
Log `iowait_blend`, `curb_factor`, `pool_uncurbed` and the actual `pool`.
This initial policy uses local iowait thresholds; no fleet-median feed exists
in the allocator and none is added here.

The IOPS cron builds one cohort with the BFQ cron's shared explicit/RAM/bonus
weight function and runs the allocator once. `quotaExponent` selects only
computed variants 1.0 (default) or 0.5. Each enforced read cap is the minimum
of the selected `would_be` and a valid explicit limit parsed by
`pmssIopsParseSpec`, if present. Existing floor, account minimum, tier ceiling,
floor charge and rounding apply within `would_be`; an explicit lower cap wins.
Enforce includes md-backed `/home` without changing legacy derivation guards.
The map passes directly to `pmssIopsWriteThrottle` under the existing positive
UID, device and slice-path validators. No child-group or v2 writer is added.

The shared cycle logger publishes mode `enforce` into the existing root-only
dynamic IOPS log, including selected `read_caps` and `read_action`. `apply`
means an allocation is available, not proof of a successful kernel write.
Successful changed read writes use audit source `dynamic-iops`; write caps
retain their previous values, path and source. The BFQ cron logs shadow cycles
only in shadow mode, avoiding a second allocation in enforce mode.

Missing capacity/PSI/quota/weight, an incomplete config scan or a failed
allocation yields no read map: log `read_action=keep-kernel` and its fallback
reason, warn in syslog, and keep existing kernel read caps. Invalid settings,
exceptions or failed cycle publication also keep read caps and warn; cycle/log
failures increment errors. Legacy write-cap processing continues. No zero or
legacy placeholder is written as a recovery from allocation failure.
Dry runs and census produce neither throttle writes nor JSONL rows.

Only reads are controlled: cgroup-v1 throttling does not reliably cover
buffered writes. No credential, permission, public artifact, state file,
service, timer or cron entry is introduced. Per-account data stays in the
existing mode-0600 snapshot/audit logs with ADR 0229's retention and guards.

## Consequences
- Rollout remains per host. Confirm task placement and a real sustained read
  workload under its cap on QA; populated throttle files alone are insufficient.
- Roll back to `off` or `shadow`: the next IOPS cycle writes available legacy
  values, with the legacy audit source. Accounts with no legacy read cap retain
  the existing no-op-on-empty behavior; rollback does not clear such caps.
- Floors and rounding can make the sum of account caps exceed the curbed pool;
  the existing allocator semantics remain intact.
- An unavailable input preserves the last enforced kernel state until evidence
  recovers or the operator changes mode; startup without evidence adds no cap.
- Hermetic tests cover selection, curb boundaries, fallback, md, real writer
  calls against memory-only I/O, audit source, dry run, idempotence and rollback.
  Testing-machine regression checks and load acceptance remain required.
- Tasks waiting in the throttle count as host I/O wait and PSI, so an account at
  its cap raises both while the disk stays idle. The pool and curb then read
  their own effect: a saturated cap holds the pool at the busy multiplier and
  can reach the full curb. Validators bound this at the busy multiplier times
  the curb and at each floor, so it settles rather than runs away. Host
  pressure notices read the same signal.
- An account at its cap queues its own panel and client reads behind its own
  load in the same throttle group. Cold pages can take minutes at low caps;
  cached pages stay fast.
- blk-throttle truncates each 100 ms slice to whole I/Os, so the delivered rate
  is `10 * floor(cap / 10)`. Measured on kernel 6.1: cap 15 read 10.0/s, cap 32
  read 30.0/s, cap 100 read 100.0/s. The default floor of 15 therefore delivers
  10; a host that needs at least 15 delivered must set a floor of 20.
- Shares come from weight and quota alone, with no term for activity, so a
  quiet neighbour's share is not passed on. A lone reader on an idle host stays
  at its own share however large the pool is.

## References
- [Shadow allocation](0177-shadow-pressure-and-quota-iops-allocation.md)
- [Aggregate placement](0176-opt-in-aggregate-cgroup-v1-io-placement.md)
- [Private observation and audit](0229-io-sharing-observation-and-audit-history.md)
- [Rollout procedure](../cgroup.md#enforced-read-iops-rollout)
