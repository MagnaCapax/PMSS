# ADR 0021: User-panel top-frame navigation contract — in-page tabs only, enabled features only

Date: 2026-06-03
Category: domain

## Status
Accepted (operator-directed, 2026-06-03)

## Context

The per-user web panel (`https://<server>/user-<USER>/`, served from `/home/<USER>/www/index.php`)
is the first surface every customer sees. Its top frame is the primary navigation. Over
2026-03 → 2026-05 a series of agent-driven changes degraded this navigation away from the
behaviour it had for years, without any visual or functional regression gate catching it:

- **Wiki opens a new browser window.** Commit `b4d284f8` (2026-03-30, "open wiki tab in a new
  window", Refs #390) set `target="_blank" rel="noopener noreferrer"` on the wiki entry. This
  was a workaround for the wiki refusing to be framed (X-Frame-Options/CSP SAMEORIGIN at the
  time). It abandoned the in-page tab paradigm: clicking a top-frame item now yanks the user
  out of the panel into a new window. As of 2026-06-03 `wiki.pulsedmedia.com` sends
  `content-security-policy: frame-ancestors 'self' https://*.pulsedmedia.com` — framing from
  PM subdomains is now explicitly allowed, so the new-window workaround is no longer warranted.

- **Top frame advertises disabled features.** The frame list includes app launchers
  (qbittorrent, deluge, jellyfin, *arr, sabnzbd, invidious…). When the underlying service is
  not enabled for that account, selecting the tab loads a per-user lighttpd backend that is not
  running → the customer sees a raw `503 Service Unavailable`. The panel must not advertise a
  tab the account cannot use.

- **Default selection can land on a disabled app.** Frame ordering placed app launchers ahead
  of `welcome`, so the panel could open on a disabled qbittorrent/deluge tab → immediate 503 on
  first load.

These are UX regressions, not abstract code issues. The prior behaviour — in-page tabs,
welcome first, only the tabs the account can actually use — is the target state.

## Options Considered

- **Option A – Leave navigation as-is, document the quirks.**
  - Pros: no code change.
  - Cons: customers keep getting kicked to new windows and hitting 503s on the first surface
    they see. Brand/UX damage on the highest-traffic page. Rejected.

- **Option B – Fix the three symptoms individually with no governing contract.**
  - Pros: fixes today's bugs.
  - Cons: no invariant; the next refactor re-breaks it. The whole reason this happened is that
    nothing encoded "navigation = in-page tabs, enabled features only." Insufficient.

- **Option C – Establish a navigation contract (this ADR) + fix the symptoms to match + add a
  QA gate that asserts the contract.**
  - Pros: durable invariant; future refactors have a written rule and a test to fail against.
  - Cons: requires a QA harness that renders the panel and asserts behaviour (worth it).

## Decision

We choose **Option C**. The user-panel top frame obeys this contract:

1. **In-page tabs only.** Every top-frame entry switches content inside the panel via the tabs
   mechanism. No top-frame entry uses `target="_blank"`, `window.open`, or otherwise opens a new
   window/tab. (Wiki returns to an in-page iframe tab; CSP `frame-ancestors` now permits it.)
2. **Enabled features only.** A feature tab (qbittorrent, deluge, jellyfin, the *arr suite,
   sabnzbd, invidious, etc.) appears **only** when that feature is enabled for the account
   (mirror the existing `../.delugeEnable`/per-app-enable gating). A disabled feature is absent
   from the frame, never a tab that returns 503.
3. **Sane default.** The panel opens on `welcome` by default — never on a feature tab that may
   be disabled.
4. **Graceful not-ready state.** When an enabled feature's backend is briefly unavailable
   (starting up), its per-user lighttpd 503 page is styled like the global 404/403 pages and
   JS-auto-refreshes until the backend answers — rather than a raw server 503. (Tracked as a
   separate issue; this ADR sets the expectation.)

Rationale: the navigation is brand-critical and was regressed precisely because no rule said
otherwise. Reverting wiki-to-new-window is now correct (the framing block that motivated it is
gone). Enabled-only + welcome-default eliminate the 503-on-first-load class entirely.

## Consequences

- **Positive:** restores the long-standing tab UX; eliminates new-window yanking and the
  503-from-disabled-tab class on the panel's highest-traffic surface; gives future refactors a
  written invariant + a QA assertion to fail against.
- **Negative / migration:** requires edits to `etc/skel/www/index.php` (frame definitions, wiki
  target, enable-gating, default), propagation to existing users (new-file drift — see ADR 0006
  / GH#487 / GH#586), and a fleet update pass. Fleet rollout is operator-gated (>2.5% change
  limit), batched.
- **Follow-ups:**
  - Revert wiki entry to an in-page iframe tab.
  - Gate every app-launcher tab on its per-account enable flag; default to welcome.
  - Style per-user lighttpd 503 like global 404/403 + JS auto-refresh.
  - Add a pmss-qa render test asserting: no `target=_blank` in frame nav, default tab = welcome,
    no tab present for a disabled feature, CSS assets present.
  - Ensure new skel assets (e.g. `jquery.tabs-ie.css`) propagate via guiFrames `$fileVersions`
    and the update-step2 per-user copy loop.

## References

- Issues: #390 (wiki framing), #536/#523/#553/#578 (panel CSS/tab regressions), #555 (welcome
  redesign readability), #586/#487 (new-file propagation gap).
- Commit `b4d284f8` (wiki new-window), `ac1877ab` (bundle skel assets locally), `bc86d54b`
  (tab height-cascade hardening).
- ADR 0006 (update-step2 per-user loop), ADR 0016/0017 (customer PHP tree separation + review
  checklist).
- Architecture reference: `memory/reference/pmss-user-panel-gui-architecture-*` (sysadmin repo).
- Live evidence 2026-06-03: `wiki.pulsedmedia.com` CSP `frame-ancestors 'self' https://*.pulsedmedia.com`.
