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

Entries may carry a plain optional `note` of 1–100 Unicode characters, without
control or Unicode format characters, and with at least one non-space character.
The installed-version projection carries
it only when set and no longer publishes the unused `badges` field. Catalog
`cve` lists remain separate from notes and are published as `cve` metadata;
removing the unused `badges` alias does not discard the CVE identifiers.
Configure renders escaped paragraphs
for each offered version under its picker; read-only cards show a note only for
the listed running build. This needs no JavaScript or customer-specific metadata.
The eight 4.3.9, 4.4.5, 4.5.5 and 4.6.7 variants carry the certificate-check note
for CVE-2024-51774, fixed in 5.0.1. NVD describes continuing HTTPS requests after
certificate-validation errors; upstream's 5.0.1 release notes confirm the SSL-error
fix. Later curated builds receive no note for this issue. These notes do not
certify the general security of any build.

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
- [NVD CVE-2024-51774](https://nvd.nist.gov/vuln/detail/CVE-2024-51774)
- [qBittorrent 5.0.1 release notes](https://www.qbittorrent.org/news.html)
- ADR 0182: qBittorrent WebUI settings follow the config's MigrationVersion.
- ADRs 0073, 0105, 0106 and 0120

Host-marker opt-in clauses superseded by [ADR 0163](0163-torrent-client-store-on-by-default.md).

## Amendment 2026-10-24: qBittorrent 5.3.0 with libtorrent 1.2 and 2.1

Two entries are added: `5.3.0-lt1.2` (userdocs `release-5.3.0_v1.2.20`) and
`5.3.0-lt2.1` (userdocs `release-5.3.0_v2.1.2`). Both x86_64 assets were
downloaded and hashed on 2026-10-24 and are pinned by sha256 like the others.
The newer family of the 5.3.0 series is libtorrent 2.1, so the label suffix
`lt2.1` joins `lt1.2` and `lt2.0`. The Configure label ("5.3.0 (libtorrent 2.1)"),
the ADR 0120 major-downgrade comparison and the build-number parsers on both the
operator and the customer side accept it; any other suffix stays an unrecognised
label. `compatFamily` is `lt1.2` and `5.3.0`; the disk-I/O rule above is unchanged.
Both entries are non-default, carry no note, and install only under the existing
space reserve.

qBittorrent 5.3 moves its WebUI settings from `[Preferences]` to `[WebUI]` the
first time it starts on a config. ADR 0182 keeps every PMSS reader and writer on
the section the config actually uses and restores the settings to `[Preferences]`
before an older build starts, so moving between 5.3.0 and the earlier builds keeps
the account port, password and access settings.

## Amendment 2026-10-25: qBittorrent Enhanced Edition 5.2.4.10

One non-userdocs entry is added, `5.2.4.10-ee-lt1.2-not-for-private-trackers`,
re-hosted on pulsedmedia.com and pinned by sha256. Its label, release parsing and
downgrade handling are in [ADR 0184](0184-qbittorrent-enhanced-edition-entry.md).
The userdocs rules above do not apply to it.
