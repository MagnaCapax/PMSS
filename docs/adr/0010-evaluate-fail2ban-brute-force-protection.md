# ADR 0010: Evaluate fail2ban for brute-force protection

Date: 2026-03-07
Category: security

## Status
Accepted (do not enable fail2ban fleet-wide by default at this time)

## Context

Issue #158 requests an architecture decision on brute-force protection,
specifically whether PMSS should enforce fail2ban by default.

Current repository state:
- PMSS ships an SSH template with `PermitRootLogin yes` and `MaxAuthTries 6`
  (`etc/seedbox/config/template.sshd_config`).
- There is no first-party fail2ban orchestration code (no PMSS-managed jail
  templates, ban actions, or lifecycle handling in updater modules).
- `fail2ban` appears in `scripts/lib/update/dpkg/selections-debian13.txt`, but
  Debian 13 is experimental per `docs/architecture.md` and is not the stable
  production baseline.

Operational constraints:
- PMSS must remain backward-compatible and avoid lockout-prone defaults on
  multi-tenant systems.
- Stateful auto-banning introduces support-risk without a standardized allowlist
  policy, clear jail defaults, and operator-facing recovery workflow.

## Options Considered

- Option A — Enforce fail2ban globally now.
  - Pros: automatic brute-force response.
  - Cons: lockout risk, new stateful failure modes, and no existing PMSS
    runtime contract for ban policy/jail templates/recovery.

- Option B — Keep fail2ban optional/unmanaged by PMSS for now.
  - Pros: preserves current behavior, avoids accidental lockouts, keeps update
    flow stable.
  - Cons: no built-in automatic IP ban mechanism in current supported
    production paths.

- Option C — Add monitor-only integration first, then decide on active bans.
  - Pros: improves visibility before enforcement.
  - Cons: adds implementation and maintenance surface without immediate
    protection effect.

## Decision

Choose **Option B**.

PMSS will not introduce default fail2ban enforcement in the current supported
production update path at this time.

Decision boundaries:
- No automatic fail2ban install/enable step is added for Debian 10/11/12 via
  PMSS orchestration in this ADR.
- Presence of `fail2ban` in the Debian 13 experimental baseline does not, by
  itself, define fleet policy or default runtime behavior.
- Any future move to active fail2ban enforcement requires a follow-up ADR with
  explicit jail policy, allowlist strategy, rollback/recovery steps, and
  observability requirements.

## Consequences

- Positive:
  - Maintains stable host behavior and avoids surprise lockouts.
  - Keeps PMSS update flow conservative while Debian 13 remains experimental.

- Negative:
  - PMSS does not yet provide a default automated brute-force ban layer.

- Follow-ups:
  - Define prerequisites for potential adoption: PMSS-managed jail templates,
    controlled ban thresholds, operator allowlists, and documented recovery SOP.
  - Revisit once those prerequisites are designed and validated across the
    supported distro matrix.

## References

- GH issue #158
- `etc/seedbox/config/template.sshd_config`
- `scripts/lib/update/dpkg/selections-debian13.txt`
- `docs/architecture.md`
- `docs/security/operational-safety.md`
