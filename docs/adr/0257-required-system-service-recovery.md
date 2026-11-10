# ADR 0257: Required system service recovery

Date: 2026-11-10
Category: operations

## Status
Accepted

## Context

Issue #1256 reports that a stopped nginx can leave every account panel
unreachable until a manual start. The existing systemd service guard enforces
disabled-service policy but has no inverse recovery pass. It also runs from
an early-boot oneshot before `basic.target`.

## Options Considered

- Add restart drop-ins only: explicit stops can still leave required units down.
- Add another cron entrypoint: duplicates the existing service-guard scheduling.
- Extend the guard with a small, locked recovery pass: chosen.

## Decision

After existing disablement enforcement, recover only nginx, ssh, cron and
proftpd. Require exact `enabled` or `enabled-runtime` enablement and `inactive`
or `failed` activity. Disabled, masked, static, missing and transitional units
are skipped. Never enable, unmask, restart active services or reset failures.

Acquire `/var/lib/pmss/update.lock` non-blockingly and hold it through the pass;
skip on contention or acquisition failure. Export its descriptor for the shared
command wrapper to close in children, then release it even on exceptions.
Require systemd to report `running` or `degraded` before each unit so boot and
shutdown do not acquire dependency-blocking start jobs. Start actions use
`runStep()` and verify active state. Nginx additionally keeps its configuration
test and the shared positive-MainPID verification from ADR 0234.

## Consequences

- Existing per-minute and reboot cron invocations provide recovery and log
  actions/results to `/var/log/pmss/systemdServicesGuard.log`.
- Operator disablement or masking suppresses recovery; stopping an enabled
  required unit alone is temporary. Updates exclude recovery with their lock.
- The boot hardening unit continues enforcing disablement without early starts.
- Failed starts remain visible and retry on the next eligible cron pass.
- Cron cannot recover itself when no scheduler is running; its existing restart
  policy and updater repair remain necessary. No new listeners are configured.
- Hermetic tests cover policy and lock lifetimes on the testing machine;
  devbox verification is syntax-only and does not start services.

## References

- Issue #1256
- ADR 0210: Systemd service policy boundaries
- ADR 0234: Nginx activation stays with the running service manager
