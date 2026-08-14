# ADR 0045: Per-user blkio accounting is BFQ-first, with a source-switch reseed guard

Date: 2026-08-14
Category: architecture

## Status
Accepted

## Context
Per-user I/O metering (`scripts/lib/resources/log.php`, `metrics.php`) read the cgroup-v1
`blkio.throttle.io_service_bytes` / `io_serviced` counters. Under the fleet-default BFQ
scheduler (rotational/md hosts) those files exist but stay all-zero unless an explicit
throttle policy is set; the real per-cgroup accounting is in `blkio.bfq.*`. Result: per-user
I/O Read/Write/ops showed `n/a` / `0` fleet-wide on BFQ hosts (GH #467 closed but ineffective;
GH #707 tracks the residue). A second, independent defect compounded it: `pmssCgroupMode()`
(`scripts/lib/runtime/system.php`) returned `v2` whenever a cgroup2 mount appeared anywhere in
`/proc/self/mountinfo`, which is true on every v1-**hybrid** host (systemd mounts a cgroup2
controller at `/sys/fs/cgroup/unified`). That misdetection routed metering to the systemd
`UINT64_MAX` sentinel path, also yielding 0.

Critical coupling: the `io_read_ops` / `io_write_ops` counters feed LIVE monthly-IOPS
enforcement (`pmssReadUserMonthlyIopsUsage` → `iopsLimitEnforcer` → hourly cron). A naive
switch to the real (large, cumulative) bfq counters produces a phantom first delta — the delta
logic treats a changed baseline as the full cumulative — which for any user with a positive
monthly IOPS limit could trip a spurious 100-IOPS `/home` throttle until it ages out (~1 month).

## Options Considered
- A — read `blkio.throttle.*`, keep current. Rejected: zero fleet-wide under BFQ.
- B — "whichever blkio file is non-null". Rejected: the throttle file is present-but-zero under
  BFQ, so it wins and reproduces the bug.
- C — BFQ-first with throttle fallback, fix the mode misdetection, AND add a source-switch
  reseed guard on the enforcement-coupled delta path.

## Decision
Choose Option C.

- **BFQ-first selector** (`pmssResourceLogReadBlkioBytesWithSource`): prefer `blkio.bfq.*` when
  it carries data; fall back to `blkio.throttle.*` for non-BFQ hosts (SSD/nvme) where the bfq
  files are absent. Ops are read from the same accounting family the bytes came from. Both
  `log.php` and `metrics.php` use the one shared selector (DRY). The dead CFQ-era
  `blkio.io_service_time`/`io_wait_time`/`io_queued` reads (never present under blk-mq) removed.
- **cgroup-mode fix**: check the kernel-boot `systemd.unified_cgroup_hierarchy=0` pin FIRST
  (authoritative for a v1-booted host), and require `cgroup.controllers` at the hierarchy ROOT
  for v2 rather than matching cgroup2 anywhere in mountinfo. This correctly classifies both
  v1-hybrid hosts (pin present → v1) and genuinely-v2-drifted hosts (pin absent, controllers at
  root → v2, remedy = reboot).
- **Phantom-delta reseed guard** (§0): the io_* counters record their `io_source`
  (`bfq`/`throttle`, or `systemd` on v2). When the source changes between samples — the
  throttle→bfq switch on deploy, or a systemd↔v1 flip from the mode fix — the stored baseline
  belonged to a different counter, so the io_* deltas are emitted as ZERO for that one sample
  (baseline reseed); the new baseline is persisted and subsequent intervals delta normally. This
  removes the enforcement hazard without touching the runaway ceilings.

## Consequences
- Positive: per-user I/O metering shows real values on BFQ hosts (customer panel + telemetry).
- Positive: no spurious IOPS throttle on deploy — the first post-fix sample reseeds to 0.
- Positive: v1-hybrid hosts are correctly detected as v1, so ALL `pmssCgroupMode()` callers
  (not just metering) get the right hierarchy.
- Expected non-bug: SSD/nvme v1 hosts (no BFQ) keep `n/a` for per-user I/O — correct; the
  blkio.bfq.* accounting does not exist there. Do NOT flag as a failed fix.
- Tests: `resourceLogHelpersTest` (bfq-preferred-over-zero-throttle), `counterStateCharacterizationTest`
  (source-switch reseed then normal delta), plus the existing throttle-fallback path updated for
  the new `io_source` key. Full dev suite 2635 / 0.

## References
- GH PMSS #707 (per-user I/O dashboard blank; converged IMPLEMENT + §0 guard across 11 comments),
  GH #467 (prior throttle-only fix), ADR 0019 (production cgroup-v1 pin), ADR 0003 (dual-path detection).
- `scripts/lib/resources/log.php`, `scripts/lib/resources/metrics.php`, `scripts/lib/runtime/system.php`
- `scripts/lib/user/iopsLimit.php` / `iopsLimitEnforcer.php` (the enforcement the guard protects)
