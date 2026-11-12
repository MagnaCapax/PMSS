# ADR 0271: Read caps follow the kernel

Date: 2026-11-12
Category: architecture

## Status
Accepted. Changes the shipped `cgroup.dynamicIops.mode` from `off` (ADR 0243) to `auto`.
ADRs 0230, 0231 and 0256 apply again wherever `auto` resolves to `enforce`.

## Context
ADR 0243 turned read caps off because Linux 6.1 blk-throttle queues ext4 metadata reads
behind the capped account's own data reads, which stalls the journal for the whole
filesystem. It allows `enforce` again only with a new ADR, on a kernel that dispatches
metadata ahead of the cap, validated on a few hosts first.

Linux 6.12 added that dispatch (commit 29390bb5661d). Stock Debian 6.12 and later kernels
are built without `CONFIG_MEMCG_V1`, so a host booted with cgroup v1 there has no
per-account memory limits. Moving to such a kernel trades the stall for unlimited memory
per account. A kernel needs both properties.

The Proxmox VE kernel (`proxmox-kernel-7.0`, trixie repository) has both:
`CONFIG_MEMCG_V1=y`, `CONFIG_BLK_DEV_THROTTLING=y` and the metadata branch in
`blk-throttle.c`. On a QA VM it installed on Debian 12 from the vendor package. With the
stall reproducer (an account capped at 5 read IOPS truncating a 4 GB file while a
neighbour fsyncs every second), two runs on 6.1 took 155.9 and 23.9 s, with 284 and 30
half-second samples of jbd2 waiting and neighbour fsyncs up to 38.0 and 8.6 s. Two runs on
7.0 took 1.1 and 2.1 s, with no jbd2 wait samples and neighbour fsyncs up to 61 and 89 ms.
Data reads stayed at the cap (4.8 of 5/s). During the truncate, 54 and 58 metadata reads
went past the cap at 20 to 30/s. A walk that reads only directory and inode metadata
still ran at the cap, 4.9 and 5.0 reads/s (802/s uncapped on 6.1). A memory limit set in
the v1 memory controller caused an OOM kill when exceeded. This was one VM with a virtio
disk, not a seedbox under real load.

## Options Considered
- Keep `off`: rejected; read caps exist so one account cannot take a whole disk.
- Set `enforce` everywhere: rejected; on 6.1 it brings the stall back.
- A per-host setting: rejected by ADR 0233.
- Check the kernel at run time: chosen. The kernel a host boots decides whether
  caps apply. No per-host configuration is involved.

## Decision
- New mode `auto`, the shipped default. Each cycle resolves it with
  `pmssDynamicIopsKernelReady()`. It returns true when the running release is 6.12 or
  later and both `/sys/fs/cgroup/memory/memory.limit_in_bytes` and
  `/sys/fs/cgroup/blkio/blkio.throttle.read_iops_device` exist. In that case `auto` acts
  as `enforce`; otherwise it acts as `off`, and the existing `off` path clears dynamic caps.
- `off` and `enforce` keep their meaning.
- Installing a kernel is a separate host operation, not part of PMSS. The first hosts to
  boot a qualifying kernel are the few-host validation ADR 0243 requires. They are
  compared against similar hosts left on 6.1, with a stop rule: a journal stall, or
  per-account read throughput below those hosts for the same demand. Either stop
  condition ends the trial by booting the previous kernel, after which `auto` resolves
  to `off`.
- Validation hosts are a trial, not a standing exception. If the trial passes, the
  kernel reaches every host through a repository change delivered by the paced rolling
  update, under its own ADR. Otherwise the trial hosts return to the fleet kernel.

## Consequences
- Hosts on 6.1 keep caps off, as they have been since ADR 0243.
- Stock Debian 6.12+ kernels resolve to `off`, so caps never apply where per-account
  memory limits are missing.
- The check is on the release number. A 6.1 kernel with the fix backported stays `off`.
- With malformed knobs the cycle keeps the kernel's current caps, as before.
- `agentDiagnostics` reports the configured string `auto`. The resolved mode is the
  `mode` field of enforce records in `/var/log/pmss/dynamic-iops.jsonl`, or their absence.

## References
- [Caps off after journal stall](0243-read-caps-off-by-default-after-the-linux-6-1-journal-stall.md)
- [Read caps enforced by default](0231-read-caps-enforced-by-default-and-contention-without-cap-stall.md)
- [Work-conserving read caps](0256-read-caps-are-work-conserving.md)
- [No host-local cgroup policy](0233-no-host-local-cgroup-policy.md)
