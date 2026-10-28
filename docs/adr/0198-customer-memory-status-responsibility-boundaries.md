# ADR 0198: Customer memory status responsibility boundaries

Date: 2026-10-28
Category: architecture

## Status

Accepted under the operator-approved architectural simplification run.

## Context

The customer memory-status helper combined cgroup discovery, counter acquisition,
pressure policy, payload assembly, saved Welcome state and RAM HTML. Two separate
memory-stat traversals implemented different completeness requirements. Severity
bands repeated threshold dispatch, and saved current/anonymous/cache counters
used separate normalization paths. The existing status and HTML snapshots define
the compatibility boundary.

## Options Considered

- Retain the combined helper and coordinate its parallel fallback loops.
- Separate responsibilities behind the existing include and share traversal and
  threshold dispatch, preserving consumer policy (chosen).
- Reuse operator-side collectors, violating the customer-tree boundary.

## Decision

`webCgroupMemoryStatus.php` remains the customer include. It checks all four local
modules before loading any memory API, following the existing statistics facade:

- `webCgroupMemorySource.php`: directory discovery, memory-stat parsing and
  traversal, and recursive v1 OOM counters.
- `webCgroupMemoryPolicy.php`: OOM recency and ordered pressure thresholds.
- `webCgroupMemoryRead.php`: the existing status payload and byte formatter.
- `welcomeMemory.php`: normalized saved/live RAM state and its HTML projection.

One memory-stat traversal returns the first candidate containing every requested
field, or the last parsed fallback. Pressure needs `anon`; Welcome needs both
`anon` and `file` and retains its saved partial breakdown when no complete pair
exists. Candidates are never merged across files. Field aliases share a lookup;
last valid rows retain precedence and malformed/overflowed rows remain rejected.

HIGH and MEDIUM use one ordered threshold table. Recent OOM and THROTTLED retain
their earlier precedence. Saved current/anonymous/cache values normalize into one
record before the existing live fallbacks; an explicit unavailable pressure
override still suppresses the live pressure indicator.

## Consequences

The control-flow ledger removes five independently maintained mechanisms: the
second memory-stat traversal, severity dispatch, field-alias dispatch, saved
memory normalization path, and pressure-override selection branch. One module
delivery set is added, giving a net reduction of four mechanisms.

All original public functions, result types, key order, thresholds, recency
windows, HTML bytes and customer-only includes remain intact. No cgroup-v2
feature, persistence change, new listener or operator-tree dependency is added.
The old combined implementation is removed.

The four modules become delivery dependencies. User maintenance distributes them
before the facade. During an incomplete heal, the Welcome and Info pages keep
their existing unavailable fallbacks until the normal update supplies them; the
external heal delivery set can then add these modules as well.

The behavior lock includes unchanged full-payload and RAM HTML hashes, severity
and OOM boundary matrices, candidate selection/fallback cases, and every missing
module. Suites and render checks execute only on testing machines.

## References

- [Customer cgroup acquisition](0059-customer-cgroup-counter-acquisition.md)
- [Customer PHP separation](0016-customer-php-tree-separation-from-operator-scripts.md)
- [Statistics facade precedent](0156-customer-statistics-helper-boundaries.md)
- [Refactoring guide](../refactoring.md)
