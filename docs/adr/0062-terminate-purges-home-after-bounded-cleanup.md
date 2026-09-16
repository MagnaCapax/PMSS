# ADR 0062: Termination purges home after the bounded cleanup, not before it

Date: 2026-09-16 — Status: Accepted
Category: architecture

## Context and options
`scripts/terminateUser.php` ran the recursive home and recreate-backup purges immediately after `userdel_initial`, ahead of nginx route retirement, the 12 `portManager release` steps, and the remaining cleanup. The purge is the only unbounded step in the script: on a multi-terabyte account it can exceed the caller's timeout, and when it does, every step sequenced after it never runs. Observed residue from one such run: a still-live nginx route and 12 unreleased port reservations. The reservations are the durable loss — they leave the allocatable pool until something reconciles them — while the route is a correctness problem, since it keeps proxying to a home that is being deleted underneath it.

That ordering was asserted by `TerminateUserContractTest::testTerminateUserPurgesHomeAndBackupSynchronously`, whose docblock cited #729. Options considered: (a) leave it and reconcile stranded ports out of band, (b) make the purge asynchronous again, (c) move the purge after the bounded work.

Option (b) is barred by #729 (revert `4d3548d2`): backgrounding or renaming the home aside lets the script return while the directory still exists, and `addUser.php` has no existence guard, so a re-provisioned username can race the deletion. Option (a) treats the symptom and leaves the route live.

## Decision
Move both purge calls to after `remove_nginx_user_file`, so every cheap bounded step completes first, and record that the property #729 protects is **synchronous-not-queued** — not the purge's position in the sequence. That property is held by the `forbidden` list in the contract test (`pmssTerminateUserMoveHomeForReclaim`, `queue_home_reclaim`, `.terminating-`), which is unchanged. The purge still blocks in the same run; nothing is queued or renamed aside, so the #729 race is not reintroduced.

The contract's `ordered` list now asserts the new sequence and additionally pins **route retire → port release**. That direction is load-bearing: `portManager` shuffles the free list, so releasing a port while its route still exists lets a newly provisioned account draw a port that a stale route proxies to — a cross-tenant exposure.

## Consequences and verification
A purge timeout now costs only the purge, which is resumable, instead of the whole cleanup tail. Ports and routes are released before the long operation begins, so an interrupted termination no longer strands them.

Verified: `php scripts/lib/tests/development/Runner.php` — all 16 TerminateUser-related tests pass, including the reordered contract. The 7 unrelated failures in that run are pre-existing and reference nothing in this change.

Refs #908, #729.
