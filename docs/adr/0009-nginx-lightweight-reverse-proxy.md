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

In May 2026, production WebDAV uploads in the multi-GB range exposed a separate
request-body problem: generic nginx proxy defaults kept request buffering enabled
and used 300s body/proxy timeouts, so large uploads over mobile links could time
out in nginx before lighttpd saw the request.

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
- Option D - Keep generic proxy defaults in `template.nginx-proxy_params`, add a
  scoped `template.nginx-webdav_proxy_params` include for WebDAV request-body
  behavior, and keep lighttpd as the sole WebDAV handler.
  - Pros: WebDAV upload behavior is tuned without changing panel/app proxy
    defaults; WebDAV blocks remain minimal and avoid duplicate nginx directives.
  - Cons: Two proxy parameter files must keep their shared header set aligned.

## Decision
Adopt Option C with the Option D refinement for WebDAV request bodies.

Nginx remains a lightweight reverse proxy that forwards WebDAV (and other app)
traffic to per-user lighttpd. All proxy headers and timeouts are centralized in
proxy parameter include files instead of being duplicated inline in location
blocks. Generic proxy locations include
`etc/seedbox/config/template.nginx-proxy_params`; WebDAV locations include
`etc/seedbox/config/template.nginx-webdav_proxy_params` so multi-GB uploads
stream to lighttpd with longer upload-specific timeouts.

Guardrail for future changes:
- Do not add new "nginx -> per-app port" proxies. App reverse proxying belongs
  in per-user lighttpd so behavior stays inside user isolation boundaries.
- Prefer per-app proxy fragments under `~/.lighttpd/custom.d/` (PMSS-managed
  files) instead of growing the shared per-user lighttpd template indefinitely.

## Consequences
- Positive: Reduced duplication, fewer config drift regressions, clearer
  boundary between nginx and per-user lighttpd.
- Negative: There are now generic and WebDAV proxy parameter files; tests must
  keep shared forwarded headers aligned and prevent inline duplicate timeout
  directives in WebDAV blocks.
- Follow-ups: Keep WebDAV-related tests aligned with the centralized proxy
  parameters and re-evaluate defaults if production behavior indicates issues.

## Amendment 2026-08-23 — transport evidence for the lighttpd watchdog

Nginx remains a lightweight proxy, but its combined access log now carries an
append-only PMSS suffix with the selected upstream address, upstream status, and
upstream-header time. `checkLighttpdInstances.php` consumes only new lines and
uses that transport evidence for guarded recovery:

- a numeric upstream-header time proves lighttpd answered, even if an application
  behind lighttpd returned its own 502;
- a final/upstream 502 with no response-header time records one failed watchdog
  cycle for the selected per-user lighttpd port;
- three failing cycles trigger one restart; three more trigger managed config
  regeneration followed by one restart; no further destructive action repeats
  until a healthy upstream response resets the state.

This keeps policy and active health checking out of nginx while closing the blind
spot where a process can exist but nginx cannot obtain a response from it.

## References
- GH issue #7 (Deluge path alignment discussion)
- GH issue #137 (WebDAV proxy timeout duplication regression)
- `etc/seedbox/config/template.nginx-proxy_params`
- `etc/seedbox/config/template.nginx-webdav_proxy_params`
- `etc/seedbox/config/template.nginx-user`
- `scripts/util/createNginxConfig.php`
