# ADR 0155: Separate status probing from report rendering

Date: 2026-10-23
Category: architecture

## Status

Accepted

## Context

The system/component status library combined probe configuration, command and
filesystem observations, report assembly, and terminal/JSON output. Assembly
passed a mixed bundle of report arrays and an optional sources row between
helpers. Binary and path probes repeated the same two-view row projection;
virtualenv binaries and their CLI links had independent application registries.

## Options Considered

- Keep the combined library and its intermediate report bundle.
- Split responsibilities while retaining the existing assembly protocol.
- Separate probing and rendering, and assemble the final ordered report in
  one flow with shared row projection and one virtualenv registry (chosen).

## Decision

`systemStatus.php` remains the entry point for both CLI utilities and their
existing public helpers. It assembles the final report directly, reusing the
codename row and sources-match decision for both views. Component-only calls
return before system-only filesystem probes.

`systemStatus/probes.php` owns the catalog, injectable context, bounded command
lookup, and shared row projection. `systemStatus/render.php` owns status rows,
summary counts, and terminal/JSON output. The old intermediate bundle has no
remaining implementation. Both virtualenv passes use one application registry,
preserving the historical binaries-before-links order.

## Consequences

CLI flags, default output bytes, row order, return codes, validation, probe
deadlines, and dependency overrides retain their existing behavior. The
characterization suite locks complete reports and output, single evaluation of
shared probes, and missing, plain-file, unreadable, wrong-target, and valid-link
virtualenv results including callback order. Verification runs on testing
machines; this restructuring does not run host probes on the control node.
