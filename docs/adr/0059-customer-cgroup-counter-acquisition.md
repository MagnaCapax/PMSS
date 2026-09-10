# ADR 0059: Shared customer cgroup counter acquisition

Date: 2026-09-10
Category: architecture

## Status
Accepted under the operator-approved architectural simplification run.

## Context
Stats and memory-pressure helpers duplicated counter paths, unlimited-sentinel
handling, and memory field selection. The pressure reader also copied local
counter variables into separate classification and output arrays.

## Options Considered
- Keep page-specific readers: retains parallel implementations of the same rules.
- Add a customer library: requires a new guiv delivery dependency (ADR 0022).
- Reuse `scriptsInc.php`: shares acquisition within the existing delivery set.

## Decision
Use the existing customer helper for one ordered counter-path builder, one
unsigned counter reader, and one memory-field schema. Build pressure output
fields once and substitute anonymous-memory values only for classification.
Keep directory-detection precedence and all v1 OOM/pressure rules unchanged.

## Consequences
Three path builders become one; two counter readers and two memory schemas each
become one. Classification consumes the output fields instead of a second map.
Pre-refactor payload snapshots and counter fixtures lock behavior. No new
customer delivery file, operator-tree dependency, or cgroup-v2 feature is added.
