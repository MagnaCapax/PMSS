# ADR 0187: User lifecycle audit and service boundaries

Date: 2026-10-26
Category: architecture

## Status

Accepted

## Context

The shared lifecycle facade mixed audit context and output, suspended web-root
recovery, and service execution. Provisioning, runtime account logs, and port
assignment also composed the audit context and writer independently. Web-stack
verification projected its success and failure through separate audit calls.

## Options Considered

- Keep the mixed facade and independent audit composition.
- Change every lifecycle caller to load individual modules.
- Retain the facade, separate its responsibilities, and use its existing audit
  entrypoint for all event composition (chosen).

## Decision

`userLifecycle.php` remains the compatibility load point. `user/lifecycleLog.php`
owns context, JSON/text output, and the existing audit entrypoints;
`user/lifecycleWebRoot.php` owns root validation, suspension state, content
inspection, and backup selection; `user/lifecycleServices.php` owns logged shell
steps, nginx refresh, and web-stack observation. These modules are composed by
the facade and do not add alternate implementations.

All callers that formerly paired `pmssUserBaseContext()` with
`pmssUserWriteLogs()` now use `pmssUserLifecycleContextLog()`. Both lower-level
functions remain available for compatibility. Runtime account logging retains
its optional mirror, checking the single entrypoint it invokes. Web-stack
verification emits one audit from the observed missing-process list and derives
its return code from that same observation. Dry-run step output uses the
existing flag directly instead of storing a second message-state variable.

## Consequences

Public function names, signatures, callback order, commands, audit field order,
extra-field precedence, text bytes, and exit semantics remain unchanged. No
state migration or deployment action is required. The root-only logging guard,
credential diagnostic redaction, and web-root safety checks remain intact.

`UserLifecycleAuditCharacterizationTest` locks deterministic JSON/text events,
root-only suppression, dry-run behavior, partial startup, and a watchdog winning
the startup race. Existing lifecycle, provisioning, port, and credential tests
retain their assertions; source-location checks and injected audit adapters
follow the shared implementation.

## References

- [User management contracts](../contracts.md#user-management-cli)
- [Refactoring guidelines](../refactoring.md)
