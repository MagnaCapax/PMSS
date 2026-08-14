# ADR 0044: Observability metric logs retained long-term and uncompressed

Date: 2026-08-14
Category: architecture

## Status
Accepted

## Context
PMSS collects the richest per-server observability data every 5 minutes into two
on-node timeseries logs:

- `/var/log/pmss/system-stats.log` (`systemStatsLog.php`) — full 8-field PSI vector
  (some/full × avg10/60/300 + totals), `ioping_root` + `ioping_home`, load,
  cpu_iowait, mem/swap breakdown, disk_busy, top_mem.
- `/var/log/pmss/iostat-history.log` + `iostat-history-raw.log` (`diskIostat.php`) —
  iostat (iops/throughput/await/util/queue), PSI full_avg300 for io/mem/cpu, and
  ioping_home; the `-raw.log` also stores the verbatim iostat text.

The prior logrotate policy (`etc/seedbox/config/template.logrotate.pmss`) capped
`system-stats.log` at `daily rotate 7` (7 days) with `compress`, while the leaner
`iostat-history` pair kept `monthly rotate 12` (~1 year) with `compress`. Two
problems: (1) the *richest* signal had the *shortest* retention — backwards; a
multi-month saturation trend (observed on heshtok: 0.8% Jan → 85% May PSI) is
invisible in a 7-day window. (2) `compress` forces a decompress step before
`grep`/`awk`, adding friction to the exact forensic path these logs exist to serve.

Keeping these logs is nearly free: `system-stats.log` is ~200 B/line × 288/day
≈ 21 MB/year uncompressed; a decade is ~210 MB. The only real disk risk is the fat
`iostat-history-raw.log` (~365 MB/year), which needs a bound so a pathological node
cannot fill `/var/log`.

The prior policy was operator-committed (a1aa3e19, 650405b2, aleksi@magnacapax.fi)
and is superseded by an explicit operator directive on 2026-08-14: retention "should
be for ever" and "no compression initially".

## Options Considered
- Option A — keep `daily rotate 7` + compression. Minimal disk, but discards
  high-value trend/forensic history for near-zero savings and keeps history gzipped.
- Option B — `daily rotate 3650` uncompressed. Effectively forever but produces
  thousands of tiny files, unwieldy to grep.
- Option C — `yearly` rotation with a high rotate count, `nocompress`, and a
  `maxsize` runaway backstop; split the fat raw log into its own disk-bounded stanza.

## Decision
Choose Option C, scoped narrowly to the three observability metric logs the
directive names.

- `system-stats.log`: `yearly`, `rotate 100`, `maxsize 1G`, `nocompress`
  (retain `copytruncate`). ~100 years, one file/year (~21 MB), plain-text.
- `iostat-history.log`: `yearly`, `rotate 100`, `maxsize 1G`, `nocompress`,
  `create 0644 root root`. Structured metrics kept long-term.
- `iostat-history-raw.log`: split into its own stanza — `yearly`, `rotate 10`,
  `maxsize 512M`, `nocompress`, `create 0644 root root`. Uncompressed but
  disk-bounded (~2 GB worst-case) because it is the fat, lower-value-per-byte log.

`maxsize` forces early rotation if a file grows abnormally within its interval —
the runaway backstop that keeps "forever + uncompressed" from ever filling a disk.
Other logs in the template (update/user/quota/resource/storage-health/process-snapshot)
are out of this directive's scope and are left unchanged; extending the same
no-compression principle to them is a separate operator decision.

## Consequences
- Positive: multi-month/multi-year I/O saturation trends, capacity-planning history,
  and forensic data survive on every node, directly greppable without decompression.
- Positive: the richest signal now has the longest retention, correcting the prior
  inversion.
- Positive: `maxsize` backstops bound disk even on a pathological node, so removing
  compression does not create a disk-full outage risk.
- Negative: higher steady-state disk in `/var/log` (~21 MB/yr for system-stats,
  bounded ~2 GB for raw) — negligible versus the analytical value.
- Regression lock: `PmssLogrotatePolicyTest.php` asserts the new policy so a future
  change cannot silently re-introduce a short retention cap or compression.
- Follow-up: none required; deploys fleet-wide via routine `update.php` (verbatim
  `install -m 0644` + `cmp -s` verify).

## References
- Operator directive 2026-08-14 (verbatim): "7 day retention is premature ejaculation
  ... should be for ever"; "compression is another premature ejaculation ... no
  compression initially"
- `etc/seedbox/config/template.logrotate.pmss`
- `scripts/lib/tests/development/PmssLogrotatePolicyTest.php`
- `scripts/cron/systemStatsLog.php`, `scripts/lib/diskIostat.php`
- Prior policy commits: a1aa3e19 (Refs #163), 650405b2
