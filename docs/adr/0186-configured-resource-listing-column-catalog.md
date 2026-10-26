# ADR 0186: Configured resource listing column catalog

Date: 2026-10-26
Category: architecture

## Status
Accepted

## Context
The configured-resource CLI kept headers, printf widths and row values in
separate positional lists. Its full layout extended each list independently.
The same file also derived quota state, read traffic artifacts and selected
text/JSON/JSONL output using two dependent flags. These representations could
drift even though the command's output is a compatibility contract.

## Options Considered
- Retain the parallel lists and rely on their positions staying synchronized.
- Introduce a configurable table framework with formatter callbacks.
- Keep one concrete column catalog and separate projection from presentation.

## Decision
Keep `user/resourcesList.php` as the CLI facade with the existing help, root
gate, account traversal and JSON emission. `resourcesListModel.php` owns quota
derivation and the existing entry projection; it loads managed-user selection
directly instead of the unrelated lifecycle/watchdog facade.
`resourcesListDisplay.php` owns compact number formatting and one ordered
catalog of header, width and rendered value triples. The brief layout takes
the first eleven columns; full takes all nineteen. Headers, printf formats
and the existing row helper project that catalog without a second column list.

One output mode replaces the dependent JSON flags, retaining JSONL precedence.
Quota bursts select explicit numeric values or their fallback directly. The
entry reuses the quota fields while preserving the final position of the
suspension field in JSON. No alternate renderer or projection remains.

## Consequences
All existing listing helper names and the CLI entrypoint remain available.
Column changes have one definition. Presentation can be loaded independently
without systemd, account or traffic readers; model queries still run only when
called. Modules add no new configuration, dependency, credential or listener.
The root gate and account validation retain their original behavior.

This is confined to configured limits. Resource-consumption aggregation and
traffic reports have different schemas and remain separate consumers.

## Validation
`UserResourcesListCharacterizationTest` exercises the real CLI function with
namespaced observations on the testing machine. It locks both complete text
layouts, JSON bytes and types, JSONL precedence, empty lists, vanished accounts,
help-before-root ordering and truthy option conflicts. Existing quota, zero
burst and compact-format cases remain in `UserResourcesListHelperTest`.
Source assertions follow the moved model; header assertions check catalog
order directly. No live systemd or account operation is needed for validation.

## References
- [Function contracts](../contracts.md)
- [Refactoring guidelines](../refactoring.md)
