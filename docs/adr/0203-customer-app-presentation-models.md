# ADR 0203: Customer app presentation uses explicit models

Date: 2026-10-29
Category: architecture

## Status

Accepted under the operator-approved architectural simplification run.

## Context

Welcome maintained separate app labels, fallback descriptions, common ordering,
media suggestions and icon memberships. Its tile closure mixed projection with
grid ordering, then split the completed grid again to place overflow tiles.
The media-stack panel also assembled separate installed-status records for
available and absent watchdog snapshots, alongside three memory-preflight records.

## Options Considered

- Keep independent app lookups and repeated result shapes.
- Introduce new customer helper files, requiring coordinated guiv delivery under
  ADR 0022. This is unnecessary for these internal boundaries.
- Consolidate presentation policy and project observations inside the existing
  delivered files. Selected.

## Decision

Welcome uses one presentation catalog for labels, fallback descriptions, common
order, absent media suggestions, torrent-profile detection and icons. Toggle-app
labels still override display names. Unknown apps keep their original fallbacks;
the catalog does not authorize actions or control service installation.

One tile projection owns state-to-action policy, including opening ruTorrent
while rTorrent is stopped. The grid retains its active/inactive sort order and
builds the overflow bucket directly. Installed media-stack status has one pure
projection after the existing recovery-gate and watchdog reads; memory preflight
has one result shape after choosing its message.

## Consequences

- Five independent Welcome presentation lookups become one catalog. The two media
  suggestion/icon membership lists also disappear. Named projections replace the
  tile closure and the installed branch's two result assemblies.
- Existing function entrypoints, field order, labels, messages, thresholds, URLs,
  HTML escaping and action endpoints retain their behavior.
- Helpers remain in the same delivered PHP files, with no new includes or guiv
  allowlist changes. Authentication, credential handling and write paths are unchanged.
- Characterization cases exercise the existing grid and media status entrypoints:
  actions, overlay precedence, icon bytes, unknown apps, absent suggestions,
  installed-state precedence and both recovery-gate outcomes. Existing tests cover
  hero placement, overflow order and memory thresholds. Tests run on testing machines.

## References

- [Welcome behavior](0147-welcome-page-status-first-home.md)
- [Customer-tree separation](0016-customer-php-tree-separation-from-operator-scripts.md)
- [Customer delivery closure](0022-guiv-delivered-customer-files-must-be-self-contained.md)
