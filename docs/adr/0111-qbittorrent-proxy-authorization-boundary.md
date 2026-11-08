# ADR 0111: Keep panel Authorization out of qBittorrent

Date: 2026-10-18
Category: security

## Status

Accepted.

## Context

The per-user lighttpd proxy requires panel Basic authentication for the private
qBittorrent URL. Its request header previously reached the backend. qBittorrent
5.2 and later can use that header for its own Basic login, so a different WebUI
password yields an unauthorized response instead of the WebUI login form.
When credentials match, the backend accepts the panel credential without its
own login prompt. The backend can manage the account's torrent data. Forwarding
the account credential also expands its exposure to the app's request handling;
this path does not intentionally log the header or change secret file modes.

The qBittorrent project documents cookie-based WebUI sessions and Basic login
from the `Authorization` header. Its public security page lists no published
advisories at this decision date; this is not a claim that older releases lack
vulnerabilities. The request-header boundary is useful regardless of version.

## Options considered

- Keep forwarding the header: preserves automatic native login but couples two
  credentials and prevents independently configured WebUI logins.
- Document a custom fragment: fixes only accounts that install the override.
- Remove the header only inside the managed qBittorrent proxy rule: retains
  panel authentication while allowing the app to own its login session.

## Decision

Use lighttpd `setenv.set-request-header` with a blank `Authorization` value in
the qBittorrent rule only. Lighttpd documents that a blank value removes the
request header. The existing `auth.require` panel gate stays in place. Other
proxy fragments and their auth behavior remain unchanged.

## Consequences

Native installs whose WebUI credentials match the panel credentials may show
the qBittorrent login form once per WebUI session. A WebUI login that differs
from the panel login now works through the managed URL. The change does not
alter qBittorrent credentials, listener binds, permissions, or logging.
Verify the rendered fragment and a panel-authenticated, separate-credential
WebUI login on a canary host before fleet rollout. Reverting the fragment
restores the old forwarding behavior; it also restores the credential exposure.

## References

