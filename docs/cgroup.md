# Cgroups (Resource Control) in PMSS

This document explains how PMSS configures Linux cgroups (via systemd) for per‑user resource control on Debian 10/11/12, how to inspect/apply limits, and how to tune defaults safely.

## Overview

- Production pins cgroup v1 on every Debian version (ADR 0019); v2 detection remains for portability.
- PMSS detects the kernel cgroup mode and renders a systemd user slice drop‑in accordingly:
  - v2: `etc/seedbox/config/template.cgroup.user-slice.v2.conf`
  - v1: `etc/seedbox/config/template.cgroup.user-slice.v1.conf`
- Admin drop‑ins are written to `/etc/systemd/system/user-.slice.d/15-pmss.conf`. Vendor paths are not used.
- When policy defines descriptor caps, PMSS writes `/etc/systemd/system/user@.service.d/20-pmss-limits.conf` with `LimitNOFILE=soft:hard`.
- Root (user‑0.slice) is always unlimited. A boot + periodic check enforces this.

## Defaults & Policy

Defaults are computed conservatively with guardrails:

- MemoryHigh ≥ 250 MiB (floor) and ~10% of total RAM by default.
- MemoryMax = min(1.5 × MemoryHigh, 95% of total RAM).
- CPUWeight=200, IOWeight=200, TasksMax=4096.
- CPUQuota = 85% of Total Logical Cores (e.g., 1020% on a 12-thread system). Falls back to 600% if detection fails.

Shipped defaults live in `/etc/seedbox/config/cgroup.policy.php` and are replaced
on updates. Put persistent host/SKU overrides in this operator-owned PHP array file:

`/etc/seedbox/config/cgroup.policy.local.php`
```php
<?php
return [
  'cpuWeight'     => 100,
  'ioWeight'      => 100,
  'tasksMax'      => 2048,
  'memoryHighMiB' => 500,
  'memoryMaxMiB'  => 750,
  'limitNoFileSoft' => 8192,
  'limitNoFileHard' => 16384,
  'profiles' => [
    'cpu' => ['balanced' => 180],
    'mem' => ['streaming' => 1536],
    'tasks' => ['service' => 12000],
  ],
  // Remaining TODOs: scheduler-aware IO auto-policy, burst allowances +
  // policy-level net shaping hints.
];
```

The local file is not shipped by PMSS, so updates preserve it. The shared loader
recursively replaces matching keys while retaining unspecified shipped defaults.
It ignores non-array returns and symlinked local files. Keep this executable PHP
configuration root-owned and non-writable by customers, like the shipped policy.

Guardrails always apply: MemoryHigh ≥ 250 MiB; MemoryMax ≤ 95% of RAM.

## Extension Status Matrix (#121)

Current state for the cgroup policy extension TODOs:

- **Per-device IO controls**: Implemented.
  - Global policy supports `mounts` entries (`ioWeight`, `readBw`, `writeBw`, `readIops`, `writeIops`) that are translated into `IODeviceWeight` and IO throttle directives.
  - `userConfigCgroup.php --apply --defaults` expands policy `mounts` entries to per-device IO properties when mount device resolution succeeds.
  - Per-user CLI supports `--device` + `--io-profile` and explicit IO bandwidth/IOPS flags.
  - Remaining gap: richer scheduler-aware auto-detection policy remains TODO.

- **Per-user io.cost exposure**: Implemented with scheduler guard.
  - Per-user knobs `ioCostQos` / `ioCostModel` map to `--io-cost-qos=...` / `--io-cost-model=...` on `userConfigCgroup.php`.
  - PMSS resolves `/home` to major:minor automatically and prefixes io.cost nested keys when the device token is omitted.
  - If an io.cost value includes an explicit major:minor token, it must match the resolved target device; mismatches are skipped.
  - io.cost writes are skipped when BFQ is active on any block scheduler queue (kernel-level incompatibility).
  - PMSS writes io.cost to root cgroup files (`/sys/fs/cgroup/io.cost.qos`, `/sys/fs/cgroup/io.cost.model`) and mirrors to the user slice path when the kernel exposes those files there.

- **NOFILE limits**: Implemented.
  - Policy keys `limitNoFileSoft` / `limitNoFileHard` are consumed during system prep.
  - PMSS writes `/etc/systemd/system/user@.service.d/20-pmss-limits.conf` with `LimitNOFILE=soft:hard` when both values are valid.

- **CPU/memory/tasks profile families**: Implemented.
  - Built-in CLI shorthands (`low`, `high`, `heavy`) remain available.
  - Policy can extend/override profile maps via `profiles.cpu`, `profiles.mem`, and `profiles.tasks` using positive numeric values.
  - `profiles.io` can extend/override IO profile shorthands (`--io-profile=<name>`) using `ioWeight`, `cpuWeight`, `tasksMax`, `readBw`, `writeBw`, `readIops`, and `writeIops`.
  - Invalid profile entries are ignored, preserving baseline behavior.

