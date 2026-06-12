# ADR 0025: Per-user web hosting (unauthenticated /public, PHP, network-reachable panel) is an intentional product feature

Date: 2026-06-12
Category: domain

## Status

Accepted

## Context

PMSS gives every seedbox and storage-box customer a per-user web-hosting capability, served by a per-user lighttpd instance:

- `~/www/public/` is served unauthenticated at `/public-<username>/` (and at the document-root `/`) over HTTP/HTTPS — a public web folder.
- PHP works out of the box (per-user `php-cgi` via lighttpd mod_fastcgi).
- WebDAV (`/webdav-<username>/`, auth-gated), the authenticated panel (`/user-<username>/`, htpasswd), and Docker-rootless web apps round out the capability.
- The per-user lighttpd listens on a per-user port reachable on the host's interfaces (nginx front-doors it, but the per-user service is itself reachable). PM runs no host firewall by design — customers run their own services on open ports.

This is a marketed, documented feature, not an accident: see the customer wiki "Web Hosting on Your Seedbox or Storage Box" (file sharing, image hosting, PHP web apps, API/webhook endpoints, static sites, CDN/asset hosting). "It ships with every plan, on the same PMSS platform that runs the seedbox fleet."

This ADR exists because an internal security review (2026-06) mis-classified parts of this feature as cross-user/external "leaks" — the unauthenticated `/public`, the PHP execution, and the network-reachable per-user lighttpd — and nearly proposed locking them down (e.g. binding lighttpd to 127.0.0.1, restricting reachability). Those changes would have BROKEN a core product feature. This record draws the boundary so future reviews do not repeat that error.

## Options Considered

- **A — Treat reachable web hosting (unauthed `/public`, PHP, open per-user port) as a vulnerability and lock it down** (`server.bind=127.0.0.1`, auth on `/public`, firewall the ports). REJECTED: this is the product; locking it down breaks customer file-sharing, PHP apps, and self-hosted services — a MISSION #4 (business longevity) self-harm.
- **B — Document the web-hosting capability as an intentional feature and define the actual privacy boundary** (this ADR). CHOSEN.
- **C — Do nothing.** REJECTED: leaves the feature undocumented at the architecture-decision level, so the next security review re-flags it (it already happened).

## Decision

Per-user web hosting is an intentional product feature. Specifically, the following are FEATURES, not vulnerabilities, and must NOT be "hardened" away without an explicit product decision:
- `~/www/public/` served unauthenticated at `/public-<username>/` and `/`.
- Per-user PHP execution (`php-cgi`).
- The per-user lighttpd being network-reachable (PM runs no host firewall; customers expose their own services by design).
- Customers being identifiable by username (usernames appear in the public panel URLs `/user-<username>/` — usernames are public identifiers, not secrets).

The REAL cross-user privacy boundary is NON-PUBLIC data: a customer's connecting IP, and their private file contents/activity. PMSS already enforces this (per-user home 0700/0750 isolation, `hidepid=2`, and `chmod o-r` on `who`/`w`/`utmp`/`wtmp`/`netstat` to hide cross-user connecting IPs). A legitimate security finding in this area is a GAP in that IP/contents boundary (e.g. another world-readable source of a customer's connecting IP) — NOT the reachability of a designed service or the enumeration of public identifiers.

## Consequences

- **Positive:** future security reviews have a clear rule — designed-reachable services (web hosting, public dirs, PHP, shell, open ports) are features; only cross-user exposure of NON-PUBLIC data (IP, file contents) is a leak. Prevents service-degrading "fixes."
- **Negative:** the unauthenticated `/public` means customers must not place secrets there — this is already a documented customer-facing limitation; nothing changes.
- **Follow-ups:** cross-reference the customer wiki and ADR-0016/0017 (customer PHP code-tree separation + review checklist). Security-review guidance for agents/reviewers should cite this ADR as the by-design boundary.

## References

- Customer wiki: "Web Hosting on Your Seedbox or Storage Box" (https://wiki.pulsedmedia.com/index.php/Web_Hosting_on_Your_Seedbox_or_Storage_Box)
- ADR-0016 (customer PHP tree separation), ADR-0017 (customer-tree PHP code-review checklist)
- `etc/seedbox/config/template.lighttpd` (server.port, auth.require for /user- and /webdav-, public alias.url, php-cgi fastcgi), `etc/seedbox/config/template.nginx-user`
- Internal 2026-06 cross-user privacy review (the IP/contents boundary; the over-flag correction)
