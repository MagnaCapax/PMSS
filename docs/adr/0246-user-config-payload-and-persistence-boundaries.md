# ADR 0246: User configuration payload and persistence boundaries

Date: 2026-11-09
Category: architecture

## Status

Accepted.

## Context

The durable `UserConfigStore` combined record orchestration, pure normalization,
canonical and legacy collection, guarded JSON publication and home display-cache
preparation. Billing identifiers had separate default branches and each feature
toggle had its own normalization statement. These repeated policy sites obscured
the boundary between accepted record values and filesystem operations.

## Options Considered

- Retain the combined class and separate per-field policy sites.
- Introduce storage objects or traits and another dispatch layer.
- Keep the public class and move its existing internals into focused functions.

## Decision

Keep the public class, locks, required-field validation and cache orchestration
in `userConfigStore.php`. `userConfigPayload.php` owns payload and user-map
normalization. `userConfigPersistence.php` owns canonical/legacy collection and
the existing guarded JSON writer. The store remains the supported include;
the two internal modules have no include-time actions.

One traversal supplies the two billing defaults. One ordered table supplies the
four feature defaults, preserving opt-in session login and scheduled backups.
RAM fallback uses one eligibility condition and still normalizes again after
the runtime observation so the low-memory Docker gate applies. All original
validation, aliases, coercion, unknown keys, sorting and fallback rules remain.
No parallel class-private implementations remain.

## Consequences

- Five responsibilities move out of the controller: payload normalization,
  map normalization, canonical collection, legacy collection and JSON writing.
- Six per-field default mechanisms become two traversals, a concepts delta of
  -4. The shared RAM fallback condition also removes a nested control boundary.
- The class still owns display-cache preparation and the authoritative-first
  persistence order. No authoritative reads move into account homes (ADR 0213).
- Public-store characterization covers exact record values and key order,
  legacy shapes, precedence and memory gates. Isolated publication fixtures
  cover failure ordering, account identity, owner/group and mode arguments.
- The obsolete writer spelling in the direct-IOPS non-persistence assertion is
  replaced by the extracted writer's name; the prohibition remains enforced.
- Only changed-file PHP syntax checks run on the dev box. Characterization and
  existing integration tests execute on the testing machine before publication.

## References

- [Durable settings boundary](0213-home-directory-files-are-display-only-never-system-settings.md)
- [User management contracts](../contracts.md#user-management-cli)
- [Operational safety](../security/operational-safety.md)
