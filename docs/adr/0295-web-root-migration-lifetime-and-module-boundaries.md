# ADR 0295: Web-root migration lifetime and module boundaries

Date: 2026-11-17
Category: architecture

## Status
Accepted

## Context
The 368-line `scripts/lib/update/users/webRoot.php` combines account command
authority, basename-collapse recovery, ordinary migration/rollback, and dispatch.
Five ordinary refusal branches each clear a cleanup flag, remove staging and log;
a sixth cleanup site handles thrown failures. Publication must transfer ownership
before logging, and neither cleanup nor a throwing logger may trigger a retry.

## Options Considered
- Keep the mixed module and six cleanup sites: preserves duplicated lifetime rules.
- Extract files alone: reduces file size but retains the duplicated cleanup policy.
- Separate responsibilities and converge staging cleanup in `finally`: chosen.

## Decision
Keep the original include and all public functions. `webRoot.php` owns dispatch
and logging; `webRootAccount.php` owns authority checks, commands and inode-checked
rename; `webRootMisfile.php` owns verified staging/publication; `webRootMove.php`
owns ordinary move/link/rollback. This replaces only ADR 0172's migration layout;
its inspection, path and reconciliation boundaries remain in force.

Use the staging path itself as ownership state, clearing it after publication.
Refusals select their existing diagnostic and return through one `finally` block:
remove unpublished staging, then log. Unexpected throwables select no diagnostic.
Cleanup faults suppress logging and propagate without retry; successful publication
survives logger faults. A strict comparison with the already non-null source
snapshot also rejects a null destination snapshot. No validation is relaxed.

The conservative concept inventory changes by -3: six cleanup sites become one
(-5), the separate cleanup flag disappears (-1), the default logger forwarding
closure disappears (-1), a pending diagnostic is introduced (+1), and three
responsibility modules are introduced (+3). Parent/snapshot aliases also disappear.
Runtime physical lines decrease from 368 to 361, including the new module headers.

## Consequences
All four runtime files stay below 150 lines. No alternate migration implementation
remains. Include order still loads the existing prerequisites before defining the
new modules; callers need no migration. Paths, snapshot bytes, authority checks,
commands, rollback decisions, diagnostics and throwable precedence are preserved.

`WebRootMigrationDispatchCharacterizationTest` locks dispatch, context refusals,
logger faults, command quoting and copy refusals without host I/O. The existing
copy/command cleanup and filesystem migration tests retain the original include.
The authority source contract moves intact to `WebRootMigrationAuthorityStaticTest`
and follows each implementation file. Tests execute on testing machines; the
devbox checks changed-file PHP syntax and symbol references only. Rollback restores
the previous module layout without changing persisted state.

The security review retains path/NUL/symlink refusals, account authority, root
parent checks, inode verification and shell quoting. PHP's historical pathname
NUL issue ([CVE-2015-2348](https://www.php.net/ChangeLog-5.php#5.4.39)) supports
retaining those guards. A path-authority regression could overwrite or remove
customer data, or cross an account boundary when running with root authority.
No credential values or storage, payload logging, secret permissions, listeners,
ports or customer routes change; primary/service credential separation is untouched.

## References
- [ADR 0041: managed/customer web-root boundary](0041-user-web-root-managed-vs-customer-owned-boundary-and-recovery-convergence.md)
- [ADR 0172: shared inspection boundaries](0172-web-root-inspection-boundaries.md)
