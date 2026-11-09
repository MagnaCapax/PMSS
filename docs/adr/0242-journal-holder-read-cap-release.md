# ADR 0242: Journal-holder read-cap release

Date: 2026-11-09
Category: architecture

## Status
Accepted for fleet deployment through the normal rolling update.

## Context
The cgroup-v1 read caps enforced by [ADR 0230](0230-enforced-read-iops-with-iowait-pool-curb.md)
and [ADR 0231](0231-read-caps-enforced-by-default-and-contention-without-cap-stall.md)
can block ext4 metadata behind the account's queued data reads. Before Linux
6.12, blk-throttle has no metadata exemption and preserves FIFO ordering.
An unlink/truncate caller can hold an open jbd2 handle while waiting in
`ext4_free_blocks` → `ext4_read_block_bitmap` → `ext4_wait_block_bitmap`.
The metadata read carries REQ_META|REQ_PRIO but belongs to the caller's blkcg.
The journal waits in `jbd2_journal_wait_updates`; other writers then wait in
`wait_transaction_locked`. Idle disks do not exclude this lock/throttle cycle.
The incident report records eight watchdog reboots on November 8–9.

Upstream commit [29390bb5661d, blk-throttle: support prioritized processing of metadata](https://git.kernel.org/pub/scm/linux/kernel/git/torvalds/linux.git/commit/?id=29390bb5661d)
dispatches metadata above the limit and carries the debt. Linux 6.12 and newer
therefore skip this workaround. Distribution backports are not inferred from
version suffixes. [ADR 0239](0239-enforced-read-ceiling-rises-but-never-falls.md)
prevents the separate ceiling ratchet; raising that ceiling does not remove a
metadata read already trapped behind the same account's data backlog.

## Options Considered
- Remove read caps globally: abandons account I/O sharing for an isolated stall.
- Raise caps or change allocation pressure: does not identify an open journal
  handle or ensure its metadata can progress.
- Release only a capped metadata holder after persistent journal-wait evidence:
  chosen, with existing write validators and normal cap convergence.

## Decision
`cgroupJournalHolderRelease.php` runs every minute at second 5, with the
canonical in-script nonblocking flock (ADR 0049). Cron wraps the process in
`timeout --signal=KILL 20s`, so even a blocked proc/sysfs/log read is bounded.
It finishes before second 25, away from the five-minute cap writer's second-50
start. Manual invocations should use the same timeout wrapper. The library also
stops scans at a 19-second deadline. There is no subprocess launch in PHP.

The shipped `cgroup.dynamicIops.journalHolderRelease` boolean defaults to true
and is read through `pmssDynamicIopsSettings()` (a malformed value keeps the
default and never disables the cap writer). False,
dry-run mode, Linux >= 6.12, unknown release syntax, non-ext4 `/home`, unresolved
device identity, or no matching kernel jbd2 thread are no-ops. In accordance
with [ADR 0233](0233-no-host-local-cgroup-policy.md), no host-local policy is
introduced. Proc, blkio and sys-block roots and the sample pause are injectable
arguments for fixtures; cron always uses the fleet paths.

The exact `/home` mount comes from proc mountinfo. The existing mount-source
resolver receives that source through its runner seam, avoiding shell execution;
the same device major:minor resolver used by `cgroupIopsLimitApply.php` reads
sysfs. The mount and sysfs major:minor must agree. Journal discovery checks
the kernel-thread flag and exact `jbd2/<device>-<journal>` stat name.
Two reads of that thread's stack, five seconds apart, must both contain
`jbd2_journal_wait_updates`. An interrupted sleep is not a second sample.

Only then does task enumeration read stat first, including worker TIDs under
`/proc/PID/task/`. Only D-state task stacks are read. A holder must show one of
`ext4_wait_block_bitmap`, `ext4_read_block_bitmap`, `ext4_read_bh`,
`__read_extent_tree_block`, `ext4_mb_init_cache`, or `ext4_read_inode_bitmap`.
Stacks containing `start_this_handle`, `wait_transaction_locked`, or
`jbd2_log_wait_commit` are excluded. Frame matches consume exact symbols.

The blkio membership record must contain one positive `user-N.slice` UID,
cross-checked against the real `Uid` in status. Duplicate/malformed memberships,
overflow, nested slice tokens and mismatched identities never authorize a write.
Membership is checked again before clearing. No membership path text enters the
target: `pmssCgroupDirectUserBlkioFilePath()` builds it and
`pmssCgroupDirectUserBlkioPathAllowed()` validates it. Only that account's
`blkio.throttle.read_iops_device` rule for `/home` is cleared, once per cycle,
through the existing sibling `pmssIopsClearThrottle()` (`MAJ:MIN 0`). Its device
and slice validators remain shared; clear rejects symlinks and short writes.
`pmssIopsWriteThrottle()` still rejects IOPS <= 0. Production default paths and
existing writer contracts are unchanged by the optional fixture-root arguments.

**There is no restore code.** Inspection of `pmssIopsWriteThrottle()` confirms
that it skips only an exact `MAJ:MIN IOPS` line match. Clearing removes the kernel
rule; the existing five-minute writer no longer finds its desired line and
rewrites the cap on the next successful allocation. Allocation failure still
keeps kernel state, so a release can last longer than five minutes when the
normal writer cannot select or apply a cap. Write caps and other users stay intact.

## Evidence and Security

Each release appends one row to the existing mode-0600
`/var/log/pmss/dynamic-iops.jsonl` via its safe snapshot writer, with `ts`,
`event=journal-holder-release`, `uid`, `cap_before`, `frame`, `stall_s=5` (the
observed lower bound), `kernel`, and `count_today`. The UTC per-UID count comes
from successful release rows in the active log and its uncompressed `.1`
rotation; an unreadable history or expired scan deadline skips releases.
Counts cover retained rows only if multiple rotations occur in one UTC day.
An outside/malformed identity produces `journal-holder-outside-user-slice` with
null UID; an uncapped holder produces `journal-holder-uncapped`. Such diagnostic
rows are deduplicated per UID (outside identities share one bucket) per cycle.
Quiet runs produce no rows. A failed release audit warns in syslog; it does not
undo the release or claim a second release occurred.

The relevant attack surface is account-controlled task activity inducing a
temporary cap release. Kernel stat state/flags and exact stack symbols gate
evidence; an account cannot request an arbitrary path through cgroup text. This
is a recovery mechanism, not a proof that any particular task owns a handle:
a simultaneous metadata read on another ext4 filesystem can be observed and
may temporarily release that account's `/home` cap. The journal and metadata
samples do not form an atomic kernel snapshot.
No primary or service credentials are read, stored or logged. Task cmdline,
environ and comm files are never read. Stat names are used privately for journal
identification only. Rows carry no task names, usernames or file paths. No
permissions, customer endpoints, public names or listening ports are added.

## Consequences and Verification
- A held transaction can progress without restarting services or rebooting;
  the identified account temporarily runs without its read cap.
- The exception ends through the existing writer, with no restore state or
  second allocator. The workaround retires automatically with kernel >= 6.12.
- Fixture tests cover persistent/transient stalls, metadata symbols, excluded
  waiters, worker threads, capped/uncapped/outside identities, UID mismatch,
  quiet gates, private logs and untouched neighboring caps.
- Run
  `php scripts/lib/tests/development/Runner.php JournalHolderRelease`
  on the testing VM. A live acceptance check should correlate a release row
  with journal progress and the next successful five-minute cap reapplication;
  no live cap write or destructive dry run is performed on the dev box.
