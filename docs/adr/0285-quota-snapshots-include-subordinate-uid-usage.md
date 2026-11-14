# ADR 0285: Quota snapshots include subordinate UID usage

Date: 2026-11-14
Category: architecture

## Status
Accepted

## Context
Rootless containers write files under the account's subordinate UIDs. The
primary account's user-quota record excludes those owners, so both snapshot
writers underreport tenant usage (#1269). User and group quotas independently
charge the same inodes: adding their counters would count container data twice.

## Options Considered
- Query every subordinate ID individually: usually 65536 commands per account,
  incompatible with the minute cron cadence and bounded quota probes.
- Sum user and group reports: double-counts files with both mapped UID and GID.
- Sum primary and subordinate UID records from a numeric filesystem report:
  includes every owning UID once, regardless of the file's group, with one bounded
  report per filesystem per sweep.

## Decision
Both writers use `quota/snapshotUsage.php` before publication. It reuses
`pmssSubordinateIdRanges()` and the existing numeric repquota parser. Sparse
reports omit unused IDs; they contribute zero. Overlapping ranges and duplicate
records count each UID once. Group records are not added: files with subordinate
GIDs are already accounted for by their primary or subordinate owning UID.

Keep primary limits, grace fields and kernel over-quota markers; change only
used KiB and inode counts. This corrects measurement, without imposing limits
on subordinate IDs. Accounts without subordinate UID ranges retain their output.

## Consequences
- The root and display cron copies and provisioning display copy include mapped
  ownership. Exact KiB counters avoid rounding subordinate records before summing.
- Reports use the existing deadline and lock-descriptor closure. Failures use the
  existing failed-probe policy and timeouts stop the sweep without publication.
- Filesystem reports, including failures, are cached only within the PHP process.
- Hermetic fixtures cover aggregation and publication; execution stays on the
  testing machine. No live quota commands run during devbox verification.

## References
- #1269; ADR 0071 (subordinate ownership), ADR 0254 (snapshot boundaries).
- [Kernel quota subsystem](https://cdn.kernel.org/doc/html/latest/filesystems/quota.html).
- [Debian repquota manual](https://manpages.debian.org/buster/quota/repquota.8.en.html).
