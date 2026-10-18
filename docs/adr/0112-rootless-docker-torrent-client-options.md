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
