# ADR 0143: LSIO first-run login before port publication

Date: 2026-10-20
Category: security

## Status

Accepted for new installations; container lab verification pending.

## Context

Host loopback is shared between accounts. Binding a first-run wizard to
127.0.0.1 does not limit it to the installing account. The B6 live checks
confirmed the firewall accepts loopback before account rules; a two-account
rootless-Docker connection check and actual image launches cannot run here.
Existing containers, configurations and credentials must remain untouched.

## Options considered

- Seed through a published host port: retains the first-run exposure window.
- Guess undocumented bootstrap details: cannot establish a usable login.
- Seed through docker exec before publication: selected, with explicit refusal
  for an uncertain recipe or any failed check.

## Decision

New Jellyfin and Servarr containers first run without `-p` and with restart
disabled. An account-local PHP helper uses curl inside that container; secret
headers and JSON travel on stdin rather than argv or logs. Generated service
credentials use the existing atomic 0600 writer, independently of account
credentials, with no-replacement publication to preserve a concurrent install's
credential file. Nonempty config and prior credential files are refused before
creation. The existing container refusal remains before any modifying action.

| Image | First-run recipe | Anonymous verification |
|-------|------------------|------------------------|
| Jellyfin | GET Startup/Configuration, GET/POST Startup/User, POST Startup/Complete; authenticate the generated login; verify exactly one matching user | GET /Users must be 401/403 |
| Radarr | Read container /config/config.xml API key; GET /api/v3/config/host, PUT /api/v3/config/host/1 with Forms and authenticationRequired=enabled | GET /api/v3/system/status must be 401/403 |
| Sonarr | Same host-config flow under /api/v3 | GET /api/v3/system/status must be 401/403 |
| Prowlarr | Same host-config flow under /api/v1 | GET /api/v1/system/status must be 401/403 |
| qBittorrent | Retain main's pre-start config seeding with a random WebUI login and PBKDF2 password hash (ADR 0112) | Existing behavior; no temporary-password bootstrap |
| Transmission | Existing USER/PASS env-file path retained | Existing behavior |
| MariaDB | Existing generated database credentials retained | Existing behavior |
| phpMyAdmin | Existing database-login UI retained | Existing behavior |

On successful verification, stop the new container gracefully, remove only
that container without volumes, then recreate using its immutable image ID and
the same config volume, publishing only on 127.0.0.1. Seeding failures preserve
the unpublished container and files and report the failure. Unknown API shapes,
missing in-image curl, unexpected status codes and timeouts refuse publication.

Reconciliation onto main retains its pinned qBittorrent/Transmission images,
reserved WebUI and peer ports, labels and panel-refresh flow (ADR 0112).
Main already writes qBittorrent's login before its first container start, so
part-2b's temporary-password API recipe and cookie-session helper are omitted.
Its existing config/credential preservation tests remain in place.

## Review and consequences

Reviewed upstream advisory history: Jellyfin CVE-2021-21402 (file read),
CVE-2025-32012 (local-network restart authorization), and CVE-2026-49247
(authenticated log path traversal); Sonarr lists authentication bypass and
path traversal advisories. These demonstrate that app access may reach files
and processes running as the account. This change prevents uninitialized login
publication; it does not claim to remediate upstream bugs. Generated service
secrets grant app access only, never SSH/account access. Config mounts and media
mounts determine container file access. The MariaDB/Transmission credential
contracts remain unchanged; there are no new binds, permissions or root-side
account-file operations.

Dry-run and stub coverage verifies phase ordering, exact image/volume reuse,
0600 credential output, redaction, refusal on failed or ambiguous replies and
preservation of existing files. Before rollout, exercise each supported image
in a disposable account, check persisted login after recreation, and confirm
rootless published-port reachability from a second test account. No containers
or production accounts were accessed during implementation.

## References

- [Jellyfin StartupController](https://github.com/jellyfin/jellyfin/blob/master/Jellyfin.Api/Controllers/StartupController.cs)
- [Sonarr host-config controller](https://github.com/Sonarr/Sonarr/blob/develop/src/Sonarr.Api.V3/Config/HostConfigController.cs)
- [Radarr host-config controller](https://github.com/Radarr/Radarr/blob/develop/src/Radarr.Api.V3/Config/HostConfigController.cs)
- [Prowlarr host-config controller](https://github.com/Prowlarr/Prowlarr/blob/develop/src/Prowlarr.Api.V1/Config/HostConfigController.cs)
- [LSIO qBittorrent first-run instructions](https://docs.linuxserver.io/images/docker-qbittorrent/)
- [Jellyfin advisories](https://github.com/jellyfin/jellyfin/security/advisories)
- [Sonarr advisories](https://github.com/Sonarr/Sonarr/security/advisories)
