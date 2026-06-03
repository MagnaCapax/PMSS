# ADR 0024: Remote guiFrames is the primary customer-panel frame source; local frames are the failover

date: 2026-06-03
category: architecture

## Status

Accepted (operator-directed 2026-06-03, reverting #601). Supersedes the #466→#601 "disable then
remove remote frames" decision chain. Pairs with ADR 0021 (top-frame navigation contract) and ADR 0022
(guiv-delivered files must be self-contained).

## Context

The PMSS customer panel `index.php` can build its top-frame tab list from two sources:

- **Remote**: fetch `https://pulsedmedia.com/remote/guiFrames.php?v=2` → base64 → unserialize → `eval()`.
  The eval'd payload (web4 `data/framesV2.php` + `version.php`'s `$fileVersions`) defines the tabs AND
  runs a self-updater that SHA-syncs the panel's PHP files from `retrieve.php` on a 3h debounce.
- **Local**: an inline `$frames` array in `index.php` (the `if ($useLocalFrames)` branch).

History: the remote self-updater reverted GitHub deploys fleet-wide when web4 served stale files (#466,
blank-page waves). The response disabled remote frames (`PMSS_DISABLE_REMOTE_FRAMES=1` in
`template.lighttpd`, #471/`b621a66b`, 2026-04-24), built local parity (#523/#566), and finally removed
the remote block entirely as "dead code" (#601/`ec38c41b`, 2026-05-28). But by 2026-05-28 the root cause
of #466 had already been fixed: web4 `guiv/` is synced to current daily
(`/home/billing/scripts/sync-guiv-from-pmss.sh`, installed 2026-05-12). Removing the remote PRIMARY —
rather than keeping local as a FAILOVER — lost the fleet-wide on-load GUI auto-convergence and left the
fleet drifting at PMSS-update cadence.

## Decision

1. **Remote guiFrames is the PRIMARY frame source.** `index.php` attempts the remote fetch/eval when
   `PMSS_DISABLE_REMOTE_FRAMES` is unset and falls back to the local frame set on any failure
   (network, decode, non-array eval, helper unavailable).
2. **Local frames are the FAILOVER, and MUST maintain parity** with the current panel contract (ADR 0021):
   wiki as an in-page iframe tab (not a new window), enabled-features-only app tabs, per-user installed
   app discovery, `?quota` welcome URL. A failover that renders a degraded panel is a defect.
3. **`PMSS_DISABLE_REMOTE_FRAMES` remains supported** as an explicit per-install opt-out (offline/air-gapped
   or licensee installs that must not phone home), but is NOT set by default in the template.

## Consequences

- **web4 currency becomes a hard production invariant.** A stale web4 re-arms the #466 revert-wave (the
  self-updater overwrites user files from web4). The daily sync cron is therefore load-bearing and must be
  monitored; `framesV2.php` and `guiFrames.php` (currently hand-maintained, not git-synced) MUST be brought
  current and kept current (see Open Items).
- **The self-updater must be idempotent and CWD-independent.** It writes process-relative paths
  (`.update`, `.guilog`, the synced files); `index.php` MUST `chdir(__DIR__)` before the eval so the heal
  resolves against the user's `www/` regardless of invocation context (see Open Items).
- **Local-fallback parity is CI-enforced** (`IndexSkeletonFrameDataTest`, `SkeletonWebLocalAssetTest`).

## Alternatives considered

- **Local frames as the single source of truth (remote retired).** Strongest on sovereignty (no phone-home)
  and reliability (one git-synced source, no revert-wave class, no `eval(remote)` surface). Business case for
  retaining remote is weaker than it appears: (a) the original PMSS-licensing rationale (central GUI push to
  third-party installs) is not implemented by the current mechanism — `framesV2.php` hardcodes PM's own URLs,
  which a sovereign licensee would not want — and is not current revenue; (b) the "push changes instantly"
  value is largely delivered by remote CONTENT fetches (welcome message / announcements), which are SEPARATE
  from the frame definitions and can be kept independently; (c) frame DEFINITIONS rarely change. This ADR
  keeps remote-primary as the operator's chosen design (it preserves the failover architecture and undoes
  the #601 amputation), and records local-as-single-source as the revisit option. **Revisit trigger:** if
  PMSS licensing does not materialize, or if maintaining web4 currency proves costly, migrate to
  local-as-primary + remote-for-content-only.
- **Decoupled redesign (remote frame definitions, drop the file-overwriting self-updater).** Removes the
  revert-wave risk while keeping remote-driven tabs; deferred as a larger change.

## Open items (tracked separately)

- `framesV2.php` on web4 is dated 2026-02 and stale (defines a defunct freenode "Chat" tab, owncloud, a
  "News" tab) — it does not match the current local frame contract. Bring current + add to the daily sync.
- `index.php` should `chdir(__DIR__)` before the remote eval so the self-updater is CWD-independent.
- Add a live-behavior verification gate before removing PRIMARY runtime code paths (the gate #601 lacked).
