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