- **Per-user burst allowances**: Not implemented yet.
  - No time-boxed "temporary MemoryMax raise" workflow exists in current policy.

- **Network IO shaping hints in cgroup policy**: Not implemented yet.
  - Network shaping remains managed by FireQOS configuration and user traffic limit files, not cgroup policy keys.

### Pending backlog contracts (#121)

The remaining extension work is tracked as explicit backlog contracts so future
changes stay incremental and reviewable:

| Extension | Current state | Next contract before implementation | Guardrail to preserve |
| --- | --- | --- | --- |
| Per-user burst allowances | Pending | Define policy schema + expiry semantics for temporary `MemoryMax` raise in one ADR/issue step | Burst must be explicit opt-in and always time-bounded |
| Network shaping hints in policy | Pending | Define policy-to-FireQOS mapping and conflict resolution with existing traffic limit files | Existing FireQOS defaults remain authoritative until mapping is proven |
| Scheduler-aware IO auto-policy | Partially pending | Keep current mount-based IO controls; add scheduler detection only as additive hints | Never remove explicit per-device mounts policy behavior |

Reference point: `etc/seedbox/config/cgroup.policy.php` contains the live
extension TODO markers for these pending items.

## Per‑User Utility

`cgroup/Manager.php` retains the CLI lifecycle and public sizing methods.
`cliInput.php` parses options once; `profiles.php` applies selected profiles using
the policy file's flat fields. `ioPlan.php` resolves devices and plans guarded IO
writes, while `cliDisplay.php` renders help, configuration, counters, and plans.
Action checks and latency pairs stay inline in the CLI lifecycle; io.cost
major:minor lookup stays with its write planner and uses the shared policy validator.
There is no translated profile catalog or diagnostic-result envelope between
these modules. The CLI output, return codes, validation, and production v1 pin
(ADR 0019) remain unchanged; `UserCgroupUtilTest` locks profile/option output.

Shared systemd property readers treat overflowed time spans and CPU percentages
as unavailable (`null`). Both direct percentages and time-derived percentages
must round to a positive PHP integer before conversion. Finite time spans,
ordinary quota rounding, and direct-percentage precedence retain their existing
behavior; `SystemdSlicePropertiesCharacterizationTest` covers these boundaries.
NUL-containing captures return the requested keys with empty values before any
whitespace normalization. Standalone trailing-integer, time-span and CPU-quota
parsers likewise return `null` for NUL-containing selected values; a malformed
direct quota does not select the fallback time-derived quota. Valid whitespace,
device-qualified integers and direct-quota precedence keep their existing results.

Inspect and apply limits per user:

```
/scripts/util/userConfigCgroup.php USER [--status] [--config]
/scripts/util/userConfigCgroup.php USER --apply [--dry-run] [--defaults] [--cpu-weight=N] [--io-weight=N] [--tasks-max=N] [--memory-high=MiB] [--memory-max=MiB] [--cpu-quota-percent=N] [--io-latency-ms=MS] [--io-cost-qos=SETTING] [--io-cost-model=SETTING] [--device=/dev/DEV|/home] [--io-profile=hdd|nvme|bulk] [--io-read-bw=/dev/DEV:SIZED] [--io-write-bw=/dev/DEV:SIZED] [--io-read-iops=/dev/DEV:OPS] [--io-write-iops=/dev/DEV:OPS] [--wipe]
```

### Examples (explicit)

- Bandwidth limits:
  - `--io-read-bw=/dev/sda:5M --io-write-bw=/dev/sda:10M`
- IOPS limits:
  - `--io-read-iops=/dev/sda:100 --io-write-iops=/dev/sda:120`
- io.cost QoS/model:
  - `--io-cost-qos='enable=1 ctrl=user rpct=95.00 rlat=75000 wpct=95.00 wlat=150000 min=50.00 max=150.00'`
  - `--io-cost-model='ctrl=user model=linear rbps=834913556 rseqiops=93622 rrandiops=102913 wbps=618985353 wseqiops=72325 wrandiops=71025'`
- CPU/IO weights:
  - `--cpu-weight=200 --io-weight=200`
- Memory:
  - `--memory-high=600 --memory-max=900`
- Defaults from policy:
  - `--defaults` (loads `/etc/seedbox/config/cgroup.policy.php` and applies keys if present)

### Shorthand profiles

- `--device=/dev/sda --io-profile=hdd`
  - IOWeight=200; adds read/write bandwidth + IOPS throttles (5M/10M; 100/100)