- [qBittorrent WebUI API authentication](https://github.com/qbittorrent/qBittorrent/wiki/WebUI-API-%28qBittorrent-4.1%29)
- [qBittorrent Basic authentication](https://github.com/qbittorrent/qBittorrent/wiki/Basic-Authentication-%28%E2%89%A5v5.2.0%29)
- [qBittorrent security page](https://github.com/qbittorrent/qBittorrent/security)
- [lighttpd mod_setenv](https://redmine.lighttpd.net/projects/lighttpd/wiki/Mod_setenv)
- ADR 0009 (per-user proxy ownership) and ADR 0102 (native WebUI credentials)

## Catalog proxy extension (2026-11-01, Refs #1089)

Copyparty, slskd and Scryer also have independent service logins. Their catalog
rows now opt into `stripAuthorization`; the generic proxy rule consumes that
flag with a default of false. This applies the same credential boundary while
retaining the inherited panel Basic-Auth gate. qui keeps its existing forwarding
behavior. Forwarding the panel credential or disabling panel authentication was
rejected: neither is necessary for the app's own login and session cookies.

Copyparty's HTTP/HTTPS scheme conditionals repeat the removal in their header
maps. Lighttpd's [mod_setenv configuration merge](https://github.com/lighttpd/lighttpd1.4/blob/lighttpd-1.4.69/src/mod_setenv.c)
replaces the parent header map when a nested condition sets the same directive;
setting only the scheme there would otherwise restore credential forwarding.
The scheme header continues to come from the trusted-hop request scheme (ADR 0153).

Security review rechecked Copyparty's [browser-session advisory](https://github.com/9001/copyparty/security/advisories/GHSA-rcp6-88mm-9vgf)
and [file/dirkey advisory, CVE-2026-70657](https://github.com/9001/copyparty/security/advisories/GHSA-x5pq-m9p8-f4vx).
These cover browser script execution and unintended file access. The upstream
[slskd](https://github.com/slskd/slskd/security) and
[Scryer](https://github.com/scryer-media/scryer/security) security pages showed no
published advisories at review time; that does not establish absence of CVEs.
Known exposure paths include app web/API actions, shared files, slskd's peer
listener and Scryer's configured download/indexer integrations. A leaked app
login exposes app data and actions under the account UID; leaking the forwarded
primary credential could additionally expose the panel and account shell access.

Source review confirms service passwords remain separately generated random
values in mode-0600 account files outside the web root. This change adds no
credential logging, secret storage, listener, DNS name or public route. Existing
loopback web binds, URL prefixes, cookie handling and validation remain intact.
Normal managed-fragment refresh applies the change without rotating app logins.
Regression fixtures cover each catalog route and both Copyparty scheme maps.
Before rollout, verify rejected requests without panel credentials and a
panel-authenticated login with a distinct app password on a testing host; no
runtime or dry-run probe was executed on the dev box. Rollback removes the three
catalog flags, restoring the previous forwarding behavior and exposure.

## Bearer catalog routes (2026-11-08, Refs #1241)

The 2026-11-01 extension assumed each catalog app keeps its session in cookies.
Scryer does not. Its web client sends `Authorization: Bearer <token>` on every
GraphQL call after sign-in and keeps the token in browser storage
([urql-client.ts](https://github.com/scryer-media/scryer/blob/scryer-v0.21.17/apps/scryer-web/lib/graphql/urql-client.ts),
[auth-session-persistence.ts](https://github.com/scryer-media/scryer/blob/scryer-v0.21.17/apps/scryer-web/lib/utils/auth-session-persistence.ts)).
A request carries one `Authorization` header, so the token replaces the
browser's cached panel Basic credential and the inherited `auth.require` gate
answers 401 before the proxy. The unconditional strip would also remove the
token from any request that did pass. Measured on a throwaway account
(Scryer 0.21.17, lighttpd 1.4.59): sign-in succeeded, the first-run password
change and every later call got the panel's 401, and the app fell back to its
sign-in page; the same steps through an SSH tunnel to the loopback port worked.

Options considered:

- Keep the gate: the app stays unusable at its panel address.
- Gate only requests without a Bearer header: the condition is a header the
  client chooses, so it exposes the same surface as no gate and adds a failure
  mode.
- Keep the gate and run Scryer with `SCRYER_AUTH_ENABLED=false`: rejected.
  Scryer listens on TCP loopback only (no Unix socket in 0.21.17), and every
  account on a node shares loopback, so other accounts would reach it with no
  login at all.
- Panel session cookie (ADR 0053): opt-in and off by default, and a cookie-only
  gate for Bearer calls is the OpenClaw design (ADR 0087, #1229). It leaves
  default accounts without a working path.
- Drop the panel gate on this path and remove only a Basic header: chosen.

Decision: catalog rows may set `bearerAuthorization`. The generic rule then
renders `auth.require = ()` and a nested `$REQUEST_HEADER["Authorization"] =~
"^Basic "` condition that blanks the header. A cached panel credential never
reaches the app; the app's Bearer token does. Scryer is the only such row.
Copyparty and slskd keep the gate and the unconditional strip byte for byte,
and qui is unchanged. The construct is already used by the OpenClaw proxy and
requires lighttpd 1.4.46, below the Debian 10 floor (1.4.53). If a Bearer row
ever sets `forwardedProtoHeader`, its scheme maps replace this header map and
must repeat the Basic-only removal.

Security consequences, stated plainly: Scryer's own login becomes the only gate
for `/user-<user>/scryer/` from the internet. The trailing-slash redirect stays
behind the panel gate. Unauthenticated requests reach `/health`, static UI
assets and the GraphQL operations Scryer itself allows without a user (sign-in,
first-run). Scryer answers an invalid Bearer token and any non-Bearer scheme
with 401 and never falls back to an anonymous actor
([middleware.rs](https://github.com/scryer-media/scryer/blob/scryer-v0.21.17/crates/scryer/src/middleware.rs)).
Before this change every other account on the node could already reach the
loopback port without the panel gate, so Scryer's login was already the only
barrier against co-tenants; this adds internet reachability of that login.
PMSS renders `SCRYER_AUTH_ENABLED=true` and `SCRYER_DISABLE_DEFAULT_ADMIN=true`
and seeds a 48-hex-character random temporary password that Scryer requires the
owner to replace at first sign-in. Scryer limits sign-in to 5 attempts per
60 seconds per client key and 20 failures per 300 seconds per principal
([rate_limit.rs](https://github.com/scryer-media/scryer/blob/scryer-v0.21.17/crates/scryer/src/rate_limit.rs)).
Behind lighttpd every request arrives from 127.0.0.1 and no trusted proxy is
configured, so the per-key bucket is shared per instance: guessing stays slow,
but a stranger can hold the owner's sign-in in that bucket while guessing.
Trusting forwarded addresses was not configured, because a client-supplied
`X-Forwarded-For` would then let a guesser rotate the key. This lockout risk is
accepted: a valid token keeps working. Scryer's
[security page](https://github.com/scryer-media/scryer/security) listed no
advisories on 2026-11-08, and its security policy targets fixes at the latest
published release, patching older ones only when warranted. The 0.21.17 pin is
already behind upstream (0.21.24 and 0.22.x exist), and without the panel gate
a stale pin is internet-facing, so keeping the pin current matters more than
before. A pin bump must re-run the first-run journey through the panel address:
from 0.21.22 a fresh instance asks for an in-app authentication choice (#1237),
and without the panel gate the first visitor must not be able to answer it with
"no authentication". A comment at the pin in `scripts/lib/update/apps/scryer.php`
points back here.

Verification is the browser journey, not the rendered rule: sign in through
the panel address, finish the first-run password change, reload, load an
authenticated page, go to the panel and back. Rollback removes the row flag;
the next managed-fragment refresh restores the gate on each node.
