# ADR 0140: Recalculate quotas during updates only when enforcement is off

Date: 2026-10-22
Category: architecture
Status: Accepted

## Context

Every update ran `quotaFix.php` from `update-step2.php`: `quotaoff -av`, `quotacheck -avugmn`, `quotaon -av`. On large `/home` arrays the cycle takes 10 to 17 minutes. `quotacheck` reads the quota limits when it starts and writes them back with the recounted usage when it ends, so a limit set while it runs is overwritten. A new account created in that window has no limit at all; an existing account keeps its previous limit. Provisioning and remote management set limits at any time, independently of updates.

Such losses were observed in production, each with its limit write inside a recalculation window. A host that updates several times a day also has enforcement off for every cycle.

`/home` is mounted with journaled quota (`usrjquota`, `grpjquota`, `jqfmt=vfsv1`, ensured by `pmssEnsureQuotaOptions`). The journal keeps usage consistent across crashes, so a recount on a host whose quota has stayed on since its last completed recount has nothing to repair.

## Options Considered

- Keep the cycle on every update and re-apply limits afterwards. The account JSON is not an authoritative limit source (limit changes can arrive outside it), so this could also overwrite correct limits.
- Serialize limit writes against the cycle with a lock. Limit writers outside PMSS do not take PMSS locks, and the enforcement-off window remains.
- Run the cycle during updates only when it can have something to repair.

## Decision

`update-step2.php` decides with `pmssQuotaHomeRecalculationNeeded()` before its quota-mount step, and runs `quotaFix.php` only when that returns true:

- the completion stamp `<state dir>/quota-recalculation.complete` is missing (the last cycle was killed, failed or never ran), or
- `quotaon -p /home` reports fewer quota types on than `/home` declares in fstab, or
- `/home` declares no quota types, or fstab cannot be read (the previous always-run behaviour).

The decision is taken before the quota-mount step because its remount turns journaled quota back on when it was off, without recounting usage. quotaFix removes the stamp before `quotaoff` and writes it only after a run with no critical failure and every declared type verified on. Run directly, quotaFix always runs the full cycle.

## Consequences

- Updates on healthy hosts no longer drop limits set during the update and no longer switch enforcement off.
- A cycle that is killed (the 1200 s step timeout) or fails leaves no stamp, so the next update recalculates even if a reboot or remount has turned quota back on in between.
- The first update with this change runs the cycle once, because no stamp exists yet.
- Usage recounts on a host whose last cycle completed and whose quota stayed on now happen only through a manual `quotaFix.php` run.
- Hosts that need the cycle still have the same race during that run.
- A `quotaon -p` exit status that undercounts enabled types makes the cycle run, which is the previous behaviour.

## References

- `scripts/util/quotaFix.php`, `scripts/lib/update/services/quota.php` (`pmssQuotaHomeRecalculationNeeded`).
- ADR 0013: ext4 native quota migration deferred.
