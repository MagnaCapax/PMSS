# ADR 0064: Single authoritative writer for per-disk queue knobs

Date: 2026-09-18
Category: architecture

## Status
Proposed

## Context
Two shipped units write the same `/sys/block/<dev>/queue/` knobs at every boot.

`etc/seedbox/config/template.pmss-boot-tuning.sh` (`pmss-boot-tuning.service`,
`After=local-fs.target mdmonitor.service`) writes per device class: `md*` 4096+bfq,
`sd*` bfq with read_ahead by rotational class, `nvme*` 128+none, `vd*`/`xvd*` 4096+bfq.
It has no `bcache` branch.

`etc/seedbox/config/template.rc.local` (`rc-local.service`,
`After=network.target network-online.target`) iterates
`ls /sys/block|grep -v nvme|grep -v md|grep -v loop` and writes a blanket 4096+bfq.
That set includes `sd*`, `vd*`, `xvd*` and `bcache*`.

`network-online.target` is ordered after `local-fs.target`, so rc.local runs later and
wins on every overlapping device. This is structural, not incidental. Measured on
lt3-1-101-127jules 2026-09-18: `pmss-boot-tuning` Finished 14:06:12, `rc-local` Finished
14:06:13, and the live `sda` read_ahead was rc.local's value, not this unit's.

Consequences of the overlap as it stands:

- The `sd*` rotational/non-rotational read_ahead split in boot-tuning never takes effect
  on a host carrying both writers.
- The `mq-deadline` scheduler choice for non-rotational `sd*` is likewise overridden by
  rc.local's blanket `bfq`.
- `bcache*` devices are tuned by rc.local only. Removing that loop without adding a
  bcache branch here would lose their tuning entirely.
- A node was measured reverting read_ahead 4096 to 2048 twenty-eight minutes after a
  verification passed, because the two writers disagreed (see 37a573bd).

Operator ruling 2026-09-18: "4096 is more authoritative". That ruling settles the VALUE
where the two writers disagree on read_ahead. It does not address the scheduler split,
and is not read here as blessing `bfq` on SSDs.

## Options Considered
- **Harmonise values, keep both writers.** Zero behaviour change and zero risk, but two
  writers for one knob remain; the next divergence is one commit away. This is what has
  been done for `md*`, `vd*`/`xvd*` and now `sd*` read_ahead as an interim measure.
- **Delete rc.local's per-disk loop.** Makes this unit authoritative and lets its device
  class differentiation take effect, but loses `bcache` tuning until a bcache branch
  exists here, and changes `sd*` behaviour fleet-wide.
- **Narrow rc.local's loop to the classes this unit does not own.** Each class then has
  exactly one writer with no coverage loss on current hosts, but a host too old to carry
  `pmss-boot-tuning.service` would lose `sd*`/`vd*` tuning silently. Such hosts exist:
  the unit arrived in 55832215, and hosts running older PMSS are still in the fleet.

## Decision
Consolidate to `pmss-boot-tuning.service` as the single authoritative writer for per-disk
queue knobs, in this order:

1. Add a `bcache*` branch to `template.pmss-boot-tuning.sh` covering what rc.local's
   blanket loop currently gives those devices, so no coverage is lost.
2. Gate the removal on the unit being present, so an un-updated host keeps its tuning:
   rc.local's per-disk loop runs only when `pmss-boot-tuning.sh` is absent.
3. Once the fleet's minimum PMSS version carries the unit, remove the conditional and the
   loop.

Until step 1 lands, the interim position is the harmonised-values one: both writers stay,
and any knob both of them write carries the SAME value in both files, with the reason
stated inline at each site. `hardware.json` advertises the values actually written.

The scheduler is harmonised to `bfq` for all `sd*` — behavior-preserving against rc.local's
blanket `bfq`, so gating rc.local's loop (step 2) does not silently flip non-rotational `sd*`
to `mq-deadline`. `bfq` is also what the per-user `blkio.bfq.weight` tiers require (mq-deadline
cannot honor them), so it is the value the fleet already runs, not a new choice. Whether SSDs
should instead run `mq-deadline` remains a separate decision needing measurement, recorded here
as deferred — not adopted as a side effect of consolidating the writers.

## Implementation status

- **Step 1: DONE (2026-09-18).** `template.pmss-boot-tuning.sh` now carries a `bcache*` branch
  writing the queue knobs rc.local's blanket loop gives those devices (`read_ahead_kb` 4096,
  `scheduler` bfq), with matching `bcache_scheduler` / `bcache_read_ahead_kb` entries in the
  `hardware.json` summary. `BootTuningEnsureTest::testBcacheBranchCoversRcLocalQueueKnobsAndNotCacheMode`
  pins both, and additionally asserts this unit writes nothing under `/sys/block/bcacheN/bcache/` —
  the cache MODE knobs stay with rc.local's separate bcache loop, which step 2 does not gate and
  which must not be frozen here while hosts are being moved off `writeback`.
- **Step 2: DONE (2026-09-19).** `template.rc.local`'s per-disk loop is now wrapped in
  `if [ ! -e /usr/local/sbin/pmss-boot-tuning.sh ]; then ... fi`, so on a host carrying the unit
  boot-tuning is the SOLE writer of `sd*`/`vd*`/`bcache*` queue knobs, while a host too old to
  carry it keeps rc.local as its tuner. Because gating the loop makes boot-tuning authoritative
  for the `sd*` scheduler too, this unit's `sd*` scheduler was harmonised to `bfq` (the value
  rc.local's blanket loop produced) so the gate changes nothing on the wire and does not silently
  flip non-rotational `sd*` to `mq-deadline`; `hardware.json`'s `nonrotational_scheduler` follows,
  and `BootTuningEnsureTest::testSdSchedulerStaysBfqNotMqDeadline` pins it. The `mq-deadline`-on-SSD
  question stays deferred per the Decision above. The md loop and the bcache `cache_mode` loop are NOT
  gated (boot-tuning does not own those). Accepted residual: the gate keys on script PRESENCE,
  not on the service having RUN this boot — if the unit is installed but its service failed,
  loop 1 is skipped and the per-disk knobs stay at kernel defaults for that boot (a perf
  regression, not a data risk). A run-marker gate (`/run/pmss-boot-tuning.applied`) would close
  this; deferred as a refinement since systemPrep installs+enables+starts the unit together, so
  presence≈ran in the normal case.
- **Step 3: blocked on fleet minimum PMSS version**, as designed.

## Consequences
- Until the consolidation completes, every shared knob must be changed in BOTH files in
  the same commit. `BootTuningEnsureTest` asserts the read_ahead values so a one-sided
  change fails the suite.
- The `sd*` device-class read_ahead distinction is formally abandoned, not merely
  overridden. Reinstating it requires the consolidation above, not a value edit.
- Hosts predating 55832215 keep rc.local as their only tuner, which is why step 2 is
  conditional rather than a straight deletion.
- `bcache` tuning has no owner in this unit today. Step 1 is a prerequisite for step 3,
  not an optional extra.
