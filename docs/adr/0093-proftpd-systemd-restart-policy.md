# ADR 0093: ProFTPD recovery through a systemd restart policy

Date: 2026-10-13
Category: architecture

## Status

Accepted

## Context

ProFTPD can fail to start when its hostname lookup is temporarily unavailable.
The failed unit then remains down until an operator restarts it, including after
boot, update, or log rotation. PMSS already installs managed systemd drop-ins
for service policy.

## Options Considered

- Add a cron watchdog: it would also restart a deliberately stopped unit and
  introduce another scheduled process.
- Use systemd's restart policy: it retries failed starts within the service
  manager and leaves an explicit `systemctl stop` in place.
- Leave recovery manual: an outage can persist after name resolution recovers.

## Decision

Install a ProFTPD drop-in with `Restart=always`, `RestartSec=30`, and
`StartLimitIntervalSec=0`. Keep it limited to restart policy; ProFTPD sessions
inherit unit resource settings, so the cron service's limits are inappropriate.
Unbounded retry allows recovery after a name-resolution fault lasting longer
than a finite start-limit window. Deploy the drop-in during the existing FTP
configuration step and reload systemd only when its content changes.

## Consequences

- Failed starts retry after 30 seconds; an explicit `systemctl stop` remains
  stopped. Operators must use systemd to stop the service because killing its
  master process now triggers a restart.
- The policy does not fix the hostname lookup dependency itself.
- Debian 10's init-script-backed generated unit has no reliable main PID, so
  this policy does not provide the same supervision there.
- Reverting code alone leaves an installed drop-in. Rollback must remove the
  managed `pmss-restart.conf` and reload systemd on affected hosts.

## References

- PMSS issue #941
- ADR 0023: cron service drop-in policy
