# ADR 0319: Web-root recovery transaction boundaries

Date: 2026-11-21
Category: architecture

## Status
Proposed

## Context
After ADR 0172 separated inspection and path policy, migration still mixed
account execution, inode-verified mutation, durable moves and misfile copying.
The reconciler mixed locking/reporting with streamed installation and staging.
Two staging transactions maintained cleanup flags independently of publication,
with eight removal sites across ordinary refusals and exception handling.
Durable migration also repeated rollback after verification and link failures.

## Options Considered
- Keep the mixed files and their duplicated cleanup branches.
- Introduce a generic transaction callback framework. Rejected: it would add a
  new abstraction to two different publication policies.
- Separate the existing responsibilities and unify failure handling locally.
  Chosen: public interfaces and authority checks remain unchanged.

## Decision
Keep `webRoot.php` as the stable include, logger and ordered state dispatcher.
Move account execution and its root-fixture guards to `webRootAccount.php`;
inode-checked moves, copies and removal to `webRootMutation.php`; durable moves
and rollback to `webRootMigration.php`; and misfile copying to `webRootMisfile.php`.
Keep locking and summaries in `webRootReconcile.php`, with streamed installation
and full restoration in `webRootReconcileRestore.php`. Existing inspection,
path policy and skeleton traversal modules retain their jobs.

Each staging transaction owns its temporary tree until publication succeeds.
One `finally` removes unpublished staging before result logging, including after
throwables. Removal faults are attempted once; successful publication survives
logger faults. No separate cleanup-needed flags remain. Durable migration uses
one rollback path for both verification and link refusals. Full/partial recovery
dispatches directly, without a temporary success value for partial recovery.

The mechanism inventory falls from four cleanup flows (ordinary and exceptional
for each transaction), two rollback flows and two mode-dispatch decisions to two
cleanup flows, one rollback flow and one dispatch: eight to four. Removal call
sites fall from eight to two. Splitting files adds no alternate implementation,
public function, configuration option or recovery mode.

## Consequences
The two original implementation files become stable facades/controllers with
focused internal modules. Account/path/owner/inode guards, command bytes,
snapshot schemas, probe order, diagnostics, counters, locking and exception
precedence retain their contracts. No installed state migration is required;
rollback restores the previous source layout.

Existing staging/process/lock fault fixtures remain unchanged. New
`WebRootInstallCharacterizationTest` locks facade loading, streamed bytes,
quoted commands, replacement modes, counters, refusals and both throwable
families. `WebRootMigrationFlowCharacterizationTest` locks move verification,
linking, rollback failure, concurrent source appearance and preflight refusals.
Source assertions follow the moved code without dropping guards. Tests run on
the testing machine; devbox verification is changed-file PHP syntax only.

Security review retains the existing account authority boundary and root-fixture
checks. Untrusted paths, symlink substitution and staging races could otherwise
let root affect another account or system files. PHP's historical pathname-NUL
issue [CVE-2015-2348](https://www.php.net/ChangeLog-5.php#5.4.39) reinforces keeping
the existing path guards. The [error-control documentation](https://www.php.net/manual/en/language.operators.errorcontrol.php)
confirms suppressed diagnostics still reach custom handlers, so cleanup covers
throwables. No credential is introduced, read or stored by this refactor;
primary/service credential separation, payload-free logs, modes, listeners and
customer-tree delivery stay unchanged.

## References
- [Web-root convergence](0041-user-web-root-managed-vs-customer-owned-boundary-and-recovery-convergence.md)
- [Inspection boundaries and cleanup contracts](0172-web-root-inspection-boundaries.md)
- [Root operations on account objects](0121-root-operations-and-account-controlled-objects.md)
