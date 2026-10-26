# ADR 0190: ARR installer module boundaries

Date: 2026-10-26
Category: architecture

## Status
Accepted

## Context
The shared ARR installer combined configuration validation, metadata parsing,
architecture selection, activation, and application dispatch in one module.
Activation interpreted positional phase records and carried both a mutable
success flag and a separate error message through subsequent guarded steps.
The public facade and ADR 0034's prohibition on executing installed binaries
must remain intact.

## Options Considered
- Keep the monolith and its phase interpreter.
- Split responsibilities while retaining the interpreter and duplicate outcome state.
- Split responsibilities and express activation as ordered guards with direct returns.

## Decision
Keep `arr.php` as the public loader, update controller, and supported-app
dispatcher. Move existing input guards to `arr/config.php`; move version
metadata and asset parsing to `arr/release.php`; put activation and its path
recheck in `arr/activation.php`. Each implementation exists in one place.

Replace the phase interpreter and mutable success/message pair with explicit
steps and direct returns. One `finally` releases the private workspace after
ordinary returns and exceptions. Configuration validation order, architecture
preference, command bytes, failure messages, ownership refresh policy, and
metadata-only version detection retain their existing contracts.

## Consequences
Callers retain every public function and the existing include path. The update
app autoloader sees only the existing facade; helper modules live below it.
Workspace cleanup now also runs when a dependency or logger throws; the original
exception propagates when cleanup succeeds. No rollback or atomic replacement
guarantee is added: the prior removal-then-move sequence is preserved.

`ArrActivationCharacterizationTest` locks command, diagnostic, guard, removal,
and cleanup traces at twelve normal and failure boundaries. Existing installer
tests retain archive, architecture, metadata, and path-guard coverage. Source
policy checks inspect the facade and all its modules for forbidden execution
primitives.

## References
- [ADR 0034](0034-install-is-not-execution-for-third-party-app-installers.md)
- [Update workflow](../update.md)
