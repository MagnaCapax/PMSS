# ADR 0267: System preparation baseline boundaries

Date: 2026-11-11
Category: architecture

## Status
Accepted

## Context
The historical systemPrep.php include mixed temporary-mount policy, boot artifact
installation, locale/timezone convergence and root-shell defaults. Boot installation
described the same two artifacts separately for preflight and publication. Locale
observations maintained availability and default-match flags alongside their inputs.
The phase-2 orchestrator consumes these helpers and their existing log/command output.

## Options Considered
- Keep unrelated baseline responsibilities in the shared include.
- Add a generic convergence framework with configurable callbacks and policies.
- Retain the include and public helpers, separating responsibilities and removing
  duplicate artifact metadata and derived locale bookkeeping.

## Decision
Keep systemPrep.php as the definition-only facade. Its baseline helpers live in
systemPrep/tempMount.php, bootTuning.php, locale.php and shellDefaults.php. Keep the
existing dependency load order before loading these definitions. Each helper keeps
its signature; callers and the phase-2 execution sequence stay unchanged.

Boot tuning uses one ordered artifact list for existence checks, reads and managed
writes. Both templates must exist before either read, and both reads must succeed
before either write. Only the service receives script-target substitution. Managed
write failures remain best-effort, and the systemd skip gate stays after publication.

Locale availability uses normalized membership directly. Default locale assignments
use the existing assignment parser and one case-normalized map instead of separate
LANG, LC_TIME and match state. Preserve last-assignment precedence, literal quoted
values, whitespace rules, prefix matching in locale.gen and both libc spellings.
Preserve the final MOTD call through its original compatibility include.

## Consequences
The mixed implementation becomes four responsibility modules without additional
runtime helpers or alternate execution paths. One duplicate artifact specification
and two separately maintained locale match states disappear. Runtime lines decrease;
the include and public helper surface remain stable.

BootTuningStagesCharacterizationTest and LocaleBaselineCharacterizationTest record
literal command/log/write traces with in-memory host-operation substitutes. They
cover success, refusal, failed writes/commands, ordering and exception/error boundaries.
Existing temporary-mount, root-shell and complete-write cases continue through the
facade. The boot source contract follows its implementation to bootTuning.php.
Tests execute on testing machines; devbox verification is syntax-only on changed PHP.

## References
- [Update workflow](../update.md)
- [Function contracts](../contracts.md)
- [Refactoring guidelines](../refactoring.md)
