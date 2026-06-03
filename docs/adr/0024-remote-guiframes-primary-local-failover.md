# ADR 0024: Remote guiFrames primary; local frames failover

Date: 2026-06-03
Category: architecture
Status: Accepted (reverts #601). Related: ADR 0021, ADR 0022.

## Context

`index.php` builds the panel tab list from remote guiFrames
(`pulsedmedia.com/remote/guiFrames.php?v=2`) or a local inline `$frames` fallback. #601 removed the
remote path (local-only); the disabling flag `PMSS_DISABLE_REMOTE_FRAMES` is a CI invariant absent from
deployed configs, so remote was still live. Local-only loses on-load convergence: a GUI change then
reaches an install only at its `update.php` cadence.

## Decision

1. Remote guiFrames is PRIMARY; `index.php` falls back to local frames on any remote failure.
2. Local frames are the FAILOVER and must hold parity with the panel contract (ADR 0021): wiki in-page
   tab, enabled-only app tabs, per-user app discovery, `?quota` welcome URL.
3. `PMSS_DISABLE_REMOTE_FRAMES` remains an explicit opt-out; not set by default.

## Consequences

- The remote frame source must stay synced to the repo; a stale source self-updates installs to stale
  files (#466 class).
- `index.php` must `chdir(__DIR__)` before using the remote payload (the self-updater writes
  process-relative paths).
- Local-fallback parity is CI-enforced (`IndexSkeletonFrameDataTest`, `SkeletonWebLocalAssetTest`).

## Alternatives

- Local-only (retire remote): simpler, no remote-eval, no revert class; rejected — loses on-load convergence.
- Remote frames without the file-sync self-updater: removes the revert class; deferred.
