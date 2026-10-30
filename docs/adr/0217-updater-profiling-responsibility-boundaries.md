# ADR 0217: Updater profiling responsibility boundaries

Date: 2026-10-30
Category: architecture

## Status

Accepted

## Context

Updater profiling mixes capture, callable execution, normalization, summary logs
and report publication. Its callable path depends on status capture defined in
the shell-command module, although that module already loads profiling. Summary
construction separately traverses accepted rows to count statuses. Existing
callers depend on both include paths and all public helper signatures.

## Options Considered

- Retain the mixed files and implicit capture dependency.
- Introduce a profiler object and a new event model: adds lifecycle and schema
  concepts without improving the existing stateless entry points.
- Group capture, invocation and report handling behind the existing include.

## Decision

Keep `profile.php` as the include and owner of raw profile storage, command-row
capture and status logging. Move PHP invocation and classified failure dispatch
to `profileCallables.php`, and normalization, summaries and guarded persistence
to `profileSummary.php`. `commands.php` consumes capture through the facade and
keeps shell execution, account tagging and command builders.

Normalize and count each accepted row together, retaining a single local report
list. Batch dispatch passes its existing optional-argument and classification
defaults directly. Remove redundant logging/runtime includes from the shell
module because the profiling facade already loads them in the same order.

This removes the reverse capture dependency, a separate counting pass, the raw
store alias and the batch's three intermediate dispatch variables. No alternate
implementation, policy schema, renamed symbol or compatibility adapter remains.

## Consequences

- Include paths, signatures, raw row fields/order, return values and exception
  identity remain unchanged. Classified failures still run after ERR capture.
- Summary normalization, status buckets, top-five selection, report JSON bytes
  and log order retain their contracts. The raw global store is never sorted.
- Encoding, path validation, directory preparation and complete-write guards
  retain their order and failure diagnostics. No filesystem policy changes.
- Two definition-only modules must accompany the facade; normal updates stage
  the full scripts tree before phase 2.
- Characterization cases use deterministic clocks and capture publication calls
  without executing commands or writing reports. Existing report tests retain
  real temporary-file byte and failure coverage. The suite runs on the testing
  machine; devbox verification checks changed PHP syntax only.

## References

- [Runtime contracts](../contracts.md#runtime-execution--profiling)
- `scripts/lib/tests/development/profileBoundariesCharacterizationTest.php`
- `scripts/lib/tests/development/runtimeProfileTest.php`
