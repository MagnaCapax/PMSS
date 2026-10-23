# ADR 0158: Bootstrap snapshot lifecycle boundaries

Date: 2026-10-23
Category: architecture

## Status
Accepted

## Context
The standalone updater downloaded and extracted release and branch archives
through separate implementations. Tree staging mixed an optional-tree callback
protocol with separate scripts and seedbox staging/backup sequences. These
mechanisms obscured the order of validation, publication and permission repair
inside the recovery bootstrap.

## Options Considered
- Keep the separate archive and publication implementations behind callbacks.
- Extract dependencies into libraries, compromising standalone recovery.
- Share transport and publication inside the bootstrap and dispatch trees explicitly.

## Decision
Keep every runtime helper in `scripts/update.php`. One archive helper owns
download followed by extraction, with HTTP/1.1 selected only for the existing
codeload fallback. One directory publisher owns staging, optional installed-base
overlay, atomic swap and backup cleanup. Tree validation returns presence to an
ordered scripts/etc/var dispatcher; etc preparation owns skeleton clearing,
seedbox merging and the remaining config overlay.

The two archive pipelines, two publication pipelines and callback protocol become
three mechanisms: archive transport, directory publication and ordered dispatch.
The installed-base overlay remains a policy of publication, preserving its
existing behavior. No alternate implementations or runtime dependencies remain.

## Consequences
- Commands, labels, exit boundaries, tree order and dry-run messages stay stable.
- Missing and empty trees share their existing warning and event path.
- Snapshot and symlink checks still run during dry runs and before publication.
- Characterization tests record complete publication traces for six installed-base
  and fetched-config combinations, archive success/failure commands, and dry-run
  output. Mutation boundaries are replaced by recorders in temporary fixtures.
- The bootstrap remains a large file by design; these boundaries are internal.

## References
- [Updater workflow](../update.md)
- [Refactoring contract rules](../refactoring.md)
- [Codeload fallback](0050-codeload-branch-tarball-fallback-when-git-clone-is-blocked.md)
- [Fetch HTTP version](0052-update-forces-http1.1-on-git-operations-to-avoid-github-http2-upload-pack-challenge.md)
