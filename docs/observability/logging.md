# Logging & JSON Events

Long-running PMSS operations should emit structured logs for traceability.

## Fields (recommended)
- `timestamp` ISO-8601
- `event` short identifier (e.g., `step`, `phase`, `command`)
- `level` info|warn|error
- `step` human label
- `rc` exit code
- `duration` seconds (float)
- `host` hostname
- `distro` name/codename/version
- `correlationId` optional request/run id

## Current Emitters
- Updater `runStep()` and profiling helpers already write JSON lines to `/var/log/pmss-update.jsonl`.
- Other scripts should align to these fields when emitting JSON for consistency.

## Storage
- Text logs: `/var/log/pmss/*.log`
- JSON events: `/var/log/pmss-update.jsonl`
- Per-user action logs:
  - Consolidated stream: `/var/log/pmss/users.log` + `/var/log/pmss/users.jsonl` (JSONL), emitted via `userLifecycle` helpers.
  - Per-user files: `/var/log/pmss/users/<username>.log` (best-effort text lines for actions taken on behalf of users).

## Runbooks
See `docs/runbooks/update-failures.md` for quick diagnosis steps.

## I/O sharing observations

[ADR 0229](../adr/0229-io-sharing-observation-and-audit-history.md) defines these
additive observations. Collection keeps the existing cron cadence.
[ADR 0230](../adr/0230-enforced-read-iops-with-iowait-pool-curb.md) enforces
read caps from these inputs in the existing IOPS writer; [ADR 0231](../adr/0231-read-caps-enforced-by-default-and-contention-without-cap-stall.md)
makes that the shipped default and removes shadow mode.

| File | Figures / keys | Retention | Who can read |
| --- | --- | --- | --- |
| `/var/run/pmss/iostat` | Serialized host snapshot: original disk/PSI/latency keys, then after `time`: `cpuIowaitPct`, `cpuBusyPct`, `cpuStealPct`, `cpuIdlePct`, `psiSomeAvg300`, `procsBlocked`, `dirtyKb`, `writebackKb`, `mdSyncActive` | Latest sample, runtime cache | Host aggregate; existing local permissions apply |
| `/var/www/iostat` | Copy of the same host-only snapshot | Latest published sample | Existing public host-statistics consumer |
| `/var/log/pmss/iostat-history.log` | Timestamp prefix + serialized host snapshots, including the new keys | Yearly, 10 generations, 1G splits, compress/delaycompress | Operator; files created 0644 by rotation, parent directory access also applies |
| `/var/log/pmss/iostat-history-raw.log` | Verbatim iostat reports, including avg-cpu headers and sampled values | Yearly, 10 generations, 1G splits, compress/delaycompress | Operator; 0644 rotation files subject to parent access |
| `/var/log/pmss/system-stats.log` | Existing `cpu_iowait`, `psi_io`, `psi_mem`, load, memory and ioping | Yearly, 10 generations, 1G splits, compress/delaycompress | Operator; existing file and parent permissions apply |
| `/var/log/pmss/dynamic-iops.jsonl` | Allocation rows: `schema_version=2`, `pressure.{io,memory,cpu,iowait}.{5,15,60}`, unchanged `pressure.blend`; passive/benchmark/host read IOPS, multiplier, pool, fallback; `variants.*.users.*.{legacy,proposed,would_be}`, shares/floors/ceilings and both floor-charge pools. Journal-holder rows (ADR 0242): `ts`, `event`, `uid`, `cap_before`, `frame`, `stall_s`, `kernel`, `count_today`; no task names or paths | Daily, 3650 generations, 50M splits, compress/delaycompress | Root only, 0600 |
| `/var/log/pmss/metrics/<account>` | Existing I/O ops/bytes and `mem_dirty`, `mem_writeback`; optional `io_pressure_some_avg300`, `io_pressure_full_avg300`, `io_pressure_some_total`, `io_pressure_full_total`; `ts`, `uid` | Daily, 3650 generations, 50M splits, compress/delaycompress; rotations in `metrics/archive` | Root only, 0600 files in a 0700 live directory |
| `/var/log/pmss/cgroup-apply.jsonl` | UTC `ts`, `user`, `uid`, `control`, `old`, `new`, `source` for successful direct IOPS/BFQ writes | Daily, 3650 generations, 50M splits, compress/delaycompress | Root only, 0600 |
| `/var/run/pmss/io-ceiling.json` | `published.read_iops`, daily qualifying samples, `computed_at` and estimator settings | Current derived cache; source history retained above | Host aggregate, published 0644 |
| `/var/log/pmss/benchmark-storage.jsonl` | Successful matching-device random-read `metrics.read_iops`, `run_ts`, device and test | Existing file; no stanza for this path in the PMSS logrotate template | Operator; existing file and parent permissions apply |
| `/etc/systemd/system.conf` and `/etc/systemd/system.conf.d/*.conf` | Manager configuration bytes and uncommented `DefaultIOAccounting=` assignments | Current configuration only | Existing host configuration permissions |
| `/etc/seedbox/config/cgroup.policy.local.php` | Leftover host-local policy; ignored since ADR 0233; diagnostics reports presence so it can be removed | Not read by PMSS | Never expose its contents to customers |

