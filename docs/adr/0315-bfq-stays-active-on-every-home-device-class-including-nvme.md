# ADR 0315: BFQ stays active on every /home device class, NVMe included, and the weight cron re-asserts it

Date: 2026-11-20
Category: architecture

## Status
Proposed

## Context

The per-user disk controls that need a scheduler (the `blkio.bfq.weight` tiers set from `IOWeight`
and the bonus multiplier) act only where BFQ is the active elevator of the device backing `/home`
(kernel Documentation/block/bfq-iosched.rst, section 4-2; ADR 0045). Two gaps let that control switch
off with nothing re-enabling it:

1. **Runtime drift.** `pmss-boot-tuning.service` (ADR 0064, the sole writer of per-disk queue knobs)
   writes the scheduler once, at boot. Every other per-user control is re-applied by its own cron
   (quotas, traffic limits, IOPS limits, CPU weight, memory), but a scheduler changed at runtime stayed
   changed until the next boot. `cgroupBfqWeightApply.php` noticed only when no leaf at all ran BFQ
   (`pmssCgroupPolicyMountBfqActive()` is an any-leaf check), so one md member of six off BFQ passed.
2. **NVMe.** The boot-tuning template wrote `none` for `nvme*`. On an NVMe-backed `/home` the weights
   never applied and the weight cron exited FATAL on every run.

The operator ruled on 2026-11-20 that the `none` scheduler is not an option on shared hosts: per-user
resource control is the requirement, and settings are chosen among those that keep every control working.

## Options Considered

- **Leave both as they are.** Weights silently inert on NVMe hosts and after any runtime scheduler write.
- **A separate unit or timer that re-applies queue knobs.** A second writer for the same knobs, against ADR 0064.
- **The weight cron re-runs the sole writer when it finds drift, and the template writes bfq for NVMe.**
  No new writer and no new schedule: the cron that needs BFQ already runs every 5 minutes.

## Decision

- `scripts/lib/cgroup/schedulerDrift.php` lists the leaves of `/home`'s backing device that the boot-tuning
  unit sets to BFQ (`sd*`, `vd*`, `xvd*`, `bcache*`, `nvme*`) and that run something else.
- `cgroupBfqWeightApply.php` logs a `pmss-bfq` warning naming them, runs
  `systemctl restart pmss-boot-tuning.service` (the unit is `Type=oneshot`, `RemainAfterExit=yes`, so
  `start` would be a no-op), re-reads the list, and exits FATAL with an error log line if any leaf is
  still off BFQ. `--dry-run` changes nothing.
- `template.pmss-boot-tuning.sh` writes `bfq` for `nvme*` and its `hardware.json` advertises
  `"nvme_scheduler": "bfq"`. `read_ahead_kb` for NVMe stays 128, a separate knob.
- `pmssEnsureBootTuning()` (update path) restarts the unit when it installed a changed script or unit file,
  and only starts it otherwise, so a changed template takes effect during the update, not at the next boot.

## Consequences

- A runtime scheduler write on a `/home` device is undone within one cron cycle on hosts with the unit.
- NVMe hosts move to BFQ during the update that installs the changed script; the weight cron's drift heal
  catches any host where that re-run did not happen.
  BFQ costs more CPU per I/O than `none`; its effect on NVMe hosts under seedbox load is measured on the
  first hosts the update reaches.
- Re-running the unit also re-writes the other knobs it owns (md stripe cache, sync speeds, read-ahead).
  `template.rc.local` writes the same md sync values, so no dynamic writer is overridden.
- `pmssCgroupPolicyMountBfqActive()` keeps its any-leaf meaning for its existing callers.
