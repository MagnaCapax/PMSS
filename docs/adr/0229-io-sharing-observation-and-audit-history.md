# ADR 0229: I/O sharing observation and audit history

Date: 2026-11-05
Category: architecture

## Status
Accepted for logging and shadow monitoring only; enforcement remains separate.

## Context
ADR 0177 records pressure/quota proposals, but explicit IOReadIOPS accounts
retain legacy proposals, hiding the counterfactual allocation. Host snapshots
lack CPU-window iowait and several contention clues. Applied BFQ/IOPS changes
reach short-lived syslog without an IOPS old value. Operators need evidence
that can be inspected later without changing any limit or proposal.

## Options Considered
- Document current data only: leaves missing observations and audit history.
- Add enforcement or an iowait-driven host IOPS pool: outside this decision.
- Extend existing collectors, shadow rows and successful-write audit: chosen.

## Decision
Keep the observation/parse split from ADRs 0162 and 0226. A separate pure
avg-cpu parser selects the last report by named columns. The second report of
the existing `iostat -xm 120 2 -g grp1` command describes the sampled 120 seconds.
Missing or malformed CPU reports yield null fields, without losing disk data.

Append these keys strictly after the existing `time` key: `cpuIowaitPct`,
`cpuBusyPct` (user + nice + system), `cpuStealPct`, `cpuIdlePct`,
`psiSomeAvg300`, `procsBlocked`, `dirtyKb`, `writebackKb`, `mdSyncActive`.
The last flag detects resync/recovery/check/reshape in mdstat; unreadable
interfaces yield null, while a readable inactive mdstat yields false.
This deliberately extends the serialized bytes described in ADR 0226;
the first fourteen keys, their order, types and values retain their contracts.
Only host figures enter the existing runtime/public iostat record.
The passive ceiling reads its named dimensions and time; the storage benchmark
reads only diskUtil. Neither reader uses the added fields for decisions.

Shadow pressure adds iowait windows at 5/15/60 minutes using the same buckets.
Missing iowait never changes the 12/4/1 full-I/O-PSI blend or selects fallback.
Each variant/account adds `would_be`, computed as if every IOReadIOPS were
unset. Its independent floor-lift pass includes all accounts. The variant adds
`would_be_floor_lift` and `would_be_charged_pool` for traceability. Both paths
share the existing floor, ceiling and rounding calculation. `proposed`, its
floor lift and charged pool remain unchanged; `would_be` is null on any
fallback and equals proposed when no accounts are explicit. No shadow value
reaches a writer. New records carry integer `schema_version=2`; absence means 1.

Per-account metrics optionally read a UID-built hybrid path:
`/sys/fs/cgroup/unified/user.slice/user-N.slice/io.pressure`. Shared numeric PSI
validation supplies `io_pressure_some_avg300`, `io_pressure_full_avg300`,
`io_pressure_some_total` and `io_pressure_full_total`. Averages are percentages,
totals are cumulative microseconds represented by the shared parser's floats.
Absent/unreadable/malformed fields are omitted independently. The injectable
cgroup root is retained. This is a read-only hybrid observation on production
v1 hosts, with no v2 writer or change to billing counters/format.

After an existing successful direct write, append one private JSON line to
`/var/log/pmss/cgroup-apply.jsonl`, overridable with `PMSS_CGROUP_APPLY_LOG`.
Use `pmssRunSnapshotLogTask` for mode 0600, umask restoration, guarded paths,
locking and complete writes. Rows contain UTC ISO-8601 `ts`, user, uid,
control (`read_iops`, `write_iops`, `bfq_weight`), old, new and source basename.
IOPS old is the integer for this device from the already captured file, or null
when no valid device line exists. No-op, failed write, dry run and IOPS census
emit no audit. Failures warn through syslog, without changing caps, counters,
exit status or the existing syslog messages. The row records a successful
write call, not a readback guarantee or proof of effective aggregate enforcement.

Both private JSONL logs use daily rotation, rotate 3650, missingok, notifempty,
compress, delaycompress, maxsize 50M and create 0600 root root. This replaces
ADR 0177's short shadow retention and follows ADR 0044's **2026-09-05 amendment**,
which supersedes its earlier uncompressed/keep-forever decision. Counts bound
generations, not guaranteed wall-clock history: size splits can shorten the
nominal ten years. Existing host and per-account metric retention is unchanged.

Diagnostics adds static host commands with unavailable fallbacks for shadow
mode, local policy presence, shadow log age, published read ceiling, system.conf
bytes, active DefaultIOAccounting assignments and two blkio membership counts.
One count covers cgroup.procs directly in account slices; the other includes
every descendant. Neither emits account names, PIDs or per-account rows.
Accounting assignments are configuration evidence, not proof of the running
manager's effective state. A missing hierarchy or failed task scan is unknown.

## Consequences
- Existing cron cadence and shipped off defaults remain unchanged. There are
  no new jobs, services, public files, ports, DNS records or certificates.
- Iowait, PSI, blocked tasks and writeback are clues, not standalone proof of
  contention. Hybrid pressure and v1 task membership can describe different
  populations. Compare timestamps and placement before drawing conclusions.
- Per-account data remains in private metric/shadow/audit files; public iostat
  contains host aggregates only. Exposing a private log would reveal account
  resource usage and limits, but no primary/service credential or access token.
- Threat review considered sysstat's 32-bit activity-file allocation overflow
  (CVE-2022-39377) and cgroup-v1 release_agent escalation (CVE-2022-0492).
  This parses existing text output and reads fixed kernel files; it introduces
  no activity-file reader or release_agent write. Existing BFQ/IOPS target
  validators remain intact. Guarded append rejects redirected log paths;
  failures log no payload or exception details. No credentials are collected,
  stored, reused or passed to a service, and no new network exposure is added.
- Storage grows with account count and change frequency, bounded by finite
  compressed rotations. Environment log overrides need their own rotation if
  they point outside the configured stanzas.
- Hermetic fixtures cover malformed/missing samples, legacy order and proposal
  values, counterfactual charging, numeric PSI, private audit/fault behavior and
  census/dry-run/no-op suppression. Dev-box verification is syntax only;
  behavioral validation and the fourteen acceptance cases belong on testing/QA
  machines. No host/fleet activation is authorized by this decision.

## References
- [Shadow allocation](0177-shadow-pressure-and-quota-iops-allocation.md)
- [Retention policy and September amendment](0044-observability-log-retention-uncompressed-long-term.md)
- [I/O placement](0176-opt-in-aggregate-cgroup-v1-io-placement.md)
- [Host observation boundaries](0226-host-statistics-observation-and-publication.md)
- [Parsing boundaries](0162-host-statistics-parsing-boundaries.md)
- [Logging locations and read commands](../observability/logging.md#io-sharing-observations)
- [Sysstat advisory](https://github.com/sysstat/sysstat/security/advisories/GHSA-q8r6-g56f-9w7x)
- [Cgroup-v1 advisory](https://access.redhat.com/security/cve/cve-2022-0492)
