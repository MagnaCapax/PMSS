# ADR 0272: PMSS changes reach a server only through update

Date: 2026-11-12
Category: architecture

## Status

Accepted

## Context

PMSS updates are rolling and paced. Some changes have also required an outside
step to place a host file before installed code takes effect. That leaves the
installed version insufficient to describe the behaviour a server receives.
One-time host changes need the same delivery path as the code that owns them.

## Options Considered

- Push individual files or commands and finish deployment with outside tools.
- Ship a fleet-wide change behind a host file placed outside PMSS.
- Deliver code and managed host changes through the PMSS update run, using
  the rolling update pace as rollout control (chosen).

## Decision

Every change to PMSS code and to the host state PMSS manages reaches a server
only through the PMSS update run (`update.php`). A one-time host change is a
migration step inside that run.

The following are not allowed:

- Pushing individual files or commands to servers outside the update run.
- A fleet-wide feature or fix that stays off until something outside PMSS
  places a host file.
- Outside tools that finish, enable or re-apply what an update installs.

The rolling update pace is the rollout control. A change that is not ready
ships switched off in code and is switched on by a later release, rather than
by a host file.

A setting an administrator chooses for one host, such as an address or an
interval written to `/etc/seedbox/config`, is configuration rather than an
update step. Its ADR must identify it as per-host configuration.

Existing host-file switches are reviewed against this rule:

- `qbittorrentLoopbackOwnerGuard.enabled` (ADR 0259): removed by this change.
- `pikkumiesPreinstall.enabled` (ADR 0090): removed as a requirement by this change.
- `pikkumiesEternals.enabled` (ADR 0128): still a host file; the feature has an open defect and stays off until a release fixes it, then it is switched on in code.
- `socket-table-privacy.enabled` (ADR 0068): review individually; either make it the default in a release or document it as per-host configuration.
- `qbittorrentWebuiEnforce.enabled` (ADR 0102): review individually; either make it the default in a release or document it as per-host configuration.
- `agentWebLogin.enabled` (ADR 0151): review individually; either make it the default in a release or document it as per-host configuration.
- `ext4-group-prealloc.enabled` (ADR 0170): review individually; either make it the default in a release or document it as per-host configuration.
- `arp-keepalive` (ADR 0069): a per-host address setting, so it is configuration.
- `mdadmCheckarrayMinDays` (ADR 0091): a per-host interval setting, so it is configuration.

This decision supersedes the marker-staging part of ADR 0090's amendment and
the marker in ADR 0259. It does not change the other switches listed above.

## Consequences

The version label on a server describes the code that is actually there.
Code and one-time migrations arrive together through the update run and can
be reviewed together. An urgent fix reaches servers at the update pace.
Readiness is a release decision; no outside action completes a rollout.

## References

- [ADR 0090: Separate per-user Pikkumies installer](0090-separate-per-user-pikkumies-installer.md)
- [ADR 0259: qBittorrent loopback service owner guard](0259-qbittorrent-loopback-service-owner-guard.md)
- [Update workflow](../update.md)
