# ADR 0009: Nginx lightweight reverse proxy; WebDAV handled by per-user lighttpd

Date: 2026-01-28
Category: architecture

## Status
Accepted

## Context
PMSS fronts per-user lighttpd instances with nginx.

Per-user lighttpd is the "heavy" web handler:
- Runs inside the user's cgroup/slice and under the user's UID (isolation).
- Owns auth decisions for the private area and WebDAV (and any future policy).
- Owns per-app reverse proxying (qBittorrent, rclone, Deluge, etc.) so complex
  behavior stays inside the user's resource/permission boundary.

Nginx is intentionally "light":
- Terminates TLS and provides a stable fleet-wide entry point.
- Reverse proxies requests to the per-user lighttpd instance.
- Must not implement user-space policy, auth logic, or per-app port routing.

Historically, WebDAV proxy blocks in nginx templates carried their own headers,
timeouts, and buffering directives. This duplicated proxy logic across multiple
templates, increased drift risk, and blurred responsibility boundaries between
nginx and lighttpd.

We need to keep nginx minimal, preserve existing WebDAV URLs, and avoid
per-location proxy divergence that can regress config safety.

## Options Considered
- Option A - Keep explicit WebDAV proxy directives in nginx templates (status quo).
  - Pros: WebDAV-specific tuning localized.
  - Cons: Duplication across templates, drift risk, heavier nginx surface.
- Option B - Move WebDAV handling into nginx (auth/path logic).
  - Pros: Single nginx surface for WebDAV.
  - Cons: Violates per-user isolation and "light proxy only" objective.
- Option C - Centralize proxy headers/timeouts in `template.nginx-proxy_params`
  and include it in WebDAV blocks; keep lighttpd as the sole WebDAV handler.
  - Pros: Minimal nginx blocks, consistent defaults, clear responsibility split.
  - Cons: Proxy defaults apply broadly; requires review for side effects.

## Decision
Adopt Option C.

Nginx remains a lightweight reverse proxy that forwards WebDAV (and other app)
traffic to per-user lighttpd. All proxy headers and timeouts are centralized in
`etc/seedbox/config/template.nginx-proxy_params`, and WebDAV blocks include that
file instead of duplicating directives.

Guardrail for future changes:
- Do not add new "nginx -> per-app port" proxies. App reverse proxying belongs
  in per-user lighttpd so behavior stays inside user isolation boundaries.
- Prefer per-app proxy fragments under `~/.lighttpd/custom.d/` (PMSS-managed
  files) instead of growing the shared per-user lighttpd template indefinitely.

## Consequences
- Positive: Reduced duplication, fewer config drift regressions, clearer
  boundary between nginx and per-user lighttpd.
- Negative: Proxy defaults (timeouts, buffering, body size) now apply to all
  proxied locations; monitor for unintended effects and adjust centrally if
  needed.
- Follow-ups: Keep WebDAV-related tests aligned with the centralized proxy
  parameters and re-evaluate defaults if production behavior indicates issues.

## References
- GH issue #7 (Deluge path alignment discussion)
- GH issue #137 (WebDAV proxy timeout duplication regression)
- `etc/seedbox/config/template.nginx-proxy_params`
- `etc/seedbox/config/template.nginx-user`
- `scripts/util/createNginxConfig.php`
