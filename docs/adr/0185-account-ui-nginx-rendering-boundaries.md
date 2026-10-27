# ADR 0185: Account UI nginx rendering boundaries

Date: 2026-10-26
Category: architecture

## Status

Accepted.

## Context

The account UI facade combined publication and preflight with all three route
families. Origin maps, trusted relay headers, upgrade settings and default-server
rendering were maintained in eighteen separate source locations. Changes to a
shared directive required checking multiple long templates, including the login
exchange embedded in both Hermes routes.

## Options Considered

- Keep the combined file and independently maintained template fragments.
- Introduce a route registry and a general template engine.
- Separate route families and share their four repeated rendering operations.

## Decision

Keep `nginxConfig/pikkumiesUi.php` as the legacy include facade and the owner of
publication, static-page installation, port checks and certificate preflight.
Load the simplified route from `pikkumiesUiSimplified.php`, Hermes and its login
exchange from `pikkumiesUiHermes.php`, and the interface chooser route from
`pikkumiesUiChoice.php`.

`pikkumiesUiFragments.php` owns origin/API maps, trusted relay headers, upgrade
settings, server framing, response headers, origin guards, proxy bodies and
offline locations. Routes choose literal listen lines, frame and Authorization
policies, auth requests, cookies, path guards, redirects and location ordering.
The historical default-server functions keep their existing shared renderer.

The original extraction reduced eighteen fragment implementations to four.
Server framing, paired CSP/frame headers, origin guards, proxy bodies and offline
locations now also share five implementations instead of twenty-one (3/6/3/6/3).
Port-A GET prefixes, anchored Hermes GET expressions, login CSP hashes and all
route-specific policy remain explicit and retain their matching behavior.

## Consequences

Public function signatures and the facade include path remain available.
Generated nginx bytes, including whitespace, directive order and regex escaping,
remain unchanged. No configuration migration or new dependency is required.
The source-oriented forwarded-scheme test now examines the expanded B/C outputs,
requiring the trusted hop in all nine emitted relay locations.

`PikkumiesUiRenderingCharacterizationTest` locks eleven pre-refactor SHA-256
snapshots: all route families with missing/present TLS files and different
identities, ports and names; the three default servers; and both login layouts.
Only the temporary TLS root is normalized. Existing routing, origin, cookie,
login-CSP and nginx syntax tests continue to cover the security semantics.

## Security Review

The relevant attack surfaces are origin checks, request forwarding, auth-request
coverage, bearer propagation, session cookies and TLS host selection. nginx's
[security advisories](https://nginx.org/en/security_advisories.html) include TLS
session reuse (CVE-2025-23419) and HTTP/3 request-handling flaws
(CVE-2024-32760 and related entries). This is a renderer refactor, not a package
remediation; it retains the existing listeners and TLS directives.

An authorization-routing regression could expose an account's agent UI and its
data or actions. Full-output snapshots therefore lock both stripped and forwarded
Authorization headers, login exchanges and auth-request placement. Primary and
service credentials remain separate under ADR 0128; no credential storage or
secret-file permissions change. Query-free access logs, fragment-based login
tokens, CSP hashes, root/account ownership and modes remain unchanged. No public
name, certificate request or listening port is added (ADR 0150).

## References

- [Account web UI policy](0128-pikkumies-eternals-account-web-uis.md)
- [Trusted proxy scheme](0153-proxied-apps-get-the-https-scheme-while-lighttpd-keeps-127-0-0-1-as-the-client.md)
