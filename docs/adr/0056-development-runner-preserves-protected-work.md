# ADR 0056: Protected-path checks preserve shared-checkout work

Date: 2026-09-10
Category: security

## Status
Accepted

## Context

The development runner checked protected paths after assistant execution and
restored tracked paths, removed untracked paths, and reset staged paths. A
post-run diff cannot establish whether those edits belong to the assistant,
the operator, or a concurrent session. Automatic cleanup therefore discarded
work that the run did not own, contrary to the shared-checkout rules.

## Options Considered

- Keep automatic restoration: simple, but loses edits whose ownership is unknown.
- Snapshot and restore pre-run files: adds a second copy of mutable state and
  still cannot safely merge concurrent edits made during the run.
- Report and fail while preserving work: leaves a concrete diff for review
  without destructive recovery.

## Decision

The protected-path checker reports staged, unstaged, untracked, and committed
changes without changing the working tree, index, or history. The runner emits
`frozen_path_violation` and exits 3. Protected paths remain prohibited targets
for autonomous work; preservation does not grant permission to modify them.

## Consequences

- Existing operator and parallel-session edits survive the check byte-for-byte.
- Violations are visible to automation as failure instead of a warning followed
  by success. Recovery is a separate reviewable action.
- This check detects changes after execution; it does not replace repository
  locking, scope selection, or the assistant's sandbox.
- Tests use a synthetic checkout with prior tracked, staged, and untracked work
  and assert that both contents and staging survive the failed run.

## References

- `AGENTS.md`, Git Safety & Concurrency
- `docs/security/operational-safety.md`
- `development/lib/codex-common.sh`, `codex_scan_frozen_paths`
- `scripts/lib/tests/development/CodexRunnerEventsTest.php`
