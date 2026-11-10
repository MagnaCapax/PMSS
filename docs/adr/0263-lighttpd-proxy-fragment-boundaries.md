# ADR 0263: Managed lighttpd proxy fragment responsibility boundaries

Date: 2026-11-10
Category: architecture

## Status
Accepted

## Context
`scripts/lib/lighttpd/proxyFragments.php` mixed route selection, lighttpd syntax,
sibling inspection, guarded publication and service-port allocation. Route rows
also mixed two representations: tagged redirects and positional proxy arguments.
Each route branch repeated its fragment heading, while map rendering tracked
both map presence and the final key solely to place delimiters.

## Options Considered
- Keep the combined module: preserves callers but leaves these responsibilities coupled.
- Introduce proxy objects or a second configuration catalog: adds concepts and migration work.
- Preserve the include and public helpers, separating their existing responsibilities
  and giving every proxy row the same argument shape: chosen.

## Decision
Keep sibling inspection and managed publication in `proxyFragments.php`.
`proxyPorts.php` owns unchanged adoption/allocation; `proxyRule.php` owns pure
conditional rendering; `proxyRoutes.php` owns route selection and framing.
The original include loads every helper without requiring caller migrations.

Fixed routes share label/redirect/proxy-rule rows. Catalog routes retain their
existing metadata reader and its guards. Redirects render once before the proxy
rules, so the tagged redirect variant disappears. Proxy arguments use the
renderer defaults directly. Path maps use separator joining, without a presence
flag or last-key state. No parallel renderer or compatibility adapter remains.

Concept accounting: remove the redirect row tag, its mixed-row dispatch, the
map-presence flag and final-map-key state; add one route descriptor shape.
This is three fewer internal concepts, with public helper contracts retained.

## Consequences
- Public signatures, authorization flags, legacy aliases, redirect ordering,
  trailing whitespace, port bounds, file modes and sibling-conflict outcomes
  retain their existing contracts. Allocation and publication code is moved
  without changing its guards or operations.
- Three focused modules replace one combined ownership boundary. They remain
  operator-side dependencies under `/scripts`; customer PHP gains no include.
- Existing fragment hash tests remain intact. `LighttpdProxyRouteCharacterizationTest`
  adds literal byte expectations for five fixed routes, seven catalog-policy
  combinations, ordered maps and unknown routes. The source-contract assertion
  follows redirect rendering to its new owner. Execute tests on the testing VM;
  devbox verification is changed-file PHP syntax and symbol searches only.

## Security Review
The reviewed upstream history includes the access/authentication fixes listed
for [lighttpd 1.4.16](https://redmine.lighttpd.net/versions/7) and HTTP/2
MadeYouReset detection (CVE-2025-8671) and stricter protocol parsing in
[lighttpd 1.4.80](https://www.lighttpd.net/2025/08/13/1.4.80/).
These establish authentication boundaries and hostile request parsing as relevant
attack surfaces; this restructuring changes neither the server nor its protocol parser.

The local exposure risks are passing cached panel Basic credentials to an app,
dropping an existing gate, or changing the trusted forwarded scheme. Literal
characterization covers the corresponding flags, including the existing combined
Bearer/scheme behavior. Sibling guards and 0640 publication remain unchanged.
No credential is generated, stored or logged here; primary and service credential
storage remains with its existing owners. Exposure of a forwarded primary
credential would cross the panel boundary, which is why stripping bytes are
preserved. There are no new public identifiers, listeners or proxy paths.

## References
- ADR 0111: qBittorrent proxy authorization boundary and Scryer Bearer amendment.
- ADR 0153: forwarded scheme handling through account lighttpd.
- `docs/contracts.md`: managed lighttpd proxy fragment contracts.
