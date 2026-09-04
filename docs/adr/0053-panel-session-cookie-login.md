# ADR 0053: Panel Session-Cookie Login Is Opt-In and Falls Back to Basic Auth

Date: 2026-09-04
Category: security

## Status
Accepted

## Context
The per-user panel is currently protected by lighttpd Basic auth against the
user's own `~/.lighttpd/.htpasswd`. Browser Basic-auth prompts are a poor login
experience, but nginx must stay a lightweight proxy (ADR 0009), WebDAV must stay
lighttpd-owned, and customer-facing PHP must not execute operator-only `/scripts`
code (ADR 0016).

The replacement mechanism also has a deployment-order risk: lighttpd config tests
fail if `magnet.attract-raw-url-to` points at a missing Lua file. A direct
`/scripts/lib/...` reference is not usable by per-user lighttpd either, because
`/scripts` is intentionally `750 root:root`.

## Decision
Add panel session-cookie login as a per-user opt-in capability, disabled by
default through the `panelSessionLogin` key in
`/etc/seedbox/config/users/<user>.json`.

The generated lighttpd config changes only when all three gates pass:

1. The user's `panelSessionLogin` flag is true.
2. `mod_magnet` is loadable on the host.
3. The Lua gate has been deployed to the user's readable
   `~/.lighttpd/panelSessionGate.lua`.

When active, the `/user-<user>/` block loads `mod_magnet`, enables
`auth.extern-authn`, and runs the Lua gate. A valid session cookie sets
`REMOTE_USER=<user>` so the existing `auth.require` rule accepts the request.
Without a valid cookie, Basic auth remains authoritative. Browser HTML requests
without credentials are redirected to the customer-tree login page; non-browser
requests continue into the current Basic challenge path. `/webdav-<user>/`
remains a separate Basic-only `auth.require` zone and is never routed through Lua.

The login/logout PHP lives in `etc/skel/www/` and is synced to existing users by
the normal skeleton file flow. It validates passwords only against the existing
per-user htpasswd file with `crypt()` comparison. It writes one flat per-user
session file under `~/.lighttpd/` with mode `0600`, regenerates the session id on
successful login, requires a CSRF token for POST, and emits a host-scoped cookie
with `Secure`, `HttpOnly`, and `SameSite=Lax`.

## Invariants
- **Off by default is byte-identical:** absent/false `panelSessionLogin` must
  render the same per-user lighttpd config bytes as before this ADR.
- **Conditional magnet emit:** `mod_magnet`, `auth.extern-authn`, and
  `magnet.attract-raw-url-to` are emitted only when the user opted in, the module
  is present, and the deployed Lua gate exists.
- **Deploy before reference:** the renderer references only the per-user deployed
  Lua path, and directory preparation copies that file before rendering can emit
  the magnet directive.

## Security Analysis
The primary credential remains the existing account password hash in htpasswd;
there is no second password store and no plaintext primary credential. The new
session value is a random bearer token, scoped to one host/path by omitting
`Domain` and using `Path=/user-<user>/`, and stored only in the user's own
`~/.lighttpd/panel-session`.

The main attack surfaces are session theft, CSRF/login CSRF, credential guessing,
header smuggling/auth bypass classes in HTTP servers, and accidental policy drift
between nginx/lighttpd. Mitigations are host/path-scoped cookies with standard
flags, POST CSRF tokens, session id regeneration after authentication, bounded
absolute and idle lifetimes, no password/session-id logging, and the cookie-OR-
Basic spine that preserves the existing htpasswd `auth.require` rule. If the Lua
gate cannot read the session file, it falls back to Basic instead of failing the
request pipeline.

## Consequences
- Positive: existing users see no config change until explicitly opted in.
- Positive: browser login improves without moving auth policy into nginx or PHP
  alone; static assets and proxied apps are still gated before handlers run.
- Positive: Phase-1-lag hosts without `lighttpd-mod-magnet` keep valid Basic-only
  configs.
- Trade-off: the Lua gate must stay small because mod_magnet runs in lighttpd's
  request path. It performs only bounded flat-file reads/writes and delegates
  credential verification to existing components.

## References
- GH #855: opt-in panel session-cookie login.
- GH #854 / commit `d995c18a`: add `lighttpd-mod-magnet` to dpkg baselines.
- ADR 0009: nginx lightweight reverse proxy.
- ADR 0016: customer PHP tree separation from operator `/scripts`.
- ADR 0032: PMSS-managed per-user lighttpd config belongs in the template.
- OWASP Session Management Cheat Sheet.
- OWASP CSRF Prevention Cheat Sheet.
- lighttpd `mod_magnet` and `mod_auth` documentation/source.
