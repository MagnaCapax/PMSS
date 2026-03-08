# ADR 0014: update-step2 step error classification policy

Date: 2026-03-08
Category: architecture

## Status
Accepted

## Context

`scripts/util/update-step2.php` historically used mostly fail-soft execution via
`runStep()` plus a mix of direct callable invocations. That kept updates moving,
but left post-package critical configuration steps without an explicit policy for
when the run must stop to avoid partially configured hosts.

PMSS needs a small, conservative rule that preserves package-phase ordering and
existing interfaces while making high-value post-package failures visible and
actionable.

## Decision

Adopt explicit step classifications for update-step2 orchestration:

- `must_succeed`: after package phase completion, failure logs `step_failed`
  with `severity=error` and aborts update-step2.
- `soft_fail`: failure logs `step_failed` with `severity=warn` and continues.
- `skip_if_missing`: reserved classification for optional dependencies that may
  be absent on some hosts; missing dependency logs and continues.

Implementation is intentionally minimal:

- Add `scripts/lib/update/runtime/stepPolicy.php` with classification constants
  and central failure handling (`pmssUpdateStep2HandleClassifiedFailure()`).
- Add `pmssUpdateStep2RunClassifiedCallable()` wrapper in update-step2.
- Progressively annotate high-value post-package steps as `must_succeed`:
  runtime service templates, web stack configuration, and sshd key directive
  enforcement.

Package phase ordering and package-phase behavior stay unchanged in this ADR.

## Consequences

- Critical post-package failures now fail loudly with structured JSON events and
  deterministic abort behavior.
- Optional/non-critical paths can remain fail-soft using explicit
  classification, instead of implicit conventions.
- The policy is additive and low-churn: existing helper interfaces are kept,
  and progressive annotation can continue without rewriting update-step2.