- `--device=/home --io-profile=hdd`
  - Resolves the backing device for `/home` and applies the same throttles (uses `findmnt` or `PMSS_HOME_DEVICE` if set)
- `--io-profile=nvme`
  - No throttles by default; IOWeight=200 (weights often have limited effect on NVMe)
- `--io-profile=bulk`
  - Favor throughput: raises IOWeight (~500), CPUWeight (~300), TasksMax (~8192)
- `--cpu-profile=<name>`, `--mem-profile=<name>`, `--tasks-profile=<name>`
  - Uses built-in shorthands by default and policy-defined profile maps from `cgroup.policy.php` when provided.

### Behavior

- All flags are additive. If you pass only one flag (e.g., `--cpu-weight=300`), only that property is changed. Unspecified settings remain as they are.
- `userConfig.php` treats an explicit `IO_READ_IOPS` or `IO_WRITE_IOPS` value of `0` as a request to clear the matching `/home` systemd IOPS cap, so plan downgrades do not leave stale `IOReadIOPSMax` or `IOWriteIOPSMax` drop-ins behind.
- `--wipe` reverts the user slice (`systemctl revert user-UID.slice`) and sets MemoryHigh/Max to infinity and resets weights to defaults. It is rejected when mixed with resource, IO, defaults, or respect-existing modifier flags.
- `--dry-run` prints planned K=V and IO properties without changing the system.

## Root Slice Safety

- Root slice (user‑0.slice) is never limited. PMSS installs an override and a repair job to enforce this:
  - At boot (after 20 seconds) and every 2 hours: `/scripts/cron/cgroupRootCheck.php` fixes limits if misconfigured.

## Opt-in aggregate I/O placement (v1)

On v1, blk-throttle does not propagate slice limits to child blkio groups.
The shipped `DefaultIOAccounting=yes` creates those groups for account scopes
and services, so populated slice throttle files alone do not prove enforcement.
BFQ weights remain hierarchical. ADR 0176 records the placement fix.

Before enabling it, run `/scripts/cron/cgroupIopsLimitApply.php --census` as root.
This read-only TSV report lists each configured account's computed read cap,
explicit write cap, recent read-IOPS P95, valid interval count and immediate
blkio child-directory count. `none` means no cap; `n/a` means unavailable data.
The existing direct-writer policy computes the caps, including explicit
overrides, storage-class floors and the md derivation exclusion.

P95 uses nearest rank over counter-delta rates in the last 24 hours from
`/var/log/pmss/metrics/<account>` and its uncompressed `.1` rotation. Each file
is limited to 16 MiB; larger/unreadable files are skipped. Timestamps use the
collector's local timezone. Duplicate timestamps are deduplicated; resets,
invalid counters and intervals over 15 minutes are omitted. These are
**slice-local** counters: child I/O can be missing before placement is fixed.
A low or zero P95 is not evidence that a cap is safe. Child-directory counts
include empty remnants and do not prove where processes run. Check actual
process membership and obtain representative uncapped measurements on testing
machines before planning rollout; the census does not authorize activation.

Set `cgroup.ioSlicePlacement` to boolean `true` in `cgroup.policy.local.php` to opt in:

```php
<?php
return ['cgroup' => ['ioSlicePlacement' => true]];
```

The default is `false`. During runtime-template convergence on v1, PMSS installs
`/etc/systemd/system.conf.d/90-pmss-io-placement.conf` with
`DefaultIOAccounting=no` before its existing systemd reexec. Account slices keep
their explicit accounting and weights, while new scopes use the slice's blkio
group and share one aggregate cap. This also makes existing slice bandwidth
limits effective; inspect those values before activation. System services lose
default per-unit I/O accounting unless explicitly requested.

Already-running delegated user managers can retain their child blkio groups,
including rootless Docker and containers. Plan a user-manager restart or reboot
and verify membership before calling rollout complete. PMSS does not restart
accounts or move their processes automatically. Explicit I/O properties in
local unit drop-ins can still create child groups and require inspection.
Buffered-write limitations and the separate monthly-clamp persistence defect
are unchanged.

Setting the switch back to `false` and running runtime-template convergence
removes only the PMSS placement drop-in before reexec, restoring the shipped
manager default. Unknown/v2 mode also removes that drop-in. Dry runs do not
create or remove it. No sysfs path guard or throttle validator is widened.

## Integration Hooks

- User creation applies defaults automatically:
  - `php /scripts/util/userConfigCgroup.php USER --apply --defaults`
  - Policy `mounts` entries are applied as per-device IO limits/weights when their backing devices can be resolved.
