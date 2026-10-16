# ADR 0099: Split scriptsInc.php into guiv-delivered parts

Date: 2026-10-14
Category: architecture

## Status

Accepted (operator-directed, 2026-10-14)

## Context

`etc/skel/www/scriptsInc.php` has grown to roughly 1,800 lines and 80 KiB.
ADR 0022 requires every customer panel dependency to be deliverable by the guiv
heal. That path accepts only flat `www/` PHP names that are present in its
billing-side allowlist and sync list; dotfiles and subdirectories cannot work.
Existing homes also do not reliably receive new sibling files through
`update.php` alone (#586, #953, #1039).

## Options Considered

- Split directly into a loader and new parts in one release: rejected because
  an existing home could receive the loader before every dependency arrives.
- Use a dotfile or subdirectory for the parts: rejected by ADR 0022's guiv
  delivery limits.
- Publish inert flat parts, verify delivery, then switch the loader: chosen.

## Decision

Create twelve flat `www/` parts named `scriptsIncFormat.php`,
`scriptsIncFiles.php`, `scriptsIncFrontend.php`, `scriptsIncBonus.php`,
`scriptsIncCgroup.php`, `scriptsIncBackup.php`, `scriptsIncRestoreTar.php`,
`scriptsIncRestore.php`, `scriptsIncAppProcess.php`, `scriptsIncAppsHtml.php`,
`scriptsIncAppsStatus.php`, and `scriptsIncActionJs.php`. The billing-side
allowlist and sync list already carry these exact names. Phase 1 adds them to
the per-user manifest as inert, guarded copies. `scriptsInc.php` and its
callers remain unchanged.

After delivery was verified on sampled hosts (every account had all twelve
parts), Phase 2 turned `scriptsInc.php` into the sole ordered loader. It keeps
`writeLog` in the loader so the home-level rTorrent scripts can log even when
every part is absent. Each part is required from `__DIR__` only when
`is_file($path) && !is_link($path)`; a missing part leaves only its functions
unavailable. Callers continue requiring only `scriptsInc.php`. `checkGui.php`
restores damaged parts along with the loader, and source-text tests inspect
the loader and parts together.

## Consequences

- Existing homes keep running the monolith during Phase 1, regardless of the
  order in which the heal delivers the new files.
- The token-based split test checks that the loader and parts define the same
  89 functions as `scriptsInc.php` at 59fc75bd, with no duplicates.
- Every future part name must enter the billing-side allowlist and sync list
  before a loader may depend on it. This decision amends ADR 0022.

## References

- ADR 0016 (customer PHP tree separation); ADR 0022 (guiv delivery closure).
- PMSS #586, #953, #1039 (existing-home sibling delivery).
