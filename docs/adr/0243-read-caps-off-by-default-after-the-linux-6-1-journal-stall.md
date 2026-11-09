# ADR 0243: Read caps off by default after the Linux 6.1 journal stall

Date: 2026-11-09
Category: architecture

## Status
Accepted. Changes ADR 0231's shipped default from `enforce` to `off`. ADR 0231's
mechanism, ADR 0230's pool and curb, and ADRs 0236-0239 stay as they are and run again
when the mode returns to `enforce`.

## Context
On the Debian 12 kernel (Linux 6.1), cgroup v1 blk-throttle queues every bio of a capped
cgroup in FIFO order and has no exemption for filesystem metadata. When an account
reading at its cap frees blocks, ext4 reads block bitmaps inside an open journal handle.
Those metadata reads are charged to the account and wait behind its own queued data
reads. The journal commit waits for the handle, so every write on the filesystem waits
too, while the disks sit idle. Hosts on the default-on build stopped committing `/home`
writes for hours and needed a restart.

Upstream fixed this in Linux 6.12 (commit 29390bb5661d, "blk-throttle: support
prioritized processing of metadata"). The fix is not in 6.1.y.

A first fix that lifted the cap of the account holding the journal was reverted
(4b04e015): it hands unlimited reads to the account the cap exists to hold.

## Options Considered
- Keep `enforce` and restart stalled hosts: the stall recurs on busy hosts every few
  hours; rejected.
- Lift the cap for the journal holder: rewards the account causing the stall; rejected.
- Ship `off` until hosts run a kernel with metadata-first dispatch: chosen.

## Decision
- The shipped `cgroup.policy.php` sets `cgroup.dynamicIops.mode` to `off`. On its next
  run the IOPS cron clears every dynamic read cap (the existing `off` path, audit reason
  `dynamic-iops-off`). Explicit per-account caps in the user config are kept.
- The mode returns to `enforce` only with a new ADR, after hosts run a kernel that
  dispatches metadata ahead of the cap (6.12 metadata-first dispatch or an equivalent
  backport), validated on a few hosts first.

## Consequences
- One heavy reader can again fill a shared disk queue for its neighbours, as before ADR
  0230. BFQ weights still order requests on the member disks.
- Kernel-level tenant controls go to a few hosts first and widen after days of soak,
  not through a default that reaches every host on its next update.
