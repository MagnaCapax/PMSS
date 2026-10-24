# ADR 0124: Curated qBittorrent static artifact entries

Date: 2026-10-20
Category: architecture

## Status

Accepted

## Context

ADRs 0105 and 0106 provide a marker-gated store and a Configure version picker,
but the shipped catalog contains only system entries. Issue #1068 supplies the
qBittorrent artifacts needed for explicit per-user selection (priority #983).
Deluge, Transmission and rTorrent artifact curation are separate work.

## Options Considered

- Build every version on each host: adds build infrastructure and dependencies.
- Require a PM mirror before curation: delays selection despite an existing
  HTTPS downloader with SHA-256 verification.
- Pin published userdocs static releases in the existing catalog: chosen;
  no new installer, storage layout or customer-side download path is needed.

## Decision

Curate the userdocs `qbittorrent-nox-static` x86_64 musl release assets for
4.3.9, 4.4.5, 4.5.5, 4.6.7, 5.0.5, 5.1.4 and 5.2.4, each with libtorrent 1.2
and 2.0. Exact release tags, asset URLs and SHA-256 pins live in
`etc/seedbox/config/torrentClients.json`. On 2026-10-20 all fourteen release
tags and asset names, URLs and sizes were checked against the release API;
every asset was downloaded and hashed. The four API-exposed digests for 5.1.4
and 5.2.4 also matched those downloaded bytes. The older ten had no API digest.
Downloaded binaries are not committed to this repository.

Labels use `<qBittorrent-version>-lt1.2` and `<qBittorrent-version>-lt2.0`.
The existing store calls `pmssInstallPinnedRemoteBinary()` with each HTTPS URL
and pin, installing to
`/home/.pmss/clients/qbittorrent/<label>/qbittorrent-nox`. The canonical basename
preserves process identity. `compatFamily` is `lt1.2` for that family (omit
DiskIOType), and the numeric qBittorrent release for lt2.0 (Posix before 5.0.1,
SimplePreadPwrite thereafter). ADR 0120's major-downgrade comparison recognizes
these suffixes so curated labels cannot bypass its refusal of a lower major.
Same-major libtorrent-family changes retain the existing snapshot/recovery path.

Only installed regular binaries are published through the existing root-owned
0644 `/var/lib/pmss/public/torrentClients.json` projection beneath the 0755
public directory (ADR 0073). The customer-local picker and resolver already
consume this view; catalog entries alone do not advertise missing binaries.
All system defaults remain unchanged and every curated entry is non-default.

Installation and publication still require `torrentClients.enabled`; this
change does not create that marker or select a version for any customer.
The strict reserve of more than 3 GiB plus the measured existing store size
free on `/home`, no-pruning behavior and all-throwables-caught app hook remain.

## Consequences

Explicit selection becomes available after an operator enables a staged host
and its updater successfully installs the pinned artifacts. HTTPS fetches from
upstream are tamper-evident against the reviewed pins: changed bytes fail
verification. A pin does not establish that an old release is vulnerability-free,
and upstream deletion or outages can still prevent a new installation.
Existing copies are retained; this curation is not live-host certification.

Re-hosting the same pinned artifacts at `pulsedmedia.com/remote/pkg` is a later
operator step for supply-chain sovereignty and reliability, not a blocker for
selection. Staged canary launch, WebUI authentication and configuration checks
remain operator rollout work under ADRs 0102 and 0106. No credentials, listeners,
account permissions or customer/operator include boundaries change here.

## References

- [userdocs releases](https://github.com/userdocs/qbittorrent-nox-static/releases)
- [Issue #1068](https://github.com/MagnaCapax/PMSS/issues/1068)
- ADRs 0073, 0105, 0106 and 0120

Host-marker opt-in clauses superseded by [ADR 0163](0163-torrent-client-store-on-by-default.md).
