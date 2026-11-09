# ADR 0244: Skeleton delivery and maintenance boundaries

Date: 2026-11-09
Category: architecture

## Status

Accepted (operator-approved architectural simplification run).

## Context

`scripts/lib/update/users/filesystem.php` combined the ordered skeleton delivery
controller with home-path maintenance, generic writable-file patches and a
specific legacy panel-index migration. The GUI watchdog separately listed the
same twelve ADR 0099 helper files. The updater delivers those parts before its
loader; the watchdog restores its index and loader before the parts. Both orders
are existing behavior and must survive this refactor.

## Options Considered

- Split helpers but retain independent delivery lists: leaves duplicate inventory
  policy and the risk of adding a part to only one delivery path.
- Make both delivery paths use one complete order: changes existing behavior.
- Separate maintenance responsibilities and share only the common part inventory:
  chosen; each controller keeps its surrounding order and writer boundaries.

## Decision

Keep `filesystem.php` as the existing include and delivery controller. Load
`filesystemPaths.php` for path guards, obsolete-file removal and skeleton links;
`filesystemPatch.php` for guarded text transformations and metadata-preserving
writes; and `panelCompatibility.php` for the legacy index migration.
`panelFiles.php` supplies the same twelve helper basenames to both controllers,
with the updater's existing `www/` prefix applied there.

Keep every existing helper name and signature. String patches retain placeholder
collision checks and ordered replacements; one `str_replace()` call performs
replacement and placeholder restoration sequentially. Its empty search element
is a no-op, removing the separate restoration branch. Obsolete explorer removal
joins the existing ordered deletion traversal. Delivery iterates its manifest
directly, without retaining a second local value.

## Consequences

- Independent panel-part inventories decrease from two to one. The special
  explorer deletion path and conditional placeholder-restoration path disappear.
  No parallel implementation or alternate delivery mode remains.
- Existing home/path guards, account writers, root-stage fallback, modes, patch
  bytes, compatibility refresh, cleanup ordering and log messages are preserved.
  Customer PHP gains no operator-tree dependency (ADRs 0016 and 0022).
- Additional internal files require the complete staged tree, which phase 2 and
  the watchdog already consume. The standalone bootstrap gains no dependency.
- `UserSkeletonDeliveryCharacterizationTest` locks the original 143-file manifest
  digest, maintenance order, all watchdog delivery arguments, patch collisions,
  overlap behavior, sequential patches and no-op writes using in-memory stubs.
  Existing source contracts follow the new inventory and migration locations.
- Local verification is changed-file PHP syntax checking and symbol/reference
  review. Characterization execution and service-level dry runs belong on the
  testing machine; this refactor does not run account mutations on the dev box.

## References

- ADR 0099 (panel helper delivery); ADR 0041 (web-root ownership boundaries).
- `docs/architecture.md`; `docs/update.md`; `docs/contracts.md`.
