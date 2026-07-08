# ADR 0029: Customer stable-host HTTP serving stays behind per-user lighttpd

Date: 2026-07-08
Category: architecture

## Status
Accepted

## Context
PMSS already serves every user's public web folder through per-user lighttpd,
fronted by nginx. The mcx.fi release needs stable customer hostnames such as
`{sha16}.mcx.fi` to serve the same customer-owned web content without exposing
the legacy path shape (`/public-<user>/` or `/user-<user>/`).

The pinned operator decisions for this build are:
- Phase 1 is HTTP only.
- Wildcard TLS certificates are banned; per-host TLS is a later phase.
- The default document root is `~/www/public`, but customers can select another
  safe subdirectory under `~/www` with `~/www/.mcx-docroot`.
- The mechanism must be generic enough for future custom domains.
- The host-to-user map must come from a pulsedmedia.com remote API slice for the
  requesting seedbox. The current endpoint does not expose that slice, so PMSS
  must not invent a contract.

## Options Considered
- Option A - Serve customer hostnames directly from nginx with `root`/FastCGI.
  - Pros: nginx alone could switch docroots.
  - Cons: violates ADR 0009 by moving PHP/app handling into nginx and outside
    the per-user lighttpd boundary.
- Option B - Add a broad lighttpd alias from an internal URL prefix to `~/www`.
  - Pros: nginx could select any subdirectory at request time.
  - Cons: would expose all of `~/www` through a new unauthenticated path on the
    network-reachable per-user lighttpd port.
- Option C - Add a narrow lighttpd alias to the validated `.mcx-docroot`
  directory, and have nginx customer-host vhosts proxy to that alias.
  - Pros: preserves nginx as a lightweight front door, keeps PHP/app handling in
    the user lighttpd process, and only exposes the selected public docroot.
  - Cons: `.mcx-docroot` changes require the normal lighttpd config regeneration
    path before the watchdog restarts the user instance.

## Decision
Adopt Option C.

PMSS adds HTTP-only customer-host nginx vhosts under
`/etc/nginx/conf.d/pmss-customer-host-<user>.conf`. Each vhost can carry any
validated external hostname and proxies `/` to that user's existing lighttpd
port at the internal `/_pmss-customer-host-docroot/` alias.

Per-user lighttpd keeps the existing `/public-<user>/` and `/user-<user>/`
routes unchanged. It adds only the narrow customer-host alias, rendered from the
validated `~/www/.mcx-docroot` value and defaulting to `public` when the file is
missing, invalid, or unsafe.

The remote host map consumer is present but intentionally returns "not loaded"
until pulsedmedia.com exposes the required per-server hostname-to-local-user
slice. Existing customer-host configs are not cleaned up when the map is
unavailable, avoiding a future transient API failure from deleting serving
configs.

## Consequences
- Positive: mcx.fi and future custom domains use one nginx mechanism while
  preserving the per-user lighttpd isolation and existing path-based routing.
- Positive: unmatched `*.mcx.fi` HTTP hosts get an explicit nginx `404` server
  block instead of falling through to `/var/www`.
- Negative: until the remote API slice exists, PMSS cannot generate real
  hostname mappings. This is deliberate; the endpoint contract remains with the
  pulsedmedia.com data API owner.
- Follow-ups:
  - Extend `pulsedmedia.com/remote/mcxData-api.php` with an authenticated
    per-server slice for the requesting seedbox. Each row must include an
    external hostname FQDN and the local PMSS username to serve on that server.
  - Phase 2 TLS: issue per-host certificates only; no wildcard certificates.
  - Decide and implement the memorable service hostname form separately from the
    serving layer.

## References
- ADR 0009: nginx lightweight reverse proxy; WebDAV handled by per-user lighttpd
- ADR 0025: per-user web hosting is an intentional product feature
- Pinned mcx.fi release vplan, 2026-07-08
- `scripts/lib/nginxConfig/userConfigsGenerate.php`
- `etc/seedbox/config/template.nginx-customer-host`
- `etc/seedbox/config/template.lighttpd`
