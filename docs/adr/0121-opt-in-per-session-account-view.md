# ADR 0121: Opt-in per-session account view

Date: 2026-10-19
Category: domain

## Status

Accepted (default off)

## Context

ADR 0118 sets the account-name presentation rule for shared hosts. PAM sessions
and lingering user managers need a consistent account database for that rule.
ADR 0011 requires an opt-in follow-up with an owner, rollback, observability,
and Debian validation before adding session policy.

## Options Considered

- Keep the shared account database in every session. This does not implement
  ADR 0118's session behavior.
- Build a view for each session from the host account database. This preserves
  the normal account records needed by system services and the current account.

## Decision

The operator owns the marker `/etc/seedbox/config/account-view.enabled`.
Without it, the updater does nothing on hosts that have never enabled the
feature. With it, the updater installs a PAM Additional profile for both session
stacks, a namespace configuration and init script, a local generator copy, a
user manager drop-in, and a root mount propagation unit. Installation requires
`/etc/passwd` on `/` and a separate `/home` mount. The generated views live in
root-owned, mode 0700 directories under `/var/lib/pmss/account-view/`; their
files are mode 0644 and are mounted read-only in the session. A user manager
receives the same files by read-only binds.

The updater verifies that `pam-auth-update` rendered the three session rules
in order in both common session files. A missing render is logged as inactive.
The generator records one `authpriv.err` line on failure, identified by UID.

Rollback: remove the marker, run the next update to remove the managed profile
and artifacts, then reboot to retire active namespaces and restore the original
root mount propagation state. Existing sessions remain on their prior view until
they end.

## Consequences

Debian 12 and later hosts with a separate `/home` mount are the supported pilot
matrix. Debian 10 and 11 remain unchanged. Pilot validation covers interactive
and noninteractive PAM sessions, user managers, source refresh, rollback, and
reboot. The default remains off until that matrix has operational evidence.

## References

- ADR 0011, ADR 0118
- `scripts/lib/update/systemPrep/accountView.php`
- `scripts/lib/update/systemPrep/accountViewGenerator.php`