- update-step2 checks the live per-user `MemoryMax` against the stored `ramMiB` plan before marking a user refreshed; if the live cap is lower than the canonical plan, it reruns the same `userConfigCgroup.php --apply` command to restore the slice policy.
- update-step2 systemd preparation backs up per-UID `90-pmss-user.conf` and `99-pmss.conf` files, then renames them to `40-pmss-user-legacy.conf` and `41-pmss-legacy.conf`. Their contents remain lower-priority defaults, so canonical `50-*` property files win after reload. The scan includes orphaned UIDs and reloads once after the batch; unsafe targets or occupied destinations are logged and retained. The host-wide `user-.slice.d/99-pmss.conf` shadow is unchanged (ADR 0160).
- Per-user maintenance also repairs bare sub-MiB `MemoryMax=<N>` values with a matching suffixed `MemoryLimit=<N>M` sibling by appending the PMSS MiB suffix before reloading systemd. This repair applies to renamed defaults too.
- Cron refresh reapplies explicit io.latency/io.cost user knobs:
  - `/scripts/cron/cgroupPolicyRefresh.php` runs at boot and every 2 hours from `root.cron`.
- Cgroup-v1 BFQ hosts get a direct kernel weight refresh:
  - `/scripts/cron/cgroupBfqWeightApply.php` runs at boot and every 5 minutes from `root.cron`.
  - Policy refresh and update-step2 also run this writer after their per-user
    batches; update-step2 repeats it after later service setup, before restoring
    root cron. This restores bonus-adjusted weights overwritten by systemd
    without waiting for cron. Writer failures are logged and remain fail-soft; its
    existing runtime and BFQ guards still apply.
  - It keeps the systemd slice settings as the operator-facing source of truth, then writes
    `/sys/fs/cgroup/blkio/.../blkio.bfq.weight` directly so Debian 12/systemd 252
    BFQ translation caps do not collapse weights above `IOWeight=200`.
  - Users without explicit `IOWeight` use the shared BFQ RAM fallback curve.
    The fallback maps 128 GiB to base weight 250 so the documented 300%
    bonus path reaches the kernel cap of 1000 without collapsing earlier tiers.
    This direct kernel fallback is separate from the `userConfigCgroup.php`
    MemoryHigh-derived CPU/systemd weight helper.
- User termination clears slice overrides:
  - `systemctl revert user-UID.slice` before deleting OS user data.
  - After the final deletion attempt, both the account name and UID must be absent before removing that UID's directories in `/etc/systemd/system/` and `/etc/systemd/system.control/`. Cleanup refuses symlinks, non-regular entries and non-root-owned or writable targets, then reloads once after any removals. Failed cleanup/reload reports incomplete termination; dry runs retain files (ADR 0160).

## Options Reference (systemd/cgroup v2)

- CPU
  - `CPUWeight=1..10000` (relative CPU priority)
  - `CPUQuota=` (hard cap; the PMSS default sets 85% of total logical cores — see the defaults above and ADR 0003 — and is configurable per host/SKU)
- Memory
  - `MemoryHigh=` (soft throttle)
  - `MemoryMax=` (hard cap)
- Processes/Threads
  - `TasksMax=` (max processes/threads)
- File descriptors
  - `LimitNOFILE=soft:hard` via policy keys `limitNoFileSoft` and `limitNoFileHard`
- IO (weights and throttles)
  - `IOWeight=` (relative I/O weight)
  - `IOReadBandwidthMax=/dev/DEV SIZE` (strict read bandwidth)
  - `IOWriteBandwidthMax=/dev/DEV SIZE` (strict write bandwidth)
  - `IOReadIOPSMax=/dev/DEV OPS` (strict read IOPS)
  - `IOWriteIOPSMax=/dev/DEV OPS` (strict write IOPS)
  - `IODeviceLatencyTargetSec=/dev/DEV MSms` (latency target; cgroup v2 only)
  - `io.cost.qos` / `io.cost.model` (root-cgroup knobs; BFQ scheduler disables these)

## Notes

- IOWeight effectiveness depends on device scheduler. It works well with BFQ (HDDs), but is less effective with NVMe (none/mq‑deadline). Prefer strict throttles when needed.
- On cgroup-v1 BFQ hosts, systemd may translate any `IOWeight >= 200`
  to the same kernel `blkio.bfq.weight`. PMSS therefore uses
  `cgroupBfqWeightApply.php` to restore per-user kernel weights directly; the
  helper exits cleanly on cgroup-v2 hosts where `/sys/fs/cgroup/blkio` is absent.
- io.cost is skipped automatically when BFQ is active on any queue; PMSS logs a skip message rather than applying dead settings.
- cgroup v1 systems (Debian 10) use BlockIOAccounting and analogous memory/task settings. PMSS retains a v1 template and selection by kernel detection.
