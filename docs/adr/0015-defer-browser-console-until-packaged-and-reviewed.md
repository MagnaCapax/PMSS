# ADR 0015: Defer browser shell console until a terminal gateway is packaged and reviewed

Date: 2026-04-02
Category: architecture

## Status
Accepted

## Context
Issue #326 requests an HTML5 browser console so tenants can open an interactive
shell from the PMSS web panel without a separate SSH client.

This feature is security-adjacent and multi-tenant sensitive:

- It exposes an interactive shell inside the private user area.
- It depends on authentication, reverse proxying, logging, and process
  lifecycle management.
- It must preserve PMSS's per-user isolation model.

Current repository constraints make a one-pass implementation unsafe:

- Supported package baselines are captured in
  `scripts/lib/update/dpkg/selections-debian10.txt`,
  `scripts/lib/update/dpkg/selections-debian11.txt`, and
  `scripts/lib/update/dpkg/selections-debian12.txt`. Those manifests do not ship
  an approved browser terminal gateway.
- ADR 0009 keeps nginx as a lightweight TLS/front-door proxy and requires app
  reverse proxying to live inside per-user lighttpd, not in fleet-wide nginx
  port-routing rules.
- The security doctrine requires attack-surface review before changing shell
  access, auth, or secret-handling behavior.

Adding a panel button without an approved terminal gateway binary, lifecycle
contract, and security review would create a dead or inconsistent feature.

## Options Considered
- Option A - Implement immediately with an ad-hoc terminal proxy download/build.
  - Pros: Fastest path to a demo.
  - Cons: Bypasses the immutable package baselines, adds unsupported dependency
    management, and lands a shell gateway before the required security review.
- Option B - Add only the web UI placeholder now.
  - Pros: Minimal code change.
  - Cons: Creates user-visible dead UX and does not deliver the requested shell
    access.
- Option C - Defer implementation until PMSS has an approved gateway binary,
  documented security posture, and a lighttpd-based proxy contract.
  - Pros: Preserves current rails, keeps auth/proxy boundaries explicit, and
    avoids shipping a half-featured shell surface.
  - Cons: Browser console access remains unavailable until the follow-up work is
    completed.

## Decision
Adopt Option C.

PMSS will not ship browser shell access until all of the following are true:

1. A specific terminal gateway is selected and approved for supported Debian
   baselines.
2. The gateway is provisioned through a PMSS-supported path rather than an
   ad-hoc download at runtime.
3. The proxy contract lives in per-user lighttpd (`~/.lighttpd/custom.d/`),
   keeping nginx as the lightweight front-door defined by ADR 0009.
4. Security review documents authentication reuse, loopback binding, logging,
   timeout behavior, and the blast radius if the gateway is exposed.
5. Hermetic development tests cover status detection, disabled-path behavior,
   and generated proxy/process artifacts.

Until those prerequisites exist, issue #326 remains intentionally deferred.

## Consequences
- Positive: Avoids introducing a new interactive shell surface without an
  approved package source, proxy boundary, and review trail.
- Positive: Keeps future implementation aligned with existing PMSS patterns for
  per-user lighttpd proxy fragments and immutable package baselines.
- Negative: Mobile-only users still need SSH or an alternate supported path for
  shell access in the meantime.
- Follow-ups: Select the terminal gateway, capture its package/support story,
  complete the security review, then implement the panel entrypoint and
  lighttpd-managed proxying in a later change.

## References
- GH issue #326
- `docs/adr/0009-nginx-lightweight-reverse-proxy.md`
- `docs/dpkg-baseline.md`
- `docs/security/operational-safety.md`
- `docs/security/testing.md`
- `scripts/lib/update/dpkg/selections-debian10.txt`
- `scripts/lib/update/dpkg/selections-debian11.txt`
- `scripts/lib/update/dpkg/selections-debian12.txt`
