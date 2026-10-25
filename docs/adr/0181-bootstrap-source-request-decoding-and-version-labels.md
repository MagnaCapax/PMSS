# ADR 0181: Bootstrap source request decoding and version labels

Date: 2026-10-25
Category: architecture

## Status
Accepted

## Context
The standalone bootstrap maintained separate flag and value-option dispatchers,
three decoded-source record constructors, and repeated version-label projection
paths. Its public functions also carry historical syntax and truthiness rules
that cannot be changed by a refactor. Recovery requires the single-file bootstrap
to remain usable without the shared updater libraries.

## Options Considered
- Preserve the parallel dispatch and record construction paths.
- Introduce a shared CLI parser dependency, compromising standalone recovery.
- Consolidate decoding and projection inside the existing public functions.

## Decision
Use one spelling table for flag and value options, retaining `=` in value-option
keys so unsupported spellings remain positional input. Git and release decoding
populate one ordered source record. Keep their distinct URL and non-URL split
rules, but share non-URL branch fallback. Serialize the optional pin suffix once.
Version ordering fills one decision record, including indeterminate dates, and
recorded content dates share one midnight expansion.

No public function is removed and no replacement implementation or dependency
is introduced. Loose normalization remains separate from structural decoding:
their existing accepted-input sets differ, including empty Git suffixes.

## Consequences
- Option precedence, raw upgrade-cap values, legacy aliases, source record key
  order, URL-port parsing, literal `0` handling and version labels stay stable.
- Three duplicated mechanisms become single mechanisms: option dispatch,
  decoded-source construction and pin-suffix serialization. Ordering and marker
  projection also lose duplicate result/formatting paths.
- Characterization cases cover option forms, normalization, decoded sources and
  serialized versions. Existing version-guard tests cover ordering and marker dates.
- URL and non-URL branch truthiness intentionally remain different for compatibility.

## References
- [Bootstrap contracts](../contracts.md#updater-bootstrap-phase-1--scriptsupdatephp)
- [Update workflow](../update.md)
- [Bootstrap snapshot lifecycle](0158-bootstrap-snapshot-lifecycle-boundaries.md)
- [Content-date version markers](0054-version-marker-records-content-commit-date-not-install-time.md)
