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
