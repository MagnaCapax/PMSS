# ADR 0049: In-script pmssLockFileAcquire is the canonical cron concurrency lock

Date: 2026-09-02
Category: architecture

## Status
Accepted

## Context
PMSS cron entrypoints that must not overlap have been guarded two different ways,
with no decision record adjudicating which is canonical:

- **In-script lock** — the script calls `pmssLockFileAcquire(pmssRuntimeLockPath('pmss-<name>.lock'), true)`
  (from `scripts/lib/runtime/locks.php`, "shared by CLI tools and cron entrypoints"), and on
  acquire-failure logs "already running; skipping" and `exit(0)`. Used by 9+ scripts
  (trafficLog, userTrackerCleaner, update-step2, usageAlertsNotify, and others).
- **Outer cron `flock`** — `etc/seedbox/config/root.cron` wraps the invocation in
  `flock -xn ... pmss-<name>.lock /scripts/cron/<name>.php`. Used by 6 scripts
  (updateQuotas, checkRootlessDocker, trafficIngressLog, trafficLimits, trafficStats,
  trafficIngressStats).

The two mechanisms are not equivalent. The outer cron `flock` only guards the cron
invocation path — a manual or test run of the script (`php /scripts/cron/<name>.php`) gets
NO concurrency protection. The in-script lock guards every invocation path and is
symlink/device-safe and handle-verified.

Having both mechanisms present is actively dangerous when they target the same lock path:
commit `cff1c67a` (2026-09-01, Refs #850) added an outer `flock` on `pmss-trafficLog.lock`
to a script that already self-locked on that exact path. The outer `flock` held the lock for
the whole child run, so trafficLog's own `pmssLockFileAcquire()` failed every invocation and
the script skipped 100% of runs — silently disabling egress traffic accounting and, through
it, egress traffic-limit enforcement, fleet-wide. Reverted in `261bd3e9`.

## Options Considered
- Option A - keep both mechanisms, chosen per-script. Preserves current behavior but leaves
  the double-lock collision class live and the choice undocumented (the state that produced #850).
- Option B - standardize on the outer cron `flock`. Rejected: it does not protect manual/test
  invocations, and the lock is expressed as bespoke per-line strings in root.cron (six slightly
  different invocations, each a place to get the lock path wrong).
- Option C - standardize on the in-script `pmssLockFileAcquire`, remove the outer cron `flock`
  wrappers, and for genuinely idempotent scripts (overlapping runs are safe) drop the wrapper
  without adding a lock rather than gold-plating one on.

## Decision
Choose Option C. The canonical concurrency-lock for a PMSS cron entrypoint is the in-script
`pmssLockFileAcquire(pmssRuntimeLockPath('pmss-<name>.lock'), true)` idiom. A cron entrypoint
that can overlap harmfully self-locks internally; the outer cron `flock` wrapper is not used.
A script whose work is genuinely idempotent (e.g. systemd IPAccounting cumulative+delta
counters) needs no lock at all and carries neither.

`scripts/lib/tests/development/CronDoubleLockGuardTest.php` (commit `ad6e852a`) enforces the
load-bearing invariant deterministically in CI: no `root.cron` cron script is BOTH
outer-`flock`-wrapped AND self-locking on the same lock path. That test makes the #850
collision class structurally impossible regardless of migration progress.

The migration of the 6 currently-outer-`flock` scripts to this convention (add the in-script
lock where a lock is needed, drop the outer `flock`, one script per commit, verifying each
script's idempotency and updating the existing flock-asserting tests) is tracked in issue #853.

## Consequences
- Positive: one lock mechanism, protecting every invocation path (cron, manual, test), not
  only the cron path.
- Positive: the #850 double-lock collision class is eliminated structurally (guard test) and
  by convention (never two locks on one path).
- Positive: removes bespoke per-line `flock` lock-path strings from root.cron; the lock lives
  next to the code that needs it.
- Negative: a migration is required (#853); it touches billing/quota cron code and must be
  done per-script with idempotency verification, so it is deliberately staged, not swept.
- Negative: existing tests that assert the outer `flock` is present
  (`scripts/lib/tests/development/updateQuotasGuardTest.php`) must be updated to the
  in-script-lock expectation as each script migrates, or CI breaks.
- Follow-ups: complete the #853 migration; `updateQuotasGuardTest.php` assertions updated
  per-script; this ADR governs new cron entrypoints from now (self-lock in-script, never wrap
  in an outer `flock` on the same path).

## References
- Issue #850 (the double-lock incident) and #853 (the migration)
- Commits: `cff1c67a` (introduced the collision), `261bd3e9` (reverted trafficLog's), `ad6e852a` (guard test)
- `scripts/lib/runtime/locks.php` (`pmssLockFileAcquire`), `scripts/cron/trafficLog.php` (the model idiom)
- Prior cron-guard precedents: commits Refs #456 (updateQuotas), #756 (checkRootlessDocker)
