# ADR 0254: Quota snapshot refresh boundaries

Date: 2026-11-10
Category: architecture

## Status
Accepted

## Context
The account quota cron combined lock ownership, account discovery, a captured
lifecycle-log callback and a refresh closure containing all failure and publication
policy. The snapshot library mixed guarded publication, pure display transforms,
bounded process acquisition and sweep stopping. Existing watchdog and UI-related
operator consumers depend on its helper names and include path.

## Options Considered
- Keep both files unchanged: preserves the mixed responsibilities and closure coupling.
- Split by responsibility behind the existing include: retains callers while giving
  refresh policy a directly characterizable boundary.
- Introduce a refresh-result model and separate dispatcher: adds another schema
  and interpretation pass without changing any consumer requirement.

## Decision
Keep cron lock ownership, child-descriptor export and managed-account discovery in
`scripts/cron/updateQuotas.php`. Move per-account refresh and lifecycle reporting
to `scripts/lib/quota/snapshotRefresh.php`, using one direct logging helper.
Consume the capture result directly and return after failure handling instead of
nesting successful publication in a second branch.

Retain publication helpers in `scripts/lib/quotaSnapshot.php`. Its existing include
loads the unchanged public text helpers from `quota/snapshotText.php` and bounded
report/sweep helpers from `quota/snapshotReport.php`. There is one implementation
of each helper; callers need no compatibility aliases or alternate modes.

## Consequences
- Removes the captured log dependency and copied probe/derived status state.
  Responsibilities have explicit owners without a new result schema or policy knob.
- Preserves exact-home and regular-file checks, symlink fallback refusal, the
  20-second probe deadline, lock lifetime and stop-on-timeout sweep behavior.
- Preserves normalization/fallback bytes, rc-1 over-quota success, root-before-display
  writes, fail-soft root-copy handling, event ordering and exception propagation.
- The original snapshot include now depends on two sibling modules being deployed
  with the repository, as the normal update staging already does.
- `QuotaSnapshotRefreshCharacterizationTest` locks source-derived legacy I/O and
  log traces using namespaced stubs. Existing normalization, bounded-capture and
  special-file fixtures follow the moved implementations. Tests run on the testing
  machine; local validation is limited to changed-file PHP syntax checks and
  deleted-symbol searches. No live quota commands or dry runs run on the devbox.

## References
- ADR 0005: trust boundaries for internal tools and script output.
- ADR 0049: in-script cron concurrency locks.
- `docs/contracts.md`: quota refresh contract.
- `docs/refactoring.md`: quota snapshot module ownership.
