# ADR 0172: Shared web-root inspection boundaries

Date: 2026-10-24
Category: architecture

## Status
Accepted

## Context
ADR 0041's migration and recovery implementation mixed account mutations,
read-only inspection, skeleton traversal and locked orchestration in two files.
Three inspection functions repeated recursive enumeration and scan-failure handling;
three migration checkpoints repeated destination-conflict selection.

## Decision
Keep existing entry points and the source-locked account mutation boundary in
`webRoot.php`. Extract inspection into `webRootInspection.php`, path preparation
and conflict selection into `webRootPaths.php`, and skeleton traversal into
`webRootReconcileCopy.php`. `webRootReconcile.php` retains locking, publication,
the account file installer and summaries. No alternate implementation remains.

Use one traversal: inspectors request directory descent, accept a leaf, or reject
an entry. Preserve scan order, early termination and partial snapshot state.
Migration still rejects all links; recovery checks relative targets; ownership
inspection skips links. Identity and content snapshots retain their exact schemas.
Destination checks share one message selector; callers retain cleanup/log order.

## Consequences
The mechanism inventory shrinks from three inspection traversals and three conflict
selectors to one of each (six to two).
No paths, ownership rules, credentials, commands, locks, or recovery modes change.
Existing migration/recovery tests remain intact. Added characterization tests lock
snapshot ordering/schema, partial failure, link policies, and missing/file inputs.
All suites run on testing machines. Rollback restores the prior module layout;
no state migration is needed.

The legacy unprivileged command fallback in `webRoot.php` retains ownership of
its child through thrown reaping faults. It attempts termination and another
reap only while the child remains acquired; termination faults cannot skip that
reap. Lone throwables propagate unchanged, with PHP's normal precedence for
combined cleanup faults. Command bytes, descriptors, account/path guards and
ordinary boolean results remain unchanged. `WebRootMigrationCommandCleanupSafetyTest`
injects exceptions and errors with memory streams and fake process operations;
execution stays on testing machines.

Misfiled-tree migration also owns its unpublished staging copy through copying,
snapshot verification, destination inspection and publication. Thrown failures
attempt the existing account-authority cleanup before propagating. Ordinary
refusals retain cleanup-before-log ordering; a cleanup fault is never retried,
and a successfully published copy survives subsequent logger faults. Lone
throwables propagate unchanged; combined faults retain PHP's `finally`
precedence. `WebRootMigrationCopyCleanupSafetyTest` intercepts filesystem and
account operations without copying or deleting real paths. Execution stays on
testing machines; devbox verification checks changed-file PHP syntax only.

Full web-root restoration similarly owns its account-created staging tree until
publication. Thrown skeleton validation, copying, stage validation, ownership
inspection, destination inspection or rename failures attempt the existing
account-authority removal in `finally`. Ordinary refusals still clean before
logging; cleanup faults are attempted once, and published trees survive logger
faults. Lone throwables propagate unchanged, with PHP's normal `finally`
precedence for combined faults. `WebRootReconcileFullCleanupSafetyTest` intercepts
all I/O and account operations, covering ordinary results and both throwable
families without creating or removing real trees. Execution stays on testing
machines; devbox verification checks changed-file PHP syntax only.

The full-restore review retains account identity, path and symlink guards,
shell quoting, staging mode and cleanup authority. Staged panel bytes stay
account-local; credential separation, payload-free diagnostics, file permissions,
listeners and web routes are unchanged. PHP's historical pathname-NUL issue
([CVE-2015-2348](https://www.php.net/ChangeLog-5.php#5.4.39)) reinforces retaining
the existing path checks. The [error-control documentation](https://www.php.net/manual/en/language.operators.errorcontrol.php)
confirms custom error handlers still run for suppressed diagnostics, so cleanup
must cover throwables as well as false returns.
