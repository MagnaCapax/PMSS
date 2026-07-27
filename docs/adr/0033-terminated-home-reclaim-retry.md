# ADR 0033: Retry stranded terminated-home reclaims

Date: 2026-07-27
Category: architecture

## Status
Accepted

## Context

Account termination renames a home to `/home/.terminating-*` and starts the
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
