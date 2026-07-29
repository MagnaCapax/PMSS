# ADR 0033: Retry stranded terminated-home reclaims

Date: 2026-07-27
Category: architecture

## Status
Accepted 2026-07-27. Superseded as design 2026-07-29 (Refs #729) — **retained as a
backward-compatibility shim**.

Account termination no longer renames homes aside: `terminateUser.php` removes the
home synchronously, so nothing creates `/home/.terminating-*` any more. The sweep and
its worker stay because hosts update on their own schedule and directories created by
any release between 2026-05-30 and 2026-07-29 may still be sitting on disk consuming a
full account's capacity. Releases in that window before 2026-07-27 had no retry at all,
so a worker killed by a reboot stranded its target permanently.

Retire this machinery only once no supported host can still be carrying such a
directory — on PMSS's usual multi-year compatibility horizon, not on the next cleanup
pass. Removing it early converts every stranded directory into a silent orphan with no
reaper and no diagnostics probe reporting it.

## Context

Account termination renamed a home to `/home/.terminating-*` and started the
existing guarded reclaim worker asynchronously. A worker interrupted by reboot,
resource pressure, immutable files, or another filesystem error can leave that
directory consuming capacity after the termination command has returned.

The retry path must preserve the worker's existing prefix, regular-directory,
realpath, post-`chattr`, and `-xdev` safety checks. It must also avoid starting a
second delete against a target whose original reclaim is still in progress.

## Options Considered

- Option A – add a root-cron sweep mode to the existing reclaim utility, using
  the encoded rename timestamp and a per-target lock. This reuses the worker and
  avoids a second deletion implementation.
- Option B – add a separate shell or PHP reaper. This duplicates entrypoint and
  safety knowledge and creates another long-lived maintenance surface.
- Option C – restore synchronous foreground deletion. This reintroduces the
  termination blocking behavior that the existing async path was designed to
  avoid.

## Decision

Choose Option A. Run `userHomeReclaim.php --sweep` every fifteen minutes from the
canonical root cron template. Only direct `/home` children with a valid PMSS
reclaim name and an encoded timestamp at least one hour old are eligible. The
worker and sweep share a non-blocking per-target lock for the full reclaim.

## Consequences

- Positive: interrupted reclaims are retried automatically and failures are
  visible in the cron log; diagnostics report the number of remaining targets.
- Negative: a periodic root job and small lock files add operational surface,
  and a reclaim can be retried only after the one-hour age threshold.
- Follow-ups: production verification should confirm the cron template is
  applied and that a deliberately failed reclaim produces a retry log entry.

## References

- GitHub issue #728
- `scripts/util/userHomeReclaim.php`
- `docs/contracts.md`
