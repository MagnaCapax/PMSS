PMSS TODOs and Near‑Term Improvements

This document tracks small, stability‑focused improvements and medium‑term refactors. Items are grouped to align with our doctrine (KISS, DRY, Pit of Success) and are intentionally brief. Each entry explains the motivation so prioritization is easier.

## Tracking

Active work items are tracked in GitHub issues to avoid duplication between this file and the issue tracker. Keep this document as a short index rather than a second backlog.

- #112: Correlation / run ID (end-to-end tracing)
- #113: Repository signing hygiene (`signed-by=` scoping without deb822 migration)
- #114: Config backups with TTL (sshd/nginx/proftpd)
- #115: Test hooks and hermeticity (path overrides + patterns)
- #116: Defensive directory creation (idempotent mkdir+perms in user maintenance)
- #111: Debian 13 (trixie) validation roadmap (experimental → supported) — see `docs/dpkg-baseline.md`

## Code Quality Audits

- [ ] Audit string matching patterns across codebase for truncation bugs and typos. Found `.rtorrentexecut` typo (missing 'e') in process.php; similar issues may exist in other `strpos()`, `preg_match()`, or filename pattern checks. Check process name matching, config file patterns, and path validations.

## Recently Completed

- Single-run locking for updates is implemented (lock + JSON events) in `ab31f8b`.
- Atomic staging swaps for `/scripts` and `/etc/seedbox` are implemented in `ab31f8b`.
- Phase 2 preflight checks are implemented in `scripts/util/update-step2.php` (expanded as of `ab31f8b`).
- Per-user action logs via `pmssUserLog()` are implemented and used in cron/util flows (see `2923d6c`).
- #117 strict error handling policy is codified via ADR 0014 and classified update-step2 wrappers.
