# ADR 0342: Per-host file quota ratio

Date: 2026-11-24
Category: architecture

## Status
Proposed. Refs #1290, #1291, #1292.

## Context
`userApplyDiskQuota` set every account's file (inode) limit to 500 files per GiB of disk
quota, with a floor of 15000 and a hard limit 1.25 times the soft limit. The panel list in
`resourcesListModel.php` repeated the formula. The limit protects shared hosts from metadata
IOPS spikes, inode and dentry cache growth and, on RAID5, read-modify-write churn from small
files. Most of that cost is an HDD cost. On SSD and NVMe `/home` the filesystems carry tens of
thousands of inodes per GiB and are almost empty of them, so an account with many small files
hits "quota full" on file count while most of its disk quota is free. The right ratio depends on
the host's storage class, and a code constant cannot differ per host.

ADR 0272 says that a setting an administrator chooses for one host, such as an address or an
interval written to `/etc/seedbox/config`, is configuration rather than an update step, and that
its ADR must identify it as per-host configuration. ADR 0091 (`mdadmCheckarrayMinDays`) is the
precedent for one integer in one file. ADR 0233 removed host-local cgroup *policy* because hosts
ran different behaviour without the repository showing it. The ratio differs from that case: every
host runs the same code and the file holds one integer that depends on the storage class.

## Options Considered
- Raise the constant for every host: wrong for HDD and RAID5 hosts, which the limit protects.
- Choose the ratio from the detected storage class in code: guesses at what the operator decides,
  and misreads mixed or unusual layouts.
- Per-host integer file, default 500 when absent: chosen. A host without the file behaves exactly
  as before.

## Decision
- `/etc/seedbox/config/quotaFilesPerGiB` is per-host configuration under ADR 0272. It holds one
  positive decimal integer, the files an account may hold per GiB of disk quota. A missing,
  empty, malformed, zero, negative or oversize value (above 1048576, ext4's limit of one inode per
  1024 bytes) means 500. The file is meant to be written by provisioning tooling from the host's
  storage class, not by hand on one host. The updater neither creates nor removes it.
- One helper, `scripts/lib/user/quotaFilesPerGiB.php`, reads the ratio and computes the limits:
  soft is the greater of GiB times the ratio and 15000, hard is 1.25 times soft. Both
  `userApplyDiskQuota` (enforced) and `pmssUserResourcesListQuotaState` (displayed) call it, so
  the two cannot drift.
- A changed ratio reaches existing accounts through the update. `pmssQuotaFilesReapplyRun`
  (`scripts/lib/user/quotaFilesReapply.php`, one step of `update-step2.php`) compares the effective
  ratio with the last applied one in `/var/lib/pmss/quotaFilesPerGiBApplied` (no record means 500).
  When they differ it runs `userApplyDiskQuota` for every account from its stored `quota`, then
  records the ratio. Accounts with no stored `quota` are skipped and logged. When the ratio went
  down, accounts above their new soft limit are logged. A failed apply leaves the record unchanged
  so the next update retries. Equal ratios do nothing, and so does a host with no file and no record.
- Diagnostics report `storage.quota_files_per_gib` (the effective ratio) and
  `storage.quota_files_per_gib_file_present` (`yes` or `no`), answering ADR 0233's concern that
  per-host values stay visible to the repository's tooling.

## Consequences
- Positive: SSD and NVMe hosts can allow far more small files per account without changing
  HDD hosts. Enforced and displayed limits cannot drift. Old accounts get a changed ratio on
  the next update.
- Negative: the file is a host setting that a reader of the repository does not see; diagnostics
  and this ADR are the visibility. Re-applying rebuilds block limits from the stored `quota`,
  which equals what `userApplyDiskQuota` set; a block limit set by hand outside PMSS would be reset.
- Follow-ups: writing the file on a host is a provisioning step outside PMSS. Accounts that hold
  more files than a lowered limit enter the kernel grace period; the log names them.

## References
- Issues #1290, #1291, #1292
- ADR 0272 (per-host configuration), ADR 0091 (per-host integer file), ADR 0233
- `scripts/lib/user/quotaFilesPerGiB.php`, `scripts/lib/user/quotaFilesReapply.php`
