# ADR 0063: Passive host I/O ceiling estimates

Date: 2026-09-17
Category: architecture

## Status
Accepted

## Context
Issue #895 needs a host measurement for future cap derivation, without applying
caps or collecting new data. A rolling percentile can fall as quiet samples
arrive even when no samples expire. Including an unfinished day has the same
problem with a maximum of daily percentiles.

## Options Considered
- Whole-window percentile: cannot preserve the requested floor within a window.
- Persistent high-water accumulator: needs separate expiry and recovery state.
- Maximum of completed UTC day percentiles: reproducible from existing history.

## Decision
Use the last option, with nearest-rank percentiles and independent dimensions.
Defaults in `cgroup.policy.php` are P95, seven completed days, at least 144 unique
samples per day and three qualifying days. Settings accept integer percentiles
1–100, windows 1–31 days, sample gates 1–288 and day gates 1–windowDays.
Use the serialized epoch timestamp, ignoring the log's local-time prefix.
The measurement frame remains the collector's grp1 physical-device aggregate,
120-second averages every five minutes; it is not a per-device capacity test.

The existing policy refresh writes `/var/run/pmss/io-ceiling.json` atomically.
Missing, thin or invalid input removes the old cache; nothing writes user config
or applies this estimate to a controller. Consumers must check `computed_at`
for freshness. `published.from_day` is a map per dimension because maxima can
come from different days; daily fields use `_percentile` plus the top-level
`percentile` value so changing P95 does not mislabel data.

Read at most 16 MiB each from the active log and `.1`, reject incomplete or
oversized records, and exclude the first cut day after a byte-budget seek.
ADR 0044's September amendment retains ten yearly rotations with a 1 GiB split
and delaycompression. Older compressed rotations are deliberately not scanned:
if the remaining data misses the gates, no estimate is published. Deduplicate
timestamps across the active log and rotation.

## Consequences
- Quiet days cannot lower a ceiling while its winning completed day remains
  available inside the window. Window expiry can lower it.
- New highs wait until the next refresh after midnight UTC (up to roughly
  26 hours on the existing two-hour cadence).
- Reboots rebuild the disposable cache. Lost/truncated history can suppress or
  change estimates; this cache is never an enforcement authority.
- No new cron, collector, benchmark, dependency or cgroup-v2 feature is added.

## References
- Issue #895, including both completed-day specification amendments.
- ADR 0019 (production cgroup v1) and ADR 0044 (metric log retention).
