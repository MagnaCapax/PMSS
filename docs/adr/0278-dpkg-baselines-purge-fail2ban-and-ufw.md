# ADR 0278: dpkg baselines purge fail2ban and ufw

Date: 2026-11-13
Category: security

## Status
Accepted. Supersedes ADR 0010.

## Context

ADR 0010 left fail2ban "optional/unmanaged by PMSS" and kept a `fail2ban install`
line in the Debian 13 baseline. Two facts changed the picture:

- Hosts built from a shared base image carry fail2ban (an active sshd jail that
  bans client addresses) and ufw, although PMSS never configured either. Their
  bans reach real users of the server, and nothing in PMSS removes them.
- The operator's ruling, 2026-11-13: "we do not use fail2ban ... similar class
  regressions, and revert them all, tear it out."

The baseline applier (`pmssApplyDpkgSelections`) runs `dpkg --set-selections`
followed by `apt dselect-upgrade`, and it does not clear selections first. A
package that no baseline mentions keeps whatever state it has, so leaving fail2ban
"unmanaged" means it stays on every host that already has it. Debian 13 hosts use
the Debian 12 baseline until the Debian 13 one is validated
(`pmssSelectDpkgSelectionsBaseline`), so the Debian 13 install line had not yet
reached a host.

ufw is included as the same class: a host firewall layer PMSS does not manage, an
additive failure point that can block legitimate access. That inclusion is the
maintainers' classification of the operator's "similar class" wording.

## Options Considered

- Option A: keep ADR 0010 (unmanaged). Hosts that have fail2ban keep banning, and
  promoting the Debian 13 baseline would install it fleet-wide. Rejected.
- Option B: remove the packages with a one-off command per host. ADR 0272 forbids
  host changes outside the update run. Rejected.
- Option C: put `purge` lines for fail2ban and ufw in every baseline, so the next
  update run removes them and later runs keep them out (chosen).

## Decision

Every file under `scripts/lib/update/dpkg/` selects `fail2ban purge` and
`ufw purge`. The Debian 13 `fail2ban install` line is replaced. The sanitiser keeps
purge lines whether or not the package is available, because it filters only
`install` lines for availability.

`DpkgBaselineRemovesFail2banUfwTest` fails if any baseline drops either line or
selects either package for install or hold.

## Consequences

- Positive: the next update run removes fail2ban, its firewall chains and ufw
  from any host that has them, and no baseline can bring them back.
- Negative: an administrator who wants fail2ban or ufw on a PMSS host must keep
  it outside PMSS; the next update run removes it.
- Follow-ups: rollout through the normal paced update path (ADR 0272); host
  images built outside PMSS should not ship these packages either.

## References

- ADR 0010 (superseded), ADR 0272 (changes reach a server only through update).
- `scripts/lib/update/dpkgBaselineApply.php`, `scripts/lib/update/dpkgBaseline.php`.
