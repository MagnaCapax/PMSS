# ADR 0292: Update package phase context and convergence

Date: 2026-11-16
Category: architecture

## Status

Accepted

## Context

Phase two spreads distro and repository facts across seven local aliases and
repeats the positive-version-to-null conversion for baseline and compatibility
calls. Package convergence also sits among unrelated entrypoint lifetimes.
Read-only status and version probes separately implement the same dpkg query
protocol. Their command bytes and failure results are compatibility contracts.

## Options Considered

- Retain mixed orchestration and independent query implementations.
- Introduce a package planner or a second execution representation.
- Keep the existing execution path with one distro context and a focused package phase.

## Decision

The existing distro module builds the phase-two context, including the effective
repository version, nullable baseline version and deferred diagnostic. Detection,
unsupported-release refusal and environment publication retain their order.

`update/packagePhase.php` owns the existing convergence sequence after the
MediaArea prelude and before quota setup. It only defines its phase helper;
the entrypoint supplies the already-loaded updater runtime and holds the global
lock. The pre-recovery Docker observation stays in the entrypoint. Comparison
and JSON publication consume one ordered version record in the phase helper.

`packageState.php` shares dpkg field queries behind the existing status/version
functions and keeps availability results at their consumers. No package queue,
new execution policy or alternate implementation remains.

## Consequences

- Three nullable-version conversions become one; two dpkg query implementations
  become one. Baseline recovery uses one short-circuit retry gate.
- Early dpkg recovery, MediaArea handling, service policy, compatibility holds,
  lock ownership, command order, warning bytes and completion signals are preserved.
- Existing source assertions follow the moved phase. The profiled batch snapshot
  retains its original count and hash by expanding the phase at its call site.
- Characterization covers healthy and failed recovery traces, unknown versions,
  Docker event field order, exception/error boundaries, query bytes, first-line
  results, availability normalization and cached policy fallbacks.
- Only changed-file PHP syntax checks run on the devbox. Execution and baseline
  comparison belong on the testing VM/CI; no package or service dry run runs here.

## References

- [Update workflow](../update.md#package-phase-ordering)
- [Dpkg baseline boundaries](0261-dpkg-baseline-responsibility-boundaries.md)
- `scripts/lib/tests/development/UpdatePackageContextCharacterizationTest.php`
- `scripts/lib/tests/development/UpdatePackagePhaseCharacterizationTest.php`
