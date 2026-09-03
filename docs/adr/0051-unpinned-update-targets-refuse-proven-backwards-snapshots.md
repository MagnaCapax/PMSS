# ADR 0051: Unpinned update targets refuse proven backwards snapshots

Date: 2026-09-03
Category: architecture

## Status
Accepted

## Context
`scripts/update.php release` resolves the latest GitHub release tag and fetches
that tarball. `scripts/update.php git/<branch>` fetches a branch tip. Before this
decision, phase 1 staged the fetched tree without comparing it to the version
already recorded in `/etc/seedbox/config/version`.

That made a silent fleet regression possible. A host carrying a recent
`git/main@YYYY-MM-DD HH:MM` marker could run bare `release` as a manual workaround
when git smart-HTTP was blocked, resolve an older date-tagged release, and replace
the live `/scripts` tree with that older snapshot.

Rollback remains a legitimate emergency action, so a blanket downgrade block would
remove useful operator control. The policy needs to distinguish accidental
backwards movement from intentional rollback.

## Decision
`update.php` now logs the installed-version -> fetched-version transition before
staging any fetched snapshot.

When the target is unpinned (`release` or `git/<branch>`) and both the installed
marker and fetched snapshot expose orderable dates, a proven backwards move is
refused with a fatal message that names both versions and tells the operator to
pin explicitly if the rollback is intended.

Pinned targets remain allowed:
- `release:<tag>`
- `git/<branch>:YYYY-MM-DD` or `git/<branch>:YYYY-MM-DD HH:MM`

If either side is unparseable, the guard logs `indeterminate` and proceeds. A bad
or legacy version marker must not brick the update path.

## Options Considered
- Option A - keep status quo. Rejected: bare `release` can silently regress a host
  that is already ahead of the latest release tag.
- Option B - block all backwards moves. Rejected: removes deliberate emergency
  rollback, which PMSS intentionally supports for scripts/configs.
- Option C - block only proven backwards moves from unpinned targets. Chosen:
  accidental regressions stop, explicit rollback survives, and indeterminate
  version data stays fail-open.

## Consequences
- Positive: `release` and `git/<branch>` no longer silently move backwards when
  ordering is clear.
- Positive: rollback remains a one-command explicit action by pinning the target.
- Positive: every fetched snapshot logs the installed -> fetched version transition
  for auditability.
- Negative: unpinned codeload git fallback tarballs may be indeterminate because
  they do not carry `.git` commit metadata; those remain fail-open by design.
- Negative: if release tags stop carrying orderable dates, bare release moves become
  indeterminate rather than refused.

## References
- `scripts/update.php` `pmssGuardSnapshotVersionMove()`, `pmssVersionMoveDecision()`
- `scripts/lib/tests/development/UpdateBackwardsVersionGuardTest.php`
- ADR 0050 for the codeload tarball fallback that made bare `release` the tempting
  manual workaround during git smart-HTTP blocks.
