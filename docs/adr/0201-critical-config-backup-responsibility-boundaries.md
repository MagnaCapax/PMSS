# ADR 0201: Critical config backup responsibility boundaries

Date: 2026-10-29
Category: architecture

## Status

Accepted

## Context

The critical-service-config backup library combines creation, target preparation,
filename normalization and retention in one file. Retention additionally builds a
kept-file map from the already sorted file list and maintains a mutable deletion
flag. Directory preparation duplicates the same unsafe-target predicate for the
backup root and service directory.

## Options Considered

- Keep the mixed library and its secondary retention representation.
- Add a separate retention planner and an intermediate deletion list.
- Keep the existing API facade, separate preparation and retention, and use the
  position in the sorted file list directly for count expiry.

## Decision

Retain `scripts/lib/configBackups.php` as the backup-creation entry point.
`configBackups/context.php` owns the existing normalization helpers, logger
fallback and target preparation. Its ordered root/service-directory traversal
uses one unsafe-target predicate and retains the existing warning text.
`configBackups/retention.php` owns pruning. Count expiry uses the sorted position;
TTL expiry remains independent. There is no kept-file map, mutable deletion flag,
deletion plan or alternative implementation.

Every existing function name, signature and context field remains available through
the original include. Backup bytes, names, chmod policy, copy-before-prune order,
logger selection and dry-run guards retain their behavior. Pruning still accepts
a missing source. Nonpositive limits disable the corresponding count/age rule;
the TTL boundary is strict. Undated names consume count slots but do not age out,
and the historical source-key prefix glob remains unchanged.

## Consequences

- Creation, preparation and retention have separate owners without a new API.
- Retention operates on one sorted list, removing the redundant membership map
  and deletion flag. Root and service-directory validation share one predicate.
- The facade must be shipped with both sibling modules, as other operator-side
  libraries are. It is not a standalone bootstrap file.
- Existing backup and guard tests remain intact. The added retention
  characterization covers count/TTL combinations, exact TTL boundaries, label
  ordering, undated names, absent sources and legacy key-prefix matching. Tests
  execute only on testing machines.

## References

- `docs/refactoring.md`
- `scripts/lib/tests/development/ConfigBackupsCharacterizationTest.php`
- `scripts/lib/tests/development/ConfigBackupsRetentionCharacterizationTest.php`