Rotation counts are finite and size splits can reduce the nominal ten-year
window. The active file and `.1` remain plain text; older rotations are gzipped.
This follows ADR 0044's **2026-09-05 amendment**, which supersedes the August
keep-forever/uncompressed policy. Override paths (`PMSS_DYNAMIC_IOPS_LOG`,
`PMSS_CGROUP_APPLY_LOG`) need rotation configured separately when used elsewhere.

Read commands (run as an authorized operator; `fixture` is a fixture account):

```bash
php -r 'echo json_encode(unserialize(file_get_contents("/var/run/pmss/iostat"), ["allowed_classes" => false]), JSON_PRETTY_PRINT), "\n";'
php -r 'echo json_encode(unserialize(file_get_contents("/var/www/iostat"), ["allowed_classes" => false]), JSON_PRETTY_PRINT), "\n";'
tail -1 /var/log/pmss/iostat-history.log
tail -60 /var/log/pmss/iostat-history-raw.log
tail -6 /var/log/pmss/system-stats.log
tail -1 /var/log/pmss/dynamic-iops.jsonl
tail -1 /var/log/pmss/metrics/fixture
tail -1 /var/log/pmss/cgroup-apply.jsonl
cat /var/run/pmss/io-ceiling.json
tail -1 /var/log/pmss/benchmark-storage.jsonl
wc -c < /etc/systemd/system.conf
grep -hE '^[[:space:]]*DefaultIOAccounting=' /etc/systemd/system.conf /etc/systemd/system.conf.d/*.conf
test -f /etc/seedbox/config/cgroup.policy.local.php && echo present
/scripts/util/agentDiagnostics.php --json
```

Use `zcat` to inspect an older `.gz` rotation. History readers used by dynamic IOPS
and the ceiling estimator still bound their reads to the live file and newest
plain rotation; retained older files are for operator analysis.

CPU percentages come from the final iostat report's 120-second window; busy
is user+nice+system, separate from steal and iowait. Host PSI avg300 is a kernel
rolling five-minute percentage. Pressure windows average distinct recent
five-minute buckets. Missing iowait yields null windows without changing the
12/4/1 full-I/O-PSI blend or fallback. Missing host kernel fields yield null;
unavailable per-account PSI keys are absent. PSI totals are cumulative
microseconds, parsed numerically and returned as floats by the shared parser.

Records without `schema_version` are version 1. `would_be` ignores
explicit IOReadIOPS for the entire account cohort and has an independent
floor-charge pool; `proposed` retains its existing explicit/legacy behavior.
Fallback makes `would_be` null.

Cycles also log `pressure.iowait_blend`, top-level `iowait_blend`,
`curb_factor`, `pool_uncurbed` and `contention`. Enforce uses
event `dynamic_iops_enforce`, `mode=enforce`, and adds `read_caps` (the selected
variant bounded by lower explicit caps) and `read_action=apply|keep-kernel`.
These are allocation decisions; successful changed writes separately audit
`source=dynamic-iops`. Missing required inputs or incomplete configs record
keep-kernel with a fallback reason and warn in syslog; failed cycles/logging
also retain kernel read caps. See the [rollout procedure](../cgroup.md#enforced-read-iops-rollout).

The audit records successful write calls, not readback or effective placement.
IOPS `old` selects only the target device line (null if absent or malformed).
Unchanged values, failed writes, dry runs and IOPS `--census` create no audit.
Audit failures emit `cgroup-apply audit log failed` to syslog and do not change
the applied value, counters or cron status. Existing syslog messages remain.

Diagnostics' `io_sharing` section returns static host observations with
`unknown` for unavailable evidence: mode, local policy presence, cycle log age
in seconds, published ceiling, system.conf byte count, accounting assignments,
direct slice task count and all descendant account task count. The two counts
are totals of cgroup.procs lines, never names or PID lists. Configuration lines
do not establish the running manager's effective configuration. Hybrid PSI may
cover different task placement from v1 blkio. Iowait/PSI/writeback and membership
counts need correlation; none alone proves contention or safe cap activation.

## Counter-state persistence
Resource and ingress accounting share `pmssCounterStateUpdate()`. Its writer
checks rewind before truncating, stops on truncate or write failure, and verifies
the full payload and flush result. Persistence or mode-setting failures emit a
PHP error-log warning with the JSON-quoted state path, without the counter payload.
The caller's lock is released even when persistence raises an exception.
Normal JSON storage, mode 0600, delta calculations, and return keys stay unchanged.
This remains an in-place write: a short write or failed flush can leave incomplete
state; the warning reports that failure without changing accounting policy.
