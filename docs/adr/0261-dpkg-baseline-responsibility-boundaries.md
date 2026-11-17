# ADR 0261: Dpkg baseline responsibility boundaries

Date: 2026-11-10
Category: architecture

## Status

Accepted

## Context

The package baseline is the sole package authority (ADRs 0018 and 0042), but its
implementation mixes baseline discovery and staging with line policy, and
application and interrupted configuration with environment setup. Application
copies sanitizer fields into separate local state; recovery repeats its failed
configuration condition across each retry action. Existing callers depend on
the environment and selection includes and their public helper signatures.

## Options Considered

- Retain mixed ownership and redundant application state.
- Introduce a package manager object or a second planning/execution model.
- Separate existing responsibilities behind the current includes and consume
  existing result records directly.

## Decision

Choose the existing interfaces with focused ownership:

- `dpkgBaseline.php` owns baseline discovery and sanitized-file staging.
- `dpkgSelections.php` retains line sanitization, drop accounting and summaries.
- `packageState.php` owns read-only package and required-hardware observations.
- `dpkgBaselineApply.php` owns ordered baseline application and install recovery.
- `dpkgRecovery.php` owns interrupted configuration and lock-aware recovery.
- `environment.php` retains its include contract and environment maintenance.

Application consumes sanitizer fields directly, without separate warning or
sanitized-line copies or a second baseline-path alias. Selection returns inside
its candidate traversal and uses an ordered unique candidate list. Interrupted
configuration has one retry gate rather than repeated failure dispatch.

## Consequences

- No new public functions, alternate implementation or package authority.
- Public symbols, signatures, candidate preference, warning bytes and command
  order remain unchanged. Existing missing/unreadable/empty-baseline behavior is
  characterized rather than changed by this refactor.
- Sanitizer validation, experimental-baseline restrictions, staging refusal,
  lock budgets, fuse3 holds and MediaArea handling retain their existing policy.
- Two definition-only modules accompany existing includes; normal updates stage
  the full scripts tree before phase 2. Environment remains the application
  entry include, including the legacy cleanup dependency.
- Characterization cases capture fallback diagnostics, application traces,
  failed staging, failed selection application, systemd retries and include
  availability. They execute only on testing machines. Devbox checks are changed
  PHP syntax and source/reference review; no package commands run here.

## Package observation safety follow-up (2026-11-17)

Read-only probes reject NUL-corrupted observations before normalization or
matching. A corrupt selected dpkg field line returns the existing empty string;
later lines remain ignored. A corrupt `apt-cache pkgnames` capture cannot form a
trusted name set, so it uses the existing per-package policy fallback. A corrupt
policy capture returns and caches `false`, even when a valid candidate precedes
the corrupt row. PCI class/vendor corruption skips that device without hiding
later valid devices.

Valid bytes, command construction, first-line selection, architecture handling,
legacy policy output without a candidate, caching and hardware matching retain
their existing behavior. No package mutation, logging or public interface is
added. `PackageStateInputSafetyTest` intercepts all commands and sysfs reads to
cover corruption positions, partial name sets, fallback results, cached refusals
and later valid devices. These cases execute only on testing machines; devbox
verification uses changed-file PHP syntax and source review.

## References

- [Canonical package baseline](0042-dpkg-selections-baseline-is-canonical-clean.md)
- `scripts/lib/tests/development/DpkgBaselineBoundariesCharacterizationTest.php`
- `scripts/lib/tests/development/DpkgBaselineRecoveryTest.php`
- `scripts/lib/tests/development/DpkgSelectionsWriteSafetyTest.php`
