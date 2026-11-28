# ADR 0346: Seed the movie root during fresh Radarr authentication

Date: 2026-11-28
Category: security
Status: Proposed

## Context
The media-stack installer secures Radarr but leaves its root folder empty.
Issue #1313 also requests a working download client and Jellyfin visibility.
The rTorrent external-client dependency #1213 remains blocked; its raw RPC
entry points still forward untrusted requests. This change delivers the root
folder independently and does not claim to complete the client/library workflow.

## Options Considered
- Rewrite defaults on every install: would undo customer choices.
- Start Radarr again for defaults: unnecessary extra start and exposure surface.
- Add the root only to a fresh database during its existing private start:
  selected. Older standalone installers remain usable without the optional helper.

## Decision
Before starting the private authentication process, record whether Radarr's
database is absent, including no dangling symlink. After authentication succeeds
and its unauthenticated API probe is refused, load the delivered account-local
`bin/mediaStackRadarr.sh` helper. Only an empty, valid root-folder list authorizes
creating `~/data/Movies` and posting that path through the normal root-folder API.
Malformed replies, existing roots and failed requests never trigger forced saves.
No additional start, shared-loopback fallback or existing-database mutation is added.

Radarr defaults `CopyUsingHardlinks` to true in both the supported older pin and
the reviewed stable release. Keep that upstream setting instead of rewriting it.
Downloads and movies must share a filesystem for hardlinks. No client, torrent
version, RPC trust, instance name or Jellyfin library is changed by this patch.

## Consequences
Fresh installs with the delivered helper receive a movie root under the account's
data folder. Existing databases retain all settings, including deliberately
removed roots. Missing helpers and API failures warn and retain secured startup;
failed first starts are not retried against an existing database. The customer
can add the root manually. A working client and Jellyfin Movies library remain
outstanding for #1313, with client seeding dependent on #1213.

Security review checked Radarr's advisory listing and CVE-2025-13130, which
concerns Windows service permissions rather than this Linux setup. An empty
advisory listing does not prove safety. API-key exposure can grant access to
the account's application and files; the helper reuses the existing private
curl key file and never reads account passwords or writes service credentials.
API replies/payloads use a 0700 workspace and 0600 files removed on exit. Requests
bypass outbound proxies, do not follow redirects, and log neither keys nor raw
replies. Normal upstream path/writability validation, authentication verification,
binds, ports and update policy remain in place (ADR 0144).

Hermetic fixtures cover fresh/existing/malformed roots, request and filesystem
failures, private-network gating, uncertain POSTs, permissions and cleanup.
Only changed-file syntax checks run on the devbox; tests and real-app checks
belong on the testing VM. No import or live-service success is claimed.

## References
- [Radarr pinned configuration defaults](https://github.com/Radarr/Radarr/blob/v5.10.4.9218/src/NzbDrone.Core/Configuration/ConfigService.cs)
- [Radarr stable configuration defaults](https://github.com/Radarr/Radarr/blob/v7.0.3.10918/src/NzbDrone.Core/Configuration/ConfigService.cs)
- [Radarr root-folder validation](https://github.com/Radarr/Radarr/blob/v7.0.3.10918/src/Radarr.Api.V3/RootFolders/RootFolderController.cs)
- [Radarr advisories](https://github.com/Radarr/Radarr/security/advisories)
- [CVE-2025-13130](https://www.cve.org/CVERecord?id=CVE-2025-13130)
- ADR 0144 (private login setup), ADR 0146 (rTorrent trust/default policy)
