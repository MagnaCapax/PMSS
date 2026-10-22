# ADR 0153: Proxied apps get the https scheme while lighttpd keeps 127.0.0.1 as the client

Date: 2026-10-22
Category: architecture

## Status

Proposed.

## Context

Apps behind an account's lighttpd (the account's own `~/.lighttpd/custom.d`
proxies and the PMSS-generated fragments in
`scripts/lib/lighttpd/proxyFragments.php`) build `http://` redirects and
absolute URLs while the browser is on `https://`. Seen with NZBHydra2,
Copyparty (#1090) and Stash.

nginx terminates TLS and sends `X-Forwarded-Proto: https` to lighttpd. lighttpd
`mod_proxy` then writes `X-Forwarded-Proto` itself on every proxied request,
from lighttpd's own scheme, which is `http`: `proxy_set_Forwarded()` runs for
every proxy request (mod_proxy.c 1.4.69 line 906) and sets the header from
`r->uri.scheme` (line 791). A `setenv` value for the header is replaced, and
`proxy.forwarded "proto"` describes the hop lighttpd received, so it also says
`http`. Measured on lighttpd 1.4.59 and 1.4.69: the app receives
`X-Forwarded-Proto: http` in every case.

The same scheme check affects WebDAV: `MOVE` and `COPY` with an absolute
`Destination: https://...` compare it with lighttpd's scheme (mod_webdav.c
1.4.69 lines 5089-5096) and were answered `400`. Measured: `400` before this
change, `201` after it, on 1.4.69 and 1.4.59.

## Options Considered

- `"proto" => 1` in `proxy.forwarded`, or `setenv` for `X-Forwarded-Proto`:
  measured, the app still receives `http`.
- `mod_extforward` with its default `X-Forwarded-For` header, trusting nginx:
  apps receive `https`, but lighttpd then takes the visitor address as its own
  client address, so each account's `~/.lighttpd/access.log`, PHP
  `REMOTE_ADDR` and `$HTTP["remoteip"]` change from 127.0.0.1 to visitor
  addresses. The scheme does not need that change. Not chosen.
- `mod_extforward` reading only `Forwarded` or only `X-Forwarded-Proto`: no
  effect. lighttpd applies a forwarded scheme only after it takes an address
  from the same header (mod_extforward.c 1.4.69 lines 630-631 and 828-840).
  Measured: no change.
- A dedicated header naming a constant loopback address, without changing
  nginx's source address: apps receive `https`, but lighttpd's client address
  becomes that constant (127.0.0.2) instead of 127.0.0.1.
- nginx connects from 127.0.0.2 and names 127.0.0.1 in a dedicated header that
  only lighttpd reads: chosen.

## Decision

- `template.lighttpd` loads `mod_extforward`. After the account's custom
  includes it sets `extforward.forwarder += ( "127.0.0.2" => "trust" )` and
  `extforward.headers += ( "X-PMSS-Proxy-Hop" )`. lighttpd sets its client
  address to 127.0.0.1 and its scheme from nginx's `X-Forwarded-Proto`, so
  `mod_proxy` forwards `https` to the app.
- `template.nginx-proxy_params` and `template.nginx-webdav_proxy_params` add
  `proxy_bind 127.0.0.2;` and `proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;`.
  The account UI locations in `scripts/lib/nginxConfig/pikkumiesUi.php` set
  their own headers per location and get the same two lines, plus
  `Forwarded: for="<visitor address>";proto=https` (they already send a fixed
  `X-Forwarded-Proto: https`).
- The account UI blocks in `scripts/lib/lighttpd/pikkumiesUi.php` no longer set
  `X-Forwarded-Proto`; lighttpd forwards `https` for them now (measured).
- nginx sets `Forwarded: for="<visitor address>";proto=<scheme>` for proxied
  requests. Apps that prefer RFC 7239 `Forwarded` over `X-Forwarded-*` (Spring)
  read its first element and receive the scheme the visitor used. It carries
  `for=` so that those apps see the same client address they see in
  `X-Real-IP` and `X-Forwarded-For` today; a `Forwarded` without `for=` would
  make them see every request as coming from 127.0.0.1. The `Host` header
  already carries the host.

## Privacy

For requests nginx proxies, lighttpd's client address stays 127.0.0.1: the
account's access log, PHP `REMOTE_ADDR` and `$HTTP["remoteip"]` see no visitor
addresses (measured). Requests that reach an account's lighttpd port directly
(ADR 0025) carry the connecting address, as before. Apps receive the same
client headers as before, plus `Forwarded`; lighttpd's own entry in
`X-Forwarded-For` and in its `Forwarded` element becomes 127.0.0.2.

## Consequences

- Covers account `custom.d` proxies, PMSS-generated fragments, WebDAV and the
  account UI mounts with no per-app change, on lighttpd 1.4.65 and later
  (Debian 12: 1.4.69; Debian 13: 1.4.79).
- Debian 11 (1.4.59) and Debian 10 (1.4.53): with `mod_extforward` loaded,
  `mod_proxy` forwards the scheme of the hop it received (mod_proxy.c 1.4.59
  line 810), so apps still receive `X-Forwarded-Proto: http`. lighttpd's own
  scheme becomes `https` (PHP `HTTPS=on`, WebDAV `Destination`), and nginx's
  `Forwarded` carries `proto=https`. The config parses on 1.4.59.
- Accounts can set `extforward` variables in their own custom files. Where an
  account sets them at global scope with `=`, a PMSS `=` of the same variable
  before the includes would make its lighttpd refuse the config ("Duplicate
  config variable", measured on 1.4.59 and 1.4.69). PMSS therefore uses `+=`
  after the includes, which merges with the account's lists (measured: parses,
  and such an account keeps the behaviour it configured).
- Where an account sets them inside a conditional block, that block uses the
  account's own list. Until the list includes 127.0.0.2, requests in that block
  lose the `https` scheme they had and lighttpd sees them from client 127.0.0.2
  (measured).
- `127.0.0.2` must be a local address (`ip route get 127.0.0.2` answers
  `local ... dev lo`), which is the Linux default for 127.0.0.0/8.
- OpenClaw keeps `gateway.trustedProxies = ["127.0.0.1"]`: it skips loopback
  entries when it reads `X-Forwarded-For` (`resolveForwardedClientIp`, checked
  in releases 2026.8.33, 2026.9.8 and 2026.10.6), so 127.0.0.2 in the chain does
  not change the client it resolves.
- nginx connects from 127.0.0.2 as soon as it reloads. An account whose
  lighttpd still runs the previous config (its new `.lighttpd.conf` was not
  written, or its restart was skipped, for example by the low-space check in
  `checkLighttpdInstances.php`) keeps the `http` scheme and sees client
  127.0.0.2 until its next write and restart.
- The `Forwarded` quoting assumes IPv4 listeners; IPv6 listeners need
  `for="[addr]"`.
- Takes effect when `update.php` rewrites `/etc/nginx/proxy_params` and each
  account's `.lighttpd.conf`; `checkLighttpdInstances.php` restarts an
  account's lighttpd when its config is newer than the running process.

## References

- #1090, #1086; ADR 0025 (per-user web hosting)
- lighttpd `src/mod_proxy.c` (`proxy_set_Forwarded`), `src/mod_extforward.c`,
  `src/mod_webdav.c`
- `scripts/lib/tests/development/ForwardedSchemeConfigTest.php`
