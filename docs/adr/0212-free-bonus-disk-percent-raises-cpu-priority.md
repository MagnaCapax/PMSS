# ADR 0212: The Free Bonus Disk percent also raises CPU priority

Date: 2026-10-30
Category: behavior

## Status
Accepted

## Context
The provisioning backend writes each account's applied Free Bonus Disk percent
into the `~/.bonus` marker in the account's home through `writeHomeMarker.php`.
`cgroupBfqWeightApply.php` already multiplies the account's disk priority
(`blkio.bfq.weight`) by that percent. CPU priority (`CPUWeight` on
`user-<uid>.slice`) stayed at the plan value, so long-standing accounts got a
larger disk share but not a larger CPU share. The operator asked that the bonus
raise CPU priority on the same path as the bonus marker.

## Options Considered
- Compute the raised weight in the provisioning backend and push it with
  `userConfig.php` on every bonus change. Rejected: `userConfig.php` restarts the
  account's rTorrent, so every bonus top-up would interrupt the account.
- Extend `cgroupBfqWeightApply.php`. Rejected: it exits on hosts where BFQ does
  not drive `/home`, and CPU priority does not depend on the disk scheduler.
- A separate five-minute cron that reads the same marker and sets `CPUWeight`
  through systemd.

## Decision
`cgroupBonusCpuWeightApply.php` runs from root cron every five minutes and at
boot. For each managed account it takes the base weight `userConfig.php`
applies (explicit `CPUWeight` in the user config, else the RAM-derived default)
and sets `max(base, min(10000, round(base * (1 + bonus/100))))` with
`systemctl set-property`, only when the active slice differs. Nothing is
restarted. Both crons read the marker through one function,
`pmssUserBonusPercentRead()` in `lib/cgroup/policy.php` (moved there from the
disk-priority cron unchanged): a regular file of at most 64 bytes, links refused,
the value clamped to the 300% policy maximum (#945). The marker sits in the
account's home, so an account can pad its own percent up to that maximum; the
value clamp, not file ownership, is the accepted bound, as for disk priority.

## Consequences
- An account never drops below the CPU weight its plan and bought extras give
  it; a bonus only adds. Bought Extra CPU Weight is part of the base, so the
  bonus multiplies it too.
- `CPUWeight` is a relative share among sibling user slices under
  `user.slice`; system services are not affected.
- `userConfig.php` runs set the base weight again; the next cron run restores
  the bonus within five minutes.
- An ownership rule was considered and rejected: production markers are owned
  by the account, including recently rewritten ones, so it would have raised no
  one, and it needs an ownership migration that the value clamp makes
  unnecessary.
