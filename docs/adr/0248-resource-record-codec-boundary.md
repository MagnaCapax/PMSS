# ADR 0248: Resource record codec boundary

Date: 2026-11-09
Category: architecture

## Status

Accepted

## Context

Resource text serialization and parsing lived in the statistics facade. The
live reader loaded account file writers, report projections and aggregation to
parse one record. Counter-state preparation, serialization, parsing defaults and
sample accumulation separately listed the same counter and current-value fields.

## Options Considered

- Keep the independent field inventories and the live reader's facade dependency.
- Introduce a configurable record framework with version dispatch.
- Extract the existing codec and share its ordered fields with the consumers.

## Decision

`scripts/lib/resources/logCodec.php` owns the existing text-record helpers,
memory-breakdown map and ordered delta/current fields. It loads only the pure runtime config parser.
The resource facade keeps exporting the same helpers. State preparation uses the
same delta and current fields, with the timestamp inserted in its existing place.
The accumulator uses the decoded seven-field layout and retains its public
metric constants; RAM hours remain derived independently. The live reader loads
runtime helpers and the codec directly.

## Consequences

Independent counter/current inventories and the accumulator's handwritten sample
projection disappear. Live reads no longer depend on resource aggregation or
account file writers. There is one codec implementation and no migration.

Five- and six-field legacy records, seven-field operation counts, nine-field
memory breakdowns and ignored trailing extensions retain their behavior. Eight
fields remain invalid. Digit-only and finite-number checks, float types, key
order, CPU deltas, optional memory handling, source/scope reseeding, daily
aggregation and serialized payload order remain unchanged.

`ResourceLogCodecCharacterizationTest` locks field layouts, writer input ownership,
parsed payloads, malformed records, state ordering, source reseeding and include
boundaries. Existing accumulator byte snapshots retain the persisted behavior
lock. Tests execute on the testing machine; this checkout runs changed-file PHP
syntax checks only. No host commands or provisioning dry runs execute locally.

## References

- [Resource counter/state boundaries](0078-resource-counter-and-state-boundaries.md)
- [Resource statistics contracts](../contracts.md#resource-statistics)
