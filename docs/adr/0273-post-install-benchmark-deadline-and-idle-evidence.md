# ADR 0273: Post-install benchmark deadline and idle evidence

Date: 2026-11-12
Category: architecture

## Status
Accepted

## Context

update-step2 runs `storageBenchmark.php --require-idle --devices --label=post-install`
once on tenant-empty hosts (#656) through `runStep`, whose deadline is the single
global `PMSS_COMMAND_TIMEOUT_DEFAULT` of 1200 s. Two fresh installs on 12-drive
storage hosts (14 inventory devices each) ended `rc=124`. The captured step output
shows the file-backed jobs finished in about 290 s. The per-device phase then took
about 80 s per device (1 GiB dd, an ioping, two 30 s fio reads) and was stopped on
the 11th device. The marker was never written, so every update before the first
tenant re-ran the benchmark and lost another 20 minutes, and the largest hosts never
recorded a baseline.

The same runs recorded `preflight-idle` as `ok:true` with `ioping_avg_ms:null` while
both md arrays were in their initial RAID5 build. `pmssIopingAverageMs()` returns
null when its probe fails or exceeds its 15 s timeout, and the preflight read that
null as 0 ms. Per-device ioping in those runs averaged 78-98 ms, under the 100 ms
threshold, so even a measured latency would not have caught the resync.

## Options Considered

- Raise the global deadline. Rejected: every command would wait longer before a
  hang is reported.
- Bound the per-device work (fewer jobs, shorter runtime) as the device count grows.
  Rejected: results stop being comparable across hosts.
- Split the per-device phase into its own steps. Rejected for now: it needs a new
  devices-only CLI mode for one caller.
- Give this one step a deadline sized from the device inventory, using the per-call
  `PMSS_COMMAND_TIMEOUT` override that `pmssUserRefreshPermissions()` already uses.

## Decision

The post-install step sets `PMSS_COMMAND_TIMEOUT` to
`max(global deadline, 600 + 120 × inventory devices)` around its single `runStep`
call, and restores the previous value afterwards. 120 s per device leaves about 50%
over the measured cost, and 600 s about twice the file phase. Hosts with five
devices or fewer keep the global deadline. A larger operator override still wins.

The idle preflight treats missing evidence as not idle. A null ioping result fails
it with `warn: ioping returned no value`. An md array whose `sync_action` is
`resync`, `recover`, `check`, `repair` or `reshape` fails it and is recorded as
`md_sync`; `PMSS_STORAGE_BENCHMARK_SYS_BLOCK_ROOT` points tests at a fixture tree.
A missing iostat cache is still not a failure on its own, because a fresh host has
none yet.

## Consequences

- Large hosts get their post-install baseline in one update instead of never.
- `--require-idle` now aborts (exit 2) when ioping cannot measure, or while any md
  array syncs. The post-install caller already leaves the marker unset on a non-zero
  exit, so the benchmark retries on the next update. A host that gains tenants before
  its arrays finish building skips the baseline, which is better than recording a
  resync as idle.
- `preflight-idle` JSONL records may carry the new `md_sync` key. Existing keys and
  their order are unchanged.
