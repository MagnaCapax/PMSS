# ADR 0153: Traffic report model and output boundaries

Date: 2026-10-22
Category: architecture

## Status

Accepted

## Context

The traffic report combined CLI policy, filesystem acquisition, sorting, JSON
projection, and terminal formatting in one module. Five separately maintained
fields held monthly totals, limit counts, and a base-account presence map.
Output helpers translated those fields into another totals and summary schema.

## Options Considered

- Retain the combined module and its running summary state.
- Split the functions while retaining both summary representations.
- Separate CLI, acquisition/model, and output responsibilities, deriving one
  totals/summary representation from accepted rows (chosen).

## Decision

Keep `traffic/report.php` as the legacy include path and CLI facade.
`traffic/reportModel.php` owns user discovery, guarded payload reads, row
acceptance, summaries, and sorting. `traffic/reportDisplay.php` owns JSON and
terminal projections and performs no filesystem reads. Existing function names
and entrypoints remain available through the facade; no alternate flow remains.

The internal report contains `rows`, `missingStats`, `totals`, and `summary`.
Derive totals and summary once, before sorting, preserving floating-point
addition order. Count distinct external accounts for users with statistics,
but retain per-row totals and limit counts when the input repeats an account.
Local-only accounts count toward total users without counting as external
accounts with statistics. Malformed and zero-month payloads remain omitted;
only missing files enter the missing-statistics list. The original raw fields,
numeric types, and insertion order remain available to JSON output.

Help returns before sort/color validation. Text color selection follows the
JSON return, and both text summary layouts share missing-user selection.

## Consequences

This supersedes ADR 0079's internal report representation while retaining its
single-report handoff. Five maintained summary fields become two derived maps;
the deferred help status and duplicate missing-user output path disappear.
CLI text, JSON ordering/rounding, exit codes, limit thresholds, ingress reads,
and serialized artifacts retain their existing contracts. No migration is needed.
The model makes a few linear array projections over the already loaded rows.

`ShowTrafficFormatTest` locks help precedence, raw numeric-string preservation,
duplicate and local-only accounts, malformed/zero/missing payloads, empty float
totals, addition order before sorting, and the existing JSON/text snapshots.

## References

- [Traffic report handoff](0079-traffic-report-state-is-passed-as-one-value.md)
- [Refactoring contract rules](../refactoring.md#minimal-contract-rules-joukahainen)
