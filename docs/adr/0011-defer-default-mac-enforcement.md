# ADR 0011: Defer default SELinux/AppArmor enforcement

Date: 2026-03-08
Category: security

## Status
Accepted (defer mandatory access control by default)

## Context

Issue #157 requests a formal architecture decision on mandatory access control
(MAC) for PMSS hosts, specifically whether PMSS should enforce SELinux or
AppArmor by default.

PMSS constraints relevant to this decision:
- The repository doctrine prioritizes stability and backwards compatibility for
  long-lived multi-tenant hosts.
- The supported production matrix is Debian 10 and Debian 11, with Debian 12
  under validation and Debian 13 experimental.
- The update flow is intentionally conservative and fail-soft, with preference
  for predictable behavior during repeated reruns.

Enforcing a MAC layer fleet-wide changes failure modes from explicit service
errors to policy-denied behavior that can be hard to diagnose without dedicated
policy ownership and operational workflows.

## Options Considered

- Option A — Enable SELinux enforcing by default.
  - Pros: strong mandatory controls and defense in depth.
  - Cons: high policy complexity and elevated operational/debugging burden for
    the current PMSS support model.

- Option B — Enable AppArmor enforcing by default.
  - Pros: simpler profile model and lower adoption complexity than SELinux.
  - Cons: still introduces policy lifecycle overhead and service-specific
    profile tuning across the distro matrix.

- Option C — Keep current discretionary-access baseline and defer default MAC.
  - Pros: preserves current behavior, minimizes regression risk, aligns with
    stability-first doctrine.
  - Cons: no immediate fleet-wide MAC hardening layer.

## Decision

Choose **Option C**.

PMSS will not enable SELinux or AppArmor enforcement by default at this time.
This ADR does not prohibit operators from enabling MAC per-host when they own
the policy and support lifecycle. It defines only the PMSS default behavior.

Reconsideration requires a follow-up ADR with:
- a supported policy ownership model,
- rollback and recovery procedures for lockout scenarios,
- observability and troubleshooting requirements,
- validation across supported Debian releases.

## Consequences

- Positive:
  - Preserves predictable upgrade behavior for existing hosts.
  - Avoids introducing new default failure modes without policy ownership.

- Negative:
  - PMSS does not gain immediate default MAC enforcement benefits.

- Follow-ups:
  - Define what evidence or incident class would trigger MAC re-evaluation.
  - If pursued later, stage via opt-in pilot before any default change.

## References

- GH issue #157
- `AGENTS.md`
- `docs/architecture.md`
- `docs/update.md`
