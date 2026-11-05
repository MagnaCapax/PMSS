# ADR 0231: Nginx activation stays with the running service manager

Date: 2026-11-05
Category: reliability

## Status
Accepted

## Context

Issue #1230 reports nginx serving outside systemd after fresh installation and
account creation reporting a completed restart despite a bind failure. The
origin of the unmanaged master is unconfirmed. Config activation and updater
shutdown recovery currently fall back from systemd to init-script actions;
account creation additionally masks all restart failures with `true`.

## Options Considered

- Retain fallback actions and only improve account-creation logging.
- Automatically signal an unmanaged master before starting the systemd unit.
- Keep activation with the running service manager and verify its result.

## Decision

Share nginx service command construction between config activation and updater
shutdown recovery. When `/run/systemd/system` exists, use only systemd. Reload
uses `reload-or-restart`, so an inactive unit starts through its manager. Require
the action, `is-active`, and a positive MainPID lookup to succeed. Without systemd,
retain init-script actions. Existing explicit command overrides remain available.
Keep the config test before activation, including shutdown fallback starts.

Account creation uses its existing required-step wrapper to regenerate the
single-user config and run the validated restart path. A failed restart produces
a failure summary and nonzero exit; the already configured account remains for
repair under the existing rollback boundary.

## Consequences

- A failed systemd action cannot be hidden by a successful init-script action.
- Existing unmanaged masters and listener conflicts require operator diagnosis;
  no automatic process signaling or listener takeover is introduced.
- This prevents the fallback paths from accepting unmanaged service operation;
  it does not establish what launched the originally reported orphan.
- Hermetic tests cover service action/state/PID failures, non-systemd commands,
  shutdown traces and the account success guard. Execution belongs on the testing
  machine. Post-install verification must inspect service state and confirm that
  its MainPID owns the configured HTTP/TLS listeners on a fresh host.

## References

- Issue #1230
- [ADR 0014: Update step failure classification](0014-update-step2-step-error-classification-policy.md)
- [systemctl service commands](https://www.freedesktop.org/software/systemd/man/247/systemctl.html)
