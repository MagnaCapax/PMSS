# ADR 0343: Seed missing account-local Prowlarr application links

Date: 2026-11-24
Category: security
Status: Proposed

## Context
The per-account installer secures Prowlarr, Radarr and Sonarr but leaves their
application links empty. Refs #1315. The installer remains usable as a standalone
download; optional application defaults must not prevent authentication or startup.

## Options considered
- Leave both links manual: preserves behavior but repeats copying service keys.
- Rewrite application settings on every install: overwrites customer choices.
- Add only missing providers through the running app's schema and normal save API:
  selected. Keep this optional work in an account-local helper delivered by updates.

## Decision
After normal protected startup, load `~/bin/mediaStackProwlarr.sh` when available.
Read `/api/v1/applications` and `/schema` using Prowlarr's validated local API key.
For each sibling, retain its schema defaults, substitute loopback URLs including
UrlBase and the sibling's validated API key, and set `syncLevel=fullSync`.
Any existing matching provider or case-insensitive name suppresses creation,
including renamed and disabled links. No existing application is updated.
Wait boundedly for authenticated APIs, then use normal POST validation, without
force-save. Missing apps, unknown schemas and failed/uncertain saves log warnings;
an uncertain POST is never retried automatically. Reruns re-read the live list.
No indexers or download clients are created. Prowlarr requires a download client
only for direct searches; sibling-initiated searches use their own clients.

## Security review and consequences
Reviewed Prowlarr's published advisory listing and Sonarr's authentication-bypass
and path-traversal advisories. NVD CVE-2025-13130 (Radarr) and CVE-2025-13131
(Sonarr) concern Windows service permissions; they do not describe this Linux flow.
The advisory review does not establish that upstream apps are free of vulnerabilities.
Exposure of a linked API key grants sibling API access, with potential access to
the account's files/processes; it does not disclose the SSH/account credential.
Prowlarr now holds both sibling service keys in its existing account-local store.
The helper neither reads primary passwords nor changes service-store permissions;
the existing installer makes each application config directory mode 0700.
Key-bearing temporary files use umask 077 in a private directory removed on exit;
curl receives key headers and payloads through files, never argv. Raw replies,
payloads and keys are not logged. Loopback requests bypass outbound proxies and
do not follow redirects. Auth, binds, ports and update policy remain unchanged;
no root permission, public identifier or customer/operator boundary changes.

The current stable source reviewed was Prowlarr v2.7.0.5723. Runtime schema fetching
preserves version-specific category defaults. Full sync lets Prowlarr manage the
indexers it creates in sibling apps; customers can change the link afterward.
Older accounts without the delivered helper retain manual setup with a warning.
Hermetic fixtures cover payload guards, preserved settings, failures and cleanup.
Only changed-file syntax checks run here. Before rollout, the testing VM must
exercise fresh install, Settings > Apps tests, category-compatible indexer sync
and rerun preservation against actual apps. No live-service result is claimed.

## References
- [Prowlarr application mapping, v2.7.0.5723](https://github.com/Prowlarr/Prowlarr/blob/v2.7.0.5723/src/Prowlarr.Api.V1/Applications/ApplicationResource.cs)
- [Prowlarr provider validation](https://github.com/Prowlarr/Prowlarr/blob/master/src/Prowlarr.Api.V1/ProviderControllerBase.cs)
- [Prowlarr direct-search download clients](https://github.com/Servarr/Wiki/blob/master/prowlarr/quick-start-guide.md#download-clients-prowlarr-searches)
- [Prowlarr advisories](https://github.com/Prowlarr/Prowlarr/security/advisories)
- [Sonarr advisories](https://github.com/Sonarr/Sonarr/security/advisories)
- [Radarr CVE-2025-13130](https://nvd.nist.gov/vuln/detail/CVE-2025-13130), [Sonarr CVE-2025-13131](https://nvd.nist.gov/vuln/detail/CVE-2025-13131)
- ADR 0144 (private login setup), ADR 0150 (public exposure)
