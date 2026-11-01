# ADR 0224: Root cleanup responsibility boundaries

Date: 2026-10-31
Category: architecture
Status: Accepted

## Context

`scripts/util/safeRootCleanup.php` mixed the deletion whitelist, filesystem
discovery, command execution, option handling and audit presentation. Rotated
and atop logs repeated the same guarded unlink lifecycle. Apt and journald
repeated before/command/after measurement. Estimates added a second record
shape containing bytes and files, although execution used separate candidate
arrays and reporting used only bytes.

## Options Considered

- Retain the mixed script and repeated workflows.
- Introduce a configurable cleanup engine, adding a policy/dispatch abstraction
  around deliberately fixed deletion classes.
- Keep the CLI and public helper names, separate their responsibilities and
  share only the existing repeated workflows.

## Decision

The script remains an include-safe entry point. `scripts/lib/rootCleanup/`
contains policy, collection, execution, CLI control and reporting modules.
Candidates stay in one ordered map; estimates are a flat byte map. The two
canonical commands share measured-byte accounting, and rotated/atop files share
one guarded unlink loop. Scratch removal retains its separate prefix check,
command and post-removal existence check. Human byte tables share one renderer.
Whitelist matching returns at the first allowed prefix without an extra
membership flag. Discovery keeps its original short-circuit guard order.

This is an internal restructuring. No allow/deny prefix, live-log exclusion,
rotation pattern, realpath/regular-file guard, iterator flag, age comparison,
option default/minimum, command byte or exit status changes. Default invocation
still discovers and reports without deleting or auditing. Apply retains
apt, journal, rotated, atop, scratch and audit order. JSON key order and human
output remain stable. Legacy `*_files_removed` fields count discovered
candidates, including failed or refused removals; correcting those names or
semantics would require a separate contract decision.

## Consequences

There is one implementation of each workflow and no compatibility copy of the
old controller. Smaller modules add include boundaries while removing the
intermediate estimate schema and duplicate execution/rendering paths.

`RootCleanupCharacterizationTest` locks exact dry-run/apply output, operation
order and audit payloads using reflected production functions and an in-memory
filesystem. Negative cases cover refusal before discovery, failed removals,
post-discovery redirects, missing paths, age boundaries, growing measurements
and audit failures. Expectations derive from the original source; execution is
reserved for the testing machine. Existing whitelist and CLI-help tests remain
unchanged. Local validation is changed-file PHP syntax checking only; no live
cleanup or dry-run scan is performed on the dev box.

## References

- [Operational safety](../security/operational-safety.md)
- [Contract conventions](../contracts.md#conventions)
- [Termination cleanup boundaries](0191-termination-cleanup-responsibility-boundaries.md)
