# ADR 0023: Cron service TasksMax guard

Date: 2026-06-03
Category: architecture

## Status
Accepted, amended 2026-06-26

## Context
PMSS applies per-user process limits through `user-UID.slice` drop-ins, but
Debian cron starts user crontab jobs inside `cron.service` under `system.slice`.
Those jobs therefore do not inherit the per-user `TasksMax` limit. A runaway
user crontab can consume host-wide pid capacity even when the user's interactive
slice is capped.

The 2026-06-03 cron.service cap did not contain PMSS per-user services started
by root cron through `su - <user> -c ...`. Once cron launched `su`, descendants
such as `screen`, `rtorrent`, `lighttpd`, and `php-cgi` could remain outside
`user-UID.slice`; the existing per-user `TasksMax` policy therefore never
charged the runaway process tree. A php-cgi storm could still exhaust the host
pid table, making sshd unable to fork even though cron.service itself had an
aggregate `TasksMax=8192`.

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
fully solved.

2026-06-26 amendment: PMSS per-user service launchers now use a shared scoped
launch helper that resolves the user's UID with `id -u`, ensures
`user-UID.slice` exists, and starts the legacy `su` command inside a
`systemd-run --scope --slice=user-UID.slice` transient scope. This preserves the
old command shapes for service detection while making the user slice, not
cron.service, the accounting parent for `rtorrent`, `screen`, `lighttpd`,
`php-cgi`, qBittorrent, Deluge, rclone, and their descendants. The cron.service
cap remains defense-in-depth for user cron jobs that are not PMSS service
launches.

PMSS also installs an `ssh.service` drop-in with high CPU/IO weights,
`OOMScoreAdjust=-1000`, and `MemoryMin=64M`. This is defense-in-depth for
CPU/IO/OOM pressure only; the pid-exhaustion fix is the per-user slice
containment above.

## Consequences
- Positive: Runaway cron jobs can hit a bounded service-level task cap instead
  of host-wide pid capacity.
- Positive: The change reuses the existing PMSS-owned drop-in and does not touch
  package baselines or user crontabs.
- Positive: PMSS-managed per-user daemons now enter `user-UID.slice`, so the
  existing per-user `TasksMax` policy contains runaway descendants before the
  host pid table is exhausted.
- Positive: Watchdog process detection still uses the same process names and
  command payloads; only the cgroup parent changes.
- Negative: User-authored crontab commands outside PMSS service launch helpers
  remain bounded only by the aggregate cron.service cap.
- Follow-up: Revisit systemd-cron only if PMSS needs per-user isolation for
  arbitrary customer crontab entries.

2026-06-29 amendment (operator directive — Refs #579): the operator has REVOKED
the "accepted residual" above. User cronjobs escaping their per-user slice is NOT
an accepted state; the durable goal is full per-user cron isolation so user cron
lands in `user-UID.slice` (where the per-user CPUQuota/TasksMax bind it).

As the immediate recoverability guarantee (shipped this change), the
`cron.service` drop-in now also sets a core-aware `CPUQuota` that reserves at
least one logical thread for `system.slice` (sshd + root recovery):
`CPUQuota=(cpuThreads-1)*100%` for hosts with >=2 threads, no cap on single-thread
hosts. Rationale: a runaway user crontab in `cron.service` could previously
CPU-starve sshd's accept loop and lock root off the box (the romera wedge, where
SSH/22 timed out even with pids capped). Reserving a thread means such a storm can
never consume every core, so sshd stays answerable and the box stays recoverable
WITHOUT a physical reboot. Side-effect analysis: PMSS's own light periodic root
crons never approach this cap (it bites only during a storm); the per-user daemon
slices (CPUQuota in `systemdSlicesEnsure.php`) already bound user services; common-
case tenant CPU is unaffected. This is the recoverability floor, NOT the full
isolation — per-user cron isolation (systemd-cron or scoped crontab execution)
remains the open durable fix and closes the revoked residual.

## References
- PMSS issue #579
- ADR 0019: Production cgroup v1 pin
- `scripts/lib/update/services/systemd.php`
- `scripts/util/setupRootCron.php`
- `scripts/lib/user/serviceLaunch.php`
- `scripts/startRtorrent`
- `scripts/startLighttpd`
- `etc/seedbox/config/template.ssh.service.pmss-starvation.conf`
