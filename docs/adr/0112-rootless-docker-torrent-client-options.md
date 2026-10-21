# ADR 0112: Rootless Docker torrent-client options

Date: 2026-10-18
Category: domain

## Status

Accepted.

## Context

`docker-install-lsio` offers qBittorrent and Transmission, but no Docker
Deluge. The per-user lighttpd panel has a native Deluge proxy and a
native Transmission socket route. The Docker install command runs as the
customer, while root owns lighttpd configuration generation.

## Options considered

- Add panel install controls: changes the read-only Docker catalog posture.
- Add CLI presets and use the managed proxy renderer: keeps installation opt-in
  and shares the existing lighttpd path.

## Decision

The CLI offers a Transmission LSIO container. Its WebUI port binds
to `127.0.0.1`; the container carries a `pmss.client` label. The installer
requests a lighttpd refresh after a successful install. The root-side refresh
accepts only a running, correctly labelled container with a loopback publish,
then renders a Transmission TCP fragment. The native Deluge proxy is unchanged.
The native Transmission socket route remains available when no Docker
Transmission is running. The panel's Docker catalog stays read-only (ADR 0088).

Transmission receives separate random `USER` and `PASS` values from a private,
account-owned env-file. The image enables RPC authentication when both are set;
its empty `WHITELIST` disables the RPC whitelist, without exempting loopback
from authentication. The Docker proxy route retains the panel authentication
gate and removes the panel `Authorization` header before forwarding to the
WebUI, using the request-header convention in ADR 0111. No container is
installed by an update alone. Deluge is absent from the Docker installer and
proxy because the LSIO image documents only changing its known default WebUI
password through the WebUI.

## Consequences

- The native Deluge proxy is unchanged. The native Transmission socket route
  remains in use unless the customer runs Docker Transmission.
- The per-user lighttpd watchdog completes proxy refresh after CLI install.
- Transmission requires a strong per-install service login even from host
  loopback. The owner reads it from the mode-0600 credential file; no service
  secret appears in Docker argv or installer output.
- Host loopback is shared across accounts. Another local account can reach the
  auth-gated port. #886 addressed public-interface binds; daemon-level
  loopback isolation remains a separate stronger fix.
- Deluge is not offered as a Docker client or proxied to a Docker container:
  its image starts with a known WebUI password and has no supported first-install
  password variable.
- Transmission uses HTTP Basic authentication. The panel also uses HTTP Basic
  and strips its `Authorization` header, so the managed proxy cannot complete
  a separate Transmission login until that two-login path is redesigned.
- Fixed default host ports may conflict with another listener. Per-account
  media-stack port reservations and a catalog-wide digest pin are separate work.
- Selecting Docker as a fleet default is an operator decision outside this
  change.

## References

- [Deluge LSIO image](https://docs.linuxserver.io/images/docker-deluge/)
- [Transmission LSIO image](https://docs.linuxserver.io/images/docker-transmission/)
- [Transmission LSIO init script](https://github.com/linuxserver/docker-transmission/blob/master/root/etc/s6-overlay/s6-rc.d/init-transmission-config/run)
- [Transmission RPC authentication](https://github.com/transmission/transmission/blob/main/docs/rpc-spec.md)
- [Transmission DNS-rebinding CVE-2018-5702](https://nvd.nist.gov/vuln/detail/CVE-2018-5702) and [Deluge WebUI advisories](https://securitylab.github.com/advisories/GHSL-2024-188_GHSL-2024-191_Deluge/).
- ADR 0088, ADR 0108, and ADR 0111.

## Completion: 2026-10-21

qBittorrent now uses the same opt-in rootless Docker path as Transmission.
Both clients take their WebUI and peer ports exclusively from the per-account
media-stack reservations (`~/.media-stack-port-<app>` and
`~/.media-stack-port-<app>-peer`). These are independent pure reservations,
never native client port files. Missing or invalid reservations fail before
any changes; the per-account HTTP configuration step publishes them. Neither preset accepts a
`HOST_PORT` override. WebUI publishes use `127.0.0.1`; peer publishes explicitly
use `0.0.0.0` for TCP and UDP, with the same reserved port inside and outside
the container (`TORRENTING_PORT` for qBittorrent, `PEERPORT` for Transmission).
Account termination releases all four reservations.

The torrent images are pinned to multi-architecture index digests verified on
2026-10-21:

- `lscr.io/linuxserver/qbittorrent:5.2.4_v2.0.15-ls481@sha256:1ea873647c43d566f251a7122aee330b184509c57ee3257ed19f62dac0f8f24b`
- `lscr.io/linuxserver/transmission:4.1.3-r0-ls365@sha256:55aaeedf9fc354d34136387e15ee0ba4679096f20398dd2875867e5cd5cf8be5`

Before first start, qBittorrent receives a pre-written
`config/qBittorrent/qBittorrent.conf` with the LSIO defaults, reserved peer port,
a random service username and PBKDF2-HMAC-SHA512 password (100000 iterations,
16-byte salt, 64-byte output), and the native proxy settings:
`WebUI\CSRFProtection=true`, `WebUI\ClickjackingProtection=true`, and
`WebUI\HostHeaderValidation=false`. Config and login files are atomically
published with mode 0600, rejecting linked, non-regular or foreign-owned targets.
The independent plaintext service login is retained in
`~/docker/qbittorrent/pmss-credentials.env` as `WEBUI_USER` and `WEBUI_PASS`;
it is never passed to Docker. Existing qBittorrent config and credentials are
preserved on reinstall. Transmission retains its private `USER`/`PASS` env-file.
Secrets do not appear in installer output or argv; dry runs use placeholders
and perform no writes.

Both containers carry `pmss.client=<app>` and request a watchdog proxy refresh
after a successful install. A running, correctly labelled qBittorrent with
exactly one loopback WebUI publish takes the existing
`/user-<name>/qbittorrent/` panel route. Peer publishes do not affect WebUI
resolution. Without that container, native qBittorrent port selection and
proxy behavior remain unchanged, including when the torrent-client store host
marker is absent. Updates alone do not install either container. Other catalog
apps still use `:latest` and their existing fixed defaults (with optional host
port overrides); this completion does not pin the whole catalog.

Security review: [qBittorrent's release history](https://www.qbittorrent.org/news)
records the SSL-error fix in 5.0.1 associated with CVE-2024-51774 and historical
WebUI file exposure. Transmission's
[CVE-2018-5702 fix](https://github.com/transmission/transmission/pull/468) and
[RPC specification](https://github.com/transmission/transmission/blob/main/docs/rpc-spec.md)
describe DNS rebinding and RPC authentication boundaries. Loopback remains
shared across tenants, so each service requires its independent random login;
the panel gate, CSRF and clickjacking protections remain in place. A stolen
service login permits control of that client and its mounted downloads/config,
but does not authenticate to SSH or the PMSS account. Config hashes and the
mode-0600 login file stay under the account's Docker directory, outside the web
root; inspect selects no environment fields. The existing Transmission
two-login proxy limitation described above remains unresolved.
