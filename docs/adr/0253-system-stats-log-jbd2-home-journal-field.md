# ADR 0253: `jbd2_home` journal field in the system-stats log

Date: 2026-11-10
Category: architecture

## Status
Accepted. Extends the `system-stats.log` line of ADR 0044 and ADR 0226 with one appended
field. Related to ADR 0243 (read caps off after the Linux 6.1 journal stall).

## Context
ADR 0243 records that on Linux 6.1 a capped cgroup's queued data reads can hold back ext4
metadata reads inside an open journal handle, so the journal stops committing and every
write on `/home` waits while the disks idle. The log held PSI and ioping, which show that
I/O is slow but not that the journal stopped committing. Before read caps return, the cause
has to be measurable directly rather than inferred from pressure.

The kernel exposes the journal's own statistics in `/proc/fs/jbd2/<dev>-<journal inode>/info`
(`jbd2_seq_info_show`, `fs/jbd2/journal.c`). The output is identical in v4.19, v5.10, v6.1
and v6.12. What each figure is, from `fs/jbd2/commit.c` and that function:

- `transactions` (`ts_tid`) and `requested` (`ts_requested`) are exact counters since mount.
- `waiting for transaction`, `request delay`, `running transaction`, `transaction was being
  locked`, `flushing data` and `logging transaction` are cumulative since mount. The
  kernel keeps a jiffies sum per figure and prints `jiffies_to_msecs(sum / transactions)`
  (`request delay` divides by `requested`). The division is an integer division in jiffies,
  so each value is floored to a whole jiffy (4 ms steps at `CONFIG_HZ_250`, the Debian
  setting).
- `average transaction commit time` is `j_average_commit_time`, a moving average that
  weights the newest commit one quarter (`(new + 3*old) / 4`), printed in microseconds.
  It changes only when a commit completes.
- `handles`, `blocks` and `logged blocks` per transaction are further cumulative averages
  and are not logged.

## Options Considered
- Log nothing new and rely on PSI: cannot tell a stalled journal from slow disks.
- Parse tracepoints (`jbd2:*`) or `ps` state of the `jbd2/*` kernel thread: needs tracefs
  or a state sample that says nothing about the window; rejected as heavier and noisier.
- Append one field read from the `info` file of the journal of `/home` (chosen).

## Decision
Append `jbd2_home` as the last label of the line; `pmssSystemStatsLogLine` keeps every
earlier label, order and byte. The value is nine slash-joined unsigned integers in kernel
order:

`transactions/requested/wait_ms/request_delay_ms/running_ms/locked_ms/flush_ms/logging_ms/commit_us`

Example: `jbd2_home:5/2/0/0/3472/0/0/0/2602`. A missing or malformed sub-value is `na`
(units are checked, so microseconds where milliseconds belong are `na`). If the first line
is unreadable the whole field is `na`. With zero transactions the kernel prints no
averages and the seven averages are `na`.

Scope follows ADR 0070: only the filesystem at `/home`. `/proc/self/mountinfo` is read for
the last `/home` row; it must be `ext3` or `ext4` (both are journaled by jbd2 under the
ext4 driver; ext2, xfs, btrfs and the rest have no jbd2 journal). Its `major:minor` is
linked in `/sys/dev/block/` to the kernel block name, which is what the journal directory
uses (`md3`, `dm-0`, `sda1`, `nvme0n1p1`; not the `/dev/mapper` name). Unlike ADR 0070's
iostat scoping, partitions and md/dm members are not walked to leaf disks: the journal belongs to
the filesystem's own device. Exactly one `<name>-<number>` directory must match; none,
several, an external journal (named after the journal device, no number), `/home` not a
separate mount, or any unreadable step gives `na`. The read is a cheap in-kernel text file:
opening it copies the statistics under the short `j_history_lock` spinlock (Linux v6.1
`jbd2_seq_info_open`) and never takes the handle or commit locks, so a stalled commit does
not block it. The inputs are kernel files (`/proc`, `/sys`), not tenant-writable paths, and
every logged value is an unsigned integer or `na`, so no text can enter the line.

### How a consumer must use the field
- Counters: `transactions` and `requested` are monotonic per mount. For a window, take the
  difference of two samples; a negative difference means a remount, reboot or a different
  filesystem, and that window is discarded. A window with writes in flight and a
  `transactions` difference of 0 is the stall signature (an idle filesystem also commits
  nothing, so pair it with write activity such as `disk_busy` or `psi_io`). A healthy ext4
  commits at least every 5 s while dirty.
- `commit_us` is a snapshot of the last few commits, not a window maximum, and does not move
  while a commit is stuck.
- The five `_ms` values are lifetime averages, not windows. A window average cannot be
  recovered by differencing `average * transactions`: each kernel value is floored by up to
  one jiffy of the true average, so the reconstructed sum is off by up to `transactions`
  jiffies and the window error is about `(N1 + N2) / (N2 - N1)` jiffies. After months of
  uptime (millions of transactions) that exceeds any real stall. They are useful only as a
  lifetime baseline, or shortly after mount while `transactions` is small.

## Consequences
- Positive: a stalled journal is directly observable (`transactions` stops advancing)
  before any read cap returns. Cost is one regular-file read per five-minute cron run.
- Negative: ~45 more bytes per line (~13 KB a day), within ADR 0044's retention arithmetic.
  Window averages of the six latency figures are not obtainable from this source;
  that needs tracepoints or a kernel with per-window counters, and would be a new ADR.
- Compatibility: every parser of the line must key on labels; this repository has none
  that parse it positionally (`agentDiagnostics` only tails it, host pressure uses the
  collected stats array). External consumers ignore labels they do not know.
- Follow-up: the sysadmin-side collectors difference `transactions` and `requested`.

The observer rejects NUL bytes in all three filesystem path arguments before
reading mountinfo, resolving the sysfs link or enumerating journals. Malformed
paths retain `na` without relying on warning suppression to handle exceptions.
Valid paths, probe order and journal formatting remain unchanged.
`SystemStatsJbd2Test` covers leading, embedded and trailing NULs in each argument
and the successful fixture result; execution stays on testing machines.

## References

- ADR 0044, ADR 0070, ADR 0226, ADR 0243
- `fs/jbd2/journal.c` `jbd2_seq_info_show`, `fs/jbd2/commit.c` statistics accumulation (Linux v6.1)
