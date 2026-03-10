# Cgroups (Resource Control) in PMSS

This document explains how PMSS configures Linux cgroups (via systemd) for per‑user resource control on Debian 10/11/12, how to inspect/apply limits, and how to tune defaults safely.

## Overview

- Debian 11/12 use cgroup v2 (unified). Debian 10 uses cgroup v1.
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

You can override defaults per host/SKU via a PHP array file:

`/etc/seedbox/config/cgroup.policy.php`
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
  // Remaining TODOs: burst allowances + policy-level net shaping hints.
];
```

Guardrails always apply: MemoryHigh ≥ 250 MiB; MemoryMax ≤ 95% of RAM.

## Extension Status Matrix (#121)

Current state for the cgroup policy extension TODOs:

- **Per-device IO controls**: Implemented for policy defaults and explicit CLI paths.
  - Global policy supports `mounts` entries (`ioWeight`, `readBw`, `writeBw`, `readIops`, `writeIops`) that are translated into `IODeviceWeight` and IO throttle directives.
  - `userConfigCgroup.php --apply --defaults` expands policy `mounts` entries to per-device IO properties when mount device resolution succeeds.
  - Per-user CLI supports `--device` + `--io-profile` and explicit IO bandwidth/IOPS flags.
  - Remaining gap: richer scheduler-aware auto-detection policy remains TODO.

- **NOFILE limits**: Implemented.
  - Policy keys `limitNoFileSoft` / `limitNoFileHard` are consumed during system prep.
  - PMSS writes `/etc/systemd/system/user@.service.d/20-pmss-limits.conf` with `LimitNOFILE=soft:hard` when both values are valid.

- **CPU/memory/tasks profile families**: Implemented.
  - Built-in CLI shorthands (`low`, `high`, `heavy`) remain available.
  - Policy can extend/override profile maps via `profiles.cpu`, `profiles.mem`, and `profiles.tasks` using positive numeric values.
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

Inspect and apply limits per user:

```
/scripts/util/userConfigCgroup.php USER [--status] [--config]
/scripts/util/userConfigCgroup.php USER --apply [--dry-run] [--defaults] [--cpu-weight=N] [--io-weight=N] [--tasks-max=N] [--memory-high=MiB] [--memory-max=MiB] [--cpu-quota-percent=N] [--device=/dev/DEV|/home] [--io-profile=hdd|nvme|bulk] [--io-read-bw=/dev/DEV:SIZED] [--io-write-bw=/dev/DEV:SIZED] [--io-read-iops=/dev/DEV:OPS] [--io-write-iops=/dev/DEV:OPS] [--wipe]
```

### Examples (explicit)

- Bandwidth limits:
  - `--io-read-bw=/dev/sda:5M --io-write-bw=/dev/sda:10M`
- IOPS limits:
  - `--io-read-iops=/dev/sda:100 --io-write-iops=/dev/sda:120`
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
- `--wipe` reverts the user slice (`systemctl revert user-UID.slice`) and sets MemoryHigh/Max to infinity and resets weights to defaults.
- `--dry-run` prints planned K=V and IO properties without changing the system.

## Root Slice Safety

- Root slice (user‑0.slice) is never limited. PMSS installs an override and a repair job to enforce this:
  - At boot (after 20 seconds) and every 2 hours: `/scripts/cron/cgroupRootCheck.php` fixes limits if misconfigured.

## Integration Hooks

- User creation applies defaults automatically:
  - `php /scripts/util/userConfigCgroup.php USER --apply --defaults`
  - Policy `mounts` entries are applied as per-device IO limits/weights when their backing devices can be resolved.
- User termination clears slice overrides:
  - `systemctl revert user-UID.slice` before deleting OS user data.

## Options Reference (systemd/cgroup v2)

- CPU
  - `CPUWeight=1..10000` (relative CPU priority)
  - (optional) `CPUQuota=` (hard cap; not set by default to avoid throughput cliffs)
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

## Notes

- IOWeight effectiveness depends on device scheduler. It works well with BFQ (HDDs), but is less effective with NVMe (none/mq‑deadline). Prefer strict throttles when needed.
- cgroup v1 systems (Debian 10) use BlockIOAccounting and analogous memory/task settings. PMSS retains a v1 template and selection by kernel detection.
