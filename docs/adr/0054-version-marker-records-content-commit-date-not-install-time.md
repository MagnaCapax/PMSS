# ADR 0054: Version marker records the content commit date, not install time

Date: 2026-09-07
Category: architecture

## Status
Accepted

## Context
ADR 0051 added an ordering guard that refuses a *proven backwards* snapshot move
for unpinned targets: `pmssVersionMoveDecision()` compares the date in the
installed `/etc/seedbox/config/version` marker against the date of the fetched
snapshot. The guard's correctness depends on the marker date representing the
**content (commit) age** of the installed tree, so that "installed vs fetched"
is a content-vs-content comparison.

`recordVersion()` did not honour that premise. It wrote the marker as
`<spec>@<install-wall-clock-time>` (`date('Y-m-d H:i', time())`), discarding the
commit date it already had in `fetched_version` (derived from
`git log -1 --format=%cI`, ADR 0050/0051 fetch path).

Because content is always committed *before* it is installed, the install-time
stamp is always >= the content date. A single update is unaffected (the previous
marker is an older day than the fetched HEAD, so the move is "forward"). But
running `update.php` twice in quick succession breaks: the first run stamps the
marker with "now", and the second run re-fetches the *same* HEAD, whose commit
date is now older than the just-written "now" marker, so the guard refuses it as
a phantom backwards move.

2026-09-07 incident (a production host, Debian 11->12): a back-to-back
`php /scripts/update.php git/main` then `php /scripts/update.php --dist-upgrade=12`
tripped the guard — installed `git/main@2026-09-07 09:08` (first-run wall-clock) vs
fetched `git/main@2026-09-06 15:06` (real repo HEAD) → "backward" → fatal. Nothing
older was ever being installed; the marker simply recorded the wrong kind of date.

## Decision
`recordVersion()` builds the marker line via a new pure helper
`pmssRecordedVersionLine($spec, $fetchedVersion, $timestamp)` that stamps the
marker with the **content commit date** extracted from the fetched-version label
(`@YYYY-MM-DD HH:MM`). It falls back to install wall-clock time only when the
fetched label carries no orderable date (e.g. codeload tarball fallbacks with no
`.git` metadata), preserving ADR 0051's fail-open contract.

The metadata object (`VERSION_META`) keeps `timestamp` = actual install time for
audit; only the ordering-significant marker line changes.

## Options Considered
- Option A - keep install-time stamping. Rejected: violates ADR 0051's premise;
  any double-run of `update.php` falsely refuses the current HEAD.
- Option B - relax the ADR 0051 guard to ignore same-spec re-fetches. Rejected:
  weakens a real safety control to paper over a wrong marker; the guard is
  correct, the marker was wrong.
- Option C - stamp the marker with the fetched content commit date, fall back to
  install time only when dateless. Chosen: makes the ADR 0051 comparison a true
  content-vs-content ordering, keeps fail-open for dateless fetches, minimal diff.

## Consequences
- Positive: back-to-back `update.php` runs (and any tooling that updates then
  dist-upgrades) no longer trip the backwards guard on the current HEAD.
- Positive: the ADR 0051 protection is now a genuine content-age comparison, so a
  real older snapshot is still refused unless pinned.
- Neutral/transition: hosts already carrying an install-time marker from a recent
  double-run stay "poisoned" until one more forward update re-stamps them with a
  content date (or a one-time explicit pin). New installs are never poisoned. No
  host is bricked (indeterminate/forward paths are unaffected).
- Negative: if a fetched label ever lacks a commit date, the marker still falls
  back to install time (same fail-open behaviour as before for that case).

## References
- `scripts/update.php` `pmssRecordedVersionLine()`, `recordVersion()`,
  `pmssGuardSnapshotVersionMove()`, `pmssVersionMoveDecision()`
- `scripts/lib/tests/development/UpdateBackwardsVersionGuardTest.php`
  (`testRecordedMarkerUsesContentDateNotInstallTime`,
  `testRecordedMarkerFallsBackToInstallTimeWhenLabelIsDateless`)
- ADR 0051 (the ordering guard whose premise this restores)
- ADR 0050 (codeload tarball fallback that can produce dateless labels)
