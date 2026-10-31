# ADR 0220: Runtime value and command observation boundaries

Date: 2026-10-31
Category: architecture

## Status

Accepted

## Context

The shared runtime's environment module combined environment defaults, numeric
conversion, config rows, executable discovery, shell construction and latency
observations. Direct includes are an established interface: moving definitions
must not require callers to load the full runtime or change their include order.
Command capture also had separate status-polling loops for inherited terminals
and piped output, while reporting copied three captured fields to local aliases.

## Options Considered

- Retain the combined environment module and independent polling loops.
- Require callers to select individual modules, changing direct-include contracts.
- Preserve the include facade, separate its existing responsibilities and share
  polling with explicit mode policy at the existing process boundary.

## Decision

Keep `runtime/environment.php` as the stable include and owner of environment
defaults, flags and directory accessors. Load these definition-only siblings:

- `values.php`: finite numbers, exact decimal integers and size conversion.
- `config.php`: ordered text, column and option transforms.
- `commandLookup.php`: executable-name/path acceptance and checked lookup.
- `commandShell.php`: argv quoting and shell/account command construction.
- `ioping.php`: bounded probe acquisition, unit conversion and latency parsing.

Each public function and constant retains its name, signature and one definition.
The path-safety dependency still loads first. No caller migration or alternate
implementation is introduced; internal siblings are loaded through the facade.

Use the existing finite-number predicate for request latency samples as well as
summary averages. Convert byte units through one scaling loop, including the
minimum-unit policy. Consume the command result directly in reporting instead
of copying stdout, stderr and the timeout flag. Shell construction and median
fallback likewise consume their single-use values directly.

After optional pipe draining, both I/O modes share one status-polling loop.
Inherited terminals retain their strict `>` deadline, 200 ms polling and
non-timeout result on an unreadable status. Piped capture retains its `>=`
deadline, 100 ms polling and timeout on an unreadable status. Unbounded piped
capture still closes directly after draining. An already timed-out drain skips
polling. Last observed exit codes and result key order remain unchanged.

## Consequences

The runtime has two fewer scaling/polling loops and four fewer intermediate
state variables: five single-use aliases disappear and elapsed time becomes
explicit. Latency samples no longer implement their own finite-value check.
The new files expose existing responsibilities rather than adding runtime
configuration, public APIs or execution modes. No parallel flow remains.

The full staged repository must contain the sibling modules, as with the
existing filesystem and command modules. The standalone update bootstrap stays
self-contained and unchanged. The customer PHP tree gains no dependency.

## Security Review

Command injection is the relevant attack vector: a quoting or validation change
could execute commands with the invoking operator or account identity. PHP's
[CVE-2024-1874 advisory](https://github.com/php/php-src/security/advisories/GHSA-pc52-254m-w9w7)
describes Windows batch-file argument injection in array-form `proc_open`.
This Debian runtime retains string-form shell invocation, existing argument
quoting, raw NUL rejection and command-name/path checks. Account launch builders,
their cleared environment and no-PAM selection move without policy changes.
Process-group wrapping, signalling, timeout logging and pipe setup stay intact.

No primary or service credential is read, stored or changed. Credential
separation, redaction and secret-file permissions remain with existing callers;
the refactor introduces no stored value, file publication, endpoint, bind or
listener. Existing command logging and account execution identities are retained.

## Validation

`RuntimeBoundariesCharacterizationTest` locks direct environment inclusion,
output silence, exported helpers, formatted bytes, shell quoting and an
in-memory process/clock matrix for both I/O modes. Its fixture never starts a
process or sleeps. Existing lookup and ioping fault-injection tests follow the
moved definitions; the cron source contract follows shell construction without
dropping assertions. Existing runtime tests retain actual command capture,
pipe, environment, timeout and logging coverage on the testing machine.

Local verification is limited to changed-file PHP syntax and moved/deleted
symbol searches. Execution tests, including dry-run rehearsals, are left to
the lane's testing machine and CI; no live commands or probes are exercised here.

## References

- [Architecture overview](../architecture.md)
- [Runtime contracts](../contracts.md#runtime-execution--profiling)
- [Refactoring guidelines](../refactoring.md)
- [Operational safety](../security/operational-safety.md)
