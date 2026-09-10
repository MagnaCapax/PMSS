# ADR 0057: Resource aggregation uses the persisted payload

Date: 2026-09-10
Category: architecture

## Status
Accepted

## Context
Resource aggregation maintained different window and daily tally structures,
then translated an intermediate result into the stored resource payload.
Daily snapshots needed a separate reader for raw-log fallback results.

## Decision
Use one sum/count bucket for windows and days. The accumulator returns the
existing persisted payload directly; both snapshot paths use its shared reader.
Keep payload projections in `scripts/lib/resources/payload.php`. Remove the
intermediate result converters and migrate their internal callers together.

## Consequences
Serialized customer/runtime artifacts, CLI output, window boundaries, first-day
exclusion, RAM-hour interval fallback, and memory-breakdown retention stay fixed.
Five pre-refactor serialized-payload snapshots lock ordering, types, and values.
The internal accumulator result shape changes; no on-disk migration is needed.
