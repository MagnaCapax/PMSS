# ADR 0238: Per-host HDD read-cap throughput probe

Date: 2026-11-07
Category: architecture

## Status
Accepted. Supersedes ADR 0236 for rotational and unknown non-solid storage.
Solid state retains ADR 0237. Applies through normal rolling updates with the
same software and policy on every host; no overrides or shadow mode.

## Context
Measurements on production hard-disk hosts on 2026-11-07 (iostat history and the enforce log, read over SSH) show:

- The HDD band's ioping median is cache dominated: 0.2 ms on a used array.
- Seedbox reads are 200–580 KiB. Read wait is 9–20 ms at low disk load and
  29–72 ms busier, while delivered MB/s keeps rising. A fixed read-wait band
  was tried and reverted.
- A multiplier step from 2 to 10 raised delivered read MB/s 1.6–2.4 times on
  three hard-disk hosts and lowered PSI full.
- Consecutive 15-minute windows at a constant multiplier vary by a median
  7–19 percent and p90 26–53 percent. A 1.1-times step is invisible in that
  throughput noise; a twofold step is visible when averaged over blocks.

The objective is each server's tightest multiplier whose doubling buys less
than ten percent more delivered reads, with continued adaptation to demand.

## Options Considered
- Fixed band on ioping median: rejected; cached reads do not measure the
  throughput limit.
- Fixed band on device read wait: rejected; larger reads have higher service
  times and delivered throughput still rises beyond the proposed band.
- Per-step throughput hill-climb: rejected; small steps are invisible under
  workload noise.
- Passive per-host knee from history: rejected; workload mix confounds cause
  and effect.
- Paired hourly blocks at a twofold multiplier difference: chosen; a deliberate
  change measures delivered work on each server without another I/O probe.

## Decision
`scripts/lib/cgroup/dynamicIopsProbe.php` contains pure transitions, wired through
the existing enforce cycle. For `hdd`, including unknown non-solid storage,
alternate low L and high min(idleMultiplier, 2L). Start L at `probeStartLo` (5),
bounded by busyMultiplier and idleMultiplier/2.

Each block spans `probeBlockCycles` (12, validated 4–48) five-minute cycles.
Clock-aligned boundaries are offset by crc32(gethostname()) modulo that cycle
count, so neighboring guests need not switch together. The first cycle's delta
is omitted for lag. Sum read IO count deltas for account slices present at both
endpoints, reusing `pmssBlkioReadCount` from the contention observer. An account
appearing partway through a block contributes only after two observations.

Missing previous counts, a reset, md sync, elapsed time
outside 240–420 seconds, skipped cycles or an allocation fallback discard the
block. Alternation continues. The existing iostat history supplies md-sync
evidence. Pressure, ceiling and quota guards still keep kernel caps unchanged.

When a valid block immediately follows a valid block of the other arm, append
ln(work_hi/work_lo), provided both are positive. Pairs run in both orders (low
then high, high then low), so a steady rise or fall in demand over the hours
cancels instead of reading as a gain. At `probePairs` (11, validated 2–24)
pairs, twelve blocks, compare their mean with ln(1 + `probeGain`) (0.10,
validated 0.01–1). Above it, multiply L by sqrt(2); otherwise divide L by
sqrt(2), within the bounds. Clear pairs after each decision; the block measured
at the old L does not pair with the next. Partial blocks at first startup
cannot contribute a pair.

Persist lo, arm, block_start, block_work, block_samples, last_counts, pairs and
pending_work in `/var/lib/pmss/dynamic-iops-probe.json`, mode 0600; the env
path is `PMSS_DYNAMIC_IOPS_PROBE_STATE`. An additional last_time records actual
elapsed seconds across observations and reboot. Null block_work marks a
discarded block. Missing, corrupt or incompatible state starts fresh at 5.
Read-only and PMSS_DRY_RUN previews never write state.

The dynamic-iops JSONL record carries `steer=probe` and probe fields lo, hi, arm,
block_cycle (zero based), pairs_n, last_ratio (log ratio), decision (raise/lower
or null). Retire latencyLowMs and latencyHighMs; latencyStep remains in use by
the unchanged solid-state band of ADR 0237.

## Consequences
- HDD caps alternate by twofold each hour by design. Under uninterrupted valid
  evidence a decision occurs about every twelve hours; discarded blocks and
  reboot gaps extend convergence. Learned L and completed pairs survive reboot.
- Work uses read IOs, so changes in request size or demand can still affect
  pairs. Eleven pairs over twelve hours reduce noise; continuous retesting follows
  demand changes.
- The first partial/invalid block is discarded, and zero-work blocks cannot
  claim a gain. Missing evidence cannot relax the existing keep-kernel guards.
- No new packages, host configuration overrides or active disk reads are added.
- Fixture coverage includes gain/bounds, invalid blocks, account arrivals,
  offsets, a steady demand trend, corrupt state, previews and unchanged
  solid-state steering. Checks
  and tests run on the orchestrator/testing VM; none run in this development lane.

## References
- [Cgroup policy](../cgroup.md)
- [ADR 0236](0236-read-cap-multiplier-follows-a-read-latency-band-on-rotational-storage.md)
- [ADR 0237](0237-read-cap-multiplier-on-solid-state-follows-a-band-tied-to-the-group-median-latency.md)
