# ADR 0193: Diagnostic snapshot responsibility boundaries

Date: 2026-10-27
Category: architecture

## Status

Accepted

## Context

The diagnostic snapshot facade contained the ordered probe catalog, checked PHP
subprocess execution, recursive acquisition, result decoding and CLI presentation.
Repository-root fallback duplicated its reported-path update; integer and text
results separately selected a scalar fallback. Timeout handling converted strings
before validating integers, and text rendering appended section output twice.

## Options Considered

- Retain all responsibilities inside the facade.
- Add a generic probe framework shared with unrelated reports.
- Separate the existing diagnostic responsibilities and consolidate their local
  repeated decisions without introducing another API or implementation.

## Decision

Keep `scripts/lib/agentDiagnostics.php` as the stable include, CLI and text
renderer. Load three internal modules from that facade:

- `catalog.php` owns the unchanged ordered host and per-account probe definitions.
- `command.php` owns checked script execution. Repository-root fallback selects
  the reported path once; the default script root accounts for the deeper module.
- `collect.php` owns recursive collection and result projection. Integer and text
  output share scalar fallback selection. Timeout acceptance and bounding use one
  predicate, and text sections share one append in the facade.

All existing functions and the timeout constant retain their names and signatures.
There is one implementation of each function. Existing callers include the facade;
the internal modules are not standalone entrypoints. The source-level interpreter
regression assertion follows the code into `command.php`.

## Consequences

The facade no longer knows the probe catalog, file acquisition or command-result
decoding. Four repeated decisions are removed: reported-path assignment, timeout
normalization state, scalar fallback selection and rendered-section append.
Splitting modules adds include boundaries but no alternate execution path.

Probe order and command bytes, script-root guards, PHP lookup through PATH,
timeouts, root and managed-user checks, file whitespace, format-specific wrapping,
JSON error records and stdout/exit contracts remain unchanged. No credential,
permission, listening-port or public exposure policy changes.

`AgentDiagnosticsCharacterizationTest` locks result shapes across successful,
failed and timed-out captures, raw file payloads, recursive execution order and
text bytes using reflected production functions and in-memory probes. The existing
CLI tests retain subprocess, unsafe-path, user-selection and timeout coverage.

## References

- [Architecture overview](../architecture.md)
- [Diagnostic contracts](../contracts.md#agent-diagnostics--scriptsutilagentdiagnosticsphp)
- [Refactoring guidelines](../refactoring.md)
