# ADR 0115: Verify termination before reporting completion

Date: 2026-10-18
Category: architecture
Status: Accepted

## Context

`terminateUser.php` printed a success line after reaching its final statement, even when purge commands failed or account data remained. A caller interrupted after an early process-kill step had no reliable completion signal. `recreateUser.php` can also leave an account-owned `backup-<user>.superseded` archive that termination did not remove.

## Options Considered

- Keep the unconditional line and require callers to inspect logs and filesystem state separately. This leaves the command's apparent success misleading.
- Stop on the first failed cleanup step. This changes the established best-effort ordering and may strand routes and ports behind a long purge.
- Keep the synchronous step order, retain purge return codes, and verify the final account and path state before reporting success.

## Decision

Use the existing purge-step runner's labeled return codes and add an exact purge of `backup-<user>.superseded` after the regular backup. After all cleanup steps, report verified success only if every required purge step succeeded, the passwd entry and `/var/lib/systemd/linger/<user>` are absent, and the home and both backup paths are absent. The linger check observes state only; it never deletes the file. Otherwise exit 1 with the first failed step or check. The lifecycle end event records `OK` or `INCOMPLETE`. Dry runs keep their planned-work behavior and use a distinct, unverified final line.

## Consequences

- Automation can distinguish completed termination from partial cleanup using the last line and exit code.
- A failed purge step makes the result incomplete even if a later retry removed the path. Operators can inspect its lifecycle log; rerunning cleanup remains possible.
- Amended 2026-10-20: the first removal of each path is excluded from that rule. It fails by design whenever immutable files remain, and PMSS itself writes `~/.trafficData*` and `~/.resourceData` immutable, so with it every account that had collected traffic stats reported `## Incomplete: remove_home_initial` after a full purge. The immutable clear and the leftover removal still count, every step must still have run, and the final path checks are unchanged.
- The purges remain synchronous and after bounded route and port cleanup, as required by ADR 0062. No account path is selected by glob.
- Empty-directory cleanup reports failed enumeration as an error and returns
  `false` to the existing port-cleanup aggregator, without attempting removal.
  Missing paths, non-empty directories, successful removals, and dry-run
  preservation keep their existing results; the final CLI completion checks
  and exit-code contract are unchanged.

## References

- ADR 0062: synchronous purge ordering.
- ADR 0092: pinned real-directory purge.
- `docs/contracts.md`: termination command contract.
