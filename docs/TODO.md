PMSS TODOs and Near‑Term Improvements

This document tracks small, stability‑focused improvements and medium‑term refactors. Items are grouped to align with our doctrine (KISS, DRY, Pit of Success) and are intentionally brief. Each entry explains the motivation so prioritization is easier.

## Tracking

Active work items are tracked in GitHub issues to avoid duplication between this file and the issue tracker. Keep this document as a short index rather than a second backlog.

- #112: Correlation / run ID (end-to-end tracing)
- #113: Repository signing hygiene (`signed-by=` scoping without deb822 migration)
- #114: Config backups with TTL (sshd/nginx/proftpd)
- #115: Test hooks and hermeticity (path overrides + patterns)
- #116: Defensive directory creation (idempotent mkdir+perms in user maintenance)
- #121: Cgroup policy extension backlog (remaining scheduler-aware IO auto-policy, burst allowances, and policy-level network shaping hints) — see `docs/cgroup.md`
- #111: Debian 13 (trixie) validation roadmap (experimental → supported) — see `docs/dpkg-baseline.md`

## Code Quality Audits

- [ ] Establish the compatibility scope of `PmssUserStatsProcessor::spawnWorkers()`
  before removing detached processing. `ResourceStatsProcessor` and
  `TrafficStatsProcessor` select inline processing, while their shared base still
  defaults to detached workers and exposes worker spawning publicly. Characterize
  both modes before deciding whether that branch can be retired; two inline
  subclasses alone do not establish that the inherited contract is unused.
- [ ] Audit string matching patterns across codebase for truncation bugs and typos. Found `.rtorrentexecut` typo (missing 'e') in process.php; similar issues may exist in other `strpos()`, `preg_match()`, or filename pattern checks. Check process name matching, config file patterns, and path validations.
- [ ] Before extracting new required helpers from `etc/skel/www/welcome.php` or
  `userMediaStackPanel.php`, establish delivery closure against the external
  retrieval allowlist and coordinate dependency ordering under
  [ADR 0022](adr/0022-guiv-delivered-customer-files-must-be-self-contained.md).
  The local skeleton manifest alone cannot verify that external prerequisite.
- [ ] Before sharing child-process cleanup across `etc/skel/bin/dockerLsioSeedHttp.php`,
  `etc/skel/bin/pmssAccountAgent.php` and `etc/skel/www/toggleAppRuntime.php`,
  verify a common helper's delivery to both account trees. Preserve each caller's
  pipe count, still-owned-child check, termination/reaping order and throwable
  precedence. `etc/skel/bin/pmssHermesRuntime.php` reaps without the same
  termination step and must retain that distinction. See
  [ADR 0022](adr/0022-guiv-delivered-customer-files-must-be-self-contained.md).

## Recently Completed

- Single-run locking for updates is implemented (lock + JSON events) in `ab31f8b`.
- Atomic staging swaps for `/scripts` and `/etc/seedbox` are implemented in `ab31f8b`.
- Phase 2 preflight checks are implemented in `scripts/lib/update/filesystem.php` (expanded as of `ab31f8b`).
- Per-user action logs via `pmssUserLog()` are implemented and used in cron/util flows (see `2923d6c`).
- #117 strict error handling policy is codified via ADR 0014 and classified update-step2 wrappers.
