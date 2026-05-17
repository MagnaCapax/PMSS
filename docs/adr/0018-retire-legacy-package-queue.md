# ADR 0018: Retire legacy package queue

Date: 2026-05-17
Category: architecture

## Status
Accepted

## Context

`update-step2.php` uses dpkg baseline selections as the package-state authority.
The old `scripts/lib/update/apps/packages.php` path remained in-tree as a
skipped app module with queue helpers, which kept a second package concept alive
without participating in the default update flow.

Keeping a dormant queue made package ownership harder to reason about: operators
had to distinguish baseline convergence from a legacy queue that update-step2 no
longer executed.

## Options Considered

- Option A - Keep the skipped queue for compatibility tooling.
  - Pros: no interface removal.
  - Cons: preserves a parallel package concept and stale tests.
- Option B - Remove the queue and keep only read-only package probes.
  - Pros: one package authority, fewer runtime modules, less branching.
  - Cons: external callers of the queue helpers must move to dpkg baselines.

## Decision

Choose Option B.

Remove the legacy package queue app and queue helper functions. Keep
`pmssPackageStatus()` and `pmssPackageAvailable()` in
`scripts/lib/update/packageState.php` for baseline sanitization and installer
guards that need read-only package state.

## Consequences

- Positive: update-step2 has one package authority: dpkg baseline selections.
- Positive: app loading no longer carries a skipped package queue module.
- Negative: any out-of-tree tooling that called the queue helpers must switch to
  baseline capture/replay or direct package probes.
- Follow-up: keep package additions in captured dpkg baselines, not installer
  queues.

## References

- `docs/update.md`
- `docs/contracts.md`
- `scripts/lib/update/environment.php`
