# ADR 0038: Classic /~username/ path alias for the public web folder

Date: 2026-07-31
Category: architecture

## Status
Accepted

## Context
A user's public web folder (`~/www/public/`) is reachable three ways today:

- `username.server.pulsedmedia.com/` — per-server subdomain vhost (DNS wildcard,
  live 2026-07-10)
- `<sha16>.mcx.fi/` — portable per-service permalink
- `server.pulsedmedia.com/public-username/` — path route on the main server vhost

The one address the hosting world reaches for by reflex — the NCSA/`mod_userdir`
classic `server.pulsedmedia.com/~username/` — was absent. It costs nothing to add
and is instantly legible to customers in a support reply.

There is a real landmine. `etc/seedbox/config/template.lighttpd` sets
`url.access-deny = ( "~", ".inc" )` on every per-user lighttpd instance, so any
request path that still contains a literal `~` when it reaches lighttpd is 403'd.
An `alias`/`root`-based nginx implementation would forward the `~` and break every
request.

## Decision
Add one `location /~##username/` block to `etc/seedbox/config/template.nginx-user`,
mirroring the existing `/public-##username/` block. It `proxy_pass`es to
`http://127.0.0.1:##serverPort/` **with a trailing slash**, so nginx replaces the
`/~##username/` prefix before forwarding: lighttpd receives the path **without**
the leading `~`, and the `url.access-deny` rule is never triggered. The block is a
faithful copy of the public block — same proxy target, same arr-app cookie-path
scoping, same redirect rewriting (retargeted to `/~##username/`), same rate/conn
limits — so `/~username/` is a genuine equal alias of `/public-username/`, not a
redirect that would reveal the `/public-` form in the address bar.

Consequences:
- New customer-facing access path, additive. No existing route changes.
- Only the template changes; per-user configs regenerate through the normal
  `createNginxConfig` path. No fleet deploy is part of this ADR.
- The `~` is a literal character in a prefix location (not the ` ~ ` regex-location
  operator), so nginx matches it as an ordinary path prefix, consistent with the
  sibling `/public-` and `/user-` blocks.

## Alternatives considered
- **301/308 redirect `/~username/` → `/public-username/`.** Simpler, but changes the
  URL in the browser bar, so a shared `/~username/` link would not stay a
  `/~username/` link. Rejected — the point of the classic path is that it is the
  shareable address.
- **`alias`/`root` serving `~/www/public/` directly from nginx.** Bypasses the
  per-user lighttpd entirely, diverging from how every other public route works,
  and forwards the literal `~` into any downstream that inspects the path.
  Rejected on both counts.

## References
- `etc/seedbox/config/template.nginx-user` — the `/~##username/` block
- `etc/seedbox/config/template.lighttpd` — `url.access-deny = ( "~", ".inc" )`
- ADR 0036 — PMSS-owned config files are generated from a template, never
  parsed-and-patched (this change is template-only, consistent with that rule)
