# ADR 0023: Cron service TasksMax guard

Date: 2026-06-03
Category: architecture

## Status
Accepted

## Context
PMSS applies per-user process limits through `user-UID.slice` drop-ins, but
Debian cron starts user crontab jobs inside `cron.service` under `system.slice`.
Those jobs therefore do not inherit the per-user `TasksMax` limit. A runaway
user crontab can consume host-wide pid capacity even when the user's interactive
slice is capped.

PMSS already owns a `cron.service` drop-in through `scripts/util/setupRootCron.php`
to keep cron available for watchdogs and quota jobs. Any fix must preserve the
existing vixie-cron path, avoid package baseline churn, and remain safe on
Debian 10/11/12 hosts pinned to cgroup v1 by ADR 0019.

## Options Considered
- Option A - replace cron with systemd-cron. This offers cleaner per-user
  cgroup placement but changes a base daemon and requires live-host rollout.
- Option B - add a `TasksMax` cap to the existing `cron.service` drop-in. This
  does not provide per-user fairness, but it immediately prevents cron from
  consuming host-wide pid capacity.
- Option C - wrap user crontab execution with `systemd-run --slice`. This is
  custom cron plumbing and would make PMSS own behavior that cron/systemd tools
  normally provide.
- Option D - reactive process watchdogs. These help for known long-running
  process names but do not close the structural fork path.

## Decision
Choose Option B as the immediate PMSS-controlled guard. The existing
`pmssCronRestartDropinContent()` payload now enables task accounting and sets
`TasksMax=8192` for `cron.service`, while keeping `Restart=always`.

This records an aggregate damage cap, not a claim that per-user cron fairness is
fully solved. Options A and C remain deferred until an operator selects a
single-host validation target and phased rollout plan.

## Consequences
- Positive: Runaway cron jobs can hit a bounded service-level task cap instead
  of host-wide pid capacity.
- Positive: The change reuses the existing PMSS-owned drop-in and does not touch
  package baselines or user crontabs.
- Negative: One user can still consume the cron aggregate cap, so this is not
  equivalent to placing every cron job in `user-UID.slice`.
- Follow-up: Revisit systemd-cron or a proven per-user launch wrapper only after
  live-host validation is explicitly planned.

## References
- PMSS issue #579
- ADR 0019: Production cgroup v1 pin
- `scripts/lib/update/services/systemd.php`
- `scripts/util/setupRootCron.php`
